# NRG-Stack Verschattung

Rollläden, Raffstores und Markisen nach dem Sonnenstand – eingerichtet über eine Grafik statt über Winkelangaben.

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, listet [SUITE.md](https://github.com/DG65/EMS/blob/main/SUITE.md). Das Modul läuft auch allein, ohne andere NRG-Stack-Module; mit ihnen nutzt es den Betriebsmodus der Wärmepumpe (Vertrag `heatpump`) und die Schutzaktionen von WarnHub.

> **Stand 0.10 (Beta):** im Test an einer echten Anlage.

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

- **Sonne auf der Fassade:** Die Sonne steht höchstens 80° seitlich der Fassadenrichtung und mindestens 1° über dem Horizont – dem höheren von Gelände (PVGIS) und Nachbargebäuden (Modell: Geschosse × 3 m, ohne Angabe 2 Geschosse, Satteldach 45°, First längs). Der Sonnenstand wird im Modul selbst berechnet (NOAA).
- **Scheint die Sonne wirklich?** Einstrahlung in W/m² (Wetterstation bevorzugt; Lux-Sensoren werden umgerechnet, 1 W/m² ≈ 120 lx – die Einheit der Variable entscheidet). Beschatten ab / Beenden unter mit Hysterese, Vorgabe 250 / 150 W/m². Klarer Himmel mittags auf 48° N: etwa 930 W/m² im Juni, 280 W/m² im Dezember.
- **Der Behang als günstigste Heizung und Kühlung:** *Kühlbedarf* – Raum zu warm (Vorgabe 24 °C), Wärmepumpe kühlt oder draußen Sommer (Vorgabe 18 °C) – heißt voll beschatten. *Heizbedarf* – Wärmepumpe heizt, Raum unter 21 °C oder außen kühl – heißt: Behang oben, die Sonne wärmt das Haus. Dazwischen (Raum behaglich) nur Blendschutz. Die Wärmepumpe findet das Modul über den Verbundvertrag `heatpump`. Ohne Temperaturen beschattet es immer bei Sonne.
- **Blendschutz je Fenster (optional):** Mit „Sonne max. in Raum“, Fensterhöhe und Brüstung fährt der Behang nur so weit herunter, dass die Sonne höchstens so tief hereinfällt.
- **Raumhelligkeit nur zum Herunterfahren:** Bleibt der Helligkeitssensor im Raum unter der Schwelle (Vorgabe 150 lx), erreicht die Sonne den Raum nicht und der Behang bleibt oben. Hochgefahren wird nie danach – mit heruntergefahrenem Behang ist der Raum ohnehin dunkel.
- Raumtemperatur und Raumhelligkeit erkennt das Modul je Behang über den Raumnamen (Spalten „Raumtemperatur“, „Raumhelligkeit“ der Geräteliste).
- **Verzögerungen** (Standard 3 min bis Beschatten, 15 min bis Ende) verhindern Hin- und Herfahren.
- **Raffstores:** Die Lamellen werden so gestellt, dass direkte Sonne gerade nicht durchkommt (Cut-off nach dem Profilwinkel), und erst ab 8° Änderung nachgeführt.
- **Handbedienung hat Vorrang:** Wer ein Gerät von Hand fährt, hat Ruhe, bis sich die Sonnenlage ändert.
- **Zurückgefahren** wird nur, was das Modul selbst gefahren hat, und nur bei Tageslicht. Das Schließen in der Dämmerung bleibt beim Nutzer oder einer anderen Automatik – außer, die Spalte „Nachts“ ist gesetzt.
- **Nachts (je Behang, ab Werk aus):** „Winter: zu“ – braucht das Modul Wärme (Heizbetrieb), schließt der Behang nach der Dämmerung ganz (Raffstore mit Lamellen zu) und hält die Wärme im Haus; morgens fährt hoch, was das Modul nachts geschlossen hat. „Winter zu, Sommer auf“ – zusätzlich öffnet der Behang in Sommernächten, wenn es draußen mindestens 2 K kühler ist als im Raum. Wer nachts von Hand fährt, hat bis zum Morgen Ruhe; offene Türen werden nicht zugefahren; Markisen nie. Braucht Außentemperatur oder Wärmepumpe, für den Sommer auch die Raumtemperatur.
- **Was zu ist, bleibt zu:** Steht ein Behang schon weiter unten als nötig (Höhe und Lamellen), bleibt er so und wird später auch nicht hochgefahren.
- **Schalter je Behang:** „Automatik <Name>“ (Variable und Kachel). Aus → nur noch Notfall und Wetterschutz.
- **Fenster-/Türkontakt:** Ist er offen, fährt der Behang nicht herunter (Aussperrschutz) – jeder offene Kontakt gilt als Tür. Meldet ein Griff „gekippt“ oder ist die Spalte „Kontakt offen“ auf „Lüftungsposition (Fenster)“ gestellt, fährt er höchstens bis zur Lüftungsposition.
- **Notfall:** Meldet ein Rauch- oder Brandmelder Alarm, fahren alle Behänge sofort hoch, auch nachts und bei ausgeschalteter Automatik.
- **„❓ Warum?“** zeigt je Behang den ganzen Entscheidungsweg mit den Messwerten, ohne dass etwas fährt.
- **Ziel-Helligkeit im Raum (optional):** Ist weder Heizen noch Kühlen nötig, regelt der Behang auf die eingestellte Helligkeit (z. B. 200 lx) – zu hell: weiter zu (Raffstores: Lamellen), zu dunkel: weiter auf, ein Schritt alle 2 Minuten.

## Kachel und IPSView

Die Kachel zeigt die Lage in einem Satz („Winter: Die Sonne heizt mit“) mit Einstrahlung, Außentemperatur und Sonnenzeiten, die Sonnenwärme von heute, ein Sonnenstandsdiagramm mit Horizont und Nachbarhäusern (per Klick ausblendbar), den Tagesverlauf und jeden Behang als Karte mit Stellung und Grund. Ein Tipp auf einen Behang zeigt den Entscheidungsweg; die Automatik lässt sich auf der Kachel schalten, gesamt und je Behang.

**Sonnenwärme:** Wie viel Wärme die Sonne heute durch die Fenster ins Haus bringt und wie viel die Behänge abhalten (kWh), dazu die Leistung jetzt, der Vortag und was bei klarem Himmel mit offenen Behängen heute möglich wäre. Geschätzt aus dem Einstrahlungssensor (aufgeteilt in direkt und diffus), dem Sonnenstand samt Horizont und Nachbarn, der Fensterfläche (Spalte „Fensterfläche“, leer = 2 m², Rahmenanteil 30 %; eine Markise zählt nur mit eingetragener Fläche, weil sie meist vor einem Fenster mit eigenem Behang hängt), der Verglasung (Feinheiten: Zweifach g 0,6 · Dreifach g 0,5 · Sonnenschutzglas g 0,35) und der Stellung jedes Behangs (Abminderung angelehnt an DIN 4108-2 und ÖNORM B 8110-6-1: Rollladen zu ≈ 10 %, Raffstore mit waagrechten Lamellen ≈ 50 %, bei 45° ≈ 25 %, zu ≈ 10 %, Markise ≈ 50 % Durchlass). Die Tageswerte stehen auch in den Variablen „Sonnenwärme heute“ und „Hitze abgehalten heute“. Ohne Einstrahlungssensor entfällt die Schätzung.

**Mit HeishaMon (ab 1.46.0):** Ist dort die Raumführung mit Sonnenwärme verfügbar, aber ausgeschaltet, zeigen Assistent (Schritt 7) und Feinheiten einen Hinweis mit Schalter „In HeishaMon einschalten“. Erst nach dem Klick schaltet die Verschattung die Variable „Sonnenwärme der Verschattung nutzen“ von HeishaMon ein – Einstellungen von HeishaMon fasst sie nie an.

Für IPSView: ein WebView-Element mit der Adresse `http://<Symcon-IP>:3777/hook/verschattung<Instanz-ID>` anlegen (optional `?bg=1c1c1e`). Die Seite zeigt nur an und aktualisiert sich jede Minute.

## Wetterschutz

Markisen und Raffstores fahren bei Wind und Unwetter ein – das geht allem vor, auch der Handbedienung. Rollläden bewegt der Wind nicht. Quellen, alle optional:

- **eigener Windsensor** bzw. eigene Wetterstation (Einheit km/h, m/s, mph oder kn aus dem Profil), Vorgabe Markise 35 km/h, Raffstore 50 km/h, danach 20 min Haltezeit; reagiert sofort, nicht erst im Minutentakt
- **Regensensor** (nur Markisen)
- **externe Sperrvariable**, z. B. der Windalarm einer KNX-Wetterstation
- **WarnHub** (DG65), falls installiert: Sturm-, Hagel- und Gewitterwarnungen der festen Standorte fahren ein, schon ab dem Vorlauf der WarnHub-Schutzaktionen; Frost und Schnee halten die Geräte still. Ab WarnHub 1.22.0 bleiben außerdem genau die Geräte in Ruhe, die eine WarnHub-Schutzaktion gefahren hat (auch Rollläden), bis die Warnung endet plus Wartezeit bzw. bis WarnHub selbst zurückgestellt hat. Wertet WarnHub die Wetterstation aus, sie hier nicht noch einmal auswählen.
- **Böenvorhersage aus dem Internet** (Open-Meteo, alle 15 min) – langsamer als ein Sensor und kein Ersatz dafür, deshalb 10 km/h vorsichtiger

**Ohne jede Wetterquelle fährt eine Markise nie automatisch aus**, außer „Markisen ohne Wetterquelle ausfahren“ ist bewusst angehakt.

## Für Skripte

`VSCH_GetState(int $InstanzID): string` – JSON, Vertrag `shading` 1.1 (nur lesend, additiv):

| Feld | Bedeutung |
|---|---|
| `contractType`, `contractVersion` | `shading`, `1.1` (`version` wie bisher, gleicher Wert) |
| `ts` | Unix-Zeit des letzten Regeltakts (läuft jede Minute; älter als etwa 30 Minuten = Modul steht) |
| `mode` | `heat` (die Sonne soll mitheizen), `cool` (Hitze abhalten), `neutral` |
| `gainNowW`, `gainTodayWh`, `blockedTodayWh` | Sonnenwärme jetzt (W), heute ins Haus (Wh), heute abgehalten (Wh); `null` ohne Einstrahlungssensor |
| `basis` | `forecast`: Wolken aus der PV-Prognose des NRG-Stack (Modul PV-Prognose, frisch, ohne Schnee auf den Modulen); `clearSky`: nur Sonnenstand bei klarem Himmel |
| `gainNextHoursWh` | 6 Werte: erwartete Sonnenwärme (Wh) in den nächsten 6 Stunden ab jetzt, je Stunde, mit den Behängen, wie sie gerade stehen |
| `heating` | Heizung in den Räumen laut Einstellung: `radiator` (Heizkörper, reagieren schnell), `floor` (Fußbodenheizung, träge), `mixed`, `unknown`. Ein Abnehmer wählt damit zwischen Momentwert (schnell) und Erwartung (träge). |
| `devices` | je Behang: Name, Fassade, Typ, Positionsvariable, ob beschattet wird, ob von Hand bedient, Grund, `gainNowW`, `gainTodayWh` |

Die Wolken je Stunde: PV-Prognose ÷ PV-Leistung bei klarem Himmel für die Generatoren aus `PVF_GetGenerators` (Neigung, Richtung, kWp). Ohne PV-Prognose oder bei veralteter Prognose gilt `clearSky` – ein Abnehmer, der Wetter braucht, verhält sich dann neutral.

## Voraussetzungen

- Symcon 9.1 oder neuer

## Lizenz

PolyForm Noncommercial 1.0.0 – für den privaten Gebrauch kostenlos. Siehe [LICENSE](LICENSE).
