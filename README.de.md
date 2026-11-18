# dalang

[English version](README.md)

dalang ist ein kleiner Link-Kürzer für eine Person. Ein Kurzlink wie `https://example.org/abc` schickt den Browser zu einer langen Adresse. Ich lege Links auf einer Admin-Seite mit Passwort an und lösche sie dort.

dalang nutzt PHP (eine Programmiersprache für Webserver) und SQLite (eine Datenbank in einer einzigen Datei). Es hat rund 600 Zeilen PHP-Code. Es braucht kein Framework, kein Composer (den Paketverwalter von PHP) und keinen Build-Schritt.

## warum

Mein YOURLS lief nicht mehr. YOURLS ist ein größerer Link-Kürzer. Ich habe aufgeschrieben, was ich davon wirklich benutzt habe. Die Liste war kurz:

- Weiterleitungen
- ein Formular zum Anlegen
- eine Liste aller Links
- ein Weg, meine alten Links einzulesen

YOURLS hatte viel mehr Code. Diesen Code musste ich pflegen, obwohl ich ihn nicht benutzt habe. Darum kann dalang nur diese wenigen Dinge. Das habe ich mit Absicht so gewählt: Einfache Software ist leichter zu pflegen.

## was dalang kann

- **Weiterleitung.** Ein Browser, der einen Kurzlink öffnet, bekommt eine Weiterleitung zum Ziel. dalang nutzt dafür den HTTP-Status 301 (dauerhaft verschoben).
- **Rückfall-Adresse.** Du kannst eine Rückfall-Adresse einstellen. Jeder unbekannte Pfad leitet dann dorthin weiter, mit Pfad und Abfrage. Beispiel: `https://example.org/about` leitet auf `https://www.example.org/about`. So funktionieren alte Adressen deiner Hauptseite weiter. Ohne Rückfall-Adresse bekommt ein unbekannter Pfad den HTTP-Status 404 (nicht gefunden).
- **Admin-Seite.** Die Admin-Seite liegt unter `/admin/` und hat eine eigene Anmeldung. Sie hat drei Ansichten:
  - **Neuer Link:** das Formular. Du gibst das Ziel ein, dazu wenn du willst ein eigenes Kürzel und einen Titel. Ohne Kürzel erzeugt dalang 5 zufällige Zeichen.
  - **Alle Links:** eine Tabelle mit allen Links, die neuesten zuerst, mit einem Knopf „Löschen“ bei jedem Link.
  - **Werkzeuge:** Sichern, Einlesen und das Bookmarklet.
- **Sichern und Einlesen.** Beim Sichern speichert dalang alle Links als CSV-Datei (Werte mit Komma getrennt). Diese Datei ist deine Sicherung. Das Einlesen liest dasselbe Format. Es liest auch eine Liste aus YOURLS. Das Format steht in [docs/csv.de.md](docs/csv.de.md).
- **Bookmarklet.** Ein Bookmarklet ist ein Lesezeichen mit einem kleinen JavaScript-Programm. Damit musst du die Admin-Seite nicht erst öffnen.
- **Sprachen.** Die Admin-Seite gibt es auf Englisch und Deutsch. dalang nimmt die Sprache deines Browsers.
- **Farben.** Die Admin-Seite folgt der hellen oder dunklen Einstellung deines Systems.

## was dalang nicht kann

Diese Funktionen habe ich mit Absicht weggelassen:

- **Keine Besucherdaten.** dalang hat keinen Klickzähler und keine Statistik. Der öffentliche Teil schreibt nie etwas.
- **Kein Bearbeiten.** Um einen Link zu ändern, löschst du ihn. Dann legst du ihn mit demselben Kürzel neu an.
- **Keine Benutzer, keine API, keine QR-Codes, keine Suche.** Die Liste ist eine Tabelle. Nutze die Suche deines Browsers.

Brauchst du diese Funktionen? Dann sind YOURLS, Shlink oder Kutt besser für dich.

Ich habe dalang nicht allein geschrieben. Ein LLM (ein großes Sprachmodell, hier Claude) hat mir beim Bauen geholfen. [CLAUDE.md](CLAUDE.md) enthält die Regeln für das LLM (auf Englisch). Die Tests entscheiden, wann eine Änderung fertig ist.

## schnellstart

Du brauchst:

- PHP 8.1 oder neuer, mit `pdo_sqlite`. Ich teste nur mit PHP 8.5.
- nginx (einen Webserver) mit php-fpm (dem Prozessverwalter von PHP).
- HTTPS. Das Anmelde-Cookie hat die Markierung `Secure`. Ohne HTTPS schickt der Browser es nicht, und du kannst dich nicht anmelden.

```sh
git clone https://github.com/jalogisch/dalang.git
cd dalang
cp config.example.php config.php
php bin/hash-password.php        # fragt nach einem Passwort und gibt den Hash aus
mkdir data                       # der PHP-Benutzer muss hier schreiben dürfen
```

Trag den Hash und deine Adressen in `config.php` ein:

```php
return [
    'base_url' => 'https://example.org',          // deine kurze Domain
    'fallback_url' => 'https://www.example.org',  // Ziel für unbekannte Pfade; ohne diese Zeile bekommen sie 404
    'password_hash' => '$2y$12$...',              // aus bin/hash-password.php, in einfachen Anführungszeichen
    'database' => __DIR__ . '/data/links.sqlite',
];
```

Dann richtest du nginx ein. Nimm das Beispiel [deploy/nginx.conf](deploy/nginx.conf) als Anfang. Öffne `https://example.org/admin/` im Browser. Alle Einzelheiten und Warnungen stehen in [docs/install.de.md](docs/install.de.md).

Ziehst du von YOURLS um? Lies [docs/migrate-from-yourls.de.md](docs/migrate-from-yourls.de.md).

## bookmarklet

Öffne auf der Admin-Seite die Ansicht „Werkzeuge“. Dort findest du einen Knopf „Mit dalang kürzen“. Zieh diesen Knopf in die Lesezeichenleiste deines Browsers.

Öffne dann eine beliebige Webseite und klick auf das Lesezeichen. dalang öffnet das Formular in einem neuen Tab. Adresse und Titel der Seite stehen schon im Formular. Du gibst ein Kürzel ein oder lässt das Feld leer. Dann klickst du „Anlegen“. dalang zeigt den neuen Kurzlink in großer Schrift, mit einem Knopf „Kopieren“.

Zwei Hinweise:

- Das Bookmarklet öffnet nur das Formular. dalang legt den Link erst an, wenn du „Anlegen“ klickst. Das ist eine Sicherheitsregel. Könnte ein einfacher Link Kurzlinks anlegen, dann könnte jede Webseite in deinem Namen Links anlegen.
- Das Bookmarklet führt JavaScript auf der Seite aus, die du gerade ansiehst. Manche Seiten verbieten das mit einer strengen Content Security Policy (CSP). Auf diesen Seiten kann der Browser das Bookmarklet blockieren. Das kann ich in dalang nicht ändern. Kopier auf diesen Seiten die Adresse und öffne `/admin/` selbst.

## sicherheit kurz

- dalang speichert nur einen Hash des Passworts (mit `password_hash` von PHP), nie das Passwort selbst.
- Jede Änderung braucht eine POST-Anfrage mit einem CSRF-Token. CSRF (Cross-Site Request Forgery) ist ein Angriff, bei dem eine fremde Webseite Anfragen in deinem Namen schickt. Das Token verhindert das. Eine GET-Anfrage ändert nie Daten.
- dalang prüft höchstens einen Anmeldeversuch alle 5 Sekunden, für alle Benutzer zusammen. Das macht Raten langsam. dalang speichert dafür keine IP-Adressen.
- Ein Ziel muss mit `http://` oder `https://` beginnen. dalang lehnt `javascript:` und `data:` ab.
- nginx liefert keine Dateien aus. Es gibt nur zwei Eingänge: die Weiterleitung und die Admin-Seite. Niemand kann die Konfiguration oder die Datenbank herunterladen.

[SECURITY.md](SECURITY.md) (auf Englisch) hat mehr Einzelheiten und die Grenzen, die ich kenne.

## tests

```sh
sh deploy/test.sh
```

Du brauchst Podman (oder Docker mit `ENGINE=docker`), curl und git. PHP auf deinem Rechner brauchst du nicht. Das Skript startet nginx und php-fpm auf `127.0.0.1:8082`. Dann macht es rund 170 Prüfungen mit curl. Am Ende löscht es die Container und die Testdaten.

```sh
sh deploy/e2e.sh
```

Dieses Skript macht rund 35 Prüfungen in einem echten Browser (End-to-End-Test). Es nutzt Chromium in einem Playwright-Container. Der Browser meldet sich an und legt Links über das Formular an. Er prüft drei Fälle: mit eigenem Kürzel, mit zufälligem Kürzel und mit einem Formular, das das Bookmarklet ausgefüllt hat. Dann öffnet er jeden Kurzlink und prüft das Ziel. Am Ende löscht das Skript alles. Das Playwright-Image ist groß (rund 2,5 GB). Jeder Lauf lädt das npm-Paket herunter, darum braucht der Test Internet.

## mitmachen und veröffentlichen

**Ein Commit enthält eine Änderung.** Jeder Commit muss für sich lesbar und leicht rückgängig zu machen sein.

**Die Betreffzeile folgt [Conventional Commits](https://www.conventionalcommits.org/).** Sie beginnt mit einem Typ: `feat:`, `fix:`, `docs:`, `test:`, `chore:` oder `refactor:`. Danach folgt ein kurzer Satz auf Englisch, im Imperativ („add“, nicht „added“). Eine Änderung, die etwas Bestehendes bricht, hat ein `!` nach dem Typ (`feat!:`) oder eine Zeile `BREAKING CHANGE:` im Text.

**Die Betreffzeilen sind das Changelog.** Darum bin ich hier streng. `bin/changelog.sh` erzeugt [CHANGELOG.md](CHANGELOG.md) aus `git log`:

- eine Zeile für jeden Commit
- einen Abschnitt für jeden Versions-Tag, der neueste zuerst
- einen Abschnitt „Unreleased“ ganz oben

Eine GitHub Action (`.github/workflows/changelog.yml`) startet das Skript nach jedem Push auf `main`. Wenn sich die Datei geändert hat, committet die Action sie. Ändere CHANGELOG.md darum nicht selbst. Der nächste Lauf würde deine Änderung löschen.

**Versionen folgen [Semantic Versioning](https://semver.org/lang/de/).** Die Tags haben die Form `vMAJOR.MINOR.PATCH`, zum Beispiel `v0.2.0`.

- Ein `fix` erhöht die PATCH-Nummer.
- Ein `feat` erhöht die MINOR-Nummer.
- Eine Änderung, die etwas bricht, erhöht die MAJOR-Nummer. Vor Version 1.0.0 erhöht sie nur die MINOR-Nummer.

Die Version steht nur im Git-Tag. Der Code enthält sie nicht.

So geht eine Veröffentlichung. Ersetze `vX.Y.Z` durch die neue Version:

```sh
sh deploy/test.sh && sh deploy/e2e.sh     # beide müssen mit ALL OK enden
sh bin/changelog.sh vX.Y.Z                # schiebt die offenen Commits unter "vX.Y.Z - <heute>"
git commit -m 'chore: update changelog' CHANGELOG.md
git tag vX.Y.Z
git push origin main vX.Y.Z
```

## der name

Der Name hat zwei Bedeutungen.

1. Im Deutschen sagt man „da lang“, wenn man jemandem die richtige Richtung zeigt.
2. Im Indonesischen und Javanischen ist ein Dalang der Puppenspieler im Wayang, dem Schattentheater. Der Dalang sitzt hinter dem Schirm und bewegt die Figuren.

Beide Bedeutungen passen zu dalang: Es arbeitet im Hintergrund und schickt Menschen an die richtige Stelle.

## lizenz

MIT, siehe [LICENSE](LICENSE).
