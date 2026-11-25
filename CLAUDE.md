# CLAUDE.md — Verschattung (NRG-Stack)

Konventionen: `/Users/dietmar/Nextcloud/Claude/TOOLKIT.md` und `SUITE.md`. Geodaten-Recherche: `/Users/dietmar/Nextcloud/Claude/Verschattung-Geodaten-DACH.md`.
Bedienskizze (von Dietmar abgenickt 21.11.2026): https://claude.ai/artifact/K89wSnHGZnveNWKh3yfiaL

- Anzeigename „NRG-Stack Verschattung" (bis 0.10.1 „DG65-Toolkit Verschattung", Wechsel in den NRG-Stack auf Dietmars Wunsch 25.11.2026), Klasse `Verschattung`, Präfix `VSCH`, Repo `DG65/Verschattung` (Freigabe 21.11.2026).
- Aufbau: Entscheidungen nur in `VSCHLogik` (ohne Symcon), Sonne `VSCHSonne`, Geodaten `VSCHGeo` (Ländertabelle `QUELLEN`, live geprüft 20./21.11.2026), Horizont `VSCHHorizont`, SVG `VSCHGrafik`, Texte `VSCHText` (Merkliste wie Automat).
- Formular: Eingabefelder ohne Eigenschaft (Fassadenauswahl, Vorschau, Vorschläge) stehen in `actions`, nie in `elements` (Prüfstand H9).
  Listen kommen in onClick als Symcon-Listenobjekt: erst per foreach in ein Array kopieren (Muster Automat).
- Store-Regel: „Haus erkennen" und „Geräte hinzufügen" schreiben nur per `UpdateFormField` ins offene Formular. Abgeleitete Geodaten in Attributen.
- Übersetzung: englische Quelltexte, Deutsch in `.tools/locale-de.json`; daraus `Verschattung/locale.json` erzeugen
  (`php -r` Einzeiler im README des Prüfstands bzw. siehe unten). Feste Texte außerhalb von `'caption' =>` mit `self::fest()` markieren.
- WarnHub: `WHUB_GetActiveWarnings` (Vertrag 1.0, nur feste Standorte), `IPS_GetProperty(…, 'SchutzaktionVorlaufMinuten')` und ab WarnHub 1.22.0
  `WHUB_GetProtectionState` (Vertrag protection 1.0, Beschreibung im WarnHub-README). Abgleich über targetVariableID oder targetInstanceID;
  `VSCHLogik::schutz()` hält das Gerät still (alle Typen), Wetter-Einfahren geht vor. Andere Hauptversion wird ignoriert.
- Vor jedem Commit: `php .tools/test-verschattung.php` (Rückgabe 0). Live-Probe der Geodaten: `php .tools/geo-live.php [BW BE …]`.
- Nicht live geprüft (Stand 0.1.0): alles in Symcon. Insbesondere Formular-Darstellung (Image-Breite, Suchknopf im Popup,
  Listen in onClick), Echo-Erkennung echter Aktoren (Z-Wave, Shelly), Lamellenrichtung, WarnHub-Anbindung.

Locale erzeugen:
`php -r '$d=json_decode(file_get_contents(".tools/locale-de.json"),true); file_put_contents("Verschattung/locale.json", json_encode(["translations"=>["de"=>$d]], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");'`
