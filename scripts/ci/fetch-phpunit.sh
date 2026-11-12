#!/usr/bin/env bash
# A projekt szándékosan Composer-mentes: a PHPUnit egyetlen .phar fájlként fut.
# Ez a szkript letölti a rögzített verziót, és SHA-256-tal ellenőrzi.
#
# Használat: bash scripts/ci/fetch-phpunit.sh
# Felülírható: PHPUNIT_VERSION, PHPUNIT_SHA256
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

version="${PHPUNIT_VERSION:-10.5.66}"
expected="${PHPUNIT_SHA256:-42bcac97bbf9fb1aecf5a7d6a1b37123a11e7e295458978f0e268999f6d9e50f}"
target="tools/phpunit.phar"

mkdir -p tools

if [ -f "$target" ]; then
  actual=$(sha256sum "$target" | cut -d' ' -f1)
  if [ "$actual" = "$expected" ]; then
    echo "PHPUnit ${version} már a helyén van (ellenőrzőösszeg egyezik)."
    exit 0
  fi
  echo "A meglévő $target ellenőrzőösszege eltér, újratöltés."
fi

tmp="${target}.tmp"
rm -f "$tmp"
curl -fsSL --retry 5 --retry-delay 2 \
  -o "$tmp" "https://phar.phpunit.de/phpunit-${version}.phar"

actual=$(sha256sum "$tmp" | cut -d' ' -f1)
if [ "$actual" != "$expected" ]; then
  rm -f "$tmp"
  echo "::error::A letöltött PHPUnit ellenőrzőösszege nem egyezik (várt: ${expected}, kapott: ${actual})."
  exit 1
fi

mv "$tmp" "$target"
php "$target" --version
