<?php

declare(strict_types=1);

/**
 * Einrichtungsassistent (Symcon 9.1: PopupAlert mit mehreren Seiten).
 *
 * Ablauf: Standort → Haus → Geräte (nur die Fassade wählt der Nutzer) → Probefahrt → Wetterschutz (nur bei
 * Markise/Raffstore) → Wann beschatten → Zusammenfassung. Der Assistent speichert nichts selbst (Store-Regel:
 * Eigenschaften gehören dem Nutzer); er füllt am Ende das Formular, der Nutzer bestätigt mit „Änderungen übernehmen“.
 *
 * Skripte der Seiten laufen ohne $this; Listen kommen als Symcon-Listenobjekt an und werden erst kopiert.
 * Alle Feldnamen tragen das Präfix W, damit sie über alle Seiten eindeutig sind.
 */
trait VSCHAssistent
{
    /** Feste Fassadenkennungen für die Auswahl im Assistenten (die Fassaden entstehen erst auf Seite 2). */
    private static function assistentKennungen(): array
    {
        $k = [];
        for ($i = 0; $i < 12; $i++) {
            $k[] = VSCHGrafik::kennung($i);
        }
        return $k;
    }

    // ------------------------------------------------------------------ Formular

    /** PopupAlert mit allen Seiten. Öffnet sich von selbst, solange weder Fassaden noch Geräte eingetragen sind. */
    private function assistentFormular(array $fassaden, array $optTyp): array
    {
        $geraete = $this->listeWerte($this->ReadPropertyString('Geraete'));
        $offen = $fassaden === [] && $geraete === [];
        $liste = function (string $feld): string {
            return '$' . $feld . 'A = []; foreach ($' . $feld . ' as $x) { $' . $feld . 'A[] = $x; } ';
        };
        $standortJson = 'is_string($WStandort) ? $WStandort : json_encode($WStandort)';
        $optFassade = [['caption' => $this->t('— choose —'), 'value' => '']];
        foreach (self::assistentKennungen() as $k) {
            $optFassade[] = ['caption' => $k, 'value' => $k];
        }
        $optFassade[] = ['caption' => $this->t('do not shade'), 'value' => '-'];
        $optProbe = array_slice($optFassade, 1, 12);
        $hatLocation = $this->standortInstanz() !== null;
        $warnHub = function_exists('WHUB_GetActiveWarnings') && IPS_GetInstanceListByModuleID(self::WARNHUB_GUID) !== [];
        $deckt = $this->warnHubDecktStation();

        // Standort: immer die Standort-Instanz von Symcon (Dietmar 25.11.: „Warum sollte ich ein anderes Haus nehmen?“).
        // Nur ohne Standort-Instanz – oder mit eigenem Standort aus dem Formular – zählt der Standort des Moduls.
        $art = $hatLocation && !$this->eigenerStandort() ? 0 : 1;
        $standortJson = $hatLocation ? json_encode($this->ReadPropertyString('Standort')) : $standortJson;
        $vorschlag = $fassaden !== [] ? $fassaden : $this->fassadenAusHaus();
        if ($offen) {
            $this->WriteAttributeString('AssistentEntwurf', '{}'); // öffnet sich von selbst: neuer Durchgang, kein alter Entwurf
        }
        $erkennung = '';
        if ($vorschlag === [] && $offen && $this->koordinaten() !== null) {
            // Live-Befund 25.11.: Der Assistent öffnet sich von selbst, bevor der Hintergrund fertig ist – dann jetzt erkennen,
            // ohne Straßen (die holt der Hintergrund nach), damit die Seite nicht leer mit „wird erkannt“ stehen bleibt
            $r = $this->hausErkennen(false, self::ERKENNEN_SOFORT_S);
            $vorschlag = $r['werte'];
            $erkennung = $r['ok'] ? '' : (($r['hintergrund'] ?? false) ? '⏳ ' : '⛔ ') . $r['text'];
        }
        $seiten = [
            [
                'name' => 'haus', 'caption' => $this->t('Step 1 of 6 · House'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('The house is detected automatically from official map data. Please check the facades – correct or add them in the list if needed.')],
                    ['type' => 'SelectLocation', 'name' => 'WStandort', 'caption' => $this->t('House'), 'value' => $this->ReadPropertyString('Standort'), 'visible' => !$hatLocation],
                    ['type' => 'Button', 'caption' => $this->t('🏠 Detect house'), 'visible' => !$hatLocation,
                        'onClick' => 'VSCH_UIAssistentHaus($id, ' . $art . ', ' . $standortJson . ');'],
                    ['type' => 'Label', 'name' => 'WHausStatus', 'caption' => $erkennung !== '' ? $erkennung : ($vorschlag === [] ? ($hatLocation ? $this->t('The house is detected when you click "Next".') : $this->t('No Location instance: choose the house on the map and click "Detect house".')) : '🔗 ' . $this->geoStatus())],
                    ['type' => 'Image', 'name' => 'WGrundriss', 'image' => $this->grundrissBild($vorschlag, -1, 'heute', self::PLAN_ASSISTENT), 'center' => true, 'width' => '900px'],
                    ['type' => 'List', 'name' => 'WFassaden', 'rowCount' => max(6, count($vorschlag)), 'add' => true, 'delete' => true, 'values' => $vorschlag, 'columns' => [
                        ['caption' => $this->t('Letter'), 'name' => 'Kennung', 'width' => '70px', 'add' => '?', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->t('Name'), 'name' => 'Name', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->t('Direction (°)'), 'name' => 'Azimut', 'width' => '120px', 'add' => 180, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 359, 'digits' => 1]],
                        ['caption' => $this->t('Active'), 'name' => 'Aktiv', 'width' => '80px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ]],
                ],
                'validate' => $liste('WFassaden') . 'return VSCH_UIAssistentPruefen($id, "haus", json_encode($WFassadenA));',
                'onConfirm' => $liste('WFassaden') . $liste('WGeraete') . 'VSCH_UIAssistentGeraete($id, json_encode($WFassadenA), json_encode($WGeraeteA), ' . $art . ', ' . $standortJson . ');',
                // Zurück auf diese Seite: Symcon baut sie aus der Formular-Vorlage neu – die gemerkten Eingaben wieder einsetzen
                'onUndo' => 'VSCH_UIAssistentZurueck($id, "haus");',
            ],
            [
                'name' => 'geraete', 'caption' => $this->t('Step 2 of 6 · Devices'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('All roller shutters, venetian blinds and awnings found. Choose the facade for each one – that is all.')],
                    ['type' => 'Label', 'caption' => $this->t('Tip: put the devices in a folder named after the direction (e.g. "South side") or write "Orientation: South" in their info field – then the facade is filled in automatically.')],
                    ['type' => 'Label', 'name' => 'WLegende', 'caption' => $this->fassadenLegende($fassaden)],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'WAlleFassade', 'caption' => $this->t('All devices to facade'), 'options' => $optProbe, 'value' => 'A'],
                        ['type' => 'Button', 'caption' => $this->t('Apply to all'),
                            'onClick' => $liste('WGeraete') . 'VSCH_UIAssistentAlle($id, json_encode($WGeraeteA), (string)$WAlleFassade);'],
                    ]],
                    ['type' => 'Image', 'name' => 'WGeraeteBild', 'image' => $this->grundrissBild($vorschlag, -1, 'heute', self::PLAN_ASSISTENT), 'center' => true, 'width' => '900px'],
                    ['type' => 'List', 'name' => 'WGeraete', 'rowCount' => max(10, count($this->geraete()) + 1), 'add' => false, 'delete' => true, 'values' => $geraete, 'columns' => [
                        ['caption' => $this->t('Device'), 'name' => 'Name', 'width' => 'auto', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->t('Facade'), 'name' => 'Fassade', 'width' => '110px', 'edit' => ['type' => 'Select', 'options' => $optFassade]],
                        ['caption' => $this->t('Type'), 'name' => 'Typ', 'width' => '140px', 'edit' => ['type' => 'Select', 'options' => $optTyp]],
                        ['caption' => $this->t('0 % means'), 'name' => 'Richtung', 'width' => '140px', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => $this->t('automatic (profile)'), 'value' => 0], ['caption' => $this->t('open / retracted'), 'value' => 1], ['caption' => $this->t('closed / extended'), 'value' => 2]]]],
                        ['caption' => $this->t('Window/door contact'), 'name' => 'Kontakt', 'width' => '160px', 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Contact 2'), 'name' => 'Kontakt2', 'visible' => false, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Contact 3'), 'name' => 'Kontakt3', 'visible' => false, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Contact 4'), 'name' => 'Kontakt4', 'visible' => false, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Room temperature'), 'name' => 'RaumTemp', 'width' => '160px', 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Room brightness'), 'name' => 'RaumHell', 'width' => '160px', 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Position', 'name' => 'Position', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Slats (optional)'), 'name' => 'Lamelle', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Slats 0 % = closed'), 'name' => 'LamelleUmgekehrt', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Shading position (%)'), 'name' => 'Beschattung', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Contact open'), 'name' => 'BeiOffen', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Sun max. into room (cm)'), 'name' => 'Tiefe', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Target brightness (lx)'), 'name' => 'ZielLux', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Window height (cm)'), 'name' => 'FensterHoehe', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Sill height (cm)'), 'name' => 'Bruestung', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Window area (m²)'), 'name' => 'Flaeche', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('At night'), 'name' => 'Nachts', 'visible' => false, 'save' => true],
                    ]],
                    ['type' => 'Label', 'name' => 'WGeraeteHinweis', 'caption' => $this->t('Missing a device? Add it later in the device list of the form with "+".')],
                    ['type' => 'CheckBox', 'name' => 'WInfoSchreiben', 'caption' => $this->t('Also write to device info field'), 'value' => false],
                    ['type' => 'Label', 'caption' => $this->t('Optional: writes the line "Orientation: South" into the info field of each device, so other modules can read it. Other text there stays.')],
                ],
                'validate' => $liste('WFassaden') . $liste('WGeraete') . 'return VSCH_UIAssistentPruefen($id, "geraete", json_encode(["fassaden" => $WFassadenA, "geraete" => $WGeraeteA]));',
                // Live-Test 23.11.: Listenwerte, die im Bearbeiten-Dialog geändert wurden, kommen auf späteren Seiten nicht an –
                // deshalb hier (aktuelle Seite) als Entwurf merken und später aus dem Entwurf lesen
                'onConfirm' => $liste('WGeraete') . 'VSCH_UIAssistentMerken($id, "geraete", json_encode($WGeraeteA)); VSCH_UIAssistentWerte($id, "geraeteFelder", json_encode(["WInfoSchreiben" => (bool)$WInfoSchreiben]));',
                'onUndo' => 'VSCH_UIAssistentZurueck($id, "geraete");',
            ],
            [
                'name' => 'probe', 'caption' => $this->t('Step 3 of 6 · Test run'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('Check the direction: "Shade" should lower the devices of the facade, "Back" raise them. If one moves the wrong way, go back and change its "0 % means" column.')],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'WProbeFassade', 'caption' => $this->t('Facade'), 'options' => $optProbe, 'value' => 'A'],
                        ['type' => 'Button', 'caption' => $this->t('⬇ Shade'),
                            'onClick' => 'VSCH_UIAssistentProbe($id, "", (string)$WProbeFassade, true);'],
                        ['type' => 'Button', 'caption' => $this->t('⬆ Back'),
                            'onClick' => 'VSCH_UIAssistentProbe($id, "", (string)$WProbeFassade, false);'],
                    ]],
                    ['type' => 'Label', 'name' => 'WProbeStatus', 'caption' => $this->t('The test run is optional.')],
                ],
                'nextPage' => 'return VSCH_UIAssistentWeiter($id, "");',
            ],
            [
                'name' => 'wetter', 'caption' => $this->t('Step 4 of 6 · Weather protection'),
                'items' => [
                    ['type' => 'Label', 'name' => 'WWarnHubDeckt', 'visible' => $deckt, 'caption' => $this->warnHubDecktText()],
                    ['type' => 'Label', 'name' => 'WWetterText', 'visible' => !$deckt, 'caption' => $this->t('Awnings and venetian blinds retract on wind and severe weather. Choose at least one source.')],
                    ['type' => 'CheckBox', 'name' => 'WWarnHub', 'onChange' => 'VSCH_UIWetterSicht($id, (bool)$WWarnHub, true);', 'caption' => $warnHub ? $this->t('Use WarnHub warnings') : $this->t('WarnHub (not installed)'),
                        'value' => $this->ReadPropertyBoolean('WarnHubNutzen') && $warnHub, 'enabled' => $warnHub],
                    ['type' => 'SelectVariable', 'name' => 'WWind', 'visible' => !$deckt, 'caption' => $this->t('Wind gust (own sensor)')] + $this->auswahlWert('WindID'),
                    ['type' => 'Label', 'name' => 'WWindInfo', 'visible' => !$deckt, 'caption' => $this->verbindungsText('WindID', $this->ReadPropertyInteger('WindID'), ['hinweise' => []])],
                    ['type' => 'SelectVariable', 'name' => 'WRegen', 'visible' => !$deckt, 'caption' => $this->t('Rain sensor (optional)')] + $this->auswahlWert('RegenID'),
                    ['type' => 'Label', 'name' => 'WRegenInfo', 'visible' => !$deckt, 'caption' => $this->verbindungsText('RegenID', $this->ReadPropertyInteger('RegenID'), ['hinweise' => []])],
                    ['type' => 'SelectVariable', 'name' => 'WSperre', 'visible' => !$deckt, 'caption' => $this->t('External lock (optional)')] + $this->auswahlWert('SperreID'),
                    ['type' => 'Label', 'name' => 'WSperreInfo', 'visible' => !$deckt, 'caption' => $this->verbindungsText('SperreID', $this->ReadPropertyInteger('SperreID'), ['hinweise' => []])],
                    ['type' => 'CheckBox', 'name' => 'WNetzWetter', 'visible' => !$deckt, 'caption' => $this->t('Gust forecast from the internet'), 'value' => $this->ReadPropertyBoolean('NetzWetter')],
                    ['type' => 'CheckBox', 'name' => 'WMarkiseOhne', 'visible' => !$deckt, 'caption' => $this->t('Extend awnings without weather source'), 'value' => $this->ReadPropertyBoolean('MarkiseOhneWetter')],
                ],
                'onConfirm' => 'VSCH_UIAssistentWerte($id, "wetter", json_encode(["WWarnHub" => (bool)$WWarnHub, "WWind" => (int)$WWind, "WRegen" => (int)$WRegen, "WSperre" => (int)$WSperre, "WNetzWetter" => (bool)$WNetzWetter, "WMarkiseOhne" => (bool)$WMarkiseOhne]));',
                'onUndo' => 'VSCH_UIAssistentZurueck($id, "wetter");',
            ],
            [
                'name' => 'wann', 'caption' => $this->t('Step 5 of 6 · When to shade'),
                'items' => array_merge($this->sonnenFelder('W', ['hinweise' => []]), [
                    ['type' => 'Label', 'caption' => '🔥 ' . $this->t('Emergency')],
                    ['type' => 'CheckBox', 'name' => 'WNotfall', 'caption' => $this->t('Smoke detector alarm: all devices up'), 'value' => $this->notfallMelder() !== []],
                    ['type' => 'Label', 'name' => 'WNotfallInfo', 'caption' => $this->t('Keeps escape routes free – also at night and with the automatic switched off.')],
                ]),
                'onConfirm' => 'VSCH_UIAssistentWerte($id, "wann", json_encode(["WHell" => (int)$WHell, "WSonneAn" => (float)$WSonneAn, "WTemp" => (int)$WTemp, "WAussenSommer" => (float)$WAussenSommer, '
                    . '"WInnenGrenze" => (float)$WInnenGrenze, "WHeizGrenze" => (float)$WHeizGrenze, "WWP" => (int)$WWP, "WHeizung" => (int)$WHeizung, "WRaumHellLux" => (float)$WRaumHellLux, '
                    . '"WZielLux" => (float)$WZielLux, "WNotfall" => (bool)$WNotfall, "WHeisha" => (bool)$WHeisha])); '
                    . 'VSCH_UIAssistentZusammenfassung($id, "", "", json_encode(["hell" => (int)$WHell, "temp" => (int)$WTemp, "wp" => (int)$WWP, "notfall" => (bool)$WNotfall]));',
                'onUndo' => 'VSCH_UIAssistentZurueck($id, "wann");',
            ],
            [
                'name' => 'fertig', 'caption' => $this->t('Step 6 of 6 · Done'),
                'items' => [
                    ['type' => 'Label', 'name' => 'WZusammenfassung', 'caption' => ''],
                    ['type' => 'Label', 'caption' => $this->t('"OK" fills in the form. Then confirm with "Apply changes" – only then does the module start.')],
                ],
                'nextPage' => -1,
                'onConfirm' => 'echo VSCH_UIAssistentUebernehmen($id, json_encode(["art" => ' . $art . ', "standort" => ' . $standortJson . ', '
                    . '"info" => (bool)$WInfoSchreiben, "warnhub" => (bool)$WWarnHub, "wind" => (int)$WWind, "regen" => (int)$WRegen, "sperre" => (int)$WSperre, "netz" => (bool)$WNetzWetter, "ohne" => (bool)$WMarkiseOhne, '
                    . '"hell" => (int)$WHell, "sonneAn" => (float)$WSonneAn, "temp" => (int)$WTemp, "aussenSommer" => (float)$WAussenSommer, '
                    . '"innenGrenze" => (float)$WInnenGrenze, "raumHellLux" => (float)$WRaumHellLux, "zielLux" => (float)$WZielLux, "heizGrenze" => (float)$WHeizGrenze, "wp" => (int)$WWP, "heizung" => (int)$WHeizung, "heisha" => (bool)$WHeisha, "notfall" => (bool)$WNotfall]));',
            ],
        ];
        return [
            'type' => 'PopupAlert', 'name' => 'Assistent',
            'visible' => $offen,
            'popup' => ['caption' => $this->t('Setup assistant'), 'width' => '960px', 'closeCaption' => $this->t('Cancel'), 'initialPage' => 'haus', 'pages' => $seiten],
        ];
    }

    // ------------------------------------------------------------------ Aktionen der Seiten

    /** Wert für eine Variablenauswahl nur setzen, wenn er nicht 0 ist (0 zeigt Symcon als Fehler „muss eine Variable sein“). */
    private function auswahlWert(string $eigenschaft): array
    {
        $v = $this->ReadPropertyInteger($eigenschaft);
        return $v > 0 ? ['value' => $v] : [];
    }

    /** Entwurf des Assistenten (Fassaden/Geräte) merken – Grundlage für Probefahrt, Zusammenfassung und Übernahme. */
    public function UIAssistentMerken(string $teil, string $json): void
    {
        $e = json_decode($this->ReadAttributeString('AssistentEntwurf'), true) ?: [];
        $e[$teil] = $this->listeWerte($json);
        $this->WriteAttributeString('AssistentEntwurf', json_encode($e));
    }

    /** Einzelwerte einer Seite merken (Feldname => Wert), damit „Zurück“ sie wieder einsetzen kann. */
    public function UIAssistentWerte(string $teil, string $json): void
    {
        $e = json_decode($this->ReadAttributeString('AssistentEntwurf'), true) ?: [];
        $e['_' . $teil] = json_decode($json, true) ?: [];
        $this->WriteAttributeString('AssistentEntwurf', json_encode($e));
    }

    /**
     * „Zurück“ auf eine Seite (onUndo): Symcon stellt die Seite aus der Formular-Vorlage wieder her – die beim „Weiter“
     * gemerkten Eingaben (Listen und Einzelwerte) hier wieder einsetzen (Dietmar 25.11.: nichts darf verloren gehen).
     */
    public function UIAssistentZurueck(string $seite): void
    {
        $e = json_decode($this->ReadAttributeString('AssistentEntwurf'), true) ?: [];
        if ($seite === 'haus' && is_array($e['fassaden'] ?? null)) {
            $this->assistentHausFuellen(['ok' => true, 'werte' => $e['fassaden']]);
        }
        if ($seite === 'geraete' && is_array($e['geraete'] ?? null)) {
            $this->UpdateFormField('WGeraete', 'values', json_encode($e['geraete']));
        }
        $felder = (array)($e['_' . ($seite === 'geraete' ? 'geraeteFelder' : $seite)] ?? []);
        foreach ($felder as $name => $wert) {
            // Variablenauswahl: 0 nicht setzen (Symcon zeigt sonst „muss eine Variable sein“)
            if (is_int($wert) && $wert === 0 && in_array($name, ['WWind', 'WRegen', 'WSperre', 'WHell', 'WTemp', 'WWP'], true)) {
                continue;
            }
            $this->UpdateFormField($name, 'value', $wert);
        }
    }

    /** Entwurfsteil, sonst die übergebene Liste. */
    private function entwurf(string $teil, string $json): array
    {
        $e = json_decode($this->ReadAttributeString('AssistentEntwurf'), true) ?: [];
        return isset($e[$teil]) && is_array($e[$teil]) ? $e[$teil] : $this->listeWerte($json);
    }

    /** Assistent (erneut) öffnen, immer ab Seite 1. */
    public function UIAssistentOeffnen(): void
    {
        $this->WriteAttributeString('AssistentEntwurf', '{}');
        // Haus noch nicht erkannt (Hintergrund lief nicht oder scheiterte): jetzt erkennen, ohne dass jemand einen Knopf drückt
        if ($this->listeWerte($this->ReadPropertyString('Fassaden')) === [] && $this->fassadenAusHaus() === [] && $this->koordinaten() !== null) {
            $this->assistentHausFuellen($this->hausErkennen(false, self::ERKENNEN_SOFORT_S));
        }
        $this->UpdateFormField('Assistent', 'popup.initialPage', 'haus');
        $this->UpdateFormField('Assistent', 'visible', true);
    }

    /** Prüft eine Seite; Rückgabe '' = in Ordnung, sonst die Meldung (Symcon blockiert dann den Seitenwechsel). */
    public function UIAssistentPruefen(string $seite, string $daten): string
    {
        $d = json_decode($daten, true) ?: [];
        switch ($seite) {
            case 'haus':
                if ($d !== []) {
                    return '';
                }
                // Hintergrund ist inzwischen fertig: dessen Fassaden übernehmen
                if (($hinten = $this->fassadenAusHaus()) !== []) {
                    $this->assistentHausFuellen(['ok' => true, 'werte' => $hinten]);
                    return $this->t('House detected: please check the facades, then click "Next" again.');
                }
                // noch keine Fassaden: jetzt erkennen und die Seite füllen – der Nutzer prüft und klickt erneut „Weiter“
                if ($this->koordinaten() !== null) {
                    $r = $this->hausErkennen();
                    $this->assistentHausFuellen($r);
                    return $r['ok'] ? $this->t('House detected: please check the facades, then click "Next" again.') : $r['text'];
                }
                return $this->t('Please choose the house on the map and click "Detect house", or enter at least one facade.');
            case 'geraete':
                $kennungen = array_map(fn($f) => (string)($f['Kennung'] ?? ''), (array)($d['fassaden'] ?? []));
                $offen = [];
                foreach ((array)($d['geraete'] ?? []) as $g) {
                    $f = (string)($g['Fassade'] ?? '');
                    if ($f !== '-' && !in_array($f, $kennungen, true)) {
                        $offen[] = (string)($g['Name'] ?? '?');
                    }
                }
                if ($offen !== []) {
                    return $this->t('Please choose a facade (or "do not shade") for: %s', implode(', ', array_slice($offen, 0, 6)) . (count($offen) > 6 ? ' …' : ''));
                }
                return '';
        }
        return '';
    }

    /** Seite 1 ohne Standort-Instanz: Haus am gewählten Punkt erkennen und die Seite füllen. */
    public function UIAssistentHaus(int $art, string $standort): void
    {
        $this->koordinatenAssistent = $this->assistentKoordinaten($art, $standort);
        $this->assistentHausFuellen($this->hausErkennen());
    }

    /** Ergebnis der Hauserkennung auf Seite 1 des Assistenten zeigen. */
    private function assistentHausFuellen(array $r): void
    {
        if (!$r['ok']) {
            $this->UpdateFormField('WHausStatus', 'caption', (($r['hintergrund'] ?? false) ? '⏳ ' : '⛔ ') . $r['text']);
            return;
        }
        $this->UpdateFormField('WFassaden', 'values', json_encode($r['werte']));
        $this->UpdateFormField('WHausStatus', 'caption', '🔗 ' . $this->geoStatus() . ' · ' . $this->t('%d facades', count($r['werte'])));
        $this->UpdateFormField('WGrundriss', 'image', $this->grundrissBild($r['werte'], -1, 'heute', self::PLAN_ASSISTENT));
    }

    /** Beim Weiter von Seite 2: Geräte und Sensoren erkennen, Seite 3/5/6 vorbefüllen. Vorhandene Zeilen bleiben. */
    public function UIAssistentGeraete(string $fassadenJson, string $geraeteJson, int $art, string $standort): void
    {
        $this->koordinatenAssistent = $this->assistentKoordinaten($art, $standort);
        $fassaden = $this->listeWerte($fassadenJson);
        $inst = $this->momentaufnahme();
        $e = json_decode($this->ReadAttributeString('AssistentEntwurf'), true) ?: [];
        $geraete = $this->listeWerte($geraeteJson);
        if (is_array($e['geraete'] ?? null) && $e['geraete'] !== []) {
            // schon einmal auf der Geräte-Seite gewesen (Zurück → Weiter): die dortigen Eingaben behalten
            $geraete = $e['geraete'];
            $kennungen = array_map(fn($f) => (string)($f['Kennung'] ?? ''), $fassaden);
            foreach ($geraete as &$g) {
                if (!in_array((string)($g['Fassade'] ?? ''), array_merge($kennungen, ['-']), true)) {
                    $g['Fassade'] = ''; // Fassade gibt es nach einer Änderung auf Seite 1 nicht mehr → neu zuordnen
                }
            }
            unset($g);
        }
        $neu = VSCHErkennung::geraete($inst, array_map(fn($g) => (int)($g['Position'] ?? 0), $geraete));
        $alle = array_merge($geraete, $neu);
        foreach ($alle as &$g) {
            if ((string)($g['Fassade'] ?? '') === '') {
                $g['Fassade'] = VSCHErkennung::fassadeZuAzimut($g['AzHinweis'] ?? null, $fassaden) ?: self::fassadeAusName((string)($g['Name'] ?? ''), $fassaden);
            }
            unset($g['AzHinweis']);
        }
        unset($g);
        $this->UpdateFormField('WGeraete', 'values', json_encode($alle));
        // Probefahrt: die Fassade mit den meisten Geräten vorwählen (Live-Befund 24.11.: stand auf A, alle Geräte an B)
        $zahl = array_count_values(array_filter(array_map(fn($g) => (string)($g['Fassade'] ?? ''), $alle), fn($f) => $f !== '' && $f !== '-'));
        if ($zahl !== []) {
            arsort($zahl);
            // nur Fassaden mit Geräten anbieten, mit Namen („B · Süd“)
            $namen = array_column($fassaden, 'Name', 'Kennung');
            $this->UpdateFormField('WProbeFassade', 'options', json_encode(array_map(fn($k) => ['caption' => $k . (isset($namen[$k]) ? ' · ' . $namen[$k] : ''), 'value' => $k], array_keys($zahl))));
            $this->UpdateFormField('WProbeFassade', 'value', (string)array_key_first($zahl));
        }
        $this->UIAssistentMerken('fassaden', json_encode($fassaden));
        $this->UIAssistentMerken('geraete', json_encode($alle));
        $this->UpdateFormField('WLegende', 'caption', $this->fassadenLegende($fassaden));
        $this->UpdateFormField('WGeraeteBild', 'image', $this->grundrissBild($fassaden, -1, 'heute', self::PLAN_ASSISTENT));
        $this->UpdateFormField('WGeraeteHinweis', 'caption', $neu === []
            ? $this->t('Missing a device? Add it later in the device list of the form with "+".')
            : $this->t('%d devices detected (%d with window contact).', count($neu), count(array_filter($neu, fn($g) => (int)($g['Kontakt'] ?? 0) > 0))));

        $sens = VSCHErkennung::sensoren($inst, $this->warnHubWetterstation());
        foreach (['WindID' => 'WWind', 'RegenID' => 'WRegen', 'SperreID' => 'WSperre', 'HelligkeitID' => 'WHell', 'TemperaturID' => 'WTemp'] as $rolle => $feld) {
            $id = $this->ReadPropertyInteger($rolle);
            if ($sens[$rolle] > 0 && $id === 0) {
                $id = $sens[$rolle];
                $this->UpdateFormField($feld, 'value', $id);
            }
            // sichtbar machen, woher der Wert kommt und dass er lebt
            $this->UpdateFormField($feld . 'Info', 'caption', $this->verbindungsText($rolle, $id, $sens));
        }
        if ($this->ReadPropertyInteger('WPModusID') === 0 && ($w = $this->wpErkennen()) > 0) {
            $this->UpdateFormField('WWP', 'value', $w);
        }
        $melder = VSCHErkennung::rauchmelder($inst);
        $this->UpdateFormField('WNotfall', 'value', $melder !== []);
        $this->UpdateFormField('WNotfallInfo', 'caption', $melder === []
            ? $this->t('No smoke detector found.')
            : $this->t('%d smoke detectors found. Keeps escape routes free – also at night and with the automatic switched off.', count($melder)));
        // Wertet WarnHub die Wetterstation aus, sagt das die Seite ausdrücklich (WWarnHubDeckt) – hier nichts weiter
    }


    /**
     * Gespeicherte Gerätezeile für den Assistenten: fehlende Felder (aus älteren Versionen) mit 0 auffüllen und – wo leer –
     * Kontakte und Raumsensoren wie bei neu erkannten Geräten ergänzen. Live-Befund 24.11.: ohne das zeigte die Liste
     * „Objekt # existiert nicht“ und eine leere Spalte „0 % bedeutet“.
     */
    private function gespeichertErgaenzen(array $g, array $inst): array
    {
        $g += ['Richtung' => 0, 'Lamelle' => 0, 'LamelleUmgekehrt' => false, 'Kontakt' => 0, 'Kontakt2' => 0, 'Kontakt3' => 0, 'Kontakt4' => 0,
            'Beschattung' => 0, 'RaumTemp' => 0, 'RaumHell' => 0, 'BeiOffen' => 0, 'Tiefe' => 0, 'FensterHoehe' => 0, 'Bruestung' => 0, 'ZielLux' => 0, 'Flaeche' => 0, 'Nachts' => 0];
        $p = (int)($g['Position'] ?? 0);
        if ($p <= 0 || !IPS_VariableExists($p)) {
            return $g;
        }
        $i = IPS_GetParent($p);
        $text = ($inst[$i]['name'] ?? (string)($g['Name'] ?? '')) . ' ' . ($inst[$i]['ort'] ?? '');
        if ((int)$g['Kontakt'] + (int)$g['Kontakt2'] + (int)$g['Kontakt3'] + (int)$g['Kontakt4'] === 0) {
            $k = VSCHErkennung::kontakteFuer($text, VSCHErkennung::kontakte($inst));
            [$g['Kontakt'], $g['Kontakt2'], $g['Kontakt3'], $g['Kontakt4']] = [$k[0] ?? 0, $k[1] ?? 0, $k[2] ?? 0, $k[3] ?? 0];
        }
        $raum = null;
        foreach (['RaumTemp' => 'temp', 'RaumHell' => 'hell'] as $sp => $art) {
            if ((int)$g[$sp] === 0) {
                $raum ??= VSCHErkennung::raumSensoren($inst);
                $g[$sp] = VSCHErkennung::raumSensorFuer($text, $raum, $art);
            }
        }
        return $g;
    }





    /** Seite 3: alle Geräte auf eine Fassade (häufig: nur die Sonnenseite ist motorisiert). Einzelne danach per Zahnrad ändern. */
    public function UIAssistentAlle(string $geraeteJson, string $fassade): void
    {
        $g = $this->listeWerte($geraeteJson);
        foreach ($g as &$z) {
            $z['Fassade'] = $fassade;
        }
        unset($z);
        $this->UpdateFormField('WGeraete', 'values', json_encode($g));
        $this->UIAssistentMerken('geraete', json_encode($g));
    }

    /** Seite 4: Probefahrt mit den Geräten aus dem Assistenten (noch nicht gespeichert). */
    public function UIAssistentProbe(string $geraeteJson, string $fassade, bool $beschatten): void
    {
        $n = 0;
        foreach ($this->normalisieren($this->entwurf('geraete', $geraeteJson)) as $g) {
            if ($g['Fassade'] !== $fassade || $g['Position'] <= 0 || !IPS_VariableExists($g['Position'])) {
                continue;
            }
            $z = [];
            $this->ausfuehren($g, $beschatten ? 'beschatten' : 'offen', $beschatten && $g['Lamelle'] > 0 ? 45.0 : null, $z, $this->now());
            $n++;
        }
        $this->UpdateFormField('WProbeStatus', 'caption', $n === 0
            ? $this->t('Facade %s has no devices.', $fassade)
            : ($n === 1 ? $this->t('1 device of facade %s is moving.', $fassade) : $this->t('%d devices of facade %s are moving.', $n, $fassade)));
    }

    /** Nach der Probefahrt: Wetterschutz nur, wenn es Markisen oder Raffstores gibt. */
    public function UIAssistentWeiter(string $geraeteJson): string
    {
        foreach ($this->entwurf('geraete', $geraeteJson) as $g) {
            if (($g['Fassade'] ?? '') !== '-' && in_array($g['Typ'] ?? '', ['markise', 'raffstore'], true)) {
                return 'wetter';
            }
        }
        return 'wann';
    }

    /** Seite 7: was der Assistent eingerichtet hat, mit den Sonnenzeiten von heute. */
    public function UIAssistentZusammenfassung(string $fassadenJson, string $geraeteJson, string $sensorJson = '{}'): void
    {
        $fassaden = array_values(array_filter($this->entwurf('fassaden', $fassadenJson), fn($f) => (bool)($f['Aktiv'] ?? true)));
        $geraete = $this->entwurf('geraete', $geraeteJson);
        $zeilen = [];
        foreach ($fassaden as $f) {
            $namen = array_map(fn($g) => (string)$g['Name'], array_filter($geraete, fn($g) => ($g['Fassade'] ?? '') === $f['Kennung']));
            if ($namen !== []) {
                $zeilen[] = $f['Kennung'] . ' ' . $f['Name'] . ': ' . implode(', ', $namen);
            }
        }
        $aus = count(array_filter($geraete, fn($g) => ($g['Fassade'] ?? '') === '-'));
        if ($aus > 0) {
            $zeilen[] = $this->t('Not shaded: %d', $aus);
        }
        $s = json_decode($sensorJson, true) ?: [];
        $raumT = count(array_filter($geraete, fn($g) => ($g['Fassade'] ?? '') !== '-' && (int)($g['RaumTemp'] ?? 0) > 0));
        $raumH = count(array_filter($geraete, fn($g) => ($g['Fassade'] ?? '') !== '-' && (int)($g['RaumHell'] ?? 0) > 0));
        $zeilen[] = (int)($s['hell'] ?? 0) > 0 ? $this->t('Shades when the sun is on the facade and the irradiance is high enough.')
            : $this->t('Shades whenever the sun is on the facade (no irradiance sensor).');
        $quellen = [];
        if ((int)($s['temp'] ?? 0) > 0) {
            $quellen[] = $this->t('outdoor temperature');
        }
        if ((int)($s['wp'] ?? 0) > 0) {
            $quellen[] = $this->t('heat pump');
        }
        if ($raumT > 0) {
            $quellen[] = $this->t('%d room temperatures', $raumT);
        }
        $zeilen[] = $quellen === [] ? $this->t('No temperatures: also shades in winter.')
            : $this->t('Heating or cooling from: %s.', implode(', ', $quellen));
        if ($raumH > 0) {
            $zeilen[] = $this->t('%d rooms confirm the sun with their brightness sensor.', $raumH);
        }
        if (!empty($s['notfall'])) {
            $zeilen[] = $this->t('Smoke detector alarm: all devices up.');
        }
        $zeilen[] = $this->vorschauText(self::nurGenutzteAktiv($fassaden, $geraete), -1, 'heute');
        $this->UpdateFormField('WZusammenfassung', 'caption', implode("\n", $zeilen));
    }

    /** „OK“ auf der letzten Seite: Formular füllen (nicht speichern – das macht der Nutzer mit „Änderungen übernehmen“). */
    public function UIAssistentUebernehmen(string $json): string
    {
        $d = json_decode($json, true) ?: [];
        $pos = (int)($d['art'] ?? 0) === 1 ? $this->assistentKoordinaten(1, (string)($d['standort'] ?? '')) : null;
        $this->UpdateFormField('Standort', 'value', json_encode(['latitude' => $pos[0] ?? 0, 'longitude' => $pos[1] ?? 0]));
        $fassaden = $this->entwurf('fassaden', json_encode($d['fassaden'] ?? []));
        $geraete = array_values(array_filter($this->entwurf('geraete', json_encode($d['geraete'] ?? [])), fn($g) => ($g['Fassade'] ?? '') !== '-'));
        $fassaden = self::nurGenutzteAktiv($fassaden, $geraete);
        $this->UpdateFormField('Fassaden', 'values', json_encode($fassaden));
        $this->UpdateFormField('Geraete', 'values', json_encode($geraete));
        $this->UpdateFormField('WarnHubNutzen', 'value', (bool)($d['warnhub'] ?? false));
        $this->UpdateFormField('WindID', 'value', (int)($d['wind'] ?? 0));
        $this->UpdateFormField('RegenID', 'value', (int)($d['regen'] ?? 0));
        $this->UpdateFormField('SperreID', 'value', (int)($d['sperre'] ?? 0));
        $this->UpdateFormField('NetzWetter', 'value', (bool)($d['netz'] ?? false));
        $this->UpdateFormField('MarkiseOhneWetter', 'value', (bool)($d['ohne'] ?? false));
        $this->UpdateFormField('HelligkeitID', 'value', (int)($d['hell'] ?? 0));
        $this->UpdateFormField('TemperaturID', 'value', (int)($d['temp'] ?? 0));
        if (isset($d['wp'])) {
            $this->UpdateFormField('WPModusID', 'value', (int)$d['wp']);
        }
        $heishaText = '';
        if ((bool)($d['heisha'] ?? false)) {
            $heishaText = ' ' . $this->UIHeishaSonne(); // ausdrücklich angehakt: HeishaMon schaltet über seine eigene Variable
        }
        if (isset($d['heizung'])) {
            $this->UpdateFormField('Heizung', 'value', (int)$d['heizung']);
        }
        if (isset($d['notfall'])) {
            $melder = $d['notfall'] ? VSCHErkennung::rauchmelder($this->momentaufnahme()) : [];
            $this->UpdateFormField('Notfall', 'values', json_encode(array_map(fn($m) => ['Melder' => $m], $melder)));
        }
        foreach (['sonneAn' => 'SonneAn', 'aussenSommer' => 'AussenSommer', 'innenGrenze' => 'InnenGrenze', 'raumHellLux' => 'RaumHellLux', 'zielLux' => 'ZielLux', 'heizGrenze' => 'HeizGrenze'] as $q => $feld) {
            if (isset($d[$q])) {
                $this->UpdateFormField($feld, 'value', (float)$d[$q]);
            }
        }
        if (isset($d['sonneAn'])) {
            // Hysterese: Ende bei 60 % der Schwelle – Wolken lassen den Behang nicht gleich hochfahren
            $this->UpdateFormField('SonneAus', 'value', (float)(round(0.6 * (float)$d['sonneAn'] / 10) * 10));
        }
        $info = 0;
        if ((bool)($d['info'] ?? false)) {
            $info = $this->ausrichtungInsInfo($geraete, $fassaden);
        }
        $this->UpdateFormField('Grundriss', 'image', $this->grundrissBild($fassaden, 0, 'heute', self::PLAN_FORM));
        $this->UpdateFormField('GeoStatus', 'caption', $this->geoStatus() !== '' ? '🔗 ' . $this->geoStatus() : $this->t('Not detected yet.'));
        $this->UpdateFormField('Quellen', 'caption', $this->quellenText());
        return $this->t('The form is filled in: %d facades, %d devices. Now click "Apply changes".', count($fassaden), count($geraete))
            . ($info > 0 ? "\n" . $this->t('Orientation noted in the info field of %d devices.', $info) : '')
            . ($heishaText !== '' ? "\n" . trim($heishaText) : '');
    }

    /**
     * Nur auf ausdrücklichen Wunsch (Häkchen in Schritt 1): „Ausrichtung: Süd“ ins Info-Feld der Geräteinstanz schreiben.
     * Eine vorhandene „Ausrichtung:“-Zeile wird ersetzt, übriger Text bleibt. Rückgabe: Anzahl geänderter Geräte.
     */
    private function ausrichtungInsInfo(array $geraete, array $fassaden): int
    {
        $az = array_column($fassaden, 'Azimut', 'Kennung');
        $n = 0;
        foreach ($geraete as $g) {
            $p = (int)($g['Position'] ?? 0);
            $richtung = isset($g['Az']) && $g['Az'] !== null ? (float)$g['Az'] : (isset($az[$g['Fassade'] ?? '']) ? (float)$az[$g['Fassade']] : null);
            if ($p <= 0 || !IPS_VariableExists($p) || $richtung === null) {
                continue;
            }
            $ziel = IPS_GetParent($p);
            if ($ziel <= 0 || !IPS_InstanceExists($ziel)) {
                $ziel = $p;
            }
            $zeile = 'Ausrichtung: ' . $this->richtungName($richtung);
            $alt = (string)(IPS_GetObject($ziel)['ObjectInfo'] ?? '');
            $neu = preg_match('/^(Ausrichtung|Orientation):.*$/mi', $alt) ? preg_replace('/^(Ausrichtung|Orientation):.*$/mi', $zeile, $alt) : trim($alt . "\n" . $zeile);
            if ($neu !== $alt) {
                IPS_SetInfo($ziel, (string)$neu);
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ Hilfen

    /**
     * Fassaden ohne Gerät auf „nicht aktiv“ (bleiben in der Liste – später reicht ein Häkchen, kein neues „Haus erkennen“).
     * Hängt gar kein Gerät an einer Fassade, bleibt alles wie es ist.
     */
    public static function nurGenutzteAktiv(array $fassaden, array $geraete): array
    {
        $genutzt = array_unique(array_map(fn($g) => (string)($g['Fassade'] ?? ''), $geraete));
        if (array_intersect($genutzt, array_column($fassaden, 'Kennung')) === []) {
            return $fassaden;
        }
        foreach ($fassaden as &$f) {
            $f['Aktiv'] = in_array((string)$f['Kennung'], $genutzt, true);
        }
        unset($f);
        return $fassaden;
    }

    /** Standort des Assistenten: 0 = Standort-Instanz, 1 = auf der Karte gewählt. */
    private function assistentKoordinaten(int $art, string $standort): ?array
    {
        if ($art === 0) {
            return $this->standortInstanz();
        }
        $l = json_decode($standort, true);
        if (is_string($l)) {
            $l = json_decode($l, true);
        }
        if (is_array($l) && ((float)($l['latitude'] ?? 0) != 0.0 || (float)($l['longitude'] ?? 0) != 0.0)) {
            return [(float)$l['latitude'], (float)$l['longitude']];
        }
        return null;
    }

    private function standortText(?array $pos): string
    {
        return $pos === null ? '—' : number_format($pos[0], 4, ',', '') . '° N, ' . number_format($pos[1], 4, ',', '') . '° O';
    }

    /** „A = Süd (166°) · B = West (256°) …“ */
    private function fassadenLegende(array $fassaden): string
    {
        if ($fassaden === []) {
            return $this->t('No facades yet – go back one step.');
        }
        return implode(' · ', array_map(fn($f) => $f['Kennung'] . ' = ' . $f['Name'] . ' (' . round((float)$f['Azimut']) . '°)', $fassaden));
    }

    /** Fassade aus dem Gerätenamen, wenn dort eine Himmelsrichtung steht („Rollladen Süd“), sonst leer. */
    public static function fassadeAusName(string $name, array $fassaden): string
    {
        $n = VSCHErkennung::norm($name);
        $richtungen = ['nordost' => 45, 'nordwest' => 315, 'suedost' => 135, 'suedwest' => 225, 'nord' => 0, 'ost' => 90, 'sued' => 180, 'west' => 270,
            'north' => 0, 'east' => 90, 'south' => 180];
        foreach ($richtungen as $wort => $az) {
            if (preg_match('/\b' . $wort . '\b/', $n)) {
                $beste = '';
                $abstand = 46.0;
                foreach ($fassaden as $f) {
                    $d = VSCHSonne::winkelAbstand((float)$f['Azimut'], (float)$az);
                    if ($d < $abstand) {
                        $abstand = $d;
                        $beste = (string)$f['Kennung'];
                    }
                }
                return $beste;
            }
        }
        return '';
    }
}
