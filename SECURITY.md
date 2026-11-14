# security

## reporting a problem

Please do not open a public issue for a security problem. Use GitHub's private reporting instead: on https://github.com/jalogisch/dalang go to "Security", then "Report a vulnerability" (GitHub Security Advisories).

Tell me what you did, what happened and what you expected. A request I can repeat with curl is the best report there is.

This is a small project and I maintain it in my spare time. I read every report and I answer, but I promise no fixed time. Fixes go into the newest version only.

## how it is built

- **Two ways in.** nginx serves no file. `/admin/` runs the admin script, everything else runs the redirect script. Config, database and code can not be fetched.
- **The public part only reads.** It looks up a code and answers 301, 404 for an unknown path when no fallback address is set, or 503 if the database is not there. It writes nothing and stores nothing about visitors.
- **Login.** One password, stored as a hash (`password_hash`) in a config file outside the web root, checked with `password_verify`. The session id changes after login. The cookie is `Secure`, `HttpOnly`, `SameSite=Lax` and limited to `/admin/`.
- **CSRF.** Every POST needs a token from the session, login and logout included. A GET never changes anything. That also holds for the bookmarklet: it only fills in the form.
- **Guessing.** Only one login attempt every 5 seconds is checked, for everybody together.
- **Input.** Targets must be `http://` or `https://` with a host. Codes are `a-z`, `0-9`, `-`. SQL only through prepared statements, output only escaped.
- **Headers.** The admin sends a strict Content Security Policy with a nonce, `no-store` and `noindex`. The example nginx config adds HSTS, `nosniff`, `no-referrer` and `X-Frame-Options: DENY`.

## limits I know of

- **The login brake is global.** I did not want to store addresses, so the brake does not know who is guessing. Someone who sends wrong passwords all the time can keep you from logging in. The redirects keep working. If that happens to you, put a second lock in front of `/admin/` in your proxy (an address filter or a second password).
- **`SameSite=Lax`, not `Strict`.** With `Strict` the bookmarklet would ask for the password every time, because it opens the admin from another site. The CSRF token is what protects the actions.
- **A login lasts 14 days.** Whoever has your unlocked browser has your short links. Use "Log out" on machines that are not yours.
- **The password is as good as you make it.** The hash script wants 12 characters. Take more.
- **The CSV is a backup, not a spreadsheet.** Titles come from the pages you shorten, so a title can start with `=`. A spreadsheet program may read that as a formula. Do not open an export in one without thinking.
- **dalang redirects wherever you tell it.** It does not check targets, not when you create them and not later.
- **Logs are not my part.** dalang stores nothing about visitors, but your proxy, nginx and php-fpm may. See `docs/install.md`.
- **Only nginx with php-fpm is tested.** With another server it is your job to make sure nothing but the two scripts is reachable.
