# dalang

dalang is a small link shortener: PHP and SQLite, no framework, no Composer, no build step. This repository is public.

Read this file completely before you work here. It applies to every agent, sub-agents included.

## Layout

| Path | What it is |
|---|---|
| `public/index.php` | Public entry point. Short code -> 301. Unknown -> 301 to `fallback_url`, or 404 if that is empty or not set. Database error -> 503. Never writes. |
| `public/admin/index.php` | Admin, reachable only at `/admin/`: login, add, list, delete, CSV import and export, bookmarklet. Three views on that address: the form (default, the bookmarklet opens it), `?view=links`, `?view=tools`. |
| `lib.php` | Shared code: load config, open database, validate code and URL, pick the language. |
| `lang/*.php` | Admin texts, one array per language. English is the fallback. |
| `config.example.php` | Template for `config.php`. Placeholders only. |
| `bin/hash-password.php` | Prints the password hash for `config.php`. |
| `bin/changelog.sh` | Writes `CHANGELOG.md` new from `git log`. Never edit `CHANGELOG.md` by hand. |
| `.github/workflows/changelog.yml` | Runs `bin/changelog.sh` after every push to `main` and commits the result. |
| `deploy/` | Example `nginx.conf`, plus `compose.yml`, `test-config.php`, `test.sh` (curl) and `e2e.sh` with `e2e.js` (browser) for the local tests. |
| `docs/`, `README*.md` | Documentation in English and German. Keep both languages in step. `docs/csv*.md` describes the CSV format: change it together with the import and export code. |

`config.php`, `data/` and `deploy/.testdaten/` are not in git. Do not read or commit them.

## Rules

1. **Nothing private in this repository.** No real host names, no real links, no IP addresses, no secrets, no hints where secrets are stored. Examples use `example.org`.
2. **No visitor data.** The public part stores nothing about visitors: no click counter, no log, no cookie.
3. **Only a POST with a valid CSRF token changes data.** A GET never creates, deletes or imports anything. This also holds for the bookmarklet.
4. **Validate at the border.** Targets are `http` or `https` only. Codes match `[a-z0-9-]{1,40}` and do not start with `admin`. SQL only with prepared statements. Output only through `htmlspecialchars`.
5. **Keep it small.** No framework, no dependency, no new option without a real need. Editing links, statistics, users and an API are left out on purpose.
6. **Every admin text lives in `lang/en.php` and `lang/de.php`.** No text in the PHP code itself.
7. **Accessibility is not optional:** labels for every field, error messages linked to their field, `lang` attribute.
8. **The maintainer commits, tags and pushes.** Agents propose a file list and a message. The locks are in `.claude/settings.json` (no commit, no push, no ssh, no `rm -r`).
9. **"Done" needs proof.** Run the tests and show the output. Mark what is not tested as UNPROVEN.
10. **One commit is one change.** One thing per commit, readable on its own, easy to revert. Propose several small commits instead of one big one. The subject follows Conventional Commits: `feat:`, `fix:`, `docs:`, `test:`, `chore:` or `refactor:`, then a short English sentence in the imperative. A break gets `!` after the type or a `BREAKING CHANGE:` line in the body. The subjects become the changelog, so write them for a reader.
11. **Documentation is in plain language.** English follows Plain Language (ISO 24495-1). German uses Einfache Sprache, not Leichte Sprache. Write for an international reader who may not be a native speaker:
    - Short sentences with one main idea each, under 20 words.
    - Common words. One term for one thing, used the same way everywhere. Button and view names exactly as in `lang/*.php`.
    - Active voice: say who does what. The maintainer writes as "I".
    - No idioms, slang, metaphors or wordplay. Simple verbs instead of phrasal verbs ("remove", not "take out").
    - Explain abbreviations and technical terms the first time they appear.
    - Main point first, then the details.
    - Explicit dates (2026-11-18) and units. No "next Tuesday", no "a couple".

## Versions and releases

Versions follow Semantic Versioning (semver.org). Tags are named `vMAJOR.MINOR.PATCH`. `fix` -> patch, `feat` -> minor, break -> major (before 1.0.0: break -> minor). The version lives in the git tag only. The code does not show it. If that ever changes, keep it in one place.

`CHANGELOG.md` is generated: `bin/changelog.sh` writes one line per commit subject, one section per `v*` tag, newest first, "Unreleased" on top. The GitHub Action runs it after every push to `main` and commits the file as `chore: update changelog [skip ci]` (the script leaves these commits out). The action uses only the automatic `GITHUB_TOKEN`, and its actions are pinned to a full commit SHA. Keep it that way.

Release steps, done by the maintainer:

1. `sh deploy/test.sh` and `sh deploy/e2e.sh` both end with `ALL OK`.
2. Pick the version from the commits since the last tag.
3. `sh bin/changelog.sh vX.Y.Z`, then commit `CHANGELOG.md` as `chore: update changelog`.
4. `git tag vX.Y.Z`, then `git push origin main vX.Y.Z`.

## Tests

Needs Podman (or Docker), curl and git. No PHP on the host needed.

```sh
sh deploy/test.sh    # curl: redirects, 404 without fallback, locks, login, CSRF, validation, CSV, headers, changelog script
sh deploy/e2e.sh     # browser: login, create with and without a code, prefill like the bookmarklet, follow the short link
```

Both start nginx and php-fpm on `127.0.0.1:8082` (the same compose project, so run one after the other), run their checks, then stop and remove the containers and the test data. Both end with `ALL OK` or with exit code 1. `e2e.sh` adds a Playwright container (image and npm package pinned to the same version in `deploy/compose.yml`, the package is fetched on every run). Run both before every tag. A change to the admin form needs a look at `deploy/e2e.js`.
