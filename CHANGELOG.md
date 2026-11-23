# Changelog

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
