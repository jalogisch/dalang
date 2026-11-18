# umzug von YOURLS

[English version](migrate-from-yourls.md)

dalang kann deine YOURLS-Links aus einer CSV-Datei einlesen. Dein YOURLS muss dafür nicht laufen, du brauchst nur seine Datenbank. So war es bei mir: Die Anwendung war kaputt, die Daten waren in Ordnung.

## was mitkommt und was nicht

YOURLS hält seine Links in einer Tabelle, `yourls_url` (das Präfix `yourls_` kann anders sein, schau nach `YOURLS_DB_PREFIX` in deiner `config.php`). dalang nimmt vier Spalten daraus:

| YOURLS | dalang | Hinweis |
|---|---|---|
| `keyword` | Kürzel | Nur `a-z`, `0-9` und `-`, höchstens 40 Zeichen, nicht mit `admin` am Anfang. |
| `url` | Ziel | Nur `http://` und `https://`. |
| `title` | Titel | Nach 1000 Zeichen abgeschnitten. |
| `timestamp` | Angelegt | Wird übernommen, wie es ist. |
| `clicks`, `ip` | - | Wird nicht übernommen. dalang zählt nicht und speichert keine Adressen. |

Zeilen, die nicht passen, werden nicht eingelesen und nicht repariert. Das Einlesen sagt dir, wie viele es abgelehnt hat und in welchen Zeilen. So kannst du sie von Hand richten. Wenn dein YOURLS Kürzel mit Großbuchstaben benutzt (die Einstellung Basis 62), werden diese Zeilen abgelehnt: Kürzel in dalang sind immer klein.

## 1. CSV exportieren

Die Datei braucht die Spalten in dieser Reihenfolge: keyword, url, title, timestamp. Eine Kopfzeile ist in Ordnung, weitere Spalten nach der vierten werden nicht beachtet. Die Einzelheiten stehen in [csv.de.md](csv.de.md).

**Mit phpMyAdmin oder Adminer:** Führ diese Abfrage aus und exportier das Ergebnis als CSV (Komma als Trenner, Felder in doppelten Anführungszeichen).

```sql
SELECT keyword, url, COALESCE(title, '') AS title, timestamp
FROM yourls_url
ORDER BY timestamp;
```

**Auf der Kommandozeile:** Das Programm `mysql` schreibt Tabulatoren, kein CSV. Diese Abfrage baut die CSV-Zeilen selbst:

```sh
mysql -N -r -u BENUTZER -p DATENBANK -e "
SELECT CONCAT_WS(',',
  keyword,
  CONCAT('\"', REPLACE(url, '\"', '\"\"'), '\"'),
  CONCAT('\"', REPLACE(COALESCE(title, ''), '\"', '\"\"'), '\"'),
  timestamp)
FROM yourls_url ORDER BY timestamp" > links.csv
```

Ehrlicher Hinweis: Das Einlesen in dalang ist getestet. Diese Kommandozeile habe ich nicht gegen eine laufende YOURLS-Datenbank ausgeführt. Prüf das Ergebnis: `wc -l links.csv` sollte die Zahl deiner Links ergeben.

In der Datei stehen alle deine Links. Leg sie nicht in ein Git-Repo.

## 2. einlesen

1. Melde dich unter `/admin/` an.
2. Öffne „Werkzeuge“. Wähle unter „Sichern und einlesen“ die Datei und klick „CSV einlesen“.
3. Lies die Meldung: `Import fertig: 250 neu, 0 schon vorhanden, 0 abgelehnt.`

Kürzel, die es in dalang schon gibt, bleiben, wie sie sind. Du kannst also zweimal einlesen, ohne dass etwas passiert. Der zweite Lauf meldet alles als „schon vorhanden“.

Die nginx-Beispielkonfiguration nimmt Dateien bis 2 MB an. Ist deine Datei größer, erhöh `client_max_body_size` (und `upload_max_filesize` in PHP) oder teil die Datei.

## 3. prüfen

Probier ein paar deiner alten Kurzlinks:

```sh
curl -sI https://example.org/dein-altes-kuerzel
```

Du willst `301` sehen und dein Ziel in `Location`. Dann klick einmal „Alle Links als CSV sichern“ und leg die Datei sicher ab. Jetzt hast du eine Sicherung im eigenen Format von dalang.

## die alte domain

Bleibt die kurze Domain dieselbe, gehen deine alten Links wieder, sobald sie auf dalang zeigt. Tote Ziele werden eingelesen wie alle anderen. dalang prüft nicht, ob ein Ziel noch lebt.
