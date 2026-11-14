# das CSV-Format

[English version](csv.md)

„Alle Links als CSV sichern“ im Admin schreibt dieses Format, „CSV einlesen“ liest es. Es ist die Sicherung von dalang und der Weg hinein für Links von woanders.

```csv
code,url,title,created
abc,https://example.org/eine/lange/adresse,,2026-01-02 03:04:05
mein-vortrag,https://example.org/vortrag?jahr=2026,"Ein Titel mit, Komma und ""Anführungszeichen""","2026-02-03 04:05:06"
```

## die spalten

Vier Spalten, immer in dieser Reihenfolge:

| # | Name | Inhalt |
|---|---|---|
| 1 | `code` | Das Kürzel: `a-z`, `0-9` und `-`, 1 bis 40 Zeichen, nicht mit `admin` am Anfang. |
| 2 | `url` | Das Ziel: `http://` oder `https://` mit einem Host, höchstens 2000 Zeichen. |
| 3 | `title` | Freier Text, darf leer sein. |
| 4 | `created` | Datum und Uhrzeit in UTC, geschrieben als `JJJJ-MM-TT HH:MM:SS`. |

- **Kopfzeile:** Die erste Zeile einer Sicherung ist immer `code,url,title,created`, auf Englisch, egal welche Sprache der Admin spricht.
- **Kodierung:** UTF-8, ohne Byte Order Mark.
- **Trennzeichen:** Komma.
- **Anführungszeichen:** Ein Feld steht in doppelten Anführungszeichen, wenn es sie braucht: wenn es ein Komma, ein doppeltes Anführungszeichen, ein Leerzeichen oder einen Zeilenumbruch enthält. Ein doppeltes Anführungszeichen im Feld wird zweimal geschrieben (`""`). Darum steht das Datum in einer Sicherung in Anführungszeichen, es enthält ein Leerzeichen. Es gibt kein Maskieren mit Backslash.
- **Zeilenende:** ein Zeilenvorschub (`\n`).
- **Reihenfolge:** der älteste Link zuerst.

## was das einlesen macht

Das Einlesen geht nach der Stelle, nicht nach dem Namen. Spalte 1 ist das Kürzel, Spalte 2 das Ziel und so weiter. Die Namen in der Kopfzeile sind egal. Darum geht auch eine Liste aus YOURLS mit `keyword,url,title,timestamp` (siehe [migrate-from-yourls.de.md](migrate-from-yourls.de.md)).

- **Kopfzeile:** Namen werden nicht geprüft. Die Regel ist einfacher: Ist das zweite Feld der ersten Zeile kein gültiges Ziel, wird die erste Zeile übersprungen. So geht eine Datei mit Kopfzeile genauso wie eine ohne. Die Kehrseite: Eine erste Zeile, die ein Link sein soll, aber ein kaputtes Ziel hat, wird auch still übersprungen und nicht als abgelehnt gezählt.
- **Leere Zeilen** werden übersprungen.
- **Weitere Spalten** nach der vierten werden nicht beachtet. Weniger gehen auch: Ein fehlender Titel ist leer, ein fehlendes Datum ist „jetzt“.
- **Kürzel und Ziel** werden geprüft wie im Formular. Eine Zeile, die nicht besteht, wird abgelehnt und nicht repariert. Die Meldung nennt die Nummern der abgelehnten Zeilen (die ersten 20), gezählt vom Anfang der Datei, die Kopfzeile zählt mit.
- **Titel:** Zeilenumbrüche und mehrere Leerzeichen werden zu einem Leerzeichen, nach 1000 Zeichen wird abgeschnitten. Ein Titel, der kein gültiges UTF-8 ist, wird leer.
- **Datum:** Sieht es nicht aus wie `JJJJ-MM-TT HH:MM:SS`, bekommt der Link die Zeit des Einlesens.
- **Kürzel, die es schon gibt, bleiben, wie sie sind.** Das Einlesen überschreibt nie. Sie werden als „schon vorhanden“ gezählt. Du kannst dieselbe Datei also zweimal einlesen, ohne dass etwas passiert.
- Beim Lesen sind die Anführungszeichen freiwillig: `"2026-02-03 04:05:06"` und `2026-02-03 04:05:06` sind dasselbe.

Eine Datei, die dalang gesichert hat, ergibt nach dem Einlesen in ein leeres dalang wieder genau dieselbe Datei. Der Test prüft das.

Die nginx-Beispielkonfiguration nimmt Dateien bis 2 MB an.

## eine warnung

Die CSV ist eine Sicherung, keine Tabelle. Titel kommen von den Seiten, die du kürzt. Ein Titel kann also mit `=` anfangen, und eine Tabellenkalkulation liest das vielleicht als Formel. Und wenn so ein Programm die Datei wieder speichert, ändert es vielleicht das Trennzeichen, das Datum oder die Kodierung. Nimm einen Texteditor.
