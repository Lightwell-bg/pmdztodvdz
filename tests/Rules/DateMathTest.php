<?php
/**
 * Тесты календарной арифметики дат.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Rules;

use Bginfo\PmzhDvzh\Rules\DateMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateMathTest extends TestCase {

	/** @return array<string,array{0:?string}> */
	public static function invalidDates(): array {
		return array(
			'not leap year'  => array( '2023-02-29' ),
			'month 13'       => array( '2025-13-01' ),
			'garbage'        => array( 'garbage' ),
			'day 31 of apr'  => array( '2025-04-31' ),
			'wrong format'   => array( '08.10.2026' ),
			'with time'      => array( '2026-10-08 10:00' ),
			'empty'          => array( '' ),
			'null'           => array( null ),
			'year too small' => array( '1899-12-31' ),
		);
	}

	#[DataProvider( 'invalidDates' )]
	public function testParseRejectsInvalid( ?string $value ): void {
		$this->assertNull( DateMath::parse( $value ) );
	}

	public function testParseAcceptsLeapDay(): void {
		$d = DateMath::parse( '2024-02-29' );
		$this->assertNotNull( $d );
		$this->assertSame( '2024-02-29', DateMath::format( $d ) );
		$this->assertSame( '29.02.2024', DateMath::human( $d ) );
		$this->assertSame( '00:00:00', $d->format( 'H:i:s' ) );
		$this->assertSame( 'UTC', $d->getTimezone()->getName() );
	}

	public function testFormatAndHumanOfNull(): void {
		$this->assertSame( '', DateMath::format( null ) );
		$this->assertSame( '—', DateMath::human( null ) );
	}

	/** @return array<string,array{0:string,1:int,2:string}> */
	public static function addMonthsCases(): array {
		return array(
			'31 aug +6 -> 28 feb'          => array( '2021-08-31', 6, '2022-02-28' ),
			'31 aug +6 -> 29 feb leap'     => array( '2023-08-31', 6, '2024-02-29' ),
			'29 feb +12 -> 28 feb'         => array( '2024-02-29', 12, '2025-02-28' ),
			'29 feb +48 -> 29 feb'         => array( '2024-02-29', 48, '2028-02-29' ),
			'plain'                        => array( '2024-01-10', 6, '2024-07-10' ),
			'year rollover'                => array( '2024-10-15', 6, '2025-04-15' ),
			'zero'                         => array( '2024-05-31', 0, '2024-05-31' ),
			'negative clamps to leap feb'  => array( '2024-03-31', -1, '2024-02-29' ),
			'negative clamps to 30'        => array( '2024-01-31', -2, '2023-11-30' ),
			'negative over year boundary'  => array( '2024-02-10', -3, '2023-11-10' ),
			'31 jan +1 non-leap'           => array( '2023-01-31', 1, '2023-02-28' ),
			'31 oct +10 -> 31 aug'         => array( '2023-10-31', 10, '2024-08-31' ),
		);
	}

	#[DataProvider( 'addMonthsCases' )]
	public function testAddMonths( string $from, int $months, string $expected ): void {
		$this->assertSame( $expected, DateMath::format( DateMath::addMonths( DateMath::make( ...array_map( 'intval', explode( '-', $from ) ) ), $months ) ) );
	}

	public function testAddYears(): void {
		$d = DateMath::parse( '2024-02-29' );
		$this->assertSame( '2019-02-28', DateMath::format( DateMath::addYears( $d, -5 ) ) );
		$this->assertSame( '2029-02-28', DateMath::format( DateMath::addYears( $d, 5 ) ) );
		$this->assertSame( '2028-02-29', DateMath::format( DateMath::addYears( $d, 4 ) ) );
	}

	public function testAddDays(): void {
		$d = DateMath::parse( '2024-02-28' );
		$this->assertSame( '2024-02-29', DateMath::format( DateMath::addDays( $d, 1 ) ) );
		$this->assertSame( '2024-03-01', DateMath::format( DateMath::addDays( $d, 2 ) ) );
		$this->assertSame( '2024-02-27', DateMath::format( DateMath::addDays( $d, -1 ) ) );
		$this->assertSame( '2024-02-28', DateMath::format( DateMath::addDays( $d, 0 ) ) );
	}

	public function testDaysBetweenSign(): void {
		$a = DateMath::parse( '2024-02-28' );
		$b = DateMath::parse( '2024-03-01' );
		$this->assertSame( 2, DateMath::daysBetween( $a, $b ) );
		$this->assertSame( -2, DateMath::daysBetween( $b, $a ) );
		$this->assertSame( 0, DateMath::daysBetween( $a, $a ) );
		$this->assertSame( 366, DateMath::daysBetween( DateMath::parse( '2024-01-01' ), DateMath::parse( '2025-01-01' ) ) );
		$this->assertSame( 365, DateMath::daysBetween( DateMath::parse( '2025-01-01' ), DateMath::parse( '2026-01-01' ) ) );
	}

	public function testMinMax(): void {
		$a = DateMath::parse( '2024-01-01' );
		$b = DateMath::parse( '2024-06-01' );
		$this->assertSame( '2024-01-01', DateMath::format( DateMath::min( $a, $b ) ) );
		$this->assertSame( '2024-06-01', DateMath::format( DateMath::max( $a, $b ) ) );
		$this->assertSame( '2024-01-01', DateMath::format( DateMath::min( $b, $a ) ) );
		$this->assertSame( '2024-06-01', DateMath::format( DateMath::max( $b, $a ) ) );
	}

	public function testMonthSpanBounds(): void {
		$this->assertSame( array( 303, 306 ), DateMath::monthSpanBounds( 10 ) );
		$this->assertSame( array( 181, 184 ), DateMath::monthSpanBounds( 6 ) );
		// Повторный вызов (кэш) даёт тот же ответ.
		$this->assertSame( array( 303, 306 ), DateMath::monthSpanBounds( 10 ) );
		$this->assertSame( array( 365, 366 ), DateMath::monthSpanBounds( 12 ) );
	}
}
