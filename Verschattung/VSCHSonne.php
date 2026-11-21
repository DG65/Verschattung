<?php

declare(strict_types=1);

// ===========================================================================
// Sonnenstand nach NOAA (Meeus, vereinfachte Reihen; Genauigkeit ~0,01° für
// 1800–2100). Reine Funktionen ohne Symcon, damit der Prüfstand sie direkt
// rechnen kann. Azimut als Kompass (0 = Nord, 90 = Ost, 180 = Süd), Höhe in
// Grad über dem mathematischen Horizont, ohne Refraktion. Die Refraktion
// (≤ 0,6° am Horizont) spielt für Beschattung keine Rolle.
// ===========================================================================

final class VSCHSonne
{
    /** Sonnenstand zum Unix-Zeitpunkt $ts. Rückgabe ['az' => Grad, 'alt' => Grad]. */
    public static function stand(float $lat, float $lon, int $ts): array
    {
        $jd = $ts / 86400 + 2440587.5;
        $t = ($jd - 2451545) / 36525;
        $l0 = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360);
        $m = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
        $e = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);
        $mr = deg2rad($m);
        $c = sin($mr) * (1.914602 - $t * (0.004817 + 0.000014 * $t)) + sin(2 * $mr) * (0.019993 - 0.000101 * $t) + sin(3 * $mr) * 0.000289;
        $omega = deg2rad(125.04 - 1934.136 * $t);
        $lambda = deg2rad($l0 + $c - 0.00569 - 0.00478 * sin($omega));
        $eps0 = 23 + (26 + (21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813))) / 60) / 60;
        $eps = deg2rad($eps0 + 0.00256 * cos($omega));
        $dec = asin(sin($eps) * sin($lambda));
        $y = tan($eps / 2) ** 2;
        $l0r = deg2rad($l0);
        $eqMin = 4 * rad2deg($y * sin(2 * $l0r) - 2 * $e * sin($mr) + 4 * $e * $y * sin($mr) * cos(2 * $l0r)
            - 0.5 * $y * $y * sin(4 * $l0r) - 1.25 * $e * $e * sin(2 * $mr));
        $utcMin = fmod($ts, 86400) / 60;
        if ($utcMin < 0) {
            $utcMin += 1440;
        }
        $tst = fmod($utcMin + $eqMin + 4 * $lon + 2880, 1440);
        $ha = deg2rad($tst / 4 - 180);
        $latr = deg2rad($lat);
        $cosZ = sin($latr) * sin($dec) + cos($latr) * cos($dec) * cos($ha);
        $zen = acos(max(-1.0, min(1.0, $cosZ)));
        $az = rad2deg(atan2(sin($ha), cos($ha) * sin($latr) - tan($dec) * cos($latr))) + 180;
        return ['az' => fmod($az + 360, 360), 'alt' => 90 - rad2deg($zen)];
    }

    /**
     * Sonnenbahn eines Kalendertags in der Zeitzone $tz, alle $schrittMin Minuten, nur Punkte mit Höhe > −2°.
     * Rückgabe Liste [ts, az, alt].
     */
    public static function bahn(float $lat, float $lon, string $ymd, string $tz, int $schrittMin = 10): array
    {
        $zone = new DateTimeZone($tz);
        $tag = new DateTimeImmutable($ymd . ' 00:00:00', $zone);
        $start = $tag->getTimestamp();
        $ende = $tag->modify('+1 day')->getTimestamp(); // Zeitumstellung: 23 oder 25 Stunden
        $out = [];
        for ($ts = $start; $ts < $ende; $ts += $schrittMin * 60) {
            $s = self::stand($lat, $lon, $ts);
            if ($s['alt'] > -2) {
                $out[] = [$ts, round($s['az'], 2), round($s['alt'], 2)];
            }
        }
        return $out;
    }

    /** Kleinster Winkelabstand zweier Kompassrichtungen (0…180). */
    public static function winkelAbstand(float $a, float $b): float
    {
        return abs(fmod($a - $b + 540, 360) - 180);
    }

    /**
     * Profilwinkel: Winkel der Sonnenstrahlen in der Ebene senkrecht zur Fassade, gegen die Waagerechte.
     * Danach werden Lamellen nachgeführt: tan(Profil) = tan(Höhe) / cos(waagerechter Einfallswinkel).
     */
    public static function profilwinkel(float $alt, float $sonnenAz, float $fassadenAz): float
    {
        $c = cos(deg2rad(self::winkelAbstand($sonnenAz, $fassadenAz)));
        if ($c <= 0.01) {
            return 90.0;
        }
        return rad2deg(atan(tan(deg2rad(max(0.0, $alt))) / $c));
    }
}
