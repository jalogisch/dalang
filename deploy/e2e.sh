#!/bin/sh
# dalang - browser test. Starts nginx and php-fpm (deploy/compose.yml) and a Playwright container
# next to them. A real Chromium logs in at /admin/, creates links through the form (own code, random
# code, prefilled like the bookmarklet), reads the short link from the page and follows it.
# Then everything is stopped and removed.
#
#   sh deploy/e2e.sh        needs podman (or ENGINE=docker) and internet for the first run:
#                           the Playwright image is about 2.5 GB, the npm package is fetched every run
#
# Prints OK or FAIL per check. Exit code 1 if anything failed. deploy/test.sh is the bigger test (curl).
ENGINE=${ENGINE:-podman}
BASE=http://127.0.0.1:8082
HERE=$(cd "$(dirname "$0")" && pwd)
DATA="$HERE/.testdaten"

cleanup() {
  "$ENGINE" compose -f "$HERE/compose.yml" --profile e2e down >/dev/null 2>&1
  rm -rf "$DATA"
}
trap cleanup EXIT

cleanup
mkdir -p "$DATA/data" && chmod 777 "$DATA/data"
"$ENGINE" compose -f "$HERE/compose.yml" up -d >/dev/null || { echo "could not start the containers"; exit 2; }
i=0
until [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = 301 ]; do
  i=$((i + 1)); [ "$i" -gt 30 ] && { echo "the test server did not come up"; exit 2; }
  sleep 1
done
"$ENGINE" compose -f "$HERE/compose.yml" --profile e2e run --rm e2e
