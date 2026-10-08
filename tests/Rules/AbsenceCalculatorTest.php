<?php
/**
 * Тесты предложенной методики расчёта отсутствий (docs/ABSENCE_METHOD.md).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Rules;

use Bginfo\PmzhDvzh\Rules\AbsenceCalculator;
use Bginfo\PmzhDvzh\Rules\DateMath;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbsenceCalculatorTest extends TestCase {

	private DateTimeImmutable $windowStart;
	private DateTimeImmutable $filing;

	protected function setUp(): void {
		$this->filing      = DateMath::make( 2026, 10, 8 );
		$this->windowStart = DateMath::addYears( $this->filing, -5 ); // 2021-10-08.
	}

	private static function d( string $date ): DateTimeImmutable {
		$d = DateMath::parse( $date );
		self::assertNotNull( $d, $date );

		return $d;
	}

	/** @return array{out:?DateTimeImmutable,back:?DateTimeImmutable,approx:bool} */
	private static function trip( ?string $out, ?string $back, bool $approx = false ): array {
		return array(
			'out'    => null === $out ? null : self::d( $out ),
			'back'   => null === $back ? null : self::d( $back ),
			'approx' => $approx,
		);
	}

	/** Поездка от даты на N суток. */
	private static function tripDays( string $out, int $days ): array {
		return array(
			'out'    => self::d( $out ),
			'back'   => DateMath::addDays( self::d( $out ), $days ),
			'approx' => false,
		);
	}

	/** @return array<string,mixed> */
	private function calc( array $trips ): array {
		return ( new AbsenceCalculator() )->calculate( $trips, $this->windowStart, $this->filing );
	}

	private function singleClass( string $out, string $back, bool $approx = false ): string {
		$r = $this->calc( array( self::trip( $out, $back, $approx ) ) );

		return $r['trips'][0]['single_class'];
	}

	// --- Один выезд: порог 6 календарных месяцев ---------------------------

	public function testClearlyUnderLimitIsOk(): void {
		$r = $this->calc( array( self::trip( '2024-01-10', '2024-03-10' ) ) );
		$this->assertSame( 'ok', $r['trips'][0]['single_class'] );
		$this->assertSame( '2024-07-10', $r['trips'][0]['limit_date'] );
		$this->assertSame( 60, $r['trips'][0]['nights'] );
		$this->assertSame( array(), $r['invalid'] );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function exactMarginCases(): array {
		// Выезд 2024-01-10, порог 2024-07-10.
		return array(
			'limit - 3 ok'        => array( '2024-07-07', 'ok' ),
			'limit - 2 boundary'  => array( '2024-07-08', 'boundary' ),
			'limit - 1 boundary'  => array( '2024-07-09', 'boundary' ),
			'limit exact (delta 0) boundary' => array( '2024-07-10', 'boundary' ),
			'limit + 1 boundary'  => array( '2024-07-11', 'boundary' ),
			'limit + 2 boundary'  => array( '2024-07-12', 'boundary' ),
			'limit + 3 exceeds'   => array( '2024-07-13', 'exceeds' ),
		);
	}

	#[DataProvider( 'exactMarginCases' )]
	public function testExactDatesMarginIsTwoDays( string $back, string $expected ): void {
		$this->assertSame( $expected, $this->singleClass( '2024-01-10', $back ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function approxMarginCases(): array {
		// Порог 2024-07-10; допуск 31 день: 2024-06-09 … 2024-08-10.
		return array(
			'limit - 32 ok'       => array( '2024-06-08', 'ok' ),
			'limit - 31 boundary' => array( '2024-06-09', 'boundary' ),
			'limit exact'         => array( '2024-07-10', 'boundary' ),
			'limit + 31 boundary' => array( '2024-08-10', 'boundary' ),
			'limit + 32 exceeds'  => array( '2024-08-11', 'exceeds' ),
		);
	}

	#[DataProvider( 'approxMarginCases' )]
	public function testApproxPrecisionWidensMarginTo31( string $back, string $expected ): void {
		$this->assertSame( $expected, $this->singleClass( '2024-01-10', $back, true ) );
	}

	public function testApproxIsWiderThanExact(): void {
		// limit + 10 дней: для точных дат — превышение, для приблизительных — граница.
		$this->assertSame( 'exceeds', $this->singleClass( '2024-01-10', '2024-07-20', false ) );
		$this->assertSame( 'boundary', $this->singleClass( '2024-01-10', '2024-07-20', true ) );
	}

	// --- Високосный год и конец месяца --------------------------------------

	public function testLimitDateClampsToMonthEnd(): void {
		$r = $this->calc( array( self::trip( '2021-08-31', '2021-12-01' ) ) );
		$this->assertSame( '2022-02-28', $r['trips'][0]['limit_date'] );

		$r = $this->calc( array( self::trip( '2023-08-31', '2023-12-01' ) ) );
		$this->assertSame( '2024-02-29', $r['trips'][0]['limit_date'] );
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function leapYearCases(): array {
		return array(
			'31 aug 2023, back 29 feb 2024 = limit'    => array( '2023-08-31', '2024-02-29', 'boundary' ),
			'31 aug 2023, back 26 feb 2024 = limit-3'  => array( '2023-08-31', '2024-02-26', 'ok' ),
			'31 aug 2023, back 3 mar 2024 = limit+3'   => array( '2023-08-31', '2024-03-03', 'exceeds' ),
			'31 aug 2021, back 28 feb 2022 = limit'    => array( '2021-08-31', '2022-02-28', 'boundary' ),
			'31 aug 2021, back 25 feb 2022 = limit-3'  => array( '2021-08-31', '2022-02-25', 'ok' ),
			'31 aug 2021, back 3 mar 2022 = limit+3'   => array( '2021-08-31', '2022-03-03', 'exceeds' ),
			'29 feb 2024 out, limit 29 aug 2024'       => array( '2024-02-29', '2024-08-29', 'boundary' ),
			'29 feb 2024 out, back 26 aug 2024'        => array( '2024-02-29', '2024-08-26', 'ok' ),
		);
	}

	#[DataProvider( 'leapYearCases' )]
	public function testLeapYearAndMonthEndCases( string $out, string $back, string $expected ): void {
		$this->assertSame( $expected, $this->singleClass( $out, $back ) );
	}

	// --- Пересечение начала окна --------------------------------------------

	public function testTripCrossingWindowStartUsesFullIntervalForSixMonths(): void {
		// Окно начинается 2021-10-08. Выезд за 5 мес. до начала, возвращение через 2 мес. после.
		$out  = DateMath::format( DateMath::addMonths( $this->windowStart, -5 ) ); // 2021-05-08.
		$back = DateMath::format( DateMath::addMonths( $this->windowStart, 2 ) );  // 2021-12-08.
		$this->assertSame( '2021-05-08', $out );
		$this->assertSame( '2021-12-08', $back );

		$r = $this->calc( array( self::trip( $out, $back ) ) );
		$row = $r['trips'][0];

		// Порог 2021-11-08, возвращение +30 суток → превышение, хотя в окне только 2 месяца.
		$this->assertSame( '2021-11-08', $row['limit_date'] );
		$this->assertSame( 'exceeds', $row['single_class'] );
		$this->assertTrue( $row['crosses_window'] );
		$this->assertTrue( $row['in_window'] );
		// В сумму идёт только обрезанная часть: 2021-10-08 → 2021-12-08 = 61 сут.
		$this->assertSame( 61, $row['window_nights'] );
		$this->assertSame( 61, $r['total']['nights'] );
		// Полная длительность поездки больше, чем часть в окне.
		$this->assertGreaterThan( 61, $row['nights'] );
	}

	public function testTripEntirelyBeforeWindow(): void {
		$r   = $this->calc( array( self::trip( '2020-01-01', '2020-02-01' ) ) );
		$row = $r['trips'][0];

		$this->assertFalse( $row['in_window'] );
		$this->assertFalse( $row['crosses_window'] );
		$this->assertSame( 0, $row['window_nights'] );
		$this->assertSame( 31, $row['nights'] );
		$this->assertSame( 0, $r['total']['nights'] );
		$this->assertSame( 'ok', $r['total']['class'] );
	}

	public function testLongTripBeforeWindowStillClassifiedButNotCounted(): void {
		// 8 месяцев целиком до окна: класс «exceeds» у самой поездки, но в сумме 0.
		$r = $this->calc( array( self::trip( '2019-01-01', '2019-09-01' ) ) );
		$this->assertSame( 'exceeds', $r['trips'][0]['single_class'] );
		$this->assertFalse( $r['trips'][0]['in_window'] );
		$this->assertSame( 0, $r['total']['nights'] );
	}

	public function testTripEndingAfterFilingIsClippedInTotal(): void {
		$r = $this->calc( array( self::trip( '2026-09-28', '2026-11-01' ) ) );
		$this->assertTrue( $r['trips'][0]['in_window'] );
		$this->assertSame( 10, $r['total']['nights'] ); // 2026-09-28 → 2026-10-08.
		$this->assertSame( 10, $r['trips'][0]['window_nights'] );
	}

	// --- Невалидные и неполные данные ---------------------------------------

	public function testBackBeforeOutIsInvalid(): void {
		$r = $this->calc( array( self::trip( '2024-05-10', '2024-05-01' ) ) );
		$this->assertSame( 'invalid', $r['trips'][0]['single_class'] );
		$this->assertSame( array( 0 ), $r['invalid'] );
		$this->assertNull( $r['trips'][0]['nights'] );
		$this->assertSame( 0, $r['total']['nights'] );
	}

	public function testMissingDatesAreUnknown(): void {
		$r = $this->calc(
			array(
				self::trip( null, '2024-05-01' ),
				self::trip( '2024-05-01', null ),
				self::trip( null, null ),
				self::trip( '2024-01-01', '2024-01-11' ),
			)
		);

		$this->assertSame( 'unknown', $r['trips'][0]['single_class'] );
		$this->assertSame( 'unknown', $r['trips'][1]['single_class'] );
		$this->assertSame( 'unknown', $r['trips'][2]['single_class'] );
		$this->assertSame( array( 0, 1, 2 ), $r['invalid'] );
		// Корректная четвёртая поездка посчитана.
		$this->assertSame( 'ok', $r['trips'][3]['single_class'] );
		$this->assertSame( 10, $r['total']['nights'] );
	}

	public function testEmptyTripsList(): void {
		$r = $this->calc( array() );
		$this->assertSame( array(), $r['trips'] );
		$this->assertSame( 0, $r['total']['nights'] );
		$this->assertSame( 0, $r['total']['lower'] );
		$this->assertSame( 0, $r['total']['upper'] );
		$this->assertSame( 'ok', $r['total']['class'] );
		$this->assertSame( 303, $r['total']['limit_min'] );
		$this->assertSame( 306, $r['total']['limit_max'] );
	}

	// --- Пересечения и слияние ----------------------------------------------

	public function testOverlappingTripsMergedOnceAndReported(): void {
		$r = $this->calc(
			array(
				self::trip( '2023-01-01', '2023-03-01' ),
				self::trip( '2023-02-01', '2023-04-01' ),
			)
		);

		$this->assertSame( array( array( 0, 1 ) ), $r['overlaps'] );
		// Объединение 2023-01-01 → 2023-04-01 = 90 суток, а не 59 + 59.
		$this->assertSame( 90, $r['total']['nights'] );
		// Неопределённость: один объединённый интервал, по 1 сут. на каждый конец.
		$this->assertSame( 88, $r['total']['lower'] );
		$this->assertSame( 92, $r['total']['upper'] );
	}

	public function testNestedTripIsAbsorbed(): void {
		$r = $this->calc(
			array(
				self::trip( '2023-01-01', '2023-06-01' ),
				self::trip( '2023-02-01', '2023-03-01' ),
			)
		);
		$this->assertSame( array( array( 0, 1 ) ), $r['overlaps'] );
		$this->assertSame( DateMath::daysBetween( self::d( '2023-01-01' ), self::d( '2023-06-01' ) ), $r['total']['nights'] );
	}

	public function testTouchingTripsAreMergedButNotReportedAsOverlap(): void {
		$r = $this->calc(
			array(
				self::trip( '2023-01-01', '2023-03-01' ),
				self::trip( '2023-03-01', '2023-04-01' ),
			)
		);
		$this->assertSame( array(), $r['overlaps'] );
		$this->assertSame( 90, $r['total']['nights'] );
		// Один объединённый интервал → неопределённость только на внешних концах.
		$this->assertSame( 88, $r['total']['lower'] );
		$this->assertSame( 92, $r['total']['upper'] );
	}

	public function testUnorderedInputIsHandled(): void {
		$r = $this->calc(
			array(
				self::trip( '2023-06-01', '2023-06-11' ),
				self::trip( '2023-01-01', '2023-01-11' ),
			)
		);
		$this->assertSame( 20, $r['total']['nights'] );
		$this->assertSame( array(), $r['overlaps'] );
	}

	// --- Неопределённость суммы ---------------------------------------------

	public function testUncertaintyPerMergedIntervalExact(): void {
		$r = $this->calc(
			array(
				self::tripDays( '2023-01-01', 10 ),
				self::tripDays( '2023-06-01', 10 ),
			)
		);
		$this->assertSame( 20, $r['total']['nights'] );
		$this->assertSame( 16, $r['total']['lower'] );
		$this->assertSame( 24, $r['total']['upper'] );
	}

	public function testUncertaintyApproxUses31PerEnd(): void {
		$r = $this->calc( array( self::trip( '2023-01-01', '2023-04-11', true ) ) );
		$this->assertSame( 100, $r['total']['nights'] );
		$this->assertSame( 38, $r['total']['lower'] );
		$this->assertSame( 162, $r['total']['upper'] );
	}

	public function testApproxFlagPropagatesToMergedInterval(): void {
		$r = $this->calc(
			array(
				self::trip( '2023-01-01', '2023-02-01', false ),
				self::trip( '2023-01-15', '2023-02-20', true ),
			)
		);
		// Объединено 2023-01-01 → 2023-02-20 = 50 сут., ±31 на каждом конце.
		$this->assertSame( 50, $r['total']['nights'] );
		$this->assertSame( 0, $r['total']['lower'] );
		$this->assertSame( 112, $r['total']['upper'] );
	}

	public function testWindowClippedEndAddsNoUncertaintyOnThatSide(): void {
		// Начало до окна: неопределённость только на конце «возвращение».
		$r = $this->calc( array( self::trip( '2021-09-01', '2021-10-18' ) ) );
		$this->assertSame( 10, $r['total']['nights'] );
		$this->assertSame( 9, $r['total']['lower'] );
		$this->assertSame( 11, $r['total']['upper'] );

		// Конец после даты подачи: неопределённость только на конце «выезд».
		$r = $this->calc( array( self::trip( '2026-09-28', '2026-11-01' ) ) );
		$this->assertSame( 10, $r['total']['nights'] );
		$this->assertSame( 9, $r['total']['lower'] );
		$this->assertSame( 11, $r['total']['upper'] );
	}

	// --- Итоговая сумма vs 10 календарных месяцев ---------------------------

	public function testTotalClearlyBelowLimitIsOk(): void {
		$r = $this->calc( array( self::tripDays( '2022-01-01', 140 ), self::tripDays( '2023-01-01', 140 ) ) );
		$this->assertSame( 280, $r['total']['nights'] );
		$this->assertSame( 284, $r['total']['upper'] );
		$this->assertSame( 'ok', $r['total']['class'] );
		$this->assertSame( 'ok', $r['trips'][0]['single_class'] );
	}

	public function testTotalUpperJustBelowMinimumIsOk(): void {
		// Две поездки: 299 сут. + 4 сут. неопределённости = 303? Нет: upper < 303 обязателен.
		$r = $this->calc( array( self::tripDays( '2022-01-01', 150 ), self::tripDays( '2023-01-01', 148 ) ) );
		$this->assertSame( 298, $r['total']['nights'] );
		$this->assertSame( 302, $r['total']['upper'] );
		$this->assertSame( 'ok', $r['total']['class'] );

		$r = $this->calc( array( self::tripDays( '2022-01-01', 150 ), self::tripDays( '2023-01-01', 149 ) ) );
		$this->assertSame( 303, $r['total']['upper'] );
		$this->assertSame( 'boundary', $r['total']['class'] );
	}

	public function testTotalNearLimitIsBoundary(): void {
		$r = $this->calc(
			array(
				self::tripDays( '2022-01-01', 100 ),
				self::tripDays( '2023-01-01', 100 ),
				self::tripDays( '2024-01-01', 100 ),
			)
		);
		$this->assertSame( 300, $r['total']['nights'] );
		$this->assertSame( 294, $r['total']['lower'] );
		$this->assertSame( 306, $r['total']['upper'] );
		$this->assertSame( 'boundary', $r['total']['class'] );
	}

	public function testTotalClearlyAboveLimitExceeds(): void {
		$r = $this->calc(
			array(
				self::tripDays( '2022-01-01', 110 ),
				self::tripDays( '2023-01-01', 110 ),
				self::tripDays( '2024-01-01', 110 ),
			)
		);
		$this->assertSame( 330, $r['total']['nights'] );
		$this->assertSame( 'exceeds', $r['total']['class'] );
		foreach ( $r['trips'] as $row ) {
			$this->assertSame( 'ok', $row['single_class'], 'каждая поездка по отдельности короткая' );
		}
	}

	public function testTotalLowerExactlyAtMaximumIsBoundary(): void {
		// lower должен быть строго больше 306.
		$r = $this->calc( array( self::tripDays( '2022-01-01', 154 ), self::tripDays( '2023-01-01', 156 ) ) );
		$this->assertSame( 310, $r['total']['nights'] );
		$this->assertSame( 306, $r['total']['lower'] );
		$this->assertSame( 'boundary', $r['total']['class'] );

		$r = $this->calc( array( self::tripDays( '2022-01-01', 154 ), self::tripDays( '2023-01-01', 157 ) ) );
		$this->assertSame( 307, $r['total']['lower'] );
		$this->assertSame( 'exceeds', $r['total']['class'] );
	}

	public function testApproxTripEndingJustBeforeWindowRaisesUpperBoundAndPossibleCrossing(): void {
		// Окно с 2021-10-08; приблизительное возвращение 2021-10-01 (за 7 сут. до начала окна).
		$r = $this->calc( array( self::trip( '2021-02-01', '2021-10-01', true ) ) );
		$this->assertFalse( $r['trips'][0]['crosses_window'] );
		$this->assertTrue( $r['trips'][0]['crosses_window_possible'] );
		$this->assertSame( 0, $r['total']['nights'] );
		$this->assertSame( 24, $r['total']['upper'] ); // 31 - 7.
		$this->assertSame( 0, $r['total']['lower'] );
	}

	public function testExactTripFarBeforeWindowHasNoPossibleCrossing(): void {
		$r = $this->calc( array( self::trip( '2021-02-01', '2021-08-01' ) ) );
		$this->assertFalse( $r['trips'][0]['crosses_window_possible'] );
		$this->assertSame( 0, $r['total']['upper'] );
	}

	public function testLimitsExposedInTotal(): void {
		$r = $this->calc( array( self::tripDays( '2022-01-01', 10 ) ) );
		$this->assertSame( 303, $r['total']['limit_min'] );
		$this->assertSame( 306, $r['total']['limit_max'] );
	}

	public function testClassLabels(): void {
		foreach ( array( 'ok', 'exceeds', 'boundary', 'invalid', 'unknown' ) as $class ) {
			$this->assertNotSame( '', AbsenceCalculator::classLabel( $class ) );
		}
		$this->assertSame( 'нет данных', AbsenceCalculator::classLabel( 'unknown' ) );
	}

	public function testLogMentionsMethodologyForEachTrip(): void {
		$r = $this->calc( array( self::trip( '2024-01-10', '2024-03-10' ) ) );
		$this->assertNotEmpty( $r['log'] );
		$this->assertStringContainsString( 'Окно проверки', $r['log'][0] );
		$this->assertStringContainsString( 'Сумма отсутствий', implode( "\n", $r['log'] ) );
	}
}
