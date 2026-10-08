<?php
/**
 * Регрессия: закрывающий тег PHP внутри однострочного комментария.
 *
 * `// ... ?> ...` завершает PHP-режим, и остаток файла выводится как HTML.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests;

use PHPUnit\Framework\TestCase;

final class NoCloseTagInCommentsTest extends TestCase {

	/**
	 * Ищет однострочные комментарии (`//`, `#`) с закрывающим тегом.
	 *
	 * Токенайзер обрывает такой комментарий перед `?>` и отдаёт T_CLOSE_TAG,
	 * поэтому ловим T_COMMENT, сразу за которым идёт T_CLOSE_TAG в той же строке.
	 * Исключение: строка вида `<?php // ... ?>` (однострочный сниппет шаблона).
	 *
	 * @param string $code Исходный код PHP.
	 * @return int[] Номера строк с нарушением.
	 */
	public static function findCloseTagInLineComments( string $code ): array {
		$tokens = token_get_all( $code );
		$lines  = explode( "\n", $code );
		$found  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_COMMENT !== $token[0] ) {
				continue;
			}
			$text = $token[1];
			if ( ! str_starts_with( $text, '//' ) && ! str_starts_with( $text, '#' ) ) {
				continue;
			}
			$line = $token[2];

			if ( str_contains( $text, '?>' ) ) {
				$found[] = $line;
				continue;
			}

			$next = $tokens[ $i + 1 ] ?? null;
			if ( ! is_array( $next ) || T_CLOSE_TAG !== $next[0] ) {
				continue;
			}
			if ( preg_match( '/[\r\n]$/', $text ) ) {
				continue;
			}
			$source_line = $lines[ $line - 1 ] ?? '';
			if ( preg_match( '/^\s*<\?php\b/', $source_line ) ) {
				continue;
			}
			$found[] = $line;
		}

		return $found;
	}

	/**
	 * Все *.php проекта, кроме сторонних и служебных каталогов.
	 *
	 * @return string[]
	 */
	private static function projectFiles(): array {
		$root     = dirname( __DIR__ );
		$excluded = array( 'vendor', 'vendor-prefixed', '.tools', 'tests', 'node_modules', '.git', '.phpunit.cache', 'dist' );
		$files    = glob( $root . '/*.php' ) ?: array();

		foreach ( array( 'src', 'bin' ) as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 );
				$parts    = explode( '/', $relative );
				if ( array_intersect( $parts, $excluded ) ) {
					continue;
				}
				$files[] = $file->getPathname();
			}
		}

		$files = array_values( array_unique( $files ) );
		sort( $files );

		return $files;
	}

	public function testDetectorFlagsCloseTagInLineComment(): void {
		$this->assertSame( array( 2 ), self::findCloseTagInLineComments( "<?php\n// a ?> b\n\$x=1;" ) );
		$this->assertSame( array( 2 ), self::findCloseTagInLineComments( "<?php\n# a ?> b\n\$x=1;" ) );
	}

	public function testDetectorAllowsLegitTemplateSnippets(): void {
		$this->assertSame( array(), self::findCloseTagInLineComments( "<p>x</p>\n\t<?php // note ?>\n<b>y</b>\n" ) );
		$this->assertSame( array(), self::findCloseTagInLineComments( "<?php\n// plain comment\n\$x = 1;\n" ) );
	}

	public function testNoCloseTagInLineCommentsInPluginFiles(): void {
		$files = self::projectFiles();
		$this->assertNotEmpty( $files );

		$errors = array();
		foreach ( $files as $file ) {
			foreach ( self::findCloseTagInLineComments( (string) file_get_contents( $file ) ) as $line ) {
				$errors[] = $file . ':' . $line;
			}
		}

		$this->assertSame( array(), $errors, "`?>` в однострочном комментарии:\n" . implode( "\n", $errors ) );
	}
}
