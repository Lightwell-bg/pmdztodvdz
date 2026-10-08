<?php
/**
 * Минимальные заглушки функций WordPress для юнит-тестов без WordPress.
 * Подключается из tests/bootstrap.php. Каждая функция объявляется только
 * если её ещё нет.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
) as $bgdvzh_const => $bgdvzh_value ) {
	if ( ! defined( $bgdvzh_const ) ) {
		define( $bgdvzh_const, $bgdvzh_value );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/** Как в WordPress: разрешены только перечисленные протоколы, иначе пустая строка. */
	function esc_url( $url, $protocols = null ): string {
		$url       = trim( (string) $url );
		$protocols = null === $protocols ? array( 'http', 'https', 'mailto' ) : $protocols;

		if ( 1 !== preg_match( '#^([a-z][a-z0-9+.-]*):#i', $url, $m ) || ! in_array( strtolower( $m[1] ), $protocols, true ) ) {
			return '';
		}

		return htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
