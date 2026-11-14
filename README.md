# dalang

[Deutsche Fassung](README.de.md)

dalang is a small link shortener for one person. `https://example.org/abc` goes to the long address, and a form behind a password lets me add and delete links. PHP and SQLite, about 600 lines, no framework, no Composer, no build step.

## why

My YOURLS installation broke. When I looked at what I really needed from it, the list was short: redirects, a form, a list, and a way to get my old links back in. Everything else was code I had to keep alive without using it.

So dalang does little, and that is the point. Simple is not bad, it may be exactly right.

What it does:

- A short code redirects with 301 to its target.
- Everything else redirects to a fallback address, if you set one, path and query included. So the short domain can sit next to your real site, and old addresses live on there. Without a fallback address, everything else answers 404.
- Admin at `/admin/` with its own login: create (with your own code or a random one), list, delete.
- CSV export as backup, CSV import as restore. The import also takes a list from YOURLS. The format is written down in [docs/csv.md](docs/csv.md).
- A bookmarklet, so I do not have to open the admin page first.
- The admin speaks English and German, picked from your browser language.

What it does not do, on purpose:

- No click counter, no statistics, no visitor data at all. The public part never writes anything.
- No editing. Delete the link and create it again with the same code.
- No users, no API, no QR codes, no search. The list is one table, your browser can search it.

If you need those, YOURLS, Shlink or Kutt are the better choice. That is fine.

I did not write it alone. An LLM (Claude) helped me to build it. The rules it has to follow are in [CLAUDE.md](CLAUDE.md), and the tests decide what counts as done.

## quick start

You need PHP 8.1 or newer with `pdo_sqlite` (I test with PHP 8.5 only), nginx with php-fpm, and HTTPS. The login cookie is marked `Secure`, without HTTPS you can not log in.

```sh
git clone https://github.com/jalogisch/dalang.git
cd dalang
cp config.example.php config.php
php bin/hash-password.php        # asks for a password, prints the hash
mkdir data                       # must be writable for the PHP user
```

Put the hash and your addresses into `config.php`:

```php
return [
    'base_url' => 'https://example.org',          // your short domain
    'fallback_url' => 'https://www.example.org',  // where unknown paths go; leave it out and they answer 404
    'password_hash' => '$2y$12$...',              // from bin/hash-password.php, in single quotes
    'database' => __DIR__ . '/data/links.sqlite',
];
```

Then point nginx at it. [deploy/nginx.conf](deploy/nginx.conf) is an example to start from. Open `https://example.org/admin/`. The details, and what to watch out for, are in [docs/install.md](docs/install.md).

Coming from YOURLS? See [docs/migrate-from-yourls.md](docs/migrate-from-yourls.md).

## bookmarklet

The admin page has a link called "Shorten with dalang". Drag it into your bookmarks bar. A click on it, on any page, opens the dalang form in a new tab with the address and the title already filled in. You choose a code or leave it empty, click "Create", and get the short link in big letters with a "Copy" button.

Two honest notes:

- The bookmarklet only opens the form. Nothing is created until you click "Create". If a plain link could create short links, every web page you visit could do it for you.
- A bookmarklet is a bit of JavaScript that runs on the page you are looking at. Some pages forbid that with a strict Content Security Policy, and then the browser may block it. I can not fix that from my side. On those pages, copy the address and open `/admin/` by hand.

## security, in short

- The password is stored as a hash (`password_hash`), never in clear text.
- Every change is a POST with a CSRF token. A GET changes nothing.
- Only one login attempt every 5 seconds is checked, for everybody together. This slows down guessing without storing any address.
- Targets must be `http://` or `https://`. No `javascript:`, no `data:`.
- nginx serves no file at all. There are two ways in, the redirect and the admin. The config and the database can not be fetched.

More, and the limits I know of, in [SECURITY.md](SECURITY.md).

## tests

```sh
sh deploy/test.sh
```

This needs Podman (or `ENGINE=docker`), curl and git, no PHP on your machine. It starts nginx and php-fpm on `127.0.0.1:8082`, runs about 160 checks with curl and cleans up again.

```sh
sh deploy/e2e.sh
```

This is the same in a real browser. A Chromium in a Playwright container logs in, creates links through the form (with its own code, with a random one, and prefilled like the bookmarklet does it), reads the short link from the page and follows it to the target. About 30 checks, then everything is stopped and removed. The Playwright image is big (about 2.5 GB), and every run fetches the npm package, so it needs internet.

## contributing and releasing

One commit is one change: one thing, readable on its own, easy to take back. The subject line follows [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `chore:` or `refactor:`, then a short sentence in English, in the imperative. A `!` after the type (`feat!:`) or a `BREAKING CHANGE:` line in the body marks a break.

I am this strict because the commit subjects are the changelog. `bin/changelog.sh` writes [CHANGELOG.md](CHANGELOG.md) new from `git log`: one line per commit, one section per version tag, the newest on top, and above them "Unreleased". A GitHub Action (`.github/workflows/changelog.yml`) runs the script after every push to `main` and commits the file if it changed. So do not edit CHANGELOG.md by hand, the next run would throw your edit away.

Versions follow [Semantic Versioning](https://semver.org/), the tags are named `vMAJOR.MINOR.PATCH`. A `fix` raises the patch number, a `feat` the minor number, a break the major number. Before 1.0.0 a break raises the minor number only. The version lives in the git tag and nowhere else.

A release goes like this:

```sh
sh deploy/test.sh && sh deploy/e2e.sh     # both must end with ALL OK
sh bin/changelog.sh v0.2.0                # the open commits now stand under "v0.2.0 - <today>"
git commit -m 'chore: update changelog' CHANGELOG.md
git tag v0.2.0
git push origin main v0.2.0
```

## the name

"Da lang" is German for "this way" - what you say when you point someone down the right street. And in Indonesian and Javanese a dalang is the puppeteer of the wayang shadow theatre, the one behind the screen who makes the figures move. Both fit a thing that stands in the back and sends people on.

## license

MIT, see [LICENSE](LICENSE).
