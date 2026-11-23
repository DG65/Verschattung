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

        $seiten = [
            [
                'name' => 'standort', 'caption' => $this->t('Step 1 of 7 · Location'),
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
                'name' => 'haus', 'caption' => $this->t('Step 2 of 7 · House'),
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
                'name' => 'geraete', 'caption' => $this->t('Step 3 of 7 · Devices'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('All roller shutters, venetian blinds and awnings found. Choose the facade for each one – that is all.')],
                    ['type' => 'Label', 'name' => 'WLegende', 'caption' => $this->fassadenLegende($fassaden)],
                    ['type' => 'Image', 'name' => 'WGeraeteBild', 'image' => $this->grundrissBild($fassaden, -1, 'heute'), 'center' => true, 'width' => '300px'],
                    ['type' => 'List', 'name' => 'WGeraete', 'rowCount' => 8, 'add' => false, 'delete' => true, 'values' => $geraete, 'columns' => [
                        ['caption' => $this->t('Device'), 'name' => 'Name', 'width' => 'auto', 'save' => true],
                        ['caption' => $this->t('Facade'), 'name' => 'Fassade', 'width' => '150px', 'edit' => ['type' => 'Select', 'options' => $optFassade]],
                        ['caption' => $this->t('Type'), 'name' => 'Typ', 'width' => '150px', 'edit' => ['type' => 'Select', 'options' => $optTyp]],
                        ['caption' => $this->t('0 % means'), 'name' => 'Richtung', 'width' => '160px', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => $this->t('automatic (profile)'), 'value' => 0], ['caption' => $this->t('open / retracted'), 'value' => 1], ['caption' => $this->t('closed / extended'), 'value' => 2]]]],
                        ['caption' => $this->t('Window/door contact'), 'name' => 'Kontakt', 'width' => '200px', 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Position', 'name' => 'Position', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Slats (optional)'), 'name' => 'Lamelle', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Slats 0 % = closed'), 'name' => 'LamelleUmgekehrt', 'visible' => false, 'save' => true],
                        ['caption' => $this->t('Shading position (%)'), 'name' => 'Beschattung', 'visible' => false, 'save' => true],
                    ]],
                    ['type' => 'Label', 'name' => 'WGeraeteHinweis', 'caption' => $this->t('Missing a device? Add it later in the device list of the form with "+".')],
                ],
                'validate' => $liste('WFassaden') . $liste('WGeraete') . 'return VSCH_UIAssistentPruefen($id, "geraete", json_encode(["fassaden" => $WFassadenA, "geraete" => $WGeraeteA]));',
            ],
            [
                'name' => 'probe', 'caption' => $this->t('Step 4 of 7 · Test run'),
                'items' => [
                    ['type' => 'Label', 'caption' => $this->t('Check the direction: "Shade" should lower the devices of the facade, "Back" raise them. If one moves the wrong way, go back and change its "0 % means" column.')],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'WProbeFassade', 'caption' => $this->t('Facade'), 'options' => $optProbe, 'value' => 'A'],
                        ['type' => 'Button', 'caption' => $this->t('⬇ Shade'),
                            'onClick' => $liste('WGeraete') . 'VSCH_UIAssistentProbe($id, json_encode($WGeraeteA), (string)$WProbeFassade, true);'],
                        ['type' => 'Button', 'caption' => $this->t('⬆ Back'),
                            'onClick' => $liste('WGeraete') . 'VSCH_UIAssistentProbe($id, json_encode($WGeraeteA), (string)$WProbeFassade, false);'],
                    ]],
                    ['type' => 'Label', 'name' => 'WProbeStatus', 'caption' => $this->t('The test run is optional.')],
                ],
                'nextPage' => $liste('WGeraete') . 'return VSCH_UIAssistentWeiter($id, json_encode($WGeraeteA));',
            ],
            [
                'name' => 'wetter', 'caption' => $this->t('Step 5 of 7 · Weather protection'),
                'items' => [
                    ['type' => 'Label', 'name' => 'WWetterText', 'caption' => $this->t('Awnings and venetian blinds retract on wind and severe weather. Choose at least one source.')],
                    ['type' => 'CheckBox', 'name' => 'WWarnHub', 'caption' => $warnHub ? $this->t('Use WarnHub warnings') : $this->t('WarnHub (not installed)'),
                        'value' => $this->ReadPropertyBoolean('WarnHubNutzen') && $warnHub, 'enabled' => $warnHub],
                    ['type' => 'SelectVariable', 'name' => 'WWind', 'caption' => $this->t('Wind gust (own sensor)'), 'value' => $this->ReadPropertyInteger('WindID')],
                    ['type' => 'SelectVariable', 'name' => 'WRegen', 'caption' => $this->t('Rain sensor (optional)'), 'value' => $this->ReadPropertyInteger('RegenID')],
                    ['type' => 'CheckBox', 'name' => 'WNetzWetter', 'caption' => $this->t('Gust forecast from the internet'), 'value' => $this->ReadPropertyBoolean('NetzWetter')],
                    ['type' => 'CheckBox', 'name' => 'WMarkiseOhne', 'caption' => $this->t('Extend awnings without weather source'), 'value' => $this->ReadPropertyBoolean('MarkiseOhneWetter')],
                ],
            ],
            [
                'name' => 'wann', 'caption' => $this->t('Step 6 of 7 · When to shade'),
                'items' => [
                    ['type' => 'RadioButtonGroup', 'name' => 'WWann', 'value' => $wann, 'width' => '600px', 'options' => [
                        ['caption' => $this->t('Whenever the sun shines on the facade'), 'value' => 0],
                        ['caption' => $this->t('Only when it is bright'), 'value' => 1, 'items' => [
                            ['type' => 'SelectVariable', 'name' => 'WHell', 'caption' => $this->t('Brightness (outdoor, lux)'), 'value' => $this->ReadPropertyInteger('HelligkeitID')],
                            ['type' => 'NumberSpinner', 'name' => 'WHellAn', 'caption' => $this->t('Shade from'), 'suffix' => 'lx', 'minimum' => 0, 'value' => $this->ReadPropertyFloat('HellAn')],
                        ]],
                        ['caption' => $this->t('Only on warm days'), 'value' => 2, 'items' => [
                            ['type' => 'SelectVariable', 'name' => 'WTemp', 'caption' => $this->t('Temperature'), 'value' => $this->ReadPropertyInteger('TemperaturID')],
                            ['type' => 'NumberSpinner', 'name' => 'WTempMin', 'caption' => $this->t('Shade only from'), 'suffix' => '°C', 'digits' => 1,
                                'value' => $this->ReadPropertyFloat('TemperaturMin') > 0 ? $this->ReadPropertyFloat('TemperaturMin') : 22.0],
                        ]],
                    ]],
                ],
                'validate' => 'return VSCH_UIAssistentPruefen($id, "wann", json_encode(["wann" => (int)$WWann, "hell" => (int)$WHell, "temp" => (int)$WTemp]));',
                'onConfirm' => $liste('WFassaden') . $liste('WGeraete') . 'VSCH_UIAssistentZusammenfassung($id, json_encode($WFassadenA), json_encode($WGeraeteA), (int)$WWann);',
                'onUndo' => '// nichts rückgängig zu machen',
            ],
            [
                'name' => 'fertig', 'caption' => $this->t('Step 7 of 7 · Done'),
                'items' => [
                    ['type' => 'Label', 'name' => 'WZusammenfassung', 'caption' => ''],
                    ['type' => 'Label', 'caption' => $this->t('"OK" fills in the form. Then confirm with "Apply changes" – only then does the module start.')],
                ],
                'nextPage' => -1,
                'onConfirm' => $liste('WFassaden') . $liste('WGeraete')
                    . 'echo VSCH_UIAssistentUebernehmen($id, json_encode(["art" => (int)$WStandortArt, "standort" => ' . $standortJson . ', "fassaden" => $WFassadenA, "geraete" => $WGeraeteA, '
                    . '"warnhub" => (bool)$WWarnHub, "wind" => (int)$WWind, "regen" => (int)$WRegen, "netz" => (bool)$WNetzWetter, "ohne" => (bool)$WMarkiseOhne, '
                    . '"wann" => (int)$WWann, "hell" => (int)$WHell, "hellAn" => (float)$WHellAn, "temp" => (int)$WTemp, "tempMin" => (float)$WTempMin]));',
            ],
        ];
        return [
            'type' => 'PopupAlert', 'name' => 'Assistent',
            'visible' => $fassaden === [] && $geraete === [],
            'popup' => ['caption' => $this->t('Setup assistant'), 'width' => '760px', 'closeCaption' => $this->t('Cancel'), 'initialPage' => 'standort', 'pages' => $seiten],
        ];
    }

    // ------------------------------------------------------------------ Aktionen der Seiten

    /** Assistent (erneut) öffnen, immer ab Seite 1. */
    public function UIAssistentOeffnen(): void
    {
        $this->UpdateFormField('Assistent', 'popup.initialPage', 'standort');
        $this->UpdateFormField('Assistent', 'visible', true);
    }

    /** Prüft eine Seite; Rückgabe '' = in Ordnung, sonst die Meldung (Symcon blockiert dann den Seitenwechsel). */
    public function UIAssistentPruefen(string $seite, string $daten): string
    {
        $d = json_decode($daten, true) ?: [];
        switch ($seite) {
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
        $geraete = $this->listeWerte($geraeteJson);
        $inst = $this->momentaufnahme();
        $neu = VSCHErkennung::geraete($inst, array_map(fn($g) => (int)($g['Position'] ?? 0), $geraete));
        $alle = array_merge($geraete, $neu);
        foreach ($alle as &$g) {
            $g['Fassade'] = (string)($g['Fassade'] ?? '') === '' ? self::fassadeAusName((string)($g['Name'] ?? ''), $fassaden) : (string)$g['Fassade'];
        }
        unset($g);
        $this->UpdateFormField('WGeraete', 'values', json_encode($alle));
        $this->UpdateFormField('WLegende', 'caption', $this->fassadenLegende($fassaden));
        $this->UpdateFormField('WGeraeteBild', 'image', $this->grundrissBild($fassaden, -1, 'heute'));
        $this->UpdateFormField('WGeraeteHinweis', 'caption', $neu === []
            ? $this->t('Missing a device? Add it later in the device list of the form with "+".')
            : $this->t('%d devices detected (%d with window contact).', count($neu), count(array_filter($neu, fn($g) => $g['Kontakt'] > 0))));

        $sens = VSCHErkennung::sensoren($inst, $this->warnHubWetterstation());
        foreach (['WindID' => 'WWind', 'RegenID' => 'WRegen', 'HelligkeitID' => 'WHell', 'TemperaturID' => 'WTemp'] as $rolle => $feld) {
            if ($sens[$rolle] > 0 && $this->ReadPropertyInteger($rolle) === 0) {
                $this->UpdateFormField($feld, 'value', $sens[$rolle]);
            }
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

    /** Seite 4: Probefahrt mit den Geräten aus dem Assistenten (noch nicht gespeichert). */
    public function UIAssistentProbe(string $geraeteJson, string $fassade, bool $beschatten): void
    {
        $n = 0;
        foreach ($this->normalisieren($this->listeWerte($geraeteJson)) as $g) {
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
        foreach ($this->listeWerte($geraeteJson) as $g) {
            if (($g['Fassade'] ?? '') !== '-' && in_array($g['Typ'] ?? '', ['markise', 'raffstore'], true)) {
                return 'wetter';
            }
        }
        return 'wann';
    }

    /** Seite 7: was der Assistent eingerichtet hat, mit den Sonnenzeiten von heute. */
    public function UIAssistentZusammenfassung(string $fassadenJson, string $geraeteJson, int $wann): void
    {
        $fassaden = array_values(array_filter($this->listeWerte($fassadenJson), fn($f) => (bool)($f['Aktiv'] ?? true)));
        $geraete = $this->listeWerte($geraeteJson);
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
        $fassaden = $this->listeWerte(json_encode($d['fassaden'] ?? []));
        $geraete = array_values(array_filter($this->listeWerte(json_encode($d['geraete'] ?? [])), fn($g) => ($g['Fassade'] ?? '') !== '-'));
        $this->UpdateFormField('Fassaden', 'values', json_encode($fassaden));
        $this->UpdateFormField('Geraete', 'values', json_encode($geraete));
        $this->UpdateFormField('WarnHubNutzen', 'value', (bool)($d['warnhub'] ?? false));
        $this->UpdateFormField('WindID', 'value', (int)($d['wind'] ?? 0));
        $this->UpdateFormField('RegenID', 'value', (int)($d['regen'] ?? 0));
        $this->UpdateFormField('NetzWetter', 'value', (bool)($d['netz'] ?? false));
        $this->UpdateFormField('MarkiseOhneWetter', 'value', (bool)($d['ohne'] ?? false));
        $wann = (int)($d['wann'] ?? 0);
        $this->UpdateFormField('HelligkeitID', 'value', $wann === 1 ? (int)($d['hell'] ?? 0) : 0);
        if ($wann === 1 && (float)($d['hellAn'] ?? 0) > 0) {
            $this->UpdateFormField('HellAn', 'value', (float)$d['hellAn']);
        }
        $this->UpdateFormField('TemperaturID', 'value', $wann === 2 ? (int)($d['temp'] ?? 0) : 0);
        $this->UpdateFormField('TemperaturMin', 'value', $wann === 2 ? (float)($d['tempMin'] ?? 0) : 0.0);
        $this->UpdateFormField('Grundriss', 'image', $this->grundrissBild($fassaden, 0, 'heute'));
        return $this->t('The form is filled in: %d facades, %d devices. Now click "Apply changes".', count($fassaden), count($geraete));
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
