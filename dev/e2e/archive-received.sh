#!/usr/bin/env bash
# Move the mock engine's received payloads into $MAGENTO_LOCAL/received-archive/<label>/ so each checklist row
# starts empty.
set -euo pipefail
label="${1:?label}"
ml="${MAGENTO_LOCAL:-/Volumes/Tukar/Code/answer-eng/magento-local}"
repo="$(CDPATH= cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$ml/received-archive/$label"
shopt -s nullglob
files=("$repo"/dev/mock-engine/received/*.json)
if [ ${#files[@]} -gt 0 ]; then mv "${files[@]}" "$ml/received-archive/$label/"; fi
echo "archived ${#files[@]} to $label"
