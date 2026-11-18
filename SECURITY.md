# security

## report a problem

Please do not open a public issue for a security problem. Use the private reporting of GitHub instead:

1. Open https://github.com/jalogisch/dalang.
2. Click "Security".
3. Click "Report a vulnerability" (GitHub Security Advisories).

Please tell me three things: what you did, what happened, and what you expected. The best report is a request that I can repeat with curl.

dalang is a small project. I maintain it in my free time. I read every report and I answer every report, but I cannot promise a fixed response time. I fix problems only in the newest version.

## how dalang protects itself

- **Two entry points.** nginx delivers no files. The path `/admin/` runs the admin script. Every other path runs the redirect script. Nobody can download the configuration, the database or the code.
- **The public part only reads.** It looks up a code and answers with one of these HTTP status codes:
  - 301 (moved permanently) for a known code, or for an unknown path when a fallback address is set
  - 404 (not found) for an unknown path when no fallback address is set
  - 503 (service unavailable) when the database does not exist yet

  The public part writes nothing. It stores nothing about visitors.
- **Login.** There is one password. dalang stores only its hash (with PHP's `password_hash`). The hash is in a configuration file outside the web root. dalang checks the password with `password_verify`. After the login, dalang creates a new session ID. The session cookie has the flags `Secure`, `HttpOnly` and `SameSite=Lax`, and it is valid only for `/admin/`.
- **CSRF.** CSRF (cross-site request forgery) is an attack where another web site sends requests in your name. Every POST request needs a token from the session. This includes login and logout. A GET request never changes data. This rule also applies to the bookmarklet: it only fills in the form.
- **Guessing passwords.** dalang checks at most one login attempt every 5 seconds, for all users together.
- **Input.** A target must start with `http://` or `https://` and must contain a host name. A code contains only `a-z`, `0-9` and `-`. All database queries use prepared statements. dalang escapes all output.
- **HTTP headers.** The admin page sends a strict Content Security Policy (CSP) with a nonce. It also sends `no-store` and `noindex`. The example nginx configuration adds HSTS (HTTP Strict Transport Security), `nosniff`, `no-referrer` and `X-Frame-Options: DENY`.

## limits that I know of

- **The login delay applies to everybody.** I did not want to store IP addresses. So the delay does not know who sends the login attempts. Someone who sends wrong passwords all the time can block your login. The redirects still work. If this happens, protect `/admin/` a second time in your proxy. Examples are an address filter or a second password.
- **`SameSite=Lax`, not `Strict`.** The bookmarklet opens the admin page from another web site. With `Strict`, the browser would not send the cookie, and the bookmarklet would ask for the password every time. The CSRF token protects all actions.
- **A login lasts 14 days.** A person with access to your unlocked browser can change your short links. Use "Log out" on computers that are not yours.
- **dalang cannot make your password strong.** The hash script requires at least 12 characters. Use more.
- **The CSV file is a backup, not a spreadsheet.** Titles come from the pages that you shorten. So a title can start with `=`, and a spreadsheet program can read this as a formula. Do not open an export in a spreadsheet program. Use a text editor.
- **dalang redirects to every target that you enter.** It does not check targets, not when you create a link and not later.
- **Logs of other programs.** dalang stores nothing about visitors. But your proxy, nginx and php-fpm can write logs. See [docs/install.md](docs/install.md).
- **Only nginx with php-fpm is tested.** With another web server, you must make sure yourself that only the two scripts are reachable.
