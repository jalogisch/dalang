# das CSV-Format

[English version](csv.md)

CSV heißt „comma-separated values“, also Werte mit Komma getrennt. Eine CSV-Datei ist eine Textdatei mit einem Eintrag pro Zeile. dalang nutzt ein CSV-Format für zwei Dinge:

- **Sichern:** „Alle Links als CSV sichern“ schreibt dieses Format. Diese Datei ist die Sicherung von dalang.
- **Einlesen:** „CSV einlesen“ liest dieses Format. Damit kannst du auch Links aus einem anderen Link-Kürzer übernehmen.

Beide Knöpfe findest du in der Ansicht „Werkzeuge“ auf der Admin-Seite.

```csv
code,url,title,created
abc,https://example.org/eine/lange/adresse,,2026-01-02 03:04:05
mein-vortrag,https://example.org/vortrag?jahr=2026,"Ein Titel mit, Komma und ""Anführungszeichen""","2026-02-03 04:05:06"
```

## die spalten

Eine Datei hat vier Spalten, immer in dieser Reihenfolge:

| # | Name | Inhalt |
|---|---|---|
| 1 | `code` | Das Kürzel: `a-z`, `0-9` und `-`, 1 bis 40 Zeichen. Es darf nicht mit `admin` beginnen. |
| 2 | `url` | Das Ziel: `http://` oder `https://` mit einem Hostnamen, höchstens 2000 Zeichen. |
| 3 | `title` | Freier Text. Er darf leer sein. |
| 4 | `created` | Datum und Uhrzeit in UTC (koordinierte Weltzeit), in der Form `YYYY-MM-DD HH:MM:SS`. |

Regeln für das Sichern:

- **Kopfzeile:** Die erste Zeile ist immer `code,url,title,created`. Sie ist immer auf Englisch, auch wenn die Admin-Seite Deutsch spricht.
- **Zeichensatz:** UTF-8, ohne Byte Order Mark (BOM).
- **Trennzeichen:** ein Komma.
- **Anführungszeichen:** Manche Felder brauchen doppelte Anführungszeichen. Das sind Felder mit Komma, doppeltem Anführungszeichen, Leerzeichen oder Zeilenumbruch. Ein doppeltes Anführungszeichen im Feld steht doppelt da (`""`). Das Datum enthält ein Leerzeichen, darum steht es beim Sichern immer in Anführungszeichen. dalang nutzt keine Backslash-Maskierung.
- **Zeilenende:** ein Zeilenvorschub (`\n`).
- **Reihenfolge:** der älteste Link zuerst.

## was das einlesen macht

Das Einlesen liest die Spalten nach ihrer Position, nicht nach ihrem Namen. Spalte 1 ist das Kürzel, Spalte 2 das Ziel und so weiter. Die Namen in der Kopfzeile spielen keine Rolle. Darum funktioniert auch eine Liste aus YOURLS mit den Spalten `keyword,url,title,timestamp` (siehe [migrate-from-yourls.de.md](migrate-from-yourls.de.md)).

- **Kopfzeile:** Das Einlesen prüft keine Spaltennamen. Es nutzt eine einfachere Regel: Ist das zweite Feld der ersten Zeile kein gültiges Ziel, überspringt das Einlesen die erste Zeile. So funktionieren Dateien mit Kopfzeile und Dateien ohne Kopfzeile. Diese Regel hat eine Nebenwirkung: Auch eine erste Zeile mit einem Link und einem kaputten Ziel wird übersprungen. Das Einlesen zählt sie nicht als abgelehnt.
- **Leere Zeilen:** Das Einlesen überspringt sie.
- **Anzahl der Spalten:** Das Einlesen ignoriert alle Spalten nach der vierten. Weniger Spalten sind auch erlaubt. Ein fehlender Titel bleibt leer. Ein fehlendes Datum wird zur Zeit des Einlesens.
- **Kürzel und Ziel:** Das Einlesen prüft sie mit denselben Regeln wie das Formular. Besteht eine Zeile die Prüfung nicht, lehnt das Einlesen sie ab. Es repariert sie nicht. Die Meldung nennt die Nummern der abgelehnten Zeilen (nur die ersten 20). Die Zählung beginnt oben in der Datei, die Kopfzeile zählt mit.
- **Titel:** Das Einlesen ersetzt Zeilenumbrüche und mehrere Leerzeichen durch ein Leerzeichen. Es kürzt den Titel nach 1000 Zeichen. Ein Titel, der kein gültiges UTF-8 ist, wird leer.
- **Datum:** Hat das Datum nicht die Form `YYYY-MM-DD HH:MM:SS`, bekommt der Link die Zeit des Einlesens.
- **Vorhandene Kürzel bleiben unverändert.** Das Einlesen überschreibt nie einen Link. Es zählt diese Zeilen als „schon vorhanden“. Darum kannst du dieselbe Datei zweimal einlesen, ohne Schaden.
- **Anführungszeichen:** Beim Einlesen sind Anführungszeichen freiwillig. `"2026-02-03 04:05:06"` und `2026-02-03 04:05:06` ergeben dasselbe.

Sichere eine Datei aus dalang und lies sie in ein leeres dalang ein. Ein neues Sichern ergibt dann genau dieselbe Datei. Der Test `deploy/test.sh` prüft das.

Die Beispiel-Konfiguration für nginx nimmt Uploads bis 2 MB an.

## eine warnung

Die CSV-Datei ist eine Sicherung. Sie ist keine Tabellenkalkulation. Titel kommen von den Seiten, die du kürzt. Darum kann ein Titel mit `=` beginnen, und eine Tabellenkalkulation kann das als Formel lesen. Eine Tabellenkalkulation kann beim Speichern auch das Trennzeichen, die Daten oder den Zeichensatz ändern. Öffne die Datei mit einem Texteditor.
