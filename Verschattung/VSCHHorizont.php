<?php

declare(strict_types=1);

// ===========================================================================
// Gelände-Horizont: Höhenwinkel des Geländes je Himmelsrichtung. Standardquelle
// PVGIS (EU-JRC, europaweit, ein Abruf, 48 Sektoren à 7,5°). Gespeichert wird
// nur die Liste [Kompass-Azimut, Höhe°]. Ohne Symcon, Abruf über Callback.
// ===========================================================================

final class VSCHHorizont
{
    /** Horizont von PVGIS. Rückgabe ['ok' => bool, 'meldung' => string, 'punkte' => [[az, h], …]] (az Kompass, aufsteigend). */
    public static function pvgis(float $lat, float $lon, callable $abruf): array
    {
        $url = 'https://re.jrc.ec.europa.eu/api/v5_3/printhorizon?outputformat=json&lat=' . VSCHGeo::zahl($lat) . '&lon=' . VSCHGeo::zahl($lon);
        [$code, $body] = $abruf($url, []);
        $j = $code === 200 ? json_decode($body, true) : null;
        $roh = is_array($j) ? (array)($j['outputs']['horizon_profile'] ?? []) : [];
        if ($roh === []) {
            return ['ok' => false, 'meldung' => 'PVGIS: HTTP ' . $code, 'punkte' => []];
        }
        // PVGIS: A = 0 Süd, −90 Ost, +90 West → Kompass = A + 180
        $p = [];
        foreach ($roh as $r) {
            $az = fmod((float)$r['A'] + 180 + 360, 360);
            $p[(string)round($az, 1)] = [round($az, 1), round((float)$r['H_hor'], 1)];
        }
        $p = array_values($p);
        usort($p, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return ['ok' => true, 'meldung' => '', 'punkte' => $p];
    }

    /** Höhe des Horizonts bei Kompass-Azimut $az (lineare Interpolation, ringförmig). Leere Liste = 0°. */
    public static function hoehe(array $punkte, float $az): float
    {
        $n = count($punkte);
        if ($n === 0) {
            return 0.0;
        }
        $az = fmod($az + 360, 360);
        for ($i = 0; $i < $n; $i++) {
            $a = $punkte[$i];
            $b = $punkte[($i + 1) % $n];
            $a0 = (float)$a[0];
            $b0 = (float)$b[0] + ($i + 1 === $n ? 360 : 0);
            $x = $az < $a0 ? $az + 360 : $az;
            if ($x >= $a0 && $x <= $b0) {
                $f = $b0 > $a0 ? ($x - $a0) / ($b0 - $a0) : 0;
                return (float)$a[1] + $f * ((float)$b[1] - (float)$a[1]);
            }
        }
        return (float)$punkte[0][1];
    }
}
