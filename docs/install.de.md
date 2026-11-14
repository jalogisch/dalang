# installation

[English version](install.md)

So betreibe ich dalang: nginx vorne, php-fpm dahinter, davor ein Reverse-Proxy, der TLS macht. Nur diesen Aufbau habe ich getestet. Mit Apache geht es vermutlich mit ein paar Rewrite-Regeln, aber ich liefere keine mit und habe es nicht probiert.

## was du brauchst

- PHP 8.1 oder neuer mit `pdo_sqlite` und Sitzungen. Ich teste nur mit PHP 8.5. Das offizielle Image `php:8.5-fpm-alpine3.24` hat alles dabei, es ist nichts nachzuinstallieren.
- nginx mit php-fpm.
- HTTPS. Das Sitzungs-Cookie ist `Secure`.

## 1. code holen

```sh
git clone https://github.com/jalogisch/dalang.git /srv/dalang
```

Der Webserver muss nur zwei Dateien ausführen: `public/index.php` und `public/admin/index.php`. Alles andere bleibt außerhalb des Web-Ordners. PHP braucht Leserecht auf den Code und Schreibrecht auf einen Ordner, den mit der Datenbank.

## 2. passwort und konfiguration

```sh
cd /srv/dalang
cp config.example.php config.php
php bin/hash-password.php
```

Das Skript fragt nach einem Passwort (12 Zeichen oder mehr) und gibt einen Hash aus. Kein PHP auf dem Rechner? Ein Container macht dasselbe:

```sh
podman run --rm -it -v "$PWD/bin":/dalang-bin:ro docker.io/library/php:8.5-cli-alpine php /dalang-bin/hash-password.php
```

Jetzt `config.php` anpassen:

| Schlüssel | Bedeutung |
|---|---|
| `base_url` | Die öffentliche Adresse des Kürzers, zum Beispiel `https://example.org`. Daraus entstehen die Kurzlinks im Admin und das Bookmarklet. |
| `fallback_url` | Freiwillig. Dorthin geht alles, was kein bekanntes Kürzel ist. Aus `https://example.org/about` wird `https://www.example.org/about`. Ich nutze das, damit die alten Adressen einer früheren Seite weiterleben. Lass den Wert leer oder lösch die Zeile, dann antwortet alles, was kein bekanntes Kürzel ist, mit `404`. |
| `password_hash` | Der Hash von oben. Einfache Anführungszeichen, weil er `$` enthält. |
| `database` | Pfad der SQLite-Datei. Der Ordner muss für PHP schreibbar sein: SQLite legt eine Journal-Datei daneben. Nicht in `public/` ablegen. |

`config.php` steht in `.gitignore`. Lass das so.

```sh
mkdir data
chown www-data data     # oder der Benutzer, unter dem dein php-fpm läuft
```

## 3. nginx

[deploy/nginx.conf](../deploy/nginx.conf) ist ein Beispiel, kein fertiger Aufbau. Nimm es und ändere drei Dinge: `listen`, den Pfad `/app` und `fastcgi_pass`.

Die Idee dahinter: nginx liefert keine Datei aus. Es gibt zwei feste Eingänge, und beide nennen ihr Skript direkt:

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

Lass die `if`-Zeile bitte drin. nginx entschlüsselt einen Pfad, bevor es den passenden Block wählt. Darum landet auch `/x%2F..%2Fadmin/` beim Admin. Ein Proxy vor nginx liest denselben Pfad vielleicht anders. Wenn du `/admin` auch in diesem Proxy schützt (ein zweites Passwort, ein Adressfilter), würde so ein Pfad daran vorbeirutschen. Mit der Prüfung der rohen Adresse antwortet der Admin unter genau `/admin/` und nirgends sonst.

## 4. erster besuch

Öffne `https://example.org/admin/` und melde dich an. Der erste Besuch legt die Datenbank an. Bis dahin antworten Kürzel mit 503. Das ist Absicht: Ohne Datenbank weiß ich nicht, ob es ein Kürzel gibt, und Browser merken sich eine falsche 301 sehr lange.

Dann einen Link anlegen und ausprobieren:

```sh
curl -sI https://example.org/dein-kuerzel
```

## gut zu wissen

- **Sitzung.** Eine Anmeldung hält 14 Tage, damit das Bookmarklet nicht jedes Mal nach dem Passwort fragt. „Abmelden“ beendet sie sofort. Sitzungen sind die normalen Dateien von PHP. Liegen sie im `/tmp` eines Containers, meldet dich ein Neustart ab. Das schadet nicht.
- **Logs.** Die Beispielkonfiguration hat `access_log off`, und dalang selbst speichert nichts über Besucher. Aber schau dir den Rest deiner Kette an: Das Fehler-Log von nginx, dein Reverse-Proxy und php-fpm können alle Logs schreiben. Das offizielle PHP-Image schreibt jede Anfrage mit Pfad und Zeit (nicht die Adresse des Besuchers) in die Ausgabe des Containers. Schalte das ab, wenn du es nicht willst.
- **Sicherung.** „Alle Links als CSV sichern“ im Admin ist die Sicherung, „CSV einlesen“ die Wiederherstellung. Es ist in beide Richtungen dasselbe Format, siehe [csv.de.md](csv.de.md). Du kannst auch einfach die SQLite-Datei kopieren.
- **301 bleibt hängen.** Browser merken sich eine 301. Wenn du einen Link löschst und das Kürzel für ein anderes Ziel neu vergibst, landen Leute, die das alte benutzt haben, vielleicht noch beim alten Ziel.
- **Update.** `git pull`. In 0.1.0 gibt es keinen Migrationsschritt. Versionen folgen Semantic Versioning, was sich geändert hat, steht in [CHANGELOG.md](../CHANGELOG.md) (Englisch).

## lokal ausprobieren

```sh
sh deploy/test.sh            # macht alle Tests und räumt auf
sh deploy/e2e.sh             # dasselbe in einem echten Browser (Playwright-Container)
KEEP=1 sh deploy/test.sh     # lässt es laufen auf http://localhost:8082/admin/
```

Das Test-Passwort steht in `deploy/test-config.php`. Mit `KEEP=1` bekommst du eine leere Datenbank zum Herumklicken. Stopp alles mit `podman compose -f deploy/compose.yml down`. Dieser Aufbau ist nur zum Testen da.
