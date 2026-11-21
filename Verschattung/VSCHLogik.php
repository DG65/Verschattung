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

        // 1. Wetterschutz
        $wetter = (string)($u['wetter']['art'] ?? '');
        if ($wetter === 'einfahren' && $typ !== 'rollladen') {
            $z['hand'] = null;
            $z['soll'] = false;
            $z['kandidat'] = null;
            return ['ziel' => 'offen', 'lamelle' => null, 'grund' => VSCHText::t('Weather protection: %s', (string)($u['wetter']['grund'] ?? '')), 'z' => ['wir' => true] + $z];
        }
        if ($wetter === 'festhalten' && $typ !== 'rollladen') {
            return ['ziel' => null, 'lamelle' => null, 'grund' => VSCHText::t('Not moving: %s', (string)($u['wetter']['grund'] ?? '')), 'z' => $z];
        }

        // 2. Wunsch aus Sonne, Helligkeit, Temperatur
        $wunsch = (bool)$g['sonneAn'];
        $warum = $wunsch ? VSCHText::t('Sun on the facade') : VSCHText::t('No direct sun on the facade');
        if ($wunsch && ($u['hell'] ?? null) === false) {
            $wunsch = false;
            $warum = VSCHText::t('Sun on the facade, but not bright enough');
        }
        if ($wunsch && ($u['warm'] ?? null) === false) {
            $wunsch = false;
            $warum = VSCHText::t('Sun on the facade, but below the temperature threshold');
        }
        if ($wunsch && $typ === 'markise' && !($u['wetterQuelle'] ?? false) && !($u['ohneWetterBestaetigt'] ?? false)) {
            $wunsch = false;
            $warum = VSCHText::t('Awning: no weather source, so no automatic extension');
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
                return ['ziel' => null, 'lamelle' => null, 'grund' => $warum . ' · ' . VSCHText::t('waiting %d min', max(1, $rest)), 'z' => $z];
            }
            $z['soll'] = $wunsch;
        }
        $z['kandidat'] = null;

        // 4. Handbedienung: Ruhe, bis sich die Lage ändert
        if ($z['hand'] !== null) {
            if ($z['hand'] === $z['soll']) {
                return ['ziel' => null, 'lamelle' => null, 'grund' => VSCHText::t('Operated by hand — automatic resumes when the sun situation changes'), 'z' => $z];
            }
            $z['hand'] = null;
        }
        if (!($u['automatik'] ?? true)) {
            return ['ziel' => null, 'lamelle' => null, 'grund' => VSCHText::t('Automatic switched off'), 'z' => $z];
        }

        // 5. Ausführen
        if ($z['soll']) {
            if (($u['fensterOffen'] ?? false) && $typ !== 'markise') {
                return ['ziel' => null, 'lamelle' => null, 'grund' => VSCHText::t('Window/door open — not lowering'), 'z' => $z];
            }
            $lamelle = ($typ === 'raffstore' && ($g['lamellen'] ?? false)) ? self::lamellenWinkel((float)$g['profil']) : null;
            return ['ziel' => 'beschatten', 'lamelle' => $lamelle, 'grund' => $warum, 'z' => ['wir' => true] + $z];
        }
        if ($z['wir'] && ($u['tag'] ?? true)) {
            return ['ziel' => 'offen', 'lamelle' => null, 'grund' => $warum, 'z' => ['wir' => false] + $z];
        }
        return ['ziel' => null, 'lamelle' => null, 'grund' => $warum, 'z' => $z];
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

    public static function zahl(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
}
