<?php

declare(strict_types=1);

// ===========================================================================
// Kachel (HTML-SDK, module.html) und dieselbe Seite als WebHook für IPSView
// (WebView-Element) oder jeden Browser: /hook/verschattung<ID>, ?json=1 = nur Daten,
// ?bg=1c1c1e = Hintergrund der ganzen Seite. Die IPSView-Seite zeigt nur an.
// ===========================================================================

trait VSCHKachel
{
    private const WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    private function hookPfad(): string
    {
        return '/hook/verschattung' . $this->InstanceID;
    }

    public function GetVisualizationTile()
    {
        return file_get_contents(__DIR__ . '/module.html')
            . '<script>handleMessage(' . json_encode($this->kachelDaten(true, true)) . ');</script>';
    }

    /** Nur bei geändertem Inhalt senden (der Regeltakt läuft jede Minute). */
    private function kachelAktualisieren(array $wege = []): void
    {
        $json = (string)json_encode($this->kachelDaten(false, true, $wege));
        $stand = md5($json);
        if ($stand !== $this->GetBuffer('Kachel')) {
            $this->SetBuffer('Kachel', $stand);
            $this->UpdateVisualizationValue($json);
        }
    }

    public function ProcessHookData()
    {
        if (isset($_GET['json'])) {
            $this->kopf('application/json');
            echo json_encode($this->kachelDaten(true, false, [], (string)($_GET['bg'] ?? '')));
            return;
        }
        $this->kopf('text/html');
        $bg = preg_replace('/[^0-9a-fA-F]/', '', (string)($_GET['bg'] ?? ''));
        $abruf = json_encode($this->hookPfad() . '?json=1' . ($bg !== '' ? '&bg=' . $bg : ''));
        echo file_get_contents(__DIR__ . '/module.html')
            . '<script>handleMessage(' . json_encode($this->kachelDaten(true, false, [], $bg)) . ');setInterval(function(){fetch(' . $abruf . ')'
            . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);}).catch(function(){});},60000);</script>';
    }

    /** Nachbargebäude als Höhenwinkel je 3° für das Sonnenstandsdiagramm (nur wo höher als 1°). */
    private function gebaeudeStreifen(float $fassadeAz): array
    {
        $out = [];
        foreach ($this->gebaeudeHorizont($fassadeAz) as $a => $h) {
            if ($a % 3 === 0 && $h > 1) {
                $out[] = [$a, round($h, 1)];
            }
        }
        return $out;
    }

    /** Grund aus dem Regeltakt, sonst die letzte Zeile des Probelaufs (ohne Pfeil). */
    private function kachelGrund(string $grund, array $weg): string
    {
        if ($grund !== '' || $weg === []) {
            return $grund;
        }
        return ltrim((string)$weg[count($weg) - 1], '→ ');
    }

    private function kopf(string $typ): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $typ . '; charset=utf-8');
            header('Cache-Control: no-store');
        }
    }

    /**
     * Alles, was Kachel und IPSView-Seite zeigen. Rechnet nichts neu, was der Regeltakt schon weiß (Gründe aus dem Zustand);
     * den Entscheidungsweg liefert der Regeltakt mit ($wege), beim ersten Aufbau ein Probelauf.
     */
    private function kachelDaten(bool $mitTexten, bool $bedienbar, array $wege = [], string $bg = ''): array
    {
        $pos = $this->koordinaten();
        $now = $this->now();
        $daten = ['automatik' => (bool)@$this->GetValue('Automatik'), 'bedienbar' => $bedienbar, 'geraete' => [], 'fassaden' => [], 'jetzt' => $now];
        if ($bg !== '') {
            $daten['bg'] = '#' . substr(preg_replace('/[^0-9a-fA-F]/', '', $bg), 0, 6);
        }
        if ($mitTexten) {
            $daten['texte'] = $this->kachelTexte();
            $daten['regeln'] = $this->regelnText();
        }
        if ($pos === null) {
            return $daten;
        }
        $tz = date_default_timezone_get();
        $sonne = VSCHSonne::stand($pos[0], $pos[1], $now);
        $daten['sonne'] = ['az' => round($sonne['az'], 1), 'alt' => round($sonne['alt'], 1)];
        $hid = $this->ReadPropertyInteger('HelligkeitID');
        $wm2 = $hid > 0 && IPS_VariableExists($hid) ? $this->wm2($hid) : null;
        $daten['einstrahlung'] = $wm2 === null ? null : (int)round($wm2);
        $daten['himmel'] = VSCHLogik::himmel($wm2, $sonne['alt'], $this->regen());
        $aussen = $this->messwert($this->ReadPropertyInteger('TemperaturID'));
        $daten['aussen'] = $aussen === null ? null : round($aussen, 1);

        // Tag: Bahn, Sonnenauf-/untergang, klarer Himmel, gemessener Verlauf
        $bahn = VSCHSonne::bahn($pos[0], $pos[1], date('Y-m-d', $now), $tz, 10);
        $hell = array_values(array_filter($bahn, fn($p) => $p[2] > 0));
        $auf = $hell === [] ? strtotime('today 08:00', $now) : (int)$hell[0][0];
        $unter = $hell === [] ? strtotime('today 17:00', $now) : (int)end($hell)[0];
        $daten['bahn'] = array_values(array_map(fn($p) => [round($p[1], 1), round($p[2], 1), (int)$p[0]], array_filter($bahn, fn($p) => $p[2] > -1)));
        $gel = $this->horizontPunkte();
        $daten['horizont'] = [];
        for ($a = 0; $a < 360; $a += 5) {
            $daten['horizont'][] = [$a, round(VSCHHorizont::hoehe($gel, $a), 1)];
        }
        $daten['tag'] = [
            'auf' => $auf, 'unter' => $unter,
            'klar' => array_map(fn($p) => [(int)$p[0], (int)round(VSCHLogik::klarHimmel($p[2]))], $hell),
            'verlauf' => json_decode($this->ReadAttributeString('Verlauf'), true) ?: [],
        ];

        // Fassaden mit ihren Fenstern, Sonnenzeiten heute und „Sonne jetzt / verdeckt“
        $haus = json_decode($this->ReadAttributeString('Haus'), true) ?: [];
        $fassaden = $this->listeWerte($this->ReadPropertyString('Fassaden'));
        $geraete = $this->geraete();
        $z = $this->zustand();
        if ($wege === []) {
            $wege = $this->regeln(true);
        }
        foreach ($this->fassadenMitKanten($fassaden, $haus) as $i => $f) {
            $k = (string)($fassaden[$i]['Kennung'] ?? '');
            $meine = array_values(array_filter($geraete, fn($g) => $g['Fassade'] === $k && $g['Position'] > 0));
            if (!$f['aktiv'] && $meine === []) {
                continue;
            }
            $hind = $this->hindernisPunkte($f['az']);
            $geo = VSCHLogik::sonneAufFassade($sonne['az'], $sonne['alt'], $f['az'], 0.0, false);
            $an = $f['aktiv'] && VSCHLogik::sonneAufFassade($sonne['az'], $sonne['alt'], $f['az'], VSCHHorizont::hoehe($hind, $sonne['az']), false);
            $fenster = VSCHGrafik::sonnenfenster($bahn, $hind, $f['az']);
            $daten['fassaden'][] = [
                'kennung' => $k, 'name' => (string)($fassaden[$i]['Name'] ?? ''), 'az' => $f['az'], 'aktiv' => $f['aktiv'],
                'sonne' => $an, 'verdeckt' => $geo && !$an, 'profil' => round(VSCHSonne::profilwinkel($sonne['alt'], $sonne['az'], $f['az']), 1),
                'zeiten' => array_map(fn($w) => [(int)$w[0], (int)$w[1]], $fenster),
                'gebaeude' => $this->gebaeudeStreifen($f['az']),
                'seg' => array_map(fn($s) => [[round($s[0][0], 1), round($s[0][1], 1)], [round($s[1][0], 1), round($s[1][1], 1)]], (array)$f['seg']),
            ];
        }
        // Lageplan (klein): Haus, Nachbarn, Straßen im Nahbereich
        $nah = fn($pts) => count(array_filter($pts, fn($p) => abs($p[0]) < 30 && abs($p[1]) < 30)) > 0;
        $daten['haus'] = array_map(fn($p) => [round($p[0], 1), round($p[1], 1)], (array)($haus['eigen'] ?? []));
        $daten['nachbarn'] = array_values(array_filter(array_map(fn($n) => array_map(fn($p) => [round($p[0], 1), round($p[1], 1)], $n), (array)($haus['nachbarn'] ?? [])), $nah));
        $daten['strassen'] = array_values(array_filter(array_map(fn($st) => ['b' => (float)$st['b'], 'p' => (array)$st['p']], (array)($haus['strassen'] ?? [])),
            fn($st) => $nah($st['p'])));

        // Lage (Heizen/Kühlen/behaglich) wie der Regeltakt sie zuletzt bestimmt hat, häufigste über die Geräte
        $lagen = array_count_values(array_filter(array_map('strval', (array)($z['_klima'] ?? []))));
        arsort($lagen);
        $lage = (string)(array_key_first($lagen) ?? '');
        $daten['lage'] = $lage;
        $daten['lageText'] = ['kuehlen' => $this->t('Summer: keeping the heat out'), 'heizen' => $this->t('Winter: the sun heats with us'),
            'neutral' => $this->t('Comfortable: glare protection only')][$lage] ?? '';
        $wp = $this->wpModus();
        $daten['wp'] = $wp === null ? '' : $this->wpText($wp);
        $notfall = $this->notfall();
        $wetter = (array)($z['_w'] ?? []);
        $daten['sperre'] = $notfall !== '' ? $this->t('Emergency: %s – all devices up', $notfall)
            : (in_array('einfahren', $wetter, true) ? $this->t('Weather protection active') : '');

        $w = (array)($z['_waerme'] ?? []);
        $w = ($w['tag'] ?? '') === date('Y-m-d', $now) ? $w : null;
        foreach ($geraete as $g) {
            if ($g['Position'] <= 0 || !IPS_VariableExists($g['Position'])) {
                continue;
            }
            [$zu, $lam] = $this->istStellung($g);
            $rt = $this->messwert((int)$g['RaumTemp']);
            $rl = $this->lux((int)$g['RaumHell']);
            $ziel = (int)$g['ZielLux'] > 0 ? (float)$g['ZielLux'] : $this->ReadPropertyFloat('ZielLux');
            $daten['geraete'][] = ['name' => $g['Name'], 'typ' => $g['Typ'], 'fassade' => $g['Fassade'],
                'zu' => $zu === null ? null : round($zu, 2), 'lamelle' => $lam === null ? null : round($lam),
                'schalter' => 'Aktiv' . $g['Position'], 'aktiv' => $this->geraetAktiv($g),
                'raumTemp' => $rt === null ? null : round($rt, 1), 'raumLux' => $rl === null ? null : (int)round($rl), 'zielLux' => $ziel > 0 ? (int)$ziel : null,
                'grund' => $this->kachelGrund((string)($z[$g['key']]['grund'] ?? ''), (array)($wege[$g['Name']] ?? [])),
                'weg' => $wege[$g['Name']] ?? [], 'waerme' => $w === null ? null : (int)round((float)($w['je'][$g['key']] ?? 0))];
        }
        $daten['waerme'] = $this->kachelWaerme($w, $wm2 !== null, $bahn);
        $daten['vorschau'] = $this->kachelVorschau($bahn, $daten['fassaden'], $now);
        return $daten;
    }

    /**
     * Vorschau (Idee 3): Wann scheint die Sonne heute noch kräftig auf welche Fassade? Sonnenzeit der Fassade (Horizont, Nachbarn)
     * × erwartete Einstrahlung (Wolken aus der PV-Prognose, sonst klarer Himmel) ≥ Schwelle „Beschatten ab“. 10-min-Raster, ab jetzt.
     * Rückgabe ['basis' => 'forecast'|'clearSky', 'zeiten' => [[Kennung, Name, von, bis], …]].
     */
    private function kachelVorschau(array $bahn, array $fassaden, int $now): array
    {
        $pv = $this->pvPrognose($now);
        $schwelle = $this->ReadPropertyFloat('SonneAn');
        $out = [];
        foreach ($fassaden as $f) {
            if (!$f['aktiv'] || $f['zeiten'] === []) {
                continue;
            }
            $start = null;
            $letzt = null;
            foreach ($bahn as $p) {
                [$ts, $az, $alt] = $p;
                $imFenster = false;
                foreach ($f['zeiten'] as $z) {
                    $imFenster = $imFenster || ($ts >= $z[0] && $ts <= $z[1]);
                }
                $k = $pv === null ? 1.0 : VSCHLogik::klarheit($this->pvWert($pv, (int)$ts), VSCHLogik::pvKlar($pv['gen'], $pv['pr'], (float)$alt, (float)$az));
                $hell = $imFenster && $ts + 600 >= $now && $alt > 0 && $k * VSCHLogik::klarHimmel((float)$alt) >= $schwelle;
                if ($hell) {
                    $start ??= max((int)$ts, $now);
                    $letzt = (int)$ts + 600;
                } elseif ($start !== null) {
                    $out[] = [$f['kennung'], $f['name'], $start, $letzt];
                    $start = null;
                }
            }
            if ($start !== null) {
                $out[] = [$f['kennung'], $f['name'], $start, $letzt];
            }
        }
        usort($out, fn($a, $b) => $a[2] <=> $b[2]);
        return ['basis' => $pv !== null ? 'forecast' : 'clearSky', 'zeiten' => $out];
    }

    /**
     * Sonnenwärme für die Kachel: heute ins Haus, heute abgehalten, jetzt, gestern und was bei klarem Himmel mit offenen Behängen heute möglich wäre (alles Wh/W).
     * null ohne Einstrahlungssensor.
     */
    private function kachelWaerme(?array $w, bool $sensor, array $bahn): ?array
    {
        if (!$sensor) {
            return null;
        }
        $w ??= [];
        $fassaden = $this->fassadenMap();
        $horizont = $this->horizontPunkte();
        $hind = [];
        $klar = 0.0;
        $gWert = $this->ReadPropertyFloat('GWert');
        foreach ($this->geraete() as $g) {
            $f = $fassaden[$g['Fassade']] ?? null;
            if ($f === null || $g['Position'] <= 0 || !VSCHLogik::zaehltFlaeche($g['Typ'], $g['Flaeche'])) {
                continue;
            }
            $faz = (float)($f['Azimut'] ?? 180);
            $hind[$g['Fassade']] ??= $this->hindernisPunkte($faz);
            foreach ($bahn as $p) {
                if ($p[2] <= 0) {
                    continue;
                }
                $hz = max(VSCHHorizont::hoehe($horizont, $p[1]), VSCHHorizont::hoehe($hind[$g['Fassade']], $p[1]));
                $an = VSCHLogik::sonneAufFassade($p[1], $p[2], $faz, $hz, false);
                $i = VSCHLogik::fassadeEinstrahlung(VSCHLogik::klarHimmel($p[2]), $p[2], $p[1], $faz, $an);
                $klar += VSCHLogik::sonnenwaerme($i, $g['Flaeche'], $gWert, 1.0)['offen'] * 10 / 60; // Bahn im 10-min-Raster
            }
        }
        return ['heute' => (float)($w['rein'] ?? 0), 'ab' => (float)($w['ab'] ?? 0), 'jetzt' => $w['jetzt'] ?? null, 'jetztAb' => $w['jetztAb'] ?? null,
            'gestern' => $w['gestern'] ?? null, 'klar' => (int)round($klar), 'geschaetzt' => count(array_filter($this->geraete(), fn($g) => $g['Flaeche'] <= 0 && $g['Typ'] !== 'markise')) > 0];
    }

    /** Texte der Kachel (einzeln aufgeführt, damit der Prüfstand jede Übersetzung sieht). */
    private function kachelTexte(): array
    {
        return [
            'Automatic' => $this->t('Automatic'),
            'Expected today: shading %s' => $this->t('Expected today: shading %s'),
            'Expected today: sun on %s – the devices stay up, the sun heats with us' => $this->t('Expected today: sun on %s – the devices stay up, the sun heats with us'),
            'Expected today: sun on %s' => $this->t('Expected today: sun on %s'),
            'No strong sun expected today any more' => $this->t('No strong sun expected today any more'),
            'if the sky is clear' => $this->t('if the sky is clear'),
            'expected' => $this->t('expected'),
            'Solar heat today' => $this->t('Solar heat today'),
            'into the house' => $this->t('into the house'),
            'kept out' => $this->t('kept out'),
            'estimated' => $this->t('estimated'),
            'Window area not entered – 2 m² assumed per device' => $this->t('Window area not entered – 2 m² assumed per device'),
            'Clear sky with all devices open: about %s kWh today' => $this->t('Clear sky with all devices open: about %s kWh today'),
            'now' => $this->t('now'),
            'yesterday' => $this->t('yesterday'),
            'E' => $this->t('E'),
            'Heat pump' => $this->t('Heat pump'),
            'How the automatic works' => $this->t('How the automatic works'),
            'Neighbours off' => $this->t('Neighbours off'),
            'Neighbours on' => $this->t('Neighbours on'),
            'No device assigned' => $this->t('No device assigned'),
            'No sun on the facades' => $this->t('No sun on the facades'),
            'Shading' => $this->t('Shading'),
            'Sun on' => $this->t('Sun on'),
            'Sun path today' => $this->t('Sun path today'),
            'Today' => $this->t('Today'),
            'closed' => $this->t('closed'),
            'extended' => $this->t('extended'),
            'hidden by the surroundings' => $this->t('hidden by the surroundings'),
            'neighbouring buildings' => $this->t('neighbouring buildings'),
            'none' => $this->t('none'),
            'open' => $this->t('open'),
            'outside' => $this->t('outside'),
            'retracted' => $this->t('retracted'),
            'shaded' => $this->t('shaded'),
            'sun height' => $this->t('sun height'),
            'sun now' => $this->t('sun now'),
            'sun on the facade' => $this->t('sun on the facade'),
            'terrain' => $this->t('terrain'),
            'Irradiance' => $this->t('Irradiance'),
            'clear sky' => $this->t('clear sky'),
            'mostly sunny' => $this->t('mostly sunny'),
            'partly cloudy' => $this->t('partly cloudy'),
            'overcast' => $this->t('overcast'),
            'rain' => $this->t('rain'),
            'twilight' => $this->t('twilight'),
            'night' => $this->t('night'),
            'no sensor' => $this->t('no sensor'),
        ];
    }

    /** Eigenen WebHook eintragen (Verbund-Muster RoboHub/NRGDashboard). */
    private function registriereHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        $hooks = is_array($hooks) ? $hooks : [];
        foreach ($hooks as $i => $hook) {
            if (($hook['Hook'] ?? '') === $this->hookPfad()) {
                if ((int)$hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $this->hookPfad(), 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    /** Beim Löschen nur den eigenen Eintrag austragen. Destroy läuft auch beim Entladen – dann bleibt alles. */
    public function Destroy()
    {
        if (!IPS_InstanceExists($this->InstanceID)) {
            $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
            if (count($ids) > 0) {
                $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
                if (is_array($hooks)) {
                    $behalten = array_values(array_filter($hooks, fn($h) => !(($h['Hook'] ?? '') === $this->hookPfad() && (int)($h['TargetID'] ?? 0) === $this->InstanceID)));
                    if (count($behalten) !== count($hooks)) {
                        IPS_SetProperty($ids[0], 'Hooks', json_encode($behalten));
                        IPS_ApplyChanges($ids[0]);
                    }
                }
            }
        }
        parent::Destroy();
    }
}
