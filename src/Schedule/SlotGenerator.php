<?php
/**
 * Возможные начала синхронных встреч и даты письменных разборов.
 * Чистая логика: занятость из БД учитывается отдельно (isFree()).
 *
 * Время строится в часовом поясе консультанта из локальных границ
 * интервалов, затем шаги идут в абсолютных секундах. Так переход на
 * летнее/зимнее время учитывается автоматически:
 *  - несуществующее локальное время (весенний переход) PHP сдвигает
 *    вперёд — начало интервала становится позже, лишних слотов нет;
 *  - повторяющийся час (осенний переход) различается по UTC, а
 *    длительность встречи всегда физическая.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Schedule;

use DateTimeImmutable;
use DateTimeZone;

final class SlotGenerator {

	public function __construct( private ScheduleConfig $config ) {
	}

	/**
	 * Все возможные начала от now (с учётом минимального времени до записи)
	 * до конца горизонта.
	 *
	 * @return list<array{start:int,end:int,block_end:int,date:string,local:string}>
	 */
	public function candidates( int $nowUtc ): array {
		$c        = $this->config;
		$earliest = $nowUtc + $c->minNoticeHours * 3600;
		$out      = array();

		foreach ( $this->dates( $nowUtc ) as $date ) {
			foreach ( $c->intervalsFor( $date ) as $iv ) {
				$from = self::localTs( $date, $iv[0], $c->tz );
				$to   = self::localTs( $date, $iv[1], $c->tz );

				for ( $t = $from; $t + $c->duration * 60 <= $to; $t += $c->step * 60 ) {
					if ( $t < $earliest ) {
						continue;
					}

					$end   = $t + $c->duration * 60;
					$out[] = array(
						'start'     => $t,
						'end'       => $end,
						'block_end' => $end + $c->break * 60,
						'date'      => $date,
						'local'     => ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $c->tz )->format( 'H:i' ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Свободен ли кандидат при занятых единицах.
	 *
	 * @param array<int,true> $busyUnits unit_utc => true
	 * @param array{start:int,block_end:int} $slot
	 */
	public static function isFree( array $slot, array $busyUnits ): bool {
		foreach ( Grid::units( $slot['start'], $slot['block_end'] ) as $u ) {
			if ( isset( $busyUnits[ $u ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Является ли (start) допустимым началом по текущему расписанию —
	 * сервер никогда не принимает время из браузера без этой проверки.
	 *
	 * @return array{start:int,end:int,block_end:int,date:string,local:string}|null
	 */
	public function match( int $startUtc, int $nowUtc ): ?array {
		foreach ( $this->candidates( $nowUtc ) as $slot ) {
			if ( $slot['start'] === $startUtc ) {
				return $slot;
			}
		}

		return null;
	}

	/**
	 * Даты для письменного разбора: рабочие дни начиная с даты, которая
	 * наступает не раньше минимального времени до записи, до горизонта.
	 *
	 * @return list<string>
	 */
	public function writtenDates( int $nowUtc ): array {
		$c       = $this->config;
		$minDate = ( new DateTimeImmutable( '@' . ( $nowUtc + $c->minNoticeHours * 3600 ) ) )->setTimezone( $c->tz )->format( 'Y-m-d' );
		$out     = array();

		foreach ( $this->dates( $nowUtc ) as $date ) {
			if ( $date >= $minDate && $c->isWorkingDay( $date ) ) {
				$out[] = $date;
			}
		}

		return $out;
	}

	/**
	 * Крайний срок письменного ответа: конец N-го рабочего дня после
	 * выбранной даты (23:59:59 по часовому поясу консультанта), в UTC.
	 * null — если в разумных пределах нет столько рабочих дней.
	 */
	public function writtenDeadline( string $date ): ?int {
		$c     = $this->config;
		$day   = new DateTimeImmutable( $date . ' 12:00:00', $c->tz );
		$count = 0;

		for ( $i = 0; $i < 400; $i++ ) {
			$day = $day->modify( '+1 day' );
			$d   = $day->format( 'Y-m-d' );

			if ( $c->isWorkingDay( $d ) && ++$count >= $c->writtenBusinessDays ) {
				// Начало следующего дня по местному времени минус секунда.
				return ( new DateTimeImmutable( $d . ' 00:00:00', $c->tz ) )->modify( '+1 day' )->getTimestamp() - 1;
			}
		}

		return null;
	}

	/**
	 * Локальные даты от сегодняшней до горизонта включительно.
	 *
	 * @return list<string>
	 */
	public function dates( int $nowUtc ): array {
		$c     = $this->config;
		$today = ( new DateTimeImmutable( '@' . $nowUtc ) )->setTimezone( $c->tz )->setTime( 12, 0 );
		$out   = array();

		for ( $i = 0; $i <= $c->horizonDays; $i++ ) {
			$out[] = $today->modify( '+' . $i . ' day' )->format( 'Y-m-d' );
		}

		return $out;
	}

	/**
	 * UTC-момент локального времени «дата + минуты от полуночи».
	 * 1440 минут = начало следующего дня.
	 */
	public static function localTs( string $date, int $minutes, DateTimeZone $tz ): int {
		$base = new DateTimeImmutable( $date . ' 00:00:00', $tz );

		if ( $minutes >= 1440 ) {
			return $base->modify( '+1 day' )->getTimestamp();
		}

		// setTime с несуществующим временем PHP сдвигает вперёд — это и нужно.
		return $base->setTime( intdiv( $minutes, 60 ), $minutes % 60 )->getTimestamp();
	}
}
