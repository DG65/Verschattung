# Changelog

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
