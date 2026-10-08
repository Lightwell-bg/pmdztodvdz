<?php
/**
 * Случайные токены и защита гостевых запросов от CSRF.
 *
 * Модель гостевой защиты (без WordPress nonce, который для гостей
 * одинаков у всех и не авторизует):
 *  1. Владелец анкеты — сессия: cookie `bgdvzh_s` (HttpOnly, Secure на
 *     HTTPS, SameSite=Lax) содержит 256-битный токен; в БД — только sha256.
 *  2. У сессии есть серверный csrf_secret. Для каждого изменяющего
 *     действия браузер получает csrf = HMAC(csrf_secret, действие|область)
 *     из ответа REST «state» (только по своей cookie) и отправляет его в
 *     заголовке X-Bgdvzh-Csrf. Чужой сайт не может прочитать этот ответ
 *     (same-origin), поэтому не может и подделать заголовок.
 *  3. Дополнительно проверяется Origin/Referer: только свой хост.
 *  4. Все изменяющие операции — только POST.
 *  5. Для вошедших дополнительно проверяется wp_rest nonce; nonce ни для
 *     кого не является авторизацией — владелец проверяется отдельно.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Access;

final class Tokens {

	/** 256-битный токен в hex (64 символа). */
	public static function random(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/** Публичный идентификатор (128 бит, 32 hex) — не секрет, но не угадывается. */
	public static function publicId(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	public static function isWellFormed( string $token, int $length = 64 ): bool {
		return strlen( $token ) === $length && ctype_xdigit( $token );
	}

	public static function csrf( string $secret, string $action, string $scope = '' ): string {
		return hash_hmac( 'sha256', $action . '|' . $scope, $secret );
	}

	public static function csrfValid( string $secret, string $action, string $scope, string $given ): bool {
		if ( '' === $secret || ! self::isWellFormed( $given ) ) {
			return false;
		}

		return hash_equals( self::csrf( $secret, $action, $scope ), $given );
	}

	/**
	 * Origin или Referer должен совпадать со схемой и хостом сайта.
	 * Отсутствие обоих заголовков — отказ (браузеры шлют Origin на POST).
	 */
	public static function sameOrigin( ?string $origin, ?string $referer, string $homeUrl ): bool {
		$home = parse_url( $homeUrl );

		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return false;
		}

		$expected = strtolower( ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) );

		foreach ( array( $origin, $referer ) as $candidate ) {
			if ( null === $candidate || '' === $candidate || 'null' === $candidate ) {
				continue;
			}

			$p = parse_url( $candidate );

			if ( ! is_array( $p ) || empty( $p['host'] ) ) {
				return false;
			}

			$actual = strtolower( ( $p['scheme'] ?? '' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );

			return hash_equals( $expected, $actual );
		}

		return false;
	}

	/**
	 * Подписанная ссылка снимка для позиции корзины. Не секрет доступа:
	 * лишь защищает от подстановки чужого снимка в корзину.
	 */
	public static function cartRef( string $publicId, int $sessionId, string $key ): string {
		return $publicId . '.' . substr( hash_hmac( 'sha256', 'cart|' . $publicId . '|' . $sessionId, $key ), 0, 32 );
	}

	/** @return array{0:string,1:string}|null [publicId, mac] */
	public static function parseCartRef( string $ref ): ?array {
		if ( 1 !== preg_match( '/^([0-9a-f]{32})\.([0-9a-f]{32})$/', $ref, $m ) ) {
			return null;
		}

		return array( $m[1], $m[2] );
	}

	public static function cartRefValid( string $ref, int $sessionId, string $key ): bool {
		$parsed = self::parseCartRef( $ref );

		return null !== $parsed && hash_equals( self::cartRef( $parsed[0], $sessionId, $key ), $ref );
	}
}
