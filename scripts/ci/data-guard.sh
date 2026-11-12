#!/usr/bin/env bash
# Adatvédelmi őr: a teszt-futtatásnak NEM szabad hozzányúlnia a valódi
# futásidejű adatokhoz. A tesztek saját, ideiglenes SQLite fájlokkal
# dolgoznak (tests/bootstrap.php), ezért a data/ és invoices/ mappának
# bitre azonosnak kell maradnia a teszt előtti állapothoz képest.
#
# Ellenőrzi a nem követett ÉS a git-ignore-olt fájlokat is, mert egy
# tévedésből a valódi data/stock.sqlite-ba író teszt épp egy ignore-olt
# fájlt módosítana.
#
# Használat: bash scripts/ci/data-guard.sh
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

status=$(git status --porcelain=v1 --ignored=matching --untracked-files=all -- data invoices)

if [ -n "$status" ]; then
  echo "::error::A teszt-futtatás fájlt hozott létre vagy módosított a data/ vagy invoices/ mappában."
  echo "Ezek a mappák valódi futásidejű adatokat tartalmazhatnak — a teszteknek ideiglenes könyvtárat kell használniuk."
  printf '%s\n' "$status"
  exit 1
fi

echo "Adatvédelmi őr: a data/ és invoices/ mappa érintetlen."
