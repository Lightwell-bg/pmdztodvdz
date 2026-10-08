<?php
/**
 * Пятиминутная сетка занятости в UTC.
 *
 * Бронь занимает ВСЕ единицы, которых касается интервал
 * [начало, конец + перерыв): начало округляется вниз, конец — вверх.
 * Поэтому даже при значениях, не кратных 5 минутам, два пересекающихся
 * интервала всегда претендуют хотя бы на одну общую единицу, и UNIQUE
 * по unit_utc исключает пересечение. Настройки дополнительно
 * валидируются на кратность 5 минутам (ScheduleConfig).
 *
 * Для Europe/Sofia сетка UTC совпадает с локальной: смещение кратно часу.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Schedule;

final class Grid {

	public const UNIT = 300; // секунд

	/** Начало единицы, содержащей момент (floor). */
	public static function floor( int $ts ): int {
		return intdiv( $ts, self::UNIT ) * self::UNIT - ( $ts < 0 && 0 !== $ts % self::UNIT ? self::UNIT : 0 );
	}

	/** Ближайшая граница единиц не раньше момента (ceil). */
	public static function ceil( int $ts ): int {
		$f = self::floor( $ts );

		return $f === $ts ? $ts : $f + self::UNIT;
	}

	/**
	 * Единицы (их начала, unix UTC), которых касается [from, to).
	 *
	 * @return list<int>
	 */
	public static function units( int $from, int $to ): array {
		if ( $to <= $from ) {
			return array();
		}

		$out = array();

		for ( $u = self::floor( $from ), $end = self::ceil( $to ); $u < $end; $u += self::UNIT ) {
			$out[] = $u;
		}

		return $out;
	}

	public static function isAligned( int $minutes ): bool {
		return $minutes >= 0 && 0 === ( $minutes * 60 ) % self::UNIT;
	}
}
