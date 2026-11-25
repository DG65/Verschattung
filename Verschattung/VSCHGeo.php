<?php

declare(strict_types=1);

// ===========================================================================
// Geodaten für die Verschattung: Gebäudeumriss am Standort holen und daraus die
// Fassaden ableiten. Ohne Symcon (Netzzugriff über einen übergebenen Abrufer),
// damit der Prüfstand alles mit gespeicherten Antworten rechnen kann und der
// Teil später auch anderen Modulen dienen kann (PV-Prognose: Horizont, Dach).
//
// Ablauf:
//   1. Land bestimmen (Reverse-Geocoding über Nominatim, ein Abruf).
//   2. Quelle wählen: Schweiz = geo.admin.ch „Sonnenfassade“ (fertige Fassaden),
//      deutsches Land mit ALKIS-Dienst = Tabelle QUELLEN, sonst OSM (Overpass).
//   3. Umrisse in ein lokales Meter-System um den Standort umrechnen
//      (x = Ost, y = Nord), eigenes Gebäude = das, in dem der Punkt liegt.
//   4. Fassaden: Außennormale je Kante, kurze Kanten verwerfen, ähnliche
//      Richtungen (±20°) längengewichtet zusammenfassen.
//
// Alle Abrufe nur auf Knopfdruck. Gespeichert werden nur abgeleitete Werte.
// ===========================================================================

final class VSCHGeo
{
    public const USER_AGENT = 'NRG-Stack-Verschattung (+https://github.com/DG65/Verschattung)';
    /** Umkreis (m), in dem Nachbargebäude für Grundriss und Hindernisse behalten werden. */
    public const UMKREIS_M = 80.0;
    /** Kanten kürzer als das zählen nicht als Fassade (Vorsprünge, Messrauschen). */
    public const MIN_KANTE_M = 1.5;
    /** Kanten, deren Richtung höchstens so weit auseinanderliegt, bilden eine Fassade. */
    public const CLUSTER_GRAD = 20.0;
    /** Fassaden mit weniger Wandlänge fallen weg (Erker, Gauben im Grundriss, Versätze). */
    public const MIN_FASSADE_M = 3.0;

    /**
     * Gebäudequellen je deutschem Land. Schlüssel = ISO 3166-2 (Nominatim „ISO3166-2-lvl4“). Alle Einträge am 20./21.11.2026
     * live geprüft (Umrisse kamen zurück). art: wfs2 (GML posList) oder ogcapi (GeoJSON). epsg = KBS der BBox:
     * 25832/25833 (Ost, Nord) oder 4326 (lat, lon). Das ist je Land FEST: MV, SN und TH liefern bei 4326 still 0 Treffer,
     * SL findet nur mit 4326 etwas. format = OUTPUTFORMAT, falls GeoJSON geht. Bayern hat keinen WFS → OSM.
     */
    public const QUELLEN = [
        'DE-BW' => ['name' => 'ALKIS Baden-Württemberg (LGL)', 'art' => 'wfs2', 'url' => 'https://owsproxy.lgl-bw.de/owsproxy/wfs/WFS_LGL-BW_ALKIS', 'typ' => 'nora:v_al_gebaeude', 'epsg' => 25832, 'lizenz' => '© GeoBasis-DE / LGL BW, dl-de/by-2-0'],
        'DE-BE' => ['name' => 'ALKIS Berlin', 'art' => 'wfs2', 'url' => 'https://gdi.berlin.de/services/wfs/alkis_gebaeude', 'typ' => 'alkis_gebaeude:gebaeude', 'epsg' => 25833, 'format' => 'application/json', 'lizenz' => 'Geoportal Berlin, dl-de/zero-2-0'],
        'DE-BB' => ['name' => 'ALKIS Brandenburg (LGB)', 'art' => 'wfs2', 'url' => 'https://isk.geobasis-bb.de/ows/alkis_vereinf_wfs', 'typ' => 'ave:GebaeudeBauwerk', 'epsg' => 25833, 'lizenz' => '© GeoBasis-DE/LGB, dl-de/by-2-0'],
        'DE-HB' => ['name' => 'ALKIS Bremen (über LGLN)', 'art' => 'wfs2', 'url' => 'https://opendata.lgln.niedersachsen.de/doorman/noauth/alkishb_wfs_sf', 'typ' => 'adv:AX_Gebaeude', 'epsg' => 25832, 'lizenz' => '© GeoInformation Bremen, CC BY 4.0'],
        'DE-HH' => ['name' => 'ALKIS Hamburg (LGV)', 'art' => 'ogcapi', 'url' => 'https://api.hamburg.de/datasets/v1/alkis_vereinfacht/collections/GebaeudeBauwerk/items', 'epsg' => 25832, 'lizenz' => '© Freie und Hansestadt Hamburg, LGV, dl-de/by-2-0'],
        'DE-HE' => ['name' => 'ALKIS Hessen (HVBG)', 'art' => 'wfs2', 'url' => 'https://www.gds.hessen.de/wfs2/aaa-suite/cgi-bin/alkis/vereinf/wfs', 'typ' => 'ave:GebaeudeBauwerk', 'epsg' => 25832, 'lizenz' => '© HVBG, dl-de/zero-2-0'],
        'DE-MV' => ['name' => 'ALKIS Mecklenburg-Vorpommern (LAiV)', 'art' => 'wfs2', 'url' => 'https://www.geodaten-mv.de/dienste/alkis_wfs_sf', 'typ' => 'adv:AX_Gebaeude', 'epsg' => 25833, 'lizenz' => '© GeoBasis-DE/M-V, CC BY 4.0'],
        'DE-NI' => ['name' => 'ALKIS Niedersachsen (LGLN)', 'art' => 'wfs2', 'url' => 'https://opendata.lgln.niedersachsen.de/doorman/noauth/alkis_wfs_sf', 'typ' => 'adv:AX_Gebaeude', 'epsg' => 25832, 'lizenz' => '© LGLN, CC BY 4.0'],
        'DE-NW' => ['name' => 'ALKIS Nordrhein-Westfalen', 'art' => 'ogcapi', 'url' => 'https://ogc-api.nrw.de/lika/v1/collections/gebaeude_bauwerk/items', 'epsg' => 25832, 'lizenz' => 'Geobasis NRW, dl-de/zero-2-0'],
        'DE-RP' => ['name' => 'ALKIS Rheinland-Pfalz', 'art' => 'wfs2', 'url' => 'https://www.geoportal.rlp.de/registry/wfs/519', 'typ' => 'ave:GebaeudeBauwerk', 'epsg' => 25832, 'lizenz' => '© GeoBasis-DE / LVermGeoRP, dl-de/by-2-0'],
        'DE-SH' => ['name' => 'ALKIS Schleswig-Holstein', 'art' => 'wfs2', 'url' => 'https://dienste.gdi-sh.de/WFS_SH_ALKIS_VWG_OpenGBD', 'typ' => 'ave:GebaeudeBauwerk', 'epsg' => 25832, 'lizenz' => '© GeoBasis-DE/LVermGeo SH, CC BY 4.0'],
        'DE-SL' => ['name' => 'ALKIS Saarland (LVGL)', 'art' => 'wfs2', 'url' => 'https://geoportal.saarland.de/registry/wfs/416', 'typ' => 'GDI_ALKIS_Gebaeude:Hausumringe', 'epsg' => 4326, 'format' => 'GEOJSON', 'lizenz' => '© GeoBasis DE/LVGL-SL, dl-de/by-2-0'],
        'DE-SN' => ['name' => 'ALKIS Sachsen (GeoSN)', 'art' => 'wfs2', 'url' => 'https://geodienste.sachsen.de/aaa/public_alkis/vereinf/wfs', 'typ' => 'ave:GebaeudeBauwerk', 'epsg' => 25833, 'lizenz' => '© GeoSN, dl-de/by-2-0'],
        'DE-ST' => ['name' => 'ALKIS Sachsen-Anhalt (LVermGeo)', 'art' => 'wfs2', 'url' => 'https://www.geodatenportal.sachsen-anhalt.de/wss/service/ST_LVermGeo_ALKIS_WFS_OpenData/guest', 'typ' => 'ave:GebaeudeBauwerk', 'epsg' => 25832, 'lizenz' => '© GeoBasis-DE / LVermGeo ST, dl-de/by-2-0'],
        'DE-TH' => ['name' => 'ALKIS Thüringen (TLBG)', 'art' => 'wfs2', 'url' => 'https://www.geoproxy.geoportal-th.de/geoproxy/services/adv_alkis_wfs', 'typ' => 'ave:GebaeudeBauwerk', 'epsg' => 25832, 'lizenz' => '© GDI-Th, CC BY 4.0'],
    ];

    // ---------------------------------------------------------------------
    //  Ablauf
    // ---------------------------------------------------------------------

    /**
     * Haus am Standort erkennen. $abruf: fn(string $url, array $kopf = [], ?string $post = null): array{0:int,1:string}.
     * Rückgabe ['ok' => bool, 'meldung' => string, 'quelle' => string, 'lizenz' => string, 'land' => string,
     *   'eigen' => [[x,y],…], 'nachbarn' => [[[x,y],…],…], 'fassaden' => [['az','len','seg'],…], 'gebaeude' => int]
     */
    public static function erkennen(float $lat, float $lon, callable $abruf, ?string $landVorgabe = null): array
    {
        $land = $landVorgabe ?? self::land($lat, $lon, $abruf);
        if ($land === 'CH') {
            $r = self::schweiz($lat, $lon, $abruf);
            if ($r['ok']) {
                return $r + ['land' => $land];
            }
        }
        $versuche = [];
        if (isset(self::QUELLEN[$land])) {
            $q = self::QUELLEN[$land];
            $r = self::ausUmrissen($lat, $lon, self::alkis($lat, $lon, $q, $abruf, $fehler), $q['name'], $q['lizenz']);
            if ($r['ok']) {
                return $r + ['land' => $land];
            }
            $versuche[] = $q['name'] . ': ' . ($fehler !== '' ? $fehler : $r['meldung']);
        }
        $r = self::ausUmrissen($lat, $lon, self::osm($lat, $lon, $abruf, $fehler), 'OpenStreetMap (Overpass)', '© OpenStreetMap-Mitwirkende, ODbL');
        if (!$r['ok'] && $fehler !== '') {
            $r['meldung'] = $fehler;
        }
        if ($versuche !== []) {
            $r['meldung'] = trim($r['meldung'] . ' (' . implode('; ', $versuche) . ')');
        }
        return $r + ['land' => $land];
    }

    /** Land/Bundesland per Nominatim: 'DE-BW', 'CH', 'AT', … oder '' bei Fehler. */
    public static function land(float $lat, float $lon, callable $abruf): string
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=5&addressdetails=1&lat=' . self::zahl($lat) . '&lon=' . self::zahl($lon);
        [$code, $body] = $abruf($url, ['Accept-Language: de']);
        $j = $code === 200 ? json_decode($body, true) : null;
        $a = is_array($j) ? (array)($j['address'] ?? []) : [];
        $cc = strtoupper((string)($a['country_code'] ?? ''));
        if ($cc === 'DE') {
            return (string)($a['ISO3166-2-lvl4'] ?? 'DE');
        }
        return $cc;
    }

    /** ALKIS-Umrisse eines Landes, als Liste von Polygonen in WGS84 [[lat,lon],…]. */
    public static function alkis(float $lat, float $lon, array $q, callable $abruf, ?string &$fehler = ''): array
    {
        $fehler = '';
        $url = self::alkisUrl($lat, $lon, $q);
        [$code, $body] = $abruf($url, []);
        if ($code !== 200 || $body === '') {
            $fehler = 'HTTP ' . $code;
            return [];
        }
        if (stripos(substr($body, 0, 2000), 'ExceptionReport') !== false) {
            $fehler = 'Dienst meldet einen Fehler'; // RP antwortet mit HTTP 200 und Fehlertext
            return [];
        }
        $t = ltrim($body);
        if (($t[0] ?? '') === '{') {
            // GeoJSON: OGC API mit crs=25832, BE in 25833, SL in 4326 (lon,lat)
            return self::geojsonPolygone($body, $q['art'] === 'ogcapi' || $q['epsg'] !== 4326 ? (int)$q['epsg'] : 4326);
        }
        return self::gmlPolygone($body, $q['epsg'] === 4326 ? 25832 : (int)$q['epsg'], false); // SL liefert GML in 25832
    }

    /** Abfrage-URL je Land (BBox ±60 m). */
    public static function alkisUrl(float $lat, float $lon, array $q): string
    {
        $h = 60.0;
        $epsg = (int)$q['epsg'];
        if ($epsg === 4326) {
            $dLat = $h / 111320.0;
            $dLon = $h / (111320.0 * cos(deg2rad($lat)));
            $b = implode(',', array_map([self::class, 'zahl'], [$lat - $dLat, $lon - $dLon, $lat + $dLat, $lon + $dLon]));
        } else {
            [$e, $n] = self::nachUtm($lat, $lon, $epsg === 25833 ? 33 : 32);
            $b = implode(',', [round($e - $h), round($n - $h), round($e + $h), round($n + $h)]);
        }
        if ($q['art'] === 'ogcapi') {
            $crs = 'http://www.opengis.net/def/crs/EPSG/0/' . $epsg;
            return $q['url'] . '?f=json&limit=200&bbox=' . $b . '&bbox-crs=' . rawurlencode($crs) . '&crs=' . rawurlencode($crs);
        }
        return $q['url'] . '?SERVICE=WFS&VERSION=2.0.0&REQUEST=GetFeature&COUNT=200&TYPENAMES=' . rawurlencode($q['typ'])
            . '&BBOX=' . $b . ',urn:ogc:def:crs:EPSG::' . $epsg . (isset($q['format']) ? '&OUTPUTFORMAT=' . rawurlencode($q['format']) : '');
    }

    /** OSM-Gebäude im Umkreis (Overpass, out geom). */
    public static function osm(float $lat, float $lon, callable $abruf, ?string &$fehler = ''): array
    {
        $fehler = '';
        $q = '[out:json][timeout:60];way["building"](around:' . (int)self::UMKREIS_M . ',' . self::zahl($lat) . ',' . self::zahl($lon) . ');out geom;';
        $code = 0;
        $body = '';
        foreach (['https://overpass-api.de/api/interpreter', 'https://overpass.private.coffee/api/interpreter'] as $server) {
            [$code, $body] = $abruf($server, ['Content-Type: application/x-www-form-urlencoded'], 'data=' . rawurlencode($q));
            if ($code === 200) {
                break; // öffentliche Server sind oft ausgelastet (429/502/504): einmal ausweichen
            }
        }
        if ($code !== 200) {
            $fehler = 'OpenStreetMap: HTTP ' . $code;
            return [];
        }
        $j = json_decode($body, true);
        $out = [];
        foreach ((array)($j['elements'] ?? []) as $el) {
            $p = [];
            foreach ((array)($el['geometry'] ?? []) as $g) {
                $p[] = [(float)$g['lat'], (float)$g['lon']];
            }
            if (count($p) >= 4) {
                $t = (array)($el['tags'] ?? []);
                $out[] = ['p' => $p, 'stockwerke' => (int)($t['building:levels'] ?? 0), 'hoehe' => (float)($t['height'] ?? 0),
                    'dach' => (string)($t['roof:shape'] ?? ''), 'first' => (string)($t['roof:orientation'] ?? ''), 'art' => (string)($t['building'] ?? '')];
            }
        }
        return $out;
    }

    /** Straßenbreite in m je OSM-Typ (für den Lageplan). */
    public const STRASSE_BREITE = ['motorway' => 12, 'trunk' => 10, 'primary' => 8, 'secondary' => 7, 'tertiary' => 6.5, 'unclassified' => 5.5,
        'residential' => 5.5, 'living_street' => 5, 'service' => 3.5, 'track' => 3, 'pedestrian' => 3, 'footway' => 1.8, 'path' => 1.5,
        'cycleway' => 1.8, 'steps' => 1.5, 'bridleway' => 1.8];

    /**
     * Straßen und Wege im Umkreis (OpenStreetMap, Overpass), nur für den Lageplan.
     * Rückgabe [['typ' => highway, 'name' => string, 'b' => Breite m, 'p' => [[x,y],…] lokal in m], …]; Fehler → [].
     */
    public static function strassen(float $lat, float $lon, callable $abruf, float $umkreis = 120.0): ?array
    {
        // Rückgabe null = kein Server hat geantwortet (später erneut versuchen); [] = wirklich keine Straße im Umkreis
        $q = '[out:json][timeout:25];way["highway"](around:' . (int)$umkreis . ',' . self::zahl($lat) . ',' . self::zahl($lon) . ');out geom;';
        $j = null;
        foreach (['https://overpass-api.de/api/interpreter', 'https://overpass.private.coffee/api/interpreter'] as $server) {
            [$code, $body] = $abruf($server, ['Content-Type: application/x-www-form-urlencoded'], 'data=' . rawurlencode($q));
            $j = $code === 200 ? json_decode($body, true) : null;
            if (is_array($j) && isset($j['elements'])) {
                break;
            }
            $j = null;
        }
        if ($j === null) {
            return null;
        }
        $out = [];
        foreach ((array)($j['elements'] ?? []) as $el) {
            $typ = (string)($el['tags']['highway'] ?? '');
            if (!isset(self::STRASSE_BREITE[$typ])) {
                continue; // Baustellen, Planungen, Bahnsteige …
            }
            $p = [];
            foreach ((array)($el['geometry'] ?? []) as $g) {
                $l = self::lokal($lat, $lon, (float)$g['lat'], (float)$g['lon']);
                $p[] = [round($l[0], 1), round($l[1], 1)];
            }
            if (count($p) >= 2) {
                $out[] = ['typ' => $typ, 'name' => (string)($el['tags']['name'] ?? ''), 'b' => self::STRASSE_BREITE[$typ], 'p' => $p];
            }
        }
        return $out;
    }

    /** Schweiz: fertige Fassaden aus „Sonnenfassade“ (BFE). Konvention dort: 0 = Süd, −90 = Ost → Kompass = Wert + 180. */
    public static function schweiz(float $lat, float $lon, callable $abruf): array
    {
        $d = 0.01; // tolerance zählt in Bildpunkten von imageDisplay über mapExtent: 10 px ≈ 15–20 m
        $url = 'https://api3.geo.admin.ch/rest/services/all/MapServer/identify?geometry=' . self::zahl($lon) . ',' . self::zahl($lat)
            . '&geometryType=esriGeometryPoint&sr=4326&layers=all:ch.bfe.solarenergie-eignung-fassaden&tolerance=10'
            . '&mapExtent=' . implode(',', array_map([self::class, 'zahl'], [$lon - $d, $lat - $d, $lon + $d, $lat + $d]))
            . '&imageDisplay=1000,1000,96&returnGeometry=true&geometryFormat=geojson';
        [$code, $body] = $abruf($url, []);
        $j = $code === 200 ? json_decode($body, true) : null;
        $res = is_array($j) ? (array)($j['results'] ?? []) : [];
        // Nur Fassaden des Gebäudes, das dem Punkt am nächsten liegt (gleiche building_id).
        $best = null;
        $bestD = INF;
        foreach ($res as $r) {
            foreach (self::linien($r['geometry'] ?? []) as $l) {
                foreach ($l as $pt) {
                    $dd = hypot(($pt[1] - $lat) * 111320, ($pt[0] - $lon) * 111320 * cos(deg2rad($lat)));
                    if ($dd < $bestD) {
                        $bestD = $dd;
                        $best = $r['properties']['building_id'] ?? ($r['properties']['gwr_egid'] ?? null);
                    }
                }
            }
        }
        $fassaden = [];
        foreach ($res as $r) {
            if (($r['properties']['building_id'] ?? ($r['properties']['gwr_egid'] ?? null)) !== $best) {
                continue;
            }
            $segs = [];
            $len = 0.0;
            foreach (self::linien($r['geometry'] ?? []) as $l) {
                for ($i = 0; $i + 1 < count($l); $i++) {
                    $a = self::lokal($lat, $lon, $l[$i][1], $l[$i][0]);
                    $b = self::lokal($lat, $lon, $l[$i + 1][1], $l[$i + 1][0]);
                    $segs[] = [$a, $b];
                    $len += hypot($b[0] - $a[0], $b[1] - $a[1]);
                }
            }
            if ($len >= self::MIN_KANTE_M) {
                $fassaden[] = ['az' => fmod((float)($r['properties']['ausrichtung'] ?? 0) + 180 + 360, 360), 'len' => round($len, 1), 'seg' => $segs];
            }
        }
        if ($fassaden === []) {
            return ['ok' => false, 'meldung' => 'Keine Fassade gefunden', 'quelle' => 'geo.admin.ch', 'lizenz' => '', 'eigen' => [], 'nachbarn' => [], 'fassaden' => [], 'gebaeude' => 0];
        }
        $fassaden = self::zusammenfassen($fassaden);
        return ['ok' => true, 'meldung' => '', 'quelle' => 'geo.admin.ch (BFE Sonnenfassade)', 'lizenz' => '© swisstopo / BFE',
            'eigen' => [], 'nachbarn' => [], 'fassaden' => $fassaden, 'gebaeude' => 1];
    }

    /** Aus WGS84-Umrissen: eigenes Gebäude, Nachbarn, Fassaden. */
    public static function ausUmrissen(float $lat, float $lon, array $polygone, string $quelle, string $lizenz): array
    {
        $leer = ['ok' => false, 'quelle' => $quelle, 'lizenz' => $lizenz, 'eigen' => [], 'nachbarn' => [], 'fassaden' => [], 'gebaeude' => 0];
        if ($polygone === []) {
            return $leer + ['meldung' => 'Keine Gebäude am Standort gefunden'];
        }
        $lokal = [];
        foreach ($polygone as $g) {
            $pts = array_map(function ($p) use ($lat, $lon) {
                return self::lokal($lat, $lon, $p[0], $p[1]);
            }, $g['p'] ?? $g);
            $lokal[] = ['p' => self::schliessen($pts), 'hoehe' => (float)($g['hoehe'] ?? 0), 'stockwerke' => (int)($g['stockwerke'] ?? 0),
                'dach' => (string)($g['dach'] ?? ''), 'first' => (string)($g['first'] ?? ''), 'art' => (string)($g['art'] ?? '')];
        }
        $eigen = null;
        foreach ($lokal as $i => $g) {
            if (self::innen($g['p'], 0.0, 0.0)) {
                $eigen = $i;
                break;
            }
        }
        if ($eigen === null) {
            // Punkt liegt z. B. im Garten: das nächste Gebäude bis 25 m nehmen
            $bestD = 25.0;
            foreach ($lokal as $i => $g) {
                $d = self::abstandZumRand($g['p'], 0.0, 0.0);
                if ($d < $bestD) {
                    $bestD = $d;
                    $eigen = $i;
                }
            }
        }
        if ($eigen === null) {
            return $leer + ['meldung' => 'Am Standort steht kein Gebäude (Standort genau auf das Haus setzen)'];
        }
        $nachbarn = [];
        $info = [];
        foreach ($lokal as $i => $g) {
            if ($i === $eigen) {
                continue;
            }
            $nah = false;
            foreach ($g['p'] as $p) {
                if (hypot($p[0], $p[1]) <= self::UMKREIS_M) {
                    $nah = true;
                    break;
                }
            }
            if ($nah) {
                $nachbarn[] = array_map(function ($p) {
                    return [round($p[0], 1), round($p[1], 1)];
                }, $g['p']);
                // Höhenangaben für den Hindernis-Horizont (gleich indiziert wie 'nachbarn'; leer = 2 Geschosse + 45°-Dach)
                $info[] = array_filter(['stockwerke' => $g['stockwerke'], 'hoehe' => $g['hoehe'], 'dach' => $g['dach'], 'first' => $g['first'], 'art' => $g['art']]);
            }
        }
        $p = $lokal[$eigen]['p'];
        return ['ok' => true, 'meldung' => '', 'quelle' => $quelle, 'lizenz' => $lizenz,
            'eigen' => array_map(function ($q) {
                return [round($q[0], 2), round($q[1], 2)];
            }, $p),
            'nachbarn' => $nachbarn, 'nachbarInfo' => $info, 'fassaden' => self::fassaden($p), 'gebaeude' => count($polygone)];
    }

    // ---------------------------------------------------------------------
    //  Fassaden
    // ---------------------------------------------------------------------

    /** Fassaden aus einem geschlossenen Umriss (lokale Meter). Rückgabe Liste ['az','len','seg'] nach Azimut sortiert. */
    public static function fassaden(array $p): array
    {
        $p = self::schliessen($p);
        $ccw = self::flaeche($p) > 0;
        $kanten = [];
        for ($i = 0; $i + 1 < count($p); $i++) {
            $dx = $p[$i + 1][0] - $p[$i][0];
            $dy = $p[$i + 1][1] - $p[$i][1];
            $l = hypot($dx, $dy);
            if ($l < self::MIN_KANTE_M) {
                continue;
            }
            // Außennormale: bei Umlauf gegen den Uhrzeigersinn rechts der Laufrichtung.
            $nx = $ccw ? $dy : -$dy;
            $ny = $ccw ? -$dx : $dx;
            $kanten[] = ['az' => fmod(rad2deg(atan2($nx, $ny)) + 360, 360), 'len' => $l, 'seg' => [[$p[$i], $p[$i + 1]]]];
        }
        return self::zusammenfassen($kanten);
    }

    /** Ähnliche Richtungen längengewichtet zusammenfassen (Mittel über Einheitsvektoren). */
    public static function zusammenfassen(array $teile): array
    {
        usort($teile, function ($a, $b) {
            return $b['len'] <=> $a['len'];
        });
        $cl = [];
        foreach ($teile as $k) {
            $treffer = false;
            foreach ($cl as &$c) {
                if (VSCHSonne::winkelAbstand($k['az'], $c['az']) <= self::CLUSTER_GRAD) {
                    $c['sx'] += sin(deg2rad($k['az'])) * $k['len'];
                    $c['sy'] += cos(deg2rad($k['az'])) * $k['len'];
                    $c['len'] += $k['len'];
                    $c['seg'] = array_merge($c['seg'], $k['seg']);
                    $c['az'] = fmod(rad2deg(atan2($c['sx'], $c['sy'])) + 360, 360);
                    $treffer = true;
                    break;
                }
            }
            unset($c);
            if (!$treffer) {
                $cl[] = ['az' => $k['az'], 'len' => $k['len'], 'seg' => $k['seg'],
                    'sx' => sin(deg2rad($k['az'])) * $k['len'], 'sy' => cos(deg2rad($k['az'])) * $k['len']];
            }
        }
        $cl = array_values(array_filter($cl, function ($c) {
            return $c['len'] >= self::MIN_FASSADE_M;
        }));
        usort($cl, function ($a, $b) {
            return $a['az'] <=> $b['az'];
        });
        return array_map(function ($c) {
            return ['az' => round($c['az'], 1), 'len' => round($c['len'], 1), 'seg' => array_map(function ($s) {
                return [[round($s[0][0], 2), round($s[0][1], 2)], [round($s[1][0], 2), round($s[1][1], 2)]];
            }, $c['seg'])];
        }, $cl);
    }

    // ---------------------------------------------------------------------
    //  Parser
    // ---------------------------------------------------------------------

    /** Polygone aus GML 3 (gml:posList bzw. gml:coordinates); Außenring je Feature. */
    public static function gmlPolygone(string $xml, int $epsg, bool $latLonReihenfolge = false): array
    {
        // Je Objekt (wfs:member / gml:featureMember) auch Gebäudefunktion und Geschosse lesen (ALKIS BW: gebaeudefunktion_name,
        // Live-Befund 24.11.: 8 Garagen und 4 Schuppen ums Haus galten sonst als zweigeschossige Häuser)
        if (preg_match_all('#<(?:wfs:member|gml:featureMember)\b.*?</(?:wfs:member|gml:featureMember)>#s', $xml, $obj) && count($obj[0]) > 1) {
            $out = [];
            foreach ($obj[0] as $block) {
                $art = preg_match('#<[\w:]*(?:gebaeudefunktion_name|gebaeudefunktion|funktion)\b[^>]*>([^<]+)<#i', $block, $fm) ? trim($fm[1]) : '';
                $gs = preg_match('#<[\w:]*(?:anzahlderoberirdischengeschosse|anzahl_der_oberirdischen_geschosse|geschosse)\b[^>]*>(\d+)<#i', $block, $gm) ? (int)$gm[1] : 0;
                foreach (self::gmlPolygone($block, $epsg, $latLonReihenfolge) as $p) {
                    $out[] = $p + ['art' => $art, 'stockwerke' => $gs];
                }
            }
            return $out;
        }
        $out = [];
        // Je Polygon nur den äußeren Ring (exterior/outerBoundaryIs), Innenhöfe bleiben weg.
        if (!preg_match_all('#<(?:gml:)?(?:exterior|outerBoundaryIs)\b.*?<(?:gml:)?posList([^>]*)>(.*?)</(?:gml:)?posList>#s', $xml, $m, PREG_SET_ORDER)) {
            preg_match_all('#<(?:gml:)?posList([^>]*)>(.*?)</(?:gml:)?posList>#s', $xml, $m, PREG_SET_ORDER);
        }
        foreach ($m as $treffer) {
            $dim = preg_match('/srsDimension="(\d)"/', $treffer[1], $dm) ? (int)$dm[1] : 2;
            $z = preg_split('/\s+/', trim($treffer[2]));
            $p = [];
            for ($i = 0; $i + $dim - 1 < count($z); $i += $dim) {
                $a = (float)$z[$i];
                $b = (float)$z[$i + 1];
                $p[] = self::nachWgs($a, $b, $epsg, $latLonReihenfolge);
            }
            if (count($p) >= 4) {
                $out[] = ['p' => $p];
            }
        }
        return $out;
    }

    /** Polygone aus GeoJSON (Polygon/MultiPolygon, Außenring). */
    public static function geojsonPolygone(string $json, int $epsg = 4326): array
    {
        $j = json_decode($json, true);
        $out = [];
        foreach ((array)($j['features'] ?? []) as $f) {
            $g = (array)($f['geometry'] ?? []);
            $ringe = [];
            if (($g['type'] ?? '') === 'Polygon') {
                $ringe[] = $g['coordinates'][0] ?? [];
            } elseif (($g['type'] ?? '') === 'MultiPolygon') {
                foreach ((array)$g['coordinates'] as $poly) {
                    $ringe[] = $poly[0] ?? [];
                }
            }
            foreach ($ringe as $r) {
                $p = [];
                foreach ($r as $c) {
                    $p[] = self::nachWgs((float)$c[0], (float)$c[1], $epsg, false);
                }
                if (count($p) >= 4) {
                    $pr = (array)($f['properties'] ?? []);
                    $out[] = ['p' => $p, 'hoehe' => (float)($pr['hoehe'] ?? 0),
                        'stockwerke' => (int)($pr['anzahlDerOberirdischenGeschosse'] ?? ($pr['geschosse'] ?? 0)),
                        'art' => (string)($pr['gebaeudefunktion_name'] ?? ($pr['gebaeudefunktion'] ?? ($pr['funktion'] ?? '')))];
                }
            }
        }
        return $out;
    }

    /** Linien (LineString/MultiLineString) eines GeoJSON-Geometrieobjekts als [[[lon,lat],…],…]. */
    private static function linien(array $g): array
    {
        if (($g['type'] ?? '') === 'LineString') {
            return [$g['coordinates']];
        }
        if (($g['type'] ?? '') === 'MultiLineString') {
            return $g['coordinates'];
        }
        return [];
    }

    // ---------------------------------------------------------------------
    //  Koordinaten
    // ---------------------------------------------------------------------

    /** Koordinate des Dienstes nach WGS84 [lat, lon]. */
    public static function nachWgs(float $a, float $b, int $epsg, bool $latLonReihenfolge): array
    {
        if ($epsg === 25832 || $epsg === 25833) {
            return self::vonUtm($a, $b, $epsg === 25833 ? 33 : 32);
        }
        // EPSG:4326/4258: GML 3.2 meist lat,lon; GeoJSON immer lon,lat
        return $latLonReihenfolge ? [$a, $b] : [$b, $a];
    }

    /** Lokales Meter-System um ($lat0,$lon0): x = Ost, y = Nord (für < 1 km genau genug). */
    public static function lokal(float $lat0, float $lon0, float $lat, float $lon): array
    {
        $r = 6371008.8;
        return [deg2rad($lon - $lon0) * $r * cos(deg2rad($lat0)), deg2rad($lat - $lat0) * $r];
    }

    /** WGS84 → UTM (GRS80/ETRS89 ≈ WGS84 auf cm). Rückgabe [Ost, Nord]. */
    public static function nachUtm(float $lat, float $lon, int $zone): array
    {
        [$a, $f, $k0] = [6378137.0, 1 / 298.257222101, 0.9996];
        $e2 = $f * (2 - $f);
        $ep2 = $e2 / (1 - $e2);
        $phi = deg2rad($lat);
        $lam = deg2rad($lon - (6 * $zone - 183));
        $n = $a / sqrt(1 - $e2 * sin($phi) ** 2);
        $t = tan($phi) ** 2;
        $c = $ep2 * cos($phi) ** 2;
        $aa = cos($phi) * $lam;
        $m = $a * ((1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256) * $phi - (3 * $e2 / 8 + 3 * $e2 ** 2 / 32 + 45 * $e2 ** 3 / 1024) * sin(2 * $phi)
            + (15 * $e2 ** 2 / 256 + 45 * $e2 ** 3 / 1024) * sin(4 * $phi) - (35 * $e2 ** 3 / 3072) * sin(6 * $phi));
        $x = $k0 * $n * ($aa + (1 - $t + $c) * $aa ** 3 / 6 + (5 - 18 * $t + $t ** 2 + 72 * $c - 58 * $ep2) * $aa ** 5 / 120) + 500000;
        $y = $k0 * ($m + $n * tan($phi) * ($aa ** 2 / 2 + (5 - $t + 9 * $c + 4 * $c ** 2) * $aa ** 4 / 24 + (61 - 58 * $t + $t ** 2 + 600 * $c - 330 * $ep2) * $aa ** 6 / 720));
        return [$x, $y];
    }

    /** UTM → WGS84 [lat, lon]. */
    public static function vonUtm(float $x, float $y, int $zone): array
    {
        [$a, $f, $k0] = [6378137.0, 1 / 298.257222101, 0.9996];
        $e2 = $f * (2 - $f);
        $ep2 = $e2 / (1 - $e2);
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));
        $m = $y / $k0;
        $mu = $m / ($a * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));
        $phi1 = $mu + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu) + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu) + (1097 * $e1 ** 4 / 512) * sin(8 * $mu);
        $n1 = $a / sqrt(1 - $e2 * sin($phi1) ** 2);
        $t1 = tan($phi1) ** 2;
        $c1 = $ep2 * cos($phi1) ** 2;
        $r1 = $a * (1 - $e2) / (1 - $e2 * sin($phi1) ** 2) ** 1.5;
        $d = ($x - 500000) / ($n1 * $k0);
        $lat = $phi1 - ($n1 * tan($phi1) / $r1) * ($d ** 2 / 2 - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ep2 - 3 * $c1 ** 2) * $d ** 6 / 720);
        $lon = ($d - (1 + 2 * $t1 + $c1) * $d ** 3 / 6 + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ep2 + 24 * $t1 ** 2) * $d ** 5 / 120) / cos($phi1);
        return [rad2deg($lat), (6 * $zone - 183) + rad2deg($lon)];
    }

    // ---------------------------------------------------------------------
    //  Geometrie
    // ---------------------------------------------------------------------

    private static function schliessen(array $p): array
    {
        if ($p !== [] && ($p[0][0] !== end($p)[0] || $p[0][1] !== end($p)[1])) {
            $p[] = $p[0];
        }
        return $p;
    }

    /** Vorzeichenbehaftete Fläche (positiv = gegen den Uhrzeigersinn). */
    public static function flaeche(array $p): float
    {
        $s = 0.0;
        for ($i = 0; $i + 1 < count($p); $i++) {
            $s += $p[$i][0] * $p[$i + 1][1] - $p[$i + 1][0] * $p[$i][1];
        }
        return $s / 2;
    }

    public static function innen(array $p, float $x, float $y): bool
    {
        $c = false;
        $n = count($p);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $p[$i];
            [$xj, $yj] = $p[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $c = !$c;
            }
        }
        return $c;
    }

    private static function abstandZumRand(array $p, float $x, float $y): float
    {
        $best = INF;
        for ($i = 0; $i + 1 < count($p); $i++) {
            [$ax, $ay] = $p[$i];
            [$bx, $by] = $p[$i + 1];
            $l2 = ($bx - $ax) ** 2 + ($by - $ay) ** 2;
            $t = $l2 > 0 ? max(0, min(1, (($x - $ax) * ($bx - $ax) + ($y - $ay) * ($by - $ay)) / $l2)) : 0;
            $best = min($best, hypot($x - ($ax + $t * ($bx - $ax)), $y - ($ay + $t * ($by - $ay))));
        }
        return $best;
    }

    /** Zahl mit Punkt, ohne Exponent (für URLs). */
    public static function zahl(float $v): string
    {
        return rtrim(rtrim(number_format($v, 7, '.', ''), '0'), '.');
    }
}
