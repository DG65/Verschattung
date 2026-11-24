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
        $wann = $this->ReadPropertyInteger('TemperaturID') > 0 && $this->ReadPropertyFloat('TemperaturMin') > 0 ? 2
            : ($this->ReadPropertyInteger('HelligkeitID') > 0 ? 1 : 0);
        $warnHub = function_exists('WHUB_GetActiveWarnings') && IPS_GetInstanceListByModuleID(self::WARNHUB_GUID) !== [];

        $optRichtung = [['caption' => $this->t('South'), 'value' => 'S'], ['caption' => $this->t('West'), 'value' => 'W'],
            ['caption' => $this->t('East'), 'value' => 'O'], ['caption' => $this->t('North'), 'value' => 'N']];
        $seiten = [
            [
                'name' => 'ausrichtung', 'caption' => $this->t('Step 1 of 8 · Orientation'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('Tick for each device where its windows face. Two neighbouring ticks = corner (e.g. South + West). No tick = do not shade.')],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'WAlleRichtung', 'caption' => $this->t('Tick all devices'), 'options' => $optRichtung, 'value' => 'S'],
                        ['type' => 'Button', 'caption' => $this->t('Apply to all'),
                            'onClick' => $liste('WAusrichtung') . 'VSCH_UIAssistentAlleRichtung($id, json_encode($WAusrichtungA), (string)$WAlleRichtung);'],
                    ]],
                    ['type' => 'List', 'name' => 'WAusrichtung', 'rowCount' => 8, 'add' => false, 'delete' => false, 'values' => $this->assistentAusrichtungZeilen($fassaden, $offen),
                        'columns' => array_merge([
                            ['caption' => $this->t('Device'), 'name' => 'Name', 'width' => 'auto', 'save' => true],
                            ['caption' => $this->t('Type'), 'name' => 'TypText', 'width' => '160px', 'save' => true],
                        ], array_map(fn($r) => ['caption' => $this->t(['N' => 'North', 'O' => 'East', 'S' => 'South', 'W' => 'West'][$r]), 'name' => 'R' . $r,
                            'width' => '70px', 'edit' => ['type' => 'CheckBox']], ['N', 'O', 'S', 'W']),
                        array_map(fn($k) => ['caption' => $k, 'name' => $k, 'visible' => false, 'save' => true],
                            ['Typ', 'Position', 'Richtung', 'Lamelle', 'LamelleUmgekehrt', 'Kontakt', 'Kontakt2', 'Kontakt3', 'Kontakt4', 'Beschattung']))],
                    ['type' => 'CheckBox', 'name' => 'WInfoSchreiben', 'caption' => $this->t('Also write to device info field'), 'value' => false],
                    ['type' => 'Label', 'caption' => $this->t('Optional: writes the line "Orientation: South" into the info field of each device, so other modules can read it. Other text there stays.')],
                ],
                'validate' => $liste('WAusrichtung') . 'return VSCH_UIAssistentPruefen($id, "ausrichtung", json_encode($WAusrichtungA));',
                'onConfirm' => $liste('WAusrichtung') . 'VSCH_UIAssistentMerken($id, "ausrichtung", json_encode($WAusrichtungA));',
                'onUndo' => '// nichts rückgängig zu machen',
            ],
            [
                'name' => 'standort', 'caption' => $this->t('Step 2 of 8 · Location'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('Where is the house? The sun position is calculated for this point.')],
                    ['type' => 'RadioButtonGroup', 'name' => 'WStandortArt', 'value' => $this->eigenerStandort() || !$hatLocation ? 1 : 0, 'width' => '600px', 'options' => [
                        ['caption' => $hatLocation ? $this->t('Location instance: %s', $this->standortText($this->standortInstanz())) : $this->t('Location instance (not set)'),
                            'value' => 0, 'enabled' => $hatLocation],
                        ['caption' => $this->t('My house is somewhere else'), 'value' => 1, 'items' => [
                            ['type' => 'SelectLocation', 'name' => 'WStandort', 'caption' => $this->t('House'), 'value' => $this->ReadPropertyString('Standort')],
                        ]],
                    ]],
                ],
                'validate' => 'return VSCH_UIAssistentPruefen($id, "standort", json_encode(["art" => (int)$WStandortArt, "standort" => ' . $standortJson . ']));',
            ],
            [
                'name' => 'haus', 'caption' => $this->t('Step 3 of 8 · House'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('Click "Detect house": the facades come from official map data. Correct or add them in the list if needed.')],
                    ['type' => 'Button', 'caption' => $this->t('🏠 Detect house'),
                        'onClick' => 'VSCH_UIAssistentHaus($id, (int)$WStandortArt, ' . $standortJson . ');'],
                    ['type' => 'Label', 'name' => 'WHausStatus', 'caption' => $fassaden === [] ? $this->t('Not detected yet.') : '🔗 ' . $this->geoStatus()],
                    ['type' => 'Image', 'name' => 'WGrundriss', 'image' => $this->grundrissBild($fassaden, -1, 'heute'), 'center' => true, 'width' => '360px'],
                    ['type' => 'List', 'name' => 'WFassaden', 'rowCount' => 4, 'add' => true, 'delete' => true, 'values' => $fassaden, 'columns' => [
                        ['caption' => $this->t('Letter'), 'name' => 'Kennung', 'width' => '70px', 'add' => '?', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->t('Name'), 'name' => 'Name', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->t('Direction (°)'), 'name' => 'Azimut', 'width' => '120px', 'add' => 180, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 359, 'digits' => 1]],
                        ['caption' => $this->t('Active'), 'name' => 'Aktiv', 'width' => '80px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ]],
                ],
                'validate' => $liste('WFassaden') . 'return VSCH_UIAssistentPruefen($id, "haus", json_encode($WFassadenA));',
                'onConfirm' => $liste('WFassaden') . $liste('WGeraete') . 'VSCH_UIAssistentGeraete($id, json_encode($WFassadenA), json_encode($WGeraeteA), (int)$WStandortArt, ' . $standortJson . ');',
                'onUndo' => '// nichts rückgängig zu machen',
            ],
            [
                'name' => 'geraete', 'caption' => $this->t('Step 4 of 8 · Devices'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('All roller shutters, venetian blinds and awnings found. Choose the facade for each one – that is all.')],
                    ['type' => 'Label', 'caption' => $this->t('Tip: put the devices in a folder named after the direction (e.g. "South side") or write "Orientation: South" in their info field – then the facade is filled in automatically.')],
                    ['type' => 'Label', 'name' => 'WLegende', 'caption' => $this->fassadenLegende($fassaden)],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'WAlleFassade', 'caption' => $this->t('All devices to facade'), 'options' => $optProbe, 'value' => 'A'],
                        ['type' => 'Button', 'caption' => $this->t('Apply to all'),
                            'onClick' => $liste('WGeraete') . 'VSCH_UIAssistentAlle($id, json_encode($WGeraeteA), (string)$WAlleFassade);'],
                    ]],
                    ['type' => 'Image', 'name' => 'WGeraeteBild', 'image' => $this->grundrissBild($fassaden, -1, 'heute'), 'center' => true, 'width' => '300px'],
                    ['type' => 'List', 'name' => 'WGeraete', 'rowCount' => 8, 'add' => false, 'delete' => true, 'values' => $geraete, 'columns' => [
                        ['caption' => $this->t('Device'), 'name' => 'Name', 'width' => 'auto', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->t('Facade'), 'name' => 'Fassade', 'width' => '110px', 'edit' => ['type' => 'Select', 'options' => $optFassade]],
                        ['caption' => $this->t('Type'), 'name' => 'Typ', 'width' => '140px', 'edit' => ['type' => 'Select', 'options' => $optTyp]],
                        ['caption' => $this->t('0 % means'), 'name' => 'Richtung', 'width' => '140px', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => $this->t('automatic (profile)'), 'value' => 0], ['caption' => $this->t('open / retracted'), 'value' => 1], ['caption' => $this->t('closed / extended'), 'value' => 2]]]],
                        ['caption' => $this->t('Window/door contact'), 'name' => 'Kontakt', 'width' => '160px', 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Contact 2'), 'name' => 'Kontakt2', 'visible' => false, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Contact 3'), 'name' => 'Kontakt3', 'visible' => false, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->t('Contact 4'), 'name' => 'Kontakt4', 'visible' => false, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Position', 'name' => 'Position', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Slats (optional)'), 'name' => 'Lamelle', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Slats 0 % = closed'), 'name' => 'LamelleUmgekehrt', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Shading position (%)'), 'name' => 'Beschattung', 'visible' => false, 'save' => true],
                    ]],
                    ['type' => 'Label', 'name' => 'WGeraeteHinweis', 'caption' => $this->t('Missing a device? Add it later in the device list of the form with "+".')],
                ],
                'validate' => $liste('WFassaden') . $liste('WGeraete') . 'return VSCH_UIAssistentPruefen($id, "geraete", json_encode(["fassaden" => $WFassadenA, "geraete" => $WGeraeteA]));',
                // Live-Test 23.11.: Listenwerte, die im Bearbeiten-Dialog geändert wurden, kommen auf späteren Seiten nicht an –
                // deshalb hier (aktuelle Seite) als Entwurf merken und später aus dem Entwurf lesen
                'onConfirm' => $liste('WGeraete') . 'VSCH_UIAssistentMerken($id, "geraete", json_encode($WGeraeteA));',
                'onUndo' => '// nichts rückgängig zu machen',
            ],
            [
                'name' => 'probe', 'caption' => $this->t('Step 5 of 8 · Test run'),
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
                'name' => 'wetter', 'caption' => $this->t('Step 6 of 8 · Weather protection'),
                'items' => [
                    ['type' => 'Label', 'name' => 'WWetterText', 'caption' => $this->t('Awnings and venetian blinds retract on wind and severe weather. Choose at least one source.')],
                    ['type' => 'CheckBox', 'name' => 'WWarnHub', 'caption' => $warnHub ? $this->t('Use WarnHub warnings') : $this->t('WarnHub (not installed)'),
                        'value' => $this->ReadPropertyBoolean('WarnHubNutzen') && $warnHub, 'enabled' => $warnHub],
                    ['type' => 'SelectVariable', 'name' => 'WWind', 'caption' => $this->t('Wind gust (own sensor)')] + $this->auswahlWert('WindID'),
                    ['type' => 'Label', 'name' => 'WWindInfo', 'caption' => $this->verbindungsText('WindID', $this->ReadPropertyInteger('WindID'), ['hinweise' => []])],
                    ['type' => 'SelectVariable', 'name' => 'WRegen', 'caption' => $this->t('Rain sensor (optional)')] + $this->auswahlWert('RegenID'),
                    ['type' => 'Label', 'name' => 'WRegenInfo', 'caption' => $this->verbindungsText('RegenID', $this->ReadPropertyInteger('RegenID'), ['hinweise' => []])],
                    ['type' => 'SelectVariable', 'name' => 'WSperre', 'caption' => $this->t('External lock (optional)')] + $this->auswahlWert('SperreID'),
                    ['type' => 'Label', 'name' => 'WSperreInfo', 'caption' => $this->verbindungsText('SperreID', $this->ReadPropertyInteger('SperreID'), ['hinweise' => []])],
                    ['type' => 'CheckBox', 'name' => 'WNetzWetter', 'caption' => $this->t('Gust forecast from the internet'), 'value' => $this->ReadPropertyBoolean('NetzWetter')],
                    ['type' => 'CheckBox', 'name' => 'WMarkiseOhne', 'caption' => $this->t('Extend awnings without weather source'), 'value' => $this->ReadPropertyBoolean('MarkiseOhneWetter')],
                ],
            ],
            [
                'name' => 'wann', 'caption' => $this->t('Step 7 of 8 · When to shade'),
                'items' => [
                    ['type' => 'RadioButtonGroup', 'name' => 'WWann', 'value' => $wann, 'width' => '600px', 'options' => [
                        ['caption' => $this->t('Whenever the sun shines on the facade'), 'value' => 0],
                        ['caption' => $this->t('Only when it is bright'), 'value' => 1, 'items' => [
                            ['type' => 'SelectVariable', 'name' => 'WHell', 'caption' => $this->t('Brightness (outdoor, lux)')] + $this->auswahlWert('HelligkeitID'),
                            ['type' => 'Label', 'name' => 'WHellInfo', 'caption' => $this->verbindungsText('HelligkeitID', $this->ReadPropertyInteger('HelligkeitID'), ['hinweise' => []])],
                            ['type' => 'NumberSpinner', 'name' => 'WHellAn', 'caption' => $this->t('Shade from'), 'suffix' => 'lx', 'minimum' => 0, 'value' => $this->ReadPropertyFloat('HellAn')],
                        ]],
                        ['caption' => $this->t('Only on warm days'), 'value' => 2, 'items' => [
                            ['type' => 'SelectVariable', 'name' => 'WTemp', 'caption' => $this->t('Temperature')] + $this->auswahlWert('TemperaturID'),
                            ['type' => 'Label', 'name' => 'WTempInfo', 'caption' => $this->verbindungsText('TemperaturID', $this->ReadPropertyInteger('TemperaturID'), ['hinweise' => []])],
                            ['type' => 'NumberSpinner', 'name' => 'WTempMin', 'caption' => $this->t('Shade only from'), 'suffix' => '°C', 'digits' => 1,
                                'value' => $this->ReadPropertyFloat('TemperaturMin') > 0 ? $this->ReadPropertyFloat('TemperaturMin') : 22.0],
                        ]],
                    ]],
                ],
                'validate' => 'return VSCH_UIAssistentPruefen($id, "wann", json_encode(["wann" => (int)$WWann, "hell" => (int)$WHell, "temp" => (int)$WTemp]));',
                'onConfirm' => 'VSCH_UIAssistentZusammenfassung($id, "", "", (int)$WWann);',
                'onUndo' => '// nichts rückgängig zu machen',
            ],
            [
                'name' => 'fertig', 'caption' => $this->t('Step 8 of 8 · Done'),
                'items' => [
                    ['type' => 'Label', 'name' => 'WZusammenfassung', 'caption' => ''],
                    ['type' => 'Label', 'caption' => $this->t('"OK" fills in the form. Then confirm with "Apply changes" – only then does the module start.')],
                ],
                'nextPage' => -1,
                'onConfirm' => 'echo VSCH_UIAssistentUebernehmen($id, json_encode(["art" => (int)$WStandortArt, "standort" => ' . $standortJson . ', '
                    . '"info" => (bool)$WInfoSchreiben, "warnhub" => (bool)$WWarnHub, "wind" => (int)$WWind, "regen" => (int)$WRegen, "sperre" => (int)$WSperre, "netz" => (bool)$WNetzWetter, "ohne" => (bool)$WMarkiseOhne, '
                    . '"wann" => (int)$WWann, "hell" => (int)$WHell, "hellAn" => (float)$WHellAn, "temp" => (int)$WTemp, "tempMin" => (float)$WTempMin]));',
            ],
        ];
        return [
            'type' => 'PopupAlert', 'name' => 'Assistent',
            'visible' => $offen,
            'popup' => ['caption' => $this->t('Setup assistant'), 'width' => '960px', 'closeCaption' => $this->t('Cancel'), 'initialPage' => 'ausrichtung', 'pages' => $seiten],
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
        // Liste der ersten Seite erst beim Öffnen füllen (Erkennung kostet Zeit, nicht bei jedem Formularaufruf)
        $this->UpdateFormField('WAusrichtung', 'values', json_encode($this->assistentAusrichtungZeilen($this->listeWerte($this->ReadPropertyString('Fassaden')), true)));
        $this->UpdateFormField('Assistent', 'popup.initialPage', 'ausrichtung');
        $this->UpdateFormField('Assistent', 'visible', true);
    }

    /** Prüft eine Seite; Rückgabe '' = in Ordnung, sonst die Meldung (Symcon blockiert dann den Seitenwechsel). */
    public function UIAssistentPruefen(string $seite, string $daten): string
    {
        $d = json_decode($daten, true) ?: [];
        switch ($seite) {
            case 'ausrichtung':
                $falsch = [];
                $irgendeins = false;
                foreach ($d as $z) {
                    $r = self::kreuze($z);
                    $irgendeins = $irgendeins || $r !== [];
                    if (self::richtungAusKreuzen($r) === null && $r !== []) {
                        $falsch[] = (string)($z['Name'] ?? '?');
                    }
                }
                if ($falsch !== []) {
                    return $this->t('Only one direction or two neighbouring ones (e.g. South + West) for: %s', implode(', ', array_slice($falsch, 0, 6)));
                }
                return $irgendeins || $d === [] ? '' : $this->t('Please tick at least one device – otherwise nothing will be shaded.');
            case 'standort':
                if ((int)($d['art'] ?? 0) === 1 && $this->assistentKoordinaten(1, (string)($d['standort'] ?? '')) === null) {
                    return $this->t('Please choose the location of the house on the map.');
                }
                if ((int)($d['art'] ?? 0) === 0 && $this->standortInstanz() === null) {
                    return $this->t('The Location instance has no position. Please choose the house on the map.');
                }
                return '';
            case 'haus':
                return $d === [] ? $this->t('Please click "Detect house" or enter at least one facade.') : '';
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
            case 'wann':
                if ((int)($d['wann'] ?? 0) === 1 && (int)($d['hell'] ?? 0) === 0) {
                    return $this->t('Please choose a brightness sensor or another option.');
                }
                if ((int)($d['wann'] ?? 0) === 2 && (int)($d['temp'] ?? 0) === 0) {
                    return $this->t('Please choose a temperature sensor or another option.');
                }
                return '';
        }
        return '';
    }

    /** Seite 2: Haus am gewählten Standort erkennen und die Seite füllen. */
    public function UIAssistentHaus(int $art, string $standort): void
    {
        $this->koordinatenAssistent = $this->assistentKoordinaten($art, $standort);
        $r = $this->hausErkennen();
        if (!$r['ok']) {
            $this->UpdateFormField('WHausStatus', 'caption', '⛔ ' . $r['text']);
            return;
        }
        $this->UpdateFormField('WFassaden', 'values', json_encode($r['werte']));
        $this->UpdateFormField('WHausStatus', 'caption', '🔗 ' . $this->geoStatus() . ' · ' . $this->t('%d facades', count($r['werte'])));
        $this->UpdateFormField('WGrundriss', 'image', $this->grundrissBild($r['werte'], -1, 'heute'));
    }

    /** Beim Weiter von Seite 2: Geräte und Sensoren erkennen, Seite 3/5/6 vorbefüllen. Vorhandene Zeilen bleiben. */
    public function UIAssistentGeraete(string $fassadenJson, string $geraeteJson, int $art, string $standort): void
    {
        $this->koordinatenAssistent = $this->assistentKoordinaten($art, $standort);
        $fassaden = $this->listeWerte($fassadenJson);
        $inst = $this->momentaufnahme();
        $e = json_decode($this->ReadAttributeString('AssistentEntwurf'), true) ?: [];
        if (isset($e['ausrichtung']) && is_array($e['ausrichtung'])) {
            // Schritt 1 hat die Ausrichtung angekreuzt: daraus die Fassade (nächste innerhalb 45°), kein Kreuz = nicht verschatten
            $alle = [];
            $neu = $e['ausrichtung'];
            foreach ($e['ausrichtung'] as $z) {
                $az = self::richtungAusKreuzen(self::kreuze($z));
                $g = array_diff_key($z, array_flip(['RN', 'RO', 'RS', 'RW', 'TypText']));
                $g['Fassade'] = $az === null ? '-' : VSCHErkennung::fassadeZuAzimut($az, $fassaden);
                $g['Az'] = $az;
                $alle[] = $g;
            }
        } else {
            $geraete = $this->listeWerte($geraeteJson);
            $neu = VSCHErkennung::geraete($inst, array_map(fn($g) => (int)($g['Position'] ?? 0), $geraete));
            $alle = array_merge($geraete, $neu);
            foreach ($alle as &$g) {
                if ((string)($g['Fassade'] ?? '') === '') {
                    $g['Fassade'] = VSCHErkennung::fassadeZuAzimut($g['AzHinweis'] ?? null, $fassaden) ?: self::fassadeAusName((string)($g['Name'] ?? ''), $fassaden);
                }
                unset($g['AzHinweis']);
            }
            unset($g);
        }
        $this->UpdateFormField('WGeraete', 'values', json_encode($alle));
        // Probefahrt: die Fassade mit den meisten Geräten vorwählen (Live-Befund 24.11.: stand auf A, alle Geräte an B)
        $zahl = array_count_values(array_filter(array_map(fn($g) => (string)($g['Fassade'] ?? ''), $alle), fn($f) => $f !== '' && $f !== '-'));
        if ($zahl !== []) {
            arsort($zahl);
            $this->UpdateFormField('WProbeFassade', 'value', (string)array_key_first($zahl));
        }
        $this->UIAssistentMerken('fassaden', json_encode($fassaden));
        $this->UIAssistentMerken('geraete', json_encode($alle));
        $this->UpdateFormField('WLegende', 'caption', $this->fassadenLegende($fassaden));
        $this->UpdateFormField('WGeraeteBild', 'image', $this->grundrissBild($fassaden, -1, 'heute'));
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
        if ($this->ReadPropertyInteger('HelligkeitID') === 0 && $this->ReadPropertyInteger('TemperaturID') === 0 && $sens['HelligkeitID'] > 0) {
            $this->UpdateFormField('WWann', 'value', 1);
        }
        $text = [$this->t('Awnings and venetian blinds retract on wind and severe weather. Choose at least one source.')];
        if (isset($sens['hinweise']['WindID']) && $sens['hinweise']['WindID'] === 'warnhub') {
            $text[] = $this->t('Your weather station is already evaluated by WarnHub – keep "Use WarnHub warnings" ticked.');
        }
        $this->UpdateFormField('WWetterText', 'caption', implode(' ', $text));
    }

    /** Zeilen für Schritt 1: gespeicherte Geräte (Richtung aus ihrer Fassade) + neu erkannte (Richtung aus Objektbaum/Name). */
    private function assistentAusrichtungZeilen(array $fassaden, bool $erkennen): array
    {
        $typText = ['rollladen' => $this->t('Roller shutter'), 'raffstore' => $this->t('Venetian blind'), 'markise' => $this->t('Awning')];
        $az = array_column($fassaden, 'Azimut', 'Kennung');
        $zeilen = [];
        foreach ($this->listeWerte($this->ReadPropertyString('Geraete')) as $g) {
            $zeilen[] = self::mitKreuzen($g, isset($az[$g['Fassade'] ?? '']) ? (float)$az[$g['Fassade']] : null, $typText);
        }
        if ($erkennen) {
            foreach (VSCHErkennung::geraete($this->momentaufnahme(), array_map(fn($g) => (int)($g['Position'] ?? 0), $zeilen)) as $g) {
                $zeilen[] = self::mitKreuzen($g, $g['AzHinweis'] ?? VSCHErkennung::richtungAusText((string)$g['Name']), $typText);
            }
        }
        return $zeilen;
    }

    /** Gerätezeile um Kreuze N/O/S/W ergänzen (Ecken: zwei Kreuze). */
    private static function mitKreuzen(array $g, ?float $az, array $typText): array
    {
        unset($g['AzHinweis']);
        $g += ['RN' => false, 'RO' => false, 'RS' => false, 'RW' => false];
        $g['TypText'] = $typText[$g['Typ'] ?? ''] ?? (string)($g['Typ'] ?? '');
        if ($az !== null) {
            $az = fmod($az + 360, 360);
            $mitte = ['N' => 0, 'O' => 90, 'S' => 180, 'W' => 270];
            foreach ($mitte as $r => $m) {
                $d = abs(fmod(abs($az - $m) + 180, 360) - 180);
                $g['R' . $r] = $d <= 67.5; // Hauptrichtung bis ±22,5°, Ecke 22,5–67,5° → zwei Kreuze
            }
            // genau auf der Hauptrichtung: nur ein Kreuz
            foreach ($mitte as $r => $m) {
                if (abs(fmod(abs($az - $m) + 180, 360) - 180) <= 22.5) {
                    foreach ($mitte as $r2 => $m2) {
                        $g['R' . $r2] = $r2 === $r;
                    }
                }
            }
        }
        return $g;
    }

    /** Angekreuzte Richtungen einer Zeile, z. B. ['S', 'W']. */
    private static function kreuze(array $z): array
    {
        return array_values(array_filter(['N', 'O', 'S', 'W'], fn($r) => (bool)($z['R' . $r] ?? false)));
    }

    /** Azimut aus Kreuzen: eine Richtung oder zwei benachbarte (Ecke); sonst null. */
    public static function richtungAusKreuzen(array $r): ?float
    {
        $m = ['N' => 0.0, 'O' => 90.0, 'S' => 180.0, 'W' => 270.0];
        sort($r);
        if (count($r) === 1) {
            return $m[$r[0]];
        }
        if (count($r) === 2) {
            $ecken = ['N,O' => 45.0, 'O,S' => 135.0, 'S,W' => 225.0, 'N,W' => 315.0];
            return $ecken[implode(',', $r)] ?? null;
        }
        return null;
    }

    /** Schritt 1: alle Geräte auf eine Richtung ankreuzen. */
    public function UIAssistentAlleRichtung(string $json, string $richtung): void
    {
        $z = $this->listeWerte($json);
        foreach ($z as &$g) {
            foreach (['N', 'O', 'S', 'W'] as $r) {
                $g['R' . $r] = $r === $richtung;
            }
        }
        unset($g);
        $this->UpdateFormField('WAusrichtung', 'values', json_encode($z));
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
    public function UIAssistentZusammenfassung(string $fassadenJson, string $geraeteJson, int $wann): void
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
        $zeilen[] = $wann === 1 ? $this->t('Shades when the sun is on the facade and it is bright.')
            : ($wann === 2 ? $this->t('Shades when the sun is on the facade on warm days.') : $this->t('Shades whenever the sun is on the facade.'));
        $zeilen[] = $this->vorschauText($fassaden, -1, 'heute');
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
        $this->UpdateFormField('Fassaden', 'values', json_encode($fassaden));
        $this->UpdateFormField('Geraete', 'values', json_encode($geraete));
        $this->UpdateFormField('WarnHubNutzen', 'value', (bool)($d['warnhub'] ?? false));
        $this->UpdateFormField('WindID', 'value', (int)($d['wind'] ?? 0));
        $this->UpdateFormField('RegenID', 'value', (int)($d['regen'] ?? 0));
        $this->UpdateFormField('SperreID', 'value', (int)($d['sperre'] ?? 0));
        $this->UpdateFormField('NetzWetter', 'value', (bool)($d['netz'] ?? false));
        $this->UpdateFormField('MarkiseOhneWetter', 'value', (bool)($d['ohne'] ?? false));
        $wann = (int)($d['wann'] ?? 0);
        $this->UpdateFormField('HelligkeitID', 'value', $wann === 1 ? (int)($d['hell'] ?? 0) : 0);
        if ($wann === 1 && (float)($d['hellAn'] ?? 0) > 0) {
            $this->UpdateFormField('HellAn', 'value', (float)$d['hellAn']);
        }
        $this->UpdateFormField('TemperaturID', 'value', $wann === 2 ? (int)($d['temp'] ?? 0) : 0);
        $this->UpdateFormField('TemperaturMin', 'value', $wann === 2 ? (float)($d['tempMin'] ?? 0) : 0.0);
        $info = 0;
        if ((bool)($d['info'] ?? false)) {
            $info = $this->ausrichtungInsInfo($geraete, $fassaden);
        }
        $this->UpdateFormField('Grundriss', 'image', $this->grundrissBild($fassaden, 0, 'heute'));
        $this->UpdateFormField('GeoStatus', 'caption', $this->geoStatus() !== '' ? '🔗 ' . $this->geoStatus() : $this->t('Not detected yet.'));
        $this->UpdateFormField('Quellen', 'caption', $this->quellenText());
        return $this->t('The form is filled in: %d facades, %d devices. Now click "Apply changes".', count($fassaden), count($geraete))
            . ($info > 0 ? "\n" . $this->t('Orientation noted in the info field of %d devices.', $info) : '');
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
