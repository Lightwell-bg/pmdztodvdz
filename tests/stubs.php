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

foreach ( array( 'ARRAY_A', 'ARRAY_N', 'OBJECT' ) as $bgdvzh_const ) {
	if ( ! defined( $bgdvzh_const ) ) {
		define( $bgdvzh_const, $bgdvzh_const );
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

/*
 * Управляемые заглушки пользователя и фильтров (для тестов AdminAccess):
 * $GLOBALS['bgdvzh_test_user'] — id пользователя, ['bgdvzh_test_caps'] — список
 * его возможностей, ['bgdvzh_test_filters'] — подмена значений фильтров по имени.
 */
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['bgdvzh_test_user'] ?? 0 );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ): bool {
		// Заглушка намеренно не смотрит на id пользователя: проверка гостя — дело AdminAccess.
		return in_array( $capability, (array) ( $GLOBALS['bgdvzh_test_caps'] ?? array() ), true );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return array_key_exists( $hook, (array) ( $GLOBALS['bgdvzh_test_filters'] ?? array() ) ) ? $GLOBALS['bgdvzh_test_filters'][ $hook ] : $value;
	}
}

/*
 * Опции и соль для тестов: $GLOBALS['bgdvzh_test_options'][имя] подменяет get_option().
 */
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, (array) ( $GLOBALS['bgdvzh_test_options'] ?? array() ) ) ? $GLOBALS['bgdvzh_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}
}

/*
 * Временный файл для вложений писем (WordPress создаёт его в каталоге temp).
 */
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '', $dir = '' ) {
		return tempnam( sys_get_temp_dir(), 'bgd' );
	}
}
