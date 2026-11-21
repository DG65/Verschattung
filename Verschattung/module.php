<?php

declare(strict_types=1);

// ===========================================================================
// Verschattung (DG65-Toolkit) — Rollläden, Raffstores und Markisen nach dem
// Sonnenstand, eingerichtet über eine Grafik statt über Winkelangaben.
//
//   1. Standort aus der Symcon-Standort-Instanz (oder eigene Kartenauswahl).
//   2. „Haus erkennen“: Umriss aus amtlichen Geodaten bzw. OpenStreetMap,
//      daraus die Fassaden; Horizont von PVGIS. Der Nutzer bestätigt mit
//      „Übernehmen“ (Store-Regel: Eigenschaften gehören dem Nutzer).
//   3. Geräte je Fassade zuordnen; Typ bringt passende Voreinstellungen mit.
//   4. Vorschau: Sonnenbahn und „Sonne auf der Fassade von … bis …“.
//
// Entscheidungen: VSCHLogik (ohne Symcon testbar). Sonne: VSCHSonne.
// Geodaten: VSCHGeo, VSCHHorizont. Grafik: VSCHGrafik. Texte: VSCHText.
// ===========================================================================

require_once __DIR__ . '/VSCHText.php';
require_once __DIR__ . '/VSCHSonne.php';
require_once __DIR__ . '/VSCHGeo.php';
require_once __DIR__ . '/VSCHHorizont.php';
require_once __DIR__ . '/VSCHLogik.php';
require_once __DIR__ . '/VSCHGrafik.php';

class Verschattung extends IPSModule
{
    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const WARNHUB_GUID = '{97621F9C-C61B-4A7F-9B03-C34BC58B04A6}';
    private const DOC_URL = 'https://github.com/DG65/Verschattung/blob/beta/README.md';
    private const LICENSE_URL = 'https://github.com/DG65/Verschattung/blob/beta/LICENSE';
    /** So lange (s) gilt eine Wertänderung nach einem eigenen Befehl als dessen Echo (Rollläden fahren bis ~2 min). */
    private const ECHO_S = 240;
    /** Lamellen erst nachführen, wenn sich der Winkel um so viel geändert hat (Grad). */
    private const LAMELLE_SCHRITT = 8.0;
    /** Ein Befehl gilt als verloren, wenn das Gerät so lange danach nicht in der Nähe des Ziels steht (s); dann einmal wiederholen. */
    private const NACHKONTROLLE_S = 300;

    // „Neu bis Version“: erst ab der ersten veröffentlichten Version gepflegt.
    private const NEWS_VERSIONS = [];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        VSCHText::setzen(VSCHText::mitMerkliste(function (string $text): string {
            return $this->Translate($text);
        }));
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Standort', '{"latitude":0,"longitude":0}');
        $this->RegisterPropertyString('Fassaden', '[]');
        $this->RegisterPropertyString('Geraete', '[]');
        $this->RegisterPropertyInteger('HelligkeitID', 0);
        $this->RegisterPropertyFloat('HellAn', 25000);
        $this->RegisterPropertyFloat('HellAus', 12000);
        $this->RegisterPropertyInteger('TemperaturID', 0);
        $this->RegisterPropertyFloat('TemperaturMin', 0);
        $this->RegisterPropertyInteger('VerzoegerungAn', 3);
        $this->RegisterPropertyInteger('VerzoegerungAus', 15);
        $this->RegisterPropertyInteger('WindID', 0);
        $this->RegisterPropertyFloat('WindMarkise', 35);
        $this->RegisterPropertyFloat('WindRaffstore', 50);
        $this->RegisterPropertyInteger('RegenID', 0);
        $this->RegisterPropertyInteger('SperreID', 0);
        $this->RegisterPropertyBoolean('WarnHubNutzen', true);
        $this->RegisterPropertyBoolean('NetzWetter', false);
        $this->RegisterPropertyBoolean('MarkiseOhneWetter', false);
        $this->RegisterPropertyInteger('Haltezeit', 20);

        $this->RegisterAttributeString('Haus', '{}');
        $this->RegisterAttributeString('Horizont', '{}');
        $this->RegisterAttributeString('Zustand', '{}');
        $this->RegisterAttributeString('Gesendet', '{}');
        $this->RegisterAttributeString('WindSeit', '{}');
        $this->RegisterAttributeInteger('RegenSeit', 0);
        $this->RegisterAttributeString('NetzBoe', '{}');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);

        $this->RegisterTimer('Tick', 0, 'VSCH_Tick($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $neu = @$this->GetIDForIdent('Automatik') === false;
        $this->MaintainVariable('Automatik', $this->t('Automatic'), VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->EnableAction('Automatik');
        if ($neu) {
            $this->SetValue('Automatik', true); // nur beim Anlegen: danach entscheidet der Nutzer
        }
        $this->MaintainVariable('Status', $this->t('Status'), VARIABLETYPE_STRING, '', 2, true);

        foreach ($this->GetMessageList() as $sender => $msgs) {
            foreach ($msgs as $m) {
                $this->UnregisterMessage($sender, $m);
            }
        }
        foreach ($this->geraete() as $g) {
            if ($g['Position'] > 0 && IPS_VariableExists($g['Position'])) {
                $this->RegisterMessage($g['Position'], VM_UPDATE);
            }
        }
        foreach (['WindID', 'RegenID', 'SperreID'] as $p) {
            $id = $this->ReadPropertyInteger($p);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
        $ok = $this->koordinaten() !== null && $this->geraete() !== [];
        $this->SetTimerInterval('Tick', $ok ? 60 * 1000 : 0);
        $this->SetStatus($this->koordinaten() === null ? 201 : 102);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Automatik') {
            $this->SetValue('Automatik', (bool)$Value);
            $this->Tick();
            return;
        }
        throw new Exception('Invalid Ident');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        if (in_array($SenderID, [$this->ReadPropertyInteger('WindID'), $this->ReadPropertyInteger('RegenID'), $this->ReadPropertyInteger('SperreID')], true)) {
            // Wetter reagiert sofort, nicht erst beim nächsten Minutentakt
            $this->wetterMerken();
            if ($this->wetterGeaendert()) {
                $this->Tick();
            }
            return;
        }
        if (!($Data[1] ?? false)) {
            return; // keine Änderung
        }
        foreach ($this->geraete() as $g) {
            if ($g['Position'] === $SenderID && !$this->istEcho($SenderID, $Data[0])) {
                $z = $this->zustand();
                $k = $g['key'];
                $z[$k]['hand'] = (bool)($z[$k]['soll'] ?? false);
                $z[$k]['grund'] = $this->t('Operated by hand — automatic resumes when the sun situation changes');
                $this->WriteAttributeString('Zustand', json_encode($z));
                $this->SendDebug('Hand', $g['Name'] . ' → ' . json_encode($Data[0]), 0);
            }
        }
    }

    // =====================================================================
    //  Regeltakt
    // =====================================================================

    public function Tick(): void
    {
        $pos = $this->koordinaten();
        if ($pos === null) {
            return;
        }
        $now = $this->now();
        $sonne = VSCHSonne::stand($pos[0], $pos[1], $now);
        $horizont = $this->horizontPunkte();
        $fassaden = $this->fassadenMap();
        $z = $this->zustand();
        $z['_f'] = (array)($z['_f'] ?? []);
        $this->wetterMerken();
        $hell = $this->hell($z);
        $warm = $this->warm($z);
        $warnungen = $this->warnungen();
        $wetterQuelle = $this->ReadPropertyInteger('WindID') > 0 || $warnungen !== null || $this->ReadPropertyBoolean('NetzWetter') || $this->ReadPropertyInteger('SperreID') > 0;
        $zeiten = ['an' => 60 * $this->ReadPropertyInteger('VerzoegerungAn'), 'aus' => 60 * $this->ReadPropertyInteger('VerzoegerungAus')];
        $statusZeilen = [];
        foreach ($this->geraete() as $g) {
            $f = $fassaden[$g['Fassade']] ?? null;
            if ($f === null || !$f['Aktiv'] || $g['Position'] <= 0 || !IPS_VariableExists($g['Position'])) {
                continue;
            }
            $hz = VSCHHorizont::hoehe($horizont, $sonne['az']);
            $fk = $g['Fassade'];
            $an = VSCHLogik::sonneAufFassade($sonne['az'], $sonne['alt'], (float)$f['Azimut'], $hz, (bool)($z['_f'][$fk] ?? false));
            $z['_f'][$fk] = $an;
            $wetter = VSCHLogik::wetter($g['Typ'], $this->wetterQuellen($g['Typ'], $warnungen), $now);
            $u = [
                'hell' => $hell, 'warm' => $warm, 'wetter' => $wetter, 'wetterQuelle' => $wetterQuelle,
                'ohneWetterBestaetigt' => $this->ReadPropertyBoolean('MarkiseOhneWetter'),
                'fensterOffen' => $g['Kontakt'] > 0 && IPS_VariableExists($g['Kontakt']) && $this->kontaktOffen($g['Kontakt']),
                'automatik' => (bool)$this->GetValue('Automatik'), 'tag' => $sonne['alt'] > 0,
            ];
            $ger = ['typ' => $g['Typ'], 'az' => (float)$f['Azimut'], 'sonneAn' => $an, 'lamellen' => $g['Lamelle'] > 0,
                'profil' => VSCHSonne::profilwinkel($sonne['alt'], $sonne['az'], (float)$f['Azimut'])];
            $k = $g['key'];
            $r = VSCHLogik::entscheide($ger, $u, (array)($z[$k] ?? []), $zeiten, $now);
            $neu = $r['z'];
            $neu['grund'] = $r['grund'];
            if ($r['ziel'] !== null) {
                $this->ausfuehren($g, $r['ziel'], $r['lamelle'], $neu, $now);
            }
            $z[$k] = $neu;
            $statusZeilen[] = $g['Name'] . ': ' . $r['grund'];
        }
        $z['_w'] = $this->wetterLage();
        $this->WriteAttributeString('Zustand', json_encode($z));
        $status = $statusZeilen === [] ? $this->t('No device assigned') : implode(' · ', array_slice($statusZeilen, 0, 4));
        if ($this->GetValue('Status') !== $status) {
            $this->SetValue('Status', $status);
        }
    }

    /** Befehl an ein Gerät: Behang auf Ziel, Lamellen nachführen. Wiederholt einmal, wenn ein Befehl verloren ging. */
    private function ausfuehren(array $g, string $ziel, ?float $lamelle, array &$z, int $now): void
    {
        $anteil = $ziel === 'beschatten' ? $g['Beschattung'] / 100 : 0.0;
        $wert = $this->positionWert($g, $anteil);
        $letzt = (array)($z['befehl'] ?? []);
        $gleich = ($letzt['ziel'] ?? '') === $ziel;
        $ist = GetValue($g['Position']);
        $weit = abs((float)$ist - $wert) > max(2.0, 0.03 * $this->maxWert($g['Position']));
        if (!$gleich || ($weit && $now - (int)($letzt['ts'] ?? 0) > self::NACHKONTROLLE_S && (int)($letzt['wdh'] ?? 0) < 1)) {
            if ($this->schalten($g['Position'], $wert)) {
                $z['befehl'] = ['ziel' => $ziel, 'ts' => $now, 'wdh' => $gleich ? (int)($letzt['wdh'] ?? 0) + 1 : 0];
            }
        }
        if ($lamelle !== null && $g['Lamelle'] > 0 && IPS_VariableExists($g['Lamelle'])) {
            if (abs($lamelle - (float)($z['lamelle'] ?? -99)) >= self::LAMELLE_SCHRITT || !$gleich) {
                $max = $this->maxWert($g['Lamelle']);
                $lw = $g['LamelleUmgekehrt'] ? $max * (1 - $lamelle / 90) : $max * $lamelle / 90;
                if ($this->schalten($g['Lamelle'], $lw)) {
                    $z['lamelle'] = round($lamelle, 1);
                }
            }
        }
    }

    // =====================================================================
    //  Eingänge
    // =====================================================================

    private function hell(array &$z): ?bool
    {
        $id = $this->ReadPropertyInteger('HelligkeitID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $v = (float)GetValue($id);
        $vorher = (bool)($z['_hell'] ?? false);
        $jetzt = $vorher ? $v > $this->ReadPropertyFloat('HellAus') : $v >= $this->ReadPropertyFloat('HellAn');
        $z['_hell'] = $jetzt;
        return $jetzt;
    }

    private function warm(array &$z): ?bool
    {
        $id = $this->ReadPropertyInteger('TemperaturID');
        $min = $this->ReadPropertyFloat('TemperaturMin');
        if ($id <= 0 || $min == 0.0 || !IPS_VariableExists($id)) {
            return null;
        }
        $v = (float)GetValue($id);
        $vorher = (bool)($z['_warm'] ?? false);
        $jetzt = $vorher ? $v > $min - 1 : $v >= $min;
        $z['_warm'] = $jetzt;
        return $jetzt;
    }

    /** Wind in km/h (Einheit aus Profil/Darstellung: m/s, mph, sonst km/h). */
    private function windKmh(): ?float
    {
        $id = $this->ReadPropertyInteger('WindID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $v = (float)GetValue($id);
        $einheit = mb_strtolower($this->einheit($id));
        if (strpos($einheit, 'm/s') !== false) {
            return $v * 3.6;
        }
        if (strpos($einheit, 'mph') !== false) {
            return $v * 1.609344;
        }
        if (strpos($einheit, 'kn') !== false) {
            return $v * 1.852;
        }
        return $v;
    }

    /** Merkt die letzte Überschreitung je Windschwelle und den letzten Regen (für die Haltezeit). */
    private function wetterMerken(): void
    {
        $wind = $this->windKmh();
        if ($wind !== null) {
            $seit = json_decode($this->ReadAttributeString('WindSeit'), true) ?: [];
            foreach (['markise' => 'WindMarkise', 'raffstore' => 'WindRaffstore'] as $typ => $p) {
                $s = $this->ReadPropertyFloat($p);
                if ($s > 0 && $wind >= $s) {
                    $seit[$typ] = $this->now();
                }
            }
            $this->WriteAttributeString('WindSeit', json_encode($seit));
        }
        if ($this->regen() === true) {
            $this->WriteAttributeInteger('RegenSeit', $this->now());
        }
    }

    /** Wetterlage je Typ nur aus Sensoren (für die Sofortreaktion; WarnHub kommt im Minutentakt). */
    private function wetterLage(): array
    {
        $neu = [];
        foreach (['markise', 'raffstore'] as $t) {
            $neu[$t] = VSCHLogik::wetter($t, $this->wetterQuellen($t, null), $this->now())['art'];
        }
        return $neu;
    }

    private function wetterGeaendert(): bool
    {
        $z = $this->zustand();
        $neu = $this->wetterLage();
        $alt = (array)($z['_w'] ?? []);
        if ($neu !== $alt) {
            $z['_w'] = $neu;
            $this->WriteAttributeString('Zustand', json_encode($z));
            return true;
        }
        return false;
    }

    private function regen(): ?bool
    {
        $id = $this->ReadPropertyInteger('RegenID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $v = GetValue($id);
        return is_bool($v) ? $v : (float)$v > 0;
    }

    private function wetterQuellen(string $typ, ?array $warnungen): array
    {
        $seit = json_decode($this->ReadAttributeString('WindSeit'), true) ?: [];
        $schwelle = $typ === 'markise' ? $this->ReadPropertyFloat('WindMarkise') : ($typ === 'raffstore' ? $this->ReadPropertyFloat('WindRaffstore') : 0.0);
        $sperre = $this->ReadPropertyInteger('SperreID');
        return [
            'wind' => $this->windKmh(), 'windSchwelle' => $schwelle, 'windSeit' => (int)($seit[$typ] ?? 0),
            'regen' => $this->regen(), 'regenSeit' => $this->ReadAttributeInteger('RegenSeit'),
            'sperre' => $sperre > 0 && IPS_VariableExists($sperre) ? (bool)GetValue($sperre) : null,
            'warnungen' => $warnungen ?? [], 'netzBoe' => $this->netzBoe(), 'halte' => 60 * $this->ReadPropertyInteger('Haltezeit'),
        ];
    }

    /**
     * Aktive Warnungen aus WarnHub (Vertrag GetActiveWarnings), nur feste Standorte (keine Autos).
     * Mit 'von' = Beginn minus Vorlauf der WarnHub-Schutzaktionen. null = kein WarnHub.
     */
    private function warnungen(): ?array
    {
        if (!$this->ReadPropertyBoolean('WarnHubNutzen') || !function_exists('WHUB_GetActiveWarnings')) {
            return null;
        }
        $ids = IPS_GetInstanceListByModuleID(self::WARNHUB_GUID);
        if ($ids === []) {
            return null;
        }
        $out = [];
        foreach ($ids as $id) {
            try {
                $j = json_decode((string)WHUB_GetActiveWarnings($id), true);
                $vorlauf = 60 * (int)@IPS_GetProperty($id, 'SchutzaktionVorlaufMinuten');
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($j)) {
                continue;
            }
            $fest = [];
            $system = [];
            foreach ((array)($j['standorte'] ?? []) as $s) {
                if (!($s['mobile'] ?? false) && ($s['active'] ?? true)) {
                    $fest[] = (string)$s['standortKey'];
                }
                if ($s['isSystemLocation'] ?? false) {
                    $system[] = (string)$s['standortKey'];
                }
            }
            $erlaubt = $system !== [] ? $system : $fest;
            foreach ((array)($j['warnings'] ?? []) as $w) {
                if (!in_array((string)($w['standortKey'] ?? ''), $erlaubt, true)) {
                    continue;
                }
                $w['von'] = isset($w['onsetTs']) && $w['onsetTs'] ? (int)$w['onsetTs'] - $vorlauf : 0;
                $w['bis'] = (int)($w['expiresTs'] ?? 0);
                $out[] = $w;
            }
        }
        return $out;
    }

    /** Böenvorhersage der nächsten Stunde (Open-Meteo), höchstens alle 15 min abgerufen. */
    private function netzBoe(): ?float
    {
        if (!$this->ReadPropertyBoolean('NetzWetter')) {
            return null;
        }
        $c = json_decode($this->ReadAttributeString('NetzBoe'), true) ?: [];
        if ($this->now() - (int)($c['ts'] ?? 0) < 900) {
            return isset($c['v']) ? (float)$c['v'] : null;
        }
        $pos = $this->koordinaten();
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . VSCHGeo::zahl($pos[0]) . '&longitude=' . VSCHGeo::zahl($pos[1])
            . '&current=wind_gusts_10m&hourly=wind_gusts_10m&forecast_hours=2&wind_speed_unit=kmh';
        [$code, $body] = $this->abruf($url, []);
        $j = $code === 200 ? json_decode($body, true) : null;
        $werte = array_merge([(float)($j['current']['wind_gusts_10m'] ?? 0)], array_map('floatval', (array)($j['hourly']['wind_gusts_10m'] ?? [])));
        $v = is_array($j) ? max($werte) : null;
        $this->WriteAttributeString('NetzBoe', json_encode(['ts' => $this->now(), 'v' => $v]));
        if ($v === null) {
            $this->LogMessage($this->t('Weather data from the internet not available (HTTP %d)', $code), KL_WARNING);
        }
        return $v;
    }

    private function kontaktOffen(int $id): bool
    {
        $v = GetValue($id);
        return is_bool($v) ? $v : (float)$v > 0;
    }

    // =====================================================================
    //  Geräte schalten
    // =====================================================================

    /** Gerätewert für „Anteil zu“ (0 = offen/eingefahren, 1 = ganz zu/ausgefahren). */
    private function positionWert(array $g, float $anteil): float
    {
        $max = $this->maxWert($g['Position']);
        $nullIstOffen = $this->nullIstOffen($g);
        return round($nullIstOffen ? $max * $anteil : $max * (1 - $anteil), 2);
    }

    /** Richtung: Spalte im Formular, sonst Profil (~Shutter: 0 = offen; ~Shutter.Reversed: 0 = zu), sonst 0 = offen. */
    private function nullIstOffen(array $g): bool
    {
        if ($g['Richtung'] === 1) {
            return true;
        }
        if ($g['Richtung'] === 2) {
            return false;
        }
        $p = mb_strtolower($this->profil($g['Position']));
        return strpos($p, 'reversed') === false;
    }

    private function schalten(int $vid, float $wert): bool
    {
        if (!IPS_VariableExists($vid)) {
            return false;
        }
        $v = IPS_GetVariable($vid);
        $wert = $v['VariableType'] === VARIABLETYPE_INTEGER ? (int)round($wert) : $wert;
        $g = json_decode($this->ReadAttributeString('Gesendet'), true) ?: [];
        $g[$vid] = [$wert, $this->now(), GetValue($vid)];
        $this->WriteAttributeString('Gesendet', json_encode($g));
        try {
            $ok = HasAction($vid) ? (bool)RequestAction($vid, $wert) : false;
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            $this->LogMessage($this->t('Command failed: %s → %s', $this->name($vid), (string)$wert), KL_WARNING);
        } else {
            $this->SendDebug('Befehl', $this->name($vid) . ' → ' . $wert, 0);
        }
        return $ok;
    }

    /** War die Wertänderung das Echo eines eigenen Befehls (Zielwert oder Zwischenwert auf dem Weg)? */
    private function istEcho(int $vid, $wert): bool
    {
        $g = json_decode($this->ReadAttributeString('Gesendet'), true) ?: [];
        $e = $g[$vid] ?? null;
        if (!is_array($e) || $this->now() - (int)$e[1] > self::ECHO_S) {
            return false;
        }
        $ziel = (float)$e[0];
        $von = (float)$e[2];
        return (float)$wert >= min($ziel, $von) - 0.6 && (float)$wert <= max($ziel, $von) + 0.6;
    }

    // =====================================================================
    //  Formular-Aktionen
    // =====================================================================

    /** „Haus erkennen“: Umriss, Fassaden, Horizont abrufen. Fassaden gehen nur in das offene Formular. */
    public function UIErkennen(): string
    {
        $pos = $this->koordinaten();
        if ($pos === null) {
            return $this->t('No location: please set the location in the Location instance or choose one here.');
        }
        $haus = VSCHGeo::erkennen($pos[0], $pos[1], function (string $url, array $kopf = [], ?string $post = null) {
            return $this->abruf($url, $kopf, $post);
        });
        $hor = VSCHHorizont::pvgis($pos[0], $pos[1], function (string $url, array $kopf = []) {
            return $this->abruf($url, $kopf);
        });
        if ($hor['ok']) {
            $this->WriteAttributeString('Horizont', json_encode(['punkte' => $hor['punkte'], 'quelle' => 'PVGIS © European Union', 'ts' => $this->now()]));
        }
        if (!$haus['ok']) {
            $text = $this->t('No house found: %s', $haus['meldung']) . ' ' . $this->t('You can enter the facades by hand in the list.');
            $this->UpdateFormField('GeoStatus', 'caption', '⛔ ' . $text);
            return $text;
        }
        $this->WriteAttributeString('Haus', json_encode([
            'eigen' => $haus['eigen'], 'nachbarn' => $haus['nachbarn'], 'quelle' => $haus['quelle'], 'lizenz' => $haus['lizenz'],
            'land' => $haus['land'], 'ts' => $this->now(), 'fassaden' => $haus['fassaden'], 'lat' => $pos[0], 'lon' => $pos[1],
        ]));
        $werte = [];
        foreach ($haus['fassaden'] as $i => $f) {
            $werte[] = ['Kennung' => VSCHGrafik::kennung($i), 'Name' => $this->richtungName((float)$f['az']), 'Azimut' => $f['az'], 'Aktiv' => true];
        }
        $this->UpdateFormField('Fassaden', 'values', json_encode($werte));
        $this->UpdateFormField('GeoStatus', 'caption', '🔗 ' . $this->geoStatus());
        $this->UpdateFormField('Grundriss', 'image', $this->grundrissBild($werte, 0, 'heute'));
        $this->UpdateFormField('Himmel', 'image', $this->himmelBild($werte, 0, 'heute'));
        $this->UpdateFormField('Vorschau', 'caption', $this->vorschauText($werte, 0, 'heute'));
        return $this->t('%d facades found. Please check them in the list and confirm with "Apply changes".', count($werte));
    }

    /** Vorschau neu zeichnen (Fassade/Tag gewählt oder Liste geändert). */
    public function UIVorschau(string $fassadenJson, int $auswahl, string $tag): void
    {
        $werte = $this->listeWerte($fassadenJson);
        $this->UpdateFormField('Grundriss', 'image', $this->grundrissBild($werte, $auswahl, $tag));
        $this->UpdateFormField('Himmel', 'image', $this->himmelBild($werte, $auswahl, $tag));
        $this->UpdateFormField('Vorschau', 'caption', $this->vorschauText($werte, $auswahl, $tag));
    }

    /** Geräte-Vorschläge: Variablen mit Rollladen-Profil oder passendem Namen, schaltbar, noch nicht zugeordnet. */
    public function UIVorschlaege(string $geraeteJson): void
    {
        $vergeben = array_map(function ($g) {
            return (int)($g['Position'] ?? 0);
        }, $this->listeWerte($geraeteJson));
        $vorschlaege = [];
        foreach (IPS_GetVariableList() as $vid) {
            if (in_array($vid, $vergeben, true) || !HasAction($vid)) {
                continue;
            }
            $v = IPS_GetVariable($vid);
            if (!in_array($v['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                continue;
            }
            $p = mb_strtolower($this->profil($vid));
            $n = $this->name($vid);
            $typ = $this->typRaten($n . ' ' . $p);
            if (strpos($p, 'shutter') === false && $typ === null) {
                continue;
            }
            $vorschlaege[] = ['Nehmen' => false, 'Name' => $n, 'Typ' => $typ ?? 'rollladen', 'Position' => $vid, 'Lamelle' => $this->lamelleFinden($vid)];
            if (count($vorschlaege) >= 60) {
                break;
            }
        }
        $this->UpdateFormField('Vorschlaege', 'values', json_encode($vorschlaege));
        $this->UpdateFormField('VorschlaegeHint', 'caption', $vorschlaege === []
            ? $this->t('No candidates found. Add devices by hand with "+" in the list.')
            : $this->t('%d candidates. Tick the ones that belong to this facade.', count($vorschlaege)));
    }

    /** Angehakte Vorschläge der gewählten Fassade in die Geräteliste übernehmen (nur offenes Formular). */
    public function UIUebernehmen(string $vorschlaegeJson, string $geraeteJson, string $fassade): string
    {
        $geraete = $this->listeWerte($geraeteJson);
        $neu = 0;
        foreach ($this->listeWerte($vorschlaegeJson) as $v) {
            if (!($v['Nehmen'] ?? false)) {
                continue;
            }
            $geraete[] = ['Name' => (string)$v['Name'], 'Fassade' => $fassade, 'Typ' => (string)$v['Typ'], 'Position' => (int)$v['Position'],
                'Richtung' => 0, 'Lamelle' => (int)($v['Lamelle'] ?? 0), 'LamelleUmgekehrt' => false, 'Kontakt' => 0, 'Beschattung' => 0];
            $neu++;
        }
        $this->UpdateFormField('Geraete', 'values', json_encode($geraete));
        return $this->t('%d devices added to facade %s. Confirm with "Apply changes".', $neu, $fassade);
    }

    /** Probefahrt: alle Geräte einer Fassade auf Beschattung bzw. zurück. Zeigt so, ob die Richtung stimmt. */
    public function UIProbefahrt(string $fassade, bool $beschatten): string
    {
        $n = 0;
        foreach ($this->geraete() as $g) {
            if ($g['Fassade'] !== $fassade) {
                continue;
            }
            $z = [];
            $this->ausfuehren($g, $beschatten ? 'beschatten' : 'offen', $beschatten && $g['Lamelle'] > 0 ? 45.0 : null, $z, $this->now());
            $n++;
        }
        return $n === 0
            ? $this->t('Facade %s has no saved devices yet (apply changes first).', $fassade)
            : $this->t('%d devices of facade %s are moving. If one moves the wrong way, change its "0 %% means" column.', $n, $fassade);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    /** Zustand aller Geräte als JSON (für Skripte, Kachel, Fehlersuche). */
    public function GetState(): string
    {
        $z = $this->zustand();
        $out = [];
        foreach ($this->geraete() as $g) {
            $s = (array)($z[$g['key']] ?? []);
            $out[] = ['name' => $g['Name'], 'facade' => $g['Fassade'], 'type' => $g['Typ'], 'positionID' => $g['Position'],
                'shading' => (bool)($s['soll'] ?? false), 'manual' => ($s['hand'] ?? null) !== null, 'reason' => (string)($s['grund'] ?? '')];
        }
        return json_encode(['version' => '1.0', 'devices' => $out]);
    }

    // =====================================================================
    //  Formular
    // =====================================================================

    public function GetConfigurationForm()
    {
        return VSCHText::forForm(function () {
            return json_encode($this->formular());
        });
    }

    private function formular(): array
    {
        $fassaden = $this->listeWerte($this->ReadPropertyString('Fassaden'));
        $optFassade = array_map(function ($f) {
            return ['caption' => $f['Kennung'] . ' · ' . $f['Name'], 'value' => $f['Kennung']];
        }, $fassaden);
        if ($optFassade === []) {
            $optFassade = [['caption' => '—', 'value' => '']];
        }
        $optTyp = [
            ['caption' => $this->t('Roller shutter'), 'value' => 'rollladen'],
            ['caption' => $this->t('Venetian blind'), 'value' => 'raffstore'],
            ['caption' => $this->t('Awning'), 'value' => 'markise'],
        ];
        $pos = $this->koordinaten();
        $standortText = $pos === null
            ? $this->t('No location set. Set it in the Location instance (core instances) or choose one below.')
            : $this->t('Location: %s° N, %s° E (%s)', number_format($pos[0], 4, ',', ''), number_format($pos[1], 4, ',', ''),
                $this->eigenerStandort() ? $this->t('chosen here') : $this->t('from the Location instance'));
        $tage = [['caption' => $this->t('Today'), 'value' => 'heute'], ['caption' => $this->t('21 June'), 'value' => 'juni'], ['caption' => $this->t('21 December'), 'value' => 'dezember']];
        // Listen kommen im Skript als Symcon-Listenobjekt an: erst in ein Array kopieren (Muster aus Automat, live bewährt)
        $vorschauAufruf = '$f = []; foreach ($Fassaden as $x) { $f[] = $x; } VSCH_UIVorschau($id, json_encode($f), (int)$VorschauFassade, (string)$VorschauTag);';

        $elements = [];
        if (!$this->ReadAttributeBoolean('PurposeIntroGone')) {
            $elements[] = ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  What is this module for?', 'items' => [
                ['type' => 'Label', 'caption' => 'Lowers roller shutters, venetian blinds and awnings when the sun shines on their facade, and raises them again afterwards. Venetian blind slats follow the sun.'],
                ['type' => 'Label', 'caption' => 'You do not enter angles: the module finds your house in official map data, shows its facades, and you only assign the devices.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'VSCH_AckPurposeIntro($id);'],
            ]];
        }
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '📖  Documentation & help', 'items' => [
            ['type' => 'Label', 'caption' => 'Setup in four steps: 1. check the location, 2. "Detect house", 3. assign devices per facade, 4. check the preview. Weather protection is mandatory for awnings.'],
            ['type' => 'Label', 'caption' => 'Manual operation has priority: if you move a device by hand, the module leaves it alone until the sun situation changes.'],
            ['type' => 'Label', 'caption' => 'The module only moves back what it has moved itself, and only in daylight. Closing at dusk stays with you or another automation.'],
            ['type' => 'Button', 'caption' => 'Open documentation', 'onClick' => 'echo "' . self::DOC_URL . '";', 'link' => true],
        ]];

        // 1. Standort & Geodaten
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '📍  Location & map data', 'expanded' => $fassaden === [], 'items' => [
            ['type' => 'Label', 'name' => 'StandortText', 'caption' => $standortText],
            ['type' => 'SelectLocation', 'name' => 'Standort', 'caption' => 'Own location (optional)'],
            ['type' => 'Label', 'name' => 'StandortHint', 'caption' => 'Leave empty to use the Location instance. Set it here if the house is not exactly at that point.'],
            ['type' => 'Button', 'caption' => '🏠 Detect house from map data', 'onClick' => 'echo VSCH_UIErkennen($id);'],
            ['type' => 'Label', 'name' => 'GeoStatus', 'caption' => $this->geoStatus() !== '' ? '🔗 ' . $this->geoStatus() : $this->t('Not detected yet.')],
            ['type' => 'Label', 'caption' => 'Privacy: only when you click, the coordinate goes to the map service of your federal state or country, to OpenStreetMap and to PVGIS (EU). Only derived values are stored: facade directions, neighbouring buildings and the horizon line.'],
            ['type' => 'Label', 'name' => 'Quellen', 'caption' => $this->quellenText()],
        ]];

        // 2. Mein Haus
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '🏠  My house', 'expanded' => true, 'items' => [
            ['type' => 'Image', 'name' => 'Grundriss', 'image' => $this->grundrissBild($fassaden, 0, 'heute'), 'center' => true, 'width' => '420px'],
            ['type' => 'List', 'name' => 'Fassaden', 'caption' => 'Facades', 'rowCount' => max(3, count($fassaden)), 'add' => true, 'delete' => true,
                'columns' => [
                    ['caption' => 'Letter', 'name' => 'Kennung', 'width' => '70px', 'add' => '?', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Name', 'name' => 'Name', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Direction (°)', 'name' => 'Azimut', 'width' => '120px', 'add' => 180, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 359, 'digits' => 1]],
                    ['caption' => 'Active', 'name' => 'Aktiv', 'width' => '80px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                ]],
            ['type' => 'Label', 'name' => 'FassadenHint', 'caption' => 'Direction = view out of the window: 0° north, 90° east, 180° south, 270° west. Correct it if the detection is wrong, or add facades by hand.'],
            ['type' => 'List', 'name' => 'Geraete', 'caption' => 'Devices', 'rowCount' => 6, 'add' => true, 'delete' => true, 'sort' => ['column' => 'Fassade', 'direction' => 'ascending'],
                'columns' => [
                    ['caption' => 'Name', 'name' => 'Name', 'width' => '180px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Facade', 'name' => 'Fassade', 'width' => '110px', 'add' => $optFassade[0]['value'], 'edit' => ['type' => 'Select', 'options' => $optFassade]],
                    ['caption' => 'Type', 'name' => 'Typ', 'width' => '150px', 'add' => 'rollladen', 'edit' => ['type' => 'Select', 'options' => $optTyp]],
                    ['caption' => 'Position', 'name' => 'Position', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => '0 % means', 'name' => 'Richtung', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => [
                        ['caption' => 'automatic (profile)', 'value' => 0], ['caption' => 'open / retracted', 'value' => 1], ['caption' => 'closed / extended', 'value' => 2]]]],
                    ['caption' => 'Slats (optional)', 'name' => 'Lamelle', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Slats 0 % = closed', 'name' => 'LamelleUmgekehrt', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'Window/door contact', 'name' => 'Kontakt', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Shading position (%)', 'name' => 'Beschattung', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 100]],
                ]],
            ['type' => 'Label', 'name' => 'GeraeteHint', 'caption' => 'Any device with a position variable works (e.g. Z-Wave, Shelly, KNX, Homematic, EnOcean, Zigbee). Shading position 0 = default of the type (roller shutter 75 %, venetian blind and awning 100 %). Use the test run to check the direction.'],
        ]];

        // 4. Wetterschutz
        $warnHub = function_exists('WHUB_GetActiveWarnings') && IPS_GetInstanceListByModuleID(self::WARNHUB_GUID) !== [];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '🌬️  Weather protection', 'items' => [
            ['type' => 'Label', 'caption' => $this->wetterStatus($warnHub)],
            ['type' => 'Label', 'caption' => 'Awnings and venetian blinds retract on wind and severe weather — this has priority over everything, also over manual operation. Roller shutters are not moved by wind.'],
            ['type' => 'SelectVariable', 'name' => 'WindID', 'caption' => 'Wind gust (own sensor)'],
            ['type' => 'Label', 'name' => 'WindIDHint', 'caption' => 'Wind speed or gust of your weather station or wind sensor. The unit (km/h, m/s, mph) is read from the profile.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'WindMarkise', 'caption' => 'Awning: retract from', 'suffix' => 'km/h', 'minimum' => 0, 'maximum' => 150],
                ['type' => 'NumberSpinner', 'name' => 'WindRaffstore', 'caption' => 'Venetian blind: retract from', 'suffix' => 'km/h', 'minimum' => 0, 'maximum' => 150],
                ['type' => 'NumberSpinner', 'name' => 'Haltezeit', 'caption' => 'Wait after wind/rain', 'suffix' => 'min', 'minimum' => 1, 'maximum' => 240],
            ]],
            ['type' => 'Label', 'name' => 'WindMarkiseHint', 'caption' => 'Defaults: awning 35 km/h (just below wind resistance class 2), venetian blind 50 km/h. Check the data sheet of your product.'],
            ['type' => 'SelectVariable', 'name' => 'RegenID', 'caption' => 'Rain sensor (optional)'],
            ['type' => 'Label', 'name' => 'RegenIDHint', 'caption' => 'Retracts awnings when it rains (true or a value above 0).'],
            ['type' => 'SelectVariable', 'name' => 'SperreID', 'caption' => 'External lock (optional)'],
            ['type' => 'Label', 'name' => 'SperreIDHint', 'caption' => 'A variable that is "true" while another system reports danger, e.g. the wind alarm of a KNX weather station.'],
            ['type' => 'CheckBox', 'name' => 'WarnHubNutzen', 'caption' => 'Use WarnHub warnings'],
            ['type' => 'Label', 'name' => 'WarnHubNutzenHint', 'caption' => $warnHub
                ? self::fest('WarnHub found. Storm, hail and thunderstorm warnings for your fixed locations retract awnings and venetian blinds, from the lead time of the WarnHub protective actions on. Frost and snow keep them still. If WarnHub evaluates your weather station, do not select it here as well.')
                : self::fest('WarnHub is not installed. It is optional: official warnings (DWD, GeoSphere, MeteoSwiss) are an additional safety net.')],
            ['type' => 'CheckBox', 'name' => 'NetzWetter', 'caption' => 'Gust forecast from the internet'],
            ['type' => 'Label', 'name' => 'NetzWetterHint', 'caption' => 'Open-Meteo, every 15 minutes. Slower than a sensor and does not replace one: the limit is 10 km/h lower than for the sensor.'],
            ['type' => 'CheckBox', 'name' => 'MarkiseOhneWetter', 'caption' => 'Extend awnings without weather source'],
            ['type' => 'Label', 'name' => 'MarkiseOhneWetterHint', 'caption' => 'Without any weather source awnings never extend automatically. Tick this only if you accept the risk (e.g. a sheltered awning).'],
        ]];

        // 5. Feinheiten
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '🌡️  Fine tuning', 'items' => [
            ['type' => 'SelectVariable', 'name' => 'HelligkeitID', 'caption' => 'Brightness (optional)'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'HellAn', 'caption' => 'Shade from', 'suffix' => 'lx', 'minimum' => 0, 'maximum' => 200000],
                ['type' => 'NumberSpinner', 'name' => 'HellAus', 'caption' => 'End below', 'suffix' => 'lx', 'minimum' => 0, 'maximum' => 200000],
            ]],
            ['type' => 'Label', 'name' => 'HelligkeitIDHint', 'caption' => 'Without a brightness sensor the module shades whenever the sun is on the facade, also when it is cloudy.'],
            ['type' => 'SelectVariable', 'name' => 'TemperaturID', 'caption' => 'Temperature (optional)'],
            ['type' => 'NumberSpinner', 'name' => 'TemperaturMin', 'caption' => 'Shade only from', 'suffix' => '°C', 'digits' => 1, 'minimum' => 0, 'maximum' => 40],
            ['type' => 'Label', 'name' => 'TemperaturMinHint', 'caption' => '0 = always. With an indoor or outdoor temperature, e.g. 22 °C, the sun can heat the house in winter.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'VerzoegerungAn', 'caption' => 'Delay before shading', 'suffix' => 'min', 'minimum' => 0, 'maximum' => 60],
                ['type' => 'NumberSpinner', 'name' => 'VerzoegerungAus', 'caption' => 'Delay before ending', 'suffix' => 'min', 'minimum' => 0, 'maximum' => 120],
            ]],
            ['type' => 'Label', 'name' => 'VerzoegerungAnHint', 'caption' => 'Prevents moving back and forth with passing clouds.'],
        ]];

        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '⚖️  License', 'items' => [
            ['type' => 'Label', 'caption' => 'DG65-Toolkit Verschattung — PolyForm Noncommercial 1.0.0. Free for private use.'],
            ['type' => 'Button', 'caption' => 'Show license', 'onClick' => 'echo "' . self::LICENSE_URL . '";', 'link' => true],
        ]];

        $actions = [];
        $actions[] = ['type' => 'ExpansionPanel', 'caption' => '🔧  Assign devices & test run', 'expanded' => $fassaden !== [] && $this->geraete() === [], 'items' => [
            ['type' => 'Label', 'caption' => 'Choose a facade, add its devices from the suggestions, then check the direction with the test run (only saved devices move).'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'ZuordnenFassade', 'caption' => 'Facade', 'options' => $optFassade],
                ['type' => 'PopupButton', 'caption' => '➕ Add devices …', 'popup' => [
                    'caption' => 'Devices for this facade',
                    'items' => [
                        // onClick am PopupButton ist in der SDK-Doku nicht belegt → eigener Suchknopf im Popup
                        ['type' => 'Button', 'caption' => '🔍 Find devices', 'onClick' => '$g = []; foreach ($Geraete as $x) { $g[] = $x; } VSCH_UIVorschlaege($id, json_encode($g));'],
                        ['type' => 'Label', 'name' => 'VorschlaegeHint', 'caption' => 'Click "Find devices" to list candidates.'],
                        ['type' => 'List', 'name' => 'Vorschlaege', 'rowCount' => 10, 'add' => false, 'delete' => false, 'columns' => [
                            ['caption' => 'Take', 'name' => 'Nehmen', 'width' => '70px', 'edit' => ['type' => 'CheckBox']],
                            ['caption' => 'Name', 'name' => 'Name', 'width' => 'auto', 'save' => true],
                            ['caption' => 'Type', 'name' => 'Typ', 'width' => '160px', 'edit' => ['type' => 'Select', 'options' => $optTyp]],
                            ['caption' => 'Position', 'name' => 'Position', 'width' => '90px', 'save' => true],
                            ['caption' => 'Slats', 'name' => 'Lamelle', 'width' => '90px', 'save' => true],
                        ], 'values' => []],
                        ['type' => 'Button', 'caption' => 'Add ticked devices', 'onClick' => '$v = []; foreach ($Vorschlaege as $x) { $v[] = $x; } $g = []; foreach ($Geraete as $x) { $g[] = $x; } echo VSCH_UIUebernehmen($id, json_encode($v), json_encode($g), (string)$ZuordnenFassade);'],
                    ],
                ]],
                ['type' => 'Button', 'caption' => '⬇ Test run', 'onClick' => 'echo VSCH_UIProbefahrt($id, (string)$ZuordnenFassade, true);'],
                ['type' => 'Button', 'caption' => '⬆ Back', 'onClick' => 'echo VSCH_UIProbefahrt($id, (string)$ZuordnenFassade, false);'],
            ]],
        ]];
        // Vorschau (im Aktionsbereich: Auswahlfelder ohne Eigenschaft dürfen nicht in elements, SUITE.md Formular-Optik 3a)
        $actions[] = ['type' => 'ExpansionPanel', 'caption' => '☀️  Preview', 'items' => [
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'VorschauFassade', 'caption' => 'Facade', 'value' => 0, 'options' => array_map(function ($o, $i) {
                    return ['caption' => $o['caption'], 'value' => $i];
                }, $optFassade, array_keys($optFassade)), 'onChange' => $vorschauAufruf],
                ['type' => 'Select', 'name' => 'VorschauTag', 'caption' => 'Day', 'value' => 'heute', 'options' => $tage, 'onChange' => $vorschauAufruf],
                ['type' => 'Button', 'caption' => 'Redraw', 'onClick' => $vorschauAufruf],
            ]],
            ['type' => 'Image', 'name' => 'Himmel', 'image' => $this->himmelBild($fassaden, 0, 'heute'), 'center' => true, 'width' => '560px'],
            ['type' => 'Label', 'name' => 'Vorschau', 'caption' => $this->vorschauText($fassaden, 0, 'heute')],
            ['type' => 'Label', 'caption' => 'Grey: terrain horizon (PVGIS, drawn three times higher). Coloured band: the part of the sky from which the chosen facade gets sun. Whether shading actually moves then depends on brightness and temperature.'],
        ]];

        $actions[] = ['type' => 'Label', 'caption' => $this->t('Current state: %s', (string)@$this->GetValue('Status'))];

        return [
            'elements' => $elements,
            'actions' => $actions,
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Active'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'No location: set it in the Location instance or here'],
            ],
        ];
    }

    // =====================================================================
    //  Grafik und Texte fürs Formular
    // =====================================================================

    private function grundrissBild(array $fassaden, int $auswahl, string $tag): string
    {
        $haus = json_decode($this->ReadAttributeString('Haus'), true) ?: [];
        $pos = $this->koordinaten();
        $bahn = $pos === null ? [] : VSCHSonne::bahn($pos[0], $pos[1], $this->tagDatum($tag), date_default_timezone_get(), 10);
        return VSCHGrafik::grundriss($haus, $this->fassadenMitKanten($fassaden, $haus), $bahn, $auswahl, date_default_timezone_get());
    }

    private function himmelBild(array $fassaden, int $auswahl, string $tag): string
    {
        $pos = $this->koordinaten();
        $bahnen = [];
        if ($pos !== null) {
            $tz = date_default_timezone_get();
            foreach (array_unique([$tag, 'heute', 'juni', 'dezember']) as $t) {
                $bahnen[$this->tagName($t)] = VSCHSonne::bahn($pos[0], $pos[1], $this->tagDatum($t), $tz, 10);
            }
        }
        $f = $fassaden[$auswahl] ?? null;
        return VSCHGrafik::himmel($bahnen, $this->horizontPunkte(), $f !== null ? (float)$f['Azimut'] : null,
            VSCHGrafik::FARBEN[$auswahl % count(VSCHGrafik::FARBEN)], date_default_timezone_get(), $this->t('Horizon (3× higher)'));
    }

    private function vorschauText(array $fassaden, int $auswahl, string $tag): string
    {
        $pos = $this->koordinaten();
        if ($pos === null || $fassaden === []) {
            return $this->t('The preview appears once facades are known.');
        }
        $bahn = VSCHSonne::bahn($pos[0], $pos[1], $this->tagDatum($tag), date_default_timezone_get(), 5);
        $teile = [];
        foreach ($fassaden as $i => $f) {
            $fenster = VSCHGrafik::sonnenfenster($bahn, $this->horizontPunkte(), (float)$f['Azimut']);
            $zeit = $fenster === [] ? $this->t('no direct sun') : implode(' + ', array_map(function ($w) {
                return date('H:i', $w[0]) . '–' . date('H:i', $w[1]);
            }, $fenster));
            $teile[] = ($i === $auswahl ? '▶ ' : '') . $f['Kennung'] . ' ' . $f['Name'] . ': ' . $zeit;
        }
        return $this->t('Sun on the facade (%s): %s', $this->tagName($tag), implode(' · ', $teile));
    }

    /** Kanten aus der Erkennung den Listeneinträgen zuordnen (gleiche Kennung, sonst nächster Azimut). */
    private function fassadenMitKanten(array $fassaden, array $haus): array
    {
        $erkannt = (array)($haus['fassaden'] ?? []);
        $out = [];
        foreach ($fassaden as $f) {
            $seg = [];
            $best = 25.0;
            foreach ($erkannt as $e) {
                $d = VSCHSonne::winkelAbstand((float)$e['az'], (float)$f['Azimut']);
                if ($d < $best) {
                    $best = $d;
                    $seg = (array)$e['seg'];
                }
            }
            $out[] = ['az' => (float)$f['Azimut'], 'seg' => $seg];
        }
        return $out;
    }

    private function geoStatus(): string
    {
        $h = json_decode($this->ReadAttributeString('Haus'), true) ?: [];
        if (($h['ts'] ?? 0) === 0) {
            return '';
        }
        $hor = json_decode($this->ReadAttributeString('Horizont'), true) ?: [];
        $max = 0.0;
        foreach ((array)($hor['punkte'] ?? []) as $p) {
            $max = max($max, (float)$p[1]);
        }
        return $this->t('%s · %d neighbouring buildings · horizon up to %s° · %s', (string)$h['quelle'], count((array)$h['nachbarn']),
            number_format($max, 1, ',', ''), date('d.m.Y H:i', (int)$h['ts']));
    }

    private function quellenText(): string
    {
        $h = json_decode($this->ReadAttributeString('Haus'), true) ?: [];
        $q = array_filter([(string)($h['lizenz'] ?? ''), ($this->horizontPunkte() !== [] ? 'PVGIS © European Union' : '')]);
        return $q === [] ? $this->t('Sources are shown here after detection.') : $this->t('Sources: %s', implode(' · ', $q));
    }

    private function wetterStatus(bool $warnHub): string
    {
        $quellen = [];
        if ($this->ReadPropertyInteger('WindID') > 0) {
            $w = $this->windKmh();
            $quellen[] = $this->t('wind sensor (%s km/h)', $w === null ? '?' : VSCHLogik::zahl($w));
        }
        if ($this->ReadPropertyInteger('RegenID') > 0) {
            $quellen[] = $this->t('rain sensor');
        }
        if ($this->ReadPropertyInteger('SperreID') > 0) {
            $quellen[] = $this->t('external lock');
        }
        if ($warnHub && $this->ReadPropertyBoolean('WarnHubNutzen')) {
            $quellen[] = 'WarnHub';
        }
        if ($this->ReadPropertyBoolean('NetzWetter')) {
            $quellen[] = $this->t('gust forecast');
        }
        if ($quellen === []) {
            return '⚠️ ' . $this->t('No weather source: awnings do not extend automatically.');
        }
        return '🔗 ' . $this->t('Weather sources: %s', implode(', ', $quellen));
    }

    private function richtungName(float $az): string
    {
        $n = [self::fest('North'), self::fest('North-east'), self::fest('East'), self::fest('South-east'), self::fest('South'),
            self::fest('South-west'), self::fest('West'), self::fest('North-west')];
        return $this->t($n[(int)round($az / 45) % 8]);
    }

    private function tagDatum(string $tag): string
    {
        $jahr = date('Y');
        return $tag === 'juni' ? $jahr . '-06-21' : ($tag === 'dezember' ? $jahr . '-12-21' : date('Y-m-d'));
    }

    private function tagName(string $tag): string
    {
        return $tag === 'juni' ? $this->t('21 June') : ($tag === 'dezember' ? $this->t('21 December') : $this->t('today'));
    }

    // =====================================================================
    //  Hilfen
    // =====================================================================

    /** [lat, lon] aus eigener Auswahl oder Standort-Instanz; null = keiner. */
    private function koordinaten(): ?array
    {
        $eigen = json_decode($this->ReadPropertyString('Standort'), true);
        if (is_array($eigen) && ((float)($eigen['latitude'] ?? 0) != 0.0 || (float)($eigen['longitude'] ?? 0) != 0.0)) {
            return [(float)$eigen['latitude'], (float)$eigen['longitude']];
        }
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL_GUID) as $id) {
            $l = json_decode((string)@IPS_GetProperty($id, 'Location'), true);
            if (is_array($l) && ((float)($l['latitude'] ?? 0) != 0.0 || (float)($l['longitude'] ?? 0) != 0.0)) {
                return [(float)$l['latitude'], (float)$l['longitude']];
            }
        }
        return null;
    }

    private function eigenerStandort(): bool
    {
        $eigen = json_decode($this->ReadPropertyString('Standort'), true);
        return is_array($eigen) && ((float)($eigen['latitude'] ?? 0) != 0.0 || (float)($eigen['longitude'] ?? 0) != 0.0);
    }

    private function horizontPunkte(): array
    {
        $h = json_decode($this->ReadAttributeString('Horizont'), true) ?: [];
        return (array)($h['punkte'] ?? []);
    }

    /** Fassaden nach Kennung. */
    private function fassadenMap(): array
    {
        $out = [];
        foreach ($this->listeWerte($this->ReadPropertyString('Fassaden')) as $f) {
            $out[(string)$f['Kennung']] = $f + ['Aktiv' => true, 'Azimut' => 180];
        }
        return $out;
    }

    /** Geräte mit vollständigen Feldern und Schlüssel (Positions-ID, bleibt beim Umsortieren gleich). */
    private function geraete(): array
    {
        $out = [];
        foreach ($this->listeWerte($this->ReadPropertyString('Geraete')) as $g) {
            $typ = in_array($g['Typ'] ?? '', VSCHLogik::TYPEN, true) ? $g['Typ'] : 'rollladen';
            $besch = (float)($g['Beschattung'] ?? 0);
            $out[] = [
                'Name' => (string)($g['Name'] ?? '') !== '' ? (string)$g['Name'] : $this->name((int)($g['Position'] ?? 0)),
                'Fassade' => (string)($g['Fassade'] ?? ''), 'Typ' => $typ, 'Position' => (int)($g['Position'] ?? 0),
                'Richtung' => (int)($g['Richtung'] ?? 0), 'Lamelle' => (int)($g['Lamelle'] ?? 0),
                'LamelleUmgekehrt' => (bool)($g['LamelleUmgekehrt'] ?? false), 'Kontakt' => (int)($g['Kontakt'] ?? 0),
                'Beschattung' => $besch > 0 ? $besch : VSCHLogik::VORGABEN[$typ]['beschattung'],
                'key' => 'g' . (int)($g['Position'] ?? 0),
            ];
        }
        return $out;
    }

    private function zustand(): array
    {
        return json_decode($this->ReadAttributeString('Zustand'), true) ?: [];
    }

    /** Listenwerte aus Formular/Eigenschaft (auch als „Liste von Objekten“ mit Zusatzfeldern). */
    private function listeWerte(string $json): array
    {
        $j = json_decode($json, true);
        return is_array($j) ? array_values(array_filter($j, 'is_array')) : [];
    }

    private function profil(int $vid): string
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return '';
        }
        $v = IPS_GetVariable($vid);
        return (string)($v['VariableCustomProfile'] ?: $v['VariableProfile']);
    }

    private function einheit(int $vid): string
    {
        $p = $this->profil($vid);
        if ($p !== '' && IPS_VariableProfileExists($p)) {
            return (string)IPS_GetVariableProfile($p)['Suffix'];
        }
        $v = IPS_GetVariable($vid);
        $pres = $v['VariableCustomPresentation'] ?? [];
        if (!is_array($pres) || $pres === []) {
            $pres = $v['VariablePresentation'] ?? [];
        }
        return (string)($pres['SUFFIX'] ?? '');
    }

    private function maxWert(int $vid): float
    {
        $p = $this->profil($vid);
        if ($p !== '' && IPS_VariableProfileExists($p)) {
            $m = (float)IPS_GetVariableProfile($p)['MaxValue'];
            if ($m > 0) {
                return $m;
            }
        }
        $v = IPS_GetVariable($vid);
        $pres = $v['VariableCustomPresentation'] ?? [];
        if (!is_array($pres) || $pres === []) {
            $pres = $v['VariablePresentation'] ?? [];
        }
        return isset($pres['MAX']) && (float)$pres['MAX'] > 0 ? (float)$pres['MAX'] : 100.0;
    }

    private function name(int $vid): string
    {
        if ($vid <= 0 || !IPS_ObjectExists($vid)) {
            return '#' . $vid;
        }
        $p = IPS_GetParent($vid);
        $n = IPS_GetName($vid);
        return $p > 0 && IPS_InstanceExists($p) ? IPS_GetName($p) . ' / ' . $n : $n;
    }

    private function typRaten(string $text): ?string
    {
        $t = mb_strtolower($text);
        if (preg_match('/markise|awning|sonnensegel/', $t)) {
            return 'markise';
        }
        if (preg_match('/raffstore|jalousie|venetian|lamell|blind/', $t)) {
            return 'raffstore';
        }
        if (preg_match('/rollladen|rolladen|rollo|shutter|rolltor/', $t)) {
            return 'rollladen';
        }
        return null;
    }

    /** Lamellenvariable neben der Positionsvariable (gleiche Instanz, Name/Ident mit Lamelle/Slat/Tilt/Winkel). */
    private function lamelleFinden(int $vid): int
    {
        $p = IPS_GetParent($vid);
        foreach (IPS_GetChildrenIDs($p) as $c) {
            if ($c !== $vid && IPS_VariableExists($c) && HasAction($c)) {
                $o = IPS_GetObject($c);
                if (preg_match('/lamell|slat|tilt|winkel|angle/i', $o['ObjectName'] . ' ' . $o['ObjectIdent'])) {
                    return $c;
                }
            }
        }
        return 0;
    }

    /** HTTP-Abruf mit generischem User-Agent (keine Kontaktdaten des Nutzers). */
    private function abruf(string $url, array $kopf = [], ?string $post = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => strpos($url, 'overpass') !== false ? 75 : 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => VSCHGeo::USER_AGENT, CURLOPT_HTTPHEADER => $kopf, CURLOPT_ENCODING => '',
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($body === false) {
            $this->SendDebug('Abruf', $url . ' → ' . $err, 0);
            return [0, ''];
        }
        $this->SendDebug('Abruf', $url . ' → HTTP ' . $code . ', ' . strlen((string)$body) . ' B', 0);
        return [$code, (string)$body];
    }

    /** Kennzeichnet feste Texte, die nicht direkt hinter 'caption' stehen (für die Übersetzungsprüfung); übersetzt nichts. */
    private static function fest(string $text): string
    {
        return $text;
    }

    protected function now(): int
    {
        return time();
    }

    private function t(string $text, ...$args): string
    {
        return VSCHText::t($text, ...$args);
    }
}
