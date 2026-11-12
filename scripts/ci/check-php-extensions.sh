#!/usr/bin/env bash
# Kötelező PHP kiterjesztések ellenőrzése a CI teszt-környezetben.
#
# A lista forrása a termék saját követelmény-listája:
#   - install.txt      (kézi Windows-telepítés, php.ini kapcsolók)
#   - webroot/install.php ($requiredExtensions, 9 elem)
# Ha a kettő változik, ezt a listát is frissíteni kell.
#
# Miért van rá szükség: a GitHub Actions PHP-jában a fileinfo/xmlwriter
# alapból KI van kapcsolva. Enélkül a tesztek nem "hiányzó kiterjesztés"
# hibát adnak, hanem a termékkód 500-as válaszát, ami félrevezető
# (3 teszt bukott el így a M0 infrastruktúra első futásainál).
#
# Használat: bash scripts/ci/check-php-extensions.sh
set -euo pipefail

REQUIRED_EXTENSIONS=(curl xmlwriter fileinfo mbstring gd zip openssl sqlite3 pdo_sqlite)

missing=()
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
  if ! php -r "exit(extension_loaded('${ext}') ? 0 : 1);"; then
    missing+=("$ext")
  fi
done

if [ "${#missing[@]}" -gt 0 ]; then
  echo "::error::Hiányzó kötelező PHP kiterjesztés: ${missing[*]}"
  echo "A termék ezeket igényli (install.txt / webroot/install.php)."
  echo "CI-ban a shivammathur/setup-php 'extensions:' beállítását kell bővíteni."
  php -m || true
  exit 1
fi

echo "PHP kiterjesztések: mind a ${#REQUIRED_EXTENSIONS[@]} kötelező kiterjesztés elérhető."
