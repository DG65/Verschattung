<?php
/**
 * test-verschattung.php — Prüfstand für die Verschattung OHNE laufendes Symcon.
 *
 * A Sonnenstand   B Geodaten/Fassaden   C Horizont   D Entscheidungen   E Wetter
 * F Grafik        G Modul gegen nachgebildetes Symcon   H Übersetzung und Formularregeln
 *
 * Aufruf:   php .tools/test-verschattung.php
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL);
$GLOBALS['WARN'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if ($no === E_DEPRECATED || !(error_reporting() & $no)) { // mit @ unterdrückt, wie in Symcon
        return true;
    }
    $GLOBALS['WARN'][] = basename($file) . ':' . $line . ' ' . $str;
    return true;
});

const VM_UPDATE = 10603;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const KL_MESSAGE = 10201; const KL_WARNING = 10204; const KL_ERROR = 10205;

$fails = 0;
$checks = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) {
        $fails++;
        echo "  ✗ $what" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}
function heading(string $t): void { echo "[$t]\n"; }
function ts(string $s): int { return (new DateTimeImmutable($s, new DateTimeZone('Europe/Berlin')))->getTimestamp(); }

$root = dirname(__DIR__);
require $root . '/Verschattung/VSCHText.php';
require $root . '/Verschattung/VSCHSonne.php';
require $root . '/Verschattung/VSCHGeo.php';
require $root . '/Verschattung/VSCHHorizont.php';
require $root . '/Verschattung/VSCHGebaeude.php';
require $root . '/Verschattung/VSCHLogik.php';
require $root . '/Verschattung/VSCHGrafik.php';
require $root . '/Verschattung/VSCHErkennung.php';
$GLOBALS['DE'] = json_decode((string)@file_get_contents($root . '/Verschattung/locale.json'), true)['translations']['de'] ?? [];
VSCHText::setzen(function (string $t): string { return $GLOBALS['DE'][$t] ?? $t; });

[$LAT, $LON] = [48.4729, 7.9447]; // öffentliches Beispielgebäude Offenburg (Fixture)

// ---------------------------------------------------------------------------
heading('A Sonnenstand');
foreach (['2026-06-21' => 90 - $LAT + 23.44, '2026-12-21' => 90 - $LAT - 23.44, '2026-03-20' => 90 - $LAT] as $tag => $soll) {
    $bahn = VSCHSonne::bahn($LAT, $LON, $tag, 'Europe/Berlin', 2);
    $max = max(array_column($bahn, 2));
    check("A1 Mittagshöhe $tag ≈ " . round($soll, 1) . '°', abs($max - $soll) < 0.35, "ist $max");
    $mittag = array_values(array_filter($bahn, fn($b) => $b[2] === $max))[0];
    check("A2 Azimut zur Mittagszeit $tag ≈ 180°", abs($mittag[1] - 180) < 1.5, (string)$mittag[1]);
}
$s = VSCHSonne::stand($LAT, $LON, ts('2026-06-21 06:30'));
check('A3 Sommermorgen: Sonne im Nordosten', $s['az'] > 50 && $s['az'] < 80 && $s['alt'] > 5, json_encode($s));
$s = VSCHSonne::stand($LAT, $LON, ts('2026-12-21 23:00'));
check('A4 Winternacht: Sonne unter dem Horizont', $s['alt'] < -40, json_encode($s));
check('A5 Zeitumstellung: Bahn am 29.03. ohne Lücke', count(VSCHSonne::bahn($LAT, $LON, '2026-03-29', 'Europe/Berlin', 10)) > 70);
check('A6 Winkelabstand über Nord', VSCHSonne::winkelAbstand(350, 10) == 20.0);
check('A7 Profilwinkel frontal = Sonnenhöhe', abs(VSCHSonne::profilwinkel(30, 180, 180) - 30) < 0.01);
check('A8 Profilwinkel schräg > Sonnenhöhe', VSCHSonne::profilwinkel(30, 240, 180) > 45);

// ---------------------------------------------------------------------------
heading('B Geodaten');
[$la, $lo] = VSCHGeo::vonUtm(422000, 5369400, 32);
[$e, $n] = VSCHGeo::nachUtm($la, $lo, 32);
check('B1 UTM hin und zurück < 1 cm', abs($e - 422000) < 0.01 && abs($n - 5369400) < 0.01);
[$e33, $n33] = VSCHGeo::nachUtm(52.5186, 13.4083, 33);
check('B2 UTM 33 Berlin plausibel', abs($e33 - 391999) < 400 && abs($n33 - 5820000) < 800, "$e33 $n33");
$poly = VSCHGeo::gmlPolygone((string)file_get_contents(__DIR__ . '/fixtures/bw-offenburg.xml'), 25832);
check('B3 GML: 39 Umrisse gelesen', count($poly) === 39, (string)count($poly));
$r = VSCHGeo::ausUmrissen($la, $lo, $poly, 'BW', 'Lizenz');
check('B4 eigenes Gebäude gefunden', $r['ok'] && count($r['eigen']) === 21);
$az = array_map(fn($f) => (int)round($f['az']), $r['fassaden']);
check('B5 vier Fassaden N/O/S/W (gegen Meridiankonvergenz korrigiert)', $az === [2, 92, 168, 271], json_encode($az));
check('B6 Nachbarn im Umkreis', count($r['nachbarn']) > 15 && count($r['nachbarn']) < 39);
// Rechteck im und gegen den Uhrzeigersinn: gleiche Außennormalen
$recht = [[0, 0], [10, 0], [10, 6], [0, 6], [0, 0]];
$a1 = array_column(VSCHGeo::fassaden($recht), 'az');
$a2 = array_column(VSCHGeo::fassaden(array_reverse($recht)), 'az');
check('B7 Rechteck: Außennormalen 0/90/180/270', $a1 == [0.0, 90.0, 180.0, 270.0], json_encode($a1));
check('B8 Umlaufrichtung egal', $a1 == $a2);
$gedreht = array_map(fn($p) => [$p[0] * cos(deg2rad(30)) - $p[1] * sin(deg2rad(30)), $p[0] * sin(deg2rad(30)) + $p[1] * cos(deg2rad(30))], $recht);
check('B9 gedrehtes Haus: Südseite bei 150°', in_array(150.0, array_column(VSCHGeo::fassaden($gedreht), 'az'), true), json_encode(array_column(VSCHGeo::fassaden($gedreht), 'az')));
$erker = [[0, 0], [10, 0], [10, 3], [11, 3], [11, 4], [10, 4], [10, 6], [0, 6], [0, 0]];
check('B10 Erker (1 m) zählt nicht als eigene Fassade', count(VSCHGeo::fassaden($erker)) === 4, json_encode(array_column(VSCHGeo::fassaden($erker), 'az')));
$gj = json_encode(['features' => [['geometry' => ['type' => 'Polygon', 'coordinates' => [[[7.0, 49.0], [7.0001, 49.0], [7.0001, 49.0001], [7.0, 49.0]]]]]]]);
check('B11 GeoJSON lon,lat → lat,lon', VSCHGeo::geojsonPolygone($gj, 4326)[0]['p'][0] === [49.0, 7.0]);
$leer = VSCHGeo::ausUmrissen($la, $lo, [], 'X', '');
check('B12 keine Umrisse: verständliche Meldung', !$leer['ok'] && $leer['meldung'] !== '');
foreach (VSCHGeo::QUELLEN as $k => $q) {
    $u = VSCHGeo::alkisUrl(51.0, 10.0, $q);
    check("B13 Abfrage $k mit fester KBS", strpos($u, (string)$q['epsg']) !== false && strpos($u, 'https://') === 0);
}
check('B14 Saarland fragt in 4326 (lat,lon)', strpos(VSCHGeo::alkisUrl(49.23, 6.99, VSCHGeo::QUELLEN['DE-SL']), 'BBOX=49.') !== false);
check('B15 MV/SN/BE/BB in Zone 33', VSCHGeo::QUELLEN['DE-MV']['epsg'] === 25833 && VSCHGeo::QUELLEN['DE-SN']['epsg'] === 25833 && VSCHGeo::QUELLEN['DE-BE']['epsg'] === 25833 && VSCHGeo::QUELLEN['DE-BB']['epsg'] === 25833);
check('B16 kein Eintrag für Bayern (kein WFS → OSM)', !isset(VSCHGeo::QUELLEN['DE-BY']));
// Ablauf mit gespielten Abrufen: Land → ALKIS
$gespielt = function (string $url, array $kopf = [], ?string $post = null) {
    if (strpos($url, 'nominatim') !== false) {
        return [200, json_encode(['address' => ['country_code' => 'de', 'ISO3166-2-lvl4' => 'DE-BW']])];
    }
    if (strpos($url, 'lgl-bw') !== false) {
        return [200, (string)file_get_contents(__DIR__ . '/fixtures/bw-offenburg.xml')];
    }
    return [500, ''];
};
$r = VSCHGeo::erkennen($la, $lo, $gespielt);
check('B17 erkennen(): BW über ALKIS', $r['ok'] && $r['land'] === 'DE-BW' && strpos($r['quelle'], 'Baden') !== false);
$r = VSCHGeo::erkennen($la, $lo, function ($u, $k = [], $p = null) {
    return strpos($u, 'nominatim') !== false ? [200, json_encode(['address' => ['country_code' => 'de', 'ISO3166-2-lvl4' => 'DE-BW']])] : [503, ''];
});
check('B18 Dienst aus: Meldung nennt Landesdienst und OSM', !$r['ok'] && strpos($r['meldung'], 'Baden') !== false, $r['meldung']);

// ---------------------------------------------------------------------------
heading('C Horizont');
$hor = VSCHHorizont::pvgis($LAT, $LON, fn($u) => [200, (string)file_get_contents(__DIR__ . '/fixtures/pvgis-offenburg.json')]);
check('C1 PVGIS gelesen, Kompass aufsteigend', $hor['ok'] && $hor['punkte'][0][0] == 0.0 && count($hor['punkte']) === 48, (string)count($hor['punkte']));
check('C2 Osten (Schwarzwald) 4,6°', abs(VSCHHorizont::hoehe($hor['punkte'], 90) - 4.6) < 0.01);
check('C3 Interpolation zwischen Stützpunkten', abs(VSCHHorizont::hoehe($hor['punkte'], 33.75) - (1.9 + 4.6) / 2) < 0.6);
check('C4 über Nord hinweg stetig', abs(VSCHHorizont::hoehe($hor['punkte'], 359) - VSCHHorizont::hoehe($hor['punkte'], 1)) < 0.5);
check('C5 leere Liste = 0°', VSCHHorizont::hoehe([], 123) === 0.0);
check('C6 Fehler: ok=false', !VSCHHorizont::pvgis(1, 1, fn($u) => [500, ''])['ok']);
$H = $hor['punkte'];

// ---------------------------------------------------------------------------
heading('D Entscheidungen');
check('D1 Sonne frontal: an', VSCHLogik::sonneAufFassade(180, 30, 180, 2, false));
check('D2 Sonne hinter dem Haus: aus', !VSCHLogik::sonneAufFassade(0, 30, 180, 0, false));
check('D3 Sonne unter Horizont: aus', !VSCHLogik::sonneAufFassade(90, 3, 90, 4.6, false));
check('D4 Hysterese seitlich: bleibt an bis Öffnung + 2°', VSCHLogik::sonneAufFassade(261, 20, 180, 0, true) && !VSCHLogik::sonneAufFassade(261, 20, 180, 0, false));
check('D5 Lamellen: flache Sonne → steiler', VSCHLogik::lamellenWinkel(10) > VSCHLogik::lamellenWinkel(40));
check('D6 Lamellen: hohe Sonne → waagerecht', VSCHLogik::lamellenWinkel(60) == 0.0);
check('D7 Lamellen: 0°..90°', VSCHLogik::lamellenWinkel(0) > 0 && VSCHLogik::lamellenWinkel(0) <= 90);

$T = ['an' => 180, 'aus' => 900];
$U = ['hell' => null, 'warm' => null, 'wetter' => ['art' => '', 'grund' => ''], 'wetterQuelle' => true, 'ohneWetterBestaetigt' => false, 'fensterOffen' => false, 'automatik' => true, 'tag' => true];
$G = ['typ' => 'raffstore', 'az' => 180, 'sonneAn' => true, 'lamellen' => true, 'profil' => 30];
$t0 = ts('2026-07-01 11:00');
$r = VSCHLogik::entscheide($G, $U, [], $T, $t0);
check('D8 Sonne kommt: erst warten', $r['ziel'] === null && $r['z']['kandidat'] === true);
$r = VSCHLogik::entscheide($G, $U, $r['z'], $T, $t0 + 200);
check('D9 nach Verzögerung: beschatten mit Lamelle', $r['ziel'] === 'beschatten' && $r['lamelle'] !== null && $r['z']['wir'] === true);
$z = $r['z'];
$r = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, $z, $T, $t0 + 400);
check('D10 kurze Wolke/Sonne weg: bleibt (Verzögerung aus)', $r['ziel'] === null && $r['z']['soll'] === true);
$r = VSCHLogik::entscheide(['sonneAn' => true] + $G, $U, $r['z'], $T, $t0 + 500);
check('D11 Sonne wieder da: Kandidat verworfen, beschattet weiter', $r['ziel'] === 'beschatten' && $r['z']['kandidat'] === null);
$z = $r['z'];
$r1 = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, $z, $T, $t0 + 600);
$r2 = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, $r1['z'], $T, $t0 + 1600);
check('D12 Sonne weg nach 15 min: hochfahren (eigene Fahrt)', $r2['ziel'] === 'offen' && $r2['z']['wir'] === false);
$r3 = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, $r2['z'], $T, $t0 + 1700);
// Einstrahlung, Sommer/Winter, Raumhelligkeit (0.4.0)
$rw = VSCHLogik::entscheide($G, ['hell' => false, 'hellGrund' => 'zu wenig'] + $U, [], $T, $t0);
check('D30 Sonne auf der Fassade, Einstrahlung zu gering: nicht beschatten, Grund mit Wert', $rw['z']['kandidat'] === null && $rw['grund'] === 'zu wenig', $rw['grund']);
$rw = VSCHLogik::entscheide($G, ['klima' => 'heizen', 'klimaGrund' => 'Winter'] + $U, [], $T, $t0);
check('D31 Winter: Sonnenwärme nutzen, Behang bleibt oben', $rw['z']['kandidat'] === null && $rw['grund'] === 'Winter');
$rw = VSCHLogik::entscheide($G, ['klima' => 'kuehlen', 'klimaGrund' => 'Raum warm'] + $U, [], $T, $t0);
check('D32 Raum warm: beschatten, Grund nennt die Wärme', $rw['z']['kandidat'] === true && strpos($rw['grund'], 'Raum warm') === 0, $rw['grund']);
$rw = VSCHLogik::entscheide($G, ['raumHell' => false, 'raumHellGrund' => 'Raum dunkel'] + $U, [], $T, $t0);
check('D33 Raumhelligkeit unter Schwelle: nicht herunterfahren', $rw['z']['kandidat'] === null && $rw['grund'] === 'Raum dunkel');
$rw = VSCHLogik::entscheide($G, ['raumHell' => false] + $U, ['soll' => true, 'wir' => true], $T, $t0);
check('D34 Behang unten, Raum dunkel: bleibt unten (Hochfahren nie nach Raumhelligkeit)', $rw['ziel'] === 'beschatten' && $rw['z']['soll'] === true, json_encode($rw));
$kl = fn(?float $i, ?float $a, ?int $wp, ?string $v = null) => VSCHLogik::klima($i, $a, $wp, 21, 24, 18, $v);
check('D35 Heizen/Kühlen/neutral: Raum warm vor allem, dann Wärmepumpe, Raum kalt, Sommer, Winter, behaglich',
    $kl(25.0, 5.0, 1) === ['kuehlen', 'raum'] && $kl(22.0, 5.0, 2) === ['kuehlen', 'wpkuehlt'] && $kl(22.0, 25.0, 4) === ['heizen', 'wpheizt']
    && $kl(20.0, 25.0, null) === ['heizen', 'raumkalt'] && $kl(22.0, 20.0, null) === ['kuehlen', 'sommer'] && $kl(null, 5.0, null) === ['heizen', 'winter']
    && $kl(22.0, 5.0, null) === ['neutral', 'behaglich'] && $kl(22.0, 5.0, 0) === ['neutral', 'behaglich'] && $kl(null, null, null) === [null, '']);
check('D35b Hysterese 0,5 K', $kl(23.6, null, null, 'kuehlen') === ['kuehlen', 'raum'] && $kl(23.6, null, null) === ['neutral', 'behaglich']
    && $kl(21.3, null, null, 'heizen') === ['heizen', 'raumkalt'] && $kl(21.3, null, null) === ['neutral', 'behaglich']);
$rw = VSCHLogik::entscheide($G, ['klima' => 'neutral', 'klimaGrund' => 'behaglich'] + $U, [], $T, $t0);
check('D38 neutral ohne Blendschutz: nicht beschatten', $rw['z']['kandidat'] === null && $rw['grund'] === 'behaglich');
$rw = VSCHLogik::entscheide(['tiefe' => [0.4, 'Blendschutz 40']] + $G, ['klima' => 'neutral', 'klimaGrund' => 'behaglich'] + $U, ['soll' => true, 'wir' => true], $T, $t0);
check('D39 neutral mit Blendschutz: Teilposition 40 %', $rw['ziel'] === 'beschatten' && abs((float)$rw['anteil'] - 0.4) < 0.001 && str_contains($rw['grund'], 'Blendschutz 40'), json_encode($rw));
$rw = VSCHLogik::entscheide(['tiefe' => [0.4, 'Blendschutz 40']] + $G, ['klima' => 'kuehlen', 'klimaGrund' => 'warm'] + $U, ['soll' => true, 'wir' => true], $T, $t0);
check('D40 Kühlbedarf schlägt Blendschutz: volle Beschattung', $rw['ziel'] === 'beschatten' && $rw['anteil'] === null);
$rw = VSCHLogik::entscheide(['tiefe' => [0.0, 'nur 30 cm']] + $G, $U, [], $T, $t0);
check('D41 Sonne kommt nicht weit genug herein: nicht beschatten', $rw['z']['kandidat'] === null && $rw['grund'] === 'nur 30 cm');
$rw = VSCHLogik::entscheide($G, ['fenster' => 'lueften', 'lueftung' => 0.7] + $U, ['soll' => true, 'wir' => true], $T, $t0);
check('D42 Fenster offen/gekippt: höchstens Lüftungsposition', $rw['ziel'] === 'beschatten' && abs((float)$rw['anteil'] - 0.7) < 0.001 && str_contains($rw['grund'], '70'), $rw['grund']);
$rw = VSCHLogik::entscheide($G, ['fenster' => 'sperre'] + $U, ['soll' => true, 'wir' => true], $T, $t0);
check('D43 Tür offen: nicht herunter (Aussperrschutz)', $rw['ziel'] === null);
$rw = VSCHLogik::entscheide(['typ' => 'rollladen'] + $G, ['notfall' => 'Rauchmelder Küche', 'automatik' => false, 'tag' => false] + $U, ['soll' => true, 'wir' => true, 'hand' => true], $T, $t0);
check('D44 Notfall: hoch, auch nachts, bei Handbedienung und ausgeschalteter Automatik', $rw['ziel'] === 'offen' && $rw['z']['hand'] === null && str_contains($rw['grund'], 'Rauchmelder Küche'));
$rw = VSCHLogik::entscheide($G, ['weg' => ['Sonne 180°']] + $U, [], $T, $t0);
check('D45 Entscheidungsweg: Messwerte vorn, Ergebnis mit Pfeil am Ende', $rw['weg'][0] === 'Sonne 180°' && str_starts_with(end($rw['weg']), '→ '), json_encode($rw['weg']));
$ta = VSCHLogik::tiefeAnteil(45.0, 150, 150, 80);
check('D46 Tiefe: 45° Sonne, 150 cm Fenster, 80 cm Brüstung, 150 cm erlaubt → 53 % verdecken; offen 230 cm; 50 cm erlaubt → ganz (Brüstung allein 80 cm)', abs($ta[0] - 0.5333) < 0.001 && abs($ta[1] - 230) < 0.5
    && VSCHLogik::tiefeAnteil(45.0, 50, 150, 80)[0] == 1.0, json_encode($ta));
check('D47 Tiefe: steile Sonne kommt kaum herein → 0; nicht eingerichtet → null', VSCHLogik::tiefeAnteil(80.0, 50, 150, 80)[0] == 0.0 && VSCHLogik::tiefeAnteil(45.0, 0, 150, 80) === null);
$RM = VSCHErkennung::rauchmelder([
    1 => ['name' => 'Rauchmelder Küche', 'ort' => '', 'vars' => [[11, 'Batterie', 'LOWBAT', 0, '~Battery', false], [12, 'Test', 'TEST', 0, '', false], [13, 'Alarm', 'SMOKE_DETECTOR_ALARM_STATUS', 0, '~Alert', false]]],
    2 => ['name' => 'Rauchmelder Flur', 'ort' => '', 'vars' => [[21, 'Zustand', 'State', 0, '~Alert', false]]],
    3 => ['name' => 'Wassermelder Keller', 'ort' => '', 'vars' => [[31, 'Alarm', '', 0, '~Alert', false]]],
    4 => ['name' => 'Kaminofen', 'ort' => 'Wohnzimmer', 'vars' => [[41, 'Feuer', '', 0, '', false]]],
    5 => ['name' => 'Messung Hausverbrauch', 'ort' => 'Verbrauch', 'vars' => [[51, 'Status', '', 0, '', false]]]]);
check('D49 Rauchmelder: Alarmvariable, nicht Batterie/Test; Wassermelder, Kaminofen und „Verbrauch“ nie', $RM === [13, 21], json_encode($RM));
check('D36 Klarer Himmel 48° N: Juni ≈ 930, Dezember ≈ 280 W/m²', abs(VSCHLogik::klarHimmel(90 - 48.34 + 23.44) - 930) < 30 && abs(VSCHLogik::klarHimmel(90 - 48.34 - 23.44) - 280) < 40,
    round(VSCHLogik::klarHimmel(65.1)) . '/' . round(VSCHLogik::klarHimmel(18.2)));
check('D37 Lux → W/m²: 120 lx ≈ 1 W/m²', VSCHLogik::luxZuWm2(30000) == 250.0);
check('D13 danach Ruhe', $r3['ziel'] === null);
// Handbedienung
$zh = ['hand' => true] + $z;
$r = VSCHLogik::entscheide($G, $U, $zh, $T, $t0 + 700);
check('D14 Hand: Ruhe, solange Lage gleich', $r['ziel'] === null && $r['z']['hand'] === true);
$r = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, $r['z'], $T, $t0 + 800);
$r = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, $r['z'], $T, $t0 + 1800);
check('D15 Hand endet, wenn die Sonne geht', $r['z']['hand'] === null && $r['ziel'] === 'offen');
// Wetter vor Hand
$r = VSCHLogik::entscheide(['typ' => 'markise'] + $G, ['wetter' => ['art' => 'einfahren', 'grund' => 'Wind']] + $U, ['hand' => true, 'soll' => true], $T, $t0);
check('D16 Wind schlägt Handbedienung (Markise fährt ein)', $r['ziel'] === 'offen' && $r['z']['hand'] === null);
$r = VSCHLogik::entscheide(['typ' => 'rollladen'] + $G, ['wetter' => ['art' => 'einfahren', 'grund' => 'Wind']] + $U, ['soll' => true], $T, $t0);
check('D17 Rollladen ignoriert Wind', $r['ziel'] === 'beschatten');
$r = VSCHLogik::entscheide($G, ['wetter' => ['art' => 'festhalten', 'grund' => 'Frost']] + $U, ['soll' => true, 'wir' => true], $T, $t0);
check('D18 Frost: Raffstore bewegt sich nicht', $r['ziel'] === null);
// Markise ohne Wetterquelle
$r = VSCHLogik::entscheide(['typ' => 'markise'] + $G, ['wetterQuelle' => false] + $U, ['soll' => false], ['an' => 0, 'aus' => 0], $t0);
check('D19 Markise ohne Wetterquelle fährt nicht aus', $r['ziel'] === null);
$r = VSCHLogik::entscheide(['typ' => 'markise'] + $G, ['wetterQuelle' => false, 'ohneWetterBestaetigt' => true] + $U, ['soll' => false], ['an' => 0, 'aus' => 0], $t0);
check('D20 … außer ausdrücklich bestätigt', $r['ziel'] === 'beschatten');
// Rückfahrt nur bei Tag und nur eigene Fahrt
$r = VSCHLogik::entscheide(['sonneAn' => false] + $G, ['tag' => false] + $U, ['soll' => true, 'wir' => true], ['an' => 0, 'aus' => 0], $t0);
check('D21 Abends: keine Rückfahrt (Abendautomatik bleibt unberührt)', $r['ziel'] === null);
$r = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, ['soll' => true, 'wir' => false], ['an' => 0, 'aus' => 0], $t0);
check('D22 Fremd gefahren: keine Rückfahrt', $r['ziel'] === null);
$r = VSCHLogik::entscheide($G, ['fensterOffen' => true] + $U, ['soll' => true], ['an' => 0, 'aus' => 0], $t0);
check('D23 Terrassentür offen: nicht runter', $r['ziel'] === null);
$r = VSCHLogik::entscheide($G, ['hell' => false] + $U, ['soll' => false], ['an' => 0, 'aus' => 0], $t0);
check('D24 zu dunkel: keine Beschattung', $r['ziel'] === null && strpos($r['grund'], '') !== false);
$r = VSCHLogik::entscheide($G, ['automatik' => false] + $U, ['soll' => false], ['an' => 0, 'aus' => 0], $t0);
check('D25 Automatik aus: nichts', $r['ziel'] === null && $r['z']['soll'] === true);
// Nachts ein ehrlicher Grund statt „keine direkte Sonne“
$r = VSCHLogik::entscheide(['sonneAn' => false] + $G, ['tag' => false] + $U, [], $T, $t0);
check('D26 nachts: Grund „Nacht“', $r['grund'] === 'Nacht', $r['grund']);
$r = VSCHLogik::entscheide(['sonneAn' => false] + $G, $U, [], $T, $t0);
check('D27 tagsüber ohne Sonne: Grund bleibt', $r['grund'] === 'Keine direkte Sonne auf der Fassade', $r['grund']);
// Gerätevorschläge: Fälle aus dem Live-Test 21.11. (Z-Wave-Jalousien, Markise, Klimaanlage)
$fall = [
    ['Jalousie Kind I', 'Position', '', '~Shutter.Reversed', 'raffstore'],
    ['Jalousie Wohnen Rechts', 'Position', '', '~Shutter', 'raffstore'],
    ['Markise', 'Position', '', '~Shutter', 'markise'],
    ['Rollladen Küche', 'Höhe', '', '~Shutter', 'rollladen'],
    ['Wohnzimmer', 'Position', 'POSITION', '~Shutter', 'rollladen'],
    ['KNX Kanal 3', 'Rollladen Bad Position', '', '', 'rollladen'],
    ['Jalousie Kind I', 'Status', '', '~ShutterMoveStop', null],
    ['Jalousie Kind I', 'Lamellenposition', '', '~Lamella.Reversed', null],
    ['Jalousie Wohnen Rechts', 'Drehwinkel', '', '~Lamella', null],
    ['Jalousie Wohnen Rechts', 'Sicherung (RF)', '', '~ProtectionRF.ZWave', null],
    ['Markise', 'Sicherung (lokal)', '', '~Protection.ZWave', null],
    ['Klimaanlage', 'Vertikale Lamellen', '', '', null],
    ['Lamellensteuerung', 'Hilfswinkel', '', '~Lamella', null],
    ['Shelly 2PM', 'Slat position', 'slat_pos', '', null],
    ['Heizung', 'Ventil', '', '~Intensity.100', null],
    ['Jalousien Position Wohnzimmer', 'Position', 'Status', '~Shutter', 'raffstore'],
];
foreach ($fall as $i => $f) {
    $ist = VSCHLogik::kandidat($f[0], $f[1], $f[2], $f[3]);
    check(sprintf('D%d Vorschlag %s / %s → %s', 28 + $i, $f[0], $f[1], $f[4] ?? 'nein'), $ist === $f[4], var_export($ist, true));
}

// ---------------------------------------------------------------------------
heading('E Wetter');
$now = ts('2026-07-01 15:00');
$Q = ['wind' => 20.0, 'windSchwelle' => 35.0, 'windSeit' => 0, 'regen' => false, 'regenSeit' => 0, 'warnungen' => [], 'sperre' => null, 'netzBoe' => null, 'halte' => 1200];
check('E1 ruhig: nichts', VSCHLogik::wetter('markise', $Q, $now)['art'] === '');
check('E2 Böe über Schwelle: einfahren', VSCHLogik::wetter('markise', ['wind' => 40.0] + $Q, $now)['art'] === 'einfahren');
check('E3 Haltezeit nach Böe', VSCHLogik::wetter('markise', ['windSeit' => $now - 600] + $Q, $now)['art'] === 'einfahren');
check('E4 nach Haltezeit frei', VSCHLogik::wetter('markise', ['windSeit' => $now - 1300] + $Q, $now)['art'] === '');
check('E5 Regen: nur Markise', VSCHLogik::wetter('markise', ['regen' => true] + $Q, $now)['art'] === 'einfahren' && VSCHLogik::wetter('raffstore', ['regen' => true, 'windSchwelle' => 50.0] + $Q, $now)['art'] === '');
check('E6 externe Sperre', VSCHLogik::wetter('raffstore', ['sperre' => true] + $Q, $now)['art'] === 'einfahren');
check('E7 Netz-Böe vorsichtiger (Schwelle − 10)', VSCHLogik::wetter('markise', ['wind' => null, 'netzBoe' => 26.0] + $Q, $now)['art'] === 'einfahren');
check('E8 Rollladen: Windschwelle 0 = nie', VSCHLogik::wetter('rollladen', ['wind' => 120.0, 'windSchwelle' => 0.0] + $Q, $now)['art'] === '');
$sturm = ['category' => 'sturm', 'hazard' => 'wind', 'event' => 'STURMBÖEN', 'von' => $now + 600, 'bis' => $now + 7200];
check('E9 Warnung erst ab Beginn minus Vorlauf', VSCHLogik::wetter('raffstore', ['warnungen' => [$sturm]] + $Q, $now)['art'] === '');
check('E10 Warnung im Vorlauf: einfahren', VSCHLogik::wetter('raffstore', ['warnungen' => [['von' => $now - 1] + $sturm]] + $Q, $now)['art'] === 'einfahren');
check('E11 Warnung vorbei, Haltezeit läuft', VSCHLogik::wetter('raffstore', ['warnungen' => [['von' => $now - 9000, 'bis' => $now - 600] + $sturm]] + $Q, $now)['art'] === 'einfahren');
check('E12 Warnung + Haltezeit vorbei: frei', VSCHLogik::wetter('raffstore', ['warnungen' => [['von' => $now - 9000, 'bis' => $now - 1300] + $sturm]] + $Q, $now)['art'] === '');
$frost = ['category' => 'sonstige', 'hazard' => 'frost', 'event' => 'FROST', 'von' => $now - 1, 'bis' => $now + 3600];
check('E13 Frost: festhalten', VSCHLogik::wetter('raffstore', ['warnungen' => [$frost]] + $Q, $now)['art'] === 'festhalten');
$regenW = ['category' => 'starkregen', 'hazard' => 'heavy_rain', 'event' => 'STARKREGEN', 'von' => $now - 1, 'bis' => $now + 3600];
check('E14 Starkregen-Warnung nur für Markise', VSCHLogik::wetter('markise', ['warnungen' => [$regenW]] + $Q, $now)['art'] === 'einfahren' && VSCHLogik::wetter('raffstore', ['warnungen' => [$regenW], 'windSchwelle' => 50.0] + $Q, $now)['art'] === '');

heading('E2 WarnHub-Schutzaktionen (Vertrag protection 1.0)');
$akt = ['index' => 0, 'name' => 'Markise rein', 'enabled' => true, 'targetVariableID' => 701, 'targetInstanceID' => 700, 'active' => true,
    'activeEpisodes' => [['event' => 'STURMBÖEN', 'expiresTs' => $now + 3600]], 'neverFired' => false, 'autoRestore' => null];
$merk = [];
check('E15 aktive Aktion auf dieses Gerät: gesperrt', VSCHLogik::schutz([$akt], [701], 700, $merk, $now, 1200) !== null && isset($merk['0']));
$merk2 = [];
check('E16 Aktion auf anderes Gerät: frei', VSCHLogik::schutz([$akt], [601], 600, $merk2, $now, 1200) === null);
check('E17 Treffer über die Instanz (Zielvariable ist eine andere Variable desselben Geräts)', VSCHLogik::schutz([['targetVariableID' => 999] + $akt], [701], 700, $merk2, $now, 1200) !== null);
$vorbei = ['active' => false, 'activeEpisodes' => [], 'lastTrigger' => ['event' => 'STURMBÖEN']] + $akt;
check('E18 nach Ende: Haltezeit läuft', VSCHLogik::schutz([$vorbei], [701], 700, $merk, $now + 600, 1200) !== null);
check('E19 nach Haltezeit: frei, Merker gelöscht', VSCHLogik::schutz([$vorbei], [701], 700, $merk, $now + 1300, 1200) === null && $merk === []);
$merk3 = [];
check('E20 Rückstellung durch WarnHub ausstehend: gesperrt', VSCHLogik::schutz([['autoRestore' => ['restoreValue' => 0, 'calmSinceTs' => $now, 'dueAt' => null]] + $vorbei], [701], 700, $merk3, $now, 1200) !== null);
check('E21 nie ausgelöst und nicht aktiv: frei', VSCHLogik::schutz([['neverFired' => true] + $vorbei], [701], 700, $merk3, $now, 1200) === null);
check('E22 abgeschaltete Aktion zählt nicht', VSCHLogik::schutz([['enabled' => false] + $akt], [701], 700, $merk3, $now, 1200) === null);
$r = VSCHLogik::entscheide(['typ' => 'rollladen'] + $G, ['schutz' => 'HAGEL'] + $U, ['soll' => false], ['an' => 0, 'aus' => 0], $t0);
check('E23 gesicherter Rollladen bleibt unten (keine Beschattungsfahrt)', $r['ziel'] === null && strpos($r['grund'], 'HAGEL') !== false);
$r = VSCHLogik::entscheide(['typ' => 'markise'] + $G, ['schutz' => 'X', 'wetter' => ['art' => 'einfahren', 'grund' => 'Wind']] + $U, ['soll' => true], ['an' => 0, 'aus' => 0], $t0);
check('E24 Wetterschutz-Einfahren geht der Sperre vor', $r['ziel'] === 'offen');

// ---------------------------------------------------------------------------
heading('F Grafik');
$haus = VSCHGeo::ausUmrissen($la, $lo, $poly, 'BW', '');
$bahn = VSCHSonne::bahn($LAT, $LON, '2026-06-21', 'Europe/Berlin', 10);
$uri = VSCHGrafik::grundriss($haus, $haus['fassaden'], $bahn, 2, 'Europe/Berlin');
$svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));
check('F1 Grundriss ist gültiges SVG', strpos($uri, 'data:image/svg+xml;base64,') === 0 && @simplexml_load_string($svg) !== false);
check('F2 Grundriss nennt Fassade D', strpos($svg, '>D</text>') !== false);
$uri = VSCHGrafik::himmel(['heute' => $bahn, 'Dez' => VSCHSonne::bahn($LAT, $LON, '2026-12-21', 'Europe/Berlin', 10)], $H, 180.0, '#c0592b', 'Europe/Berlin', 'Horizont <3×>');
$svg = base64_decode(substr($uri, 26));
check('F3 Himmel ist gültiges SVG (auch mit < > im Text)', @simplexml_load_string($svg) !== false);
check('F4 Grafik ohne Haus (manuelle Fassaden) geht', @simplexml_load_string(base64_decode(substr(VSCHGrafik::grundriss([], [['az' => 135.0]], [], 0, 'Europe/Berlin'), 26))) !== false);
check('F8 Grundriss: Fassade ohne Gerät blass (opacity 0.3)', strpos(base64_decode(substr(VSCHGrafik::grundriss([], [['az' => 166.0, 'aktiv' => true], ['az' => 76.0, 'aktiv' => false]], [], 0, 'Europe/Berlin'), 26)), '<g opacity="0.3">') !== false);
$fenster = VSCHGrafik::sonnenfenster(VSCHSonne::bahn($LAT, $LON, '2026-06-21', 'Europe/Berlin', 5), $H, 2.0);
check('F5 Nordfassade 21.06.: Sonne morgens und abends', count($fenster) === 2, json_encode(array_map(fn($w) => [date('H:i', $w[0]), date('H:i', $w[1])], $fenster)));
check('F6 Nordfassade 21.12.: keine Sonne', VSCHGrafik::sonnenfenster(VSCHSonne::bahn($LAT, $LON, '2026-12-21', 'Europe/Berlin', 5), $H, 2.0) === []);
// Horizont maßstabsgetreu: 10° Gelände liegt auf der 10°-Höhe, nicht auf 30°
$svg = base64_decode(substr(VSCHGrafik::himmel(['heute' => $bahn], [[0, 10], [90, 10], [180, 10], [270, 10]], 180.0, '#c0592b', 'Europe/Berlin', 'Horizont'), 26));
preg_match('/<path d="M[^"]*"/', $svg, $pf);
$ys = array_map(fn($p) => (float)explode(',', $p)[1], array_slice(explode(' L', $pf[0] ?? ''), 1, 10));
check('F7 Horizont maßstabsgetreu (10° ≈ y 199)', $ys !== [] && abs(max($ys) - min($ys)) < 0.2 && abs($ys[0] - 199.2) < 1.5, json_encode(array_slice($ys, 0, 3)));

// Nachbargebäude (0.6.0): 2 Geschosse + 45°-Dach, First parallel zur längeren Seite
$hausS = [[-5, -10], [5, -10], [5, -18], [-5, -18]];
$md = VSCHGebaeude::modell($hausS);
check('F20 Gebäude ohne Angaben: Traufe 6 m (2 Geschosse), First 10 m (halbe Breite 4 m bei 45°)', $md['traufe'] == 6.0 && $md['first'] == 10.0, json_encode([$md['traufe'], $md['first']]));
$gh = VSCHGebaeude::horizont([$hausS], [], [0, 0]);
check('F21 Haus 10 m südlich: Höhenwinkel nach Süden vom First (atan(8/14) ≈ 29,7°)', abs(($gh[180] ?? 0) - 29.7) < 0.3, json_encode($gh[180] ?? null));
check('F22 kein Gebäude im Norden', !isset($gh[0]) && !isset($gh[90]));
$gf = VSCHGebaeude::horizont([$hausS], [['dach' => 'flat']], [0, 0]);
check('F23 Flachdach: Traufkante vorn (atan(4/10) ≈ 21,8°)', abs(($gf[180] ?? 0) - 21.8) < 0.3, json_encode($gf[180] ?? null));
$gq = VSCHGebaeude::horizont([$hausS], [['first' => 'across']], [0, 0]);
check('F24 First quer (OSM roof:orientation=across): First 11 m, atan(9/10) ≈ 42°', abs(($gq[180] ?? 0) - 42.0) < 0.3, json_encode($gq[180] ?? null));
$g3 = VSCHGebaeude::horizont([$hausS], [['stockwerke' => 3]], [0, 0]);
check('F25 OSM 3 Geschosse: höher als 2', ($g3[180] ?? 0) > ($gh[180] ?? 0) + 5);
check('F25b Haus hinter der Fassade (Nordfassade sieht das Südhaus nicht)', VSCHGebaeude::horizont([$hausS], [], [0, 0], 0.0) === [] && isset(VSCHGebaeude::horizont([$hausS], [], [0, 0], 180.0)[180]));
$zs = VSCHGebaeude::zusammen([[0, 3], [90, 3], [180, 3], [270, 3]], $gh);
check('F26 Gelände und Gebäude: je Grad das Höhere', VSCHHorizont::hoehe($zs, 90) == 3.0 && abs(VSCHHorizont::hoehe($zs, 180) - 29.7) < 0.3 && count($zs) === 360);
$au = VSCHGebaeude::auge(['fassaden' => [['az' => 180, 'seg' => [[[-4, -2], [4, -2]]]]]], 175);
check('F27 Fenster-Standort: Mitte der Südfassade, 0,5 m davor', abs($au[0]) < 0.01 && abs($au[1] + 2.5) < 0.01, json_encode($au));
$gar = VSCHGebaeude::modell([[0, 0], [6, 0], [6, 3], [0, 3]], ['art' => 'Garage']);
check('F29 Garage (ALKIS-Funktion): eingeschossig, Flachdach, 3 m', $gar['traufe'] == 3.0 && $gar['first'] == 3.0 && $gar['neben'] === true, json_encode([$gar['traufe'], $gar['first']]));
$kl = VSCHGebaeude::modell([[0, 0], [6, 0], [6, 5], [0, 5]]);
check('F30 unter 40 m² ohne Angabe: Nebengebäude (3 m)', $kl['first'] == 3.0 && $kl['neben'] === true);
$wh = VSCHGebaeude::modell([[0, 0], [12, 0], [12, 9], [0, 9]], ['art' => 'Wohnhaus']);
check('F31 Wohnhaus bleibt 2 Geschosse + 45°-Dach', $wh['traufe'] == 6.0 && $wh['first'] == 10.5 && $wh['neben'] === false);
check('F32 OSM building=garage zählt als Nebengebäude, Angabe Geschosse geht vor', VSCHGebaeude::modell([[0, 0], [12, 0], [12, 9], [0, 9]], ['art' => 'garages'])['neben'] === true
    && VSCHGebaeude::modell([[0, 0], [6, 0], [6, 3], [0, 3]], ['art' => 'Garage', 'stockwerke' => 2])['traufe'] == 6.0);
$gml = '<wfs:FeatureCollection><wfs:member><nora:v_al_gebaeude><nora:gebaeudefunktion_name>Garage</nora:gebaeudefunktion_name><nora:geom><gml:Polygon><gml:exterior><gml:LinearRing><gml:posList>420000 5355000 420006 5355000 420006 5355003 420000 5355003 420000 5355000</gml:posList></gml:LinearRing></gml:exterior></gml:Polygon></nora:geom></nora:v_al_gebaeude></wfs:member>'
    . '<wfs:member><nora:v_al_gebaeude><nora:gebaeudefunktion_name>Wohnhaus</nora:gebaeudefunktion_name><nora:geom><gml:Polygon><gml:exterior><gml:LinearRing><gml:posList>420010 5355000 420022 5355000 420022 5355009 420010 5355009 420010 5355000</gml:posList></gml:LinearRing></gml:exterior></gml:Polygon></nora:geom></nora:v_al_gebaeude></wfs:member></wfs:FeatureCollection>';
$gp = VSCHGeo::gmlPolygone($gml, 25832);
check('F33 ALKIS-GML: Gebäudefunktion je Objekt gelesen', count($gp) === 2 && $gp[0]['art'] === 'Garage' && $gp[1]['art'] === 'Wohnhaus', json_encode(array_column($gp, 'art')));
check('F28 Himmelsbild zeichnet Nachbargebäude', str_contains(base64_decode(substr(VSCHGrafik::himmel(['heute' => $bahn], [[0, 2], [180, 2]], 180.0, '#c0592b', 'Europe/Berlin', 'Horizont', $gh, 'Nachbarhaus'), 26)), 'Nachbarhaus'));

// ---------------------------------------------------------------------------
heading('G Modul');
require __DIR__ . '/symcon-nachbau.inc.php';
require $root . '/Verschattung/module.php';
class VerschattungTest extends Verschattung
{
    protected function now(): int { return $GLOBALS['CLOCK']; }
}
$GLOBALS['CLOCK'] = ts('2026-07-01 13:30');
mkinst(500, 'Standort', 0, '{45E97A63-F870-408A-B259-2933F7EABF74}');
$GLOBALS['CONF_PROP'][500]['Location'] = json_encode(['latitude' => $LAT, 'longitude' => $LON]);
mkinst(600, 'Raffstore Süd', 0);
mkvar(601, VARIABLETYPE_INTEGER, 0, 'Position', 600, true, '~Shutter');
mkvar(602, VARIABLETYPE_INTEGER, 0, 'Lamelle', 600, true, '~Intensity.100');
mkinst(700, 'Markise', 0);
mkvar(701, VARIABLETYPE_INTEGER, 0, 'Position', 700, true, '~Shutter');
mkvar(800, VARIABLETYPE_FLOAT, 10.0, 'Windböe', 0, false, 'Wind.ms'); // m/s!
$m = new VerschattungTest(12345);
$m->Create();
$m->props['Fassaden'] = json_encode([['Kennung' => 'A', 'Name' => 'Süd', 'Azimut' => 180, 'Aktiv' => true], ['Kennung' => 'B', 'Name' => 'Nord', 'Azimut' => 0, 'Aktiv' => true]]);
$m->props['Geraete'] = json_encode([
    ['Name' => 'Raffstore Büro', 'Fassade' => 'A', 'Typ' => 'raffstore', 'Position' => 601, 'Richtung' => 0, 'Lamelle' => 602, 'LamelleUmgekehrt' => false, 'Kontakt' => 0, 'Beschattung' => 0],
    ['Name' => 'Markise', 'Fassade' => 'A', 'Typ' => 'markise', 'Position' => 701, 'Richtung' => 0, 'Lamelle' => 0, 'LamelleUmgekehrt' => false, 'Kontakt' => 0, 'Beschattung' => 0],
]);
$m->props['WindID'] = 800;
$m->props['VerzoegerungAn'] = 0;
$m->ApplyChanges();
check('G1 Timer läuft, Status aktiv', $m->timers['Tick'] === 60000 && $m->status === 102);
check('G2 Automatik beim Anlegen an', $m->GetValue('Automatik') === true);
check('G3 Positionen und Wind abonniert', isset($m->messages[601]) && isset($m->messages[701]) && isset($m->messages[800]));
$m->Tick();
check('G4 Mittag Süd: Raffstore fährt runter (~Shutter: 100 = zu)', GetValue(601) === 100, (string)GetValue(601));
check('G5 Lamelle: Mittagssonne steil → waagerecht (0)', in_array([602, 0], $GLOBALS['BEFEHLE'] ?? [], true), json_encode($GLOBALS['BEFEHLE'] ?? []));
check('G6 Wind 10 m/s = 36 km/h ≥ 35: Markise bleibt drin', GetValue(701) === 0);
check('G7 Status nennt Gerät', strpos((string)$m->GetValue('Status'), 'Raffstore Büro') !== false);
// Echo des eigenen Befehls ist keine Handbedienung
fire($m, 601, 100);
check('G8 Echo zählt nicht als Hand', (json_decode($m->attrs['Zustand'], true)['g601']['hand'] ?? null) === null);
// Hand
$GLOBALS['CLOCK'] += 600;
fire($m, 601, 30);
check('G9 fremde Änderung = Handbedienung', (json_decode($m->attrs['Zustand'], true)['g601']['hand'] ?? null) === true);
$m->Tick();
check('G10 nach Hand: kein neuer Befehl', GetValue(601) === 30);
// Wind fällt, Haltezeit
SetValue(800, 3.0);
$GLOBALS['CLOCK'] += 60;
$m->MessageSink($GLOBALS['CLOCK'], 800, VM_UPDATE, [3.0, true, 10.0, $GLOBALS['CLOCK']]);
check('G11 Wind weg: Markise wartet Haltezeit', GetValue(701) === 0);
$GLOBALS['CLOCK'] += 21 * 60;
$m->Tick();
check('G12 nach Haltezeit: Markise fährt aus', GetValue(701) === 100, (string)GetValue(701));
// Wind kommt: sofort über MessageSink
SetValue(800, 12.0);
$m->MessageSink($GLOBALS['CLOCK'], 800, VM_UPDATE, [12.0, true, 3.0, $GLOBALS['CLOCK']]);
check('G13 Böe: Markise fährt sofort ein (ohne Minutentakt)', GetValue(701) === 0, (string)GetValue(701));
// Formular
$form = json_decode($m->GetConfigurationForm(), true);
check('G14 Formular ist JSON mit Elementen', is_array($form) && count($form['elements']) >= 6);
$namen = [];
$walk = function ($x) use (&$walk, &$namen) {
    if (is_array($x)) {
        if (isset($x['name'], $x['type'])) {
            $namen[] = $x['name'];
        }
        foreach ($x as $v) {
            $walk($v);
        }
    }
};
$walk($form['elements']);
$walk($form['actions']);
foreach (['Standort', 'Fassaden', 'Geraete', 'WindID', 'RegenID', 'SperreID', 'WarnHubNutzen', 'NetzWetter', 'MarkiseOhneWetter', 'HelligkeitID', 'SonneAn', 'SonneAus', 'TemperaturID', 'AussenSommer', 'InnenGrenze', 'RaumHellLux', 'VerzoegerungAn', 'VerzoegerungAus', 'WindMarkise', 'WindRaffstore', 'Haltezeit'] as $p) {
    check("G15 Eigenschaft $p im Formular", in_array($p, $namen, true));
}
check('G16 Grafiken im Formular', in_array('Grundriss', $namen, true) && in_array('Himmel', $namen, true));
// Vorschau-Knopf
$m->fieldUpdates = [];
$m->UIVorschau($m->props['Fassaden'], 0, 'juni');
$f = array_column($m->fieldUpdates, 2, 0);
check('G17 Vorschau aktualisiert Bilder und Text', isset($f['Grundriss'], $f['Himmel'], $f['Vorschau']) && strpos($f['Vorschau'], 'A ') !== false);
// Automatisch erkennen: schreibt nur ins offene Formular, ergänzt Geräte und leere Sensorfelder
mkinst(950, 'Jalousie Kind I', 949);
mkinst(949, 'Jalousien', 0);
$GLOBALS['OBJ'][949]['kind'] = 0;
mkvar(951, 1, 0, 'Position', 950, true, '~Shutter.Reversed', 'CoverPosition');
mkvar(952, 1, 0, 'Lamellenposition', 950, true, '~Lamella.Reversed', 'SlatPosition');
mkvar(953, 1, 4, 'Status', 950, true, '~ShutterMoveStop', 'State');
mkinst(955, 'Kind 1 Balkontüre', 0);
mkvar(956, 0, true, 'Status', 955, true, '', 'E1_BooleanState_State');
mkvar(957, 0, false, 'Ersatz erforderlich', 955, false, '', 'E0_PowerSource_BatReplacementNeeded');
$m->fieldUpdates = [];
$msg = $m->UIAutomatik($m->props['Geraete'], 0, 0, 0, 0);
$f = array_column($m->fieldUpdates, 2, 0);
$liste = json_decode($f['Geraete'] ?? '[]', true);
$neu = array_values(array_filter($liste, fn($g) => $g['Position'] === 951));
check('G18 Erkennen: UpdateFormField statt Eigenschaft, bestehende Geräte bleiben', count(json_decode($m->props['Geraete'], true)) === 2 && count($liste) >= 3);
check('G20 Erkennen: Kind I als Raffstore mit umgekehrter Lamelle und Kontakt', count($neu) === 1 && $neu[0]['Typ'] === 'raffstore' && $neu[0]['Lamelle'] === 952
    && $neu[0]['LamelleUmgekehrt'] === true && $neu[0]['Kontakt'] === 956 && $neu[0]['Fassade'] === '', json_encode($neu));
check('G21 Erkennen: Meldung nennt Anzahl und Fassadenwahl', strpos($msg, 'Fassade wählen') !== false, $msg);
// Laufrichtung aus Rollladen-Darstellung (offen bei / geschlossen bei), ohne Profil
mkvar(960, 1, 0, 'Höhe', 950, true, '');
$GLOBALS['OBJ'][960]['pres'] = ['PRESENTATION' => VSCHErkennung::SHUTTER_DARSTELLUNG, 'USAGE_TYPE' => 0, 'OPEN_OUTSIDE_VALUE' => 100, 'CLOSE_INSIDE_VALUE' => 0];
$GLOBALS['OBJ'][961] = $GLOBALS['OBJ'][960]; $GLOBALS['OBJ'][961]['pres'] = ['PRESENTATION' => VSCHErkennung::SHUTTER_DARSTELLUNG, 'USAGE_TYPE' => 0, 'OPEN_OUTSIDE_VALUE' => 0, 'CLOSE_INSIDE_VALUE' => 255];
$ref = new ReflectionMethod($m, 'nullIstOffen'); $ref->setAccessible(true);
$mx = new ReflectionMethod($m, 'maxWert'); $mx->setAccessible(true);
check('G23 Darstellung „offen bei 100, geschlossen bei 0“: 0 % heißt zu', $ref->invoke($m, ['Richtung' => 0, 'Position' => 960]) === false);
check('G24 Darstellung „offen bei 0, geschlossen bei 255“: 0 = offen, Höchstwert 255', $ref->invoke($m, ['Richtung' => 0, 'Position' => 961]) === true && $mx->invoke($m, 961) == 255.0);
check('G25 Spalte „0 % bedeutet“ des Nutzers geht vor', $ref->invoke($m, ['Richtung' => 1, 'Position' => 960]) === true);
// sichtbare Verbindung unter den Sensorfeldern
$vt = new ReflectionMethod($m, 'verbindungsText'); $vt->setAccessible(true);
mkvar(970, 2, 3.1, 'Außentemperatur', 0, false, '~Temperature');
$GLOBALS['OBJ'][970]['updated'] = $GLOBALS['CLOCK'] - 120;
check('G26 verbunden: Name, aktueller Wert und Alter sichtbar', strpos($vt->invoke($m, 'TemperaturID', 970, []), '✓ Außentemperatur: 3,1 · vor 2 min') === 0, $vt->invoke($m, 'TemperaturID', 970, []));
check('G27 leer, Kandidat gefunden: „🔍 Gefunden“ mit Wert', strpos($vt->invoke($m, 'TemperaturID', 0, ['TemperaturID' => 970, 'hinweise' => []]), '🔍 Gefunden: Außentemperatur (3,1') === 0);
check('G28 gelöschte Variable wird gemeldet', strpos($vt->invoke($m, 'WindID', 999999, []), '⚠️') === 0);
check('G29 WarnHub-Hinweis statt „nicht verbunden“', strpos($vt->invoke($m, 'WindID', 0, ['WindID' => 0, 'hinweise' => ['WindID' => 'warnhub']]), 'WarnHub') !== false);
$fo = new ReflectionMethod($m, 'fensterOffen'); $fo->setAccessible(true);
mkvar(980, 0, false, 'Fenster Links', 0, false, '~Window');
mkvar(981, 0, true, 'Fenster Rechts', 0, false, '~Window');
check('G30 zweiter Kontakt offen → Fenster gilt als offen', $fo->invoke($m, ['Kontakt' => 980, 'Kontakt2' => 981]) === true);
check('G30b vierter Kontakt offen → Fenster gilt als offen', $fo->invoke($m, ['Kontakt' => 980, 'Kontakt2' => 0, 'Kontakt3' => 0, 'Kontakt4' => 981]) === true);
check('G31 beide zu → nicht offen', (function () use ($fo, $m) { SetValue(981, false); return $fo->invoke($m, ['Kontakt' => 980, 'Kontakt2' => 981]) === false; })());
$w2 = new ReflectionMethod($m, 'wm2'); $w2->setAccessible(true);
mkvar(985, 2, 30000.0, 'Helligkeit', 0, false, ''); $GLOBALS['OBJ'][985]['suffix'] = ' lx';
mkvar(986, 2, 21617.0, 'Solar Einstrahlung', 0, false, '', 'solarradiation'); $GLOBALS['OBJ'][986]['suffix'] = ' lx';
mkvar(987, 2, 412.0, 'Strahlung', 0, false, ''); $GLOBALS['OBJ'][987]['suffix'] = ' W/m²';
mkvar(988, 2, 30.0, 'Helligkeit', 0, false, ''); $GLOBALS['OBJ'][988]['suffix'] = ' klx';
check('G32 W/m²: die Einheit entscheidet – Lux umgerechnet (auch Froggit „Solar Einstrahlung“ in lx, Live-Befund 24.11.), W/m² direkt, klx', $w2->invoke($m, 985) == 250.0 && abs($w2->invoke($m, 986) - 180.1) < 0.1 && $w2->invoke($m, 987) == 412.0
    && $w2->invoke($m, 988) == 250.0 && $w2->invoke($m, 0) === null);
check('G33 Verbindung zeigt den umgerechneten Wert', strpos($vt->invoke($m, 'HelligkeitID', 985, []), '≈ 250 W/m²') !== false && strpos($vt->invoke($m, 'HelligkeitID', 987, []), '≈') === false);
$lu = new ReflectionMethod($m, 'lux'); $lu->setAccessible(true);
check('G34 Raumhelligkeit in Lux: lx direkt, klx × 1000, W/m² × 120', $lu->invoke($m, 985) == 30000.0 && $lu->invoke($m, 988) == 30000.0 && $lu->invoke($m, 987) == 412.0 * 120 && $lu->invoke($m, 0) === null);
check('G22 Probefahrt-Text Einzahl', strpos(VSCHText::t('1 device of facade %s is moving. If it moves the wrong way, change its "0 %% means" column.', 'A'), '1 Gerät der Fassade A fährt') === 0);
// 0.5.0: Warum-Probelauf, Tür/Fenster, Notfall, Wärmepumpe
$fl = new ReflectionMethod($m, 'fensterLage'); $fl->setAccessible(true);
mkinst(990100, 'Terrassentür Links', 0); mkvar(990101, 0, true, 'Zustand', 990100, false, '~Window');
mkinst(990110, 'Fenster Bad', 0); mkvar(990111, 0, true, 'Zustand', 990110, false, '~Window');
mkinst(990120, 'Griff Küche', 0); mkvar(990121, 1, 1, 'Griff', 990120, false, ''); $GLOBALS['GVF'][990121] = 'gekippt';
check('G40 Tür offen → Aussperrschutz', $fl->invoke($m, ['Kontakt' => 990101, 'BeiOffen' => 0])[0] === 'sperre');
check('G41 auch „Fenster“ offen → Aussperrschutz (Name sagt nicht, ob man hinausgeht; Dietmar 24.11.)', $fl->invoke($m, ['Kontakt' => 990111, 'BeiOffen' => 0])[0] === 'sperre');
check('G42 Spalte „Kontakt offen: Lüftungsposition (Fenster)“ erlaubt Lüften', $fl->invoke($m, ['Kontakt' => 990111, 'BeiOffen' => 2])[0] === 'lueften');
check('G43 Griff meldet „gekippt“ → Lüften', $fl->invoke($m, ['Kontakt' => 990121, 'BeiOffen' => 0])[0] === 'lueften');
SetValue(990111, false);
check('G44 alles zu', $fl->invoke($m, ['Kontakt' => 990111, 'Kontakt2' => 0, 'BeiOffen' => 0])[0] === 'zu');
$vorher = [$m->attrs['Zustand'] ?? '', count($GLOBALS['BEFEHLE'] ?? [])];
$txt = $m->UIWarum();
check('G45 „Warum?“: Probelauf ohne Fahrt und ohne Zustandsänderung, mit Messwerten je Gerät', $vorher === [$m->attrs['Zustand'] ?? '', count($GLOBALS['BEFEHLE'] ?? [])]
    && str_contains($txt, 'Raffstore Büro') && str_contains($txt, 'Fassade A') && str_contains($txt, '→ '), $txt);
// Notfall
mkinst(990200, 'Rauchmelder Flur', 0); mkvar(990201, 0, false, 'Alarm', 990200, false, '~Alert');
$m->props['Notfall'] = json_encode([['Melder' => 990201]]);
SetValue(990201, true);
$GLOBALS['BEFEHLE'] = [];
$m->Tick();
$offen601 = array_values(array_filter($GLOBALS['BEFEHLE'], fn($b) => $b[0] === 601));
check('G46 Notfall: Rauchmelder-Alarm fährt die Behänge hoch', $offen601 !== [] && (float)end($offen601)[1] === 0.0 && str_contains((string)$m->GetValue('Status'), 'Notfall'), json_encode($GLOBALS['BEFEHLE']) . ' ' . $m->GetValue('Status'));
SetValue(990201, false);
$m->props['Notfall'] = '[]';
// Wärmepumpe über den Verbundvertrag
$GLOBALS['MODULINFO']['{WPTEST}'] = ['ModuleType' => 3, 'Prefix' => 'WPTEST'];
$GLOBALS['MODULES']['{WPTEST}'] = [990300];
mkinst(990300, 'Wärmepumpe', 0); mkvar(990301, 1, 1, 'Betriebsart', 990300, false, '');
function WPTEST_GetFunctions(int $id): array { return [['contractType' => 'heatpump', 'contractVersion' => '1.19', 'operatingModeNormID' => 990301]]; }
$we = new ReflectionMethod($m, 'wpErkennen'); $we->setAccessible(true);
check('G47 Wärmepumpe: Betriebsart aus dem heatpump-Vertrag gefunden', $we->invoke($m) === 990301);
unset($GLOBALS['MODULINFO']['{WPTEST}']);
// Vorschau: Nachbargebäude ein- und ausblenden
$m->attrs['Haus'] = json_encode(['eigen' => [], 'nachbarn' => [[[-5, -10], [5, -10], [5, -18], [-5, -18]]], 'fassaden' => []]);
$m->fieldUpdates = [];
$m->UIVorschau(json_encode([['Kennung' => 'A', 'Name' => 'Süd', 'Azimut' => 180, 'Aktiv' => true]]), 0, 'heute', true);
$mit = base64_decode(substr(array_column($m->fieldUpdates, 2, 0)['Himmel'] ?? '', 26));
$m->fieldUpdates = [];
$m->UIVorschau(json_encode([['Kennung' => 'A', 'Name' => 'Süd', 'Azimut' => 180, 'Aktiv' => true]]), 0, 'heute', false);
$ohne = base64_decode(substr(array_column($m->fieldUpdates, 2, 0)['Himmel'] ?? '', 26));
check('G48 Vorschau: Nachbargebäude ein- und ausblendbar', str_contains($mit, 'Nachbargebäude') && !str_contains($ohne, 'Nachbargebäude'));
$m->attrs['Haus'] = '{}';
// Status 201 ohne Standort
$GLOBALS['CONF_PROP'][500]['Location'] = json_encode(['latitude' => 0, 'longitude' => 0]);
$m2 = new VerschattungTest(22222);
$m2->Create();
$m2->ApplyChanges();
check('G19 ohne Standort: Status 201, Timer aus, Instanz trotzdem angelegt', $m2->status === 201 && $m2->timers['Tick'] === 0);
check('G20 Formular auch ohne Standort', is_array(json_decode($m2->GetConfigurationForm(), true)));
$GLOBALS['CONF_PROP'][500]['Location'] = json_encode(['latitude' => $LAT, 'longitude' => $LON]);
// WarnHub-Vertrag: nur feste Standorte
$GLOBALS['MODULES']['{97621F9C-C61B-4A7F-9B03-C34BC58B04A6}'] = [900];
$GLOBALS['CONF_PROP'][900]['SchutzaktionVorlaufMinuten'] = 30;
$GLOBALS['WHUB'] = ['contractVersion' => '1.0', 'standorte' => [
    ['standortKey' => 'h', 'name' => 'Zuhause', 'active' => true, 'mobile' => false, 'isSystemLocation' => true],
    ['standortKey' => 'auto', 'name' => 'Auto', 'active' => true, 'mobile' => true, 'isSystemLocation' => false]],
    'warnings' => [['standortKey' => 'auto', 'category' => 'sturm', 'hazard' => 'wind', 'event' => 'ORKAN', 'onsetTs' => $GLOBALS['CLOCK'] - 60, 'expiresTs' => $GLOBALS['CLOCK'] + 3600]]];
function WHUB_GetActiveWarnings(int $id): string { return json_encode($GLOBALS['WHUB']); }
SetValue(800, 1.0);
$m->props['WindID'] = 0;
$m->attrs['WindSeit'] = '{}';
$GLOBALS['CLOCK'] += 3600;
$m->Tick();
check('G21 Sturm am Auto-Standort: Markise darf ausfahren', GetValue(701) === 100, (string)GetValue(701));
$GLOBALS['WHUB']['warnings'][] = ['standortKey' => 'h', 'category' => 'sturm', 'hazard' => 'wind', 'event' => 'STURMBÖEN', 'onsetTs' => $GLOBALS['CLOCK'] + 20 * 60, 'expiresTs' => $GLOBALS['CLOCK'] + 7200];
$m->Tick();
check('G22 Sturm zuhause in 20 min (Vorlauf 30): Markise fährt jetzt ein', GetValue(701) === 0, (string)GetValue(701));
// Schutzaktion von WarnHub auf den Raffstore: bleibt stehen, auch wenn die Sonne ihn sonst fahren würde
$GLOBALS['WHUB']['warnings'] = [];
$GLOBALS['WHUB_P'] = ['contractVersion' => '1.0', 'actions' => [['index' => 3, 'name' => 'Raffstore hoch', 'enabled' => true, 'targetVariableID' => 601, 'targetInstanceID' => 600,
    'active' => true, 'activeEpisodes' => [['event' => 'HAGEL']], 'autoRestore' => null]]];
function WHUB_GetProtectionState(int $id): string { return json_encode($GLOBALS["WHUB_P"] ?? ["contractVersion" => "1.0", "actions" => []]); }
SetValue(601, 0);
$z = json_decode($m->attrs['Zustand'], true);
$z['g601'] = ['soll' => false, 'wir' => false];
$m->attrs['Zustand'] = json_encode($z);
$GLOBALS['CLOCK'] += 7200;
$GLOBALS['BEFEHLE'] = [];
$m->Tick();
check('G24 von WarnHub gesicherter Raffstore fährt nicht', GetValue(601) === 0 && !in_array(601, array_column($GLOBALS['BEFEHLE'], 0), true), json_encode($GLOBALS['BEFEHLE']));
check('G25 Status nennt WarnHub', strpos((string)$m->GetValue('Status'), 'HAGEL') !== false, (string)$m->GetValue('Status'));
$GLOBALS['WHUB_P']['contractVersion'] = '2.0';
$m->Tick();
check('G26 fremde Hauptversion: Vertrag wird ignoriert', GetValue(601) === 100, (string)GetValue(601));
check('G23 GetState liefert Geräte', count(json_decode($m->GetState(), true)['devices']) === 2);

// ---------------------------------------------------------------------------
heading('I Automatische Erkennung (Nachbildung eines echten Bestands)');
$zw = '{101352E1-88C7-4F16-998B-E20D50779AF6}';
$sh = '{362656D9-579E-F416-7C0B-CD01C08A3800}';
$mt = '{MATTER}';
$zwave = fn(int $b, string $n) => ['name' => $n, 'ort' => 'Jalousien', 'modul' => $zw, 'vars' => [
    [$b + 1, 'Position', 'IntensityVariable', 1, '~Shutter', true], [$b + 2, 'Drehwinkel', '', 1, '~Lamella', true],
    [$b + 3, 'Status', 'StatusVariable', 0, '~Switch', true], [$b + 4, 'Sicherung (RF)', 'ProtectionRFVariable', 1, '~ProtectionRF.ZWave', true],
    [$b + 5, 'Meter (2), Elektro, W', 'Meter2Variable', 2, '~Watt.3680', false]]];
$shelly = fn(int $b, string $n) => ['name' => $n, 'ort' => 'Jalousien', 'modul' => $sh, 'vars' => [
    [$b + 1, 'Position', 'CoverPosition', 1, '~Shutter.Reversed', true], [$b + 2, 'Status', 'State', 1, '~ShutterMoveStop', true],
    [$b + 3, 'Lamellenposition', 'SlatPosition', 1, '~Lamella.Reversed', true], [$b + 4, 'Eingang 1', 'Input0', 0, '~Switch', false]]];
$kontakt = fn(int $b, string $n) => ['name' => $n, 'ort' => 'Öffnungskontakte', 'modul' => $mt, 'vars' => [
    [$b + 1, 'Status', 'E1_BooleanState_State', 0, '', true], [$b + 2, 'Ersatz erforderlich', 'E0_PowerSource_BatReplacementNeeded', 0, '', false]]];
$IN = [
    100 => $zwave(100, 'Jalousie Wohnen Rechts'), 110 => $zwave(110, 'Jalousie Wohnen Links'), 120 => $zwave(120, 'Jalousie Esszimmer'),
    130 => $shelly(130, 'Jalousie Kind I'), 140 => $shelly(140, 'Jalousie Kind II'), 150 => $shelly(150, 'Jalousie Schlafzimmer'),
    160 => ['name' => 'Markise', 'ort' => 'Jalousien', 'modul' => $zw, 'vars' => [[161, 'Position', 'IntensityVariable', 1, '~Shutter', true], [162, 'Sicherung (lokal)', 'ProtectionVariable', 1, '~Protection.ZWave', true]]],
    170 => ['name' => 'Jalousien Position Wohnzimmer', 'ort' => 'Jalousien Wohnen', 'modul' => VSCHErkennung::GRUPPE_GUID, 'vars' => [[171, 'Position', 'Status', 1, '~Shutter', true]]],
    200 => $kontakt(200, 'Wohnen Terrassentür Links'), 210 => $kontakt(210, 'Wohnen Terrassentür Rechts'), 220 => $kontakt(220, 'Essen Terrassentür'),
    230 => $kontakt(230, 'Kind 1 Balkontüre'), 240 => $kontakt(240, 'Kind 2 Balkontüre'), 250 => $kontakt(250, 'Schlafzimmer Fenster Links'),
    260 => $kontakt(260, 'Schlafzimmer Fenster Rechts'), 270 => $kontakt(270, 'Küche Fenster'),
    280 => ['name' => 'Haustüre', 'ort' => 'Schlösser', 'modul' => $zw, 'vars' => [[281, 'Türschloß (Türstatus)', 'DoorLockDoorState', 0, '~Lock', true]]],
    300 => ['name' => 'Wetterstation', 'ort' => 'Geräte', 'modul' => '{FROGGIT}', 'vars' => [
        [301, 'Windböe', 'windgustmph', 2, '~WindSpeed.kmh', false], [302, 'Windböe (Max.) Tag', 'maxdailygust', 2, '~WindSpeed.kmh', false],
        [303, 'Windgeschwindigkeit', 'windspeedmph', 2, '~WindSpeed.kmh', false], [304, 'Windrichtung', 'winddir_int', 1, '~WindDirection', false],
        [305, 'Regenrate', 'rainratein', 2, '~Rainfall', false], [306, 'Regenmenge Tag', 'dailyrainin', 2, '~Rainfall', false],
        [307, 'Außentemperatur', 'tempf', 2, '~Temperature', false], [308, 'Innentemperatur', 'tempinf', 2, '~Temperature', false],
        [309, 'Gefühlte Temperatur', 'windchill', 2, '~Temperature', false], [310, 'Taupunkt', 'dewpoint', 2, '~Temperature', false],
        [311, 'Solar Einstrahlung', 'solarradiation', 1, '~Illumination', false]]],
    320 => ['name' => 'Wetter-Prognose', 'ort' => '', 'modul' => '{OWM}', 'vars' => [[321, 'Windgeschwindigkeit #1', '', 2, 'OpenWeatherMap.WindSpeed', false]]],
    330 => ['name' => 'Sensor Wohnzimmer', 'ort' => 'Sensoren', 'modul' => $zw, 'vars' => [[331, 'Helligkeit', '', 2, 'Illumination', false]]],
    350 => ['name' => 'Börsenpreis', 'ort' => '', 'modul' => '{SPOT}', 'vars' => [[351, 'Börsenpreis jetzt', 'Price', 2, 'SPOT.Price', false]]],
    360 => ['name' => 'Mähroboter', 'ort' => 'Garten', 'modul' => '{ROBO}', 'vars' => [[361, 'Regen erkannt', 'rain', 0, '', false]]],
    380 => ['name' => 'Hydraulik', 'ort' => '', 'modul' => '', 'vars' => [[381, 'Wasserfluss-Geschwindigkeit', 'speed', 1, '', false]]],
    370 => ['name' => 'Thermostat Windfang', 'ort' => 'EG', 'modul' => $zw, 'vars' => [[371, 'Windfang Isttemperatur', 'temp', 2, '~Temperature', false]]],
    340 => ['name' => 'Kohlekasten', 'ort' => '', 'modul' => '{TESSIE}', 'vars' => [[341, 'Außentemperatur (°C)', '', 2, '', false]]],
];
$G = array_column(VSCHErkennung::geraete($IN, []), null, 'Name');
check('I1 sieben Behänge, Gruppensteuerung ausgelassen', count($G) === 7 && !isset($G['Jalousien Position Wohnzimmer']), implode(', ', array_keys($G)));
check('I2 Z-Wave: Position + Drehwinkel → Raffstore', ($G['Jalousie Wohnen Rechts']['Position'] ?? 0) === 101 && $G['Jalousie Wohnen Rechts']['Lamelle'] === 102 && $G['Jalousie Wohnen Rechts']['Typ'] === 'raffstore' && $G['Jalousie Wohnen Rechts']['LamelleUmgekehrt'] === false);
check('I3 Shelly: ~Lamella.Reversed → umgekehrt', ($G['Jalousie Kind I']['Lamelle'] ?? 0) === 133 && $G['Jalousie Kind I']['LamelleUmgekehrt'] === true);
check('I4 Markise: Typ Markise, ohne Lamelle', ($G['Markise']['Typ'] ?? '') === 'markise' && $G['Markise']['Lamelle'] === 0 && $G['Markise']['Position'] === 161);
check('I5 Kontakt Wohnen Rechts → Terrassentür Rechts', ($G['Jalousie Wohnen Rechts']['Kontakt'] ?? 0) === 211);
check('I6 Kontakt Wohnen Links → Terrassentür Links', ($G['Jalousie Wohnen Links']['Kontakt'] ?? 0) === 201);
check('I7 Kontakt Esszimmer → Essen Terrassentür', ($G['Jalousie Esszimmer']['Kontakt'] ?? 0) === 221);
check('I8 Kontakt Kind I → Kind 1 (römisch = arabisch)', ($G['Jalousie Kind I']['Kontakt'] ?? 0) === 231);
check('I9 Kontakt Kind II → Kind 2, nicht Kind 1', ($G['Jalousie Kind II']['Kontakt'] ?? 0) === 241);
check('I10 Schlafzimmer: zwei Kontakte an einem Fenster → beide (Live-Befund 24.11.)', ($G['Jalousie Schlafzimmer']['Kontakt'] ?? -1) === 251 && ($G['Jalousie Schlafzimmer']['Kontakt2'] ?? -1) === 261, json_encode($G['Jalousie Schlafzimmer'] ?? null));
check('I10b Seite trennt weiterhin: Wohnen Rechts nur Terrassentür Rechts', ($G['Jalousie Wohnen Rechts']['Kontakt2'] ?? -1) === 0);
check('I10c Fensterfront mit vier Kontakten: alle vier', VSCHErkennung::kontakteFuer('Rollladen Wintergarten', [[1, 'Wintergarten Flügel 1'], [2, 'Wintergarten Flügel 2'], [3, 'Wintergarten Flügel 3'], [4, 'Wintergarten Tür']]) === [1, 2, 3, 4]);
check('I10d mehr als vier passende Kontakte: nicht raten', VSCHErkennung::kontakteFuer('Rollladen Wintergarten', [[1, 'Wintergarten A'], [2, 'Wintergarten B'], [3, 'Wintergarten C'], [4, 'Wintergarten D'], [5, 'Wintergarten E']]) === []);
check('I11 Markise: kein Kontakt', ($G['Markise']['Kontakt'] ?? -1) === 0);
check('I12 Fassade bleibt leer (Angabe des Nutzers)', array_unique(array_column($G, 'Fassade')) === ['']);
check('I13 schon zugeordnete Geräte nicht doppelt', count(VSCHErkennung::geraete($IN, [101, 161])) === 5);
check('I14 Türschloss ist kein Fensterkontakt', !in_array(281, array_column(VSCHErkennung::kontakte($IN), 0), true));
$S = VSCHErkennung::sensoren($IN, []);
check('I15 Wind: aktuelle Böe, nicht Tagesmaximum/Prognose', $S['WindID'] === 301, (string)$S['WindID']);
check('I16 Regen: Wetterstation vor Mähroboter, nie Tagesmenge', $S['RegenID'] === 305, (string)$S['RegenID']);
check('I16c Regen ohne Station: Regenmelder des Geräts', VSCHErkennung::sensoren([360 => $IN[360]], [])['RegenID'] === 361);
check('I16d Wind: „Geschwindigkeit“ ist kein Wind', VSCHErkennung::sensoren([380 => $IN[380]], [])['WindID'] === 0);
check('I16b Wind: „Börse“ ist keine „Böe“', VSCHErkennung::sensoren([350 => $IN[350]], [])['WindID'] === 0);
check('I17 Temperatur: Außentemperatur, nicht gefühlt/innen/Auto', $S['TemperaturID'] === 307, (string)$S['TemperaturID']);
check('I18 Sonne: Einstrahlung der Wetterstation (W/m², auch mit Lux-Profil), nie Innensensor', $S['HelligkeitID'] === 311, (string)$S['HelligkeitID']);
check('I18g 15-Minuten-Mittelwert nicht neben dem Rohwert (Live-Befund 24.11.)', VSCHErkennung::sensoren([300 => ['name' => 'Wetterstation', 'ort' => '', 'modul' => '', 'vars' => [[1, 'Solar Einstrahlung (MW 15 Minuten)', '', 2, '~Illumination', false], [2, 'Solar Einstrahlung', 'solarradiation', 2, '~Illumination', false]]]], [])['HelligkeitID'] === 2);
check('I18h Z-Wave „Sensor Multilevel (Beleuchtung)“ ist Raumhelligkeit', VSCHErkennung::raumSensorFuer('Jalousie Kind I', VSCHErkennung::raumSensoren([5 => ['name' => 'Sensor Kind I', 'ort' => 'Sensoren', 'modul' => '', 'vars' => [[51, 'Sensor Multilevel (Beleuchtung)', 'SensorMultilevel03Variable', 2, 'Illumination', false]]]]), 'hell') === 51);
check('I18b Lux außen, wenn keine Einstrahlung da ist', VSCHErkennung::sensoren([390 => ['name' => 'Wetter', 'ort' => '', 'modul' => '', 'vars' => [[391, 'Helligkeit außen', '', 2, '~Illumination', false]]], 330 => $IN[330]], [])['HelligkeitID'] === 391);
$RS = VSCHErkennung::raumSensoren([
    1 => ['name' => 'Thermostat Kind I', 'ort' => '', 'modul' => '', 'vars' => [[11, 'Temperatur', 'temp', 2, '~Temperature.Room', false], [12, 'Solltemperatur', 'setpoint', 2, '~Temperature.Room', false]]],
    2 => ['name' => 'Sensor Kind II', 'ort' => '', 'modul' => '', 'vars' => [[21, 'Temperatur', '', 2, '~Temperature', false], [22, 'Helligkeit', '', 2, '~Illumination', false]]],
    3 => ['name' => 'Jalousie Kind I', 'ort' => '', 'modul' => '', 'vars' => [[31, 'Position', '', 1, '~Shutter', true], [32, 'Gerätetemperatur', '', 2, '~Temperature', false]]],
    4 => ['name' => 'Wetterstation', 'ort' => '', 'modul' => '', 'vars' => [[41, 'Außentemperatur', '', 2, '~Temperature', false]]],
    5 => ['name' => 'Sensor Küche', 'ort' => '', 'modul' => '', 'vars' => [[51, 'Temperatur', '', 2, '~Temperature', false], [52, 'Temperatur', '', 2, '', false, '', '', VSCHErkennung::V_RAUMTEMP[0]]]],
]);
check('I18c Raumtemperatur: Raum und Nummer passen, Sollwert/Gerätetemperatur/Station nie', VSCHErkennung::raumSensorFuer('Jalousie Kind I', $RS, 'temp') === 11
    && VSCHErkennung::raumSensorFuer('Jalousie Kind II', $RS, 'temp') === 21 && !in_array(12, array_column($RS, 0), true)
    && !in_array(32, array_column($RS, 0), true) && !in_array(41, array_column($RS, 0), true), json_encode($RS));
check('I18d Raumhelligkeit nur im selben Raum; Behang ohne Nummer nimmt keinen „Kind II“-Sensor', VSCHErkennung::raumSensorFuer('Jalousie Kind II', $RS, 'hell') === 22
    && VSCHErkennung::raumSensorFuer('Jalousie Kind I', $RS, 'hell') === 0 && VSCHErkennung::raumSensorFuer('Rollladen Kinderzimmer', $RS, 'hell') === 0);
check('I18e Vorlage Raumtemperatur geht vor', VSCHErkennung::raumSensorFuer('Rollladen Küche', $RS, 'temp') === 52);
check('I18f Gerätezeile bekommt Raumsensoren', ($G['Jalousie Wohnen Rechts']['RaumHell'] ?? -1) === 331, json_encode($G['Jalousie Wohnen Rechts'] ?? null));
$S = VSCHErkennung::sensoren($IN, ['instanz' => 300, 'vars' => []]);
check('I19 WarnHub wertet die Station aus: Wind/Regen gar nicht zuordnen, auch nicht aus anderen Geräten', $S['WindID'] === 0 && $S['RegenID'] === 0 && ($S['hinweise']['WindID'] ?? '') === 'warnhub' && ($S['hinweise']['RegenID'] ?? '') === 'warnhub' && $S['TemperaturID'] === 307);
$IN2 = $IN + [400 => ['name' => 'Zweite Station', 'ort' => 'Wetter', 'modul' => '', 'vars' => [[401, 'Außentemperatur', 'temp', 2, '~Temperature', false]]]];
$S = VSCHErkennung::sensoren($IN2, []);
check('I20 zwei gleich gute Kandidaten: leer lassen und Hinweis', $S['TemperaturID'] === 0 && ($S['hinweise']['TemperaturID'] ?? '') === 'mehrdeutig');
check('I21 Kontaktrichtung: Matter BooleanState = true heißt zu', VSCHErkennung::kontaktUmgekehrt('', 'Status E1_BooleanState_State'));
check('I22 Kontaktrichtung: ~Window true = offen', !VSCHErkennung::kontaktUmgekehrt('~Window', 'Status STATE'));
check('I23 Kontaktrichtung: ~Window.Reversed umgekehrt', VSCHErkennung::kontaktUmgekehrt('~Window.Reversed', 'x'));
check('I24 Kontaktrichtung: Zigbee2MQTT contact = true heißt zu', VSCHErkennung::kontaktUmgekehrt('', 'Kontakt contact'));
check('I25 Kontaktrichtung: Homematic STATE ohne Profil = offen', !VSCHErkennung::kontaktUmgekehrt('', 'STATE STATE'));

// Andere Systeme und Werkseinstellungen (nicht Dietmars Anlage): Erkennung darf nicht an Namen hängen
$X = [
    500 => ['name' => 'Wohnzimmer links', 'ort' => 'Wohnzimmer', 'modul' => $zw, 'modulName' => 'Z-Wave Module', 'vars' => [
        [501, 'Intensität', 'IntensityVariable', 1, '~Intensity.100', true], [502, 'Status', 'StatusVariable', 0, '~Switch', true], [503, 'Bewegung', 'ShutterMoveVariable', 1, '~ShutterMoveStop', true]]],
    510 => ['name' => 'Küche', 'ort' => 'EG', 'modul' => '{Z2M}', 'modulName' => 'Zigbee2MQTT Device', 'vars' => [
        [511, 'Position', 'position', 1, '', true, '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}'], [512, 'Neigung', 'tilt', 1, '', true], [513, 'Zustand', 'state', 3, '', true]]],
    520 => ['name' => 'Büro', 'ort' => 'OG', 'modul' => '{HMIP}', 'modulName' => 'HomeMatic Device', 'vars' => [
        [521, 'LEVEL', 'LEVEL', 2, '~Intensity.1', true], [522, 'SLATS_LEVEL', 'SLATS_LEVEL', 2, '~Intensity.1', true], [523, 'ACTIVITY_STATE', 'ACTIVITY_STATE', 1, '', false]]],
    530 => ['name' => 'Licht Flur', 'ort' => 'Flur', 'modul' => $zw, 'modulName' => 'Z-Wave Module', 'vars' => [
        [531, 'Intensität', 'IntensityVariable', 1, '~Intensity.100', true], [532, 'Status', 'StatusVariable', 0, '~Switch', true]]],
    540 => ['name' => 'Gaube', 'ort' => 'DG', 'modul' => '{NEU}', 'modulName' => 'Irgendein Modul', 'vars' => [
        [541, 'Höhe', 'height', 1, '', true, VSCHErkennung::SHUTTER_DARSTELLUNG]]],
    550 => ['name' => 'Beschattung Süd', 'ort' => '', 'modul' => '{BC}', 'modulName' => 'Blind Control', 'vars' => [
        [551, 'Behang', 'BlindLevel', 1, '~Shutter', true]]],
    560 => ['name' => 'Rollladen Bad', 'ort' => 'OG', 'modul' => '{KNX}', 'modulName' => 'EIS Value', 'vars' => [[561, 'Wert', 'Value', 1, '~Intensity.100', true]]],
];
$X += [
    600 => ['name' => 'Shelly ab Werk', 'ort' => 'OG', 'modul' => $sh, 'modulName' => 'Gen3Shelly2PM', 'vars' => [
        [601, 'Lamellenposition', 'SlatPosition', 1, '~Shutter', true], [602, 'Position', 'CoverPosition', 1, '~Shutter', true], [603, 'Status', 'State', 1, '~ShutterMoveStop', true]]],
    610 => ['name' => 'Neues Modul', 'ort' => 'EG', 'modul' => '{X}', 'modulName' => 'X', 'vars' => [
        [611, 'Winkel A', 'a', 1, '', true, VSCHErkennung::SHUTTER_DARSTELLUNG, '', '', ['usage' => 1, 'open' => 0, 'close' => 100]],
        [612, 'Höhe B', 'b', 1, '', true, VSCHErkennung::SHUTTER_DARSTELLUNG, '', '', ['usage' => 0, 'open' => 100, 'close' => 0]]]],
    620 => ['name' => 'Gerät 7', 'ort' => '', 'modul' => '{Y}', 'modulName' => 'Y', 'vars' => [
        [621, 'Wert', 'v', 1, '', true, VSCHErkennung::SHUTTER_DARSTELLUNG, '', VSCHErkennung::V_ROLLLADEN_INVERTIERT]]],
    630 => ['name' => 'Sensor 4', 'ort' => '', 'modul' => '{Z}', 'modulName' => 'Z', 'vars' => [[631, 'State', 'st', 0, '', false, '{3319437D-7CDE-699D-750A-3C6A3841FA75}', 'Geöffnet', VSCHErkennung::V_FENSTER]]],
];
$GX = array_column(VSCHErkennung::geraete($X, []), null, 'Position');
check('I26 Z-Wave ab Werk (~Intensity.100) mit Auf/Ab/Stopp: erkannt ohne Namenshinweis', isset($GX[501]) && $GX[501]['Typ'] === 'rollladen', json_encode(array_keys($GX)));
check('I27 Zigbee2MQTT-Cover (position + tilt, ohne Profil): Raffstore mit Neigung', isset($GX[511]) && $GX[511]['Typ'] === 'raffstore' && $GX[511]['Lamelle'] === 512);
check('I28 Homematic IP (LEVEL + SLATS_LEVEL): Raffstore', isset($GX[521]) && $GX[521]['Typ'] === 'raffstore' && $GX[521]['Lamelle'] === 522);
check('I29 Dimmer mit gleichem Aufbau wird NICHT erkannt', !isset($GX[531]));
check('I30 Darstellung „Shutter“ ohne Profil: erkannt', isset($GX[541]));
check('I31 Steuermodul (Blind Control) ist kein Gerät', !isset($GX[551]));
check('I32 KNX-Wert, Name sagt Rollladen: erkannt', isset($GX[561]) && $GX[561]['Typ'] === 'rollladen');
check('I35 Shelly ab Werk: Lamelle mit ~Shutter wird nicht zur Position', isset($GX[602]) && $GX[602]['Lamelle'] === 601 && !isset($GX[601]), json_encode($GX[602] ?? $GX[601] ?? null));
check('I36 Rollladen-Darstellung: Verwendungstyp Öffnung = Position, Rotation = Lamelle (ohne Namen)', isset($GX[612]) && $GX[612]['Lamelle'] === 611 && $GX[612]['Typ'] === 'raffstore');
check('I37 Vorlage „Rollladen (Invertiert)“ erkennt ohne jeden Namenshinweis', isset($GX[621]));
check('I38 Vorlage „Fenster“ macht Kontakt auch ohne Fenster im Namen', in_array(631, array_column(VSCHErkennung::kontakte($X), 0), true));
$SV = VSCHErkennung::sensoren([
    700 => ['name' => 'Garten', 'ort' => '', 'modul' => '', 'vars' => [
        [701, 'T1', 't1', 2, '', false, '', '', VSCHErkennung::V_AUSSENTEMP],
        [702, 'Außentemperatur', 't2', 2, '~Temperature', false, '', '', VSCHErkennung::V_RAUMTEMP[0]],
        [703, 'L', 'l', 2, '', false, '', '', VSCHErkennung::V_SOLAR],
        [704, 'R', 'r', 0, '', false, '', '', VSCHErkennung::V_REGEN],
        [705, 'W', 'w', 2, '', false, '', '', VSCHErkennung::V_WIND[1]]]]], []);
check('I39 Vorlagen: Außentemperatur/Regen/Wind/Solarstrahlung sicher, Raumtemperatur-Vorlage nie außen', $SV['TemperaturID'] === 701 && $SV['RegenID'] === 704 && $SV['WindID'] === 705 && $SV['HelligkeitID'] === 703, json_encode($SV));
$SS = VSCHErkennung::sensoren([
    800 => ['name' => 'KNX Wetterstation', 'ort' => 'Wetter', 'modul' => '{KNX}', 'vars' => [[801, 'Windalarm', 'Value', 0, '~Alert', false], [802, 'Regen', 'Value', 0, '~Raining', false]]],
    810 => ['name' => 'WarnHub', 'ort' => '', 'modul' => VSCHErkennung::WARNHUB_GUID, 'vars' => [[811, 'Sturmwarnung aktiv', 'storm', 0, '~Alert', false], [812, 'Wind', 'w', 2, '~WindSpeed.kmh', false]]]], []);
check('I40 Sperre: KNX-Windalarm wird als externe Sperre erkannt', $SS['SperreID'] === 801 && $SS['RegenID'] === 802, json_encode($SS));
check('I41 Variablen von WarnHub selbst werden nie als Sensor genommen', $SS['WindID'] === 0);
// Ausrichtung aus dem Objektbaum
check('I42 Richtung aus Ordnername („Südseite“, „Fassade West“, „Ostflügel“)', VSCHErkennung::richtungAusText('Südseite / EG / Zuhause') === 180.0
    && VSCHErkennung::richtungAusText('Fassade West') === 270.0 && VSCHErkennung::richtungAusText('Ostflügel') === 90.0 && VSCHErkennung::richtungAusText('Nordwest') === 315.0);
check('I43 Richtung aus Info-Feld, auch als Gradzahl', VSCHErkennung::richtungAusText('Ausrichtung: Süd') === 180.0 && VSCHErkennung::richtungAusText('Ausrichtung: 166°') === 166.0
    && VSCHErkennung::richtungAusText('Orientation 250') === 250.0);
check('I44 keine Richtung in „Kost“, „Wohnen“, „Jalousien“', VSCHErkennung::richtungAusText('Kostbar Wohnen Jalousien') === null);
check('I44b Gradzahl ohne „Ausrichtung“ zählt nicht („Anzeige um 180° drehen“), allein schon („166°“)', VSCHErkennung::richtungAusText('Anzeige um 180° drehen') === null && VSCHErkennung::richtungAusText('166°') === 166.0);
check('I45 Fassade zum Azimut, höchstens 45° daneben', VSCHErkennung::fassadeZuAzimut(180.0, [['Kennung' => 'A', 'Azimut' => 76], ['Kennung' => 'B', 'Azimut' => 166]]) === 'B'
    && VSCHErkennung::fassadeZuAzimut(0.0, [['Kennung' => 'B', 'Azimut' => 166]]) === '');
$K = ['name' => 'Jalousie Wohnen', 'ort' => 'Jalousien', 'modul' => $zw, 'kontext' => ['Südseite / Zuhause'], 'vars' => [[901, 'Position', 'IntensityVariable', 1, '~Shutter', true]]];
check('I46 Behang in Ordner „Südseite“: Hinweis 180°', (VSCHErkennung::geraete([900 => $K], [])[0]['AzHinweis'] ?? null) === 180.0);
check('I33 Kontaktrichtung aus Darstellung „Geschlossen“', VSCHErkennung::kontaktUmgekehrt('', 'Status STATE', 'Geschlossen'));
check('I34 Kontaktrichtung aus Darstellung „Open“', !VSCHErkennung::kontaktUmgekehrt('~Window.Reversed', 'x', 'Open'));

// ---------------------------------------------------------------------------
heading('J Einrichtungsassistent (Symcon 9.1, mehrseitiges PopupAlert)');
$form = json_decode($m->GetConfigurationForm(), true);
$wiz = null;
foreach ($form['actions'] as $a) {
    if (($a['name'] ?? '') === 'Assistent') {
        $wiz = $a;
    }
}
$seiten = $wiz['popup']['pages'] ?? [];
check('J1 PopupAlert „Assistent“ mit 8 Seiten in fester Reihenfolge', $wiz !== null && $wiz['type'] === 'PopupAlert'
    && array_column($seiten, 'name') === ['ausrichtung', 'standort', 'haus', 'geraete', 'probe', 'wetter', 'wann', 'fertig'], json_encode(array_column($seiten, 'name')));
check('J2 öffnet sich bei eingerichteter Instanz nicht von selbst', ($wiz['visible'] ?? true) === false);
$m3 = new VerschattungTest(33333);
$m3->Create();
$m3->ApplyChanges();
$w3 = null;
foreach (json_decode($m3->GetConfigurationForm(), true)['actions'] as $a) {
    if (($a['name'] ?? '') === 'Assistent') {
        $w3 = $a;
    }
}
check('J3 öffnet sich bei neuer Instanz von selbst', ($w3['visible'] ?? false) === true);
// Felder und Skripte
$felder = [];
$skripte = [];
$sammle = function ($x) use (&$sammle, &$felder, &$skripte) {
    if (!is_array($x)) {
        return;
    }
    if (isset($x['name'], $x['type'])) {
        $felder[] = $x['name'];
    }
    foreach (['onClick', 'validate', 'onConfirm', 'onUndo', 'nextPage', 'onChange'] as $k) {
        if (isset($x[$k]) && is_string($x[$k])) {
            $skripte[] = $x[$k];
        }
    }
    foreach ($x as $v) {
        $sammle($v);
    }
};
$sammle($seiten);
$wFelder = array_values(array_filter($felder, fn($n) => $n[0] === 'W'));
check('J4 Feldnamen über alle Seiten eindeutig', count($wFelder) === count(array_unique($wFelder)), json_encode(array_diff_assoc($wFelder, array_unique($wFelder))));
$fehler = [];
foreach ($skripte as $sk) {
    try {
        token_get_all('<?php ' . $sk, TOKEN_PARSE);
    } catch (ParseError $e) {
        $fehler[] = $e->getMessage() . ' in ' . substr($sk, 0, 60);
    }
}
check('J5 alle Seiten-Skripte sind gültiges PHP', $skripte !== [] && $fehler === [], implode(' | ', $fehler));
check('J6 kein Seiten-Skript nutzt $this', array_filter($skripte, fn($sk) => strpos($sk, '$this') !== false) === []);
preg_match_all('/\$(W[A-Za-z]+)(?![A-Za-z])/', implode("\n", $skripte), $mm);
$benutzt = array_unique(array_map(fn($v) => preg_replace('/A$/', '', $v), $mm[1]));
$unbekannt = array_values(array_diff($benutzt, $wFelder));
check('J7 jede $W…-Variable in Skripten ist ein Feld des Assistenten', $unbekannt === [], implode(', ', $unbekannt));
$echoVerbot = array_filter($seiten, fn($p) => strpos((string)($p['validate'] ?? '') . (is_string($p['nextPage'] ?? null) ? $p['nextPage'] : ''), 'echo') !== false);
check('J8 validate/nextPage ohne echo (Symcon wertet die Rückgabe sonst nicht aus)', $echoVerbot === []);
foreach ($seiten as $p) {
    if (isset($p['onConfirm']) && $p['name'] !== 'fertig') {
        check("J9 Seite {$p['name']}: onConfirm hat onUndo (sonst kein „Zurück“)", isset($p['onUndo']) && trim((string)$p['onUndo']) !== '');
    }
}
// Prüfen
$fx = [['Kennung' => 'A', 'Name' => 'Süd', 'Azimut' => 180, 'Aktiv' => true], ['Kennung' => 'B', 'Name' => 'West', 'Azimut' => 270, 'Aktiv' => true]];
check('J10 Geräte-Seite blockiert ohne Fassade und nennt das Gerät', strpos($m->UIAssistentPruefen('geraete', json_encode(['fassaden' => $fx, 'geraete' => [['Name' => 'Küche', 'Fassade' => '']]])), 'Küche') !== false);
check('J11 „nicht verschatten“ und gültige Fassade gehen durch', $m->UIAssistentPruefen('geraete', json_encode(['fassaden' => $fx, 'geraete' => [['Name' => 'X', 'Fassade' => '-'], ['Name' => 'Y', 'Fassade' => 'B']]])) === '');
check('J12 Fassade, die es nicht gibt, blockiert', $m->UIAssistentPruefen('geraete', json_encode(['fassaden' => $fx, 'geraete' => [['Name' => 'Z', 'Fassade' => 'F']]])) !== '');
check('J13 Haus-Seite ohne Fassade blockiert', $m->UIAssistentPruefen('haus', '[]') !== '' && $m->UIAssistentPruefen('haus', json_encode($fx)) === '');
check('J14 Schritt 7: Sensoren sind freiwillig, nichts blockiert', $m->UIAssistentPruefen('wann', json_encode(['hell' => 0, 'temp' => 0])) === '');
check('J15 Standort „woanders“ ohne Kartenpunkt blockiert', $m->UIAssistentPruefen('standort', json_encode(['art' => 1, 'standort' => '{"latitude":0,"longitude":0}'])) !== ''
    && $m->UIAssistentPruefen('standort', json_encode(['art' => 1, 'standort' => '{"latitude":48.1,"longitude":7.9}'])) === '');
check('J16 nur Rollläden: Wetterschutz-Seite wird übersprungen', $m->UIAssistentWeiter(json_encode([['Typ' => 'rollladen', 'Fassade' => 'A']])) === 'wann'
    && $m->UIAssistentWeiter(json_encode([['Typ' => 'markise', 'Fassade' => 'A']])) === 'wetter'
    && $m->UIAssistentWeiter(json_encode([['Typ' => 'markise', 'Fassade' => '-']])) === 'wann');
check('J17 Fassade aus dem Gerätenamen („Rollladen Süd“)', Verschattung::fassadeAusName('Rollladen Süd', $fx) === 'A' && Verschattung::fassadeAusName('Jalousie Wohnen', $fx) === '');
// Geräte vorbefüllen; Ausrichtung aus Link-Ordner
mkinst(990, 'Südseite', 0); $GLOBALS['OBJ'][990]['kind'] = 0;
$GLOBALS['OBJ'][991] = ['kind' => 6, 'name' => 'Kind I', 'parent' => 990, 'ident' => '', 'target' => 950];
// Geräte vorbefüllen
$m->fieldUpdates = [];
$m->UIAssistentGeraete(json_encode($fx), '[]', 0, '');
$f = array_column($m->fieldUpdates, 2, 0);
$wg = json_decode($f['WGeraete'] ?? '[]', true);
check('J18 Seite 3 wird mit erkannten Geräten gefüllt', in_array(951, array_column($wg, 'Position'), true) && isset($f['WLegende']) && strpos($f['WLegende'], 'A = Süd') === 0, json_encode(array_column($wg, 'Position')));
$kind = array_values(array_filter($wg, fn($g) => $g['Position'] === 951));
check('J31 Link im Ordner „Südseite“ → Fassade A (Süd) vorbelegt', ($kind[0]['Fassade'] ?? '') === 'A', json_encode($kind));
// Übernehmen (Entwurf wie im echten Ablauf: Seite 2/3 haben ihn gemerkt)
$m->UIAssistentOeffnen();
$m->UIAssistentMerken('fassaden', json_encode($fx));
$m->UIAssistentMerken('geraete', json_encode([['Name' => 'X', 'Fassade' => 'A', 'Typ' => 'raffstore', 'Position' => 601], ['Name' => 'Y', 'Fassade' => '-', 'Position' => 602]]));
$m->fieldUpdates = [];
$m->UIAssistentZusammenfassung('', '', '{}');
$f = array_column($m->fieldUpdates, 2, 0);
check('J25 Zusammenfassung nennt die Geräte je Fassade (Live-Befund 23.11.)', strpos($f['WZusammenfassung'] ?? '', 'A Süd: X') !== false, $f['WZusammenfassung'] ?? '');
$m->fieldUpdates = [];
$msg = $m->UIAssistentUebernehmen(json_encode(['art' => 0, 'standort' => '', 'fassaden' => $fx, 'geraete' => [['Name' => 'X', 'Fassade' => 'A', 'Typ' => 'raffstore', 'Position' => 601], ['Name' => 'Y', 'Fassade' => '-', 'Position' => 602]],
    'warnhub' => true, 'wind' => 0, 'regen' => 0, 'netz' => false, 'ohne' => false, 'hell' => 123, 'sonneAn' => 300, 'temp' => 0, 'aussenSommer' => 20, 'innenGrenze' => 25, 'raumHellLux' => 300]));
$f = array_column($m->fieldUpdates, 2, 0);
check('J19 Übernehmen füllt nur das Formular (keine Eigenschaft geändert)', isset($f['Fassaden'], $f['Geraete']) && count(json_decode($m->props['Geraete'], true)) === 2);
check('J20 „nicht verschatten“ landet nicht in der Geräteliste', array_column(json_decode($f['Geraete'], true), 'Name') === ['X']);
check('J21 Schritt 7: Sensor und Schieberegler landen im Formular, Ende bei 60 %', ($f['HelligkeitID'] ?? -1) === 123 && ($f['TemperaturID'] ?? -1) === 0
    && ($f['SonneAn'] ?? 0) == 300 && ($f['SonneAus'] ?? 0) == 180 && ($f['AussenSommer'] ?? 0) == 20 && ($f['InnenGrenze'] ?? 0) == 25 && ($f['RaumHellLux'] ?? 0) == 300, json_encode($f));
check('J22 Standort-Instanz gewählt: eigener Standort wird geleert', json_decode($f['Standort'], true) === ['latitude' => 0, 'longitude' => 0]);
check('J23 Meldung sagt „Änderungen übernehmen“', strpos($msg, 'Änderungen übernehmen') !== false, $msg);
// Probefahrt mit Assistenten-Daten
$GLOBALS['BEFEHLE'] = [];
$m->UIAssistentMerken('geraete', json_encode([['Name' => 'Kind', 'Fassade' => 'B', 'Typ' => 'raffstore', 'Position' => 951, 'Lamelle' => 952]]));
$m->fieldUpdates = [];
$m->UIAssistentProbe(json_encode([['Name' => 'Kind', 'Fassade' => 'B', 'Typ' => 'raffstore', 'Position' => 951, 'Lamelle' => 952]]), 'B', true);
check('J24 Probefahrt fährt Gerät aus dem Assistenten (ungespeichert)', in_array(951, array_column($GLOBALS['BEFEHLE'], 0), true), json_encode($GLOBALS['BEFEHLE']));
$m->fieldUpdates = [];
$m->UIAssistentAlle(json_encode([['Name' => 'X', 'Fassade' => ''], ['Name' => 'Y', 'Fassade' => 'A']]), 'B');
$f = array_column($m->fieldUpdates, 2, 0);
check('J30 „Für alle übernehmen“ setzt alle Geräte auf die Fassade', array_column(json_decode($f['WGeraete'] ?? '[]', true), 'Fassade') === ['B', 'B']);
// Schritt 1: Ausrichtung ankreuzen
check('J32 Kreuze → Azimut: eine Richtung, Ecke, Unsinn', Verschattung::richtungAusKreuzen(['S']) === 180.0 && Verschattung::richtungAusKreuzen(['S', 'W']) === 225.0
    && Verschattung::richtungAusKreuzen(['W', 'N']) === 315.0 && Verschattung::richtungAusKreuzen(['N', 'S']) === null && Verschattung::richtungAusKreuzen(['N', 'O', 'S']) === null);
check('J33 Prüfung: gegenüberliegende Kreuze blockieren mit Gerätename', strpos($m->UIAssistentPruefen('ausrichtung', json_encode([['Name' => 'Küche', 'RN' => true, 'RS' => true]])), 'Küche') !== false);
check('J34 Prüfung: kein einziges Kreuz blockiert, ein Kreuz geht durch', $m->UIAssistentPruefen('ausrichtung', json_encode([['Name' => 'A']])) !== ''
    && $m->UIAssistentPruefen('ausrichtung', json_encode([['Name' => 'A', 'RS' => true], ['Name' => 'B']])) === '');
$m->fieldUpdates = [];
$m->UIAssistentAlleRichtung(json_encode([['Name' => 'A', 'RN' => true], ['Name' => 'B']]), 'S');
$f = array_column($m->fieldUpdates, 2, 0);
check('J35 „Alle ankreuzen“ setzt genau Süd bei allen', array_map(fn($z) => [$z['RN'], $z['RS']], json_decode($f['WAusrichtung'] ?? '[]', true)) === [[false, true], [false, true]]);
// Kreuze → Fassade nach Hauserkennung, kein Kreuz = nicht verschatten
$m->UIAssistentOeffnen();
$m->UIAssistentMerken('ausrichtung', json_encode([['Name' => 'Süd1', 'Position' => 951, 'Typ' => 'raffstore', 'RS' => true], ['Name' => 'Ohne', 'Position' => 952, 'Typ' => 'rollladen']]));
$m->fieldUpdates = [];
$m->UIAssistentGeraete(json_encode([['Kennung' => 'A', 'Name' => 'Ost', 'Azimut' => 76], ['Kennung' => 'B', 'Name' => 'Süd', 'Azimut' => 166]]), '[]', 0, '');
$f = array_column($m->fieldUpdates, 2, 0);
check('J36 Süd angekreuzt → Fassade B (166°), ohne Kreuz → nicht verschatten', array_column(json_decode($f['WGeraete'] ?? '[]', true), 'Fassade', 'Name') === ['Süd1' => 'B', 'Ohne' => '-'], $f['WGeraete'] ?? '');
check('J40 Probefahrt wählt die Fassade mit den meisten Geräten vor', ($f['WProbeFassade'] ?? '') === 'B');
check('J41 Probefahrt bietet nur Fassaden mit Geräten an, mit Namen', json_decode($f['WProbeFassade'] ?? 'null', true) !== null || (function () use ($m) {
    foreach ($m->fieldUpdates as $u) { if ($u[0] === 'WProbeFassade' && $u[1] === 'options') { return json_decode($u[2], true) === [['caption' => 'B · Süd', 'value' => 'B']]; } } return false; })());
// Fassaden ohne Gerät werden beim Übernehmen inaktiv (bleiben in der Liste)
$vier = [['Kennung' => 'A', 'Name' => 'Ost', 'Azimut' => 76, 'Aktiv' => true], ['Kennung' => 'B', 'Name' => 'Süd', 'Azimut' => 166, 'Aktiv' => true], ['Kennung' => 'C', 'Name' => 'West', 'Azimut' => 256, 'Aktiv' => true]];
check('J42 nur genutzte Fassaden aktiv', array_column(Verschattung::nurGenutzteAktiv($vier, [['Fassade' => 'B']]), 'Aktiv', 'Kennung') === ['A' => false, 'B' => true, 'C' => false]);
check('J43 ohne jede Zuordnung bleibt alles aktiv', array_column(Verschattung::nurGenutzteAktiv($vier, [['Fassade' => '']]), 'Aktiv') === [true, true, true]);
$m->UIAssistentMerken('fassaden', json_encode($vier));
$m->UIAssistentMerken('geraete', json_encode([['Name' => 'J1', 'Fassade' => 'B', 'Position' => 951]]));
$m->fieldUpdates = [];
$m->UIAssistentUebernehmen(json_encode(['art' => 0]));
$f = array_column($m->fieldUpdates, 2, 0);
check('J44 Übernehmen: Fassadenliste mit A/C inaktiv, B aktiv', array_column(json_decode($f['Fassaden'] ?? '[]', true), 'Aktiv', 'Kennung') === ['A' => false, 'B' => true, 'C' => false]);
$m->fieldUpdates = [];
$m->UIAssistentZusammenfassung('', '', '{}');
$f = array_column($m->fieldUpdates, 2, 0);
check('J45 Zusammenfassung nennt nur Sonnenzeiten genutzter Fassaden', strpos($f['WZusammenfassung'] ?? '', 'B Süd:') !== false && strpos($f['WZusammenfassung'] ?? '', 'A Ost:') === false, $f['WZusammenfassung'] ?? '');
// Info-Feld nur mit Häkchen
$GLOBALS['OBJ'][950]['info'] = 'Bitte nicht anfassen';
$m->UIAssistentUebernehmen(json_encode(['art' => 0, 'info' => false]));
check('J37 ohne Häkchen bleibt das Info-Feld unangetastet', $GLOBALS['OBJ'][950]['info'] === 'Bitte nicht anfassen');
$msg = $m->UIAssistentUebernehmen(json_encode(['art' => 0, 'info' => true]));
check('J38 mit Häkchen: „Ausrichtung: Süd“ angehängt, alter Text bleibt', $GLOBALS['OBJ'][950]['info'] === "Bitte nicht anfassen\nAusrichtung: Süd" && strpos($msg, 'Info-Feld von 1') !== false, $GLOBALS['OBJ'][950]['info'] . ' | ' . $msg);
$m->UIAssistentUebernehmen(json_encode(['art' => 0, 'info' => true]));
check('J39 zweimal übernehmen ersetzt die Zeile statt sie zu verdoppeln', substr_count($GLOBALS['OBJ'][950]['info'], 'Ausrichtung:') === 1);
$m->UIAssistentOeffnen();
check('J26 erneutes Öffnen verwirft den alten Entwurf', $m->UIAssistentWeiter(json_encode([['Typ' => 'rollladen', 'Fassade' => 'A']])) === 'wann');
$ff = json_decode($m->GetConfigurationForm(), true);
$optG = [];
$sm = function ($x) use (&$sm, &$optG) { if (!is_array($x)) { return; } if (($x['name'] ?? '') === 'Geraete') { foreach ($x['columns'] as $c) { if ($c['name'] === 'Fassade') { $optG = array_column($c['edit']['options'], 'value'); } } } foreach ($x as $v) { $sm($v); } };
$sm($ff['elements']);
check('J27 Formular-Geräteliste kennt immer A–L (Live-Befund: „B“ ging beim Speichern verloren)', in_array('B', $optG, true) && in_array('L', $optG, true), json_encode($optG));
$pg = array_column($seiten, null, 'name');
check('J28 Geräteseite merkt beim Weiter den Entwurf (aktuelle Seite = Listenänderungen sind da)', strpos((string)($pg['geraete']['onConfirm'] ?? ''), 'VSCH_UIAssistentMerken') !== false);
check('J29 spätere Seiten lesen nicht mehr $WGeraete', strpos(json_encode([$pg['probe'], $pg['wann'], $pg['fertig']]), 'WGeraete') === false);

// WarnHub wertet die Wetterstation aus: klar sagen, überflüssige Felder ausblenden, nicht doppelt auswerten (Dietmar 24.11.)
mkinst(905, 'Wetterstation', 0);
$GLOBALS['CONF_PROP'][900]['WetterstationInstanceID'] = 905;
$sicht = function (array $form, string $name) { $v = null; $w = function ($x) use (&$w, &$v, $name) { if (!is_array($x)) { return; } if (($x['name'] ?? null) === $name) { $v = $x; return; } foreach ($x as $y) { $w($y); } }; $w($form); return $v; };
$ff = json_decode($m->GetConfigurationForm(), true);
check('J46 WarnHub deckt die Station: Hinweis sichtbar, nennt die Wetterstation', ($sicht($ff, 'WarnHubDeckt')['visible'] ?? false) === true && strpos($sicht($ff, 'WarnHubDeckt')['caption'] ?? '', 'Wetterstation') !== false);
check('J47 Formular: Wind/Regen/Böenvorhersage/Markise-ohne ausgeblendet, Sperre und Haltezeit sichtbar',
    ($sicht($ff, 'WindID')['visible'] ?? true) === false && ($sicht($ff, 'RegenID')['visible'] ?? true) === false && ($sicht($ff, 'WindSchwellen')['visible'] ?? true) === false
    && ($sicht($ff, 'NetzWetter')['visible'] ?? true) === false && ($sicht($ff, 'SperreID')['visible'] ?? true) !== false && ($sicht($ff, 'Haltezeit')['visible'] ?? true) !== false);
check('J48 Assistent: Wetterseite blendet die Felder aus und sagt „kommt von WarnHub“', ($sicht($ff, 'WWind')['visible'] ?? true) === false && ($sicht($ff, 'WWarnHubDeckt')['visible'] ?? false) === true);
$m->fieldUpdates = [];
$m->UIWetterSicht(false, false);
$u = []; foreach ($m->fieldUpdates as $x) { if ($x[1] === 'visible') { $u[$x[0]] = $x[2]; } }
check('J49 „WarnHub nutzen“ aus: Felder wieder sichtbar, Hinweis weg', ($u['WindID'] ?? null) === true && ($u['WarnHubDeckt'] ?? null) === false);
unset($GLOBALS['CONF_PROP'][900]['WetterstationInstanceID']);
$ff = json_decode($m->GetConfigurationForm(), true);
check('J50 ohne Station in WarnHub: Felder sichtbar', ($sicht($ff, 'WindID')['visible'] ?? true) !== false && ($sicht($ff, 'WarnHubDeckt')['visible'] ?? true) === false);

// ---------------------------------------------------------------------------
heading('H Übersetzung und Formularregeln');
$code = (string)file_get_contents($root . '/Verschattung/module.php');
check('H1 kein IPS_SetProperty/IPS_ApplyChanges', !preg_match('/IPS_SetProperty|IPS_ApplyChanges/', $code));
check('H2 kein IPS_LogMessage', strpos($code, 'IPS_LogMessage') === false);
check('H3 kein Dateizugriff im Modul', !preg_match('/file_get_contents|fopen|file_put_contents/', $code));
$texte = [];
foreach (glob($root . '/Verschattung/*.php') as $datei) {
    $c = (string)file_get_contents($datei);
    preg_match_all("/(?:->t|VSCHText::t|self::fest)\\('((?:[^'\\\\]|\\\\.)*)'/", $c, $mm);
    preg_match_all("/'(?:caption|label)' => '((?:[^'\\\\]|\\\\.)*)'/", $c, $mc);
    foreach (array_merge($mm[1], $mc[1]) as $t) {
        $t = stripslashes($t);
        if ($t !== '' && preg_match('/[a-z]/i', $t)) {
            $texte[$t] = true;
        }
    }
}
$fehlt = array_values(array_filter(array_keys($texte), fn($t) => !isset($GLOBALS['DE'][$t])));
check('H4 jeder Text hat eine deutsche Übersetzung', $fehlt === [], count($fehlt) . ': ' . implode(' | ', array_slice($fehlt, 0, 60)));
// Modulname aus module.json: Übersetzung für die Spalte „Typ“ der Konsole (SUITE.md, Verbund-Regel 24.11.)
$texte[json_decode(file_get_contents($root . '/Verschattung/module.json'), true)['name']] = true;
check('H6 Typ-Spalte: Modulname lesbar übersetzt', ($GLOBALS['DE']['Verschattung'] ?? '') === 'DG65-Toolkit Verschattung');
$unbenutzt = array_values(array_filter(array_keys($GLOBALS['DE']), fn($t) => !isset($texte[$t])));
check('H5 keine unbenutzten Übersetzungen', $unbenutzt === [], implode(' | ', array_slice($unbenutzt, 0, 8)));
foreach ($GLOBALS['DE'] as $en => $de) {
    check("H6 Platzhalter gleich: $en", substr_count($en, '%') === substr_count($de, '%'));
}
// Beschriftungen von Eingabefeldern ≤ 40 Zeichen (SUITE.md Formular-Optik), deutsch und englisch
$eingabe = ['SelectVariable', 'NumberSpinner', 'CheckBox', 'Select', 'SelectLocation', 'ValidationTextBox'];
$lang = [];
$pruefe = function ($x) use (&$pruefe, &$lang, $eingabe) {
    if (!is_array($x)) {
        return;
    }
    if (in_array($x['type'] ?? '', $eingabe, true) && isset($x['caption'])) {
        foreach ([$x['caption'], $GLOBALS['DE'][$x['caption']] ?? $x['caption']] as $c) {
            if (mb_strlen($c) > 40) {
                $lang[] = $c;
            }
        }
    }
    foreach ($x as $v) {
        $pruefe($v);
    }
};
$pruefe($form['elements']);
check('H7 Beschriftungen ≤ 40 Zeichen', $lang === [], implode(' | ', $lang));
// Eingabefelder ohne Eigenschaft dürfen nicht in elements stehen (SUITE.md Formular-Optik 3a)
$ohne = [];
$suche = function ($x) use (&$suche, &$ohne, $m) {
    if (!is_array($x)) {
        return;
    }
    if (isset($x['type'], $x['name']) && in_array($x['type'], ['SelectVariable', 'NumberSpinner', 'CheckBox', 'Select', 'SelectLocation', 'ValidationTextBox', 'List'], true) && !array_key_exists($x['name'], $m->props)) {
        $ohne[] = $x['name'];
    }
    foreach ($x as $v) {
        $suche($v);
    }
};
$suche($form['elements']);
check('H9 elements: jedes Eingabefeld hat eine Eigenschaft', $ohne === [], implode(', ', $ohne));
$pruefe($form['actions']);
check('H10 Beschriftungen ≤ 40 Zeichen auch in actions', $lang === [], implode(' | ', $lang));
check('H8 keine PHP-Warnungen', $GLOBALS['WARN'] === [], implode(' | ', array_slice($GLOBALS['WARN'], 0, 5)));

echo "\n" . ($fails === 0 ? "✓ alle $checks Prüfungen bestanden\n" : "✗ $fails von $checks Prüfungen fehlgeschlagen\n");
exit($fails === 0 ? 0 : 1);
