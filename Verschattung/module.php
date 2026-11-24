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
require_once __DIR__ . '/VSCHGebaeude.php';
require_once __DIR__ . '/VSCHLogik.php';
require_once __DIR__ . '/VSCHGrafik.php';
require_once __DIR__ . '/VSCHErkennung.php';
require_once __DIR__ . '/VSCHAssistent.php';

class Verschattung extends IPSModule
{
    use VSCHAssistent;

    /** Standort, den der Assistent gerade prüft (überlagert Eigenschaft/Standort-Instanz). */
    private ?array $koordinatenAssistent = null;
    /** Hindernis-Horizont je Fassadenrichtung (Gelände + Nachbargebäude), nur für einen Aufruf gemerkt. */
    private array $hindernisMerk = [];

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
        $this->RegisterPropertyFloat('TemperaturMin', 0); // bis 0.3.3, ungenutzt
        // ab 0.4.0: Sonne in W/m², Sommer/Winter über Außen- und Raumtemperatur, Raumhelligkeit nur zum Herunterfahren
        $this->RegisterPropertyFloat('SonneAn', 250);
        $this->RegisterPropertyFloat('SonneAus', 150);
        $this->RegisterPropertyFloat('AussenSommer', 18);
        $this->RegisterPropertyFloat('InnenGrenze', 24);
        $this->RegisterPropertyFloat('RaumHellAn', 1.5); // bis 0.6.0 in W/m², ungenutzt
        // ab 0.6.1 in Lux (Dietmar 24.11.: „eher in Lux, damit kann man mehr anfangen“); Archiv: Sonne im Raum 200–2100 lx, Wolken 30–100 lx
        $this->RegisterPropertyFloat('RaumHellLux', 150);
        // ab 0.5.0: Heizbedarf, Wärmepumpe, Lüftungsposition, Notfall
        $this->RegisterPropertyFloat('HeizGrenze', 21);
        $this->RegisterPropertyInteger('WPModusID', 0);
        $this->RegisterPropertyFloat('Lueftung', 70);
        $this->RegisterPropertyString('Notfall', '[]'); // Raumsensoren messen hinter Glas wenig: Sonne im Raum ≈ 2–18 W/m², Wolken < 1 (Archiv 24.11.)
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
        $this->RegisterAttributeString('AssistentEntwurf', '{}');

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
        foreach ($this->notfallMelder() as $id) {
            if (IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE); // Rauchmelder: sofort, nicht erst im Minutentakt
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
        if (in_array($SenderID, $this->notfallMelder(), true)) {
            if ($Data[0] ?? false) {
                $this->Tick(); // Notfall: alle Behänge sofort hoch
            }
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
        $this->regeln(false);
    }

    /**
     * „❓ Warum?“: Probelauf ohne Fahrt und ohne Zustandsänderung – der ganze Entscheidungsweg je Behang mit den Messwerten.
     */
    public function UIWarum(): string
    {
        if ($this->koordinaten() === null) {
            return $this->t('No location yet.');
        }
        $bloecke = [];
        foreach ($this->regeln(true) as $name => $weg) {
            $bloecke[] = '▶ ' . $name . "\n   " . implode("\n   ", $weg);
        }
        return $bloecke === [] ? $this->t('No device assigned') : $this->t('Test run – nothing moves:') . "\n\n" . implode("\n\n", $bloecke);
    }

    /**
     * Ein Regeldurchlauf. $probe = true: nichts fahren, nichts speichern (für „Warum?“).
     * Rückgabe: [Gerätename => Entscheidungsweg (Zeilen)].
     */
    private function regeln(bool $probe): array
    {
        $pos = $this->koordinaten();
        if ($pos === null) {
            return [];
        }
        $now = $this->now();
        $sonne = VSCHSonne::stand($pos[0], $pos[1], $now);
        $horizont = $this->horizontPunkte();
        $fassaden = $this->fassadenMap();
        $z = $this->zustand();
        $z['_f'] = (array)($z['_f'] ?? []);
        if (!$probe) {
            $this->wetterMerken();
        }
        $hell = $this->hell($z);
        $aussen = $this->messwert($this->ReadPropertyInteger('TemperaturID'));
        $wp = $this->wpModus();
        $notfall = $this->notfall();
        $z['_klima'] = (array)($z['_klima'] ?? []);
        $warnungen = $this->warnungen();
        $schutzAktionen = $this->schutzAktionen();
        $schutzMerk = (array)($z['_schutz'] ?? []);
        $wetterQuelle = $this->ReadPropertyInteger('WindID') > 0 || $warnungen !== null || $this->ReadPropertyBoolean('NetzWetter') || $this->ReadPropertyInteger('SperreID') > 0;
        $zeiten = ['an' => 60 * $this->ReadPropertyInteger('VerzoegerungAn'), 'aus' => 60 * $this->ReadPropertyInteger('VerzoegerungAus')];
        $kopf = [$this->t('Sun: azimuth %d°, elevation %d°', (int)round($sonne['az']), (int)round($sonne['alt']))];
        $hid = $this->ReadPropertyInteger('HelligkeitID');
        $kopf[] = $hid > 0 && IPS_VariableExists($hid)
            ? $this->t('Irradiance: %d W/m² (shade from %d, end below %d)', (int)round((float)$this->wm2($hid)), (int)$this->ReadPropertyFloat('SonneAn'), (int)$this->ReadPropertyFloat('SonneAus'))
            : $this->t('Irradiance: no sensor – the position of the sun decides');
        if ($wp !== null) {
            $kopf[] = $this->t('Heat pump: %s', $this->wpText($wp));
        }
        $statusZeilen = [];
        $wege = [];
        foreach ($this->geraete() as $g) {
            $k = $g['key'];
            if ($g['Position'] <= 0 || !IPS_VariableExists($g['Position'])) {
                continue;
            }
            $f = $fassaden[$g['Fassade']] ?? null;
            if ($notfall === '' && $f === null) {
                $statusZeilen[] = $g['Name'] . ': ' . $this->t('choose a facade');
                $wege[$g['Name']] = [$this->t('choose a facade')];
                continue;
            }
            if ($notfall === '' && !$f['Aktiv']) {
                $wege[$g['Name']] = [$this->t('Facade %s is not active', (string)$g['Fassade'])];
                continue;
            }
            $faz = (float)($f['Azimut'] ?? 180);
            $gel = VSCHHorizont::hoehe($horizont, $sonne['az']);
            $hz = $f !== null ? VSCHHorizont::hoehe($this->hindernisPunkte($faz), $sonne['az']) : $gel;
            $fk = $g['Fassade'];
            $an = $f !== null && VSCHLogik::sonneAufFassade($sonne['az'], $sonne['alt'], $faz, $hz, (bool)($z['_f'][$fk] ?? false));
            $z['_f'][$fk] = $an;
            $profil = VSCHSonne::profilwinkel($sonne['alt'], $sonne['az'], $faz);
            $wetter = VSCHLogik::wetter($g['Typ'], $this->wetterQuellen($g['Typ'], $warnungen), $now);
            $klima = $this->klima($g, $aussen, $wp, $z['_klima'][$k] ?? null);
            $z['_klima'][$k] = $klima[0];
            $raumHell = $this->raumHell($g);
            $fenster = $this->fensterLage($g);
            $weg = array_merge($kopf, [
                $this->t('Facade %s (%d°): %s · horizon %d°', (string)$g['Fassade'], (int)round($faz), $an ? $this->t('sun on the facade') : $this->t('no sun'), (int)round($hz))
                    . ($hz > $gel + 0.5 ? ' (' . $this->t('neighbouring building') . ')' : ''),
                $this->t('Room and climate: %s', $klima[1] !== '' ? $klima[1] : $this->t('no temperatures – shading whenever the sun shines')),
                $this->t('Window/door: %s', $fenster[1]),
            ]);
            if ($wetter['art'] !== '') {
                $weg[] = $this->t('Weather: %s', $wetter['grund']);
            }
            $tiefe = null;
            if ((int)$g['Tiefe'] > 0 && (int)$g['FensterHoehe'] > 0 && $g['Typ'] !== 'markise') {
                $t = VSCHLogik::tiefeAnteil($profil, (float)$g['Tiefe'], (float)$g['FensterHoehe'], (float)$g['Bruestung']);
                if ($t !== null) {
                    $tiefe = [$t[0], $t[0] <= 0.02
                        ? $this->t('glare protection: the sun reaches only %d cm into the room', (int)round(min(9999, $t[1])))
                        : $this->t('glare protection: sun at most %d cm into the room (%d %% down)', (int)$g['Tiefe'], (int)round($t[0] * 100))];
                }
            }
            $u = [
                'notfall' => $notfall, 'weg' => $weg, 'hell' => $hell[0], 'hellGrund' => $hell[1], 'klima' => $klima[0], 'klimaGrund' => $klima[1],
                'raumHell' => $raumHell[0], 'raumHellGrund' => $raumHell[1], 'wetter' => $wetter, 'wetterQuelle' => $wetterQuelle,
                'ohneWetterBestaetigt' => $this->ReadPropertyBoolean('MarkiseOhneWetter'),
                'fenster' => $fenster[0], 'lueftung' => $this->ReadPropertyFloat('Lueftung') / 100,
                'automatik' => (bool)$this->GetValue('Automatik'), 'tag' => $sonne['alt'] > 0,
                'schutz' => $schutzAktionen === [] ? null : VSCHLogik::schutz($schutzAktionen, array_values(array_filter([$g['Position'], $g['Lamelle']])),
                    IPS_GetParent($g['Position']), $schutzMerk, $now, 60 * $this->ReadPropertyInteger('Haltezeit')),
            ];
            $ger = ['typ' => $g['Typ'], 'az' => $faz, 'sonneAn' => $an, 'lamellen' => $g['Lamelle'] > 0, 'profil' => $profil, 'tiefe' => $tiefe];
            $r = VSCHLogik::entscheide($ger, $u, (array)($z[$k] ?? []), $zeiten, $now);
            $wege[$g['Name']] = $r['weg'];
            if ($probe) {
                continue;
            }
            $neu = $r['z'];
            $neu['grund'] = $r['grund'];
            if ($r['ziel'] !== null) {
                $this->ausfuehren($g, $r['ziel'], $r['lamelle'], $neu, $now, $r['anteil'] ?? null);
            }
            $z[$k] = $neu;
            $statusZeilen[] = $g['Name'] . ': ' . $r['grund'];
        }
        if ($probe) {
            return $wege;
        }
        $z['_w'] = $this->wetterLage();
        $z['_schutz'] = $schutzMerk;
        $this->WriteAttributeString('Zustand', json_encode($z));
        $status = $statusZeilen === [] ? $this->t('No device assigned') : implode(' · ', array_slice($statusZeilen, 0, 4))
            . (count($statusZeilen) > 4 ? ' · ' . $this->t('+%d more', count($statusZeilen) - 4) : '');
        if ($this->GetValue('Status') !== $status) {
            $this->SetValue('Status', $status);
        }
        return $wege;
    }

    /** Befehl an ein Gerät: Behang auf Ziel, Lamellen nachführen. Wiederholt einmal, wenn ein Befehl verloren ging. */
    private function ausfuehren(array $g, string $ziel, ?float $lamelle, array &$z, int $now, ?float $teil = null): void
    {
        // $teil: Blendschutz-Tiefe oder Lüftungsposition begrenzen die Beschattungsposition des Geräts
        $anteil = $ziel === 'beschatten' ? ($teil === null ? $g['Beschattung'] / 100 : min($g['Beschattung'] / 100, $teil)) : 0.0;
        $wert = $this->positionWert($g, $anteil);
        $letzt = (array)($z['befehl'] ?? []);
        // gleiches Ziel, aber Position um mehr als 5 % gewandert (Sonne steigt, Fenster geöffnet) → neu fahren
        $gleich = ($letzt['ziel'] ?? '') === $ziel && abs((float)($letzt['anteil'] ?? $anteil) - $anteil) < 0.05;
        $ist = GetValue($g['Position']);
        $weit = abs((float)$ist - $wert) > max(2.0, 0.03 * $this->maxWert($g['Position']));
        if (!$gleich || ($weit && $now - (int)($letzt['ts'] ?? 0) > self::NACHKONTROLLE_S && (int)($letzt['wdh'] ?? 0) < 1)) {
            if ($this->schalten($g['Position'], $wert)) {
                $z['befehl'] = ['ziel' => $ziel, 'ts' => $now, 'wdh' => $gleich ? (int)($letzt['wdh'] ?? 0) + 1 : 0, 'anteil' => round($anteil, 3)];
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

    /**
     * Scheint die Sonne? Einstrahlung (W/m², Lux umgerechnet) gegen SonneAn/SonneAus mit Hysterese.
     * Rückgabe [?bool, Begründung wenn zu wenig]; ohne Sensor [null, ''] (dann zählt nur die Geometrie).
     */
    private function hell(array &$z): array
    {
        $w = $this->wm2($this->ReadPropertyInteger('HelligkeitID'));
        if ($w === null) {
            return [null, ''];
        }
        $vorher = (bool)($z['_hell'] ?? false);
        $an = $this->ReadPropertyFloat('SonneAn');
        $jetzt = $vorher ? $w > min($an, $this->ReadPropertyFloat('SonneAus')) : $w >= $an;
        $z['_hell'] = $jetzt;
        return [$jetzt, $this->t('Sun on the facade, but only %d W/m² (shade from %d W/m²)', (int)round($w), (int)round($an))];
    }

    /**
     * Heizen, Kühlen oder neutral für diesen Behang (VSCHLogik::klima) samt lesbarer Begründung mit den Messwerten.
     */
    private function klima(array $g, ?float $aussen, ?int $wp, ?string $vorher): array
    {
        $iI = (int)$g['RaumTemp'];
        $iA = $this->ReadPropertyInteger('TemperaturID');
        $innen = $this->messwert($iI);
        [$wert, $grund] = VSCHLogik::klima($innen, $aussen, $wp, $this->ReadPropertyFloat('HeizGrenze'), $this->ReadPropertyFloat('InnenGrenze'),
            $this->ReadPropertyFloat('AussenSommer'), $vorher);
        // Anzeige so, wie Symcon den Wert formatiert (Einheit, Dezimalzeichen der Sprache)
        $ft = fn(?float $v, int $id) => $v === null ? '–' : (string)@GetValueFormatted($id);
        switch ($grund) {
            case 'raum':
                return [$wert, $this->t('room warm (%s): cooling needed, shade', $ft($innen, $iI))];
            case 'wpkuehlt':
                return [$wert, $this->t('heat pump is cooling: shade')];
            case 'wpheizt':
                return [$wert, $this->t('heat pump is heating: the sun may warm the room (room %s)', $ft($innen, $iI))];
            case 'raumkalt':
                return [$wert, $this->t('room %s below %s °C: the sun may warm it', $ft($innen, $iI), VSCHLogik::zahl($this->ReadPropertyFloat('HeizGrenze')))];
            case 'sommer':
                return [$wert, $this->t('summer (outside %s): shade', $ft($aussen, $iA))];
            case 'winter':
                return [$wert, $this->t('cool outside (%s): the sun may warm the house', $ft($aussen, $iA))];
            case 'behaglich':
                return [$wert, $this->t('room comfortable (%s): neither heating nor cooling needed', $ft($innen, $iI))];
        }
        return [null, ''];
    }

    /** Betriebsart der Wärmepumpe (Verbund-Enum 0–5) oder null. */
    private function wpModus(): ?int
    {
        $id = $this->ReadPropertyInteger('WPModusID');
        return $id > 0 && IPS_VariableExists($id) ? (int)GetValue($id) : null;
    }

    private function wpText(int $m): string
    {
        return [0 => $this->t('standby'), 1 => $this->t('heating'), 2 => $this->t('cooling'), 3 => $this->t('hot water'),
            4 => $this->t('heating + hot water'), 5 => $this->t('cooling + hot water')][$m] ?? (string)$m;
    }

    /** Aktiver Rauch-/Brandmelder („Rauchmelder Küche“) oder ''. */
    private function notfall(): string
    {
        foreach ($this->notfallMelder() as $id) {
            if (IPS_VariableExists($id) && (bool)GetValue($id)) {
                return $this->name($id);
            }
        }
        return '';
    }

    /** @return int[] Variablen der Rauch-/Brandmelder aus der Liste „Notfall“ */
    private function notfallMelder(): array
    {
        return array_values(array_filter(array_map(fn($x) => (int)($x['Melder'] ?? 0), $this->listeWerte($this->ReadPropertyString('Notfall')))));
    }

    /**
     * Fenster/Tür für diesen Behang: ['zu'|'lueften'|'sperre', Text].
     * Im Zweifel ist jeder offene Kontakt eine Tür → nicht herunter (Aussperrschutz, die sichere Richtung). Am Namen lässt sich
     * Fenster und Tür nicht verlässlich unterscheiden (bodentiefe „Fenster“ sind Türen; Dietmar 24.11.: „Wir haben nur Türen“).
     * Lüftungsposition nur, wenn ein Griff „gekippt“ meldet oder die Spalte „Kontakt offen“ sie für diesen Behang erlaubt.
     */
    private function fensterLage(array $g): array
    {
        $sperre = false;
        $lueften = false;
        $namen = [];
        foreach ([$g['Kontakt'], $g['Kontakt2'] ?? 0, $g['Kontakt3'] ?? 0, $g['Kontakt4'] ?? 0] as $k) {
            if ($k <= 0 || !IPS_VariableExists($k)) {
                continue;
            }
            $zust = $this->kontaktZustand($k);
            if ($zust === 'zu') {
                continue;
            }
            $namen[] = $this->name($k) . ' (' . ($zust === 'gekippt' ? $this->t('tilted') : $this->t('open')) . ')';
            if ($zust === 'offen') {
                $sperre = true;
            } else {
                $lueften = true;
            }
        }
        if (!$sperre && !$lueften) {
            return ['zu', $this->t('closed')];
        }
        $art = (int)$g['BeiOffen'];
        if ($art === 1) {
            $sperre = true;
        } elseif ($art === 2) {
            $sperre = false;
        }
        return [$sperre ? 'sperre' : 'lueften', implode(', ', $namen) . ' → '
            . ($sperre ? $this->t('not lowering (lockout protection)') : $this->t('at most ventilation position'))];
    }

    /** 'zu', 'gekippt' oder 'offen'. Mehrstufige Griffe über den angezeigten Text („gekippt“, „tilted“), sonst wie ein Kontakt. */
    private function kontaktZustand(int $id): string
    {
        $v = GetValue($id);
        if (!is_bool($v)) {
            $text = mb_strtolower((string)@GetValueFormatted($id));
            if (preg_match('/kipp|tilt/', $text)) {
                return 'gekippt';
            }
            if (is_string($v)) {
                return preg_match('/offen|open|geöffnet/', $text) ? 'offen' : 'zu';
            }
        }
        return $this->kontaktOffen($id) ? 'offen' : 'zu';
    }

    /** Raumhelligkeit (nur zum Herunterfahren): erreicht die Sonne den Raum? [?bool, Begründung] */
    private function raumHell(array $g): array
    {
        $lx = $this->lux((int)$g['RaumHell']);
        if ($lx === null) {
            return [null, ''];
        }
        return [$lx >= $this->ReadPropertyFloat('RaumHellLux'), $this->t('Sun on the facade, but it does not reach the room (%d lx)', (int)round($lx))];
    }

    /** Helligkeit in Lux. Die Einheit entscheidet: lx direkt, klx × 1000, W/m² × 120. */
    private function lux(int $id): ?float
    {
        $v = $this->messwert($id);
        if ($v === null) {
            return null;
        }
        $e = mb_strtolower($this->einheit($id));
        if (strpos($e, 'w/m') !== false) {
            return $v * 120;
        }
        return strpos($e, 'klx') !== false ? $v * 1000 : $v;
    }

    /** Zahlenwert einer Variable, null wenn nicht gesetzt oder nicht vorhanden. */
    private function messwert(int $id): ?float
    {
        return $id > 0 && IPS_VariableExists($id) ? (float)GetValue($id) : null;
    }

    /**
     * Helligkeit/Einstrahlung in W/m². Die Einheit entscheidet: W/m² direkt, Lux (lx/klx) ≈ /120.
     * Live-Befund 24.11.: Die Froggit-„Solar Einstrahlung“ liefert trotz des Namens Lux (21 617 lx ≈ 180 W/m²).
     */
    private function wm2(int $id): ?float
    {
        $v = $this->messwert($id);
        if ($v === null) {
            return null;
        }
        $e = mb_strtolower($this->einheit($id));
        if (strpos($e, 'w/m') !== false) {
            return $v;
        }
        if (strpos($e, 'klx') !== false) {
            return VSCHLogik::luxZuWm2($v * 1000);
        }
        if (strpos($e, 'lx') !== false || strpos($e, 'lux') !== false) {
            return VSCHLogik::luxZuWm2($v);
        }
        return $v;
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

    /**
     * Schutzaktionen aus WarnHub (WHUB_GetProtectionState, Vertrag protection 1.x, ab WarnHub 1.22.0).
     * Liefert die Aktionen aller WarnHub-Instanzen; leer, wenn es die Funktion nicht gibt oder die Hauptversion nicht passt.
     */
    private function schutzAktionen(): array
    {
        if (!$this->ReadPropertyBoolean('WarnHubNutzen') || !function_exists('WHUB_GetProtectionState')) {
            return [];
        }
        $out = [];
        foreach (IPS_GetInstanceListByModuleID(self::WARNHUB_GUID) as $id) {
            try {
                $j = json_decode((string)WHUB_GetProtectionState($id), true);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($j) || (int)explode('.', (string)($j['contractVersion'] ?? '0'))[0] !== 1) {
                continue;
            }
            foreach ((array)($j['actions'] ?? []) as $a) {
                if (is_array($a)) {
                    $a['index'] = $id . ':' . ($a['index'] ?? count($out));
                    $out[] = $a;
                }
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

    /** Ein Fenster gilt als offen, wenn einer seiner (bis zu vier) Kontakte offen meldet. */
    private function fensterOffen(array $g): bool
    {
        foreach ([$g['Kontakt'], $g['Kontakt2'] ?? 0, $g['Kontakt3'] ?? 0, $g['Kontakt4'] ?? 0] as $k) {
            if ($k > 0 && IPS_VariableExists($k) && $this->kontaktOffen($k)) {
                return true;
            }
        }
        return false;
    }

    private function kontaktOffen(int $id): bool
    {
        $v = GetValue($id);
        $offen = is_bool($v) ? $v : (float)$v > 0;
        return VSCHErkennung::kontaktUmgekehrt($this->profil($id), IPS_GetName($id) . ' ' . IPS_GetObject($id)['ObjectIdent'], $this->wahrText($id)) ? !$offen : $offen;
    }

    /**
     * Darstellung einer Variable (Symcon 8): [GUID, Beschriftung für true, Vorlagen-GUID, Rollladen-Werte].
     * Die gespeicherte Darstellung enthält den Vorlagen-Verweis (TEMPLATE); IPS_GetVariablePresentation()
     * löst die Vorlage in ihre Werte auf (OPTIONS, OPEN_OUTSIDE_VALUE …), lässt TEMPLATE aber weg – daher beides lesen.
     */
    private function darstellung(int $id): array
    {
        $v = IPS_GetVariable($id);
        $d = $v['VariableCustomPresentation'] ?? [];
        if (!is_array($d) || $d === []) {
            $d = $v['VariablePresentation'] ?? [];
        }
        $d = is_array($d) ? $d : [];
        $w = $this->aufgeloest($id) ?: $d;
        $wahr = '';
        $opt = isset($w['OPTIONS']) ? json_decode((string)$w['OPTIONS'], true) : null;
        foreach (is_array($opt) ? $opt : [] as $o) {
            if (($o['Value'] ?? null) === true) {
                $wahr = (string)($o['Caption'] ?? '');
            }
        }
        $roll = [];
        if (($w['PRESENTATION'] ?? '') === VSCHErkennung::SHUTTER_DARSTELLUNG && isset($w['OPEN_OUTSIDE_VALUE'], $w['CLOSE_INSIDE_VALUE'])) {
            $roll = ['usage' => (int)($w['USAGE_TYPE'] ?? 0), 'open' => (float)$w['OPEN_OUTSIDE_VALUE'], 'close' => (float)$w['CLOSE_INSIDE_VALUE']];
        }
        return [(string)($d['PRESENTATION'] ?? ''), $wahr, (string)($d['TEMPLATE'] ?? ''), $roll];
    }

    /** Aufgelöste Darstellung (Vorlagenwerte eingesetzt), leer wenn nicht verfügbar. */
    private function aufgeloest(int $id): array
    {
        if (!function_exists('IPS_GetVariablePresentation')) {
            return [];
        }
        try {
            $w = @IPS_GetVariablePresentation($id);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($w) ? $w : [];
    }

    private function wahrText(int $id): string
    {
        return $this->darstellung($id)[1];
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
        // Rollladen-Darstellung/-Vorlage: „offen bei“ kleiner als „geschlossen bei“ → 0 % = offen
        $roll = $this->darstellung($g['Position'])[3];
        if ($roll !== [] && $roll['open'] !== $roll['close']) {
            return $roll['open'] < $roll['close'];
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
        $r = $this->hausErkennen();
        if (!$r['ok']) {
            $this->UpdateFormField('GeoStatus', 'caption', '⛔ ' . $r['text']);
            return $r['text'];
        }
        $this->UpdateFormField('Fassaden', 'values', json_encode($r['werte']));
        $this->UpdateFormField('GeoStatus', 'caption', '🔗 ' . $this->geoStatus());
        $this->UpdateFormField('Grundriss', 'image', $this->grundrissBild($r['werte'], 0, 'heute'));
        $this->UpdateFormField('Himmel', 'image', $this->himmelBild($r['werte'], 0, 'heute'));
        $this->UpdateFormField('Vorschau', 'caption', $this->vorschauText($r['werte'], 0, 'heute'));
        return $r['text'];
    }

    /**
     * Umriss, Fassaden und Horizont am aktuellen Standort abrufen und die abgeleiteten Werte merken.
     * Rückgabe: ['ok' => bool, 'text' => Meldung, 'werte' => Zeilen für eine Fassadenliste]
     */
    private function hausErkennen(): array
    {
        $pos = $this->koordinaten();
        if ($pos === null) {
            return ['ok' => false, 'werte' => [], 'text' => $this->t('No location: please set the location in the Location instance or choose one here.')];
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
            return ['ok' => false, 'werte' => [], 'text' => $this->t('No house found: %s', $haus['meldung']) . ' ' . $this->t('You can enter the facades by hand in the list.')];
        }
        $this->WriteAttributeString('Haus', json_encode([
            'eigen' => $haus['eigen'], 'nachbarn' => $haus['nachbarn'], 'nachbarInfo' => $haus['nachbarInfo'] ?? [], 'quelle' => $haus['quelle'], 'lizenz' => $haus['lizenz'],
            'land' => $haus['land'], 'ts' => $this->now(), 'fassaden' => $haus['fassaden'], 'lat' => $pos[0], 'lon' => $pos[1],
        ]));
        $werte = [];
        foreach ($haus['fassaden'] as $i => $f) {
            $werte[] = ['Kennung' => VSCHGrafik::kennung($i), 'Name' => $this->richtungName((float)$f['az']), 'Azimut' => $f['az'], 'Aktiv' => true];
        }
        return ['ok' => true, 'werte' => $werte, 'text' => $this->t('%d facades found. Please check them in the list and confirm with "Apply changes".', count($werte))];
    }

    /** Vorschau neu zeichnen (Fassade/Tag gewählt oder Liste geändert). */
    public function UIVorschau(string $fassadenJson, int $auswahl, string $tag, bool $gebaeude = true): void
    {
        $werte = $this->listeWerte($fassadenJson);
        if ($werte === []) {
            $werte = $this->listeWerte($this->ReadPropertyString('Fassaden'));
        }
        $this->UpdateFormField('Grundriss', 'image', $this->grundrissBild($werte, $auswahl, $tag));
        $this->UpdateFormField('Himmel', 'image', $this->himmelBild($werte, $auswahl, $tag, $gebaeude));
        $this->UpdateFormField('Vorschau', 'caption', $this->vorschauText($werte, $auswahl, $tag, $gebaeude));
    }

    /**
     * „Automatisch erkennen“: Behänge je Instanz (Typ, Position, Lamelle samt Richtung, Fensterkontakt) in die Geräteliste,
     * leere Sensorfelder (Wind, Regen, Helligkeit, Temperatur) füllen. Nur ins offene Formular, nichts wird überschrieben.
     * Die Fassade bleibt die einzige Angabe des Nutzers.
     */
    public function UIAutomatik(string $geraeteJson, int $wind, int $regen, int $hell, int $temp, int $sperre = 0, int $wp = 0, string $notfallJson = '[]'): string
    {
        $inst = $this->momentaufnahme();
        $geraete = $this->listeWerte($geraeteJson);
        $vergeben = array_map(fn($g) => (int)($g['Position'] ?? 0), $geraete);
        // bestehende Zeilen nur ergänzen, wo etwas fehlt
        $kontakte = VSCHErkennung::kontakte($inst);
        $raum = VSCHErkennung::raumSensoren($inst);
        $ergaenzt = 0;
        foreach ($geraete as &$g) {
            $p = (int)($g['Position'] ?? 0);
            if ($p > 0 && IPS_VariableExists($p)) {
                $text = ($inst[IPS_GetParent($p)]['name'] ?? (string)($g['Name'] ?? '')) . ' ' . ($inst[IPS_GetParent($p)]['ort'] ?? '');
                foreach (['RaumTemp' => 'temp', 'RaumHell' => 'hell'] as $sp => $art) {
                    if ((int)($g[$sp] ?? 0) === 0) {
                        $g[$sp] = VSCHErkennung::raumSensorFuer($text, $raum, $art);
                    }
                }
            }
            if ($p > 0 && IPS_VariableExists($p) && (int)($g['Kontakt'] ?? 0) === 0 && (int)($g['Kontakt2'] ?? 0) === 0
                && (int)($g['Kontakt3'] ?? 0) === 0 && (int)($g['Kontakt4'] ?? 0) === 0) {
                $i = IPS_GetParent($p);
                $k = VSCHErkennung::kontakteFuer(($inst[$i]['name'] ?? (string)($g['Name'] ?? '')) . ' ' . ($inst[$i]['ort'] ?? ''), $kontakte);
                if ($k !== []) {
                    $g['Kontakt'] = $k[0];
                    $g['Kontakt2'] = $k[1] ?? 0;
                    $g['Kontakt3'] = $k[2] ?? 0;
                    $g['Kontakt4'] = $k[3] ?? 0;
                    $ergaenzt++;
                }
            }
        }
        unset($g);
        $neu = VSCHErkennung::geraete($inst, $vergeben);
        $fassaden = $this->listeWerte($this->ReadPropertyString('Fassaden'));
        foreach ($neu as &$n) {
            $n['Fassade'] = VSCHErkennung::fassadeZuAzimut($n['AzHinweis'], $fassaden);
            unset($n['AzHinweis']);
        }
        unset($n);
        $mitKontakt = count(array_filter($neu, fn($g) => $g['Kontakt'] > 0));
        $this->UpdateFormField('Geraete', 'values', json_encode(array_merge($geraete, $neu)));

        $sens = VSCHErkennung::sensoren($inst, $this->warnHubWetterstation());
        $gesetzt = [];
        foreach (['WindID' => $wind, 'RegenID' => $regen, 'SperreID' => $sperre, 'HelligkeitID' => $hell, 'TemperaturID' => $temp] as $feld => $jetzt) {
            if ($jetzt === 0 && $sens[$feld] > 0) {
                $this->UpdateFormField($feld, 'value', $sens[$feld]);
                $this->UpdateFormField($feld . 'Verbindung', 'caption', $this->verbindungsText($feld, $sens[$feld], ['hinweise' => []]));
                $gesetzt[] = $this->sensorName($feld) . ': ' . $this->name($sens[$feld]);
            }
        }
        if ($wp === 0 && ($w = $this->wpErkennen()) > 0) {
            $this->UpdateFormField('WPModusID', 'value', $w);
            $gesetzt[] = $this->t('Heat pump') . ': ' . $this->name($w);
        }
        if ($this->listeWerte($notfallJson) === []) {
            $melder = VSCHErkennung::rauchmelder($inst);
            if ($melder !== []) {
                $this->UpdateFormField('Notfall', 'values', json_encode(array_map(fn($m) => ['Melder' => $m], $melder)));
                $gesetzt[] = $this->t('%d smoke detectors', count($melder));
            }
        }
        $zeilen = [$this->t('%d devices detected (%d with window contact).', count($neu), $mitKontakt + $ergaenzt)];
        if ($neu !== []) {
            $zeilen[] = $this->t('Please choose the facade for each device in the list.');
        }
        $zeilen[] = $gesetzt === [] ? $this->t('No further sensors assigned.') : $this->t('Sensors: %s', implode(' · ', $gesetzt));
        foreach ($sens['hinweise'] as $feld => $h) {
            $zeilen[] = $this->sensorName($feld) . ': ' . ($h === 'warnhub' ? $this->t('already evaluated by WarnHub') : $this->t('several candidates, please choose'));
        }
        $zeilen[] = $this->t('Confirm with "Apply changes".');
        return implode("\n", $zeilen);
    }

    /** Kandidaten für leere Sensorfelder (nur berechnet, wenn eines leer ist). */
    private function sensorVorschlag(): array
    {
        foreach (['WindID', 'RegenID', 'SperreID', 'HelligkeitID', 'TemperaturID'] as $f) {
            if ($this->ReadPropertyInteger($f) === 0) {
                return VSCHErkennung::sensoren($this->momentaufnahme(), $this->warnHubWetterstation());
            }
        }
        return ['hinweise' => []];
    }

    /**
     * Sichtbarer Nachweis der Verbindung unter einem Sensorfeld:
     * „✓ Wetterstation / Außentemperatur: 3,1 °C · vor 1 min“ oder „🔍 Gefunden: …“ oder „– nicht verbunden“.
     */
    private function verbindungsText(string $feld, int $id, array $vorschlag): string
    {
        if ($id > 0) {
            if (!IPS_VariableExists($id)) {
                return '⚠️ ' . $this->t('Variable #%d no longer exists.', $id);
            }
            $wm2 = '';
            if ($feld === 'HelligkeitID' && strpos(mb_strtolower($this->einheit($id)), 'w/m') === false) {
                $wm2 = ' ≈ ' . (int)round((float)$this->wm2($id)) . ' W/m²'; // was das Modul daraus macht
            }
            return '✓ ' . $this->name($id) . ': ' . $this->wertText($id) . $wm2;
        }
        $k = (int)($vorschlag[$feld] ?? 0);
        if ($k > 0 && IPS_VariableExists($k)) {
            return '🔍 ' . $this->t('Found: %s (%s) – "Detect devices and sensors" enters it.', $this->name($k), $this->wertText($k));
        }
        if (($vorschlag['hinweise'][$feld] ?? '') === 'warnhub') {
            return 'ℹ️ ' . $this->t('Evaluated by WarnHub (weather station).');
        }
        return '– ' . $this->t('not connected');
    }

    /** „3,1 °C · vor 2 min“ */
    private function wertText(int $id): string
    {
        $wert = @GetValueFormatted($id);
        $alter = max(0, $this->now() - (int)(IPS_GetVariable($id)['VariableUpdated'] ?? 0));
        $wann = $alter < 3600 ? $this->t('%d min ago', intdiv($alter, 60)) : ($alter < 172800 ? $this->t('%d h ago', intdiv($alter, 3600)) : $this->t('%d days ago', intdiv($alter, 86400)));
        return $wert . ' · ' . $wann;
    }

    private function sensorName(string $feld): string
    {
        switch ($feld) {
            case 'WindID':
                return $this->t('Wind');
            case 'RegenID':
                return $this->t('Rain');
            case 'HelligkeitID':
                return $this->t('Brightness');
            case 'SperreID':
                return $this->t('Lock');
            default:
                return $this->t('Temperature');
        }
    }

    /** Momentaufnahme aller Instanzen mit ihren Variablen (Grundlage der Erkennung). */
    private function momentaufnahme(): array
    {
        // Ordner, in denen Links auf ein Objekt liegen (für die Ausrichtung aus dem Objektbaum)
        $linkOrdner = [];
        foreach (IPS_GetLinkList() as $l) {
            $ziel = (int)(IPS_GetLink($l)['TargetID'] ?? 0);
            $linkOrdner[$ziel][] = $this->ordnerText(IPS_GetParent($l));
        }
        $out = [];
        foreach (IPS_GetInstanceList() as $i) {
            if ($i === $this->InstanceID) {
                continue;
            }
            $vars = [];
            foreach (IPS_GetChildrenIDs($i) as $c) {
                if (IPS_VariableExists($c)) {
                    $v = IPS_GetVariable($c);
                    $d = $this->darstellung($c);
                    $vars[] = [$c, IPS_GetName($c), IPS_GetObject($c)['ObjectIdent'], $v['VariableType'], $this->profil($c), HasAction($c), $d[0], $d[1], $d[2], $d[3]];
                }
            }
            if ($vars !== []) {
                $p = IPS_GetParent($i);
                $mi = IPS_GetInstance($i)['ModuleInfo'];
                $kontext = [(string)(IPS_GetObject($i)['ObjectInfo'] ?? ''), $this->ordnerText($p)];
                foreach ($vars as $v) {
                    $kontext[] = (string)(IPS_GetObject($v[0])['ObjectInfo'] ?? '');
                    foreach ($linkOrdner[$v[0]] ?? [] as $t) {
                        $kontext[] = $t;
                    }
                }
                foreach ($linkOrdner[$i] ?? [] as $t) {
                    $kontext[] = $t;
                }
                $out[$i] = ['name' => IPS_GetName($i), 'ort' => $p > 0 ? IPS_GetName($p) : '', 'modul' => $mi['ModuleID'], 'modulName' => (string)($mi['ModuleName'] ?? ''),
                    'vars' => $vars, 'kontext' => array_values(array_filter($kontext, fn($t) => $t !== ''))];
            }
        }
        return $out;
    }

    /** Namen eines Ordners und seiner zwei übergeordneten Ordner („Fassade Süd / EG / Zuhause“). */
    private function ordnerText(int $id): string
    {
        $teile = [];
        for ($n = 0; $id > 0 && $n < 3; $n++) {
            $teile[] = IPS_GetName($id);
            $id = IPS_GetParent($id);
        }
        return implode(' / ', $teile);
    }

    /** Felder, die überflüssig sind, wenn WarnHub die Wetterstation auswertet (Formular bzw. Assistent). */
    private const WETTER_FELDER = ['WindID', 'WindIDVerbindung', 'WindIDHint', 'WindSchwellen', 'WindMarkiseHint', 'RegenID', 'RegenIDVerbindung', 'RegenIDHint',
        'NetzWetter', 'NetzWetterHint', 'MarkiseOhneWetter', 'MarkiseOhneWetterHint'];
    private const WETTER_FELDER_ASSISTENT = ['WWind', 'WWindInfo', 'WRegen', 'WRegenInfo', 'WSperre', 'WSperreInfo', 'WNetzWetter', 'WMarkiseOhne'];

    /**
     * Wertet WarnHub eine Wetterstation aus und ist „WarnHub nutzen“ an? Dann kommt der Wetterschutz von dort –
     * keine Doppelauswertung (Dietmar 24.11.), die eigenen Wind-/Regenfelder werden ausgeblendet.
     */
    private function warnHubDecktStation(?bool $nutzen = null): bool
    {
        if (!($nutzen ?? $this->ReadPropertyBoolean('WarnHubNutzen')) || !function_exists('WHUB_GetActiveWarnings')) {
            return false;
        }
        $st = (int)($this->warnHubWetterstation()['instanz'] ?? 0);
        return $st > 0 && IPS_InstanceExists($st);
    }

    /** „🔗 Der Wetterschutz kommt von WarnHub: Wetterstation „…“ (Wind, Regen) und amtliche Warnungen. …“ */
    private function warnHubDecktText(): string
    {
        $st = (int)($this->warnHubWetterstation()['instanz'] ?? 0);
        $name = $st > 0 && IPS_InstanceExists($st) ? IPS_GetName($st) : '—';
        return '🔗 ' . $this->t('Weather protection comes from WarnHub: weather station "%s" (wind, rain) and official warnings. Nothing to enter here – the wind limits are set in WarnHub.', $name);
    }

    /** „WarnHub nutzen“ umgeschaltet: überflüssige Felder aus- bzw. wieder einblenden. */
    public function UIWetterSicht(bool $nutzen, bool $assistent): void
    {
        $deckt = $this->warnHubDecktStation($nutzen);
        foreach ($assistent ? self::WETTER_FELDER_ASSISTENT : self::WETTER_FELDER as $f) {
            $this->UpdateFormField($f, 'visible', !$deckt);
        }
        $this->UpdateFormField($assistent ? 'WWarnHubDeckt' : 'WarnHubDeckt', 'visible', $deckt);
    }

    /** Was WarnHub schon an Wetterstation auswertet (dann ordnet die Verschattung Wind/Regen nicht doppelt zu). */
    private function warnHubWetterstation(): array
    {
        $wh = IPS_GetInstanceListByModuleID(self::WARNHUB_GUID);
        if ($wh === []) {
            return [];
        }
        $lies = function (string $p) use ($wh) {
            try {
                return (int)@IPS_GetProperty($wh[0], $p);
            } catch (Throwable $e) {
                return 0;
            }
        };
        return ['instanz' => $lies('WetterstationInstanceID'), 'vars' => array_values(array_filter([$lies('WetterstationWindVariableID'), $lies('WetterstationRegenVariableID')]))];
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
        if ($n === 1) {
            return $this->t('1 device of facade %s is moving. If it moves the wrong way, change its "0 %% means" column.', $fassade);
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
        // immer A–L anbieten (der Assistent kann Fassaden eintragen, die beim Öffnen des Formulars noch nicht gespeichert waren)
        $bekannt = array_column($fassaden, 'Name', 'Kennung');
        $optGeraetFassade = [['caption' => '— choose —', 'value' => '']];
        foreach (self::assistentKennungen() as $k) {
            $optGeraetFassade[] = ['caption' => isset($bekannt[$k]) ? $k . ' · ' . $bekannt[$k] : $k, 'value' => $k];
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
        // Live-Befund 24.11.: $Fassaden fehlt im Aktionsbereich, solange die Fassadenliste nicht geladen ist → gespeicherte Fassaden nehmen
        $vorschauAufruf = '$f = []; foreach (($Fassaden ?? []) as $x) { $f[] = $x; } VSCH_UIVorschau($id, json_encode($f), (int)$VorschauFassade, (string)$VorschauTag, (bool)($VorschauGebaeude ?? true));';

        $elements = [];
        if (!$this->ReadAttributeBoolean('PurposeIntroGone')) {
            $elements[] = ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  What is this module for?', 'items' => [
                ['type' => 'Label', 'caption' => 'Lowers roller shutters, venetian blinds and awnings when the sun shines on their facade, and raises them again afterwards. Venetian blind slats follow the sun.'],
                ['type' => 'Label', 'caption' => 'You do not enter angles: the module finds your house in official map data, shows its facades, and you only assign the devices.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'VSCH_AckPurposeIntro($id);'],
            ]];
        }
        $elements[] = ['type' => 'Button', 'caption' => '🧭 Start setup assistant', 'onClick' => 'VSCH_UIAssistentOeffnen($id);'];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '📖  Documentation & help', 'items' => [
            ['type' => 'Label', 'caption' => 'Easiest way: the setup assistant guides you through location, house, devices, test run, weather protection and when to shade. You only choose the facade for each device.'],
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
                    ['caption' => 'Facade', 'name' => 'Fassade', 'width' => '130px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => $optGeraetFassade]],
                    ['caption' => 'Type', 'name' => 'Typ', 'width' => '150px', 'add' => 'rollladen', 'edit' => ['type' => 'Select', 'options' => $optTyp]],
                    ['caption' => 'Position', 'name' => 'Position', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => '0 % means', 'name' => 'Richtung', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => [
                        ['caption' => 'automatic (profile)', 'value' => 0], ['caption' => 'open / retracted', 'value' => 1], ['caption' => 'closed / extended', 'value' => 2]]]],
                    ['caption' => 'Slats (optional)', 'name' => 'Lamelle', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Slats 0 % = closed', 'name' => 'LamelleUmgekehrt', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'Window/door contact', 'name' => 'Kontakt', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Contact 2', 'name' => 'Kontakt2', 'width' => '180px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Contact 3', 'name' => 'Kontakt3', 'width' => '180px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Contact 4', 'name' => 'Kontakt4', 'width' => '180px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Shading position (%)', 'name' => 'Beschattung', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 100]],
                    ['caption' => 'Room temperature', 'name' => 'RaumTemp', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Room brightness', 'name' => 'RaumHell', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Contact open', 'name' => 'BeiOffen', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => [
                        ['caption' => 'do not lower (door)', 'value' => 0], ['caption' => 'ventilation position (window)', 'value' => 2]]]],
                    ['caption' => 'Sun max. into room (cm)', 'name' => 'Tiefe', 'width' => '190px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 1000]],
                    ['caption' => 'Window height (cm)', 'name' => 'FensterHoehe', 'width' => '170px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 600]],
                    ['caption' => 'Sill height (cm)', 'name' => 'Bruestung', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 300]],
                ]],
            ['type' => 'Button', 'caption' => '🔍 Detect devices and sensors', 'onClick' => '$g = []; foreach ($Geraete as $x) { $g[] = $x; } $n = []; foreach (($Notfall ?? []) as $x) { $n[] = $x; } echo VSCH_UIAutomatik($id, json_encode($g), (int)$WindID, (int)$RegenID, (int)$HelligkeitID, (int)$TemperaturID, (int)$SperreID, (int)($WPModusID ?? 0), json_encode($n));'],
            ['type' => 'Label', 'name' => 'KontaktHint', 'caption' => 'Up to four window/door contacts per device. Open: the device is not lowered (lockout protection) – every open contact counts as a door, because the name does not tell whether one can walk through. Handles that report "tilted" lower at most to the ventilation position. For real windows set the column "Contact open" to "ventilation position (window)".'],
            ['type' => 'HorizontalSlider', 'displayValue' => true, 'suffix' => ' %', 'name' => 'Lueftung', 'caption' => 'Ventilation position: lower at most to (%)', 'minimum' => 0, 'maximum' => 100, 'stepSize' => 5, 'width' => '600px'],
            ['type' => 'Label', 'name' => 'TiefeHint', 'caption' => 'Optional glare protection: with "Sun max. into room", window height and sill height the device goes down only as far as needed, so that the sun reaches at most that far into the room. Used when neither heating nor cooling is needed; when cooling, the device shades fully.'],
            ['type' => 'Label', 'name' => 'GeraeteHint', 'caption' => 'Any device with a position variable works (e.g. Z-Wave, Shelly, KNX, Homematic, EnOcean, Zigbee). Shading position 0 = default of the type (roller shutter 75 %, venetian blind and awning 100 %). Use the test run to check the direction.'],
        ]];

        // 4. Wetterschutz
        $vorschlag = $this->sensorVorschlag();
        $warnHub = function_exists('WHUB_GetActiveWarnings') && IPS_GetInstanceListByModuleID(self::WARNHUB_GUID) !== [];
        $deckt = $this->warnHubDecktStation();
        $sicht = !$deckt; // Felder, die WarnHub mit der Wetterstation überflüssig macht
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '🌬️  Weather protection', 'items' => [
            ['type' => 'Label', 'caption' => $this->wetterStatus($warnHub)],
            ['type' => 'Label', 'name' => 'WarnHubDeckt', 'visible' => $deckt, 'caption' => $this->warnHubDecktText()],
            ['type' => 'Label', 'caption' => 'Awnings and venetian blinds retract on wind and severe weather — this has priority over everything, also over manual operation. Roller shutters are not moved by wind.'],
            ['type' => 'SelectVariable', 'name' => 'WindID', 'caption' => 'Wind gust (own sensor)', 'visible' => $sicht],
            ['type' => 'Label', 'name' => 'WindIDVerbindung', 'visible' => $sicht, 'caption' => $this->verbindungsText('WindID', $this->ReadPropertyInteger('WindID'), $vorschlag)],
            ['type' => 'Label', 'name' => 'WindIDHint', 'visible' => $sicht, 'caption' => 'Wind speed or gust of your weather station or wind sensor. The unit (km/h, m/s, mph) is read from the profile.'],
            ['type' => 'RowLayout', 'name' => 'WindSchwellen', 'visible' => $sicht, 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'WindMarkise', 'caption' => 'Awning: retract from', 'suffix' => 'km/h', 'minimum' => 0, 'maximum' => 150],
                ['type' => 'NumberSpinner', 'name' => 'WindRaffstore', 'caption' => 'Venetian blind: retract from', 'suffix' => 'km/h', 'minimum' => 0, 'maximum' => 150],
            ]],
            ['type' => 'Label', 'name' => 'WindMarkiseHint', 'visible' => $sicht, 'caption' => 'Defaults: awning 35 km/h (just below wind resistance class 2), venetian blind 50 km/h. Check the data sheet of your product.'],
            ['type' => 'SelectVariable', 'name' => 'RegenID', 'caption' => 'Rain sensor (optional)', 'visible' => $sicht],
            ['type' => 'Label', 'name' => 'RegenIDVerbindung', 'visible' => $sicht, 'caption' => $this->verbindungsText('RegenID', $this->ReadPropertyInteger('RegenID'), $vorschlag)],
            ['type' => 'Label', 'name' => 'RegenIDHint', 'visible' => $sicht, 'caption' => 'Retracts awnings when it rains (true or a value above 0).'],
            ['type' => 'NumberSpinner', 'name' => 'Haltezeit', 'caption' => 'Wait after wind/rain', 'suffix' => 'min', 'minimum' => 1, 'maximum' => 240],
            ['type' => 'SelectVariable', 'name' => 'SperreID', 'caption' => 'External lock (optional)'],
            ['type' => 'Label', 'name' => 'SperreIDVerbindung', 'caption' => $this->verbindungsText('SperreID', $this->ReadPropertyInteger('SperreID'), $vorschlag)],
            ['type' => 'Label', 'name' => 'SperreIDHint', 'caption' => 'A variable that is "true" while another system reports danger, e.g. the wind alarm of a KNX weather station.'],
            ['type' => 'CheckBox', 'name' => 'WarnHubNutzen', 'caption' => 'Use WarnHub warnings', 'onChange' => 'VSCH_UIWetterSicht($id, (bool)$WarnHubNutzen, false);'],
            ['type' => 'Label', 'name' => 'WarnHubNutzenHint', 'caption' => $warnHub
                ? self::fest('WarnHub found. Storm, hail and thunderstorm warnings for your fixed locations retract awnings and venetian blinds, from the lead time of the WarnHub protective actions on. Frost and snow keep them still. Devices that a WarnHub protective action has moved stay still until it ends plus the waiting time. If WarnHub evaluates your weather station, do not select it here as well.')
                : self::fest('WarnHub is not installed. It is optional: official warnings (DWD, GeoSphere, MeteoSwiss) are an additional safety net.')],
            ['type' => 'CheckBox', 'name' => 'NetzWetter', 'caption' => 'Gust forecast from the internet', 'visible' => $sicht],
            ['type' => 'Label', 'name' => 'NetzWetterHint', 'visible' => $sicht, 'caption' => 'Open-Meteo, every 15 minutes. Slower than a sensor and does not replace one: the limit is 10 km/h lower than for the sensor.'],
            ['type' => 'CheckBox', 'name' => 'MarkiseOhneWetter', 'caption' => 'Extend awnings without weather source', 'visible' => $sicht],
            ['type' => 'Label', 'name' => 'MarkiseOhneWetterHint', 'visible' => $sicht, 'caption' => 'Without any weather source awnings never extend automatically. Tick this only if you accept the risk (e.g. a sheltered awning).'],
        ]];

        // 4b. Notfall
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '🔥  Emergency', 'items' => [
            ['type' => 'Label', 'caption' => 'If a smoke or fire detector reports an alarm, all devices go up immediately – also at night, after manual operation and with the automatic switched off – to keep escape routes free. Afterwards the automatic continues; nothing is lowered at night.'],
            ['type' => 'List', 'name' => 'Notfall', 'caption' => 'Smoke/fire detectors', 'rowCount' => 4, 'add' => true, 'delete' => true,
                'columns' => [['caption' => 'Alarm variable', 'name' => 'Melder', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']]]],
            ['type' => 'Label', 'caption' => '"Detect devices and sensors" enters the detectors. Empty list = no emergency opening.'],
        ]];

        // 5. Feinheiten
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => '🌡️  Fine tuning', 'items' => [
            ...$this->sonnenFelder('', $vorschlag),
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
        $actions[] = $this->assistentFormular($fassaden, $optTyp);
        $actions[] = ['type' => 'ExpansionPanel', 'caption' => '🔧  Assign devices & test run', 'expanded' => $fassaden !== [] && $this->geraete() === [], 'items' => [
            ['type' => 'Label', 'caption' => 'Choose a facade and check the direction with the test run (only saved devices move).'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'ZuordnenFassade', 'caption' => 'Facade', 'options' => $optFassade],
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
                ['type' => 'CheckBox', 'name' => 'VorschauGebaeude', 'caption' => 'Show neighbouring buildings', 'value' => true, 'onChange' => $vorschauAufruf],
            ]],
            ['type' => 'Image', 'name' => 'Himmel', 'image' => $this->himmelBild($fassaden, 0, 'heute'), 'center' => true, 'width' => '560px'],
            ['type' => 'Label', 'name' => 'Vorschau', 'caption' => $this->vorschauText($fassaden, 0, 'heute')],
            ['type' => 'Label', 'caption' => 'Light grey: terrain horizon (PVGIS, true to scale). Dark grey: neighbouring buildings (model: storeys × 3 m, without data 2 storeys, 45° gable roof). Coloured band: the part of the sky from which the chosen facade gets sun. Whether shading actually moves then depends on irradiance and temperature.'],
        ]];

        $actions[] = ['type' => 'Label', 'caption' => $this->t('Current state: %s', (string)@$this->GetValue('Status'))];
        $actions[] = ['type' => 'Button', 'caption' => '❓ Why? (test run, nothing moves)', 'onClick' => 'echo VSCH_UIWarum($id);'];

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

    private function himmelBild(array $fassaden, int $auswahl, string $tag, bool $gebaeude = true): string
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
            VSCHGrafik::FARBEN[$auswahl % count(VSCHGrafik::FARBEN)], date_default_timezone_get(), $this->t('Horizon'),
            $f !== null && $gebaeude ? $this->gebaeudeHorizont((float)$f['Azimut']) : [], $this->t('Neighbouring buildings'));
    }

    private function vorschauText(array $fassaden, int $auswahl, string $tag, bool $gebaeude = true): string
    {
        $pos = $this->koordinaten();
        if ($pos === null || $fassaden === []) {
            return $this->t('The preview appears once facades are known.');
        }
        $bahn = VSCHSonne::bahn($pos[0], $pos[1], $this->tagDatum($tag), date_default_timezone_get(), 5);
        $teile = [];
        foreach ($fassaden as $i => $f) {
            if (!(bool)($f['Aktiv'] ?? true)) {
                continue; // Fassade ohne Gerät: keine Sonnenzeiten
            }
            $fenster = VSCHGrafik::sonnenfenster($bahn, $gebaeude ? $this->hindernisPunkte((float)$f['Azimut']) : $this->horizontPunkte(), (float)$f['Azimut']);
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
            $out[] = ['az' => (float)$f['Azimut'], 'seg' => $seg, 'aktiv' => (bool)($f['Aktiv'] ?? true)];
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
    /**
     * Betriebsart-Variable einer Wärmepumpe aus dem Verbundvertrag `heatpump` (<PRÄFIX>_GetFunctions, Feld operatingModeNormID,
     * Enum 0 standby/1 heizen/2 kühlen/3 WW/4 heizen+WW/5 kühlen+WW). Allgemeine Suche wie NRGDashboard/EMS, Zeitbudget 3 s.
     */
    private function wpErkennen(): int
    {
        $t0 = microtime(true);
        foreach (IPS_GetModuleList() as $guid) {
            if (microtime(true) - $t0 > 3.0) {
                break;
            }
            $mod = @IPS_GetModule($guid);
            $fn = (string)($mod['Prefix'] ?? '') . '_GetFunctions';
            if (!is_array($mod) || (int)($mod['ModuleType'] ?? 0) !== 3 || ($mod['Prefix'] ?? '') === '' || !function_exists($fn)) {
                continue;
            }
            foreach (IPS_GetInstanceListByModuleID($guid) as $id) {
                if ((int)(IPS_GetInstance($id)['InstanceStatus'] ?? 0) === 104) {
                    continue;
                }
                try {
                    $data = call_user_func($fn, $id);
                } catch (\Throwable $e) {
                    continue 2;
                }
                $data = is_string($data) ? json_decode($data, true) : $data;
                if (!is_array($data) || $data === []) {
                    continue;
                }
                foreach (array_is_list($data) ? $data : [$data] as $e) {
                    $typ = (string)($e['contractType'] ?? ($e['Type'] ?? ($e['type'] ?? ($e['function'] ?? ''))));
                    $vid = (int)($e['operatingModeNormID'] ?? 0);
                    if ($typ === 'heatpump' && $vid > 0 && IPS_VariableExists($vid)) {
                        return $vid;
                    }
                }
            }
        }
        return 0;
    }

    /**
     * Klarer Himmel an diesem Ort mittags: [Juni, Dezember] in W/m² (Haurwitz). Zeigt am Schieberegler,
     * welche Einstrahlung bei uns überhaupt möglich ist. Ohne Standort: 50° N.
     */
    private function einstrahlungBreite(): array
    {
        $lat = abs((float)($this->koordinaten()[0] ?? 50.0));
        return [(int)(round(VSCHLogik::klarHimmel(90 - $lat + 23.44) / 10) * 10), (int)(round(VSCHLogik::klarHimmel(max(1.0, 90 - $lat - 23.44)) / 10) * 10)];
    }

    /**
     * Felder „Wann beschatten“ (Formular und Assistent Schritt 7): Einstrahlung, Außen-/Raumtemperatur, Raumhelligkeit,
     * alles mit Schiebereglern. $p = Namenspräfix ('' im Formular, 'W' im Assistenten).
     */
    private function sonnenFelder(string $p, array $vorschlag): array
    {
        [$juni, $dez] = $this->einstrahlungBreite();
        $max = (int)(ceil(($juni + 50) / 100) * 100);
        $a = $p === 'W';
        $wert = fn(string $prop) => $a ? ['value' => $this->ReadPropertyFloat($prop)] : [];
        $sel = fn(string $prop) => $a ? $this->auswahlWert($prop) : [];
        $felder = [
            ['type' => 'Label', 'caption' => $this->t('The module decides with measured values, not only with the position of the sun.')],
            ['type' => 'Label', 'caption' => '☀️ ' . $this->t('Sun: irradiance')],
            ['type' => 'SelectVariable', 'name' => $p . ($a ? 'Hell' : 'HelligkeitID'), 'caption' => $this->t('Irradiance or outdoor brightness')] + $sel('HelligkeitID'),
            ['type' => 'Label', 'name' => $p . ($a ? 'HellInfo' : 'HelligkeitIDVerbindung'), 'caption' => $this->verbindungsText('HelligkeitID', $this->ReadPropertyInteger('HelligkeitID'), $vorschlag)],
            ['type' => 'HorizontalSlider', 'displayValue' => true, 'suffix' => ' W/m²', 'name' => $p . 'SonneAn', 'caption' => $this->t('Shade from (W/m²)'), 'minimum' => 0, 'maximum' => $max, 'stepSize' => 10, 'width' => '600px'] + $wert('SonneAn'),
        ];
        if (!$a) {
            $felder[] = ['type' => 'HorizontalSlider', 'displayValue' => true, 'suffix' => ' W/m²', 'name' => 'SonneAus', 'caption' => $this->t('End below (W/m²)'), 'minimum' => 0, 'maximum' => $max, 'stepSize' => 10, 'width' => '600px'];
        }
        return array_merge($felder, [
            ['type' => 'Label', 'name' => $p . 'SonneHint', 'caption' => $this->t('Clear sky at your latitude at noon: about %d W/m² in June, about %d W/m² in December. Thin clouds roughly halve it, overcast sky gives below 150 W/m². Lux sensors are converted (1 W/m² ≈ 120 lx).', $juni, $dez)],
            ['type' => 'Label', 'caption' => '🌡️ ' . $this->t('Summer or winter: temperatures')],
            ['type' => 'SelectVariable', 'name' => $p . ($a ? 'Temp' : 'TemperaturID'), 'caption' => $this->t('Outdoor temperature')] + $sel('TemperaturID'),
            ['type' => 'Label', 'name' => $p . ($a ? 'TempInfo' : 'TemperaturIDVerbindung'), 'caption' => $this->verbindungsText('TemperaturID', $this->ReadPropertyInteger('TemperaturID'), $vorschlag)],
            ['type' => 'HorizontalSlider', 'displayValue' => true, 'suffix' => ' °C', 'name' => $p . 'AussenSommer', 'caption' => $this->t('Summer from outdoor temperature (°C)'), 'minimum' => 0, 'maximum' => 35, 'stepSize' => 1, 'width' => '600px'] + $wert('AussenSommer'),
            ['type' => 'HorizontalSlider', 'displayValue' => true, 'suffix' => ' °C', 'name' => $p . 'InnenGrenze', 'caption' => $this->t('Room too warm from (°C)'), 'minimum' => 18, 'maximum' => 30, 'stepSize' => 0.5, 'width' => '600px'] + $wert('InnenGrenze'),
            ['type' => 'HorizontalSlider', 'displayValue' => true, 'suffix' => ' °C', 'name' => $p . 'HeizGrenze', 'caption' => $this->t('Room needs heat below (°C)'), 'minimum' => 15, 'maximum' => 25, 'stepSize' => 0.5, 'width' => '600px'] + $wert('HeizGrenze'),
            ['type' => 'SelectVariable', 'name' => $p . ($a ? 'WP' : 'WPModusID'), 'caption' => $this->t('Heat pump: operating mode (optional)')] + $sel('WPModusID'),
            ['type' => 'Label', 'name' => $p . 'WPHint', 'caption' => $this->t('Found automatically from NRG-Stack heat pump modules. Heating → the sun may warm the house; cooling → shade.')],
            ['type' => 'Label', 'name' => $p . 'TempHint', 'caption' => $this->t('The device is the cheapest heating and cooling of the room: room too warm, heat pump cooling or summer outside → shade. Room below the heating limit, heat pump heating or cool outside → the sun warms the house. In between only glare protection (if set up). The room temperature comes from the device list (column "Room temperature"). Without temperatures the module shades whenever the sun shines.')],
            ['type' => 'Label', 'caption' => '💡 ' . $this->t('Room brightness: only for lowering')],
            ['type' => 'HorizontalSlider', 'displayValue' => true, 'suffix' => ' lx', 'name' => $p . 'RaumHellLux', 'caption' => $this->t('Sun reaches the room from (lx)'), 'minimum' => 0, 'maximum' => 2000, 'stepSize' => 10, 'width' => '600px'] + $wert('RaumHellLux'),
            ['type' => 'Label', 'name' => $p . 'RaumHellHint', 'caption' => $this->t('If the brightness sensor of the room (column "Room brightness") stays below, the sun does not reach that room and the device stays up. Raising never depends on it: with the device down the room is dark anyway. Indoor sensors measure much less than outside: sun in the room is about 200–2000 lx, clouds below 100 lx.')],
        ]);
    }

    private function koordinaten(): ?array
    {
        if ($this->koordinatenAssistent !== null) {
            return $this->koordinatenAssistent;
        }
        $eigen = json_decode($this->ReadPropertyString('Standort'), true);
        if (is_array($eigen) && ((float)($eigen['latitude'] ?? 0) != 0.0 || (float)($eigen['longitude'] ?? 0) != 0.0)) {
            return [(float)$eigen['latitude'], (float)$eigen['longitude']];
        }
        return $this->standortInstanz();
    }

    /** [lat, lon] der Standort-Instanz (Kerninstanz „Location“) oder null. */
    private function standortInstanz(): ?array
    {
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

    /** Nachbargebäude als Höhenwinkel je Grad [az => °], gesehen von der Fassade mit Richtung $fassadeAz. */
    private function gebaeudeHorizont(float $fassadeAz): array
    {
        $haus = json_decode($this->ReadAttributeString('Haus'), true) ?: [];
        $nachbarn = (array)($haus['nachbarn'] ?? []);
        if ($nachbarn === []) {
            return [];
        }
        return VSCHGebaeude::horizont($nachbarn, (array)($haus['nachbarInfo'] ?? []), VSCHGebaeude::auge($haus, $fassadeAz));
    }

    /** Hindernis-Horizont für eine Fassade: Gelände (PVGIS) und Nachbargebäude, je Grad das Höhere. */
    private function hindernisPunkte(?float $fassadeAz): array
    {
        if ($fassadeAz === null) {
            return $this->horizontPunkte();
        }
        $k = (string)round($fassadeAz, 1);
        return $this->hindernisMerk[$k] ??= VSCHGebaeude::zusammen($this->horizontPunkte(), $this->gebaeudeHorizont($fassadeAz));
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
        return $this->normalisieren($this->listeWerte($this->ReadPropertyString('Geraete')));
    }

    /** Geräteliste (Eigenschaft oder Assistent) in vollständige Einträge mit Schlüssel umwandeln. */
    private function normalisieren(array $liste): array
    {
        $out = [];
        foreach ($liste as $g) {
            $typ = in_array($g['Typ'] ?? '', VSCHLogik::TYPEN, true) ? $g['Typ'] : 'rollladen';
            $besch = (float)($g['Beschattung'] ?? 0);
            $out[] = [
                'Name' => (string)($g['Name'] ?? '') !== '' ? (string)$g['Name'] : $this->name((int)($g['Position'] ?? 0)),
                'Fassade' => (string)($g['Fassade'] ?? ''), 'Typ' => $typ, 'Position' => (int)($g['Position'] ?? 0),
                'Richtung' => (int)($g['Richtung'] ?? 0), 'Lamelle' => (int)($g['Lamelle'] ?? 0),
                'LamelleUmgekehrt' => (bool)($g['LamelleUmgekehrt'] ?? false), 'Kontakt' => (int)($g['Kontakt'] ?? 0), 'Kontakt2' => (int)($g['Kontakt2'] ?? 0),
                'Kontakt3' => (int)($g['Kontakt3'] ?? 0), 'Kontakt4' => (int)($g['Kontakt4'] ?? 0),
                'RaumTemp' => (int)($g['RaumTemp'] ?? 0), 'RaumHell' => (int)($g['RaumHell'] ?? 0),
                'BeiOffen' => (int)($g['BeiOffen'] ?? 0), 'Tiefe' => (int)($g['Tiefe'] ?? 0),
                'FensterHoehe' => (int)($g['FensterHoehe'] ?? 0), 'Bruestung' => (int)($g['Bruestung'] ?? 0),
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
        $p = (string)($v['VariableCustomProfile'] ?: $v['VariableProfile']);
        if ($p === '') {
            // Symcon 8+: Profil steckt ggf. nur in der Darstellung „Legacy-Profil“
            foreach (['VariableCustomPresentation', 'VariablePresentation'] as $f) {
                if (is_array($v[$f] ?? null) && !empty($v[$f]['PROFILE'])) {
                    return (string)$v[$f]['PROFILE'];
                }
            }
        }
        return $p;
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
        $roll = $this->darstellung($vid)[3];
        if ($roll !== [] && max($roll['open'], $roll['close']) > 0) {
            return max($roll['open'], $roll['close']);
        }
        $pres = $this->aufgeloest($vid);
        if ($pres === []) {
            $v = IPS_GetVariable($vid);
            $pres = $v['VariableCustomPresentation'] ?? [];
            if (!is_array($pres) || $pres === []) {
                $pres = $v['VariablePresentation'] ?? [];
            }
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
