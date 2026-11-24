# DG65-Toolkit Verschattung

Rollläden, Raffstores und Markisen nach dem Sonnenstand – eingerichtet über eine Grafik statt über Winkelangaben.

> **Stand 0.1.1 (in Entwicklung):** am Prüfstand getestet, noch an keiner echten Anlage gelaufen.

## Wozu?

Die Verschattung fährt Rollläden, Raffstores und Markisen, wenn die Sonne auf ihre Fassade scheint, und danach wieder zurück. Die Lamellen von Raffstores folgen der Sonne. Winkel muss niemand eintippen: Das Modul findet das Haus in amtlichen Kartendaten, zeigt seine Fassaden, und man ordnet nur noch die Geräte zu.

## Einrichtung mit dem Assistenten

Beim ersten Öffnen startet der **Einrichtungsassistent** von selbst; später jederzeit über „🧭 Einrichtungsassistent starten“. Er führt in acht Schritten durch alles:

0. **Ausrichtung** – je Behang ankreuzen, wohin seine Fenster schauen (Nord/Ost/Süd/West, zwei Kreuze = Ecke, keins = nicht verschatten); „Für alle übernehmen“ setzt alle in einem Klick. Optional landet die Angabe auch im Info-Feld der Geräte („Ausrichtung: Süd“), damit andere Module sie lesen.
1. **Standort** – aus der Standort-Instanz oder auf der Karte gewählt.
2. **Haus** – „Haus erkennen“ holt die Fassaden aus amtlichen Kartendaten; korrigieren oder ergänzen geht in der Liste.
3. **Geräte** – alle Rollläden, Raffstores und Markisen sind schon erkannt (Typ, Lamelle samt Richtung, bis zu vier Fensterkontakte). **Von Hand wird nur je Gerät die Fassade gewählt** (oder „nicht verschatten“) – mit „Für alle übernehmen“ in einem Klick. Steht die Richtung schon im Objektbaum (Ordner „Südseite“, Info-Feld „Ausrichtung: Süd“), ist sie vorbelegt.
4. **Probefahrt** – je Fassade herunter und wieder hoch, noch bevor etwas gespeichert ist.
5. **Wetterschutz** – nur bei Markisen/Raffstores: WarnHub, eigener Windsensor, Regensensor, Böenvorhersage.
6. **Wann beschatten** – mit Messwerten und Schiebereglern: Einstrahlung (W/m², der Regler zeigt, was auf der eigenen Breite bei klarem Himmel möglich ist), Sommer ab Außentemperatur, Raum zu warm ab, Raumhelligkeit.
7. **Fertig** – Zusammenfassung mit den Sonnenzeiten von heute. „OK“ füllt das Formular, danach mit **„Änderungen übernehmen“** bestätigen.

Der Assistent speichert nichts selbst – die Einstellungen gehören dem Nutzer. Für Feinarbeit bleibt das Formular darunter.

## Einrichtung ohne Assistent


1. **Standort prüfen.** Das Modul nimmt den Standort der Symcon-Standort-Instanz (Kern Instanzen). Steht das Haus nicht genau dort, im Formular einen eigenen Standort auf der Karte wählen.
2. **„Haus aus Kartendaten erkennen“.** Das Modul holt den Gebäudeumriss und leitet daraus die Fassaden ab, dazu den Gelände-Horizont. Die Fassaden erscheinen als Grafik und Liste; mit „Änderungen übernehmen“ bestätigen. Stimmt etwas nicht, Richtung in der Liste korrigieren oder Fassaden von Hand eintragen.
3. **Geräte erkennen, Fassade wählen.** „🔍 Geräte und Sensoren erkennen“ trägt alle Rollläden, Raffstores/Jalousien und Markisen in die Geräteliste ein – mit Typ, Positions- und Lamellenvariable samt Richtung und, wenn eindeutig, dem Fenster-/Türkontakt. Leere Felder für Wind, Regen, Helligkeit und Temperatur werden mit passenden Sensoren gefüllt. **Von Hand bleibt nur die Fassade je Gerät.** Die **Probefahrt** zeigt, ob die Richtung stimmt; sonst die Spalte „0 % bedeutet“ ändern.

   So wird erkannt (bei jedem Nutzer, nicht anhand bestimmter Namen):
   - am sichersten: eine Symcon-Vorlage an der Variable (z. B. „Fenster“, „Außentemperatur“, „Rollladen (Invertiert)“);
   - sicher: Darstellung „Rollladen“ (Verwendungstyp Öffnung = Position, Rotation = Lamelle; Laufrichtung aus „offen bei/geschlossen bei“) oder Profil `~Shutter`/`~Shutter.Reversed`;
   - belegt: die Geräteinstanz hat eine Auf/Ab/Stopp-, Lamellen- oder Neigungsvariable, Idents wie cover/roller/shutter/blind/slat oder einen Modul-/Gerätenamen mit Rollladen/Jalousie/Markise – dann gilt ihre Prozent-/Level-Variable als Position (z. B. Z-Wave ab Werk mit `~Intensity.100`, Homematic `LEVEL`, Zigbee2MQTT `position`);
   - nicht übernommen: Gruppensteuerungen und Beschattungs-Steuermodule (z. B. Blind Control), Dimmer ohne Behang-Merkmal.
   - Fensterkontakt: über Raum-/Gerätenamen (z. B. „Jalousie Wohnen Rechts“ ↔ „Wohnen Terrassentür Rechts“, „Kind I“ ↔ „Kind 1“). Ist es nicht eindeutig (zwei Fenster im Raum, Behang ohne Seitenangabe), bleibt das Feld leer. Ob der Kontakt mit `true` „offen“ oder „geschlossen“ meldet, liest das Modul aus der Darstellung („Geschlossen“/„Closed“) bzw. dem Profil.
   - Sensoren: Wetterstation vor Sensoren anderer Geräte; nie Tageshöchstwerte, Regenmengen, Prognosewerte, Einstrahlung (W/m²) oder Innenraumsensoren. Wertet WarnHub die Wetterstation aus, bleiben Wind und Regen hier leer.
   - Was nicht gefunden wird, lässt sich mit „+“ von Hand ergänzen.
4. **Vorschau prüfen.** Sonnenbahn für heute, den 21. Juni und den 21. Dezember über dem Horizont und „Sonne auf der Fassade von … bis …“.

## Woher die Kartendaten kommen

| Gebiet | Quelle | Lizenz |
|---|---|---|
| Deutschland ohne Bayern | ALKIS-Gebäude des jeweiligen Landes (WFS bzw. OGC API) | je Land: dl-de/zero, dl-de/by-2-0 oder CC BY 4.0 |
| Bayern, Österreich, übrige Länder | OpenStreetMap (Overpass) | ODbL |
| Schweiz | geo.admin.ch, BFE „Sonnenfassade“ (fertige Fassaden) | swisstopo / BFE |
| Horizont überall | PVGIS (EU-JRC) | © Europäische Union |

Abgerufen wird **nur auf Knopfdruck**. Dabei geht die Koordinate an den Kartendienst des Landes, an OpenStreetMap (Nominatim zur Landbestimmung) und an PVGIS. Gespeichert werden nur abgeleitete Werte: Fassadenrichtungen, Nachbargebäude im Umkreis von 80 m und die Horizontlinie. Die Quellenvermerke stehen im Formular.

## Wie entschieden wird

- **Sonne auf der Fassade:** Die Sonne steht höchstens 80° seitlich der Fassadenrichtung und mindestens 1° über dem Gelände-Horizont. Der Sonnenstand wird im Modul selbst berechnet (NOAA).
- **Scheint die Sonne wirklich?** Einstrahlung in W/m² (Wetterstation bevorzugt; Lux-Sensoren werden umgerechnet, 1 W/m² ≈ 120 lx – die Einheit der Variable entscheidet). Beschatten ab / Beenden unter mit Hysterese, Vorgabe 250 / 150 W/m². Klarer Himmel mittags auf 48° N: etwa 930 W/m² im Juni, 280 W/m² im Dezember.
- **Der Behang als günstigste Heizung und Kühlung:** *Kühlbedarf* – Raum zu warm (Vorgabe 24 °C), Wärmepumpe kühlt oder draußen Sommer (Vorgabe 18 °C) – heißt voll beschatten. *Heizbedarf* – Wärmepumpe heizt, Raum unter 21 °C oder außen kühl – heißt: Behang oben, die Sonne wärmt das Haus. Dazwischen (Raum behaglich) nur Blendschutz. Die Wärmepumpe findet das Modul über den Verbundvertrag `heatpump`. Ohne Temperaturen beschattet es immer bei Sonne.
- **Blendschutz je Fenster (optional):** Mit „Sonne max. in Raum“, Fensterhöhe und Brüstung fährt der Behang nur so weit herunter, dass die Sonne höchstens so tief hereinfällt.
- **Raumhelligkeit nur zum Herunterfahren:** Bleibt der Helligkeitssensor im Raum unter der Schwelle (Vorgabe 1,5 W/m² ≈ 180 lx), erreicht die Sonne den Raum nicht und der Behang bleibt oben. Hochgefahren wird nie danach – mit heruntergefahrenem Behang ist der Raum ohnehin dunkel.
- Raumtemperatur und Raumhelligkeit erkennt das Modul je Behang über den Raumnamen (Spalten „Raumtemperatur“, „Raumhelligkeit“ der Geräteliste).
- **Verzögerungen** (Standard 3 min bis Beschatten, 15 min bis Ende) verhindern Hin- und Herfahren.
- **Raffstores:** Die Lamellen werden so gestellt, dass direkte Sonne gerade nicht durchkommt (Cut-off nach dem Profilwinkel), und erst ab 8° Änderung nachgeführt.
- **Handbedienung hat Vorrang:** Wer ein Gerät von Hand fährt, hat Ruhe, bis sich die Sonnenlage ändert.
- **Zurückgefahren** wird nur, was das Modul selbst gefahren hat, und nur bei Tageslicht. Das Schließen in der Dämmerung bleibt beim Nutzer oder einer anderen Automatik.
- **Fenster-/Türkontakt:** Ist er offen, fährt der Behang nicht herunter (Aussperrschutz) – jeder offene Kontakt gilt als Tür. Meldet ein Griff „gekippt“ oder ist die Spalte „Kontakt offen“ auf „Lüftungsposition (Fenster)“ gestellt, fährt er höchstens bis zur Lüftungsposition.
- **Notfall:** Meldet ein Rauch- oder Brandmelder Alarm, fahren alle Behänge sofort hoch, auch nachts und bei ausgeschalteter Automatik.
- **„❓ Warum?“** zeigt je Behang den ganzen Entscheidungsweg mit den Messwerten, ohne dass etwas fährt.

## Wetterschutz

Markisen und Raffstores fahren bei Wind und Unwetter ein – das geht allem vor, auch der Handbedienung. Rollläden bewegt der Wind nicht. Quellen, alle optional:

- **eigener Windsensor** bzw. eigene Wetterstation (Einheit km/h, m/s, mph oder kn aus dem Profil), Vorgabe Markise 35 km/h, Raffstore 50 km/h, danach 20 min Haltezeit; reagiert sofort, nicht erst im Minutentakt
- **Regensensor** (nur Markisen)
- **externe Sperrvariable**, z. B. der Windalarm einer KNX-Wetterstation
- **WarnHub** (DG65), falls installiert: Sturm-, Hagel- und Gewitterwarnungen der festen Standorte fahren ein, schon ab dem Vorlauf der WarnHub-Schutzaktionen; Frost und Schnee halten die Geräte still. Ab WarnHub 1.22.0 bleiben außerdem genau die Geräte in Ruhe, die eine WarnHub-Schutzaktion gefahren hat (auch Rollläden), bis die Warnung endet plus Wartezeit bzw. bis WarnHub selbst zurückgestellt hat. Wertet WarnHub die Wetterstation aus, sie hier nicht noch einmal auswählen.
- **Böenvorhersage aus dem Internet** (Open-Meteo, alle 15 min) – langsamer als ein Sensor und kein Ersatz dafür, deshalb 10 km/h vorsichtiger

**Ohne jede Wetterquelle fährt eine Markise nie automatisch aus**, außer „Markisen ohne Wetterquelle ausfahren“ ist bewusst angehakt.

## Für Skripte

`VSCH_GetState(int $InstanzID): string` – JSON mit allen Geräten: Fassade, Typ, ob beschattet wird, ob von Hand bedient, Grund.

## Voraussetzungen

- Symcon 9.1 oder neuer

## Lizenz

PolyForm Noncommercial 1.0.0 – für den privaten Gebrauch kostenlos. Siehe [LICENSE](LICENSE).
