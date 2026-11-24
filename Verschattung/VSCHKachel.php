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
        $daten = ['automatik' => (bool)@$this->GetValue('Automatik'), 'bedienbar' => $bedienbar, 'geraete' => [], 'fassaden' => []];
        if ($bg !== '') {
            $daten['bg'] = '#' . substr(preg_replace('/[^0-9a-fA-F]/', '', $bg), 0, 6);
        }
        if ($pos === null) {
            return $daten + ($mitTexten ? ['texte' => $this->kachelTexte()] : []);
        }
        $sonne = VSCHSonne::stand($pos[0], $pos[1], $now);
        $daten['sonne'] = ['az' => round($sonne['az'], 1), 'alt' => round($sonne['alt'], 1)];
        $hid = $this->ReadPropertyInteger('HelligkeitID');
        $wm2 = $hid > 0 && IPS_VariableExists($hid) ? $this->wm2($hid) : null;
        $daten['einstrahlung'] = $wm2 === null ? null : (int)round($wm2);
        $daten['himmel'] = VSCHLogik::himmel($wm2, $sonne['alt'], $this->regen());
        $aussen = $this->messwert($this->ReadPropertyInteger('TemperaturID'));
        $daten['aussen'] = $aussen === null ? null : round($aussen, 1);

        // Fassaden: beschienen jetzt? Hauptfassade = die mit den meisten Geräten
        $haus = json_decode($this->ReadAttributeString('Haus'), true) ?: [];
        $fassaden = $this->listeWerte($this->ReadPropertyString('Fassaden'));
        $zahl = array_count_values(array_map(fn($g) => (string)$g['Fassade'], $this->geraete()));
        $haupt = null;
        foreach ($this->fassadenMitKanten($fassaden, $haus) as $i => $f) {
            $k = (string)($fassaden[$i]['Kennung'] ?? '');
            $an = $f['aktiv'] && VSCHLogik::sonneAufFassade($sonne['az'], $sonne['alt'], $f['az'],
                VSCHHorizont::hoehe($this->hindernisPunkte($f['az']), $sonne['az']), false);
            $daten['fassaden'][] = ['kennung' => $k, 'az' => $f['az'], 'aktiv' => $f['aktiv'], 'sonne' => $an,
                'seg' => array_map(fn($s) => [[round($s[0][0], 1), round($s[0][1], 1)], [round($s[1][0], 1), round($s[1][1], 1)]], (array)$f['seg'])];
            if ($f['aktiv'] && ($haupt === null || ($zahl[$k] ?? 0) > ($zahl[$haupt['k']] ?? 0))) {
                $haupt = ['k' => $k, 'az' => $f['az']];
            }
        }
        $daten['haus'] = array_map(fn($p) => [round($p[0], 1), round($p[1], 1)], (array)($haus['eigen'] ?? []));
        $daten['nachbarn'] = array_map(fn($n) => array_map(fn($p) => [round($p[0], 1), round($p[1], 1)], $n), (array)($haus['nachbarn'] ?? []));
        // Straßen im Nahbereich der Kachel (±30 m)
        $daten['strassen'] = array_values(array_filter(array_map(fn($st) => ['b' => (float)$st['b'], 'p' => (array)$st['p']], (array)($haus['strassen'] ?? [])),
            fn($st) => count(array_filter($st['p'], fn($p) => abs($p[0]) < 30 && abs($p[1]) < 30)) > 0));

        // Himmelsbogen: Bahn heute, Gelände, Gebäude, Sonnenzeit der Hauptfassade
        $bahn = VSCHSonne::bahn($pos[0], $pos[1], date('Y-m-d', $now), date_default_timezone_get(), 10);
        $daten['bahn'] = array_values(array_map(fn($p) => [round($p[1], 1), round($p[2], 1)], array_filter($bahn, fn($p) => $p[2] > -2)));
        $gelaende = $this->horizontPunkte();
        $daten['horizont'] = [];
        for ($a = 30; $a <= 330; $a += 5) {
            $daten['horizont'][] = [$a, round(VSCHHorizont::hoehe($gelaende, $a), 1)];
        }
        $daten['gebaeude'] = [];
        $daten['bahnAn'] = [];
        if ($haupt !== null) {
            $daten['fassadeAz'] = $haupt['az'];
            foreach ($this->gebaeudeHorizont($haupt['az']) as $a => $h) {
                if ($a % 2 === 0 && $h > VSCHHorizont::hoehe($gelaende, $a) + 0.2) {
                    $daten['gebaeude'][] = [$a, round($h, 1)];
                }
            }
            $hind = $this->hindernisPunkte($haupt['az']);
            $seg = [];
            foreach ($bahn as $p) {
                if (VSCHLogik::sonneAufFassade($p[1], $p[2], $haupt['az'], VSCHHorizont::hoehe($hind, $p[1]), false)) {
                    $seg[] = [round($p[1], 1), round($p[2], 1)];
                } elseif ($seg !== []) {
                    $daten['bahnAn'][] = $seg;
                    $seg = [];
                }
            }
            if ($seg !== []) {
                $daten['bahnAn'][] = $seg;
            }
            $fenster = VSCHGrafik::sonnenfenster($bahn, $hind, $haupt['az']);
            $daten['sonnenzeit'] = $fenster === [] ? $this->t('Facade %s: no direct sun today', $haupt['k'])
                : $this->t('Facade %s: sun today %s', $haupt['k'], implode(' + ', array_map(fn($w) => date('H:i', $w[0]) . '–' . date('H:i', $w[1]), $fenster)));
        }

        // Lage (Heizen/Kühlen/neutral) wie der Regeltakt sie zuletzt bestimmt hat, häufigste über die Geräte
        $z = $this->zustand();
        $lagen = array_count_values(array_filter(array_map('strval', (array)($z['_klima'] ?? []))));
        arsort($lagen);
        $lage = (string)(array_key_first($lagen) ?? '');
        $daten['lage'] = $lage;
        $daten['lageText'] = ['kuehlen' => $this->t('Cooling: shade'), 'heizen' => $this->t('Heating: let the sun in'),
            'neutral' => $this->t('Comfortable: glare protection only')][$lage] ?? '';
        $wp = $this->wpModus();
        if ($wp !== null) {
            $daten['lageText'] .= ($daten['lageText'] !== '' ? ' · ' : '') . $this->t('Heat pump: %s', $this->wpText($wp));
        }
        $notfall = $this->notfall();
        $wetter = (array)($z['_w'] ?? []);
        $daten['sperre'] = $notfall !== '' ? $this->t('Emergency: %s – all devices up', $notfall)
            : (in_array('einfahren', $wetter, true) ? $this->t('Weather protection active') : '');

        if ($wege === []) {
            $wege = $this->regeln(true);
        }
        foreach ($this->geraete() as $g) {
            if ($g['Position'] <= 0 || !IPS_VariableExists($g['Position'])) {
                continue;
            }
            [$zu, $lam] = $this->istStellung($g);
            $lam = $lam === null ? null : round($lam);
            $daten['geraete'][] = ['name' => $g['Name'], 'typ' => $g['Typ'], 'zu' => $zu === null ? null : round($zu, 2),
                'schalter' => 'Aktiv' . $g['Position'], 'aktiv' => $this->geraetAktiv($g),
                'lamelle' => $lam, 'grund' => $this->kachelGrund((string)($z[$g['key']]['grund'] ?? ''), (array)($wege[$g['Name']] ?? [])),
                'weg' => $wege[$g['Name']] ?? []];
        }
        if ($mitTexten) {
            $daten['texte'] = $this->kachelTexte();
            $daten['regeln'] = $this->regelnText();
        }
        return $daten;
    }

    /** Texte der Kachel (einzeln aufgeführt, damit der Prüfstand jede Übersetzung sieht). */
    private function kachelTexte(): array
    {
        return [
            'Automatic' => $this->t('Automatic'),
            'on' => $this->t('on'),
            'off' => $this->t('off'),
            'outside' => $this->t('outside'),
            'Irradiance' => $this->t('Irradiance'),
            'No device assigned' => $this->t('No device assigned'),
            'E' => $this->t('E'),
            'SE' => $this->t('SE'),
            'S' => $this->t('S'),
            'SW' => $this->t('SW'),
            'W' => $this->t('W'),
            'clear sky' => $this->t('clear sky'),
            'mostly sunny' => $this->t('mostly sunny'),
            'partly cloudy' => $this->t('partly cloudy'),
            'overcast' => $this->t('overcast'),
            'rain' => $this->t('rain'),
            'twilight' => $this->t('twilight'),
            'night' => $this->t('night'),
            'no sensor' => $this->t('no sensor'),
            'auto' => $this->t('auto'),
            'Neighbours on' => $this->t('Neighbours on'),
            'Neighbours off' => $this->t('Neighbours off'),
            'How the automatic works' => $this->t('How the automatic works'),
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
