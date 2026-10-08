<?php
/**
 * Тесты конфигурации расписания: валидация и приоритет отпуск > исключение > неделя.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Schedule;

use Bginfo\PmzhDvzh\Schedule\ScheduleConfig;
use PHPUnit\Framework\TestCase;

final class ScheduleConfigTest extends TestCase {

	/** @param array<string,mixed> $over */
	private function cfg( array $over = array() ): ScheduleConfig {
		return new ScheduleConfig(
			array_merge(
				array(
					'tz'           => 'Europe/Sofia',
					'weekly'       => array( 1 => array( '10:00-13:00', '14:00-18:00' ), 5 => array( '10:00-12:00' ) ),
					'duration_min' => 45,
					'break_min'    => 15,
					'step_min'     => 15,
				),
				$over
			)
		);
	}

	public function test_valid_config_has_no_errors(): void {
		$this->assertTrue( $this->cfg()->isValid() );
	}

	public function test_minutes_must_be_multiples_of_five(): void {
		foreach ( array( 'duration_min' => 47, 'break_min' => 7, 'step_min' => 12 ) as $key => $value ) {
			$errors = $this->cfg( array( $key => $value ) )->errors();

			$this->assertNotEmpty( $errors, $key );
			$this->assertStringContainsString( 'кратен 5', $errors[0], $key );
		}
	}

	public function test_interval_bounds_must_be_multiples_of_five(): void {
		$errors = $this->cfg( array( 'weekly' => array( 1 => array( '10:02-13:00' ) ) ) )->errors();

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'кратны 5', $errors[0] );
	}

	public function test_bad_interval_formats(): void {
		$this->assertNotEmpty( $this->cfg( array( 'weekly' => array( 1 => array( 'утро' ) ) ) )->errors() );
		$this->assertNotEmpty( $this->cfg( array( 'weekly' => array( 1 => array( '13:00-10:00' ) ) ) )->errors() );
		$this->assertNotEmpty( $this->cfg( array( 'weekly' => array( 1 => array( '10:00-13:00', '12:00-14:00' ) ) ) )->errors() );
	}

	public function test_empty_schedule_is_an_error(): void {
		$this->assertNotEmpty( $this->cfg( array( 'weekly' => array() ) )->errors() );
	}

	public function test_unknown_timezone_is_an_error(): void {
		$this->assertNotEmpty( $this->cfg( array( 'tz' => 'Mars/Base' ) )->errors() );
	}

	public function test_priority_vacation_exception_weekly(): void {
		// 2026-06-08 — понедельник.
		$c = $this->cfg(
			array(
				'exceptions' => array(
					array( 'date' => '2026-06-08', 'intervals' => array( '12:00-13:00' ) ),
					array( 'date' => '2026-06-15', 'intervals' => array() ),
				),
				'vacations'  => array( array( 'from' => '2026-06-22', 'to' => '2026-06-23' ) ),
			)
		);

		$this->assertSame( array( array( 720, 780 ) ), $c->intervalsFor( '2026-06-08' ), 'исключение заменяет неделю' );
		$this->assertSame( array(), $c->intervalsFor( '2026-06-15' ), 'пустое исключение — выходной' );
		$this->assertFalse( $c->isWorkingDay( '2026-06-15' ) );
		$this->assertSame( array( array( 600, 780 ), array( 840, 1080 ) ), $c->intervalsFor( '2026-06-01' ) );
		$this->assertSame( array(), $c->intervalsFor( '2026-06-22' ), 'отпуск главнее недели' );
		$this->assertSame( array(), $c->intervalsFor( '2026-06-23' ), 'граница отпуска включительно' );
		$this->assertTrue( $c->isWorkingDay( '2026-06-29' ) );
		$this->assertSame( array(), $c->intervalsFor( '2026-06-09' ), 'вторник без интервалов' );
	}

	public function test_vacation_beats_exception(): void {
		$c = $this->cfg(
			array(
				'exceptions' => array( array( 'date' => '2026-06-08', 'intervals' => array( '12:00-13:00' ) ) ),
				'vacations'  => array( array( 'from' => '2026-06-08', 'to' => '2026-06-08' ) ),
			)
		);

		$this->assertSame( array(), $c->intervalsFor( '2026-06-08' ) );
	}

	public function test_bad_dates_are_errors(): void {
		$this->assertNotEmpty( $this->cfg( array( 'exceptions' => array( array( 'date' => '2026-02-30', 'intervals' => array() ) ) ) )->errors() );
		$this->assertNotEmpty( $this->cfg( array( 'vacations' => array( array( 'from' => '2026-06-10', 'to' => '2026-06-01' ) ) ) )->errors() );
	}
}
