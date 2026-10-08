#!/usr/bin/env bash
# Rebuild vendor-prefixed (dompdf prefixed with PHP-Scoper).
# Usage: bash build.sh <path-to-.tools-dir>   (run from the directory holding composer.json)
set -euo pipefail
TOOLS="${1:?path to .tools dir required}"
PHP="${PHP:-php}"
rm -rf vendor build vendor-prefixed composer.lock
"$PHP" "$TOOLS/composer.phar" install --no-dev --no-interaction --no-progress
"$PHP" "$TOOLS/php-scoper.phar" add-prefix --output-dir=build --force --no-interaction
(cd build && "$PHP" "$TOOLS/composer.phar" dump-autoload --no-dev --classmap-authoritative)
mv build/vendor vendor-prefixed
rm -rf build
echo "vendor-prefixed ready: $(du -sh vendor-prefixed | cut -f1)"
