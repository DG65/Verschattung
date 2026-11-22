# Changelog

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
