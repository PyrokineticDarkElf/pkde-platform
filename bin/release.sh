#!/usr/bin/env bash
#
# Cuts a release of this package: the ONLY way a tag is made (the account is on GitHub's free plan, so there is no branch protection;
# this script is the guard). A tag is a dated version, YYYY.MM.DD, with .1 .2 ... for a second release the same day.
#
#   bin/release.sh 2026.10.21
#
# It refuses unless: the tag is well formed and new; the working tree is clean; you are on main and level with origin/main; CHANGELOG.md
# has a "## <tag>" section; and CI is green for HEAD. Then it tags HEAD and pushes the tag. Nothing is built or published elsewhere:
# Composer reads the tag from GitHub.
set -euo pipefail

TAG="${1:-}"
[[ "$TAG" =~ ^20[0-9]{2}\.(0[1-9]|1[0-2])\.(0[1-9]|[12][0-9]|3[01])(\.[0-9]+)?$ ]] || { echo "usage: bin/release.sh YYYY.MM.DD[.n]" >&2; exit 2; }
cd "$(dirname "$0")/.."

[ -z "$(git status --porcelain)" ] || { echo "the working tree is not clean" >&2; exit 1; }
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || { echo "not on main" >&2; exit 1; }
git fetch -q origin --tags
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || { echo "main is not level with origin/main (push or pull first)" >&2; exit 1; }
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null && { echo "tag $TAG already exists" >&2; exit 1; }
grep -q "^## $TAG\$" CHANGELOG.md || { echo "CHANGELOG.md has no '## $TAG' section" >&2; exit 1; }

SHA="$(git rev-parse HEAD)"
STATE="$(gh run list --branch main --commit "$SHA" --limit 1 --json status,conclusion -q '.[0] | "\(.status) \(.conclusion)"' 2>/dev/null || true)"
[ "$STATE" = "completed success" ] || { echo "CI is not green for ${SHA:0:7} (got: ${STATE:-no run})" >&2; exit 1; }

git tag -a "$TAG" -m "$TAG"
git push origin "$TAG"
echo "Released $TAG at ${SHA:0:7}"
