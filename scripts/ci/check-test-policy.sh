#!/usr/bin/env bash
# Teszt-politika: termékkód-változáshoz tartozzon teszt-változás is.
#
# A szabály csak pull requestre érvényes. Kivétel: ha a PR leírása
# tartalmazza a [skip-test-policy] jelölést (indoklással együtt).
#
# Használat:
#   BASE_SHA=<base commit sha> PR_BODY="<pr leírás>" bash scripts/ci/check-test-policy.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ -z "${BASE_SHA:-}" ]; then
  echo "::error::BASE_SHA környezeti változó hiányzik."
  exit 1
fi

if ! git cat-file -e "${BASE_SHA}^{commit}" 2>/dev/null; then
  echo "::error::A BASE_SHA (${BASE_SHA}) nem érhető el a checkoutban. Szükséges: fetch-depth: 0."
  exit 1
fi

mapfile -t changed < <(git diff --name-only "$BASE_SHA" HEAD)

prod=()
tests_changed=0

for file in "${changed[@]}"; do
  [ -z "$file" ] && continue
  case "$file" in
    tests/*)
      tests_changed=1
      ;;
    src/*|webroot/api/*|config/*|install-windows.ps1|install-windows-lib.ps1|schema.sql|schema.mysql.sql)
      prod+=("$file")
      ;;
  esac
done

echo "Változott fájlok: ${#changed[@]}; termékkód: ${#prod[@]}; teszt-változás: ${tests_changed}"

if [ "${#prod[@]}" -eq 0 ]; then
  echo "Nincs termékkód-változás — a teszt-politika nem alkalmazandó."
  exit 0
fi

if [ "$tests_changed" -eq 1 ]; then
  echo "Termékkód-változáshoz tartozik teszt-változás is."
  exit 0
fi

if printf '%s' "${PR_BODY:-}" | grep -qF '[skip-test-policy]'; then
  echo "::warning::A PR tartalmazza a [skip-test-policy] jelölést — a teszt-politika ellenőrzés kihagyva. Az indoklásnak a PR leírásában kell szerepelnie."
  exit 0
fi

echo "::error::Termékkód változott, de egyetlen teszt sem (tests/)."
echo "Adj hozzá vagy módosíts tesztet, illetve indokold a kivételt a PR leírásában a [skip-test-policy] jelöléssel."
printf 'Érintett termékkód-fájlok:\n'
printf '  %s\n' "${prod[@]}"
exit 1
