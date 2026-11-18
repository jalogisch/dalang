# install

[Deutsche Fassung](install.de.md)

This page describes my own setup. It is the only setup that I have tested:

- nginx (a web server) receives the requests.
- php-fpm (the PHP process manager) runs the PHP code behind nginx.
- A reverse proxy in front of nginx handles TLS (the encryption for HTTPS).

Apache may work with some rewrite rules. I do not ship such rules, and I have not tested Apache.

## what you need

- PHP 8.1 or newer, with `pdo_sqlite` and sessions. I test with PHP 8.5 only. The official container image `php:8.5-fpm-alpine3.24` contains everything. You do not need to install anything.
- nginx with php-fpm.
- HTTPS. The session cookie has the `Secure` flag, so the browser sends it only over HTTPS.

## 1. download the code

```sh
git clone https://github.com/jalogisch/dalang.git /srv/dalang
```

The web server runs only two files: `public/index.php` and `public/admin/index.php`. All other files stay outside the web root. PHP needs read access to the code. PHP needs write access to one folder only: the folder with the database.

## 2. password and configuration

```sh
cd /srv/dalang
cp config.example.php config.php
php bin/hash-password.php
```

The script asks for a password with at least 12 characters. Then it prints a hash.

You have no PHP on the machine? A container does the same:

```sh
podman run --rm -it -v "$PWD/bin":/dalang-bin:ro docker.io/library/php:8.5-cli-alpine php /dalang-bin/hash-password.php
```

Now edit `config.php`:

| Key | Meaning |
|---|---|
| `base_url` | The public address of your short domain, for example `https://example.org`. dalang uses it for the short links on the admin page and for the bookmarklet. |
| `fallback_url` | Optional. Every path that is not a known code redirects to this address. Example: `https://example.org/about` redirects to `https://www.example.org/about`. I use this so that old addresses of my earlier site still work. If the value is empty or the line is missing, every unknown path gets HTTP status 404 (not found). |
| `password_hash` | The hash from the script above. Use single quotes, because the hash contains `$`. |
| `database` | The path of the SQLite file. PHP must be able to write to its folder, because SQLite creates a journal file next to the database. Do not put the file into `public/`. |

`config.php` is listed in `.gitignore`. Do not remove it from there.

```sh
mkdir data
chown www-data data     # use the user of your php-fpm
```

## 3. nginx

[deploy/nginx.conf](../deploy/nginx.conf) is an example. It is not a finished setup. Copy it and change three things: `listen`, the path `/app` and `fastcgi_pass`.

The main idea: nginx delivers no files. There are two fixed entry points, and each one names its script directly:

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

Please keep the `if` line. Here is why:

- nginx decodes a path before it chooses a `location` block. So a path like `/x%2F..%2Fadmin/` also reaches the admin page.
- A proxy in front of nginx can read the same path in a different way.
- Maybe you protect `/admin` in that proxy too, for example with a second password or an address filter. Such a path would then avoid this protection.

The `if` line checks the raw address. With it, the admin page answers only at exactly `/admin/`. The three views of the admin page use the same address with a query, for example `/admin/?view=links`.

## 4. first visit

Open `https://example.org/admin/` and log in. The first visit creates the database. Before that, every short code gets HTTP status 503 (service unavailable). I chose this on purpose: without a database, dalang cannot know if a code exists. A wrong 301 would be a problem, because browsers remember a 301 for a long time.

Then create a link and test it:

```sh
curl -sI https://example.org/your-code
```

## important details

- **Session.** A login lasts 14 days. So the bookmarklet does not ask for the password every time. "Log out" ends the session immediately. PHP stores sessions as files. If these files are in the `/tmp` folder of a container, a restart of the container logs you out. This causes no damage.
- **Logs.** The example configuration contains `access_log off`. dalang itself stores nothing about visitors. But check the other parts of your setup: the nginx error log, your reverse proxy and php-fpm can all write logs. The official PHP image logs every request with path and time to the container output. It does not log the visitor's IP address. Switch this log off if you do not want it.
- **Backup.** On the admin page, open "Tools". "Save all links as CSV" creates the backup. "Import CSV" restores it. Both use the same format, see [csv.md](csv.md). You can also copy the SQLite file.
- **Browsers remember a 301.** Maybe you delete a link and use its code again for another target. Then people who opened the old link can still reach the old target.
- **Update.** Run `git pull`. Up to version v0.2.0, no update needs a migration step. Versions follow Semantic Versioning. [CHANGELOG.md](../CHANGELOG.md) lists the changes.

## test it locally

```sh
sh deploy/test.sh            # runs all tests and removes everything afterwards
sh deploy/e2e.sh             # the same in a real browser (Playwright container)
KEEP=1 sh deploy/test.sh     # keeps the test server running on http://localhost:8082/admin/
```

The test password is in `deploy/test-config.php`. With `KEEP=1`, you get a test server with an empty database. Stop it with `podman compose -f deploy/compose.yml down`. This setup is for tests only. Do not use it on a public server.
