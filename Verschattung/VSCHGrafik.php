<?php

declare(strict_types=1);

// ===========================================================================
// Grafiken fürs Konsolenformular als SVG-Data-URI (Image-Element):
//   grundriss()  — Haus von oben, Nachbarn grau, Fassaden farbig mit Buchstabe,
//                  Sonnenbahn des gewählten Tags als Bogen.
//   himmel()     — Sonnenbahnen (heute, 21.06., 21.12.) über dem Horizont,
//                  Band der gewählten Fassade.
// Eigener heller Hintergrund: die Konsole kann hell oder dunkel sein, das Bild
// soll in beiden lesbar bleiben.
// ===========================================================================

final class VSCHGrafik
{
    public const FARBEN = ['#2e6fb7', '#c0592b', '#7a4fb0', '#2b8a7a', '#a3832a', '#b03a6d', '#4a7f2a', '#6b6b6b'];
    private const SONNE = '#d98a00';

    /** Buchstabe einer Fassade (0 = A). */
    public static function kennung(int $i): string
    {
        return chr(65 + $i % 26);
    }

    /**
     * $haus: ['eigen' => [[x,y]…], 'nachbarn' => [...]], $fassaden: Liste ['az','seg'(optional)], $bahn: [[ts,az,alt]…], $auswahl: Index.
     */
    public static function grundriss(array $haus, array $fassaden, array $bahn, int $auswahl, string $tz): string
    {
        $w = 420;
        $c = $w / 2;
        $umfang = 10.0;
        foreach (array_merge([$haus['eigen'] ?? []], []) as $poly) {
            foreach ($poly as $p) {
                $umfang = max($umfang, abs($p[0]), abs($p[1]));
            }
        }
        $s = ($w / 2 - 70) / max(15.0, $umfang * 1.6); // eigenes Haus füllt gut die Mitte
        $pt = function ($p) use ($c, $s) {
            return self::n($c + $p[0] * $s) . ',' . self::n($c - $p[1] * $s);
        };
        $o = '<rect width="' . $w . '" height="' . $w . '" rx="8" fill="#ffffff"/>';
        $sichtbar = function ($poly) use ($c, $s, $w) {
            foreach ($poly as $p) {
                if (abs($p[0] * $s) < $w / 2 && abs($p[1] * $s) < $w / 2) {
                    return true;
                }
            }
            return false;
        };
        $o .= '<g clip-path="url(#k)">';
        foreach ((array)($haus['nachbarn'] ?? []) as $n) {
            if ($sichtbar($n)) {
                $o .= '<polygon points="' . implode(' ', array_map($pt, $n)) . '" fill="#d6dad2" stroke="#b6bbb3" stroke-width="0.8"/>';
            }
        }
        $o .= '</g>';
        if (($haus['eigen'] ?? []) !== []) {
            $o .= '<polygon points="' . implode(' ', array_map($pt, $haus['eigen'])) . '" fill="#eef0ec" stroke="#1d211c" stroke-width="1"/>';
        }
        // Sonnenbahn als Bogen um das Haus (über den Gebäuden, sonst verdecken Nachbarn die Uhrzeiten)
        $r = $w / 2 - 22;
        $punkte = [];
        foreach ($bahn as $b) {
            if ($b[2] > 0) {
                $a = deg2rad($b[1]);
                $rr = $r - $b[2] * 1.2;
                $punkte[] = self::n($c + sin($a) * $rr) . ',' . self::n($c - cos($a) * $rr);
            }
        }
        if ($punkte !== []) {
            $o .= '<polyline points="' . implode(' ', $punkte) . '" fill="none" stroke="' . self::SONNE . '" stroke-width="2.2" stroke-dasharray="1 5" stroke-linecap="round"/>';
        }
        $zone = new DateTimeZone($tz);
        foreach ($bahn as $b) {
            $h = (new DateTime('@' . $b[0]))->setTimezone($zone)->format('H:i');
            if ($b[2] > 0 && in_array($h, ['09:00', '12:00', '15:00', '18:00'], true)) {
                $a = deg2rad($b[1]);
                $rr = $r - $b[2] * 1.2;
                $x = $c + sin($a) * $rr;
                $y = $c - cos($a) * $rr;
                $o .= '<circle cx="' . self::n($x) . '" cy="' . self::n($y) . '" r="7" fill="' . self::SONNE . '"/>'
                    . '<text x="' . self::n($x) . '" y="' . self::n($y - 11) . '" font-size="10" text-anchor="middle" fill="#5f665c">' . substr($h, 0, 2) . '</text>';
            }
        }
        // Fassaden: Kanten farbig, Kennung außen. Ohne Umriss (manuell/Schweiz ohne Kanten): Pfeil am Rand.
        foreach ($fassaden as $i => $f) {
            $farbe = self::FARBEN[$i % count(self::FARBEN)];
            $breit = $i === $auswahl ? 5 : 3.2;
            $segs = (array)($f['seg'] ?? []);
            $az = deg2rad((float)$f['az']);
            if ($segs !== []) {
                $lang = $segs[0];
                $best = 0;
                foreach ($segs as $sg) {
                    $l = hypot($sg[1][0] - $sg[0][0], $sg[1][1] - $sg[0][1]);
                    $o .= '<line x1="' . self::n($c + $sg[0][0] * $s) . '" y1="' . self::n($c - $sg[0][1] * $s) . '" x2="' . self::n($c + $sg[1][0] * $s) . '" y2="' . self::n($c - $sg[1][1] * $s)
                        . '" stroke="' . $farbe . '" stroke-width="' . $breit . '" stroke-linecap="round"/>';
                    if ($l > $best) {
                        $best = $l;
                        $lang = $sg;
                    }
                }
                $mx = ($lang[0][0] + $lang[1][0]) / 2 * $s;
                $my = ($lang[0][1] + $lang[1][1]) / 2 * $s;
                $bx = $c + $mx + sin($az) * 18;
                $by = $c - $my - cos($az) * 18;
            } else {
                $bx = $c + sin($az) * 60;
                $by = $c - cos($az) * 60;
                $o .= '<line x1="' . self::n($c) . '" y1="' . self::n($c) . '" x2="' . self::n($bx) . '" y2="' . self::n($by) . '" stroke="' . $farbe . '" stroke-width="' . $breit . '"/>';
            }
            $o .= '<circle cx="' . self::n($bx) . '" cy="' . self::n($by) . '" r="' . ($i === $auswahl ? 13 : 11) . '" fill="' . $farbe . '" stroke="#ffffff" stroke-width="2"/>'
                . '<text x="' . self::n($bx) . '" y="' . self::n($by + 4.5) . '" font-size="13" font-weight="600" text-anchor="middle" fill="#ffffff">' . self::kennung($i) . '</text>';
        }
        // Nordpfeil und Maßstab (10 m)
        $o .= '<path d="M' . ($w - 24) . ',14 l7,18 l-7,-5 l-7,5z" fill="#1d211c"/><text x="' . ($w - 24) . '" y="46" font-size="11" font-weight="600" text-anchor="middle" fill="#1d211c">N</text>';
        $o .= '<line x1="14" y1="' . ($w - 16) . '" x2="' . self::n(14 + 10 * $s) . '" y2="' . ($w - 16) . '" stroke="#1d211c" stroke-width="2"/><text x="14" y="' . ($w - 22) . '" font-size="10" fill="#5f665c">10 m</text>';
        $defs = '<defs><clipPath id="k"><rect width="' . $w . '" height="' . $w . '" rx="8"/></clipPath></defs>';
        return self::uri('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $w . '" font-family="-apple-system,Segoe UI,Helvetica,Arial,sans-serif">' . $defs . $o . '</svg>');
    }

    /**
     * $bahnen: ['Beschriftung' => [[ts,az,alt]…], …] (erste = hervorgehoben), $horizont: [[az,h]…], $fassadeAz: ?float.
     */
    public static function himmel(array $bahnen, array $horizont, ?float $fassadeAz, string $farbe, string $tz, string $hinweisHorizont): string
    {
        [$w, $h, $ml, $mr, $mt, $mb] = [560, 260, 36, 12, 14, 30];
        [$az0, $az1, $alt1] = [30, 330, 70];
        $x = function ($az) use ($ml, $mr, $w, $az0, $az1) {
            return $ml + ($az - $az0) / ($az1 - $az0) * ($w - $ml - $mr);
        };
        $y = function ($alt) use ($h, $mt, $mb, $alt1) {
            return $h - $mb - $alt / $alt1 * ($h - $mt - $mb);
        };
        $o = '<rect width="' . $w . '" height="' . $h . '" rx="8" fill="#ffffff"/>';
        if ($fassadeAz !== null) {
            foreach ([-360, 0, 360] as $off) {
                $a = max($az0, $fassadeAz - VSCHLogik::OEFFNUNG + $off);
                $b = min($az1, $fassadeAz + VSCHLogik::OEFFNUNG + $off);
                if ($b > $a) {
                    $o .= '<rect x="' . self::n($x($a)) . '" y="' . $mt . '" width="' . self::n($x($b) - $x($a)) . '" height="' . ($h - $mt - $mb) . '" fill="' . $farbe . '" fill-opacity="0.13"/>';
                }
            }
        }
        $namen = [45 => 'NO', 90 => 'O', 135 => 'SO', 180 => 'S', 225 => 'SW', 270 => 'W', 315 => 'NW'];
        foreach ($namen as $a => $n) {
            $o .= '<line x1="' . self::n($x($a)) . '" y1="' . $mt . '" x2="' . self::n($x($a)) . '" y2="' . ($h - $mb) . '" stroke="#e3e6e0"/>'
                . '<text x="' . self::n($x($a)) . '" y="' . ($h - $mb + 16) . '" font-size="10" text-anchor="middle" fill="#5f665c">' . $n . '</text>';
        }
        foreach ([0, 20, 40, 60] as $g) {
            $o .= '<line x1="' . $ml . '" y1="' . self::n($y($g)) . '" x2="' . ($w - $mr) . '" y2="' . self::n($y($g)) . '" stroke="#e3e6e0"/>'
                . '<text x="' . ($ml - 6) . '" y="' . self::n($y($g) + 3) . '" font-size="10" text-anchor="end" fill="#5f665c">' . $g . '°</text>';
        }
        // Horizont, dreifach überhöht (sonst kaum sichtbar)
        $d = 'M' . self::n($x($az0)) . ',' . self::n($y(0));
        for ($a = $az0; $a <= $az1; $a += 2.5) {
            $d .= ' L' . self::n($x($a)) . ',' . self::n($y(min($alt1, VSCHHorizont::hoehe($horizont, $a) * 3)));
        }
        $o .= '<path d="' . $d . ' L' . self::n($x($az1)) . ',' . self::n($y(0)) . 'Z" fill="#c9cfc4"/>';
        $o .= '<text x="' . ($ml + 6) . '" y="' . self::n($y(0) - 4) . '" font-size="9.5" fill="#3d443a">' . htmlspecialchars($hinweisHorizont) . '</text>';
        $zone = new DateTimeZone($tz);
        $erste = true;
        $nr = 0;
        $o .= '<rect x="' . ($w - $mr - 124) . '" y="' . ($mt - 6) . '" width="124" height="' . (14 * count($bahnen) + 6) . '" rx="4" fill="#ffffff" fill-opacity="0.85"/>';
        foreach ($bahnen as $name => $bahn) {
            $pts = [];
            foreach ($bahn as $b) {
                if ($b[1] >= $az0 && $b[1] <= $az1 && $b[2] > -1) {
                    $pts[] = self::n($x($b[1])) . ',' . self::n($y(max(0, $b[2])));
                }
            }
            if ($pts !== []) {
                $o .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . ($erste ? self::SONNE : '#8f968b') . '" stroke-width="' . ($erste ? 2.6 : 1) . '"' . ($erste ? '' : ' stroke-dasharray="4 3"') . '/>';
                $ly = $mt + 4 + 14 * $nr;
                $o .= '<line x1="' . ($w - $mr - 118) . '" y1="' . $ly . '" x2="' . ($w - $mr - 96) . '" y2="' . $ly . '" stroke="' . ($erste ? self::SONNE : '#8f968b') . '" stroke-width="' . ($erste ? 2.6 : 1) . '"' . ($erste ? '' : ' stroke-dasharray="4 3"') . '/>'
                    . '<text x="' . ($w - $mr - 90) . '" y="' . ($ly + 3.5) . '" font-size="10" fill="#1d211c">' . htmlspecialchars((string)$name) . '</text>';
                $nr++;
            }
            if ($erste) {
                foreach ($bahn as $b) {
                    $t = (new DateTime('@' . $b[0]))->setTimezone($zone)->format('H:i');
                    if ($b[2] > 0 && substr($t, 3) === '00' && $b[1] >= $az0 && $b[1] <= $az1) {
                        $o .= '<circle cx="' . self::n($x($b[1])) . '" cy="' . self::n($y($b[2])) . '" r="2.6" fill="' . self::SONNE . '"/>';
                        if ((int)substr($t, 0, 2) % 3 === 0) {
                            $o .= '<text x="' . self::n($x($b[1])) . '" y="' . self::n($y($b[2]) - 7) . '" font-size="9.5" text-anchor="middle" fill="#1d211c">' . substr($t, 0, 2) . '</text>';
                        }
                    }
                }
            }
            $erste = false;
        }
        return self::uri('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" font-family="-apple-system,Segoe UI,Helvetica,Arial,sans-serif">' . $o . '</svg>');
    }

    /** Zeitfenster, in denen die Fassade direkte Sonne bekommt: Liste [von_ts, bis_ts]. */
    public static function sonnenfenster(array $bahn, array $horizont, float $fassadeAz): array
    {
        $out = [];
        $start = null;
        $letzt = null;
        foreach ($bahn as $b) {
            $an = VSCHLogik::sonneAufFassade((float)$b[1], (float)$b[2], $fassadeAz, VSCHHorizont::hoehe($horizont, (float)$b[1]), false);
            if ($an && $start === null) {
                $start = $b[0];
            }
            if (!$an && $start !== null) {
                $out[] = [$start, $letzt];
                $start = null;
            }
            if ($an) {
                $letzt = $b[0];
            }
        }
        if ($start !== null) {
            $out[] = [$start, $letzt];
        }
        return $out;
    }

    private static function uri(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function n(float $v): string
    {
        return number_format($v, 1, '.', '');
    }
}
