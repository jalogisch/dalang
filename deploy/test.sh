#!/bin/sh
# dalang - local test. Starts nginx and php-fpm (deploy/compose.yml), checks redirects, locks,
# login, CSRF, validation, CSV import and export, languages, headers and bin/changelog.sh, then cleans up.
# The same forms in a real browser: sh deploy/e2e.sh
#
#   sh deploy/test.sh                          needs podman (or ENGINE=docker), curl and git
#   LINKS_CSV=/path/to/your.csv sh deploy/test.sh    also imports your own file (keep it outside the repo)
#   KEEP=1 sh deploy/test.sh                   leaves the containers running afterwards
#
# Prints OK or FAIL per check. Exit code 1 if anything failed.
ENGINE=${ENGINE:-podman}
BASE=http://127.0.0.1:8082
SHORT=https://short.example.org     # base_url in deploy/test-config.php
FALLBACK=https://www.example.org    # fallback_url in deploy/test-config.php
PASSWORD=dalang-test-password       # public test password, see deploy/test-config.php
HERE=$(cd "$(dirname "$0")" && pwd)
DATA="$HERE/.testdaten"
TMP=$(mktemp -d) || exit 2
FAILED=0
CHECKS=0
COOKIE=""

cleanup() {
  if [ -n "$KEEP" ]; then
    empty_db   # the last check breaks the database on purpose
  else
    "$ENGINE" compose -f "$HERE/compose.yml" down >/dev/null 2>&1
    rm -rf "$DATA"
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT

ok()   { CHECKS=$((CHECKS + 1)); echo "OK    $1"; }
fail() { CHECKS=$((CHECKS + 1)); FAILED=$((FAILED + 1)); echo "FAIL  $1 (got: $2)"; }
is()   { if [ "$2" = "$3" ]; then ok "$1"; else fail "$1, want '$3'" "'$2'"; fi; }

# req [curl options] URL: status in $CODE, headers in $TMP/head, body in $TMP/body. Keeps the session cookie.
# The cookie is "Secure" and the test runs over plain http, so the script sends it by hand.
req() {
  if [ -n "$COOKIE" ]; then
    CODE=$(curl -s -o "$TMP/body" -D "$TMP/head.raw" -w '%{http_code}' -H "Cookie: $COOKIE" "$@")
  else
    CODE=$(curl -s -o "$TMP/body" -D "$TMP/head.raw" -w '%{http_code}' "$@")
  fi
  tr -d '\r' < "$TMP/head.raw" > "$TMP/head"
  new=$(sed -n 's/^[Ss]et-[Cc]ookie: \(dalang=[^;]*\).*/\1/p' "$TMP/head" | tail -1)
  if [ -n "$new" ]; then COOKIE=$new; fi
}
head_of() { grep -i "^$1:" "$TMP/head" | head -1 | sed 's/^[^:]*: *//'; }
token()   { sed -n 's/.*name="csrf" value="\([0-9a-f]*\)".*/\1/p' "$TMP/body" | head -1; }
has()     { if grep -qF -- "$2" "$TMP/body"; then ok "$1"; else fail "$1" "text not found: $2"; fi; }
hasnot()  { if grep -qF -- "$2" "$TMP/body"; then fail "$1" "text found: $2"; else ok "$1"; fi; }
links()   { req "$BASE/admin/?view=links"; sed -n 's/.*<caption>\([0-9]*\) links.*/\1/p' "$TMP/body"; }

# redirect PATH TARGET: 301 with exactly this Location
redirect() {
  req "$BASE$1"
  is "$1 -> 301 $2" "$CODE $(head_of Location)" "301 $2"
}
# locked PATH [curl options]: never a file, never the admin form
locked() {
  p=$1; shift
  req "$@" "$BASE$p"
  if [ "$CODE" = 200 ] || grep -qE '<\?php|SQLite format 3|name="csrf"|password_hash|\[core\]' "$TMP/body"; then
    fail "$p is locked" "status $CODE or file content"
  else
    ok "$p is locked ($CODE)"
  fi
}
# post FIELD=VALUE ...: POST to /admin/ with the token of the page fetched last, then load the page again
post() {
  set -- --data-urlencode "csrf=$TOKEN" "$@"
  req "$@" "$BASE/admin/"
  POSTCODE=$CODE; POSTLOC=$(head_of Location)
  req "$BASE/admin/"
  TOKEN=$(token)
}
# in_php COMMAND...: run a command inside the PHP container (the database file belongs to that container)
in_php()   { "$ENGINE" compose -f "$HERE/compose.yml" exec -T php "$@" 2>/dev/null; }
empty_db() { in_php sh -c 'rm -f /data/links.sqlite*'; }
add() { post --data-urlencode action=add --data-urlencode "url=$1" --data-urlencode "code=$2" --data-urlencode "title=$3"; }

# --- start ------------------------------------------------------------------------------
"$ENGINE" compose -f "$HERE/compose.yml" down >/dev/null 2>&1
rm -rf "$DATA"
mkdir -p "$DATA/data" && chmod 777 "$DATA/data"
"$ENGINE" compose -f "$HERE/compose.yml" up -d >/dev/null || { echo "could not start the containers"; exit 2; }
i=0
until [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 301 ]; do
  i=$((i + 1)); [ "$i" -gt 30 ] && { echo "the test server did not come up"; exit 2; }
  sleep 1
done

echo "--- redirect without a database"
req "$BASE/abc"
is "/abc without database -> 503, no Location" "$CODE $(head_of Location)" "503 "
redirect / "$FALLBACK/"

echo "--- language"
lang() { req -H "Accept-Language: $1" "$BASE/admin/"; has "Accept-Language '$1' -> $2" "<html lang=\"$2\">"; }
lang 'de' de
lang 'de-DE,de;q=0.9,en;q=0.8' de
lang 'fr;q=0.9, de;q=0.8, en;q=0.1' de
lang 'de;q=0.1, en;q=0.9' en
lang 'de;q=0, fr' en
lang 'fr-FR, es;q=0.5' en
lang '../../etc/passwd' en
req -H 'Accept-Language: de' "$BASE/admin/"; has "German login text" 'Passwort'
COOKIE=""
req "$BASE/admin/"; has "no Accept-Language -> English" '<html lang="en">'

echo "--- login"
is "/admin/ -> 200" "$CODE" 200
has "login form has a label" '<label for="password">Password</label>'
hasnot "login page does not show the link form" 'name="url"'
flags=$(head_of Set-Cookie | tr 'A-Z' 'a-z')
for f in 'path=/admin/' secure httponly 'samesite=lax'; do
  case "$flags" in *"$f"*) ok "session cookie has $f";; *) fail "session cookie has $f" "$flags";; esac
done
TOKEN=$(token)
BEFORE=$COOKIE
req --data-urlencode action=login --data-urlencode "password=$PASSWORD" "$BASE/admin/"
is "login without CSRF token -> 403" "$CODE" 403
add 'https://example.org/not-logged-in' nologin ''
redirect /nologin "$FALLBACK/nologin"
req "$BASE/admin/?export"; hasnot "no CSV export without login" 'code,url'
req "$BASE/admin/"; TOKEN=$(token)
post --data-urlencode action=login --data-urlencode 'password=wrong-password'
is "wrong password -> 303" "$POSTCODE" 303
has "wrong password is reported" 'Wrong password.'
has "error is linked to the field" 'aria-describedby="password-err"'
hasnot "wrong password does not log in" 'name="url"'
post --data-urlencode action=login --data-urlencode "password=$PASSWORD"
has "brake: right password too early is not checked" 'Too many attempts'
sleep 6
post --data-urlencode action=login --data-urlencode "password=$PASSWORD"
has "right password logs in" 'name="url"'
if [ "$COOKIE" != "$BEFORE" ]; then ok "new session id after login"; else fail "new session id after login" "same cookie"; fi

echo "--- views"
has "form is the default view" 'name="url"'
has "form view is marked in the menu" 'href="/admin/" aria-current="page"'
hasnot "form view has no list" '<table'
hasnot "form view has no import" 'name="csv"'
req "$BASE/admin/?view=links"
has "list view is marked in the menu" 'href="/admin/?view=links" aria-current="page"'
hasnot "list view has no form" 'name="url"'
req "$BASE/admin/?view=tools"
has "tools view is marked in the menu" 'href="/admin/?view=tools" aria-current="page"'
has "tools view has the import" 'name="csv"'
hasnot "tools view has no form" 'name="url"'
req "$BASE/admin/?view=nonsense"; has "unknown view shows the form" 'name="url"'
req "$BASE/admin/?view%5B%5D=x"; has "view as array shows the form" 'name="url"'
req "$BASE/admin/?view=tools"
has "bookmarklet link is offered" "href=\"javascript:void(open(&quot;$SHORT/admin/?url=&quot;+encodeURIComponent(location.href)"

echo "--- create"
add 'https://example.org/target?a=1&b=2' test-1 'First <script>alert(1)</script>'
has "created link is shown" "<a id=\"short\" href=\"$SHORT/test-1\">"
has "copy button is there" 'id="copy"'
req "$BASE/admin/?view=links"
has "title is escaped" 'First &lt;script&gt;alert(1)&lt;/script&gt;'
hasnot "title is not raw" '<script>alert(1)'
redirect /test-1 'https://example.org/target?a=1&b=2'
redirect '/test-1?x=1' 'https://example.org/target?a=1&b=2'
add 'https://example.org/minus' -a- ''
redirect /-a- 'https://example.org/minus'
add 'https://example.org/random' '' ''
random=$(sed -n "s|.*<a id=\"short\" href=\"$SHORT/\([^\"]*\)\">.*|\1|p" "$TMP/body")
case "$random" in [a-z0-9][a-z0-9][a-z0-9][a-z0-9][a-z0-9]) ok "empty code -> 5 random characters";; *) fail "empty code -> 5 random characters" "'$random'";; esac
redirect "/$random" 'https://example.org/random'
add 'https://example.org/other' test-1 ''
has "taken code is rejected" 'already in use'
has "taken code: error is linked to the field" 'aria-describedby="code-hint code-err"'
redirect /test-1 'https://example.org/target?a=1&b=2'

echo "--- validation"
N=$(links)
for u in 'javascript:alert(1)' 'ftp://example.org/x' 'https://' 'https://exa mple.org/' '//example.org/x' 'data:text/html,x' 'JAVASCRIPT:alert(1)'; do
  add "$u" '' ''
  has "target '$u' is rejected" 'id="url-err"'
done
add 'https://example.org/long' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ''
has "code with 41 characters is rejected" 'id="code-err"'
for c in Abc a.b a/b 'a b' adminx admin; do
  add 'https://example.org/code' "$c" ''
  has "code '$c' is rejected" 'id="code-err"'
done
is "nothing was created by rejected input" "$(links)" "$N"
redirect /adminx "$FALLBACK/adminx"

echo "--- CSRF and GET"
TOKEN=$(token)
req --data-urlencode action=add --data-urlencode 'url=https://example.org/csrf' --data-urlencode code=csrf-1 "$BASE/admin/"
is "POST without token -> 403" "$CODE" 403
req --data-urlencode csrf=0000 --data-urlencode action=add --data-urlencode 'url=https://example.org/csrf' --data-urlencode code=csrf-1 "$BASE/admin/"
is "POST with wrong token -> 403" "$CODE" 403
req --data-urlencode 'csrf[]=x' --data-urlencode action=add --data-urlencode 'url=https://example.org/csrf' --data-urlencode code=csrf-1 "$BASE/admin/"
is "POST with token as array -> 403" "$CODE" 403
SAVED=$COOKIE; COOKIE=""
req --data-urlencode "csrf=$TOKEN" --data-urlencode action=add --data-urlencode 'url=https://example.org/csrf' --data-urlencode code=csrf-1 "$BASE/admin/"
is "POST with token but without session -> 403" "$CODE" 403
req "$BASE/admin/?url=https%3A%2F%2Fexample.org%2Fbm&title=Hello"
has "bookmarklet without login: login form keeps the address" 'action="/admin/?url=https%3A%2F%2Fexample.org%2Fbm&amp;title=Hello"'
COOKIE=$SAVED
redirect /csrf-1 "$FALLBACK/csrf-1"
req "$BASE/admin/?url=https%3A%2F%2Fexample.org%2Fbm&title=Hello%20%22there%22&code=bm-1&action=add&csrf=$TOKEN"
has "GET with ?url= fills the form" 'name="url" required maxlength="2000" value="https://example.org/bm"'
has "GET with ?title= fills the title" 'value="Hello &quot;there&quot;"'
is "GET with ?url= creates nothing" "$(links)" "$N"
redirect /bm-1 "$FALLBACK/bm-1"
req "$BASE/admin/?url=javascript%3Aalert(1)%22%3E%3Cscript%3Ealert(2)%3C%2Fscript%3E"
hasnot "prefilled value is escaped" '<script>alert(2)'

echo "--- delete"
req "$BASE/admin/"; TOKEN=$(token)
post --data-urlencode action=delete --data-urlencode code=-a-
is "delete goes back to the list" "$POSTLOC" "/admin/?view=links"
has "delete names code and target" 'Deleted: -a-, which pointed to https://example.org/minus'
redirect /-a- "$FALLBACK/-a-"
is "one link less" "$(links)" "$((N - 1))"

echo "--- CSV import and export"
cat > "$TMP/in.csv" <<'CSV'
code,url,title,created,clicks
csv-1,https://example.org/one,"Title with, comma and ""quotes""",2020-01-02 03:04:05,7
csv-2,http://example.org/two?x=1&y=2,,2021-05-06 07:08:09,0
csv-3,https://example.org/three,Third,2022-01-01 00:00:00,1
bad-1,javascript:alert(1),Bad target,2022-01-01 00:00:00,1
Bad-2,https://example.org/bad,Bad code,2022-01-01 00:00:00,1
test-1,https://example.org/overwrite,Must not overwrite,2022-01-01 00:00:00,1
CSV
import() { req -F "csrf=$TOKEN" -F action=import -F "csv=@$1;type=text/csv" "$BASE/admin/"; POSTCODE=$CODE; POSTLOC=$(head_of Location); req "$BASE/admin/"; TOKEN=$(token); }
req "$BASE/admin/"; TOKEN=$(token)
import "$TMP/in.csv"
is "import goes back to the tools" "$POSTLOC" "/admin/?view=tools"
has "import counts" 'Import done: 3 new, 1 already there, 2 rejected. Rejected lines: 5, 6'
redirect /csv-2 'http://example.org/two?x=1&y=2'
redirect /test-1 'https://example.org/target?a=1&b=2'
import "$TMP/in.csv"
has "second import adds nothing" 'Import done: 0 new, 4 already there, 2 rejected.'
req -F action=import -F "csv=@$TMP/in.csv;type=text/csv" "$BASE/admin/"
is "import without token -> 403" "$CODE" 403
req "$BASE/admin/?export"; cp "$TMP/body" "$TMP/export1.csv"
is "export has a header line" "$(head -1 "$TMP/export1.csv")" 'code,url,title,created'
is "export keeps date, comma and quotes" "$(grep -c '^csv-1,https://example.org/one,"Title with, comma and ""quotes""","2020-01-02 03:04:05"$' "$TMP/export1.csv")" 1
case "$(head_of Content-Disposition)" in attachment*) ok "export is a download";; *) fail "export is a download" "$(head_of Content-Disposition)";; esac
# round trip: empty database, import the export, export again
empty_db
is "database is empty again" "$(links)" ""
TOKEN=$(token)
ROWS=$(($(wc -l < "$TMP/export1.csv") - 1))
import "$TMP/export1.csv"
has "round trip: header line is skipped, not rejected" "Import done: $ROWS new, 0 already there, 0 rejected."
req "$BASE/admin/?export"; cp "$TMP/body" "$TMP/export2.csv"
if cmp -s "$TMP/export1.csv" "$TMP/export2.csv"; then ok "round trip: export -> import -> export is identical"; else fail "round trip" "files differ"; fi
# the same file without the header line: the first line is a link and must not be skipped
sed 1d "$TMP/export1.csv" > "$TMP/nohead.csv"
empty_db
req "$BASE/admin/"; TOKEN=$(token)
import "$TMP/nohead.csv"
has "import without header line: first line counts" "Import done: $ROWS new, 0 already there, 0 rejected."
req "$BASE/admin/?export"; cp "$TMP/body" "$TMP/export3.csv"
if cmp -s "$TMP/export1.csv" "$TMP/export3.csv"; then ok "import without header line gives the same export"; else fail "import without header line" "files differ"; fi
# fewer columns, and a line break inside a quoted title
printf '%s\n' 'short-1,https://example.org/short' '"multi-1",https://example.org/multi,"line one' 'line two"' > "$TMP/few.csv"
import "$TMP/few.csv"
has "import with two and three columns" 'Import done: 2 new, 0 already there, 0 rejected.'
req "$BASE/admin/?export"
is "missing title is empty, missing date is today" "$(grep -c "^short-1,https://example.org/short,,\"$(date -u +%Y-%m-%d) " "$TMP/body")" 1
is "line break in a title becomes a space" "$(grep -c '^multi-1,https://example.org/multi,"line one line two",' "$TMP/body")" 1

if [ -n "$LINKS_CSV" ]; then
  echo "--- your own CSV (only counts are printed)"
  WANT=${LINKS_COUNT:-$(($(wc -l < "$LINKS_CSV") - 1))}
  empty_db
  req "$BASE/admin/"; TOKEN=$(token)
  import "$LINKS_CSV"
  has "own CSV: $WANT new, 0 rejected" "Import done: $WANT new, 0 already there, 0 rejected."
  is "own CSV: $WANT links in the list" "$(links)" "$WANT"
  req "$BASE/admin/?export"; cp "$TMP/body" "$TMP/own.csv"
  is "own CSV: export has $WANT lines plus header" "$(($(wc -l < "$TMP/own.csv")))" "$((WANT + 1))"
  code=$(sed -n 2p "$TMP/own.csv" | cut -d, -f1)
  req "$BASE/$code"
  case "$CODE $(head_of Location)" in "301 $FALLBACK"*|"301 ") fail "own CSV: first code redirects" "no target";; 301*) ok "own CSV: first code redirects to its target";; *) fail "own CSV: first code redirects" "$CODE";; esac
  import "$LINKS_CSV"
  has "own CSV: second import adds nothing" "Import done: 0 new, $WANT already there, 0 rejected."
fi

echo "--- locks"
locked /config.php
locked /config.example.php
locked /lib.php
locked /lang/en.php
locked /data/links.sqlite
locked /links.sqlite
locked /.git/config
locked /.env
locked /public/index.php
locked /index.php
locked /deploy/test-config.php
locked /admin/index.php
locked /admin/x
locked /admin/..%2Fconfig.php
locked /x%2F..%2Fadmin/
locked /x/../admin/ --path-as-is
locked /%61dmin/
locked //admin/
locked /admin//
locked '/admin/%3Fexport'
req "$BASE/x%2F..%2Fadmin/"; is "/x%2F..%2Fadmin/ -> 404" "$CODE" 404
req "$BASE/admin"; is "/admin -> 301 /admin/" "$CODE $(head_of Location)" "301 /admin/"
redirect '/a/b?c=d' "$FALLBACK/a/b?c=d"
redirect /ABC "$FALLBACK/ABC"
redirect //evil.example/ "$FALLBACK//evil.example/"
req -X POST "$BASE/test-1"; is "POST to a short link also only redirects" "$CODE" 301

echo "--- no fallback_url: 404"
# deploy/test-config.php leaves fallback_url out (unset) or empty when these files exist
for mode in unset empty; do
  in_php touch "/data/fallback-$mode"
  for p in /nothing-here / '/a/b?c=d' /ABC; do
    req "$BASE$p"
    is "fallback $mode: $p -> 404, no Location" "$CODE $(head_of Location)" "404 "
  done
  has "fallback $mode: 404 has a short text" '404 - there is no short link here.'
  is "fallback $mode: 404 is plain text" "$(head_of Content-Type)" 'text/plain; charset=utf-8'
  redirect /test-1 'https://example.org/target?a=1&b=2'
  req "$BASE/admin/"; is "fallback $mode: admin still works" "$CODE" 200
  in_php rm "/data/fallback-$mode"
done
redirect /nothing-here "$FALLBACK/nothing-here"

echo "--- headers"
for p in / /test-1 /admin/ /admin/x; do
  req "$BASE$p"
  is "$p Strict-Transport-Security" "$(head_of Strict-Transport-Security)" 'max-age=31536000'
  is "$p X-Content-Type-Options" "$(head_of X-Content-Type-Options)" nosniff
  is "$p Referrer-Policy" "$(head_of Referrer-Policy)" no-referrer
  is "$p X-Frame-Options" "$(head_of X-Frame-Options)" DENY
done
req "$BASE/test-1"
is "redirect sets no cookie" "$(head_of Set-Cookie)" ""
is "redirect has an empty body" "$(wc -c < "$TMP/body" | tr -d ' ')" 0
req "$BASE/admin/"
is "admin Cache-Control" "$(head_of Cache-Control)" no-store
is "admin X-Robots-Tag" "$(head_of X-Robots-Tag)" 'noindex, nofollow'
case "$(head_of Content-Security-Policy)" in "default-src 'none'; style-src 'nonce-"*"; script-src 'nonce-"*"; form-action 'self'; base-uri 'none'; frame-ancestors 'none'") ok "admin Content-Security-Policy";; *) fail "admin Content-Security-Policy" "$(head_of Content-Security-Policy)";; esac
is "no server version in the headers" "$(head_of Server)$(head_of X-Powered-By)" nginx

echo "--- logout"
TOKEN=$(token); OLD=$COOKIE
post --data-urlencode action=logout
hasnot "after logout the link form is gone" 'name="url"'
COOKIE=$OLD
req "$BASE/admin/"; hasnot "the old session no longer works" 'name="url"'

echo "--- broken database"
in_php sh -c 'echo "this is not a database" > /data/links.sqlite'
req "$BASE/test-1"
is "broken database -> 503, no Location" "$CODE $(head_of Location)" "503 "
req "$BASE/admin/"
is "broken database: admin -> 503" "$CODE" 503
redirect / "$FALLBACK/"

echo "--- bin/changelog.sh (in a throwaway repository)"
(
  mkdir "$TMP/repo" && cd "$TMP/repo" && git init -q -b main . || exit 1
  commit() { echo "$1" >> file; git add file; GIT_AUTHOR_DATE="$2T12:00:00" GIT_COMMITTER_DATE="$2T12:00:00" \
    git -c user.name=test -c user.email=test@example.org -c commit.gpgsign=false commit -q -m "$1"; }
  echo "written by hand" > CHANGELOG.md
  sh "$HERE/../bin/changelog.sh" 2>/dev/null; echo "$? $(cat CHANGELOG.md)" > "$TMP/cl-empty"
  commit "chore: first commit" 2026-01-01; commit "feat: add a thing" 2026-01-02; git tag v0.1.0
  commit "fix: the thing" 2026-02-01; commit "chore: update changelog [skip ci]" 2026-02-01; git tag v0.2.0; git tag not-a-version
  sh "$HERE/../bin/changelog.sh" && cp CHANGELOG.md "$TMP/cl-tagged"
  commit "feat!: newest change" 2026-03-01
  sh "$HERE/../bin/changelog.sh" && cp CHANGELOG.md "$TMP/cl-unreleased"
  sh "$HERE/../bin/changelog.sh" v0.3.0 && cp CHANGELOG.md "$TMP/cl-release"
) >/dev/null
TAGGED='# changelog

## v0.2.0 - 2026-02-01

- fix: the thing

## v0.1.0 - 2026-01-02

- feat: add a thing
- chore: first commit'
is "changelog: no commits -> exit 1, file untouched" "$(cat "$TMP/cl-empty")" "1 written by hand"
is "changelog: one section per version tag, newest first, without its own commits" "$(cat "$TMP/cl-tagged")" "$TAGGED"
is "changelog: commits after the last tag are Unreleased" "$(cat "$TMP/cl-unreleased")" "$(printf '# changelog\n\n## Unreleased\n\n- feat!: newest change\n'; echo "$TAGGED" | sed 1d)"
is "changelog: with a version they get that name and today" "$(sed -n 3p "$TMP/cl-release")" "## v0.3.0 - $(date +%Y-%m-%d)"

echo
if [ "$FAILED" = 0 ]; then echo "ALL OK ($CHECKS checks)"; else echo "$FAILED of $CHECKS checks FAILED"; fi
[ "$FAILED" = 0 ]
