<?php

declare(strict_types=1);

// ===========================================================================
// Entscheidungen der Verschattung, ohne Symcon (der Prüfstand ruft sie direkt).
// Das Modul sammelt Werte (Sonne, Sensoren, Wetter, Gerätestand), ruft
// entscheide() je Gerät und führt das Ergebnis aus.
//
// Grundsätze:
//   - Wetterschutz geht immer vor, auch vor Handbedienung.
//   - Handbedienung hat sonst Vorrang: das Gerät bleibt in Ruhe, bis sich die
//     Beschattungslage ändert (Sonne kommt oder geht).
//   - Zurückgefahren wird nur, was die Verschattung selbst gefahren hat, und
//     nur bei Tageslicht. Abends übernimmt der Nutzer bzw. eine andere
//     Automatik (z. B. Rollladen nach Dämmerung).
//   - Markise ohne Wetterquelle fährt nie automatisch aus, außer der Nutzer
//     hat das ausdrücklich bestätigt.
// ===========================================================================

final class VSCHLogik
{
    public const TYPEN = ['rollladen', 'raffstore', 'markise'];

    /** Voreinstellungen je Typ (alles im Formular änderbar). Positionen in Prozent „zu“ bzw. „ausgefahren“. */
    public const VORGABEN = [
        'rollladen' => ['beschattung' => 75.0, 'wind' => 0.0, 'regen' => false],
        'raffstore' => ['beschattung' => 100.0, 'wind' => 50.0, 'regen' => false],
        'markise'   => ['beschattung' => 100.0, 'wind' => 35.0, 'regen' => true],
    ];

    /** Halber Öffnungswinkel: so weit seitlich darf die Sonne stehen und trifft die Fassade noch (Grad). */
    public const OEFFNUNG = 80.0;
    /** Mindesthöhe der Sonne über dem Horizont, damit sie zählt (Grad). */
    public const UEBER_HORIZONT = 1.0;
    /** Verhältnis Lamellenabstand / Lamellenbreite (typisch 0,8–0,9). */
    public const LAMELLE_D_W = 0.85;

    /**
     * Trifft direkte Sonne die Fassade? $vorher = bisheriger Zustand (Hysterese 2° bzw. 0,5°).
     */
    public static function sonneAufFassade(float $az, float $alt, float $fassadeAz, float $horizont, bool $vorher, float $oeffnung = self::OEFFNUNG): bool
    {
        $seit = VSCHSonne::winkelAbstand($az, $fassadeAz);
        $grenzeSeite = $oeffnung + ($vorher ? 2.0 : 0.0);
        $grenzeHoehe = $horizont + self::UEBER_HORIZONT - ($vorher ? 0.5 : 0.0);
        return $alt > 0 && $alt > $grenzeHoehe && $seit < $grenzeSeite;
    }

    /** Lamellenwinkel (0 = waagerecht, 90 = zu), der direkte Sonne gerade abhält („Cut-off“). */
    public static function lamellenWinkel(float $profilwinkel, float $dw = self::LAMELLE_D_W): float
    {
        $phi = deg2rad(max(0.0, min(89.0, $profilwinkel)));
        $x = $dw * cos($phi);
        $beta = rad2deg(asin(max(-1.0, min(1.0, $x)))) - rad2deg($phi);
        return max(0.0, min(90.0, $beta));
    }

    /**
     * Entscheidung für ein Gerät.
     *
     * $g Gerät: typ, az (Fassade), beschattung (%), sonneAn (bool, nur Geometrie, mit Hysterese vom Modul),
     *    profil (Profilwinkel°), lamellen (bool).
     * $u Umwelt: hell (?bool, null = kein Sensor), warm (?bool, null = keine Bedingung), wetter: ['art' => ''|'einfahren'|'festhalten',
     *    'grund' => string], wetterQuelle (bool), ohneWetterBestaetigt (bool), fensterOffen (bool), automatik (bool), tag (bool: Sonne über 0°).
     * $z Zustand: soll (bool, zuletzt entschiedener Beschattungswunsch), kandidat (?bool), kandidatSeit (int),
     *    hand (?bool: bei Handbedienung gemerkter Wunsch), wir (bool: Gerät steht auf unserem Befehl).
     * $t Zeiten: an (s, Verzögerung bis Beschatten), aus (s, Verzögerung bis Ende).
     *
     * Rückgabe ['ziel' => ?string ('beschatten'|'offen'|null = nichts tun), 'lamelle' => ?float (0–90), 'grund' => string, 'z' => neuer Zustand]
     */
    public static function entscheide(array $g, array $u, array $z, array $t, int $now): array
    {
        $z += ['soll' => false, 'kandidat' => null, 'kandidatSeit' => 0, 'hand' => null, 'wir' => false];
        $typ = (string)$g['typ'];
        $weg = (array)($u['weg'] ?? []); // Entscheidungsweg für „Warum?“ (das Modul gibt die Messwerte vor)
        $fertig = function (?string $ziel, ?float $lamelle, string $grund, array $z, ?float $anteil = null) use (&$weg): array {
            $weg[] = '→ ' . $grund;
            return ['ziel' => $ziel, 'lamelle' => $lamelle, 'grund' => $grund, 'z' => $z, 'anteil' => $anteil, 'weg' => $weg];
        };

        // 0. Notfall (Rauch-/Brandmelder): alles hoch, vor allem anderen – auch nachts, bei Handbedienung und ausgeschalteter Automatik
        if ((string)($u['notfall'] ?? '') !== '') {
            $z['hand'] = null;
            $z['soll'] = false;
            $z['kandidat'] = null;
            return $fertig('offen', null, VSCHText::t('Emergency: %s – up', (string)$u['notfall']), ['wir' => true] + $z);
        }

        // 1. Wetterschutz
        $wetter = (string)($u['wetter']['art'] ?? '');
        if ($wetter === 'einfahren' && $typ !== 'rollladen') {
            $z['hand'] = null;
            $z['soll'] = false;
            $z['kandidat'] = null;
            return $fertig('offen', null, VSCHText::t('Weather protection: %s', (string)($u['wetter']['grund'] ?? '')), ['wir' => true] + $z);
        }
        // WarnHub hat genau dieses Gerät gesichert (Vertrag protection 1.0): nicht bewegen, auch Rollläden nicht
        if (($u['schutz'] ?? null) !== null) {
            return $fertig(null, null, VSCHText::t('Secured by WarnHub: %s', (string)$u['schutz']), $z);
        }
        if ($wetter === 'festhalten' && $typ !== 'rollladen') {
            return $fertig(null, null, VSCHText::t('Not moving: %s', (string)($u['wetter']['grund'] ?? '')), $z);
        }

        // 2. Wunsch: Der Behang ist die günstigste Heizung und Kühlung des Raums.
        //    Heizbedarf → Sonne herein; Kühlbedarf → voll beschatten; neutral → nur Blendschutz (Tiefe), sonst offen.
        $wunsch = (bool)$g['sonneAn'];
        $anteil = null; // null = volle Beschattungsposition des Geräts
        $warum = $wunsch ? VSCHText::t('Sun on the facade') : (($u['tag'] ?? true) ? VSCHText::t('No direct sun on the facade') : VSCHText::t('Night'));
        $weg[] = $warum;
        if ($wunsch && ($u['hell'] ?? null) === false) {
            $wunsch = false;
            $warum = (string)($u['hellGrund'] ?? VSCHText::t('Sun on the facade, but not bright enough'));
            $weg[] = $warum;
        }
        $klima = $u['klima'] ?? null;
        if ($wunsch) {
            $tiefe = $g['tiefe'] ?? null; // [Anteil 0..1, Text] aus der Fenstergeometrie, null = nicht eingerichtet
            if ($klima === 'heizen') {
                $wunsch = false;
                $warum = (string)($u['klimaGrund'] ?? '');
            } elseif ($klima === 'kuehlen') {
                $warum = (string)($u['klimaGrund'] ?? $warum);
            } elseif (is_array($tiefe)) {
                if ((float)$tiefe[0] <= 0.02) {
                    $wunsch = false;
                } else {
                    $anteil = (float)$tiefe[0];
                }
                $warum = ($klima === 'neutral' ? (string)($u['klimaGrund'] ?? '') . ' · ' : '') . (string)$tiefe[1];
            } elseif ($klima === 'neutral') {
                $wunsch = false;
                $warum = (string)($u['klimaGrund'] ?? '');
            }
            $weg[] = $warum;
        }
        // Raumhelligkeit nur zum Herunterfahren: fällt die Sonne wirklich in diesen Raum? (Hochfahren nicht danach,
        // sonst Hin und Her – ist der Behang unten, wird der Raum dunkel.)
        if ($wunsch && !(bool)$z['soll'] && ($u['raumHell'] ?? null) === false) {
            $wunsch = false;
            $warum = (string)($u['raumHellGrund'] ?? VSCHText::t('The sun does not reach the room'));
            $weg[] = $warum;
        }
        if ($wunsch && $typ === 'markise' && !($u['wetterQuelle'] ?? false) && !($u['ohneWetterBestaetigt'] ?? false)) {
            $wunsch = false;
            $warum = VSCHText::t('Awning: no weather source, so no automatic extension');
            $weg[] = $warum;
        }

        // 3. Verzögerung: der neue Wunsch muss eine Weile anstehen
        if ($wunsch !== (bool)$z['soll']) {
            if ($z['kandidat'] !== $wunsch) {
                $z['kandidat'] = $wunsch;
                $z['kandidatSeit'] = $now;
            }
            $warte = $wunsch ? (int)$t['an'] : (int)$t['aus'];
            if ($now - (int)$z['kandidatSeit'] < $warte) {
                $rest = (int)ceil(($warte - ($now - (int)$z['kandidatSeit'])) / 60);
                return $fertig(null, null, $warum . ' · ' . VSCHText::t('waiting %d min', max(1, $rest)), $z);
            }
            $z['soll'] = $wunsch;
        }
        $z['kandidat'] = null;

        // 4. Handbedienung: Ruhe, bis sich die Lage ändert
        if ($z['hand'] !== null) {
            if ($z['hand'] === $z['soll']) {
                return $fertig(null, null, VSCHText::t('Operated by hand — automatic resumes when the sun situation changes'), $z);
            }
            $z['hand'] = null;
        }
        if (!($u['automatik'] ?? true)) {
            return $fertig(null, null, VSCHText::t('Automatic switched off'), $z);
        }

        // 5. Ausführen. Fenster/Tür: Tür offen → nicht herunter (Aussperrschutz); Fenster offen/gekippt → höchstens Lüftungsposition.
        $fenster = (string)($u['fenster'] ?? (($u['fensterOffen'] ?? false) ? 'sperre' : 'zu'));
        if ($z['soll']) {
            if ($typ !== 'markise' && $fenster === 'sperre') {
                return $fertig(null, null, VSCHText::t('Window/door open — not lowering'), $z);
            }
            if ($typ !== 'markise' && $fenster === 'lueften') {
                $l = (float)($u['lueftung'] ?? 0.7);
                $anteil = min($anteil ?? 1.0, $l);
                $warum .= ' · ' . VSCHText::t('window open: ventilation position %d %%', (int)round($l * 100));
            }
            $lamelle = ($typ === 'raffstore' && ($g['lamellen'] ?? false)) ? self::lamellenWinkel((float)$g['profil']) : null;
            return $fertig('beschatten', $lamelle, $warum, ['wir' => true] + $z, $anteil);
        }
        if ($z['wir'] && ($u['tag'] ?? true)) {
            return $fertig('offen', null, $warum, ['wir' => false] + $z);
        }
        return $fertig(null, null, $warum, $z);
    }

    /**
     * Heizen, Kühlen oder neutral – der Behang als günstigste Heizung und Kühlung des Raums.
     * Reihenfolge: Raum zu warm → kühlen; Wärmepumpe kühlt → kühlen; Wärmepumpe heizt → heizen; Raum unter der Heizgrenze → heizen;
     * außen Sommer → kühlen; außen kühl ohne Raumwert → heizen (Winter); Raum behaglich → neutral. Ohne Werte null.
     * $wp: Betriebsart nach Verbund-Enum (0 standby, 1 heizen, 2 kühlen, 3 WW, 4 heizen+WW, 5 kühlen+WW), null = keine WP.
     * Hysterese 0,5 K über $vorher. Rückgabe [?string 'heizen'|'kuehlen'|'neutral', Grundschlüssel].
     */
    public static function klima(?float $innen, ?float $aussen, ?int $wp, float $heizGrenze, float $innenGrenze, float $sommerAb, ?string $vorher): array
    {
        $hk = $vorher === 'kuehlen' ? 0.5 : 0.0;
        $hh = $vorher === 'heizen' ? 0.5 : 0.0;
        if ($innen !== null && $innen >= $innenGrenze - $hk) {
            return ['kuehlen', 'raum'];
        }
        if (in_array($wp, [2, 5], true)) {
            return ['kuehlen', 'wpkuehlt'];
        }
        if (in_array($wp, [1, 4], true)) {
            return ['heizen', 'wpheizt'];
        }
        if ($innen !== null && $innen < $heizGrenze + $hh) {
            return ['heizen', 'raumkalt'];
        }
        if ($aussen !== null && $aussen >= $sommerAb - $hk) {
            return ['kuehlen', 'sommer'];
        }
        if ($innen === null && $aussen !== null) {
            return ['heizen', 'winter'];
        }
        if ($innen !== null) {
            return ['neutral', 'behaglich'];
        }
        return [null, ''];
    }

    /**
     * Blendschutz nach Tiefe (senkrechtes Fenster): Wie weit muss der Behang herunter, damit die Sonne höchstens $tiefe cm
     * in den Raum fällt? Sonnenfleck-Tiefe am Boden = Höhe der freien Oberkante / tan(Profilwinkel).
     * Rückgabe [Anteil 0..1 des Fensters, das verdeckt werden muss, Tiefe in cm bei offenem Fenster] oder null (nicht eingerichtet).
     */
    public static function tiefeAnteil(float $profil, float $tiefe, float $hoehe, float $bruestung): ?array
    {
        if ($tiefe <= 0 || $hoehe <= 0) {
            return null;
        }
        if ($profil <= 0.5) {
            return [1.0, 9999.0];
        }
        $tan = tan(deg2rad(min(89.9, $profil)));
        $offen = ($bruestung + $hoehe) / $tan;
        $f = 1 - ($tiefe * $tan - $bruestung) / $hoehe;
        return [max(0.0, min(1.0, $f)), $offen];
    }

    /**
     * Wetterlage für einen Gerätetyp aus den Quellen. $q: wind (?float km/h), windSchwelle (float, 0 = aus),
     * windSeit (int: Zeit der letzten Überschreitung), regen (?bool), regenSeit (int), warnungen (Liste aus WHUB_GetActiveWarnings,
     * bereits mit 'von'/'bis' in Unixzeit), sperre (?bool externe Sperrvariable), netzBoe (?float km/h aus Wetterdaten), halte (s).
     * Rückgabe ['art' => ''|'einfahren'|'festhalten', 'grund' => string].
     */
    public static function wetter(string $typ, array $q, int $now): array
    {
        $halte = (int)($q['halte'] ?? 900);
        if (($q['sperre'] ?? null) === true) {
            return ['art' => 'einfahren', 'grund' => VSCHText::t('external lock active')];
        }
        $schwelle = (float)($q['windSchwelle'] ?? 0);
        if ($schwelle > 0) {
            if (($q['wind'] ?? null) !== null && (float)$q['wind'] >= $schwelle) {
                return ['art' => 'einfahren', 'grund' => VSCHText::t('wind gust %s km/h', self::zahl((float)$q['wind']))];
            }
            if ((int)($q['windSeit'] ?? 0) > 0 && $now - (int)$q['windSeit'] < $halte) {
                return ['art' => 'einfahren', 'grund' => VSCHText::t('wind, waiting %d min', (int)ceil(($halte - ($now - (int)$q['windSeit'])) / 60))];
            }
            // Wetterdaten aus dem Netz: vorsichtiger (10 km/h unter der Sensorschwelle)
            if (($q['netzBoe'] ?? null) !== null && (float)$q['netzBoe'] >= max(15.0, $schwelle - 10)) {
                return ['art' => 'einfahren', 'grund' => VSCHText::t('forecast gusts %s km/h', self::zahl((float)$q['netzBoe']))];
            }
        }
        if ($typ === 'markise' && ($q['regenAktiv'] ?? true)) {
            if (($q['regen'] ?? null) === true) {
                return ['art' => 'einfahren', 'grund' => VSCHText::t('rain')];
            }
            if ((int)($q['regenSeit'] ?? 0) > 0 && $now - (int)$q['regenSeit'] < $halte) {
                return ['art' => 'einfahren', 'grund' => VSCHText::t('rain, waiting %d min', (int)ceil(($halte - ($now - (int)$q['regenSeit'])) / 60))];
            }
        }
        foreach ((array)($q['warnungen'] ?? []) as $w) {
            $von = (int)($w['von'] ?? 0);
            $bis = (int)($w['bis'] ?? 0);
            if ($von > $now || ($bis > 0 && $bis + $halte < $now)) {
                continue;
            }
            $kat = (string)($w['category'] ?? '');
            $haz = (string)($w['hazard'] ?? '');
            $name = (string)($w['event'] ?? $kat);
            if (in_array($kat, ['sturm', 'hagel', 'gewitter'], true) || in_array($haz, ['wind', 'hail', 'thunderstorm'], true)) {
                return ['art' => 'einfahren', 'grund' => VSCHText::t('warning: %s', $name)];
            }
            if ($typ === 'markise' && ($kat === 'starkregen' || $haz === 'heavy_rain')) {
                return ['art' => 'einfahren', 'grund' => VSCHText::t('warning: %s', $name)];
            }
            if (in_array($haz, ['frost', 'ice'], true) || $kat === 'schnee' || $haz === 'snow') {
                return ['art' => 'festhalten', 'grund' => VSCHText::t('warning: %s (risk of freezing)', $name)];
            }
        }
        return ['art' => '', 'grund' => ''];
    }

    /**
     * WarnHub-Schutzaktionen (WHUB_GetProtectionState, Vertrag protection 1.x) für ein Gerät auswerten.
     * $aktionen = 'actions' des Vertrags, $vids = Variablen des Geräts (Position, Lamelle), $inst = deren Instanz,
     * $merk = [Aktionsindex => letzter Zeitpunkt „aktiv“] (wird fortgeschrieben), $halte = Haltezeit nach Ende (s).
     * Rückgabe: Grund (string) solange gesperrt, sonst null.
     */
    public static function schutz(array $aktionen, array $vids, int $inst, array &$merk, int $now, int $halte): ?string
    {
        $grund = null;
        foreach ($aktionen as $a) {
            if (!($a['enabled'] ?? true)) {
                continue;
            }
            $ziel = (int)($a['targetVariableID'] ?? 0);
            $zielInst = (int)($a['targetInstanceID'] ?? 0);
            if (!in_array($ziel, $vids, true) && !($inst > 0 && $zielInst === $inst)) {
                continue;
            }
            $k = (string)($a['index'] ?? $ziel);
            $ep = (array)($a['activeEpisodes'][0] ?? ($a['lastTrigger'] ?? []));
            $name = (string)($ep['event'] ?? ($a['name'] ?? ''));
            if ($a['active'] ?? false) {
                $merk[$k] = $now;
                $grund = $name;
                continue;
            }
            $r = $a['autoRestore'] ?? null;
            if (is_array($r) && ($r['dueAt'] === null || (int)$r['dueAt'] > $now)) {
                $grund = $grund ?? VSCHText::t('%s, WarnHub resets it', $name); // WarnHub stellt selbst zurück
                continue;
            }
            if (isset($merk[$k]) && $now - (int)$merk[$k] < $halte) {
                $grund = $grund ?? VSCHText::t('%s, waiting %d min', $name, (int)ceil(($halte - ($now - (int)$merk[$k])) / 60));
                continue;
            }
            unset($merk[$k]);
        }
        return $grund;
    }

    public static function zahl(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }

    /** Typ aus Namen/Profil raten (Markise, Raffstore, Rollladen) oder null. */
    public static function typRaten(string $text): ?string
    {
        $t = mb_strtolower($text);
        if (preg_match('/markise|awning|sonnensegel/', $t)) {
            return 'markise';
        }
        if (preg_match('/raffstore|jalousie|venetian|lamell|blind/', $t)) {
            return 'raffstore';
        }
        if (preg_match('/rollladen|rolladen|rollo|shutter|rolltor/', $t)) {
            return 'rollladen';
        }
        return null;
    }

    /**
     * Ist diese Variable eine Positionsvariable eines Behangs? Rückgabe: geratener Typ oder null.
     * Aussortiert werden Begleitvariablen derselben Geräteinstanz (Lamelle/Drehwinkel, Auf/Ab/Stopp,
     * Z-Wave-Sicherung, Geschwindigkeit …), damit die Vorschlagsliste nur echte Behänge zeigt.
     */
    public static function kandidat(string $instanzName, string $varName, string $ident, string $profil): ?string
    {
        $p = mb_strtolower($profil);
        if (preg_match('/movestop|movestep|protection|lamella|slat|tilt|switch|direction|speed/', $p)) {
            return null;
        }
        // Name: alle Begleitvariablen; Ident nur Lamellen-Hinweise (Gruppensteuerung u. a. nennen ihren Wert intern „Status“)
        if (preg_match('/lamell|slat|tilt|winkel|angle|sicherung|protection|schutz|status|stop|richtung|direction|speed|geschwindigkeit|laufzeit|kalibr/', mb_strtolower($varName))
            || preg_match('/lamell|slat|tilt|winkel|angle|protection/', mb_strtolower($ident))) {
            return null;
        }
        $typ = self::typRaten($instanzName . ' ' . $varName . ' ' . $p);
        if (preg_match('/^~shutter(\.reversed)?$/', $p)) {
            return $typ ?? 'rollladen';
        }
        return $typ;
    }

    /** Klarer Himmel: globale Einstrahlung (W/m², horizontal) bei Sonnenhöhe $alt (Haurwitz-Näherung). */
    public static function klarHimmel(float $alt): float
    {
        if ($alt <= 0) {
            return 0.0;
        }
        $s = sin(deg2rad($alt));
        return 1098 * $s * exp(-0.057 / $s);
    }

    /** Helligkeit in Lux ungefähr in W/m² (Sonnenlicht ≈ 120 lm/W). */
    public static function luxZuWm2(float $lux): float
    {
        return $lux / 120;
    }
}
