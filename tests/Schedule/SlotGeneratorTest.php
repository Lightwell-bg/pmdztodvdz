<?php
/**
 * Тесты генерации слотов: границы интервалов, шаг, минимальное время до записи,
 * горизонт, исключения, отпуска, переход на летнее/зимнее время (2026 и 2027),
 * срок письменного ответа.
 *
 * Europe/Sofia: летнее время 2026 — с 29 марта по 25 октября; 2027 — с 28 марта по 31 октября.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Schedule;

use Bginfo\PmzhDvzh\Schedule\ScheduleConfig;
use Bginfo\PmzhDvzh\Schedule\SlotGenerator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SlotGeneratorTest extends TestCase {

	/** @param array<string,mixed> $over */
	private function gen( array $over = array(), array $weekly = array( '10:00-13:00' ) ): SlotGenerator {
		return new SlotGenerator(
			new ScheduleConfig(
				array_merge(
					array(
						'tz'               => 'Europe/Sofia',
						'weekly'           => array_fill( 1, 7, $weekly ),
						'duration_min'     => 45,
						'break_min'        => 15,
						'step_min'         => 15,
						'min_notice_hours' => 0,
						'horizon_days'     => 1,
					),
					$over
				)
			)
		);
	}

	private static function local( string $datetime ): int {
		return ( new DateTimeImmutable( $datetime, new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
	}

	/**
	 * @param list<array{start:int,date:string,local:string}> $slots
	 * @return list<string>
	 */
	private static function times( array $slots, string $date ): array {
		$out = array();

		foreach ( $slots as $s ) {
			if ( $s['date'] === $date ) {
				$out[] = $s['local'];
			}
		}

		return $out;
	}

	public function test_interval_boundaries_last_start_ends_exactly_at_interval_end(): void {
		$slots = $this->gen()->candidates( self::local( '2026-06-09 00:00:00' ) );

		// 10:00-13:00 при 45 минутах и шаге 15: последнее начало 12:15 (конец ровно 13:00), 12:30 уже нет.
		$this->assertSame(
			array( '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30', '11:45', '12:00', '12:15' ),
			self::times( $slots, '2026-06-10' )
		);
	}

	public function test_two_intervals_and_break_block(): void {
		$slots = $this->gen( array(), array( '10:00-11:00', '14:00-15:00' ) )->candidates( self::local( '2026-06-09 00:00:00' ) );
		$day   = array_values( array_filter( $slots, static fn( array $s ): bool => '2026-06-10' === $s['date'] ) );

		$this->assertSame( array( '10:00', '10:15', '14:00', '14:15' ), array_column( $day, 'local' ) );
		$this->assertSame( $day[0]['start'] + 45 * 60, $day[0]['end'] );
		$this->assertSame( $day[0]['end'] + 15 * 60, $day[0]['block_end'] );
	}

	public function test_step_is_respected(): void {
		$slots = $this->gen( array( 'step_min' => 30 ) )->candidates( self::local( '2026-06-09 00:00:00' ) );

		$this->assertSame( array( '10:00', '10:30', '11:00', '11:30', '12:00' ), self::times( $slots, '2026-06-10' ) );
	}

	public function test_min_notice_hides_early_slots(): void {
		// Сейчас 09:00 местного; уведомление 2 ч → не раньше 11:00.
		$now   = self::local( '2026-06-10 09:00:00' );
		$slots = $this->gen( array( 'min_notice_hours' => 2 ) )->candidates( $now );

		$this->assertSame( '11:00', self::times( $slots, '2026-06-10' )[0] );
		$this->assertSame( self::local( '2026-06-10 11:00:00' ), $slots[0]['start'] );
	}

	public function test_min_notice_24h_moves_to_next_day(): void {
		$now   = self::local( '2026-06-10 11:31:00' );
		$slots = $this->gen( array( 'min_notice_hours' => 24 ) )->candidates( $now );

		$this->assertSame( array(), self::times( $slots, '2026-06-10' ) );
		$this->assertSame( '11:45', self::times( $slots, '2026-06-11' )[0], 'ровно через 24 часа и позже' );
	}

	public function test_horizon_includes_last_day_only(): void {
		$now   = self::local( '2026-06-10 00:00:00' );
		$slots = $this->gen( array( 'horizon_days' => 2 ) )->candidates( $now );
		$dates = array_values( array_unique( array_column( $slots, 'date' ) ) );

		$this->assertSame( array( '2026-06-10', '2026-06-11', '2026-06-12' ), $dates );
	}

	public function test_exceptions_and_vacations(): void {
		$g = $this->gen(
			array(
				'horizon_days' => 4,
				'exceptions'   => array(
					array( 'date' => '2026-06-11', 'intervals' => array() ),
					array( 'date' => '2026-06-12', 'intervals' => array( '15:00-16:00' ) ),
				),
				'vacations'    => array( array( 'from' => '2026-06-13', 'to' => '2026-06-13' ) ),
			)
		);
		$slots = $g->candidates( self::local( '2026-06-10 00:00:00' ) );

		$this->assertNotEmpty( self::times( $slots, '2026-06-10' ) );
		$this->assertSame( array(), self::times( $slots, '2026-06-11' ), 'выходной' );
		$this->assertSame( array( '15:00', '15:15' ), self::times( $slots, '2026-06-12' ), 'исключение заменяет неделю' );
		$this->assertSame( array(), self::times( $slots, '2026-06-13' ), 'отпуск' );
		$this->assertNotEmpty( self::times( $slots, '2026-06-14' ) );
	}

	public function test_match_accepts_only_generated_starts(): void {
		$g   = $this->gen();
		$now = self::local( '2026-06-09 00:00:00' );

		$this->assertNotNull( $g->match( self::local( '2026-06-10 10:15:00' ), $now ) );
		$this->assertNull( $g->match( self::local( '2026-06-10 10:20:00' ), $now ), 'не по шагу' );
		$this->assertNull( $g->match( self::local( '2026-06-10 12:30:00' ), $now ), 'вылезает за интервал' );
		$this->assertNull( $g->match( self::local( '2026-06-10 07:00:00' ), $now ), 'вне рабочего времени' );
	}

	public function test_is_free_checks_every_unit_of_block(): void {
		$g     = $this->gen();
		$slot  = $g->match( self::local( '2026-06-10 10:00:00' ), self::local( '2026-06-09 00:00:00' ) );
		$this->assertNotNull( $slot );

		// Единица внутри перерыва (10:50) тоже блокирует.
		$busy = array( self::local( '2026-06-10 10:50:00' ) => true );
		$this->assertFalse( SlotGenerator::isFree( $slot, $busy ) );

		// Единица сразу после блока (11:00) — свободно.
		$busy = array( self::local( '2026-06-10 11:00:00' ) => true );
		$this->assertTrue( SlotGenerator::isFree( $slot, $busy ) );
	}

	public function test_dst_spring_2026_gap_is_skipped(): void {
		// 29.03.2026 в 03:00 часы переводят на 04:00: локального 03:00-03:59 не существует.
		$slots = $this->gen( array(), array( '03:00-05:00' ) )->candidates( self::local( '2026-03-28 00:00:00' ) );
		$day   = array_values( array_filter( $slots, static fn( array $s ): bool => '2026-03-29' === $s['date'] ) );

		$this->assertSame( array( '04:00', '04:15' ), array_column( $day, 'local' ) );
		$this->assertSame( gmmktime( 1, 0, 0, 3, 29, 2026 ), $day[0]['start'], '04:00 EEST = 01:00 UTC' );
		$this->assertSame( 2700, $day[0]['end'] - $day[0]['start'], 'длительность встречи физическая' );
	}

	public function test_dst_fall_2026_repeated_hour_is_not_lost(): void {
		// 25.10.2026 в 04:00 часы переводят на 03:00: час 03:00-03:59 повторяется.
		$slots = $this->gen( array(), array( '03:00-06:00' ) )->candidates( self::local( '2026-10-24 00:00:00' ) );
		$day   = array_values( array_filter( $slots, static fn( array $s ): bool => '2026-10-25' === $s['date'] ) );

		// Физически от 03:00 EEST (00:00Z) до 06:00 EET (04:00Z) = 4 часа → 14 начал.
		$this->assertCount( 14, $day );
		$this->assertSame( gmmktime( 0, 0, 0, 10, 25, 2026 ), $day[0]['start'] );

		for ( $i = 1, $n = count( $day ); $i < $n; $i++ ) {
			$this->assertSame( 900, $day[ $i ]['start'] - $day[ $i - 1 ]['start'], 'шаг по абсолютному времени' );
		}

		$this->assertLessThanOrEqual( gmmktime( 4, 0, 0, 10, 25, 2026 ), $day[13]['end'] );
	}

	public function test_dst_spring_and_fall_2027(): void {
		$spring = array_values(
			array_filter(
				$this->gen( array(), array( '03:00-05:00' ) )->candidates( self::local( '2027-03-27 00:00:00' ) ),
				static fn( array $s ): bool => '2027-03-28' === $s['date']
			)
		);
		$this->assertSame( array( '04:00', '04:15' ), array_column( $spring, 'local' ) );

		$fall = array_values(
			array_filter(
				$this->gen( array(), array( '03:00-06:00' ) )->candidates( self::local( '2027-10-30 00:00:00' ) ),
				static fn( array $s ): bool => '2027-10-31' === $s['date']
			)
		);
		$this->assertCount( 14, $fall );
		$this->assertSame( gmmktime( 0, 0, 0, 10, 31, 2027 ), $fall[0]['start'] );
	}

	public function test_utc_offset_differs_between_summer_and_winter(): void {
		$summer = $this->gen()->candidates( self::local( '2026-07-14 00:00:00' ) )[0];
		$winter = $this->gen()->candidates( self::local( '2026-12-15 00:00:00' ) )[0];

		$this->assertSame( '07:00', gmdate( 'H:i', $summer['start'] ), '10:00 EEST = 07:00Z' );
		$this->assertSame( '08:00', gmdate( 'H:i', $winter['start'] ), '10:00 EET = 08:00Z' );
	}

	public function test_written_dates_are_working_days_from_min_notice(): void {
		$g = new SlotGenerator(
			new ScheduleConfig(
				array(
					'tz'               => 'Europe/Sofia',
					'weekly'           => array( 1 => array( '10:00-18:00' ), 2 => array( '10:00-18:00' ), 3 => array( '10:00-18:00' ), 4 => array( '10:00-18:00' ), 5 => array( '10:00-18:00' ) ),
					'min_notice_hours' => 24,
					'horizon_days'     => 7,
				)
			)
		);

		// Среда 10.06.2026 в 09:00: минимальная дата — четверг 11.06; суббота и воскресенье пропускаются.
		$dates = $g->writtenDates( self::local( '2026-06-10 09:00:00' ) );

		$this->assertSame( array( '2026-06-11', '2026-06-12', '2026-06-15', '2026-06-16', '2026-06-17' ), $dates );
	}

	public function test_written_deadline_skips_weekend(): void {
		$g = $this->weekdays();

		// Пятница 12.06.2026 + 2 рабочих дня = вторник 16.06, конец дня.
		$this->assertSame( self::local( '2026-06-16 23:59:59' ), $g->writtenDeadline( '2026-06-12' ) );
		// Четверг 11.06 + 2 = понедельник 15.06 (через выходные).
		$this->assertSame( self::local( '2026-06-15 23:59:59' ), $g->writtenDeadline( '2026-06-11' ) );
	}

	public function test_written_deadline_skips_exception_day_off_and_vacation(): void {
		$g = $this->weekdays(
			array(
				'exceptions' => array( array( 'date' => '2026-06-16', 'intervals' => array() ) ),
				'vacations'  => array( array( 'from' => '2026-06-17', 'to' => '2026-06-17' ) ),
			)
		);

		// Пятница 12.06: пн 15.06 — 1-й день, вт 16.06 — выходной, ср 17.06 — отпуск, чт 18.06 — 2-й.
		$this->assertSame( self::local( '2026-06-18 23:59:59' ), $g->writtenDeadline( '2026-06-12' ) );
	}

	public function test_written_deadline_after_dst_change(): void {
		$g = $this->weekdays();

		// Пятница 26.03.2027 + 2 рабочих дня = вторник 30.03 (после перехода 28.03), 23:59:59 по Софии.
		$deadline = $g->writtenDeadline( '2027-03-26' );

		$this->assertSame( self::local( '2027-03-30 23:59:59' ), $deadline );
		$this->assertSame( '2027-03-30 23:59:59', ( new DateTimeImmutable( '@' . $deadline ) )->setTimezone( new DateTimeZone( 'Europe/Sofia' ) )->format( 'Y-m-d H:i:s' ) );
	}

	/** @param array<string,mixed> $over */
	private function weekdays( array $over = array() ): SlotGenerator {
		return new SlotGenerator(
			new ScheduleConfig(
				array_merge(
					array(
						'tz'                    => 'Europe/Sofia',
						'weekly'                => array( 1 => array( '10:00-18:00' ), 2 => array( '10:00-18:00' ), 3 => array( '10:00-18:00' ), 4 => array( '10:00-18:00' ), 5 => array( '10:00-18:00' ) ),
						'written_business_days' => 2,
					),
					$over
				)
			)
		);
	}
}
