# dalang

[English version](README.md)

dalang ist ein kleiner Link-Kürzer für eine Person. `https://example.org/abc` führt zur langen Adresse, und über ein Formular hinter einem Passwort lege ich Links an und lösche sie. PHP und SQLite, rund 600 Zeilen, kein Framework, kein Composer, kein Bauen.

## warum

Mein YOURLS ging kaputt. Als ich nachsah, was ich davon wirklich brauche, war die Liste kurz: Weiterleitungen, ein Formular, eine Liste und ein Weg, die alten Links wieder hineinzubekommen. Der Rest war Code, den ich am Leben halten musste, ohne ihn zu benutzen.

Darum kann dalang wenig, und genau das ist der Sinn. Einfach ist nicht schlecht, sondern vielleicht genau richtig.

Was es kann:

- Ein Kürzel leitet mit 301 auf sein Ziel.
- Alles andere leitet auf eine Rückfall-Adresse, wenn du eine einstellst, mit Pfad und Abfrage. So kann die kurze Domain neben deiner richtigen Seite stehen, und alte Adressen leben dort weiter. Ohne Rückfall-Adresse antwortet alles andere mit 404.
- Admin unter `/admin/` mit eigener Anmeldung: anlegen (mit eigenem Kürzel oder einem zufälligen), Liste, löschen.
- CSV sichern und CSV einlesen. Das Einlesen nimmt auch eine Liste aus YOURLS. Das Format steht in [docs/csv.de.md](docs/csv.de.md).
- Ein Bookmarklet, damit ich nicht erst die Admin-Seite öffnen muss.
- Der Admin spricht Englisch und Deutsch, je nach Sprache deines Browsers.

Was es mit Absicht nicht kann:

- Kein Klickzähler, keine Statistik, gar keine Besucherdaten. Der öffentliche Teil schreibt nie etwas.
- Kein Bearbeiten. Lösch den Link und leg ihn mit demselben Kürzel neu an.
- Keine Benutzer, keine API, keine QR-Codes, keine Suche. Die Liste ist eine Tabelle, dein Browser kann darin suchen.

Wenn du das brauchst, sind YOURLS, Shlink oder Kutt die bessere Wahl. Das ist in Ordnung.

Ich habe es nicht allein geschrieben. Beim Bauen hat ein LLM geholfen (Claude). Die Regeln, an die es sich halten muss, stehen in [CLAUDE.md](CLAUDE.md) (Englisch), und was fertig ist, entscheiden die Tests.

## schnellstart

Du brauchst PHP 8.1 oder neuer mit `pdo_sqlite` (ich teste nur mit PHP 8.5), nginx mit php-fpm und HTTPS. Das Anmelde-Cookie ist als `Secure` markiert, ohne HTTPS kannst du dich nicht anmelden.

```sh
git clone https://github.com/jalogisch/dalang.git
cd dalang
cp config.example.php config.php
php bin/hash-password.php        # fragt nach einem Passwort, gibt den Hash aus
mkdir data                       # muss für den PHP-Benutzer schreibbar sein
```

Trag den Hash und deine Adressen in `config.php` ein:

```php
return [
    'base_url' => 'https://example.org',          // deine kurze Domain
    'fallback_url' => 'https://www.example.org',  // dorthin gehen unbekannte Pfade; ohne die Zeile antworten sie mit 404
    'password_hash' => '$2y$12$...',              // aus bin/hash-password.php, in einfachen Anführungszeichen
    'database' => __DIR__ . '/data/links.sqlite',
];
```

Dann richtest du nginx darauf. [deploy/nginx.conf](deploy/nginx.conf) ist ein Beispiel für den Anfang. Öffne `https://example.org/admin/`. Die Einzelheiten und worauf du achten musst, stehen in [docs/install.de.md](docs/install.de.md).

Du kommst von YOURLS? Siehe [docs/migrate-from-yourls.de.md](docs/migrate-from-yourls.de.md).

## bookmarklet

Auf der Admin-Seite gibt es einen Link „Mit dalang kürzen“. Zieh ihn in deine Lesezeichenleiste. Ein Klick darauf, auf irgendeiner Seite, öffnet das dalang-Formular in einem neuen Tab, Adresse und Titel sind schon eingetragen. Du wählst ein Kürzel oder lässt das Feld leer, klickst „Anlegen“ und bekommst den Kurzlink groß angezeigt, mit einem Knopf „Kopieren“.

Zwei ehrliche Hinweise:

- Das Bookmarklet öffnet nur das Formular. Angelegt wird erst, wenn du „Anlegen“ klickst. Könnte ein einfacher Link Kurzlinks anlegen, dann könnte das jede Webseite, die du besuchst, für dich tun.
- Ein Bookmarklet ist ein Stück JavaScript, das auf der Seite läuft, die du gerade ansiehst. Manche Seiten verbieten das mit einer strengen Content Security Policy, und dann kann der Browser es blockieren. Das kann ich von meiner Seite nicht ändern. Auf solchen Seiten: Adresse kopieren und `/admin/` von Hand öffnen.

## sicherheit, kurz

- Das Passwort liegt als Hash vor (`password_hash`), nie im Klartext.
- Jede Änderung ist ein POST mit CSRF-Token. Ein GET ändert nichts.
- Nur ein Anmeldeversuch alle 5 Sekunden wird geprüft, für alle zusammen. Das bremst das Raten, ohne eine Adresse zu speichern.
- Ziele müssen mit `http://` oder `https://` beginnen. Kein `javascript:`, kein `data:`.
- nginx liefert gar keine Datei aus. Es gibt zwei Eingänge, die Weiterleitung und den Admin. Konfiguration und Datenbank sind nicht abrufbar.

Mehr dazu und die Grenzen, die ich kenne, in [SECURITY.md](SECURITY.md) (Englisch).

## tests

```sh
sh deploy/test.sh
```

Das braucht Podman (oder `ENGINE=docker`), curl und git, kein PHP auf deinem Rechner. Es startet nginx und php-fpm auf `127.0.0.1:8082`, macht rund 160 Prüfungen mit curl und räumt wieder auf.

```sh
sh deploy/e2e.sh
```

Das ist dasselbe in einem echten Browser. Ein Chromium in einem Playwright-Container meldet sich an, legt über das Formular Links an (mit eigenem Kürzel, mit einem zufälligen und vorausgefüllt wie vom Bookmarklet), liest den Kurzlink von der Seite und folgt ihm bis zum Ziel. Rund 30 Prüfungen, danach wird alles gestoppt und gelöscht. Das Playwright-Image ist groß (rund 2,5 GB), und jeder Lauf holt das npm-Paket. Es braucht also Internet.

## mitmachen und veröffentlichen

Ein Commit ist eine Änderung: eine Sache, für sich lesbar, leicht zurückzunehmen. Die Betreffzeile folgt [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `chore:` oder `refactor:`, dann ein kurzer Satz auf Englisch, im Imperativ. Ein `!` nach dem Typ (`feat!:`) oder eine Zeile `BREAKING CHANGE:` im Text markiert einen Bruch.

So streng bin ich, weil die Betreffzeilen das Changelog sind. `bin/changelog.sh` schreibt [CHANGELOG.md](CHANGELOG.md) neu aus `git log`: eine Zeile je Commit, ein Abschnitt je Versions-Tag, der neueste oben, und darüber „Unreleased“. Eine GitHub Action (`.github/workflows/changelog.yml`) startet das Skript nach jedem Push auf `main` und committet die Datei, wenn sie sich geändert hat. Ändere CHANGELOG.md also nicht von Hand, der nächste Lauf wirft deine Änderung weg.

Versionen folgen [Semantic Versioning](https://semver.org/lang/de/), die Tags heißen `vMAJOR.MINOR.PATCH`. Ein `fix` erhöht die Patch-Nummer, ein `feat` die Minor-Nummer, ein Bruch die Major-Nummer. Vor 1.0.0 erhöht ein Bruch nur die Minor-Nummer. Die Version steht im Git-Tag und sonst nirgends.

Eine Veröffentlichung geht so:

```sh
sh deploy/test.sh && sh deploy/e2e.sh     # beide müssen mit ALL OK enden
sh bin/changelog.sh v0.2.0                # die offenen Commits stehen jetzt unter "v0.2.0 - <heute>"
git commit -m 'chore: update changelog' CHANGELOG.md
git tag v0.2.0
git push origin main v0.2.0
```

## der name

„Da lang“ sagt man im Deutschen, wenn man jemandem die richtige Straße zeigt. Und im Indonesischen und Javanischen ist ein Dalang der Puppenspieler im Wayang, dem Schattentheater: der hinter dem Schirm, der die Figuren bewegt. Beides passt zu einem Ding, das im Hintergrund steht und Leute weiterschickt.

## lizenz

MIT, siehe [LICENSE](LICENSE).
