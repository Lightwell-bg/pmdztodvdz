<?php
/**
 * PHP-Scoper config: prefix dompdf and its dependencies for the bginfo-pmzh-dvzh plugin.
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'Bginfo\\PmzhDvzh\\Vendor',
    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE.*|.*\\.md|composer\\.lock|phpunit\\.xml.*|\\.gitignore|\\.gitattributes/')
            ->exclude(['test', 'tests', 'Tests', 'doc', 'docs', 'bin', 'examples', 'phpunit'])
            ->in('vendor'),
        Finder::create()->files()->name('/^LICENSE.*$/')->in('vendor'),
        Finder::create()->append(['composer.json']),
    ],
    'patchers' => [
        /*
         * dompdf / php-font-lib build class names dynamically ("Dompdf\\FrameDecorator\\$name"),
         * which PHP-Scoper cannot rewrite. Prefix those double-quoted strings by hand.
         */
        static function (string $filePath, string $prefix, string $contents): string {
            if (!str_ends_with($filePath, '.php')) {
                return $contents;
            }
            // $prefix has single backslashes; in PHP source string literals they are doubled.
            $escapedPrefix = str_replace('\\', '\\\\', $prefix);
            foreach (['Dompdf', 'FontLib'] as $ns) {
                foreach (['"', "'", "'\\\\", '"\\\\'] as $lead) {
                    $contents = str_replace(
                        $lead . $ns . '\\\\',
                        $lead . $escapedPrefix . '\\\\' . $ns . '\\\\',
                        $contents
                    );
                }
                // Pretty-printed single-quoted form: '\Dompdf\Positioner\\' (one backslash between parts).
                $contents = str_replace(
                    "'\\" . $ns . '\\',
                    "'\\" . $prefix . '\\' . $ns . '\\',
                    $contents
                );
            }
            return $contents;
        },
        /*
         * FontLib\TrueType\File::getFontType() takes the 2nd segment of the class name
         * ("FontLib\TrueType\File" -> "TrueType"). With a longer namespace prefix the
         * index shifts, so take the segment before the class short name instead.
         */
        /*
         * FontLib\Font::load() uses relative names ("TrueType\\File") that PHP-Scoper wrongly
         * prefixes as if they were global classes; they are completed with "FontLib\\" at runtime.
         */
        static function (string $filePath, string $prefix, string $contents): string {
            if (!str_ends_with(str_replace('\\', '/', $filePath), 'FontLib/Font.php')) {
                return $contents;
            }
            $escapedPrefix = str_replace('\\', '\\\\', $prefix);
            foreach (['TrueType', 'OpenType', 'WOFF', 'EOT'] as $type) {
                $contents = str_replace(
                    '"' . $escapedPrefix . '\\\\' . $type . '\\\\',
                    '"' . $type . '\\\\',
                    $contents
                );
            }
            return $contents;
        },
        static function (string $filePath, string $prefix, string $contents): string {
            if (!str_ends_with(str_replace('\\', '/', $filePath), 'FontLib/TrueType/File.php')) {
                return $contents;
            }
            return str_replace(
                'return $class_parts[1];',
                'return $class_parts[count($class_parts) - 2];',
                $contents
            );
        },
    ],
    'exclude-namespaces' => [],
    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
];
