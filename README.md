# dalang

[Deutsche Fassung](README.de.md)

dalang is a small link shortener for one person. A short link like `https://example.org/abc` sends the browser to a long address. I add and delete links on an admin page with a password.

dalang uses PHP (a programming language for web servers) and SQLite (a database in one single file). It has about 600 lines of PHP code. It needs no framework, no Composer (the PHP package manager) and no build step.

## why

My YOURLS installation stopped working. YOURLS is a bigger link shortener. I made a list of what I really used. The list was short:

- redirects
- a form to add links
- a list of all links
- a way to import my old links

YOURLS had much more code. I had to maintain that code, but I did not use it. So dalang does only these few things. I chose this on purpose: simple software is easier to maintain.

## what dalang does

- **Redirect.** A browser that opens a short link gets a redirect to the target address. dalang uses HTTP status 301 (moved permanently).
- **Fallback address.** You can set a fallback address. Every unknown path then redirects there, with path and query. Example: `https://example.org/about` redirects to `https://www.example.org/about`. So old addresses of your main site still work. Without a fallback address, an unknown path gets HTTP status 404 (not found).
- **Admin page.** The admin page is at `/admin/` and has its own login. It has three views:
  - **New link:** the form. You enter the target, and optionally your own code and a title. Without a code, dalang creates 5 random characters.
  - **All links:** a table of all links, newest first, with a "Delete" button for each link.
  - **Tools:** export, import and the bookmarklet.
- **Export and import.** The export saves all links as a CSV file (comma-separated values). This file is your backup. The import reads the same format. It also reads a list from YOURLS. [docs/csv.md](docs/csv.md) describes the format.
- **Bookmarklet.** A bookmarklet is a bookmark that contains a small JavaScript program. With it, you do not have to open the admin page first.
- **Languages.** The admin page is in English and German. dalang uses the language of your browser.
- **Colours.** The admin page follows the light or dark setting of your system.

## what dalang does not do

I left these features out on purpose:

- **No visitor data.** dalang has no click counter and no statistics. The public part never writes anything.
- **No editing.** To change a link, delete it. Then create it again with the same code.
- **No users, no API, no QR codes, no search.** The list is one table. Use the search function of your browser.

Do you need these features? Then YOURLS, Shlink or Kutt are a better choice for you.

I did not write dalang alone. An LLM (a large language model, here Claude) helped me to build it. [CLAUDE.md](CLAUDE.md) contains the rules for the LLM. The tests decide when a change is done.

## quick start

You need:

- PHP 8.1 or newer, with `pdo_sqlite`. I test with PHP 8.5 only.
- nginx (a web server) with php-fpm (the PHP process manager).
- HTTPS. The login cookie has the `Secure` flag. Without HTTPS, the browser does not send it, and you cannot log in.

```sh
git clone https://github.com/jalogisch/dalang.git
cd dalang
cp config.example.php config.php
php bin/hash-password.php        # asks for a password and prints its hash
mkdir data                       # the PHP user must be able to write here
```

Write the hash and your addresses into `config.php`:

```php
return [
    'base_url' => 'https://example.org',          // your short domain
    'fallback_url' => 'https://www.example.org',  // target for unknown paths; without this line they get 404
    'password_hash' => '$2y$12$...',              // from bin/hash-password.php, in single quotes
    'database' => __DIR__ . '/data/links.sqlite',
];
```

Then configure nginx. Start with the example [deploy/nginx.conf](deploy/nginx.conf). Open `https://example.org/admin/` in your browser. [docs/install.md](docs/install.md) has all details and warnings.

Do you move from YOURLS? Read [docs/migrate-from-yourls.md](docs/migrate-from-yourls.md).

## bookmarklet

Open the view "Tools" on the admin page. There you find a button "Shorten with dalang". Drag this button into the bookmarks bar of your browser.

Then open any web page and click the bookmark. dalang opens the form in a new tab. The address and the title of the page are already in the form. You enter a code or leave the field empty. Then you click "Create". dalang shows the new short link in large text, with a "Copy" button.

Two notes:

- The bookmarklet only opens the form. dalang creates the link only after you click "Create". This is a security rule. If a simple link could create short links, every web page could create links in your name.
- The bookmarklet runs JavaScript on the page that you look at. Some pages forbid this with a strict Content Security Policy (CSP). On these pages, the browser can block the bookmarklet. I cannot change that in dalang. On these pages, copy the address and open `/admin/` yourself.

## security in short

- dalang stores only a hash of the password (with PHP's `password_hash`), never the password itself.
- Every change needs a POST request with a CSRF token. CSRF (cross-site request forgery) is an attack where another web site sends requests in your name. The token blocks this. A GET request never changes data.
- dalang checks at most one login attempt every 5 seconds, for all users together. This makes guessing slow. dalang does not store IP addresses for this.
- A target must start with `http://` or `https://`. dalang rejects `javascript:` and `data:`.
- nginx delivers no files. There are only two entry points: the redirect and the admin page. Nobody can download the configuration or the database.

[SECURITY.md](SECURITY.md) has more details and the limits that I know of.

## tests

```sh
sh deploy/test.sh
```

You need Podman (or Docker with `ENGINE=docker`), curl and git. You do not need PHP on your computer. The script starts nginx and php-fpm on `127.0.0.1:8082`. Then it runs about 170 checks with curl. At the end, it removes the containers and the test data.

```sh
sh deploy/e2e.sh
```

This script runs about 35 checks in a real browser (end-to-end test). It uses Chromium in a Playwright container. The browser logs in and creates links with the form. It tests three cases: with its own code, with a random code, and with a form that the bookmarklet filled in. Then it opens each short link and checks the target. At the end, it removes everything. The Playwright image is large (about 2.5 GB). Every run downloads the npm package, so the test needs internet access.

## contributing and releasing

**One commit contains one change.** Each commit must be readable on its own and easy to revert.

**The subject line follows [Conventional Commits](https://www.conventionalcommits.org/).** It starts with a type: `feat:`, `fix:`, `docs:`, `test:`, `chore:` or `refactor:`. Then follows a short sentence in English, in the imperative ("add", not "added"). A change that breaks something has a `!` after the type (`feat!:`), or a line `BREAKING CHANGE:` in the body.

**The commit subjects are the changelog.** That is why I am strict about them. `bin/changelog.sh` creates [CHANGELOG.md](CHANGELOG.md) from `git log`:

- one line for each commit
- one section for each version tag, newest first
- a section "Unreleased" at the top

A GitHub Action (`.github/workflows/changelog.yml`) runs the script after every push to `main`. If the file changed, the Action commits it. So do not edit CHANGELOG.md yourself. The next run would delete your change.

**Versions follow [Semantic Versioning](https://semver.org/).** The tags have the form `vMAJOR.MINOR.PATCH`, for example `v0.2.0`.

- A `fix` increases the PATCH number.
- A `feat` increases the MINOR number.
- A breaking change increases the MAJOR number. Before version 1.0.0, a breaking change increases only the MINOR number.

The version exists only in the git tag. The code does not contain it.

These are the steps for a release. Replace `vX.Y.Z` with the new version:

```sh
sh deploy/test.sh && sh deploy/e2e.sh     # both must end with ALL OK
sh bin/changelog.sh vX.Y.Z                # moves the open commits under "vX.Y.Z - <today>"
git commit -m 'chore: update changelog' CHANGELOG.md
git tag vX.Y.Z
git push origin main vX.Y.Z
```

## the name

The name has two meanings.

1. In German, "da lang" means "this way". People say it when they show someone the right direction.
2. In Indonesian and Javanese, a dalang is the puppet master of the wayang shadow theatre. The dalang sits behind the screen and moves the figures.

Both meanings fit dalang: it works in the background and sends people to the right place.

## license

MIT, see [LICENSE](LICENSE).
