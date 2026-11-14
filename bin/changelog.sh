#!/bin/sh
# dalang - writes CHANGELOG.md new from the git history. One line per commit subject,
# one section per version tag (v*, newest first), on top "Unreleased" for the commits after the last tag.
#
#   sh bin/changelog.sh           commits after the last tag stand under "Unreleased"
#   sh bin/changelog.sh v0.2.0    ... stand under "v0.2.0 - <today>": run this right before you tag v0.2.0
#
# Run it in the repository. .github/workflows/changelog.yml runs it on every push to main.
# Hand-made edits in CHANGELOG.md are lost: the commit subjects are the changelog.
# Its own commits ("chore: update changelog") are left out.
set -eu
cd "$(git rev-parse --show-toplevel)"
git rev-parse --verify -q HEAD >/dev/null || { echo "no commits yet, CHANGELOG.md stays as it is" >&2; exit 1; }
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

echo "# changelog" > "$tmp"
title=${1:+"$1 - $(date +%Y-%m-%d)"}
newer=HEAD
# The empty word at the end stands for "everything before the oldest tag".
for tag in $(git tag --list 'v[0-9]*' --merged HEAD --sort=-creatordate) ""; do
  entries=$(git log --no-merges --invert-grep --grep='^chore: update changelog' --format='- %s' ${tag:+"$tag.."}"$newer")
  [ -z "$entries" ] || printf '\n## %s\n\n%s\n' "${title:-Unreleased}" "$entries" >> "$tmp"
  [ -n "$tag" ] || break
  title="$tag - $(git log -1 --format=%as "$tag")"
  newer=$tag
done
cat "$tmp" > CHANGELOG.md
