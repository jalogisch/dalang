# installation

[English version](install.md)

Diese Seite beschreibt meinen eigenen Aufbau. Nur diesen Aufbau habe ich getestet:

- nginx (ein Webserver) nimmt die Anfragen an.
- php-fpm (der Prozessverwalter von PHP) führt hinter nginx den PHP-Code aus.
- Ein Reverse Proxy vor nginx kümmert sich um TLS (die Verschlüsselung für HTTPS).

Apache funktioniert vielleicht mit einigen Rewrite-Regeln. Solche Regeln liefere ich nicht mit, und ich habe Apache nicht getestet.

## was du brauchst

- PHP 8.1 oder neuer, mit `pdo_sqlite` und Sessions. Ich teste nur mit PHP 8.5. Das offizielle Container-Image `php:8.5-fpm-alpine3.24` enthält alles. Du musst nichts nachinstallieren.
- nginx mit php-fpm.
- HTTPS. Das Session-Cookie hat die Markierung `Secure`. Darum schickt der Browser es nur über HTTPS.

## 1. code herunterladen

```sh
git clone https://github.com/jalogisch/dalang.git /srv/dalang
```

Der Webserver führt nur zwei Dateien aus: `public/index.php` und `public/admin/index.php`. Alle anderen Dateien bleiben außerhalb des Web-Ordners. PHP braucht Leserechte auf den Code. Schreibrechte braucht PHP nur für einen Ordner: den Ordner mit der Datenbank.

## 2. passwort und konfiguration

```sh
cd /srv/dalang
cp config.example.php config.php
php bin/hash-password.php
```

Das Skript fragt nach einem Passwort mit mindestens 12 Zeichen. Dann gibt es einen Hash aus.

Du hast kein PHP auf dem Rechner? Ein Container macht dasselbe:

```sh
podman run --rm -it -v "$PWD/bin":/dalang-bin:ro docker.io/library/php:8.5-cli-alpine php /dalang-bin/hash-password.php
```

Jetzt bearbeitest du `config.php`:

| Schlüssel | Bedeutung |
|---|---|
| `base_url` | Die öffentliche Adresse deiner kurzen Domain, zum Beispiel `https://example.org`. dalang nutzt sie für die Kurzlinks auf der Admin-Seite und für das Bookmarklet. |
| `fallback_url` | Freiwillig. Jeder Pfad, der kein bekanntes Kürzel ist, leitet auf diese Adresse weiter. Beispiel: `https://example.org/about` leitet auf `https://www.example.org/about`. Ich nutze das, damit alte Adressen meiner früheren Seite weiter funktionieren. Ist der Wert leer oder fehlt die Zeile, bekommt jeder unbekannte Pfad den HTTP-Status 404 (nicht gefunden). |
| `password_hash` | Der Hash aus dem Skript oben. Nimm einfache Anführungszeichen, weil der Hash `$` enthält. |
| `database` | Der Pfad der SQLite-Datei. PHP muss in ihren Ordner schreiben dürfen, weil SQLite dort eine Journal-Datei anlegt. Leg die Datei nicht in `public/`. |

`config.php` steht in `.gitignore`. Entferne sie dort nicht.

```sh
mkdir data
chown www-data data     # nimm den Benutzer deines php-fpm
```

## 3. nginx

[deploy/nginx.conf](../deploy/nginx.conf) ist ein Beispiel. Es ist kein fertiger Aufbau. Kopier es und ändere drei Dinge: `listen`, den Pfad `/app` und `fastcgi_pass`.

Der Grundgedanke: nginx liefert keine Dateien aus. Es gibt zwei feste Eingänge, und jeder nennt sein Skript direkt:

```nginx
location ^~ /admin/ {
    if ($request_uri !~ "^/admin/(\?|$)") { return 404; }
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /app/public/admin/index.php;
    fastcgi_pass 127.0.0.1:9000;
}
location / {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /app/public/index.php;
    fastcgi_pass 127.0.0.1:9000;
}
```

Lass bitte die `if`-Zeile drin. Der Grund:

- nginx entschlüsselt einen Pfad, bevor es einen `location`-Block wählt. Darum erreicht auch ein Pfad wie `/x%2F..%2Fadmin/` die Admin-Seite.
- Ein Proxy vor nginx kann denselben Pfad anders lesen.
- Vielleicht schützt du `/admin` auch in diesem Proxy, zum Beispiel mit einem zweiten Passwort oder einem Adressfilter. So ein Pfad würde diesen Schutz dann umgehen.

Die `if`-Zeile prüft die rohe Adresse. Mit ihr antwortet die Admin-Seite nur unter genau `/admin/`. Die drei Ansichten der Admin-Seite nutzen dieselbe Adresse mit einer Abfrage, zum Beispiel `/admin/?view=links`.

## 4. erster besuch

Öffne `https://example.org/admin/` und melde dich an. Der erste Besuch legt die Datenbank an. Vorher bekommt jedes Kürzel den HTTP-Status 503 (Dienst nicht verfügbar). Das habe ich mit Absicht so gewählt: Ohne Datenbank kann dalang nicht wissen, ob es ein Kürzel gibt. Eine falsche 301 wäre ein Problem, weil Browser sich eine 301 sehr lange merken.

Leg dann einen Link an und teste ihn:

```sh
curl -sI https://example.org/dein-kuerzel
```

## wichtige einzelheiten

- **Session.** Eine Anmeldung gilt 14 Tage. So fragt das Bookmarklet nicht jedes Mal nach dem Passwort. „Abmelden“ beendet die Session sofort. PHP speichert Sessions als Dateien. Liegen diese Dateien im Ordner `/tmp` eines Containers, meldet dich ein Neustart des Containers ab. Das richtet keinen Schaden an.
- **Logs.** Die Beispiel-Konfiguration enthält `access_log off`. dalang selbst speichert nichts über Besucher. Prüf aber die anderen Teile deines Aufbaus: Das Fehler-Log von nginx, dein Reverse Proxy und php-fpm können alle Logs schreiben. Das offizielle PHP-Image schreibt jede Anfrage mit Pfad und Uhrzeit in die Ausgabe des Containers. Die IP-Adresse der Besucher schreibt es nicht. Schalte dieses Log ab, wenn du es nicht willst.
- **Sicherung.** Öffne auf der Admin-Seite die Ansicht „Werkzeuge“. „Alle Links als CSV sichern“ erzeugt die Sicherung. „CSV einlesen“ stellt sie wieder her. Beide nutzen dasselbe Format, siehe [csv.de.md](csv.de.md). Du kannst auch die SQLite-Datei kopieren.
- **Browser merken sich eine 301.** Vielleicht löschst du einen Link und nutzt sein Kürzel für ein anderes Ziel. Dann landen Menschen, die den alten Link schon geöffnet haben, vielleicht weiter beim alten Ziel.
- **Aktualisieren.** Starte `git pull`. Bis Version v0.2.0 braucht kein Update einen Umbau der Datenbank. Versionen folgen Semantic Versioning. [CHANGELOG.md](../CHANGELOG.md) nennt die Änderungen.

## lokal ausprobieren

```sh
sh deploy/test.sh            # startet alle Tests und löscht danach alles
sh deploy/e2e.sh             # dasselbe in einem echten Browser (Playwright-Container)
KEEP=1 sh deploy/test.sh     # lässt den Testserver auf http://localhost:8082/admin/ laufen
```

Das Test-Passwort steht in `deploy/test-config.php`. Mit `KEEP=1` bekommst du einen Testserver mit leerer Datenbank. Stopp ihn mit `podman compose -f deploy/compose.yml down`. Dieser Aufbau ist nur für Tests. Nutze ihn nicht auf einem öffentlichen Server.
