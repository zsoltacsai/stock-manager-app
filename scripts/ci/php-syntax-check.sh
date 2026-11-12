#!/usr/bin/env bash
# Minden git által követett PHP fájl szintaxis-ellenőrzése.
# Használat: bash scripts/ci/php-syntax-check.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

mapfile -t files < <(git ls-files '*.php')

if [ "${#files[@]}" -eq 0 ]; then
  echo "::error::Nem található egyetlen követett PHP fájl sem."
  exit 1
fi

failed=0
for file in "${files[@]}"; do
  if ! output=$(php -l "$file" 2>&1); then
    echo "::error file=${file}::${output}"
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  echo "::error::PHP szintaxis-hiba található. Javítsd a fenti fájlokat."
  exit 1
fi

echo "PHP szintaxis: OK (${#files[@]} fájl)."
