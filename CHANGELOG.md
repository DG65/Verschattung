# Changelog

## 0.8.2 (in Entwicklung)

- **Straßen im Lageplan** (Dietmar 24.11.): Straßen und Wege aus OpenStreetMap im Umkreis von 120 m, in echter Breite je Straßenart (z. B. Wohnstraße 5,5 m, Zufahrt 3,5 m, Fußweg 1,8 m), unter den Gebäuden, mit Straßennamen entlang der Achse. Auch auf der Kachel (Nahbereich). Geholt werden sie mit „Haus erkennen“; einmal neu erkennen, damit sie erscheinen. Quellenvermerk „Straßen: © OpenStreetMap-Mitwirkende (ODbL)“.


## 0.8.1 (in Entwicklung)

- **Lageplan nutzt die Breite** (Dietmar 24.11.): gleiche Höhe und gleicher Maßstab, aber links und rechts mehr Umgebung – im Assistenten (Schritt „Haus“, 900 px) und im Formular (900 px). Nachbarn, die vorher am Rand abgeschnitten waren, sind jetzt zu sehen.
- Fassadenliste im Formular und im Assistenten mit Platz für mindestens 6 Fassaden (vorher 3 bzw. 4 Zeilen).
- Geräteliste im Formular und in den Assistenten-Schritten „Ausrichtung“ und „Geräte“ mit mindestens 10 Zeilen, bei mehr Behängen eine Zeile mehr als Behänge (vorher 6 bzw. 8).


## 0.8.0 (in Entwicklung)

- **„Was zu ist, bleibt zu“** (Dietmar 24.11., Kinderzimmer): Steht ein Behang schon weiter unten, als die Beschattung verlangt (Höhe und Lamellen), fährt das Modul ihn nicht – vorher hätte es einen ganz geschlossenen Rollladen auf 75 % hochgefahren und geschlossene Lamellen geöffnet. Es merkt ihn auch nicht als eigenen und fährt ihn deshalb nach der Sonne nicht hoch. Öffnet ihn jemand, gilt wieder die normale Automatik.
- **Schalter „Automatik“ je Behang:** Variable „Automatik <Name>“ unter der Instanz (für Visualisierung, IPSView, Skripte, Szenen) und ein Schalter „auto/aus“ in jeder Kachelzeile. Aus → der Behang hat Ruhe; nur Notfall und Wetterschutz fahren ihn dann noch. Schalter entfernter Behänge verschwinden.
- **„So arbeitet die Automatik“**: dieselben acht Regeln in der Kachel (aufklappbar) und im Formular unter „Dokumentation & Hilfe“, damit niemand etwas sucht, was es nicht gibt.


## 0.7.2 (in Entwicklung)

- **Behoben:** 0.7.1 brach mit „end(): Argument #1 could not be passed by reference“ ab, sobald ein Behang noch keinen Grund hatte (frisch angelegte Instanz, Behang an inaktiver Fassade). Betroffen waren Kachel, IPSView-Seite und – bei solchen Behängen – auch der Regeltakt. Live-Befund 24.11. 21:52; neuer Test G54 deckt den Fall ab.


## 0.7.1 (in Entwicklung)

- Live-Test 0.7.0 (24.11. abends, Test-Instanz angelegt und entfernt): IPSView-Seite läuft dunkel und hell (`?bg=ffffff`) – Nachthimmel mit Sternen, Gelände, Nachbarhäuser, Sonnenzeiten der Südfassade (09:00–09:40 + 14:00–15:50), Entscheidungsweg per Tipp. Verbessert:
  - Grundriss näher heran (Nahbereich 22 m), das eigene Haus kräftiger mit Rand – es hob sich kaum von den Nachbarn ab.
  - Geschlossene Lamellen als Fläche statt dünner senkrechter Striche.
  - Grund je Behang auch vor dem ersten Regeltakt (aus dem Probelauf).
  - „Wärmepumpe heizt“ ohne „(Raum –)“, wenn der Behang keinen Raumsensor hat (Markise).


## 0.7.0 (in Entwicklung)

- **Kachel** (Dietmar 24.11.): Haus von oben mit Nachbargebäuden, beschienene Fassaden in Sonnenfarbe und die Sonne in ihrer Richtung; daneben der Himmel von heute mit Sonnenbahn, Sonnenzeit der Hauptfassade (kräftig), Gelände-Horizont, Nachbarhäusern und der Sonne jetzt. Der Himmel zeigt blau, heiter, wolkig, bedeckt, Regen, Dämmerung oder Nacht – aus der gemessenen Einstrahlung im Vergleich zum klaren Himmel und dem Regensensor. Kopfzeile: Einstrahlung, Außentemperatur, Lage (Kühlen/Heizen/Behaglich, Wärmepumpe), Automatik an/aus, Hinweis bei Notfall oder Wetterschutz. Je Behang ein Symbol der echten Stellung (Rollladen-Höhe, Lamellenwinkel, Markise), Prozent und Grund; ein Tipp zeigt den ganzen Entscheidungsweg.
- **IPSView:** dieselbe Seite unter `/hook/verschattung<ID>` für ein WebView-Element (nur Anzeige, aktualisiert sich jede Minute, `?bg=` für die Hintergrundfarbe; ohne Angabe hell oder dunkel nach Gerätemodus). Beim Löschen der Instanz wird der Eintrag im WebHook-Control wieder entfernt.
- **Raumhelligkeit als Regelgröße** (Dietmar 24.11.): Ziel-Helligkeit im Raum (Schieberegler, 0 = aus; je Behang Spalte „Ziel-Helligkeit“). Wenn weder Heizen noch Kühlen nötig ist, regelt der Behang auf den Helligkeitssensor des Raums: zu hell (über Ziel × 1,25) → ein Schritt weiter zu, zu dunkel (unter Ziel × 0,75) → ein Schritt weiter auf, höchstens alle 2 Minuten. Raffstores mit Lamellen: Behang unten, geregelt wird der Lamellenwinkel in 15°-Schritten; sonst die Behanghöhe in 10-%-Schritten. Ist der Raum ohnehin dunkler als das Ziel, fährt nichts herunter; ganz offen und weiter zu dunkel → Beschattung endet. Kühlbedarf: volle Beschattung, Heizbedarf: Behang oben.
- Die Prognose liefert derzeit keine Bewölkung (nur PV-Ertrag und Temperatur); der Himmel kommt deshalb aus der Messung.


## 0.6.3 (in Entwicklung)

- **Garagen, Schuppen und andere Nebengebäude** zählen als eingeschossig mit Flachdach (3 m). Erkannt an der ALKIS-Gebäudefunktion (BW liefert z. B. Wohnhaus, Garage, Schuppen, Scheune), am OSM-Gebäudetyp (`building=garage`, `shed` …) oder an einer Grundfläche unter 40 m². Live-Befund 24.11.: Ums Haus stehen 8 Garagen und 4 Schuppen, die vorher als zweigeschossige Häuser galten (eine Garage 6,9 m vor der Südfassade verdeckte so 35°).
- **Gebäude hinter der Fassadenebene** zählen für diese Fassade nicht mehr (Sonne von dort trifft sie nie). Live: ein Haus 3,4 m westlich erschien im Südbild mit 50°.
- Konsolentest 0.6.2 (24.11., Test-Instanz angelegt und entfernt): Typ-Spalte „DG65-Toolkit Verschattung“, Regler mit Wert („70 %“), Häkchen „Nachbargebäude anzeigen“, „Warum?“ mit „Horizont 14° (Nachbargebäude)“.


## 0.6.2 (in Entwicklung)

- **Vorschau: Nachbargebäude ein- und ausblenden** (Dietmar 24.11.). Das Häkchen „Nachbargebäude anzeigen“ wirkt auf das Himmelsbild und auf die Sonnenzeiten darunter; die Steuerung rechnet immer mit den Gebäuden. Die Erklärzeile unter dem Bild nennt jetzt Hellgrau (Gelände) und Dunkelgrau (Nachbargebäude).


## 0.6.1 (in Entwicklung)

- **Raumhelligkeit in Lux** (Dietmar 24.11.: „eher in Lux, damit kann man mehr anfangen“): Schieberegler „Sonne erreicht den Raum ab“ 0–2000 lx, Vorgabe 150 lx (Archiv: Sonne im Raum 200–2100 lx, Wolken 30–100 lx). Neue Eigenschaft `RaumHellLux`; der alte W/m²-Wert wird nicht übernommen. Sensoren in W/m² werden umgerechnet (× 120).


## 0.6.0 (in Entwicklung)

- **Nachbargebäude als Hindernis** (Dietmar 24.11.): Die Umrisse im Umkreis von 80 m werden zu einem Gebäudemodell: Traufe = Geschosse × 3 m (ohne Angabe 2 Geschosse), Satteldach 45°, First parallel zur längeren Seite. Wo OpenStreetMap Höhe, Geschosse, Dachform (`roof:shape`) oder Firstrichtung (`roof:orientation`) nennt, gilt das. Daraus der Höhenwinkel je Grad, gesehen von der Mitte der Fassade (Augenhöhe 2 m).
  - Das **Himmelsbild** zeigt die Nachbargebäude dunkler über dem Gelände-Horizont.
  - **Entscheidung, Vorschau und „Warum?“** rechnen mit dem Höheren von Gelände und Gebäude; „Warum?“ nennt „(Nachbargebäude)“, wenn ein Haus die Sonne verdeckt.
  - Höhenangaben kommen erst mit einem erneuten „Haus erkennen“; bis dahin gilt für alle Nachbarn 2 Geschosse + 45°-Dach.
- Konsolentest 0.5.0 (24.11., Test-Instanz angelegt und entfernt): „Warum?“, Notfall-Panel (2 Rauchmelder), Wärmepumpe, Assistent Schritt 7/8 und „Neu zeichnen“ laufen. Behoben:
  - **Schieberegler zeigen ihren Wert** samt Einheit (`displayValue`, vorher nur der Punkt).
  - **Gespeicherte Geräte im Assistenten** werden vervollständigt (fehlende Felder, Kontakte, Raumsensoren) – vorher „Objekt # existiert nicht“ und leere Spalte „0 % bedeutet“; die neuen Spalten gehen im Assistenten nicht mehr verloren.
  - „Warum?“: keine doppelte Zeile; bei ausgeschalteter Automatik steht dabei, was sonst passieren würde.
  - Spalte „Typ“ der Konsole zeigt „DG65-Toolkit Verschattung“ (Übersetzung des Modulnamens, Verbund-Regel 24.11.).
  - Zusammenfassung im Assistenten: „Heizen oder Kühlen aus: Außentemperatur, Wärmepumpe, N Raumtemperaturen“ und Notfall.


## 0.5.0 (in Entwicklung)

- **Der Behang als günstigste Heizung und Kühlung des Raums** (Dietmar 24.11.): drei Lagen statt nur Sommer/Winter.
  - **Kühlbedarf** (Raum zu warm, Wärmepumpe kühlt oder draußen Sommer) → voll beschatten.
  - **Heizbedarf** (Wärmepumpe heizt, Raum unter der Heizgrenze – neuer Schieberegler, Vorgabe 21 °C – oder außen kühl) → Sonne herein.
  - **Neutral** (Raum behaglich) → nur Blendschutz, wenn eingerichtet, sonst offen. Hysterese 0,5 K.
  - **Wärmepumpe** wird über den Verbundvertrag `heatpump` (Betriebsart `operatingModeNormID`) automatisch gefunden, bei WPHub, HeishaMon und jedem anderen Modul mit diesem Vertrag. Live: WPHub und HeishaMon melden Heizen + Warmwasser.
- **Blendschutz je Fenster:** neue Spalten „Sonne max. in Raum (cm)“, „Fensterhöhe“ und „Brüstung“. Der Behang fährt nur so weit herunter, dass die Sonne höchstens so tief in den Raum fällt, und folgt dem Sonnenstand. Gilt bei neutraler Lage; bei Kühlbedarf beschattet er voll.
- **Fenster und Türen:** Jeder offene Kontakt gilt im Zweifel als Tür → nicht herunterfahren (Aussperrschutz), denn am Namen lässt sich nicht erkennen, ob man hinausgehen kann (Dietmar: „Wir haben nur Türen“). Meldet ein Griff „gekippt“, fährt der Behang höchstens auf die **Lüftungsposition** (Schieberegler, Vorgabe 70 %). Für echte Fenster ohne Kippmeldung erlaubt die Spalte „Kontakt offen: Lüftungsposition (Fenster)“ dasselbe.
- **Notfall:** Meldet ein Rauch- oder Brandmelder Alarm, fahren alle Behänge sofort hoch – auch nachts, nach Handbedienung und bei ausgeschalteter Automatik. Neues Panel „🔥 Notfall“ mit der Melderliste; „Geräte und Sensoren erkennen“ und der Assistent (Schritt 7) tragen die Melder ein. Live gefunden: Rauchmelder Heizung (Rauch) und Rauchmelder Vorrat (Hitze).
- **„❓ Warum?“:** Probelauf ohne Fahrt und ohne Zustandsänderung. Zeigt je Behang den ganzen Entscheidungsweg mit den Messwerten: Sonnenstand, Einstrahlung, Fassade und Horizont, Raum und Klima, Fenster/Tür, Wetter, Ergebnis.
- Behoben: „Neu zeichnen“ in der Vorschau meldete „Undefined variable $Fassaden“ (live 24.11.).


## 0.4.0 (in Entwicklung)

- **Wann beschatten – mit Messwerten statt nur nach Sonnenstand** (Dietmar 24.11.: „Woran machst Du fest, dass nun Sonne auf der Fassade ist?“):
  - **Einstrahlung in W/m²** mit Schieberegler „Beschatten ab“/„Beenden unter“ (Vorgabe 250/150 W/m²). Der Regler reicht bis zum Klarhimmelwert der eigenen Breite; darunter steht, was dort im Juni und im Dezember mittags möglich ist (48° N: ≈ 930 / 280 W/m²). Lux-Sensoren werden umgerechnet (1 W/m² ≈ 120 lx); die Einheit der Variable entscheidet. Unter dem Sensorfeld steht der umgerechnete Wert.
  - **Sommer oder Winter aus Außen- und Raumtemperatur:** beschatten, wenn der Raum zu warm ist (Schieberegler, Vorgabe 24 °C) oder draußen Sommer ist (Schieberegler, Vorgabe 18 °C). Im Winter bleibt der Behang oben und die Sonne wärmt das Haus. Hysterese 0,5 K.
  - **Raumhelligkeit nur zum Herunterfahren** (Schieberegler 0–20 W/m², Vorgabe 1,5): Erreicht die Sonne den Raum nicht, bleibt der Behang oben; das Hochfahren hängt nie davon ab.
  - Begründungen im Status nennen die Werte, z. B. „Sonne auf der Fassade, aber nur 180 W/m² (Beschattung ab 250 W/m²)“ oder „außen kühl (6,4 °C), Raum 22,0 °C: Die Sonne darf das Haus wärmen“.
- **Erkennung:** Einstrahlung der Wetterstation (auch Vorlage „Solar“) geht vor Lux außen; Mittelwerte („MW 15 Minuten“) werden übergangen. Neu je Behang: **Raumtemperatur** und **Raumhelligkeit** über den Raumnamen (Nummern trennen: Kind I ≠ Kind II; Sollwerte, Gerätetemperatur des Aktors und Wetterstation nie). Live gegen die eigene Anlage geprüft: sechs von sechs Raumtemperaturen richtig.
- Assistent Schritt 7 und Formular „Feinheiten“ neu mit Schiebereglern; die Auswahl „immer / nur hell / nur warm“ entfällt. Die alten Eigenschaften HellAn/HellAus/TemperaturMin sind ungenutzt; die Temperaturvariable gilt jetzt als **Außen**temperatur.
- Live-Befunde 24.11.: Die Froggit-„Solar Einstrahlung“ liefert Lux (Juli-Spitze 104 169 lx ≈ 870 W/m²), nicht W/m². Raumsensoren messen hinter Glas wenig: Sonne im Raum ≈ 200–2 100 lx, Wolken 30–100 lx.

### aus 0.3.3 (nie veröffentlicht)


- **Wetterschutz über WarnHub klar erkennbar:** Wertet WarnHub die Wetterstation aus und ist „WarnHub nutzen“ an, steht im Formular und im Assistenten „Der Wetterschutz kommt von WarnHub: Wetterstation „…“ (Wind, Regen) und amtliche Warnungen. Hier ist nichts einzutragen – die Windgrenzen werden in WarnHub eingestellt.“ Eigener Windsensor, Windgrenzen, Regensensor, Böenvorhersage und „Markisen ohne Wetterquelle“ sind dann ausgeblendet (im Assistenten auch die externe Sperre). Haken „WarnHub nutzen“ raus → die Felder erscheinen wieder. Keine Doppelauswertung (Dietmar 24.11.).

## 0.3.2 (in Entwicklung)

- Fassaden ohne Gerät werden beim „OK“ des Assistenten auf „nicht aktiv“ gesetzt. Sie bleiben in der Liste – kommt später ein Behang dazu, reicht ein Häkchen. Vorschau und Zusammenfassung nennen nur noch aktive Fassaden, der Grundriss zeigt die ungenutzten blass, die Probefahrt bietet nur Fassaden mit Geräten an (mit Namen, z. B. „B · Süd“).

## 0.3.1 (in Entwicklung)

- Probefahrt im Assistenten wählt die Fassade mit den meisten Geräten vor (vorher immer A).
- Konsolen-Test 0.3.0 (24.11.): Kreuze direkt in der Zeile, „Für alle übernehmen“ (Süd), Prüfungen (kein Kreuz; Nord + Süd) und die automatische Zuordnung aller sieben Behänge zu B · Süd nach „Haus erkennen“ live bestätigt; Info-Felder ohne Häkchen unverändert.

## 0.3.0 (in Entwicklung)

- **Neuer erster Schritt im Assistenten: Ausrichtung ankreuzen.** Je Behang ein Kreuz bei Nord, Ost, Süd oder West – direkt in der Zeile, ohne Dialog; zwei benachbarte Kreuze = Ecke (z. B. Süd + West), kein Kreuz = nicht verschatten. „Alle Geräte ankreuzen: Süd → Für alle übernehmen“ in einem Klick. Vorbelegt aus Objektbaum/Info-Feld/Name. Nach „Haus erkennen“ ordnet der Assistent jedes Gerät der passenden Fassade zu (höchstens 45° daneben); die Geräteseite ist nur noch Kontrolle. Der Assistent hat jetzt 8 Schritte.
- **Häkchen „Auch ins Info-Feld der Geräte schreiben“** (standardmäßig aus): schreibt „Ausrichtung: Süd“ ins Info-Feld der Geräteinstanz, damit andere Module sie lesen. Eine vorhandene Ausrichtungszeile wird ersetzt, übriger Text bleibt.

## 0.2.5 (in 0.3.0 enthalten)

- **Ausrichtung aus dem Objektbaum:** Liegt ein Behang (oder ein Link auf ihn) in einem Ordner mit Himmelsrichtung („Südseite“, „Fassade West“, „Ostflügel“) oder steht im Info-Feld „Ausrichtung: Süd“ bzw. „Ausrichtung: 166°“, wird die Fassade automatisch vorgeschlagen – im Assistenten und bei „Geräte und Sensoren erkennen“. Gradzahlen zählen nur mit „Ausrichtung“ davor oder allein (live: „Anzeige um 180° drehen“ wird nicht gelesen).
- **Assistent, Schritt 3: „Alle Geräte auf Fassade … → Für alle übernehmen“** – ein Klick, wenn alle motorischen Behänge an einer Seite hängen; Ausnahmen danach per Zahnrad.
- Hintergrund: Ein Lernen der Ausrichtung aus Raumhelligkeit oder Behang-Positionen im Archiv wurde gemessen und verworfen (trennt nicht verlässlich).

## 0.2.4 (in Entwicklung)

- **Bis zu vier Fenster-/Türkontakte je Behang** (Spalten „Kontakt 2–4“). Ist einer davon offen, fährt der Behang nicht herunter. Hintergrund (Live-Befund 24.11.): Im Schlafzimmer hängen „Fenster Links“ und „Fenster Rechts“ an einem Fenster, und eine Fensterfront kann noch mehr Flügel haben.
- Erkennung nimmt alle Kontakte im passenden Raum (Seitenangaben und Nummern trennen weiterhin), erst bei mehr als vier bleibt es leer. Bei dir: Schlafzimmer → Fenster Links + Rechts.

## 0.2.3 (in Entwicklung)

- Statuszeile: bei mehr als vier Geräten steht „+2 weitere“ dahinter, statt die übrigen still wegzulassen.
- Konsolen-Nachtest des Assistenten (24.11., v0.2.2): gewählte Fassaden kommen jetzt im Formular an und werden gespeichert, Zusammenfassung nennt die Geräte je Fassade, „nicht verschatten“ bleibt draußen, Fenster breit genug ohne Scrollen, keine rote Leerfeld-Meldung.

## 0.2.2 (in Entwicklung)

Befunde aus dem ersten Durchklicken des Assistenten in der Konsole (Chrome, 23.11.):

- **Fehler behoben:** Die im Assistenten gewählten Fassaden kamen nicht im Formular an (alle Geräte ohne Fassade gespeichert), und die Zusammenfassung nannte keine Geräte. Ursache: Listenänderungen aus dem Bearbeiten-Dialog stehen auf späteren Seiten nicht zur Verfügung. Der Assistent merkt sich die Geräteseite jetzt beim „Weiter“ als Entwurf; Probefahrt, Zusammenfassung und Übernahme lesen daraus.
- Die Fassaden-Auswahl der Geräteliste im Formular kennt immer A–L (mit Namen, soweit bekannt) – vorher wurde eine im Assistenten gewählte, noch nicht gespeicherte Fassade beim Speichern verworfen.
- Geräteseite: Gerätename auch im Bearbeiten-Dialog sichtbar, schmalere Spalten, breiteres Fenster.
- Leere Sensorfelder im Assistenten zeigen keine rote Meldung „Ausgewähltes Objekt muss eine Variable sein“ mehr.
- Nach „OK“ zeigt das Formular auch den Erkennungsstand und die Quellen.

## 0.2.1 (in Entwicklung)

- Unter jedem Sensorfeld (Wind, Regen, Sperre, Helligkeit, Temperatur) steht jetzt sichtbar, womit es verbunden ist – mit aktuellem Wert und Alter, z. B. „✓ Wetterstation / Außentemperatur: 1,1 °C · vor 0 min“. Ist das Feld leer und es gibt einen passenden Kandidaten: „🔍 Gefunden: …“ mit Wert. Wertet WarnHub die Wetterstation aus, steht das dort. Gelöschte Variablen werden gemeldet. Dasselbe im Assistenten.
- Neu erkannt: externe Sperre (Wind-/Unwetteralarm einer Wetterstation, z. B. KNX); auch im Assistenten (Wetterschutz).
- Variablen von WarnHub selbst werden nie als Sensor zugeordnet.
- Live geprüft (Privat, Symcon 9.1): Seitenprüfungen, Verzweigung Wetterschutz, Geräte-/Sensorerkennung (0,7 s) und Übernahme des Assistenten; die Darstellung des Assistenten in der Konsole ist noch nicht gesehen.

## 0.2.0 (in Entwicklung)

- Neu: „🔍 Geräte und Sensoren erkennen“ ersetzt die Vorschlagsliste. Erkennt Rollläden, Raffstores/Jalousien und Markisen je Geräteinstanz – herstellerunabhängig über Darstellung „Shutter“, Profil, Auf/Ab/Stopp-, Lamellen-/Neigungsvariable, Idents und Modulnamen, Namen nur ergänzend – samt Lamelle (Richtung aus dem Profil) und eindeutigem Fenster-/Türkontakt. Gruppensteuerungen, Beschattungs-Steuermodule und Dimmer bleiben draußen. Nur die Fassade wählt der Nutzer.
- Neu: leere Felder Wind, Regen, Helligkeit, Temperatur werden mit passenden Sensoren gefüllt (Wetterstation bevorzugt; keine Tageshöchstwerte, Regenmengen, Prognosen, Einstrahlung, Innenraumsensoren). Wertet WarnHub die Wetterstation aus, bleiben Wind und Regen leer.
- Fensterkontakt: Richtung („true = geschlossen“) aus der Darstellung bzw. dem Profil; Matter- und Zigbee2MQTT-Kontakte ohne Profil werden richtig gelesen.
- Geräte ohne Fassade erscheinen im Status mit „Fassade wählen“.
- Symcon-Vorlagen als stärkstes Merkmal: „Fenster“/„Tür“ (Kontakt), „Rollladen (Invertiert)“ und Lamellen-Vorlagen, „Außentemperatur“, „Regen“, „Wind“, „Beleuchtungsstärke“; „Solarstrahlung“ und „Raumtemperatur“ werden nie als Helligkeit bzw. Außentemperatur genommen. Rollladen-Darstellung: Verwendungstyp Öffnung = Position, Rotation = Lamelle.
- Laufrichtung und Höchstwert aus der Rollladen-Darstellung („offen bei“/„geschlossen bei“), sonst wie bisher aus dem Profil; Profil auch aus der Darstellung „Legacy-Profil“ gelesen.
- Shelly ab Werk: Die Lamelle trägt dort ebenfalls `~Shutter` – sie wird über Name/Ident als Lamelle erkannt, nicht als Position.
- Neu: **Einrichtungsassistent** (Symcon 9.1, mehrseitiges Popup) in sieben Schritten: Standort → Haus → Geräte (nur Fassade wählen) → Probefahrt → Wetterschutz (nur bei Markise/Raffstore) → Wann beschatten → Zusammenfassung. Startet bei einer neuen Instanz von selbst, sonst über „🧭 Einrichtungsassistent starten“. Jede Seite prüft ihre Eingaben, bevor es weitergeht. Er füllt am Ende das Formular aus; gespeichert wird wie immer mit „Änderungen übernehmen“.
- Die Vorschlagsliste „Geräte hinzufügen …“ entfällt; Probefahrt im Formular bleibt.
- Mindestversion jetzt **Symcon 9.1** (für den Einrichtungsassistenten).

## 0.1.2 (in Entwicklung)

Befunde aus dem ersten Live-Test:

- Gerätevorschläge zeigen nur noch echte Behänge. Begleitvariablen derselben Geräteinstanz (Lamelle/Drehwinkel, Auf/Ab/Stopp, Z-Wave-Sicherung, Geschwindigkeit) und fremde Lamellen (z. B. Klimaanlage) fallen heraus. Im Test: 8 statt 30 Vorschläge.
- Himmelsansicht: Der Horizont wird maßstabsgetreu gezeichnet. Bisher war er dreifach überhöht, dadurch wirkte die tiefe Wintersonne verdeckt, obwohl sie es nicht war.
- Lamellen mit dem Profil `~Lamella.Reversed` werden beim Übernehmen automatisch als „umgekehrt“ eingetragen.
- Nachts lautet der Grund „Nacht“ statt „Keine direkte Sonne“; Probefahrt mit einem Gerät in der Einzahl.

## 0.1.1 (in Entwicklung)

- WarnHub-Schutzaktionen (ab WarnHub 1.22.0, Vertrag „protection“ 1.0): Geräte, die WarnHub gesichert hat, bleiben in Ruhe – auch Rollläden –, bis die Warnung endet plus Wartezeit oder WarnHub selbst zurückgestellt hat. Eine andere Hauptversion des Vertrags wird ignoriert.

## 0.1.0 (in Entwicklung)

- Erste Fassung: Standort aus der Standort-Instanz, Fassaden aus ALKIS (15 Länder), OpenStreetMap und geo.admin.ch, Horizont von PVGIS.
- Grafik im Formular: Grundriss mit Fassaden und Sonnenbahn, Himmelsansicht mit Horizont, Sonnenzeiten je Fassade.
- Rollladen, Raffstore mit Lamellennachführung, Markise; Handbedienung hat Vorrang.
- Wetterschutz: Windsensor, Regensensor, externe Sperre, WarnHub, Böenvorhersage.
