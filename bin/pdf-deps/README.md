# bin/pdf-deps - PDF dependencies (dompdf) with prefixed namespaces

`vendor-prefixed/` in the project root is a build artifact committed to the repo / included in the plugin ZIP.
It contains dompdf 3.x + its dependencies, all namespaces rewritten to `Bginfo\PmzhDvzh\Vendor\...`,
so they do not clash with other plugins that bundle dompdf.

Why PHP-Scoper and not Strauss: Strauss 0.22-0.31 crashes on Windows (drive-root path handling bug).
Scoper works on Windows. `scoper.inc.php` also contains patchers for dompdf/php-font-lib code that builds
class names dynamically (`"Dompdf\\FrameDecorator\\$x"`, `'\Dompdf\Positioner\\'`, `FontLib\TrueType\File::getFontType()`).

## Entry point

```php
require PLUGIN_DIR . 'vendor-prefixed/autoload.php';
$dompdf = new \Bginfo\PmzhDvzh\Vendor\Dompdf\Dompdf( $options ); // $options = new \Bginfo\PmzhDvzh\Vendor\Dompdf\Options()
```

Set `fontCache` / `tempDir` to a writable dir (e.g. in `wp-content/uploads`), `chroot` to the plugin dir,
`isRemoteEnabled` = false, `defaultFont` = `DejaVu Sans` (supports Cyrillic; fonts live in
`vendor-prefixed/dompdf/dompdf/lib/fonts`).

## Rebuild

Tools are in `.tools/` (gitignored): `composer.phar`, `php-scoper.phar` (https://github.com/humbug/php-scoper/releases).
From Git Bash, project root:

```bash
export PATH=$PATH:/c/php
mkdir -p .tools/pdfbuild && cp bin/pdf-deps/{composer.json,scoper.inc.php,build.sh} .tools/pdfbuild/
cd .tools/pdfbuild
bash build.sh "$(cd .. && pwd)"            # -> .tools/pdfbuild/vendor-prefixed
rm -rf ../../vendor-prefixed && cp -r vendor-prefixed ../../vendor-prefixed
cd ../..
php bin/pdf-deps/smoke-test.php vendor-prefixed .tools/test.pdf   # expect: class name + "OK <bytes>"
```

`composer.json` pins `platform.php` = 8.1.0, so dependencies stay compatible with PHP 8.1.
To update dompdf: change the constraint in `composer.json`, rebuild, run the smoke test (Cyrillic, table, link).

Note: on 32-bit PHP (the local C:\php build) php-font-lib prints "float ... not representable as an int"
warnings while embedding fonts; this does not happen on 64-bit PHP (servers). Keep `display_errors` off.
