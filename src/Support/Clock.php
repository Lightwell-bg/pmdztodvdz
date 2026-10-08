<?php
/**
 * Время. В БД все метки хранятся в UTC (Y-m-d H:i:s); «сегодня» для
 * правил — локальная дата сайта (часовой пояс из настроек WordPress).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Support;

final class Clock {

	/** Подмена времени в тестах (unix timestamp). */
	private static ?int $fixed = null;

	public static function freeze( ?int $timestamp ): void {
		self::$fixed = $timestamp;
	}

	public static function timestamp(): int {
		return self::$fixed ?? time();
	}

	/** Текущий момент в UTC для БД. */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s', self::timestamp() );
	}

	/** UTC-метка через $seconds секунд от сейчас (может быть отрицательным). */
	public static function fromNow( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', self::timestamp() + $seconds );
	}

	/** Сегодняшняя дата в часовом поясе сайта, Y-m-d. */
	public static function today(): string {
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'Y-m-d', self::timestamp() );
		}

		return gmdate( 'Y-m-d', self::timestamp() );
	}

	/** Unix-время из UTC-метки БД; 0 при пустом значении. */
	public static function toTimestamp( ?string $utc ): int {
		if ( null === $utc || '' === $utc || str_starts_with( $utc, '0000' ) ) {
			return 0;
		}

		$ts = strtotime( $utc . ' UTC' );

		return false === $ts ? 0 : $ts;
	}
}
