<?php

declare(strict_types=1);

/**
 * Automatische Erkennung: Behänge je Instanz, Fensterkontakte, Wetter- und Helligkeitssensoren.
 *
 * Arbeitet nur auf einer Momentaufnahme (reine Arrays), damit alles ohne Symcon prüfbar ist.
 * Instanz: ['name' => string, 'ort' => string (Name der übergeordneten Kategorie), 'modul' => GUID, 'modulName' => string,
 *           'kontext' => [Texte, in denen eine Ausrichtung stehen kann: Info-Feld, Namen der Ordner über der Instanz
 *                         und der Ordner, in denen Links auf sie liegen],
 *           'vars' => [[id, name, ident, typ (0 bool, 1 int, 2 float, 3 string), profil, aktion (bool), darstellung (GUID),
 *                       wahr (Beschriftung für true), vorlage (TEMPLATE-GUID), rollladen (['usage','open','close'] der Rollladen-Darstellung)], …]]
 *
 * Vorrang: Symcon-Vorlage (stärkstes Merkmal, z. B. „Fenster“, „Außentemperatur“, „Rollladen (Invertiert)“)
 * → Darstellung (Rollladen: Verwendungstyp Öffnung/Rotation) → Profil → Idents/Modulname → Namen.
 *
 * Erkennung in Stufen, damit sie bei jedem Nutzer greift und nicht an Namen hängt:
 *  sicher  – Darstellung „Shutter“ oder Profil ~Shutter/~Shutter.Reversed
 *  belegt  – die Instanz trägt Behang-Merkmale (Auf/Ab/Stopp, Lamelle/Tilt, Idents cover/roller/shutter/blind/slat,
 *            Modul- oder Instanzname); dann gilt ihre Prozent-/Level-Variable als Position (z. B. Z-Wave mit ~Intensity.100)
 * Nichts wird geraten, was nicht eindeutig ist: lieber leer lassen als falsch zuordnen.
 */
class VSCHErkennung
{
    /** Gruppensteuerung: schaltet nur andere Geräte, die ohnehin einzeln erkannt werden. */
    /** So viele Fenster-/Türkontakte kann ein Behang haben (Fensterfront, Hebe-Schiebe-Tür mit mehreren Flügeln). */
    public const MAX_KONTAKTE = 4;
    public const GRUPPE_GUID = '{F197064F-791F-0964-2B8E-563136E9B7B4}';
    /** WarnHub: seine eigenen Variablen sind keine Sensoren für die Verschattung (WarnHub wird direkt abgefragt). */
    public const WARNHUB_GUID = '{97621F9C-C61B-4A7F-9B03-C34BC58B04A6}';
    /** Symcon-8-Darstellung „Shutter“. */
    public const SHUTTER_DARSTELLUNG = '{6075FC22-69AF-B110-3749-C24138883082}';
    /** Symcon-Vorlagen (VARIABLE_TEMPLATE_*), ab Symcon 8.0. */
    public const V_FENSTER = '{A51A0A4B-97CC-4699-BFB6-5EF4B47B6664}';
    public const V_TUER = '{A206855F-E320-4E11-8D7B-4F360B2AAC4C}';
    public const V_REGEN = '{E613947C-8ABE-47DB-B8A9-83C0331FA326}';
    public const V_WIND = ['{D3E514E9-92A9-425E-8F1E-C837DFB086AC}', '{D29BF6AA-9ECA-44D9-8910-FE5366A80C66}'];
    public const V_HELLIGKEIT = '{18D580EF-DD37-40B3-8323-E90C52043E2D}';
    public const V_SOLAR = '{4B0B5196-C0F3-4DEC-9225-06E5C7BC7A21}';
    public const V_AUSSENTEMP = '{EF547792-2022-434E-B892-E38F1DC8C19A}';
    public const V_RAUMTEMP = ['{90AF8F8F-183F-BBFD-E078-35FAB6DCFE4F}', '{868B087E-A38D-2155-EBE0-157AFBBF9E8C}'];
    public const V_ROLLLADEN_INVERTIERT = '{7C50128A-B8B6-4FC1-9A06-EF2553921393}';
    public const V_LAMELLE = ['{3BE75DE9-7D84-C082-2E77-9ED3AEE04D63}', '{22A0DF9C-C200-154A-641B-3A3CB096DB6D}'];
    /** Steuer-/Automatikmodule, die selbst Behänge steuern – keine Geräte. */
    private const STEUERMODUL = '/blind ?control|verschattung|beschattung|shading|raffstore ?manager|rollladen ?steuerung|szene|scene/i';

    private const GENERISCH = '/^(jalousie|jalousien|rollladen|rolladen|rolllaeden|rollo|rollos|raffstore|raffstores|markise|markisen|fenster|tuer|tuere|terrassentuer|terrassentuere|balkontuer|balkontuere|haustuer|haustuere|kontakt|kontakte|oeffnungskontakt|oeffnungskontakte|fensterkontakt|tuerkontakt|sensor|sensoren|position|status|shutter|shutters|blind|blinds|window|windows|door|doors|contact|cover|raum|room|geraete|geraet|module|modul|og|eg|dg|ug|kg)$/';
    private const SEITE = '/^(links|rechts|left|right|mitte|middle|vorne|hinten)$/';

    // ------------------------------------------------------------------ Behänge

    /**
     * Alle Behänge, die noch nicht in der Liste stehen. Rückgabe: Zeilen für die Geräteliste.
     * @param int[] $vergeben schon zugeordnete Positionsvariablen
     */
    public static function geraete(array $instanzen, array $vergeben): array
    {
        $kontakte = self::kontakte($instanzen);
        $out = [];
        foreach ($instanzen as $iid => $i) {
            if (($i['modul'] ?? '') === self::GRUPPE_GUID || preg_match(self::STEUERMODUL, (string)($i['modulName'] ?? ''))) {
                continue;
            }
            $pos = self::position($i);
            if ($pos === null || in_array($pos[0], $vergeben, true)) {
                continue;
            }
            $lam = self::lamelle($i, $pos[0]);
            $typ = VSCHLogik::typRaten($i['name'] . ' ' . ($i['ort'] ?? ''))
                ?? VSCHLogik::kandidat($i['name'], $pos[1], $pos[2], $pos[4]);
            if ($typ === 'rollladen' && $lam !== null) {
                $typ = 'raffstore'; // ein Behang mit Lamellen ist kein Rollladen
            }
            $out[] = [
                'Name' => $i['name'], 'Fassade' => '', 'Typ' => $typ ?? ($lam !== null ? 'raffstore' : 'rollladen'),
                'Position' => $pos[0], 'Richtung' => 0, 'AzHinweis' => self::ausrichtung($i),
                'Lamelle' => $lam[0] ?? 0, 'LamelleUmgekehrt' => $lam !== null && stripos($lam[4], 'reversed') !== false,
                'Kontakt' => ($k = self::kontakteFuer($i['name'] . ' ' . ($i['ort'] ?? ''), $kontakte))[0] ?? 0,
                'Kontakt2' => $k[1] ?? 0, 'Kontakt3' => $k[2] ?? 0, 'Kontakt4' => $k[3] ?? 0, 'Beschattung' => 0,
            ];
        }
        return $out;
    }

    /**
     * Rolle einer Variable laut Rollladen-Darstellung/-Vorlage/-Profil: 'position', 'rotation' (Lamelle) oder null.
     * Verwendungstyp der Darstellung: 0 = Öffnung, 1 = Rotation.
     */
    public static function rollladenRolle(array $v): ?string
    {
        $vorlage = (string)($v[8] ?? '');
        if (($v[6] ?? '') === self::SHUTTER_DARSTELLUNG) {
            if (isset($v[9]['usage'])) {
                return (int)$v[9]['usage'] === 1 ? 'rotation' : 'position';
            }
            return in_array($vorlage, self::V_LAMELLE, true) || preg_match('/lamell|slat|tilt|winkel|angle/i', $v[1] . ' ' . $v[2]) ? 'rotation' : 'position';
        }
        if (in_array($vorlage, self::V_LAMELLE, true)) {
            return 'rotation';
        }
        if ($vorlage === self::V_ROLLLADEN_INVERTIERT) {
            return 'position';
        }
        if (preg_match('/^~lamella/i', $v[4])) {
            return 'rotation';
        }
        if (preg_match('/^~shutter(\.reversed)?$/i', $v[4])) {
            // Shelly u. a. geben ab Werk auch der Lamelle ~Shutter: dann entscheidet der Name/Ident
            return preg_match('/lamell|slat|tilt|winkel|angle/i', $v[1] . ' ' . $v[2]) ? 'rotation' : 'position';
        }
        return null;
    }

    /** Positionsvariable einer Instanz oder null (siehe Stufen oben). */
    public static function position(array $i): ?array
    {
        $belegt = self::behangMerkmale($i);
        $beste = null;
        foreach ($i['vars'] as $v) {
            if (!$v[5] || !in_array($v[3], [1, 2], true)) {
                continue;
            }
            $rolle = self::rollladenRolle($v);
            if ($rolle === 'position') {
                return $v; // sicher: Vorlage, Darstellung oder Profil sagt Rollladen-Öffnung
            }
            if ($rolle === 'rotation' || self::istBegleiter($v)) {
                continue;
            }
            if ($belegt && $beste === null
                && (preg_match('/position|level|hoehe|höhe|height|stellung|value|wert|intensity|percent|prozent|cover/i', $v[1] . ' ' . $v[2])
                    || preg_match('/intensity|percent|shutter|position/i', $v[4]))) {
                $beste = $v;
            }
            if ($beste === null && VSCHLogik::kandidat($i['name'], $v[1], $v[2], $v[4]) !== null) {
                $beste = $v; // Name des Geräts sagt Rollladen/Jalousie/Markise
            }
        }
        return $beste;
    }

    /** Trägt die Instanz Merkmale eines Behangs (unabhängig von Namen, die der Nutzer vergeben hat)? */
    public static function behangMerkmale(array $i): bool
    {
        if (VSCHLogik::typRaten($i['name'] . ' ' . ($i['ort'] ?? '') . ' ' . ($i['modulName'] ?? '')) !== null
            || preg_match('/roller|cover|shutter|blind|jalousie|awning|markise|store\b/i', (string)($i['modulName'] ?? ''))) {
            return true;
        }
        foreach ($i['vars'] as $v) {
            if (preg_match('/shuttermove|lamella|shutter/i', $v[4]) || self::rollladenRolle($v) !== null
                || preg_match('/cover|roller|shutter|blind|slat|tilt|lamell|jalousie|awning/i', $v[2])) {
                return true;
            }
        }
        return false;
    }

    /** Begleitvariable eines Behangs, nie die Position: Lamelle, Auf/Ab/Stopp, Sicherung, Geschwindigkeit … */
    private static function istBegleiter(array $v): bool
    {
        return preg_match('/movestop|movestep|protection|lamella|slat|tilt|switch|direction|speed/i', $v[4]) === 1
            || preg_match('/lamell|slat|tilt|winkel|angle|sicherung|protection|schutz|status|stop|richtung|direction|speed|geschwindigkeit|laufzeit|kalibr|energie|energy|leistung|power|strom|current|spannung|voltage|temperatur|temperature|meter|szene|scene/i', $v[1]) === 1
            || preg_match('/lamell|slat|tilt|winkel|angle|protection|power|energy|current|voltage|temp|meter|scene|direction|state$/i', $v[2]) === 1;
    }

    /** Lamellen-/Drehwinkelvariable derselben Instanz oder null. */
    public static function lamelle(array $i, int $ohne): ?array
    {
        foreach ($i['vars'] as $v) {
            if ($v[0] !== $ohne && $v[5] && in_array($v[3], [1, 2], true)
                && (self::rollladenRolle($v) === 'rotation' || preg_match('/lamell|slat|tilt|winkel|angle/i', $v[1] . ' ' . $v[2]))) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Ausrichtung des Behangs aus dem Objektbaum, sonst null. Reihenfolge: Gerätename → Info-Feld
     * („Ausrichtung: Süd“ oder „166°“) → Ordner über der Instanz bzw. Ordner mit Links auf sie („Südseite“, „Fassade West“).
     */
    public static function ausrichtung(array $i): ?float
    {
        foreach (array_merge([$i['name']], (array)($i['kontext'] ?? [])) as $text) {
            $az = self::richtungAusText((string)$text);
            if ($az !== null) {
                return $az;
            }
        }
        return null;
    }

    /** Himmelsrichtung (Wort oder Gradzahl mit °) in einem Text → Azimut, sonst null. */
    public static function richtungAusText(string $text): ?float
    {
        // Gradzahl nur mit „Ausrichtung“ davor oder allein („166°“) – nicht „Anzeige um 180° drehen“ (Live-Befund 24.11.)
        if (preg_match('/(?:ausrichtung|orientation)\D{0,3}(\d{1,3}(?:[.,]\d)?)\s*°?|^\s*(\d{1,3}(?:[.,]\d)?)\s*°\s*$/iu', $text, $m)) {
            $g = (float)str_replace(',', '.', $m[1] !== '' ? $m[1] : $m[2]);
            if ($g >= 0 && $g < 360) {
                return $g;
            }
        }
        $n = self::norm($text);
        $worte = ['nordost' => 45, 'nordwest' => 315, 'suedost' => 135, 'suedwest' => 225, 'northeast' => 45, 'northwest' => 315,
            'southeast' => 135, 'southwest' => 225, 'nord' => 0, 'north' => 0, 'ost' => 90, 'east' => 90, 'sued' => 180, 'south' => 180, 'west' => 270];
        foreach ($worte as $wort => $az) {
            // Wortanfang: „Südseite“, „Fassade West“, „Ostflügel“ – aber nicht „Kost“
            if (preg_match('/(^|\s)' . $wort . '(en|seite|fassade|fluegel|front|wand|lich|lage)?(\s|$)/', $n)) {
                return (float)$az;
            }
        }
        return null;
    }

    /** Nächstliegende Fassade (Kennung) zu einem Azimut, höchstens 45° daneben, sonst ''. */
    public static function fassadeZuAzimut(?float $az, array $fassaden): string
    {
        if ($az === null) {
            return '';
        }
        $beste = '';
        $abstand = 45.01;
        foreach ($fassaden as $f) {
            $d = abs(fmod(abs((float)$f['Azimut'] - $az) + 180, 360) - 180);
            if ($d < $abstand) {
                $abstand = $d;
                $beste = (string)$f['Kennung'];
            }
        }
        return $beste;
    }

    // ------------------------------------------------------------------ Fensterkontakte

    /** Fenster-/Türkontakte: [[Variablen-ID, Name der Instanz samt Ort], …] */
    public static function kontakte(array $instanzen): array
    {
        $out = [];
        foreach ($instanzen as $i) {
            $text = self::norm($i['name'] . ' ' . ($i['ort'] ?? ''));
            $wahl = null;
            foreach ($i['vars'] as $v) {
                if ($v[3] === 0 && in_array((string)($v[8] ?? ''), [self::V_FENSTER, self::V_TUER], true)) {
                    $wahl = $v; // Vorlage Fenster/Tür: sicher, auch ohne passenden Namen
                    break;
                }
            }
            if ($wahl !== null) {
                $out[] = [$wahl[0], $i['name'] . ' ' . ($i['ort'] ?? '')];
                continue;
            }
            if (!preg_match('/fenster|tuer|window|door|balkon|kontakt|contact|oeffnung/', $text) || preg_match('/schloss|lock|kamera|cam\b|klingel|bell/', $text)) {
                continue;
            }
            foreach ($i['vars'] as $v) {
                if ($v[3] !== 0 || preg_match('/ersatz|batter|sabotage|tamper|erreichbar|reachable|lock|alarm|update/i', $v[1] . ' ' . $v[2] . ' ' . $v[4])) {
                    continue;
                }
                if (preg_match('/window|door|contact/i', $v[4]) || preg_match('/geschlossen|geöffnet|offen|closed|open/i', (string)($v[7] ?? ''))) {
                    $wahl = $v;
                    break;
                }
                if ($wahl === null && preg_match('/booleanstate|contact|^state$|status|zustand|kontakt|offen|open/i', $v[1] . ' ' . $v[2])) {
                    $wahl = $v;
                }
            }
            if ($wahl !== null) {
                $out[] = [$wahl[0], $i['name'] . ' ' . ($i['ort'] ?? '')];
            }
        }
        return $out;
    }

    /** Erster passender Kontakt (Kurzform von kontakteFuer). */
    public static function kontaktFuer(string $geraet, array $kontakte): int
    {
        return self::kontakteFuer($geraet, $kontakte)[0] ?? 0;
    }

    /**
     * Passende Fenster-/Türkontakte zum Gerätenamen: alle im selben Raum, höchstens MAX_KONTAKTE.
     * Mehrere Kontakte gehören oft zu einem Behang (zwei Flügel, „gekippt“/„offen“, Fensterfront) – ist einer offen,
     * gilt das Fenster als offen; ein Kontakt zu viel verhindert höchstens das Herunterfahren (die sichere Richtung).
     * Seitenangaben/Nummern trennen, wenn beide Namen sie tragen. Mehr als MAX_KONTAKTE Treffer: leer, nicht raten.
     * (Live-Befund 24.11.: Schlafzimmer Fenster Links/Rechts an einer Jalousie.)
     */
    public static function kontakteFuer(string $geraet, array $kontakte): array
    {
        $d = self::teile($geraet);
        if ($d['raum'] === []) {
            return [];
        }
        $treffer = [];
        foreach ($kontakte as $k) {
            $c = self::teile($k[1]);
            if (!self::gleicherRaum($d['raum'], $c['raum'])) {
                continue;
            }
            if ($d['nr'] !== [] && $c['nr'] !== [] && array_intersect($d['nr'], $c['nr']) === []) {
                continue;
            }
            if ($d['seite'] !== [] && $c['seite'] !== [] && array_intersect($d['seite'], $c['seite']) === []) {
                continue;
            }
            $treffer[] = $k[0];
        }
        return count($treffer) <= self::MAX_KONTAKTE ? $treffer : [];
    }

    private static function gleicherRaum(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                $n = min(strlen($x), strlen($y));
                $gemeinsam = 0;
                while ($gemeinsam < $n && $x[$gemeinsam] === $y[$gemeinsam]) {
                    $gemeinsam++;
                }
                if ($gemeinsam >= 3) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Name zerlegen in Raumwörter, Nummern (römisch → arabisch) und Seitenangaben. */
    public static function teile(string $s): array
    {
        $r = ['raum' => [], 'nr' => [], 'seite' => []];
        $rom = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5'];
        foreach (preg_split('/\s+/', self::norm($s), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (isset($rom[$t]) || ctype_digit($t)) {
                $r['nr'][] = $rom[$t] ?? $t;
            } elseif (preg_match(self::SEITE, $t)) {
                $r['seite'][] = ['left' => 'links', 'right' => 'rechts', 'middle' => 'mitte'][$t] ?? $t;
            } elseif (strlen($t) >= 3 && !preg_match(self::GENERISCH, $t)) {
                $r['raum'][] = $t;
            }
        }
        return $r;
    }

    /**
     * true = der Kontakt meldet „geschlossen“ mit true.
     * Reihenfolge: Beschriftung der Darstellung für true („Geschlossen“/„Closed“) → Profil (~Window: true = offen,
     * „.Reversed“ umgekehrt) → ohne beides melden Matter (BooleanState) und Zigbee2MQTT (contact) „geschlossen“ = true.
     */
    public static function kontaktUmgekehrt(string $profil, string $nameIdent, string $wahr = ''): bool
    {
        if (preg_match('/geschlossen|closed|zu\b|shut/i', $wahr)) {
            return true;
        }
        if (preg_match('/offen|geöffnet|open|auf\b/i', $wahr)) {
            return false;
        }
        if ($profil !== '') {
            return stripos($profil, 'reversed') !== false;
        }
        return preg_match('/booleanstate|\bcontact\b/i', $nameIdent) === 1;
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    // ------------------------------------------------------------------ Sensoren

    /**
     * Wind, Regen, Sperre (Wind-/Unwetteralarm), Helligkeit, Temperatur: je Rolle der beste eindeutige Kandidat.
     * $warnHub: ['instanz' => int, 'vars' => int[]] – wertet WarnHub eine Wetterstation aus, ordnet die Verschattung
     * Wind und Regen gar nicht zu (WarnHub ist dann der einzige Auswerter, abgestimmt 20.11.2026).
     * Rückgabe: ['WindID' => id|0, …, 'hinweise' => [rolle => text-schlüssel]]
     */
    public static function sensoren(array $instanzen, array $warnHub): array
    {
        $rollen = ['WindID' => [], 'RegenID' => [], 'SperreID' => [], 'HelligkeitID' => [], 'TemperaturID' => []];
        $belegt = [];
        foreach ($instanzen as $iid => $i) {
            $ort = self::norm($i['name'] . ' ' . ($i['ort'] ?? ''));
            if (preg_match('/prognose|forecast|vorhersage/', $ort) || ($i['modul'] ?? '') === self::WARNHUB_GUID) {
                continue;
            }
            foreach ($i['vars'] as $v) {
                [$id, $name, $ident, $typ, $profil] = $v;
                $n = self::norm($name . ' ' . $ident);
                $p = strtolower($profil);
                if (preg_match('/openweathermap|forecast/', $p) || preg_match('/#\d/', $name)) {
                    continue;
                }
                $vonWarnHub = $iid === ($warnHub['instanz'] ?? -1) || in_array($id, $warnHub['vars'] ?? [], true);
                $s = self::wertung($n, $ort, $p, $typ, (string)($v[8] ?? ''));
                foreach ($s as $rolle => $punkte) {
                    if ($vonWarnHub && in_array($rolle, ['WindID', 'RegenID', 'SperreID'], true)) {
                        $belegt[$rolle] = true;
                        continue;
                    }
                    $rollen[$rolle][] = [$punkte, $id];
                }
            }
        }
        $out = ['hinweise' => []];
        foreach ($rollen as $rolle => $liste) {
            usort($liste, fn($a, $b) => $b[0] <=> $a[0]);
            $eindeutig = $liste !== [] && (count($liste) === 1 || $liste[0][0] > $liste[1][0]);
            $out[$rolle] = $eindeutig && !isset($belegt[$rolle]) ? $liste[0][1] : 0;
            if (isset($belegt[$rolle])) {
                $out['hinweise'][$rolle] = 'warnhub';
            } elseif ($liste !== [] && !$eindeutig) {
                $out['hinweise'][$rolle] = 'mehrdeutig';
            }
        }
        return $out;
    }

    /** Punkte je Rolle (höher = besser), leer wenn die Variable für keine Rolle taugt. */
    private static function wertung(string $n, string $ort, string $p, int $typ, string $vorlage = ''): array
    {
        $s = [];
        // Symcon-Vorlage zuerst: sie sagt die Bedeutung ausdrücklich
        $zahlV = in_array($typ, [1, 2], true);
        $ausgeschlossen = preg_match('/max|tag|day|durchschnitt|avg|mittel|gleitend|prognose|forecast/', $n) === 1;
        if (in_array($vorlage, self::V_WIND, true) && $zahlV && !$ausgeschlossen && !preg_match('/richtung|direction/', $n)) {
            return ['WindID' => 10];
        }
        if ($vorlage === self::V_REGEN) {
            return ['RegenID' => 10];
        }
        if ($vorlage === self::V_AUSSENTEMP && $zahlV && !$ausgeschlossen) {
            return ['TemperaturID' => 10];
        }
        if ($vorlage === self::V_HELLIGKEIT && $zahlV && !preg_match('/innen|indoor/', $n . ' ' . $ort)) {
            return ['HelligkeitID' => preg_match('/aussen|outdoor|outside|wetter|weather|dach|roof/', $n . ' ' . $ort) ? 10 : 5];
        }
        if ($vorlage === self::V_SOLAR || in_array($vorlage, self::V_RAUMTEMP, true)) {
            return []; // Einstrahlung ist keine Helligkeit, Raumtemperatur keine Außentemperatur
        }
        $aussen = preg_match('/aussen|outdoor|outside|wetter|weather|dach|roof/', $n . ' ' . $ort) === 1;
        $zahl = in_array($typ, [1, 2], true);
        // Wind: aktuelle Böe vor Mittelwert; Tageshöchstwerte und Richtungen nie
        $station = preg_match('/wetter|weather|station/', $ort) === 1 ? 2 : 0; // feste Wetterstation vor Sensoren anderer Geräte
        // Sperre: Alarmmeldung einer Wetterstation/Wetterzentrale (z. B. KNX-Windalarm), true = Gefahr
        if ($typ === 0 && preg_match('/windalarm|sturmalarm|wind alarm|storm alarm|wetteralarm|unwetter|windwarnung|wind warning|sturmwarnung/', $n)) {
            $s['SperreID'] = 3 + $station;
        }
        if ($zahl && (str_contains($p, 'windspeed') || preg_match('/\bwind|windboe|\bboee?\b|gust/', $n))
            && !preg_match('/richtung|direction|dir\b|max|tag|day|durchschnitt|avg|mittel|chill|gefuehlt|windfang/', $n)) {
            $s['WindID'] = (preg_match('/windboe|\bboee?\b|gust/', $n) ? 2 : 1) + $station;
        }
        // Regen: Regenmelder (bool) vor Regenrate; Mengen über Zeiträume nie
        if (preg_match('/regen|rain/', $n . ' ' . $p) && !preg_match('/menge|amount|total|tag|day|woche|week|monat|month|jahr|year|24h|stunde|hour|aktuell|event|wahrscheinlich|probab/', $n)) {
            if ($typ === 0) {
                $s['RegenID'] = 2 + $station;
            } elseif ($zahl && preg_match('/rate/', $n)) {
                $s['RegenID'] = 1 + $station;
            }
        }
        // Helligkeit: nur Lux außen; Einstrahlung (W/m²) und Innenraumsensoren nie
        if ($zahl && $aussen && (str_contains($p, 'illumination') || preg_match('/helligkeit|lux|illumin|brightness/', $n))
            && !preg_match('/solar|einstrahlung|radiation|uv|led/', $n)) {
            $s['HelligkeitID'] = 1 + $station;
        }
        // Temperatur: Außentemperatur mit Temperaturprofil, keine abgeleiteten Werte oder Sollwerte
        if ($zahl && $aussen && (str_contains($p, 'temperature') && !str_contains($p, 'room'))
            && !preg_match('/gefuehlt|feels|taupunkt|dew|innen|indoor|gleitend|mittel|mw|heizkurve|kuehlkurve|grenze|freigabe|soll|setpoint|rohr|boden/', $n)) {
            $s['TemperaturID'] = (preg_match('/aussentemperatur|outdoor|outside/', $n) ? 2 : 1) + $station;
        }
        return $s;
    }
}
