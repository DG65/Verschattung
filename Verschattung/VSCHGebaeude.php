<?php

declare(strict_types=1);

// ===========================================================================
// Nachbargebäude als Hindernis: Höhenwinkel je Himmelsrichtung, gesehen vom
// Fenster. Ohne Symcon (Prüfstand ruft es direkt).
//
// Gebäudemodell (Dietmar 24.11.: „zwei Geschosse + Dach, Dachneigung 45°“):
//   - Traufe = Geschosse × 3 m; ohne Angabe 2 Geschosse. Bekannte Gesamthöhe (OSM height) = Firsthöhe.
//   - Satteldach 45°: First in der Mitte, Firsthöhe = Traufe + halbe Gebäudebreite.
//   - First parallel zur längeren Seite (so sieht man es im Luftbild bei fast allen Satteldächern);
//     OSM roof:orientation=across dreht ihn, roof:shape=flat ergibt ein Flachdach.
// Für jeden Punkt der Traufkanten und des Firsts: Höhenwinkel = atan((Höhe − Augenhöhe) / Abstand).
// Der höchste Punkt einer Dachfläche liegt immer an Traufe oder First (45° Neigung, Winkel < 45°),
// darum genügen Kanten und First.
// ===========================================================================

final class VSCHGebaeude
{
    public const GESCHOSS_M = 3.0;
    public const GESCHOSSE_VORGABE = 2;
    public const AUGE_M = 2.0;      // Fenstermitte im Erdgeschoss über Gelände
    /** Nebengebäude: eingeschossig mit Flachdach, 3 m (ALKIS-Funktion, OSM building=…, sonst unter 40 m² Grundfläche). */
    public const NEBEN = '/garage|carport|schuppen|gartenhaus|geräte|geraete|nebengeb|überdach|ueberdach|gewächs|gewaechs|shed|hut|roof|greenhouse|kiosk|trafo|transformer|garages/i';
    public const NEBEN_FLAECHE_M2 = 40.0;
    private const SCHRITT_M = 0.5;

    /**
     * Modell eines Gebäudes. $p: Umriss [[x,y],…] in m (x Ost, y Nord), $info: ['stockwerke','hoehe','dach','first'].
     * Rückgabe ['traufe' => m, 'first' => m, 'linien' => [[[x,y,h],…],…]] (Kanten und Firstlinie mit Höhen).
     */
    public static function modell(array $p, array $info = []): array
    {
        $n = count($p);
        if ($n < 3) {
            return ['traufe' => 0.0, 'first' => 0.0, 'linien' => []];
        }
        // Längste Kante = Firstrichtung (Achse u), Breite quer dazu (Achse v)
        $best = 0.0;
        $u = [1.0, 0.0];
        for ($i = 0; $i < $n; $i++) {
            $a = $p[$i];
            $b = $p[($i + 1) % $n];
            $l = hypot($b[0] - $a[0], $b[1] - $a[1]);
            if ($l > $best) {
                $best = $l;
                $u = [($b[0] - $a[0]) / $l, ($b[1] - $a[1]) / $l];
            }
        }
        if (($info['first'] ?? '') === 'across') {
            $u = [-$u[1], $u[0]];
        }
        $v = [-$u[1], $u[0]];
        $su = array_map(fn($q) => $q[0] * $u[0] + $q[1] * $u[1], $p);
        $sv = array_map(fn($q) => $q[0] * $v[0] + $q[1] * $v[1], $p);
        [$u0, $u1, $v0, $v1] = [min($su), max($su), min($sv), max($sv)];
        $halb = ($v1 - $v0) / 2;
        $vm = ($v0 + $v1) / 2;
        $flaeche = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $flaeche += $p[$i][0] * $p[($i + 1) % $n][1] - $p[($i + 1) % $n][0] * $p[$i][1];
        }
        $flaeche = abs($flaeche) / 2;
        $neben = (int)($info['stockwerke'] ?? 0) === 0 && (float)($info['hoehe'] ?? 0) <= 0
            && (preg_match(self::NEBEN, (string)($info['art'] ?? '')) === 1 || $flaeche < self::NEBEN_FLAECHE_M2);
        if ($neben) {
            $info['stockwerke'] = 1;
            $info['dach'] = 'flat';
        }
        $flach = in_array((string)($info['dach'] ?? ''), ['flat', 'flach'], true);
        $dach = $flach ? 0.0 : $halb; // 45°: je Meter waagerecht ein Meter hoch
        $hoehe = (float)($info['hoehe'] ?? 0);
        $geschosse = (int)($info['stockwerke'] ?? 0) > 0 ? (int)$info['stockwerke'] : self::GESCHOSSE_VORGABE;
        if ($hoehe > 0) {
            $first = $hoehe;
            $traufe = max(2.5, $hoehe - $dach);
        } else {
            $traufe = $geschosse * self::GESCHOSS_M;
            $first = $traufe + $dach;
        }
        $hAn = function (float $x, float $y) use ($v, $vm, $halb, $traufe, $first): float {
            if ($halb <= 0) {
                return $traufe;
            }
            $d = abs($x * $v[0] + $y * $v[1] - $vm); // Abstand von der Firstlinie
            return $traufe + ($first - $traufe) * max(0.0, 1 - $d / $halb);
        };
        $linien = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $p[$i];
            $b = $p[($i + 1) % $n];
            $linien[] = [[$a[0], $a[1], $hAn($a[0], $a[1])], [$b[0], $b[1], $hAn($b[0], $b[1])]];
        }
        if (!$flach && $halb > 0) {
            $f0 = [$u[0] * $u0 + $v[0] * $vm, $u[1] * $u0 + $v[1] * $vm];
            $f1 = [$u[0] * $u1 + $v[0] * $vm, $u[1] * $u1 + $v[1] * $vm];
            $linien[] = [[$f0[0], $f0[1], $first], [$f1[0], $f1[1], $first]];
        }
        return ['traufe' => round($traufe, 1), 'first' => round($first, 1), 'linien' => $linien, 'neben' => $neben];
    }

    /**
     * Höhenwinkel der Nachbargebäude je Grad (0–359), gesehen von $auge [x,y] in AUGE_M Höhe.
     * $nachbarn: Umrisse, $infos: gleich indizierte Höhenangaben. Rückgabe [az => Grad] (nur Richtungen mit Gebäude).
     * $fassadeAz: Punkte hinter der Fassadenebene zählen nicht – Sonne von dort trifft die Fassade ohnehin nicht.
     */
    public static function horizont(array $nachbarn, array $infos, array $auge, ?float $fassadeAz = null): array
    {
        $out = [];
        foreach ($nachbarn as $i => $poly) {
            $m = self::modell($poly, (array)($infos[$i] ?? []));
            foreach ($m['linien'] as $l) {
                [$a, $b] = $l;
                $len = hypot($b[0] - $a[0], $b[1] - $a[1]);
                $schritte = max(1, (int)ceil($len / self::SCHRITT_M));
                $vorher = null;
                for ($k = 0; $k <= $schritte; $k++) {
                    $t = $k / $schritte;
                    $x = $a[0] + $t * ($b[0] - $a[0]) - $auge[0];
                    $y = $a[1] + $t * ($b[1] - $a[1]) - $auge[1];
                    $h = $a[2] + $t * ($b[2] - $a[2]);
                    $d = max(0.5, hypot($x, $y));
                    $az = fmod(rad2deg(atan2($x, $y)) + 360, 360);
                    $alt = rad2deg(atan(($h - self::AUGE_M) / $d));
                    if ($fassadeAz !== null && VSCHSonne::winkelAbstand($az, $fassadeAz) >= 90) {
                        $vorher = null; // hinter der Fassade
                        continue;
                    }
                    if ($vorher !== null) {
                        self::fuellen($out, $vorher[0], $vorher[1], $az, $alt);
                    }
                    $vorher = [$az, $alt];
                }
            }
        }
        return $out;
    }

    /** Alle ganzen Grade zwischen zwei Punkten (kürzester Weg) mit dem interpolierten Winkel belegen, Maximum behalten. */
    private static function fuellen(array &$out, float $az1, float $alt1, float $az2, float $alt2): void
    {
        $diff = fmod($az2 - $az1 + 540, 360) - 180;
        $n = max(1, (int)ceil(abs($diff)));
        for ($k = 0; $k <= $n; $k++) {
            $t = $k / $n;
            $az = (int)round(fmod($az1 + $t * $diff + 360, 360)) % 360;
            $alt = $alt1 + $t * ($alt2 - $alt1);
            if ($alt > ($out[$az] ?? -90)) {
                $out[$az] = $alt;
            }
        }
    }

    /** Gelände-Horizont (PVGIS-Punkte) und Gebäude zu einer Liste [[az, h], …] je Grad zusammenführen (Maximum). */
    public static function zusammen(array $gelaende, array $gebaeude): array
    {
        if ($gebaeude === []) {
            return $gelaende;
        }
        $out = [];
        for ($az = 0; $az < 360; $az++) {
            $out[] = [$az, round(max(VSCHHorizont::hoehe($gelaende, $az), $gebaeude[$az] ?? 0.0), 1)];
        }
        return $out;
    }

    /** Fenster-Standort für eine Fassadenrichtung: Mitte der passenden Hausfassade, 0,5 m davor. Sonst Hausmitte bzw. Ursprung. */
    public static function auge(array $haus, float $fassadeAz): array
    {
        $best = null;
        $abstand = 30.0;
        foreach ((array)($haus['fassaden'] ?? []) as $f) {
            $d = VSCHSonne::winkelAbstand((float)$f['az'], $fassadeAz);
            if ($d < $abstand && isset($f['seg'][0])) {
                $abstand = $d;
                $best = $f;
            }
        }
        if ($best !== null) {
            $s = $best['seg'][0];
            $mx = ($s[0][0] + $s[1][0]) / 2;
            $my = ($s[0][1] + $s[1][1]) / 2;
            $r = deg2rad((float)$best['az']);
            return [$mx + 0.5 * sin($r), $my + 0.5 * cos($r)];
        }
        $eigen = (array)($haus['eigen'] ?? []);
        if ($eigen !== []) {
            return [array_sum(array_column($eigen, 0)) / count($eigen), array_sum(array_column($eigen, 1)) / count($eigen)];
        }
        return [0.0, 0.0];
    }
}
