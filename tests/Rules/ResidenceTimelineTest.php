<?php
/**
 * Тесты покрытия пятилетнего окна статусами.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Rules;

use Bginfo\PmzhDvzh\Rules\DateMath;
use Bginfo\PmzhDvzh\Rules\ResidenceTimeline;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ResidenceTimelineTest extends TestCase {

	private DateTimeImmutable $filing;
	private DateTimeImmutable $windowStart;

	protected function setUp(): void {
		$this->filing      = DateMath::make( 2026, 10, 8 );
		$this->windowStart = DateMath::make( 2021, 10, 8 );
	}

	private static function d( ?string $date ): ?DateTimeImmutable {
		return null === $date ? null : DateMath::parse( $date );
	}

	/** @return array{kind:string,from:?DateTimeImmutable,to:?DateTimeImmutable,end:string,approx:bool} */
	private static function st( string $kind, ?string $from, ?string $to, string $end, bool $approx = false ): array {
		return array(
			'kind'   => $kind,
			'from'   => self::d( $from ),
			'to'     => self::d( $to ),
			'end'    => $end,
			'approx' => $approx,
		);
	}

	/** @return array{from:?DateTimeImmutable,to:?DateTimeImmutable} */
	private static function gap( ?string $from, ?string $to ): array {
		return array( 'from' => self::d( $from ), 'to' => self::d( $to ) );
	}

	/** @return array<string,mixed> */
	private function analyse( array $statuses, array $gaps = array(), bool $complete = true ): array {
		return ( new ResidenceTimeline() )->analyse( $statuses, $gaps, $complete, $this->windowStart, $this->filing );
	}

	public function testOngoingPmzhCoversWindowToFilingWithoutGap(): void {
		// Карты в данных нет вообще: окончание срока карты не может создать разрыв.
		$r = $this->analyse( array( self::st( 'pmzh', '2019-03-15', null, 'ongoing' ) ) );

		$this->assertSame( array(), $r['uncovered'] );
		$this->assertFalse( $r['head_uncovered'] );
		$this->assertFalse( $r['insufficient'] );
		$this->assertSame( array(), $r['unknown_status_indexes'] );
		$this->assertSame( '2019-03-15', $r['earliest_start'] );
		$this->assertSame( '', $r['earliest_filing'] );
	}

	public function testPermanentKindWithUnknownEndStillCoversToFiling(): void {
		$r = $this->analyse( array( self::st( 'pmzh', '2019-03-15', null, 'unknown' ) ) );
		$this->assertSame( array(), $r['uncovered'] );
		$this->assertSame( array(), $r['unknown_status_indexes'] );
	}

	public function testPriorVnzhEndedDayBeforePmzhIsAdjacentNoGap(): void {
		$r = $this->analyse(
			array(
				self::st( 'vnzh', '2019-01-10', '2023-05-09', 'ended' ),
				self::st( 'pmzh', '2023-05-10', null, 'ongoing' ),
			)
		);
		$this->assertSame( array(), $r['uncovered'] );
		$this->assertFalse( $r['head_uncovered'] );
	}

	public function testStatusesOverlappingDoNotCreateGap(): void {
		$r = $this->analyse(
			array(
				self::st( 'vnzh', '2019-01-10', '2023-06-30', 'ended' ),
				self::st( 'pmzh', '2023-05-10', null, 'ongoing' ),
			)
		);
		$this->assertSame( array(), $r['uncovered'] );
	}

	public function testTenDayHoleBetweenStatusesIsUncoveredButNotConfirmedGap(): void {
		$r = $this->analyse(
			array(
				self::st( 'vnzh', '2019-01-10', '2023-05-09', 'ended' ),
				self::st( 'pmzh', '2023-05-19', null, 'ongoing' ),
			)
		);

		$this->assertSame( array( array( 'from' => '2023-05-09', 'to' => '2023-05-19' ) ), $r['uncovered'] );
		// Это «нужна проверка», а не препятствие.
		$this->assertFalse( $r['confirmed_gap_in_window'] );
		$this->assertFalse( $r['head_uncovered'] );
		$this->assertFalse( $r['insufficient'] );
	}

	public function testHoleBeforeFilingAtTheTail(): void {
		$r = $this->analyse( array( self::st( 'vnzh', '2019-01-10', '2025-01-01', 'ended' ) ) );
		$this->assertSame( array( array( 'from' => '2025-01-01', 'to' => '2026-10-08' ) ), $r['uncovered'] );
	}

	public function testDeclaredGapWithDatesInsideWindowIsConfirmed(): void {
		$r = $this->analyse(
			array( self::st( 'pmzh', '2019-03-15', null, 'ongoing' ) ),
			array( self::gap( '2023-05-10', '2023-05-18' ) )
		);
		$this->assertTrue( $r['confirmed_gap_in_window'] );
		$this->assertFalse( $r['gap_dates_unknown'] );
	}

	public function testDeclaredGapOverlappingWindowStartIsConfirmed(): void {
		$r = $this->analyse(
			array( self::st( 'pmzh', '2019-03-15', null, 'ongoing' ) ),
			array( self::gap( '2021-09-01', '2021-10-09' ) )
		);
		$this->assertTrue( $r['confirmed_gap_in_window'] );
	}

	public function testDeclaredGapBeforeWindowIsNotConfirmed(): void {
		$r = $this->analyse(
			array( self::st( 'pmzh', '2019-03-15', null, 'ongoing' ) ),
			array( self::gap( '2019-05-10', '2019-05-18' ), self::gap( '2021-09-01', '2021-10-08' ) )
		);
		$this->assertFalse( $r['confirmed_gap_in_window'] );
		$this->assertFalse( $r['gap_dates_unknown'] );
	}

	public function testDeclaredGapWithoutDatesIsUnknown(): void {
		$r = $this->analyse(
			array( self::st( 'pmzh', '2019-03-15', null, 'ongoing' ) ),
			array( self::gap( null, null ) )
		);
		$this->assertTrue( $r['gap_dates_unknown'] );
		$this->assertFalse( $r['confirmed_gap_in_window'] );

		$r = $this->analyse(
			array( self::st( 'pmzh', '2019-03-15', null, 'ongoing' ) ),
			array( self::gap( '2023-01-01', null ) )
		);
		$this->assertTrue( $r['gap_dates_unknown'] );
	}

	public function testEarliestStartAfterWindowStartWithCompleteHistoryIsInsufficient(): void {
		$r = $this->analyse( array( self::st( 'pmzh', '2023-05-10', null, 'ongoing' ) ), array(), true );

		$this->assertTrue( $r['insufficient'] );
		$this->assertSame( '2023-05-10', $r['earliest_start'] );
		$this->assertSame( '2028-05-10', $r['earliest_filing'] );
		$this->assertTrue( $r['head_uncovered'] );
	}

	public function testEarliestFilingClampsLeapDay(): void {
		$r = $this->analyse( array( self::st( 'pmzh', '2024-02-29', null, 'ongoing' ) ), array(), true );
		$this->assertSame( '2029-02-28', $r['earliest_filing'] );
	}

	public function testIncompleteHistoryGivesHeadUncoveredNotInsufficient(): void {
		$r = $this->analyse( array( self::st( 'pmzh', '2023-05-10', null, 'ongoing' ) ), array(), false );

		$this->assertFalse( $r['insufficient'] );
		$this->assertTrue( $r['head_uncovered'] );
		// Ориентир даты всё равно рассчитан.
		$this->assertSame( '2028-05-10', $r['earliest_filing'] );
		$this->assertSame( array( array( 'from' => '2021-10-08', 'to' => '2023-05-10' ) ), $r['uncovered'] );
	}

	public function testApproxStartNearWindowStartSuppressesInsufficient(): void {
		$r = $this->analyse( array( self::st( 'vnzh', '2021-10-20', null, 'ongoing', true ) ), array(), true );

		$this->assertTrue( $r['approx_near_window'] );
		$this->assertFalse( $r['insufficient'] );
		$this->assertTrue( $r['head_uncovered'] );
	}

	public function testApproxFarFromWindowStartDoesNotSetFlag(): void {
		$r = $this->analyse( array( self::st( 'pmzh', '2019-03-01', null, 'ongoing', true ) ) );
		$this->assertFalse( $r['approx_near_window'] );
	}

	public function testUnknownEndOfNonPermanentStatusIsReported(): void {
		$r = $this->analyse(
			array(
				self::st( 'vnzh', '2019-01-01', null, 'unknown' ),
				self::st( 'pmzh', '2023-05-10', null, 'ongoing' ),
			)
		);
		$this->assertSame( array( 0 ), $r['unknown_status_indexes'] );
	}

	public function testMissingStartDateIsReportedAndSkipped(): void {
		$r = $this->analyse(
			array(
				self::st( 'vnzh', null, null, 'unknown' ),
				self::st( 'pmzh', '2019-03-15', null, 'ongoing' ),
			)
		);
		$this->assertSame( array( 0 ), $r['unknown_status_indexes'] );
		$this->assertSame( '2019-03-15', $r['earliest_start'] );
		$this->assertSame( array(), $r['uncovered'] );
	}

	public function testEndedWithoutEndDateIsUnknown(): void {
		$r = $this->analyse( array( self::st( 'vnzh', '2019-01-01', null, 'ended' ) ) );
		$this->assertSame( array( 0 ), $r['unknown_status_indexes'] );
	}

	public function testEndBeforeStartIsExcluded(): void {
		$r = $this->analyse(
			array(
				self::st( 'vnzh', '2023-01-01', '2022-01-01', 'ended' ),
				self::st( 'pmzh', '2019-03-15', null, 'ongoing' ),
			)
		);
		$this->assertSame( array( 0 ), $r['unknown_status_indexes'] );
		$this->assertSame( array(), $r['uncovered'] );
	}

	public function testOngoingNonPermanentStatusCoversToFiling(): void {
		$r = $this->analyse( array( self::st( 'vnzh', '2019-01-01', null, 'ongoing' ) ) );
		$this->assertSame( array(), $r['uncovered'] );
		$this->assertSame( array(), $r['unknown_status_indexes'] );
	}

	public function testNoStatusesAtAll(): void {
		$r = $this->analyse( array() );
		$this->assertSame( array( array( 'from' => '2021-10-08', 'to' => '2026-10-08' ) ), $r['uncovered'] );
		$this->assertTrue( $r['head_uncovered'] );
		$this->assertFalse( $r['insufficient'] );
		$this->assertSame( '', $r['earliest_start'] );
	}

	public function testSpecialKindsDetectedWhenOverlappingWindow(): void {
		$r = $this->analyse(
			array(
				self::st( 'study_24v', '2021-01-01', '2023-01-01', 'ended' ),
				self::st( 'blue_card', '2023-01-02', '2024-01-01', 'ended' ),
				self::st( 'intl_protection', '2024-01-02', '2024-06-01', 'ended' ),
				self::st( 'temp_protection', '2024-06-02', '2024-12-01', 'ended' ),
				self::st( 'other', '2024-12-02', '2025-06-01', 'ended' ),
				self::st( 'vnzh', '2025-06-02', null, 'ongoing' ),
			)
		);

		$kinds = $r['special_kinds'];
		sort( $kinds );
		$this->assertSame( array( 'blue_card', 'intl_protection', 'other', 'study_24v', 'temp_protection' ), $kinds );
		$this->assertNotContains( 'vnzh', $kinds );
	}

	public function testSpecialKindEndedBeforeWindowIsIgnored(): void {
		$r = $this->analyse(
			array(
				self::st( 'study_24v', '2018-01-01', '2019-01-01', 'ended' ),
				self::st( 'pmzh', '2019-01-02', null, 'ongoing' ),
			)
		);
		$this->assertSame( array(), $r['special_kinds'] );
	}

	public function testSpecialKindEndedExactlyOnWindowStartIsIgnored(): void {
		$r = $this->analyse(
			array(
				self::st( 'study_24v', '2018-01-01', '2021-10-08', 'ended' ),
				self::st( 'pmzh', '2021-10-09', null, 'ongoing' ),
			)
		);
		$this->assertSame( array(), $r['special_kinds'] );
	}

	public function testKindLabelsAreDefined(): void {
		foreach ( array_merge( array( 'vnzh', 'pmzh' ), ResidenceTimeline::SPECIAL_KINDS ) as $kind ) {
			$this->assertNotSame( 'статус', ResidenceTimeline::kindLabel( $kind ), $kind );
		}
		$this->assertSame( 'статус', ResidenceTimeline::kindLabel( 'nonsense' ) );
	}

	public function testLogDescribesStatusesAndHoles(): void {
		$r = $this->analyse(
			array(
				self::st( 'vnzh', '2019-01-10', '2023-05-09', 'ended' ),
				self::st( 'pmzh', '2023-05-19', null, 'ongoing' ),
			)
		);
		$log = implode( "\n", $r['log'] );
		$this->assertStringContainsString( '09.05.2023', $log );
		$this->assertStringContainsString( 'не подтверждён известными статусами', $log );
	}
}
