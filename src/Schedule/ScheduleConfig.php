<?php
/**
 * Нормализованная конфигурация расписания консультанта (чистые данные).
 *
 * Формат входного массива (из настроек плагина):
 *  tz                  'Europe/Sofia'
 *  weekly              [1..7 => ['09:00-13:00', '14:00-18:00']]  (ISO: 1 = пн)
 *  exceptions          [['date'=>'2026-12-24','intervals'=>[]], ['date'=>..., 'intervals'=>['10:00-12:00']]]
 *                      пустой список интервалов — выходной; иначе интервалы ЗАМЕНЯЮТ недельные
 *  vacations           [['from'=>'2026-08-01','to'=>'2026-08-15']]  (включительно — выходные)
 *  duration_min        45
 *  break_min           15
 *  step_min            15     шаг возможных начал
 *  min_notice_hours    24
 *  horizon_days        30
 *  written_business_days 2   срок письменного разбора: конец N-го рабочего дня после даты
 *
 * Все минуты и границы интервалов должны быть кратны 5 (Grid::UNIT);
 * errors() возвращает список нарушений — их показывает админка, а
 * консультация выключается, пока они не исправлены.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Schedule;

use DateTimeZone;

final class ScheduleConfig {

	public DateTimeZone $tz;
	/** @var array<int,list<array{0:int,1:int}>> день недели => [[начало мин, конец мин]] */
	public array $weekly = array();
	/** @var array<string,list<array{0:int,1:int}>> дата => интервалы ([] — выходной) */
	public array $exceptions = array();
	/** @var list<array{0:string,1:string}> */
	public array $vacations = array();
	public int $duration;
	public int $break;
	public int $step;
	public int $minNoticeHours;
	public int $horizonDays;
	public int $writtenBusinessDays;
	/** @var list<string> */
	private array $errors = array();

	/** @param array<string,mixed> $c */
	public function __construct( array $c ) {
		try {
			$this->tz = new DateTimeZone( (string) ( $c['tz'] ?? 'Europe/Sofia' ) );
		} catch ( \Exception $e ) {
			$this->errors[] = 'Неизвестный часовой пояс.';
			$this->tz       = new DateTimeZone( 'Europe/Sofia' );
		}

		$this->duration            = (int) ( $c['duration_min'] ?? 45 );
		$this->break               = (int) ( $c['break_min'] ?? 15 );
		$this->step                = (int) ( $c['step_min'] ?? 15 );
		$this->minNoticeHours      = max( 0, (int) ( $c['min_notice_hours'] ?? 24 ) );
		$this->horizonDays         = max( 1, min( 365, (int) ( $c['horizon_days'] ?? 30 ) ) );
		$this->writtenBusinessDays = max( 1, min( 30, (int) ( $c['written_business_days'] ?? 2 ) ) );

		foreach ( array( 'duration' => 'Длительность', 'break' => 'Перерыв', 'step' => 'Шаг начала' ) as $prop => $label ) {
			if ( ! Grid::isAligned( $this->$prop ) ) {
				$this->errors[] = $label . ' должен быть кратен 5 минутам.';
			}
		}

		if ( $this->duration < 5 || $this->step < 5 ) {
			$this->errors[] = 'Длительность и шаг начала должны быть не меньше 5 минут.';
		}

		foreach ( (array) ( $c['weekly'] ?? array() ) as $dow => $list ) {
			$dow = (int) $dow;

			if ( $dow < 1 || $dow > 7 ) {
				continue;
			}

			$this->weekly[ $dow ] = $this->intervals( (array) $list, 'день ' . $dow );
		}

		foreach ( (array) ( $c['exceptions'] ?? array() ) as $ex ) {
			$date = (string) ( $ex['date'] ?? '' );

			if ( ! self::isDate( $date ) ) {
				$this->errors[] = 'Неверная дата исключения: ' . $date;
				continue;
			}

			$this->exceptions[ $date ] = $this->intervals( (array) ( $ex['intervals'] ?? array() ), $date );
		}

		foreach ( (array) ( $c['vacations'] ?? array() ) as $v ) {
			$from = (string) ( $v['from'] ?? '' );
			$to   = (string) ( $v['to'] ?? '' );

			if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $to < $from ) {
				$this->errors[] = 'Неверный период отпуска: ' . $from . ' — ' . $to;
				continue;
			}

			$this->vacations[] = array( $from, $to );
		}

		if ( empty( array_filter( $this->weekly ) ) && empty( array_filter( $this->exceptions ) ) ) {
			$this->errors[] = 'Не заданы рабочие интервалы.';
		}
	}

	/** @return list<string> */
	public function errors(): array {
		return $this->errors;
	}

	public function isValid(): bool {
		return empty( $this->errors );
	}

	/**
	 * Интервалы рабочего времени на локальную дату (минуты от полуночи).
	 *
	 * @return list<array{0:int,1:int}>
	 */
	public function intervalsFor( string $date ): array {
		foreach ( $this->vacations as $v ) {
			if ( $date >= $v[0] && $date <= $v[1] ) {
				return array();
			}
		}

		if ( array_key_exists( $date, $this->exceptions ) ) {
			return $this->exceptions[ $date ];
		}

		$dow = (int) ( new \DateTimeImmutable( $date . ' 12:00:00', $this->tz ) )->format( 'N' );

		return $this->weekly[ $dow ] ?? array();
	}

	public function isWorkingDay( string $date ): bool {
		return ! empty( $this->intervalsFor( $date ) );
	}

	/**
	 * @param list<mixed> $list
	 * @return list<array{0:int,1:int}>
	 */
	private function intervals( array $list, string $where ): array {
		$out = array();

		foreach ( $list as $item ) {
			if ( 1 !== preg_match( '/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', (string) $item, $m ) ) {
				$this->errors[] = 'Неверный интервал «' . (string) $item . '» (' . $where . '), нужен формат 09:00-13:00.';
				continue;
			}

			$a = (int) $m[1] * 60 + (int) $m[2];
			$b = (int) $m[3] * 60 + (int) $m[4];

			if ( (int) $m[1] > 23 || (int) $m[2] > 59 || (int) $m[3] > 24 || (int) $m[4] > 59 || $b > 1440 || $b <= $a ) {
				$this->errors[] = 'Неверный интервал «' . (string) $item . '» (' . $where . ').';
				continue;
			}

			if ( ! Grid::isAligned( $a ) || ! Grid::isAligned( $b ) ) {
				$this->errors[] = 'Границы интервала «' . (string) $item . '» (' . $where . ') должны быть кратны 5 минутам.';
				continue;
			}

			$out[] = array( $a, $b );
		}

		usort( $out, static fn( array $x, array $y ): int => $x[0] <=> $y[0] );

		for ( $i = 1, $n = count( $out ); $i < $n; $i++ ) {
			if ( $out[ $i ][0] < $out[ $i - 1 ][1] ) {
				$this->errors[] = 'Интервалы пересекаются (' . $where . ').';
				break;
			}
		}

		return $out;
	}

	public static function isDate( string $d ): bool {
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
