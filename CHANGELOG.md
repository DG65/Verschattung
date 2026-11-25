# Changelog

## 0.17.2 (in Entwicklung)

- **Abstimmung mit DG65-Toolkit Automat (Vertrag shutters 1.0):** Behänge, die ein aktiver Automat-Typ „Rollladen“ morgens und abends fährt (`AUTOMAT_GetShutters`, ab Automat 0.3.0), bekommen keine Nachtfunktion – keine zweite Automatik. Grund im Entscheidungsweg, Hinweis im Formular bei der Geräteliste. Ohne Automat ändert sich nichts.


## 0.17.1 (in Entwicklung)

- **Nachtfunktion: „Morgens frühestens öffnen“** (Feinheiten, Vorgabe 07:00, wählbar „mit der Sonne“ oder 05:00–10:00): Was die Nachtfunktion geschlossen hat, öffnet morgens erst, wenn die Sonne da ist **und** die Uhrzeit erreicht ist – Schlafzimmer bleiben dunkel. Anlass: Abstimmung mit DG65-Toolkit Automat (Typ „Rollladen“ hat schon „frühestens“).
- **`VSCH_GetState`: `devices[].shading` genauer** – nur noch wahr, wenn die Verschattung den Behang gerade wirklich beschattet (Automatik gesamt und je Behang an, keine Handbedienung). Automat überlässt solchen Behängen morgens das Öffnen und holt es nach, sobald `shading` auf falsch wechselt.


## 0.17.0 (in Entwicklung)

- **Szenen-Tasten (Idee 6):** auf der Kachel „Alle auf“, „Alle zu“, „Lüften“, „Automatik“, im Skript `VSCH_Szene($id, 'auf'|'zu'|'lueften'|'automatik')`. Eine Szene wirkt wie eine Handbedienung (Ruhe bis zum nächsten Sonnenwechsel, Nachtfunktion ruht bis morgen), „Automatik“ gibt die Behänge sofort zurück. Offene Türen werden nicht zugefahren (Aussperrschutz), Markisen fahren nur ein, Notfall und Wetterschutz gehen vor; der Gerätewächter prüft auch Szenen-Fahrten.


## 0.16.0 (in Entwicklung)

- **Abwesenheit / Urlaub (Idee 5):** Niemand zu Hause → im behaglichen Bereich voll beschatten statt Blendschutz oder Helligkeitsregelung (Grund „Niemand zu Hause: voll beschatten …“); Heizbetrieb bleibt unverändert. Quelle: neue Einstellungen „Anwesenheit (optional)“ und „Die Variable bedeutet“ in den Feinheiten; ohne Variable automatisch „Simulation aktiv“ der Anwesenheitssimulation (DG65-Toolkit). Kachel zeigt „🏖 Niemand zu Hause“, `VSCH_GetState` liefert `absent`.


## 0.15.0 (in Entwicklung)

- **Gerätewächter (Idee 4):** prüft nach jedem Fahrbefehl, ob der Behang sein Ziel erreicht (Toleranz 5 %). Nach der einen Wiederholung (5 min) und weiteren 5 min ohne Erfolg → Störung: neue Variable „Gerätewächter“ (Text, leer = in Ordnung), Warnung im Meldungsfenster (einmal), Hinweis und rote Zeile auf der Kachel, `fault` in `VSCH_GetState`. Handbedienung seit dem Befehl löst nichts aus; am Ziel angekommen → Störung weg.
- **Fahrtenzähler** je Behang (heute auf der Kachel, heute/insgesamt in `VSCH_GetState`: `movesToday`, `movesTotal`).


## 0.14.0 (in Entwicklung)

- **Vorschau auf der Kachel (Idee 3):** „Heute voraussichtlich beschattet: Süd 11:20–15:40“ – im Winter „Heute voraussichtlich Sonne auf Süd … – die Behänge bleiben oben, die Sonne heizt mit“. Gerechnet ab jetzt im 10-min-Raster: Sonnenzeit der Fassade (Horizont, Nachbarn) × erwartete Einstrahlung ≥ „Beschatten ab“; Wolken aus der PV-Prognose (wie beim Vertrag shading), ohne sie „bei klarem Himmel“. Im Tagesverlauf gestrichelt unter der Sonnenzeit.


## 0.13.3 (in Entwicklung)

- **Lageplan im Formular in voller Breite** (Dietmar 25.11.): gleicher Maßstab, links und rechts mehr Umgebung. Dafür holt das Modul Nachbargebäude aus ALKIS jetzt ±150 m Ost-West und ±100 m Nord-Süd (bis 1000 Gebäude; vorher ±60 m, 200) und Straßen bis 160 m (vorher 120 m); die OSM-Gebäudeabfrage bleibt bei 100 m, weil Overpass oft ausgelastet ist. Live-Probe 14 Länder: ALKIS antwortet in 3–4 s. Bestehende Instanzen laden die weitere Umgebung nach dem Update einmal von selbst im Hintergrund nach (die Fassadenliste bleibt unverändert).


## 0.13.2 (in Entwicklung)

- **Fix: Straßen im Lageplan kamen nicht** (Live-Befund 25.11.). Der Straßendienst (OpenStreetMap/Overpass) braucht bei Dietmar 39 s; der einmalige Hintergrundabruf ging verloren und wurde nie wiederholt, ein „Übernehmen“ brach ihn zudem ab. Jetzt: Antwortet kein Server, gilt das nicht als „keine Straßen“, sondern das Modul versucht es alle 15 Minuten erneut (höchstens einen Tag lang); „Übernehmen“ bricht das nicht mehr ab; der Abruf darf länger laufen.


## 0.13.1 (in Entwicklung)

- **Assistent zeigt das Haus sofort** (Live-Befund 25.11.: neue Instanz, Assistent öffnete sich mit „Das Haus wird gerade erkannt …“ und blieb leer). Ursache: Die Erkennung brauchte 75 s, fast alles für die Straßen des Lageplans (OpenStreetMap/Overpass 27–75 s; Hausumriss und Horizont je 0,3 s). Jetzt erkennt der Assistent beim Öffnen das Haus ohne Straßen (unter 1 s), die Straßen holt der Hintergrund nach.


## 0.13.0 (in Entwicklung)

- **Assistent kürzer und logischer** (Dietmar 25.11.): Haus zuerst, ohne Knopf – das Modul erkennt das Haus im Hintergrund, sobald ein Standort da ist (einmaliger Timer nach dem Anlegen); fehlt es noch, holt der Assistent es beim Öffnen bzw. bei „Weiter“ nach. Der Standort-Schritt entfällt (immer die Standort-Instanz; nur ohne sie Kartenwahl auf der Haus-Seite). Der Ausrichtungs-Schritt mit Nord/Ost/Süd/West entfällt: Im Geräte-Schritt wählt man direkt eine der erkannten Fassaden. Sechs statt acht Schritte.
- Lageplan im Geräte-Schritt in voller Breite (gleicher Maßstab, mehr Umgebung links und rechts).


## 0.12.0 (in Entwicklung)

- **Nachts (Idee 2), neue Spalte je Behang, ab Werk aus:** „Winter: zu (hält die Wärme im Haus)“ – im Heizbetrieb schließt der Behang nach der Dämmerung ganz (Sonne unter −4°), morgens (Sonne über 2°) fährt hoch, was das Modul nachts geschlossen hat. „Winter zu, Sommer auf (kühlt aus)“ – zusätzlich nachts auf, wenn es draußen mindestens 2 K kühler ist als im Raum. Nachts von Hand bewegt → bis zum Morgen in Ruhe; offene Tür → nicht zu; schon vom Nutzer geschlossen → bleibt, wird morgens nicht hochgefahren; Markisen nie. Regel 9 in „So arbeitet die Automatik“.
- **HeishaMon-Abfrage** (Dietmars Idee 25.11., Schnittstelle mit HeishaMon 1.46.0 abgestimmt): Assistent und Feinheiten bieten „In HeishaMon einschalten“ an, wenn dort die Sonnenwärme verfügbar, aber aus ist. Geschaltet wird nur nach Klick und nur über HeishaMons Schaltvariable `RoomShift_SolarAssist` (RequestAction), nie über dessen Eigenschaften; gelesen über `HEISHA_GetSolarAssist`.


## 0.11.1 (in Entwicklung)

- **Sonnenwärme: Durchlass der Behänge nach Norm** (Recherche 25.11.): Markise 0,5 statt 0,3 (DIN 4108-2, Tuch nicht parallel zum Glas), Rollladen zu 0,1 statt 0,05 (ÖNORM B 8110-6-1: gtot ≈ 0,05 bei g ≈ 0,5), Raffstore waagrecht 0,5 / 45° 0,25 (DIN) / zu 0,1; ohne Lamellenstellung gilt 45°. Wirkt nur auf die kWh-Schätzung und den Vertrag, nicht auf die Steuerung.


## 0.11.0 (in Entwicklung)

- **Vertrag `shading` 1.1 (`VSCH_GetState`, nur lesend, additiv)** für die Raumführung der HeishaMon-Heizungsautomatik (Dietmar 25.11.: „Ja, nur bei basis forecast“): `mode` (heat/cool/neutral), Sonnenwärme jetzt/heute/abgehalten, `basis` (forecast/clearSky) und **`gainNextHoursWh`** – die erwartete Sonnenwärme der nächsten 6 Stunden je Stunde. Die Wolken kommen aus der PV-Prognose des Verbunds (PVF_GetForecast ÷ PV bei klarem Himmel aus PVF_GetGenerators); veraltet (> 6 h) oder mit Schnee auf den Modulen → `clearSky`. Je Behang zusätzlich `gainNowW`, `gainTodayWh`.
- **Abfrage „Heizung in den Räumen“** (Feinheiten und Assistent Schritt 7: keine Angabe / Heizkörper / Fußbodenheizung / beides; Dietmar 25.11.: nicht jeder Wärmepumpen-Haushalt hat eine träge Fußbodenheizung). Im Vertrag als `heating` (radiator/floor/mixed/unknown) – Abnehmer nehmen bei Heizkörpern den Momentwert, bei Fußbodenheizung die Erwartung.
- `VSCHLogik::ebeneEinstrahlung()` für beliebig geneigte Flächen (die senkrechte Fassade ist der Sonderfall 90°).
- **Jetzt Teil des NRG-Stack** (Dietmar 25.11.: der Behang als günstigste Heizung und Kühlung, Sonnenwärme in kWh, Wärmepumpe über den Vertrag `heatpump` – das ist Energiearbeit). Anzeigename „NRG-Stack Verschattung“ in Modulverwaltung, Typ-Spalte, README und Lizenz. GUID, Präfix `VSCH_`, Idents und Repo bleiben – bestehende Instanzen laufen ohne Eingriff weiter. Das Modul läuft weiter auch ganz allein.


## 0.10.1 (in Entwicklung)

- **Sonnenwärme: Markise zählt nur mit eingetragener Fensterfläche** (Live-Test 25.11.): Sie hängt meist vor einer Tür oder einem Fenster mit eigenem Behang – sonst wäre dieselbe Glasfläche doppelt gerechnet.


## 0.10.0 (in Entwicklung)

- **Sonnenwärme in kWh** (Idee 1, Dietmar 24.11.: „Der Behang ist die günstigste Raum-Heiz- und Kühlmethode“ – jetzt sieht man, wie viel):
  - Neue Karte auf der Kachel unter der Lage: heute **ins Haus** und **abgehalten** (im Sommer zuerst „abgehalten“), Balken gegen „bei klarem Himmel und offenen Behängen möglich“, Leistung jetzt, gestern. „geschätzt“, solange Fensterflächen fehlen. Je Behang die Wärme von heute in seiner Karte.
  - Rechnung in `VSCHLogik`: gemessene Globalstrahlung nach Erbs in direkt/diffus geteilt, auf die senkrechte Fassade umgerechnet (direkt nur, wenn Horizont und Nachbarn frei sind; Messung bei tiefer Sonne auf 115 % des klaren Himmels begrenzt), × Fensterfläche × Glasanteil 0,7 × g-Wert × Durchlass des Behangs in seiner Stellung.
  - Neue Gerätespalte **Fensterfläche (m²)**, leer = 2 m²; neue Einstellung **Verglasung** in den Feinheiten (Vorgabe Dreifach, g 0,5).
  - Neue Variablen **Sonnenwärme heute** und **Hitze abgehalten heute** (kWh, um Mitternacht neu; Archiv nach Wunsch selbst einschalten).


## 0.9.0 (in Entwicklung)

- **Kachel neu** im Stil der übrigen NRG-Stack-Kacheln (Karten, ruhige Typografie, klare Zahlen, keine Illustration; Dietmar 24.11.: die erste Fassung mit gemalter Fassade war „naive Kunst“):
  - **Lage-Karte:** Lage in einem Satz („Winter: Die Sonne heizt mit“ / „Sommer: Die Hitze bleibt draußen“), welche Fassade gerade Sonne hat, Wärmepumpe; Kennzahlen Einstrahlung (mit Himmelszustand), außen, Sonnenhöhe; Sonnenauf- und -untergang, Sonnenzeit der Fassade; Automatik als Schalter. Rot nur für Notfall und Wetterschutz.
  - **Sonnenstandsdiagramm** (polar, Norden oben, wie in der Architektur): Höhenringe 0/30/60°, Gelände-Horizont und Nachbarhäuser als Silhouette am Rand, Sichtbereich der Fassade als Sektor, Sonnenbahn heute (auf der Fassade kräftig), Uhrzeiten, Sonne jetzt, Hausumriss mit der Fassade in der Mitte; „Nachbarn an/aus“.
  - **Heute:** Einstrahlung gemessen gegen klaren Himmel, Sonnenzeit der Fassade, Beschattungszeiten, „jetzt“. Der Verlauf wird alle 10 Minuten mitgeschrieben (Attribut, nur heute).
  - **Behänge als Karten je Fassade** (wie Apple Home): Symbol mit Füllstand bzw. Lamellen, Stellung in Klartext, Grund, Raumtemperatur und -helligkeit (mit Ziel), Schalter Automatik; ein Tipp zeigt den Entscheidungsweg.
  - Zeichnet passend zur Kachelgröße, hell und dunkel.


## 0.8.3 (in Entwicklung)

- **„⏯️ Automatik je Behang“ im Formular** (Dietmar 24.11.: „Wo kann ich einen Behang herausnehmen?“): Liste aller Behänge mit Häkchen; Abhaken nimmt einen Behang sofort aus der automatischen Verschattung (ohne „Übernehmen“) – derselbe Schalter wie auf der Kachel und die Variable „Automatik <Name>“. Hinweis darauf bei der Geräteliste.
- **Kachel: Nachbarn per Klick aus- und einblenden** („🏘 Nachbarn an/aus“ im Grundriss) – wirkt auf Grundriss und Himmelsbild, je Betrachter gemerkt. Die Steuerung rechnet immer mit den Gebäuden.


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
