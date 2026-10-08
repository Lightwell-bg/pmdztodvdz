<?php
/**
 * Ограничение частоты на transient'ах. Субъект (IP, сессия, покупка)
 * хранится только в виде хеша с солью сайта.
 *
 * Счётчик не атомарен (transient API), но для защиты от перебора и
 * рассылки этого достаточно.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Support;

final class RateLimiter {

	/**
	 * Регистрирует обращение. Возвращает false, если лимит исчерпан.
	 *
	 * @param string $bucket  Название действия (session, submit, grant, send_link).
	 * @param string $subject Уже захешированный субъект (см. subject()).
	 */
	public static function hit( string $bucket, string $subject, int $limit, int $windowSeconds ): bool {
		$key   = 'bgdvzh_rl_' . substr( hash( 'sha256', $bucket . '|' . $subject ), 0, 40 );
		$now   = Clock::timestamp();
		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['c'], $state['until'] ) || (int) $state['until'] <= $now ) {
			$state = array( 'c' => 0, 'until' => $now + $windowSeconds );
		}

		if ( (int) $state['c'] >= $limit ) {
			return false;
		}

		$state['c'] = (int) $state['c'] + 1;
		set_transient( $key, $state, max( 1, (int) $state['until'] - $now ) );

		return true;
	}

	/** Хеш произвольного значения с солью сайта. */
	public static function subject( string $value ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'bgdvzh';

		return hash_hmac( 'sha256', $value, $salt );
	}

	/** Субъект «IP клиента». */
	public static function ipSubject(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return self::subject( 'ip|' . $ip );
	}
}
