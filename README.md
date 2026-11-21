# DG65-Toolkit Verschattung

Rollläden, Raffstores und Markisen nach dem Sonnenstand – eingerichtet über eine Grafik statt über Winkelangaben.

> **Stand 0.1.1 (in Entwicklung):** am Prüfstand getestet, noch an keiner echten Anlage gelaufen.

## Wozu?

Die Verschattung fährt Rollläden, Raffstores und Markisen, wenn die Sonne auf ihre Fassade scheint, und danach wieder zurück. Die Lamellen von Raffstores folgen der Sonne. Winkel muss niemand eintippen: Das Modul findet das Haus in amtlichen Kartendaten, zeigt seine Fassaden, und man ordnet nur noch die Geräte zu.

## Einrichtung in vier Schritten

1. **Standort prüfen.** Das Modul nimmt den Standort der Symcon-Standort-Instanz (Kern Instanzen). Steht das Haus nicht genau dort, im Formular einen eigenen Standort auf der Karte wählen.
2. **„Haus aus Kartendaten erkennen“.** Das Modul holt den Gebäudeumriss und leitet daraus die Fassaden ab, dazu den Gelände-Horizont. Die Fassaden erscheinen als Grafik und Liste; mit „Änderungen übernehmen“ bestätigen. Stimmt etwas nicht, Richtung in der Liste korrigieren oder Fassaden von Hand eintragen.
3. **Geräte zuordnen.** Unten bei den Aktionen eine Fassade wählen, „Geräte hinzufügen …“ schlägt passende Positionsvariablen vor. Jedes Gerät mit Positionsvariable funktioniert (z. B. Z-Wave, Shelly, KNX, Homematic, EnOcean, Zigbee). Die **Probefahrt** zeigt, ob die Richtung stimmt; sonst die Spalte „0 % bedeutet“ ändern.
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
- **Optional** Helligkeit (Beschatten ab / Beenden unter, mit Hysterese) und Temperatur (Beschatten erst ab).
- **Verzögerungen** (Standard 3 min bis Beschatten, 15 min bis Ende) verhindern Hin- und Herfahren.
- **Raffstores:** Die Lamellen werden so gestellt, dass direkte Sonne gerade nicht durchkommt (Cut-off nach dem Profilwinkel), und erst ab 8° Änderung nachgeführt.
- **Handbedienung hat Vorrang:** Wer ein Gerät von Hand fährt, hat Ruhe, bis sich die Sonnenlage ändert.
- **Zurückgefahren** wird nur, was das Modul selbst gefahren hat, und nur bei Tageslicht. Das Schließen in der Dämmerung bleibt beim Nutzer oder einer anderen Automatik.
- **Fenster-/Türkontakt:** Ist er offen, fährt der Behang nicht herunter (Aussperrschutz).

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

Symcon 8.1 oder neuer. Für die Erkennung Internetzugang; im Betrieb nur für die optionale Böenvorhersage und WarnHub.

## Lizenz

PolyForm Noncommercial 1.0.0 – für den privaten Gebrauch kostenlos. Siehe [LICENSE](LICENSE).
