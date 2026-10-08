<?php
/**
 * Загрузчик тестов: PSR-4 для кода плагина и самих тестов.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class ): void {
		$map = array(
			'Bginfo\\PmzhDvzh\\Tests\\' => __DIR__ . '/',
			'Bginfo\\PmzhDvzh\\'        => dirname( __DIR__ ) . '/src/',
		);

		foreach ( $map as $prefix => $dir ) {
			if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
				continue;
			}

			$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}

			return;
		}
	}
);

if ( is_readable( __DIR__ . '/stubs.php' ) ) {
	require_once __DIR__ . '/stubs.php';
}
