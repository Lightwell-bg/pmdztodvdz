<?php
/**
 * Установка cookie плагина с едиными атрибутами: HttpOnly, SameSite=Lax,
 * Secure на HTTPS.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Support;

final class Cookies {

	/**
	 * @param int $expires Unix-время истечения; 0 — до закрытия браузера;
	 *                     прошлое значение удаляет cookie.
	 */
	public static function set( string $name, string $value, int $expires ): void {
		$path   = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';

		if ( ! headers_sent() ) {
			setcookie(
				$name,
				$value,
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		if ( $expires > 0 && $expires < time() ) {
			unset( $_COOKIE[ $name ] );
		} else {
			$_COOKIE[ $name ] = $value;
		}
	}

	/** Значение cookie запроса (строка без слэшей) либо ''. */
	public static function get( string $name ): string {
		if ( ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
			return '';
		}

		$v = function_exists( 'wp_unslash' ) ? wp_unslash( $_COOKIE[ $name ] ) : $_COOKIE[ $name ];

		return is_string( $v ) ? $v : '';
	}
}
