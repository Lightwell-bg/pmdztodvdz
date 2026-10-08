<?php
/**
 * Календарная арифметика дат без часов и часовых поясов.
 *
 * Все даты — DateTimeImmutable в UTC на полночь. Месяцы и годы
 * прибавляются по календарю с «прижатием» к последнему дню месяца
 * (31.08 + 6 мес. = 28/29.02, а не 03.03, как делает PHP по умолчанию).
 * Нигде не используется замена месяца на 30 дней.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Rules;

use DateTimeImmutable;
use DateTimeZone;

final class DateMath {

	/**
	 * Строгий разбор даты формата Y-m-d. Невалидные даты (31.02) — null.
	 */
	public static function parse( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return null;
		}

		$y = (int) $m[1];
		$mo = (int) $m[2];
		$d = (int) $m[3];

		if ( $y < 1900 || $y > 2200 || ! checkdate( $mo, $d, $y ) ) {
			return null;
		}

		return self::make( $y, $mo, $d );
	}

	public static function make( int $y, int $m, int $d ): DateTimeImmutable {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->setDate( $y, $m, $d )
			->setTime( 0, 0, 0 );
	}

	public static function format( ?DateTimeImmutable $date ): string {
		return null === $date ? '' : $date->format( 'Y-m-d' );
	}

	/** Дата в виде ДД.ММ.ГГГГ для текстов. */
	public static function human( ?DateTimeImmutable $date ): string {
		return null === $date ? '—' : $date->format( 'd.m.Y' );
	}

	/**
	 * Прибавляет (или вычитает) календарные месяцы с прижатием дня
	 * к концу месяца.
	 */
	public static function addMonths( DateTimeImmutable $date, int $months ): DateTimeImmutable {
		$y = (int) $date->format( 'Y' );
		$m = (int) $date->format( 'n' );
		$d = (int) $date->format( 'j' );

		$total = $y * 12 + ( $m - 1 ) + $months;
		$ny = intdiv( $total, 12 );
		$nm = $total % 12 + 1;

		if ( $total < 0 ) {
			// Отрицательных годов не бывает в нашем диапазоне; защита от intdiv.
			$ny = (int) floor( $total / 12 );
			$nm = $total - $ny * 12 + 1;
		}

		$last = (int) self::make( $ny, $nm, 1 )->format( 't' );

		return self::make( $ny, $nm, min( $d, $last ) );
	}

	public static function addYears( DateTimeImmutable $date, int $years ): DateTimeImmutable {
		return self::addMonths( $date, 12 * $years );
	}

	public static function addDays( DateTimeImmutable $date, int $days ): DateTimeImmutable {
		return $date->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' );
	}

	/** Число суток от $a до $b (может быть отрицательным). */
	public static function daysBetween( DateTimeImmutable $a, DateTimeImmutable $b ): int {
		$diff = $a->diff( $b );

		return (int) $diff->days * ( 1 === $diff->invert ? -1 : 1 );
	}

	public static function min( DateTimeImmutable $a, DateTimeImmutable $b ): DateTimeImmutable {
		return $a <= $b ? $a : $b;
	}

	public static function max( DateTimeImmutable $a, DateTimeImmutable $b ): DateTimeImmutable {
		return $a >= $b ? $a : $b;
	}

	/**
	 * Минимальная и максимальная длина N календарных месяцев в сутках
	 * по всем возможным датам начала полного четырёхлетнего цикла
	 * (учитывает високосный год). Для N = 10 получается [303, 306].
	 *
	 * @return array{0:int,1:int}
	 */
	public static function monthSpanBounds( int $months ): array {
		static $cache = array();

		if ( isset( $cache[ $months ] ) ) {
			return $cache[ $months ];
		}

		$min = PHP_INT_MAX;
		$max = 0;
		$day = self::make( 2024, 1, 1 );
		$end = self::make( 2028, 1, 1 );

		while ( $day < $end ) {
			$len = self::daysBetween( $day, self::addMonths( $day, $months ) );
			$min = min( $min, $len );
			$max = max( $max, $len );
			$day = $day->modify( '+1 day' );
		}

		return $cache[ $months ] = array( $min, $max );
	}
}
