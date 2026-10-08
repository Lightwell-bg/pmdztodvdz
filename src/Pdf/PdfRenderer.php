<?php
/**
 * HTML → PDF через Dompdf с префиксом пространства имён (vendor-prefixed).
 *
 * Каталог кэша шрифтов находится в uploads и хранит ТОЛЬКО метрики
 * шрифтов, никогда документы пользователей. Удаление/замена папки плагина
 * кэш не затрагивает (в кэше нет ничего, что нельзя пересоздать).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Pdf;

final class PdfRenderer {

	public const CACHE_DIRNAME = 'bginfo-pmzh-dvzh-cache';

	/**
	 * Строит PDF.
	 *
	 * @throws \RuntimeException Если зависимости недоступны или рендер не удался.
	 */
	public function render( string $html ): string {
		$autoload = BGINFO_PMZH_DVZH_DIR . 'vendor-prefixed/autoload.php';

		if ( ! is_readable( $autoload ) ) {
			throw new \RuntimeException( 'PDF dependencies are missing' );
		}

		require_once $autoload;

		$dompdfClass  = 'Bginfo\\PmzhDvzh\\Vendor\\Dompdf\\Dompdf';
		$optionsClass = 'Bginfo\\PmzhDvzh\\Vendor\\Dompdf\\Options';

		if ( ! class_exists( $dompdfClass ) || ! class_exists( $optionsClass ) ) {
			throw new \RuntimeException( 'Dompdf class not found' );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$cache = self::cacheDir();

		$options = new $optionsClass();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setFontDir( BGINFO_PMZH_DVZH_DIR . 'vendor-prefixed/dompdf/dompdf/lib/fonts' );
		$options->setFontCache( $cache );
		$options->setTempDir( $cache );
		$options->setChroot( rtrim( BGINFO_PMZH_DVZH_DIR, '/\\' ) );

		$dompdf = new $dompdfClass( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		// Нумерация страниц рисуется средствами canvas (PHP в HTML отключён).
		$canvas = $dompdf->getCanvas();
		$font   = $dompdf->getFontMetrics()->getFont( 'DejaVu Sans', 'normal' );

		if ( null !== $font ) {
			$canvas->page_text( $canvas->get_width() - 110, $canvas->get_height() - 28, 'Стр. {PAGE_NUM} из {PAGE_COUNT}', $font, 8, array( 0.3, 0.3, 0.3 ) );
		}

		$output = $dompdf->output();

		if ( ! is_string( $output ) || '' === $output ) {
			throw new \RuntimeException( 'Empty PDF output' );
		}

		return $output;
	}

	/**
	 * Записываемый каталог кэша метрик шрифтов (со страховкой от листинга и
	 * прямого доступа). Фолбэк — временный каталог.
	 */
	public static function cacheDir(): string {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$base    = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		if ( '' !== $base ) {
			$dir = rtrim( $base, '/\\' ) . '/' . self::CACHE_DIRNAME;

			if ( self::prepareDir( $dir ) ) {
				return $dir;
			}
		}

		$tmp = rtrim( function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir(), '/\\' ) . '/' . self::CACHE_DIRNAME;
		self::prepareDir( $tmp );

		return $tmp;
	}

	private static function prepareDir( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ? ! wp_mkdir_p( $dir ) : ! @mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return false;
			}
		}

		if ( ! is_writable( $dir ) ) {
			return false;
		}

		if ( ! is_file( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		if ( ! is_file( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		return true;
	}
}
