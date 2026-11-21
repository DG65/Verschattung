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
require $root . '/Verschattung/VSCHLogik.php';
require $root . '/Verschattung/VSCHGrafik.php';
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
$fenster = VSCHGrafik::sonnenfenster(VSCHSonne::bahn($LAT, $LON, '2026-06-21', 'Europe/Berlin', 5), $H, 2.0);
check('F5 Nordfassade 21.06.: Sonne morgens und abends', count($fenster) === 2, json_encode(array_map(fn($w) => [date('H:i', $w[0]), date('H:i', $w[1])], $fenster)));
check('F6 Nordfassade 21.12.: keine Sonne', VSCHGrafik::sonnenfenster(VSCHSonne::bahn($LAT, $LON, '2026-12-21', 'Europe/Berlin', 5), $H, 2.0) === []);

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
foreach (['Standort', 'Fassaden', 'Geraete', 'WindID', 'RegenID', 'SperreID', 'WarnHubNutzen', 'NetzWetter', 'MarkiseOhneWetter', 'HelligkeitID', 'HellAn', 'HellAus', 'TemperaturID', 'TemperaturMin', 'VerzoegerungAn', 'VerzoegerungAus', 'WindMarkise', 'WindRaffstore', 'Haltezeit'] as $p) {
    check("G15 Eigenschaft $p im Formular", in_array($p, $namen, true));
}
check('G16 Grafiken im Formular', in_array('Grundriss', $namen, true) && in_array('Himmel', $namen, true));
// Vorschau-Knopf
$m->fieldUpdates = [];
$m->UIVorschau($m->props['Fassaden'], 0, 'juni');
$f = array_column($m->fieldUpdates, 2, 0);
check('G17 Vorschau aktualisiert Bilder und Text', isset($f['Grundriss'], $f['Himmel'], $f['Vorschau']) && strpos($f['Vorschau'], 'A ') !== false);
// Übernehmen schreibt nur ins offene Formular
$m->fieldUpdates = [];
$msg = $m->UIUebernehmen(json_encode([['Nehmen' => true, 'Name' => 'X', 'Typ' => 'rollladen', 'Position' => 601, 'Lamelle' => 0]]), $m->props['Geraete'], 'B');
check('G18 Übernehmen: UpdateFormField statt Eigenschaft', $m->fieldUpdates[0][0] === 'Geraete' && count(json_decode($m->fieldUpdates[0][2], true)) === 3 && count(json_decode($m->props['Geraete'], true)) === 2);
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
check('G23 GetState liefert Geräte', count(json_decode($m->GetState(), true)['devices']) === 2);

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
check('H4 jeder Text hat eine deutsche Übersetzung', $fehlt === [], count($fehlt) . ': ' . implode(' | ', array_slice($fehlt, 0, 8)));
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
