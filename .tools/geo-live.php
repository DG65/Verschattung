<?php
// Live-Probe der Geodaten-Kette ohne Symcon: php .tools/geo-live.php [Kürzel …]
// Öffentliche Punkte (Rathaus/Dom/Markt), keine Privatadressen.
require __DIR__ . '/../Verschattung/VSCHSonne.php';
require __DIR__ . '/../Verschattung/VSCHGeo.php';
require __DIR__ . '/../Verschattung/VSCHHorizont.php';
$abruf = function (string $url, array $kopf = [], ?string $post = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => strpos($url, 'overpass') !== false ? 75 : 30, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => VSCHGeo::USER_AGENT, CURLOPT_HTTPHEADER => $kopf, CURLOPT_ENCODING => '']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    usleep(1100000); // Nominatim/Overpass: höchstens ein Abruf je Sekunde
    return [$c, (string)$b];
};
$punkte = [
    'BW' => [422000, 5369400, 32], 'BE' => [391999, 5819911, 33], 'BB' => [368068, 5806774, 33], 'HB' => [487083, 5880720, 32],
    'HH' => [565756, 5933947, 32], 'HE' => [445890, 5548044, 32], 'MV' => [262900, 5948222, 33], 'NI' => [550282, 5802081, 32],
    'NW' => [344461, 5677404, 32], 'RP' => [447969, 5538805, 32], 'SH' => [573738, 6019451, 32], 'SN' => [411798, 5656349, 33],
    'ST' => [680341, 5778170, 32], 'TH' => [642049, 5649071, 32],
    'SL' => [49.2333, 6.9958], 'BY' => [48.1374, 11.5755], 'CH' => [46.9466, 7.4440], 'AT' => [47.0707, 15.4387],
];
$wahl = array_slice($argv, 1) ?: array_keys($punkte);
foreach ($wahl as $k) {
    $p = $punkte[$k];
    [$lat, $lon] = count($p) === 3 ? VSCHGeo::vonUtm($p[0], $p[1], $p[2]) : $p;
    $t = microtime(true);
    $r = VSCHGeo::erkennen($lat, $lon, $abruf);
    printf("%-3s %-6s %-45s ok=%d geb=%3d nachb=%3d fassaden=%s %s (%.1fs)\n", $k, $r['land'], $r['quelle'], $r['ok'], $r['gebaeude'], count($r['nachbarn']),
        implode('/', array_map(fn($f) => round($f['az']), $r['fassaden'])), $r['meldung'], microtime(true) - $t);
}
