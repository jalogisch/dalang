# install

[Deutsche Fassung](install.de.md)

This is how I run dalang: nginx in front, php-fpm behind it, a reverse proxy that does TLS. It is the only setup I have tested. Apache will probably work with a few rewrite rules, but I ship none and I have not tried it.

## what you need

- PHP 8.1 or newer with `pdo_sqlite` and sessions. I test with PHP 8.5 only. The official image `php:8.5-fpm-alpine3.24` has everything, nothing to install.
- nginx with php-fpm.
- HTTPS. The session cookie is `Secure`.

## 1. get the code

```sh
git clone https://github.com/jalogisch/dalang.git /srv/dalang
```

The web server only needs to run two files: `public/index.php` and `public/admin/index.php`. Everything else stays outside the web root. PHP needs read access to the code and write access to one folder, the one with the database.

## 2. password and configuration

```sh
cd /srv/dalang
cp config.example.php config.php
php bin/hash-password.php
```

The script asks for a password (12 characters or more) and prints a hash. No PHP on the machine? A container does the same:

```sh
podman run --rm -it -v "$PWD/bin":/dalang-bin:ro docker.io/library/php:8.5-cli-alpine php /dalang-bin/hash-password.php
```

Now edit `config.php`:

| Key | Meaning |
|---|---|
| `base_url` | The public address of the shortener, for example `https://example.org`. Used for the short links shown in the admin and for the bookmarklet. |
| `fallback_url` | Optional. Where everything goes that is not a known code. `https://example.org/about` becomes `https://www.example.org/about`. I use it so the old addresses of an earlier site live on. Leave it empty or delete the line, and everything that is not a known code answers `404`. |
| `password_hash` | The hash from above. Single quotes, because it contains `$`. |
| `database` | Path of the SQLite file. The folder must be writable for PHP: SQLite puts a journal file next to it. Keep it out of `public/`. |

`config.php` is in `.gitignore`. Keep it that way.

```sh
mkdir data
chown www-data data     # or whatever user your php-fpm runs as
```

## 3. nginx

[deploy/nginx.conf](../deploy/nginx.conf) is an example, not a finished setup. Take it and change three things: `listen`, the path `/app` and `fastcgi_pass`.

The idea behind it: nginx serves no file. There are two fixed ways in, and both name their script directly:

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

Please keep the `if` line. nginx decodes a path before it picks a location, so `/x%2F..%2Fadmin/` ends up at the admin too. A proxy in front of nginx may read the same path differently. If you protect `/admin` in that proxy as well (an extra password, an address filter), such a path would slip past it. With the check on the raw address the admin answers at exactly `/admin/` and nowhere else.

## 4. first visit

Open `https://example.org/admin/` and log in. The first visit creates the database. Until then, short codes answer with 503. That is on purpose: without a database I do not know if a code exists, and browsers remember a wrong 301 for a long time.

Then create a link and try it:

```sh
curl -sI https://example.org/your-code
```

## things to know

- **Session.** A login lasts 14 days, so the bookmarklet does not ask for the password every time. "Log out" ends it at once. Sessions are PHP's own files. If they live in a container's `/tmp`, a restart logs you out. That does no harm.
- **Logs.** The example config has `access_log off`, and dalang itself stores nothing about visitors. But look at the rest of your chain: the nginx error log, your reverse proxy and php-fpm can all write logs. The official PHP image logs every request with path and time (not the visitor's address) to the container output. Turn that off if you do not want it.
- **Backup.** "Save all links as CSV" in the admin is the backup, "Import CSV" is the restore. It is the same format in both directions, see [csv.md](csv.md). You can also just copy the SQLite file.
- **301 sticks.** Browsers remember a 301. If you delete a link and reuse the code for another target, people who used the old one may still land at the old target.
- **Update.** `git pull`. There is no migration step in 0.1.0. Versions follow Semantic Versioning, what changed is in [CHANGELOG.md](../CHANGELOG.md).

## try it locally

```sh
sh deploy/test.sh            # runs all tests and cleans up
sh deploy/e2e.sh             # the same in a real browser (Playwright container)
KEEP=1 sh deploy/test.sh     # leaves it running on http://localhost:8082/admin/
```

The test password is in `deploy/test-config.php`. With `KEEP=1` you get an empty database to click around in. Stop it with `podman compose -f deploy/compose.yml down`. This setup is for testing only.
