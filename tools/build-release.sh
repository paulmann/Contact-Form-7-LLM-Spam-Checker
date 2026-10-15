#!/usr/bin/env bash
# Build the WordPress.org / GitHub release ZIP (runtime files only).
# Usage: bash tools/build-release.sh [version]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(grep -oP "define\('CF7SEC_VERSION', '\K[^']+" "$ROOT/contact-form-7-spam-checker.php")}"
SLUG="contact-form-7-spam-checker"
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
cp "$ROOT/contact-form-7-spam-checker.php" "$ROOT/uninstall.php" "$ROOT/index.php" \
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
if command -v zip >/dev/null 2>&1; then
    zip -qr "$ZIP" "$SLUG"
else
    python3 - "$SLUG" "$ZIP" <<'PY'
import os, sys, zipfile
slug, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(slug):
        dirs.sort(); files.sort()
        for f in files:
            p = os.path.join(root, f)
            z.write(p, p)
PY
fi

rm -rf "$STAGE"
echo "→ Release ready: dist/$ZIP ($(du -h "$DIST/$ZIP" | cut -f1))"
