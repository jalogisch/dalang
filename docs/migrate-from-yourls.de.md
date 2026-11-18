# umzug von YOURLS

[English version](migrate-from-yourls.md)

dalang kann deine YOURLS-Links aus einer CSV-Datei (Werte mit Komma getrennt) einlesen. YOURLS muss dafür nicht laufen. Du brauchst nur seine Datenbank. So war es bei mir: Die Anwendung YOURLS lief nicht mehr, aber die Daten waren vollständig.

## was dalang übernimmt und was nicht

YOURLS speichert seine Links in einer Tabelle: `yourls_url`. Deine Tabelle kann ein anderes Präfix als `yourls_` haben. Such nach `YOURLS_DB_PREFIX` in der `config.php` von YOURLS. dalang übernimmt vier Spalten aus dieser Tabelle:

| YOURLS | dalang | Hinweis |
|---|---|---|
| `keyword` | Kürzel | Nur `a-z`, `0-9` und `-`, höchstens 40 Zeichen. Es darf nicht mit `admin` beginnen. |
| `url` | Ziel | Nur `http://` und `https://`. |
| `title` | Titel | dalang kürzt ihn nach 1000 Zeichen. |
| `timestamp` | Angelegt | dalang übernimmt den Wert unverändert. |
| `clicks`, `ip` | - | dalang übernimmt sie nicht. dalang zählt keine Klicks und speichert keine IP-Adressen. |

Zeilen, die diese Regeln brechen, liest das Einlesen nicht ein und repariert sie nicht. Die Meldung sagt dir, wie viele Zeilen es abgelehnt hat, und nennt die Zeilennummern. Diese Zeilen kannst du dann selbst korrigieren.

YOURLS hat eine Einstellung für Kürzel mit Großbuchstaben (Basis 62). Mit dieser Einstellung können Kürzel Großbuchstaben enthalten. dalang lehnt diese Zeilen ab, weil Kürzel in dalang nur Kleinbuchstaben haben.

## 1. CSV-Datei aus YOURLS exportieren

Die Datei braucht die Spalten in dieser Reihenfolge: keyword, url, title, timestamp. Eine Kopfzeile ist erlaubt. Das Einlesen ignoriert alle Spalten nach der vierten. Die Einzelheiten stehen in [csv.de.md](csv.de.md).

**Mit phpMyAdmin oder Adminer** (Web-Werkzeuge für MySQL-Datenbanken): Führ diese Abfrage aus. Exportier dann das Ergebnis als CSV, mit Komma als Trennzeichen und Feldern in doppelten Anführungszeichen.

```sql
SELECT keyword, url, COALESCE(title, '') AS title, timestamp
FROM yourls_url
ORDER BY timestamp;
```

**Auf der Kommandozeile:** Das Programm `mysql` schreibt Tabulatoren, kein CSV. Diese Abfrage erzeugt die CSV-Zeilen selbst:

```sh
mysql -N -r -u BENUTZER -p DATENBANK -e "
SELECT CONCAT_WS(',',
  keyword,
  CONCAT('\"', REPLACE(url, '\"', '\"\"'), '\"'),
  CONCAT('\"', REPLACE(COALESCE(title, ''), '\"', '\"\"'), '\"'),
  timestamp)
FROM yourls_url ORDER BY timestamp" > links.csv
```

Bitte beachte: Die Tests prüfen das Einlesen in dalang. Diese Kommandozeile habe ich aber nicht mit einer echten YOURLS-Datenbank ausgeführt. Prüf das Ergebnis: `wc -l links.csv` muss die Zahl deiner Links zeigen.

Die Datei enthält alle deine Links. Leg sie nicht in ein Git-Repository.

## 2. datei einlesen

1. Melde dich unter `/admin/` an.
2. Öffne die Ansicht „Werkzeuge“. Wähle unter „Sichern und einlesen“ die Datei und klick „CSV einlesen“.
3. Lies die Meldung, zum Beispiel: `Import fertig: 250 neu, 0 schon vorhanden, 0 abgelehnt.`

Das Einlesen ändert keine Kürzel, die es in dalang schon gibt. Darum kannst du zweimal einlesen, ohne Schaden. Der zweite Lauf meldet alle Links als „schon vorhanden“.

Die Beispiel-Konfiguration für nginx nimmt Uploads bis 2 MB an. Ist deine Datei größer? Dann erhöhe `client_max_body_size` in nginx und `upload_max_filesize` in PHP. Oder teil die Datei in kleinere Dateien.

## 3. ergebnis prüfen

Teste einige deiner alten Kurzlinks:

```sh
curl -sI https://example.org/dein-altes-kuerzel
```

Die Antwort muss `301` zeigen, und dein Ziel im Header `Location`. Klick dann einmal „Alle Links als CSV sichern“. Bewahre die Datei an einem sicheren Ort auf. Jetzt hast du eine Sicherung im Format von dalang.

## die alte domain

Behältst du dieselbe kurze Domain? Dann funktionieren deine alten Links wieder, sobald die Domain auf dalang zeigt. Das Einlesen übernimmt auch Links mit Zielen, die es nicht mehr gibt. dalang prüft nicht, ob ein Ziel noch existiert.
