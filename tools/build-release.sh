#!/usr/bin/env bash
# Build the WordPress.org / GitHub release ZIP (runtime files only).
# Usage: bash tools/build-release.sh [version]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="spam-checker-for-contact-form-7"
# The plugin bootstrap is the root PHP file that defines CF7SEC_VERSION.
# Discovered rather than hardcoded so a slug/filename change cannot silently
# break the release build.
MAIN_FILE="$(basename "$(grep -l "define('CF7SEC_VERSION'" "$ROOT"/*.php | head -n1)")"
if [ -z "$MAIN_FILE" ] || [ ! -f "$ROOT/$MAIN_FILE" ]; then
    echo "✗ cannot locate the plugin bootstrap (no root *.php defines CF7SEC_VERSION)" >&2
    exit 1
fi
VERSION="${1:-$(grep -oP "define\('CF7SEC_VERSION', '\K[^']+" "$ROOT/$MAIN_FILE")}"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"

echo "→ Building $SLUG v$VERSION"

# 1. Rebuild assets
if command -v npx >/dev/null 2>&1 && [ -x "$ROOT/node_modules/.bin/tailwindcss" ]; then
    (cd "$ROOT" && npx tailwindcss -c tailwind.config.js -i assets/src/input.css -o assets/dist/admin.css --minify)
    echo "  ✓ CSS rebuilt"
else
    echo "  • tailwindcss not installed locally — using committed assets/dist/admin.css"
fi
mkdir -p "$ROOT/assets/dist"
cp "$ROOT/assets/src/admin.js" "$ROOT/assets/dist/admin.js"
echo "  ✓ JS synced"

# 2. Lint PHP (if php available)
if command -v php >/dev/null 2>&1; then
    find "$ROOT/src" "$ROOT" -maxdepth 1 -name '*.php' -print0 | while IFS= read -r -d '' f; do php -l "$f" >/dev/null; done
    find "$ROOT/src" -name '*.php' -print0 | while IFS= read -r -d '' f; do php -l "$f" >/dev/null; done
    echo "  ✓ PHP lint OK"
fi

# 3. Stage runtime files
rm -rf "$STAGE"
mkdir -p "$STAGE"
cp "$ROOT/$MAIN_FILE" "$ROOT/uninstall.php" "$ROOT/index.php" \
   "$ROOT/readme.txt" "$ROOT/README.md" "$ROOT/README.ru.md" "$ROOT/CHANGELOG.md" "$ROOT/LICENSE.txt" "$STAGE/"
cp -r "$ROOT/src" "$STAGE/src"
cp -r "$ROOT/languages" "$STAGE/languages"
mkdir -p "$STAGE/assets/dist"
cp "$ROOT/assets/dist/admin.css" "$ROOT/assets/dist/admin.js" "$STAGE/assets/dist/"
mkdir -p "$STAGE/docs/screenshots"
cp "$ROOT/docs/ARCHITECTURE.md" "$STAGE/docs/"
cp "$ROOT"/docs/screenshots/*.png "$STAGE/docs/screenshots/" 2>/dev/null || true

# directory guards
for d in src assets assets/dist languages docs; do
    [ -f "$STAGE/$d/index.php" ] || printf '<?php\n// Silence is golden.\n' > "$STAGE/$d/index.php"
done

# 4. Zip (folder at archive root, as WP expects)
cd "$DIST"
ZIP="$SLUG-$VERSION.zip"
rm -f "$ZIP"

# Prefer `zip`; otherwise fall back to a Python that actually runs — on Windows
# `python3` is often the Microsoft Store stub, and `python`/`py` may be the real
# interpreter, so each candidate is probed instead of assumed.
PY_BIN=""
for candidate in python3 python py; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c 'import sys' >/dev/null 2>&1; then
        PY_BIN="$candidate"
        break
    fi
done

if command -v zip >/dev/null 2>&1; then
    zip -qr "$ZIP" "$SLUG"
elif [ -n "$PY_BIN" ]; then
    "$PY_BIN" - "$SLUG" "$ZIP" <<'PY'
import os, sys, zipfile
slug, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(slug):
        dirs.sort(); files.sort()
        for f in files:
            p = os.path.join(root, f)
            z.write(p, p)
PY
else
    echo "✗ no zip tool and no working python interpreter found" >&2
    exit 1
fi

rm -rf "$STAGE"
echo "→ Release ready: dist/$ZIP ($(du -h "$DIST/$ZIP" | cut -f1))"
