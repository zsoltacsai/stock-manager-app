#!/usr/bin/env bash
# Gitleaks letöltése rögzített verzióval, ellenőrzőösszeggel.
# Csak Linux x64 (a CI runner).
#
# Használat: bash scripts/ci/fetch-gitleaks.sh
# Felülírható: GITLEAKS_VERSION, GITLEAKS_SHA256
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

version="${GITLEAKS_VERSION:-8.30.1}"
expected="${GITLEAKS_SHA256:-551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb}"
# A binárist szándékosan a repón KÍVÜLre tesszük, hogy a szkennelés ne
# lássa saját magát. CI-ben: $RUNNER_TEMP, helyben: tools/ (gitignore-olt).
target="${GITLEAKS_BIN:-${RUNNER_TEMP:-$PWD/tools}/gitleaks}"

mkdir -p "$(dirname "$target")"

if [ -x "$target" ] && [ "$("$target" version 2>/dev/null || true)" = "$version" ]; then
  echo "Gitleaks ${version} már a helyén van."
  exit 0
fi

tmp_dir=$(mktemp -d)
trap 'rm -rf "$tmp_dir"' EXIT

archive="${tmp_dir}/gitleaks.tar.gz"
curl -fsSL --retry 5 --retry-delay 2 \
  -o "$archive" \
  "https://github.com/gitleaks/gitleaks/releases/download/v${version}/gitleaks_${version}_linux_x64.tar.gz"

actual=$(sha256sum "$archive" | cut -d' ' -f1)
if [ "$actual" != "$expected" ]; then
  echo "::error::A letöltött Gitleaks ellenőrzőösszege nem egyezik (várt: ${expected}, kapott: ${actual})."
  exit 1
fi

tar -xzf "$archive" -C "$tmp_dir" gitleaks
install -m 0755 "${tmp_dir}/gitleaks" "$target"
"$target" version
