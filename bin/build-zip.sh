#!/usr/bin/env bash
# Build a Composer-installable zip of the module (no tests/dev/docs: see .gitattributes).
# Usage: bin/build-zip.sh 0.1.0
set -euo pipefail
version="${1:?usage: bin/build-zip.sh <version>}"
root="$(CDPATH= cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$root/dist"
out="$root/dist/answereng-magento-$version.zip"
git -C "$root" archive --format=zip -o "$out" HEAD
echo "$out"
