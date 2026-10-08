#!/usr/bin/env bash
# Build a Composer-installable zip of the module (no tests/dev/docs: see .gitattributes).
# Usage: bin/build-zip.sh 0.1.0
# Archives the committed HEAD (commit first). Composer's artifact repository needs a "version" inside
# the zip's composer.json; the repo's composer.json must not carry one (`composer validate --strict`
# rejects it), so the version is stamped into the archived copy only.
set -euo pipefail
version="${1:?usage: bin/build-zip.sh <version>}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
  echo "invalid version '$version' (expected semver such as 0.1.0 or 0.1.0-rc.1)" >&2
  exit 1
fi
root="$(CDPATH= cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$root/dist"
out="$root/dist/answereng-magento-$version.zip"
tmp="$(mktemp -d)"
trap '/bin/rm -rf "$tmp"' EXIT

git -C "$root" archive --format=tar HEAD | tar -x -C "$tmp"

# Insert "version" on the line after "name" (text edit: the rest of the file stays byte-identical),
# then check the result still decodes and carries exactly that version.
php -r '
$file = $argv[1];
$text = file_get_contents($file);
$data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
if (array_key_exists("version", $data)) {
    fwrite(STDERR, "composer.json already has a version field\n");
    exit(1);
}
$text = preg_replace("/^(\\s*)(\"name\"\\s*:\\s*\"[^\"]*\",)\\R/m", "$1$2\n$1\"version\": \"" . $argv[2] . "\",\n", $text, 1, $count);
$data = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);
if ($count !== 1 || ($data["version"] ?? null) !== $argv[2]) {
    fwrite(STDERR, "could not stamp the version into composer.json\n");
    exit(1);
}
file_put_contents($file, $text);
' "$tmp/composer.json" "$version"

/bin/rm -f "$out"
(cd "$tmp" && zip -qrX "$out" .)
echo "$out"
