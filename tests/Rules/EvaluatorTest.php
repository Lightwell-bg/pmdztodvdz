<?php
/**
 * Сценарные тесты Evaluator на полных нормализованных ответах.
 *
 * Дата расчёта во всех сценариях — 2026-10-08, окно — 2021-10-08 … 2026-10-08.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Rules;

use Bginfo\PmzhDvzh\Instruction\DefaultTemplates;
use Bginfo\PmzhDvzh\Rules\DateMath;
use Bginfo\PmzhDvzh\Rules\Evaluator;
use Bginfo\PmzhDvzh\Rules\RulesMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase {

	/**
	 * @param array<string,mixed> $override
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	private function run_( array $override = array(), array $config = array() ): array {
		return ( new Evaluator() )->evaluate( AnswersFactory::with( $override ), $config + AnswersFactory::config() );
	}

	/** @return array<string,array<string,mixed>> code => finding */
	private static function byCode( array $result ): array {
		$out = array();

		foreach ( $result['findings'] as $f ) {
			$out[ $f['code'] ] = $f;
		}

		return $out;
	}

	/** @return list<string> */
	private static function codes( array $result ): array {
		return array_keys( self::byCode( $result ) );
	}

	private function assertHas( string $code, array $result, ?string $severity = null ): void {
		$codes = self::byCode( $result );
		$this->assertArrayHasKey( $code, $codes, 'ожидался ' . $code . ', есть: ' . implode( ',', array_keys( $codes ) ) );

		if ( null !== $severity ) {
			$this->assertSame( $severity, $codes[ $code ]['severity'], $code );
		}
	}

	private function assertNotHas( string $code, array $result ): void {
		$this->assertNotContains( $code, self::codes( $result ) );
	}

	// --- Общие поля результата ---------------------------------------------

	public function testResultEnvelope(): void {
		$r = $this->run_();
		$this->assertSame( RulesMeta::VERSION, $r['rules_version'] );
		$this->assertSame( RulesMeta::VERIFIED_AT, $r['verified_at'] );
		$this->assertSame( '2026-10-08', $r['calc_date'] );
		$this->assertSame( '2026-10-08', $r['filing_date'] );
		$this->assertSame( '2021-10-08', $r['window_start'] );
		$this->assertTrue( $r['filing_is_default'] );
		$this->assertIsArray( $r['log'] );
		$this->assertArrayHasKey( 'timeline', $r );
		$this->assertArrayHasKey( 'absences', $r );
	}

	// --- Полностью подходящий -----------------------------------------------

	public function testFullySuitableIsOk(): void {
		$r = $this->run_();

		$this->assertSame( 'ok', $r['status'] );
		foreach ( array( 'RES_OK', 'TRIP_OK', 'HOUSING_OK', 'HEALTH_NZOK_OK', 'FUNDS_REGULAR_OK' ) as $code ) {
			$this->assertHas( $code, $r, 'ok' );
		}
		foreach ( $r['findings'] as $f ) {
			$this->assertNotContains( $f['severity'], array( 'obstacle', 'check' ), $f['code'] );
		}
	}

	public function testExpiredPmzhCardDoesNotCreateGap(): void {
		$r = $this->run_( array( 'documents' => array( 'pmzh_card_until' => '2024-01-01' ) ) );

		$this->assertSame( 'ok', $r['status'] );
		$this->assertHas( 'RES_OK', $r );
		$this->assertNotHas( 'RES_UNCOVERED', $r );
		$this->assertNotHas( 'RES_GAP_CONFIRMED', $r );
		$this->assertSame( array(), $r['timeline']['uncovered'] );
		$this->assertHas( 'DOC_PMZH_CARD', $r, 'info' );
	}

	// --- Проживание ---------------------------------------------------------

	public function testConfirmedGapIsObstacle(): void {
		$r = $this->run_( array( 'history' => array( 'gap_without_permit' => 'yes', 'gaps' => array( array( 'from' => '2023-05-10', 'to' => '2023-05-18' ) ) ) ) );

		$this->assertSame( 'obstacle', $r['status'] );
		$this->assertHas( 'RES_GAP_CONFIRMED', $r, 'obstacle' );
		$this->assertNotHas( 'RES_OK', $r );
	}

	public function testGapOutsideWindowIsNotObstacle(): void {
		$r = $this->run_( array( 'history' => array( 'gap_without_permit' => 'yes', 'gaps' => array( array( 'from' => '2019-05-10', 'to' => '2019-05-18' ) ) ) ) );
		$this->assertNotHas( 'RES_GAP_CONFIRMED', $r );
		$this->assertNotHas( 'RES_GAP_DATES_UNKNOWN', $r );
		$this->assertSame( 'ok', $r['status'] );
	}

	public function testGapWithoutDatesIsCheck(): void {
		$r = $this->run_( array( 'history' => array( 'gap_without_permit' => 'yes', 'gaps' => array() ) ) );
		$this->assertSame( 'check', $r['status'] );
		$this->assertHas( 'RES_GAP_DATES_UNKNOWN', $r, 'check' );
		$this->assertNotHas( 'RES_GAP_CONFIRMED', $r );
	}

	public function testInsufficientResidenceIsObstacleWithEarliestFiling(): void {
		$r = $this->run_( array( 'pmzh' => array( 'decision_date' => '2023-05-10' ) ) );

		$this->assertSame( 'obstacle', $r['status'] );
		$this->assertHas( 'RES_INSUFFICIENT', $r, 'obstacle' );
		$this->assertSame( '10.05.2023', self::byCode( $r )['RES_INSUFFICIENT']['params']['earliest_start'] );
		$this->assertSame( '10.05.2028', self::byCode( $r )['RES_INSUFFICIENT']['params']['earliest_filing'] );
		$this->assertNotHas( 'RES_HEAD_UNCOVERED', $r );
	}

	public function testIncompleteHistoryGivesHeadUncoveredCheck(): void {
		$r = $this->run_( array( 'pmzh' => array( 'decision_date' => '2023-05-10' ), 'statuses_complete' => 'no' ) );

		$this->assertSame( 'check', $r['status'] );
		$this->assertHas( 'RES_HEAD_UNCOVERED', $r, 'check' );
		$this->assertNotHas( 'RES_INSUFFICIENT', $r );
	}

	public function testHoleBetweenStatusesIsCheckNotObstacle(): void {
		$r = $this->run_(
			array(
				'pmzh'     => array( 'decision_date' => '2023-05-19' ),
				'statuses' => array( AnswersFactory::status( 'vnzh', '2019-01-10', 'ended', '2023-05-09' ) ),
			)
		);
		$this->assertSame( 'check', $r['status'] );
		$this->assertHas( 'RES_UNCOVERED', $r, 'check' );
		$this->assertStringContainsString( '09.05.2023', self::byCode( $r )['RES_UNCOVERED']['params']['periods'] );
	}

	public function testAdjacentStatusesAreOk(): void {
		$r = $this->run_(
			array(
				'pmzh'     => array( 'decision_date' => '2023-05-10' ),
				'statuses' => array( AnswersFactory::status( 'vnzh', '2019-01-10', 'ended', '2023-05-09' ) ),
			)
		);
		$this->assertSame( 'ok', $r['status'] );
		$this->assertHas( 'RES_OK', $r );
	}

	public function testPmzhDateUnknownIsCheck(): void {
		$r = $this->run_( array( 'pmzh' => array( 'date_precision' => 'unknown', 'decision_date' => '' ) ) );
		$this->assertHas( 'PMZH_DATE_UNKNOWN', $r, 'check' );
		$this->assertNotSame( 'ok', $r['status'] );
	}

	// --- Поездки ------------------------------------------------------------

	public function testSingleTripOverSixMonthsIsObstacleWithEarliestFiling(): void {
		$r = $this->run_(
			array(
				'trips_any'      => 'yes',
				'trips'          => array( AnswersFactory::trip( '2024-01-10', '2024-08-20' ) ),
				'trips_complete' => 'complete',
			)
		);

		$this->assertSame( 'obstacle', $r['status'] );
		$this->assertHas( 'TRIP_SINGLE_EXCEEDS', $r, 'obstacle' );
		$this->assertNotHas( 'TRIP_OK', $r );
		$p = self::byCode( $r )['TRIP_SINGLE_EXCEEDS']['params'];
		$this->assertSame( '20.08.2029', $p['earliest_filing'] );
		$this->assertSame( '10.07.2024', $p['limit'] );
		$this->assertSame( 1, $p['n'] );
	}

	public function testSingleTripBoundaryIsCheck(): void {
		$r = $this->run_( array( 'trips_any' => 'yes', 'trips' => array( AnswersFactory::trip( '2024-01-10', '2024-07-10' ) ), 'trips_complete' => 'complete' ) );
		$this->assertSame( 'check', $r['status'] );
		$this->assertHas( 'TRIP_SINGLE_BOUNDARY', $r, 'check' );
	}

	public function testElevenMonthsOfShortTripsExceedsTotal(): void {
		$r = $this->run_(
			array(
				'trips_any'      => 'yes',
				'trips_complete' => 'complete',
				'trips'          => array(
					AnswersFactory::trip( '2022-01-10', '2022-03-17' ),
					AnswersFactory::trip( '2022-06-01', '2022-08-07' ),
					AnswersFactory::trip( '2022-10-01', '2022-12-07' ),
					AnswersFactory::trip( '2023-02-01', '2023-04-09' ),
					AnswersFactory::trip( '2023-06-01', '2023-08-08' ),
				),
			)
		);

		$this->assertSame( 'obstacle', $r['status'] );
		$this->assertHas( 'TRIP_TOTAL_EXCEEDS', $r, 'obstacle' );
		$this->assertNotHas( 'TRIP_SINGLE_EXCEEDS', $r );
		$this->assertGreaterThan( 306, self::byCode( $r )['TRIP_TOTAL_EXCEEDS']['params']['nights'] );
	}

	public function testUnknownTripsIsCheckIncomplete(): void {
		$r = $this->run_( array( 'trips_any' => 'unknown' ) );
		$this->assertSame( 'check', $r['status'] );
		$this->assertHas( 'TRIP_INCOMPLETE', $r, 'check' );
		$this->assertNotHas( 'TRIP_OK', $r );
	}

	public function testPartialTripListIsCheckIncomplete(): void {
		$r = $this->run_( array( 'trips_any' => 'yes', 'trips' => array( AnswersFactory::trip( '2024-01-10', '2024-02-10' ) ), 'trips_complete' => 'partial' ) );
		$this->assertHas( 'TRIP_INCOMPLETE', $r, 'check' );
		$this->assertNotHas( 'TRIP_OK', $r );
	}

	public function testInvalidAndOverlappingTripsAreChecks(): void {
		$r = $this->run_(
			array(
				'trips_any'      => 'yes',
				'trips_complete' => 'complete',
				'trips'          => array(
					AnswersFactory::trip( '2023-05-10', '2023-05-01' ),
					AnswersFactory::trip( '2023-01-01', '2023-03-01' ),
					AnswersFactory::trip( '2023-02-01', '2023-04-01' ),
				),
			)
		);
		$this->assertHas( 'TRIP_DATES_INVALID', $r, 'check' );
		$this->assertHas( 'TRIP_OVERLAP', $r, 'check' );
		$this->assertSame( '1', self::byCode( $r )['TRIP_DATES_INVALID']['params']['list'] );
		$this->assertSame( '2 и 3', self::byCode( $r )['TRIP_OVERLAP']['params']['list'] );
	}

	public function testTripCrossingWindowStartIsInfoOnly(): void {
		$r = $this->run_( array( 'trips_any' => 'yes', 'trips_complete' => 'complete', 'trips' => array( AnswersFactory::trip( '2021-09-20', '2021-11-20' ) ) ) );
		$this->assertHas( 'TRIP_CROSSES_WINDOW', $r, 'info' );
		$this->assertHas( 'TRIP_OK', $r, 'ok' );
		$this->assertSame( 'ok', $r['status'] );
	}

	public function testTripBeforeWindowIsIgnored(): void {
		$r = $this->run_( array( 'trips_any' => 'yes', 'trips_complete' => 'complete', 'trips' => array( AnswersFactory::trip( '2019-01-01', '2019-09-01' ) ) ) );
		$this->assertNotHas( 'TRIP_SINGLE_EXCEEDS', $r );
		$this->assertSame( 'ok', $r['status'] );
	}

	// --- Особые основания ---------------------------------------------------

	public function testStudyStatusInWindowIsCheckWithHalfDays(): void {
		$r = $this->run_(
			array(
				'pmzh'     => array( 'decision_date' => '2023-01-02' ),
				'statuses' => array( AnswersFactory::status( 'study_24v', '2021-01-01', 'ended', '2023-01-01' ) ),
			)
		);

		$this->assertSame( 'check', $r['status'] );
		$this->assertHas( 'SPEC_STUDY', $r, 'check' );

		$days = DateMath::daysBetween( DateMath::make( 2021, 10, 8 ), DateMath::make( 2023, 1, 1 ) );
		$p    = self::byCode( $r )['SPEC_STUDY']['params'];
		$this->assertSame( $days, $p['study_days'] );
		$this->assertSame( intdiv( $days, 2 ), $p['half_days'] );
	}

	public function testStudyFlagWithoutStatusStillCheck(): void {
		$r = $this->run_( array( 'special' => array( 'study_24v' => 'yes' ) ) );
		$this->assertHas( 'SPEC_STUDY', $r, 'check' );
		$this->assertSame( 0, self::byCode( $r )['SPEC_STUDY']['params']['study_days'] );
	}

	public function testStudiedOnOtherBasisIsInfoOnly(): void {
		$r = $this->run_( array( 'special' => array( 'studied_other_basis' => 'yes' ) ) );

		$this->assertHas( 'SPEC_STUDY_OTHER_BASIS', $r, 'info' );
		$this->assertNotHas( 'SPEC_STUDY', $r );
		$this->assertSame( 'ok', $r['status'], 'info не должен переводить статус в check' );
	}

	public function testStudyOtherBasisSuppressedWhenStudy24vPresent(): void {
		$r = $this->run_( array( 'special' => array( 'studied_other_basis' => 'yes', 'study_24v' => 'yes' ) ) );
		$this->assertHas( 'SPEC_STUDY', $r );
		$this->assertNotHas( 'SPEC_STUDY_OTHER_BASIS', $r );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string}> */
	public static function specialCases(): array {
		return array(
			'blue card'            => array( array( 'special' => array( 'blue_card' => 'yes' ) ), 'SPEC_BLUE_CARD' ),
			'blue card unknown'    => array( array( 'special' => array( 'blue_card' => 'unknown' ) ), 'SPEC_BLUE_CARD' ),
			'international'        => array( array( 'special' => array( 'protection' => 'international' ) ), 'SPEC_INTL_PROTECTION' ),
			'temporary protection' => array( array( 'special' => array( 'protection' => 'temporary' ) ), 'SPEC_TEMP_PROTECTION' ),
			'protection unknown'   => array( array( 'special' => array( 'protection' => 'unknown' ) ), 'SPEC_PROTECTION_UNKNOWN' ),
			'temporary basis'      => array( array( 'special' => array( 'temporary_basis' => 'yes' ) ), 'SPEC_TEMPORARY_BASIS' ),
			'other eu ltr'         => array( array( 'special' => array( 'other_eu_status' => 'ltr' ) ), 'SPEC_OTHER_EU' ),
			'eu citizen'           => array( array( 'applicant' => array( 'eu_status' => 'eu_citizen' ) ), 'APP_EU_CITIZEN' ),
			'bg citizen'           => array( array( 'applicant' => array( 'eu_status' => 'bg_citizen' ) ), 'APP_EU_CITIZEN' ),
			'eu status unknown'    => array( array( 'applicant' => array( 'eu_status' => 'unknown' ) ), 'APP_EU_STATUS_UNKNOWN' ),
			'minor'                => array( array( 'applicant' => array( 'age' => 15 ) ), 'APP_MINOR' ),
			'adverse decisions'    => array( array( 'documents' => array( 'adverse' => 'yes' ) ), 'DOC_ADVERSE' ),
			'entry ban'            => array( array( 'documents' => array( 'entry_ban' => 'unknown' ) ), 'DOC_ENTRY_BAN' ),
			'pending decision'     => array( array( 'history' => array( 'pending_decision' => 'yes' ) ), 'RES_PENDING' ),
			'late renewal'         => array( array( 'history' => array( 'late_renewal' => 'yes' ) ), 'RES_LATE_OR_REFUSAL' ),
			'refusal'              => array( array( 'history' => array( 'refusal' => 'yes' ) ), 'RES_LATE_OR_REFUSAL' ),
		);
	}

	/** @param array<string,mixed> $override */
	#[DataProvider( 'specialCases' )]
	public function testSpecialCasesMakeStatusCheck( array $override, string $code ): void {
		$r = $this->run_( $override );
		$this->assertHas( $code, $r, 'check' );
		$this->assertSame( 'check', $r['status'] );
	}

	public function testBlueCardStatusKindInWindowDetected(): void {
		$r = $this->run_(
			array(
				'pmzh'     => array( 'decision_date' => '2024-01-02' ),
				'statuses' => array( AnswersFactory::status( 'blue_card', '2019-01-01', 'ended', '2024-01-01' ) ),
			)
		);
		$this->assertHas( 'SPEC_BLUE_CARD', $r, 'check' );
	}

	public function testMinorAndFamilyParams(): void {
		$r = $this->run_( array( 'applicant' => array( 'age' => 15, 'family' => 'family' ) ) );
		$this->assertSame( 15, self::byCode( $r )['APP_MINOR']['params']['age'] );
		$this->assertHas( 'APP_FAMILY', $r, 'info' );
	}

	// --- Здоровье -----------------------------------------------------------

	public function testNoHealthCoverIsObstacle(): void {
		$r = $this->run_( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ) );
		$this->assertSame( 'obstacle', $r['status'] );
		$this->assertHas( 'HEALTH_NONE', $r, 'obstacle' );
	}

	public function testNzokYesDoesNotDemandPolicy(): void {
		$r = $this->run_( array( 'health' => array( 'nzok' => 'yes', 'contributions' => 'ok', 'other_cover' => 'none' ) ) );
		foreach ( array( 'HEALTH_NONE', 'HEALTH_POLICY_PERIOD', 'HEALTH_POLICY_OK', 'HEALTH_UNKNOWN' ) as $code ) {
			$this->assertNotHas( $code, $r );
		}
		$this->assertHas( 'HEALTH_NZOK_OK', $r, 'ok' );
	}

	public function testNzokWithContributionGapsIsCheck(): void {
		$r = $this->run_( array( 'health' => array( 'contributions' => 'gaps' ) ) );
		$this->assertHas( 'HEALTH_NZOK_GAPS', $r, 'check' );
		$this->assertFalse( self::byCode( $r )['HEALTH_NZOK_GAPS']['params']['unknown'] );
		$r = $this->run_( array( 'health' => array( 'contributions' => 'unknown' ) ) );
		$this->assertTrue( self::byCode( $r )['HEALTH_NZOK_GAPS']['params']['unknown'] );
	}

	public function testPolicyPeriod(): void {
		$r = $this->run_( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'policy', 'cover_until' => '2028-01-01' ) ) );
		$this->assertHas( 'HEALTH_POLICY_OK', $r, 'ok' );

		$r = $this->run_( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'policy', 'cover_until' => '2027-01-01' ) ) );
		$this->assertHas( 'HEALTH_POLICY_PERIOD', $r, 'check' );

		$r = $this->run_( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'policy', 'cover_until' => '' ) ) );
		$this->assertHas( 'HEALTH_POLICY_PERIOD', $r, 'check' );
	}

	public function testHealthUnknown(): void {
		$r = $this->run_( array( 'health' => array( 'nzok' => 'unknown' ) ) );
		$this->assertHas( 'HEALTH_UNKNOWN', $r, 'check' );
	}

	// --- Жильё --------------------------------------------------------------

	public function testHousing(): void {
		$r = $this->run_( array( 'housing' => array( 'tenure' => 'rent', 'proofs' => array( 'lease' ) ) ) );
		$this->assertHas( 'HOUSING_RENT_DECLARATION', $r, 'check' );

		$r = $this->run_( array( 'housing' => array( 'tenure' => 'rent', 'proofs' => array( 'lease', 'owner_declaration' ) ) ) );
		$this->assertHas( 'HOUSING_OK', $r, 'ok' );

		$r = $this->run_( array( 'housing' => array( 'tenure' => 'own', 'proofs' => array( 'none' ) ) ) );
		$this->assertHas( 'HOUSING_NO_PROOF', $r, 'check' );

		$r = $this->run_( array( 'housing' => array( 'tenure' => 'unknown' ) ) );
		$this->assertHas( 'HOUSING_UNKNOWN', $r, 'check' );
	}

	/** @return array<string,array{0:string,1:array<int,string>,2:string,3:string}> */
	public static function housingCases(): array {
		return array(
			'own: only address registration' => array( 'own', array( 'address_registration' ), 'HOUSING_NO_PROOF', 'check' ),
			'own: lease is not enough'       => array( 'own', array( 'lease' ), 'HOUSING_NO_PROOF', 'check' ),
			'own: notarial act'              => array( 'own', array( 'notarial_act' ), 'HOUSING_OK', 'ok' ),
			'rent: declaration only'         => array( 'rent', array( 'owner_declaration' ), 'HOUSING_RENT_LEASE', 'check' ),
			'rent: address only'             => array( 'rent', array( 'address_registration' ), 'HOUSING_RENT_LEASE', 'check' ),
			'rent: lease only'               => array( 'rent', array( 'lease', 'address_registration' ), 'HOUSING_RENT_DECLARATION', 'check' ),
			'rent: both'                     => array( 'rent', array( 'lease', 'owner_declaration' ), 'HOUSING_OK', 'ok' ),
			'family: address only'           => array( 'family', array( 'address_registration' ), 'HOUSING_OTHER_BASIS', 'check' ),
			'family: declaration'            => array( 'family', array( 'owner_declaration' ), 'HOUSING_OK', 'ok' ),
			'other: lease only'              => array( 'other', array( 'lease' ), 'HOUSING_OTHER_BASIS', 'check' ),
			'other: declaration'             => array( 'other', array( 'owner_declaration' ), 'HOUSING_OK', 'ok' ),
		);
	}

	/** @param array<int,string> $proofs */
	#[DataProvider( 'housingCases' )]
	public function testHousingByBasis( string $tenure, array $proofs, string $code, string $severity ): void {
		$r = $this->run_( array( 'housing' => array( 'tenure' => $tenure, 'proofs' => $proofs ) ) );
		$this->assertHas( $code, $r, $severity );
	}

	public function testUnknownGapLateRenewalAndRefusalAreChecks(): void {
		$r = $this->run_( array( 'history' => array( 'gap_without_permit' => 'unknown' ) ) );
		$this->assertHas( 'RES_GAP_UNKNOWN', $r, 'check' );
		$this->assertNotSame( 'ok', $r['status'] );
		$this->assertNotHas( 'RES_OK', $r );

		$r = $this->run_( array( 'history' => array( 'late_renewal' => 'unknown' ) ) );
		$this->assertHas( 'RES_LATE_OR_REFUSAL', $r, 'check' );

		$r = $this->run_( array( 'history' => array( 'refusal' => 'unknown' ) ) );
		$this->assertHas( 'RES_LATE_OR_REFUSAL', $r, 'check' );
	}

	public function testTripsYesWithZeroTripsIsIncompleteEvenIfComplete(): void {
		$r = $this->run_( array( 'trips_any' => 'yes', 'trips' => array(), 'trips_complete' => 'complete' ) );
		$this->assertHas( 'TRIP_INCOMPLETE', $r, 'check' );
		$this->assertNotHas( 'TRIP_OK', $r );
		$this->assertNotSame( 'ok', $r['status'] );
	}

	public function testApproxTripEndingJustBeforeWindowIsBoundaryNotOk(): void {
		// Подача 2026-10-08, окно с 2021-10-08; выезд 01.02.2021, возвращение 01.10.2021 (приблизительно).
		$r = $this->run_(
			array(
				'trips_any'      => 'yes',
				'trips_complete' => 'complete',
				'trips'          => array( AnswersFactory::trip( '2021-02-01', '2021-10-01', 'approx' ) ),
			)
		);

		$this->assertNotSame( 'ok', $r['status'] );
		$this->assertHas( 'TRIP_SINGLE_BOUNDARY', $r, 'check' );
		$this->assertNotHas( 'TRIP_SINGLE_EXCEEDS', $r );
		$this->assertNotHas( 'TRIP_OK', $r );
	}

	public function testExactTripClearlyBeforeWindowStaysIgnored(): void {
		$r = $this->run_( array( 'trips_any' => 'yes', 'trips_complete' => 'complete', 'trips' => array( AnswersFactory::trip( '2021-02-01', '2021-09-20' ) ) ) );
		$this->assertNotHas( 'TRIP_SINGLE_BOUNDARY', $r );
		$this->assertNotHas( 'TRIP_SINGLE_EXCEEDS', $r );
		$this->assertSame( 'ok', $r['status'] );
	}

	// --- Средства -----------------------------------------------------------

	public function testOnlySavingsIsCheck(): void {
		$r = $this->run_( array( 'funds' => array( 'income_sources' => array( 'none' ), 'income_proofs' => array(), 'savings' => 'yes' ) ) );
		$this->assertHas( 'FUNDS_ONLY_SAVINGS', $r, 'check' );
		$this->assertSame( 'check', $r['status'] );
	}

	public function testNoIncomeNoSavingsIsObstacle(): void {
		$r = $this->run_( array( 'funds' => array( 'income_sources' => array( 'none' ), 'income_proofs' => array(), 'savings' => 'no' ) ) );
		$this->assertHas( 'FUNDS_NONE', $r, 'obstacle' );
	}

	public function testIncomeBelowGuideWithDependents(): void {
		$r = $this->run_( array( 'funds' => array( 'income_amount' => 1500.0, 'dependents' => 2 ) ) );

		$this->assertHas( 'FUNDS_BELOW_GUIDE', $r, 'check' );
		$p = self::byCode( $r )['FUNDS_BELOW_GUIDE']['params'];
		$this->assertSame( 3, $p['persons'] );
		$this->assertSame( '3 000,00', $p['needed'] );
		$this->assertSame( '1 500,00', $p['amount'] );
		$this->assertSame( '2026-10-08', $p['verified'] );
	}

	public function testSameIncomeWithoutDependentsIsOk(): void {
		$r = $this->run_( array( 'funds' => array( 'income_amount' => 1500.0, 'dependents' => 0 ) ) );
		$this->assertHas( 'FUNDS_REGULAR_OK', $r, 'ok' );
	}

	public function testFundsWithoutThresholdOrWithOtherCurrency(): void {
		$r = $this->run_( array(), array( 'income_min_monthly' => null ) );
		$this->assertHas( 'FUNDS_REGULAR_NO_THRESHOLD', $r, 'check' );
		$this->assertNotSame( 'ok', $r['status'] );

		$r = $this->run_( array( 'funds' => array( 'income_currency' => 'BGN' ) ) );
		$this->assertHas( 'FUNDS_AMOUNT_UNCLEAR', $r, 'check' );

		$r = $this->run_( array( 'funds' => array( 'income_proofs' => array( 'none' ) ) ) );
		$this->assertHas( 'FUNDS_NO_PROOF', $r, 'check' );

		$r = $this->run_( array( 'funds' => array( 'income_sources' => array( 'unknown' ) ) ) );
		$this->assertHas( 'FUNDS_UNKNOWN', $r, 'check' );
	}

	// --- Документы ----------------------------------------------------------

	public function testPassportExpiringBeforeFilingIsObstacle(): void {
		$r = $this->run_( array( 'documents' => array( 'passport_until' => '2026-09-01' ) ) );
		$this->assertSame( 'obstacle', $r['status'] );
		$this->assertHas( 'DOC_PASSPORT_EXPIRES', $r, 'obstacle' );

		// В день подачи паспорт уже считается истекающим (<=).
		$r = $this->run_( array( 'documents' => array( 'passport_until' => '2026-10-08' ) ) );
		$this->assertHas( 'DOC_PASSPORT_EXPIRES', $r, 'obstacle' );
	}

	public function testPassportExpiringSoonAfterFilingIsCheck(): void {
		$r = $this->run_( array( 'documents' => array( 'passport_until' => '2027-01-01' ) ) );
		$this->assertHas( 'DOC_PASSPORT_SOON', $r, 'check' );

		// Граница 6 месяцев включительно: 2027-04-08.
		$r = $this->run_( array( 'documents' => array( 'passport_until' => '2027-04-08' ) ) );
		$this->assertHas( 'DOC_PASSPORT_SOON', $r );
		$r = $this->run_( array( 'documents' => array( 'passport_until' => '2027-04-09' ) ) );
		$this->assertNotHas( 'DOC_PASSPORT_SOON', $r );
	}

	public function testPassportUnknownIsCheck(): void {
		$r = $this->run_( array( 'documents' => array( 'passport_until' => '' ) ) );
		$this->assertHas( 'DOC_PASSPORT_UNKNOWN', $r, 'check' );
	}

	// --- Дата подачи --------------------------------------------------------

	public function testPlannedDateInPastIsTreatedAsToday(): void {
		$r = $this->run_( array( 'filing' => array( 'planned_date' => '2026-01-01' ) ) );

		$this->assertSame( '2026-10-08', $r['filing_date'] );
		$this->assertSame( '2021-10-08', $r['window_start'] );
		$this->assertTrue( $r['filing_is_default'] );
		$this->assertHas( 'FILING_DATE_DEFAULT', $r, 'info' );
		$this->assertSame( '08.10.2026', self::byCode( $r )['FILING_DATE_DEFAULT']['params']['date'] );
	}

	public function testPlannedDateTodayIsExplicit(): void {
		$r = $this->run_( array( 'filing' => array( 'planned_date' => '2026-10-08' ) ) );
		$this->assertFalse( $r['filing_is_default'] );
		$this->assertNotHas( 'FILING_DATE_DEFAULT', $r );
	}

	public function testFuturePlannedDateShiftsWindow(): void {
		$r = $this->run_( array( 'filing' => array( 'planned_date' => '2027-03-15' ) ) );

		$this->assertSame( '2027-03-15', $r['filing_date'] );
		$this->assertSame( '2022-03-15', $r['window_start'] );
		$this->assertFalse( $r['filing_is_default'] );
		$this->assertNotHas( 'FILING_DATE_DEFAULT', $r );
	}

	public function testFutureDateMovesOldTripOutOfWindow(): void {
		$trips = array( 'trips_any' => 'yes', 'trips_complete' => 'complete', 'trips' => array( AnswersFactory::trip( '2021-11-01', '2021-12-01' ) ) );

		$today = $this->run_( $trips );
		$this->assertSame( 30, $today['absences']['total']['nights'] );

		$later = $this->run_( $trips + array( 'filing' => array( 'planned_date' => '2027-03-15' ) ) );
		$this->assertSame( 0, $later['absences']['total']['nights'] );
	}

	public function testPassportIsCheckedAgainstPlannedFilingDate(): void {
		$r = $this->run_( array( 'documents' => array( 'passport_until' => '2027-01-01' ), 'filing' => array( 'planned_date' => '2027-03-15' ) ) );
		$this->assertHas( 'DOC_PASSPORT_EXPIRES', $r, 'obstacle' );
	}

	public function testInvalidTodayFallsBackWithoutCrash(): void {
		$r = ( new Evaluator() )->evaluate( AnswersFactory::base(), array( 'today' => 'not-a-date' ) );
		$this->assertSame( '2026-01-01', $r['calc_date'] );
	}

	// --- Сортировка и предпросмотр -----------------------------------------

	public function testFindingsSortedBySeverityThenOrder(): void {
		$r    = $this->run_( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ), 'special' => array( 'blue_card' => 'yes' ) ) );
		$rank = array( 'obstacle' => 3, 'check' => 2, 'ok' => 1, 'info' => 0 );
		$prev = 99;

		foreach ( $r['findings'] as $f ) {
			$this->assertLessThanOrEqual( $prev, $rank[ $f['severity'] ] );
			$prev = $rank[ $f['severity'] ];
		}
	}

	public function testPreviewFindingsWithProblemsHasNoOkAndAtMostThree(): void {
		$r = $this->run_(
			array(
				'health'  => array( 'nzok' => 'no', 'other_cover' => 'none' ),
				'special' => array( 'blue_card' => 'yes', 'temporary_basis' => 'yes', 'protection' => 'international' ),
			)
		);

		$preview = Evaluator::previewFindings( $r );
		$this->assertLessThanOrEqual( 3, count( $preview ) );
		$this->assertNotEmpty( $preview );
		$this->assertSame( 'obstacle', $preview[0]['severity'] );

		foreach ( $preview as $f ) {
			$this->assertNotSame( 'ok', $f['severity'] );
			$this->assertNotSame( 'info', $f['severity'] );
		}
	}

	public function testPreviewFindingsForOkShowsOnlyOkItems(): void {
		$preview = Evaluator::previewFindings( $this->run_() );
		$this->assertNotEmpty( $preview );
		$this->assertLessThanOrEqual( 3, count( $preview ) );

		foreach ( $preview as $f ) {
			$this->assertSame( 'ok', $f['severity'] );
		}
	}

	public function testPreviewFindingsRespectsLimit(): void {
		$r = $this->run_( array( 'special' => array( 'blue_card' => 'yes', 'temporary_basis' => 'yes', 'protection' => 'international' ) ) );
		$this->assertCount( 2, Evaluator::previewFindings( $r, 2 ) );
		$this->assertCount( 3, Evaluator::previewFindings( $r ) );
	}

	public function testPreviewFindingsWhenOnlyInfoReturnsNothing(): void {
		$this->assertSame( array(), Evaluator::previewFindings( array( 'findings' => array( array( 'severity' => 'info', 'code' => 'X' ) ) ) ) );
	}

	public function testEvaluatorIsReusable(): void {
		$e = new Evaluator();
		$a = $e->evaluate( AnswersFactory::with( array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ) ), AnswersFactory::config() );
		$b = $e->evaluate( AnswersFactory::base(), AnswersFactory::config() );

		$this->assertSame( 'obstacle', $a['status'] );
		$this->assertSame( 'ok', $b['status'], 'findings прошлого вызова не должны протекать' );
	}

	// --- Шаблоны и источники -----------------------------------------------

	/** @return array<string,array{0:string}> */
	public static function scenarioNames(): array {
		$out = array();

		foreach ( array_keys( AnswersFactory::scenarioAnswers() ) as $name ) {
			$out[ $name ] = array( $name );
		}

		return $out;
	}

	#[DataProvider( 'scenarioNames' )]
	public function testScenarioEmitsOnlyKnownCodesSourcesAndPlaceholders( string $name ): void {
		$answers   = AnswersFactory::scenarioAnswers()[ $name ];
		$result    = ( new Evaluator() )->evaluate( $answers, AnswersFactory::config() );
		$templates = DefaultTemplates::get()['findings'];

		$this->assertContains( $result['status'], array( 'ok', 'check', 'obstacle' ) );
		$this->assertNotEmpty( $result['findings'] );

		foreach ( $result['findings'] as $f ) {
			$code = $f['code'];
			$this->assertArrayHasKey( $code, $templates, "{$name}: нет шаблона для {$code}" );
			$this->assertNotSame( '', trim( (string) ( $templates[ $code ]['detail'] ?? '' ) ), "{$name}: пустой detail для {$code}" );

			foreach ( $f['sources'] as $source ) {
				$this->assertNotNull( RulesMeta::source( $source ), "{$name}: {$code} ссылается на неизвестный источник {$source}" );
			}

			foreach ( array( 'short', 'detail', 'action', 'question' ) as $kind ) {
				preg_match_all( '/\{([a-z_]+)\}/', (string) ( $templates[ $code ][ $kind ] ?? '' ), $m );
				foreach ( $m[1] as $placeholder ) {
					$this->assertArrayHasKey( $placeholder, $f['params'], "{$name}: {$code}.{$kind} требует {{$placeholder}}, а в params его нет" );
				}
			}
		}
	}

	public function testEveryCodeInEvaluatorSourceHasTemplate(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rules/Evaluator.php' );
		preg_match_all( "/'((?:APP|PMZH|RES|TRIP|SPEC|HOUSING|HEALTH|FUNDS|DOC|FILING)_[A-Z_]+)'/", $src, $m );
		$codes = array_unique( $m[1] );

		$this->assertGreaterThan( 40, count( $codes ) );
		$templates = DefaultTemplates::get()['findings'];

		foreach ( $codes as $code ) {
			$this->assertArrayHasKey( $code, $templates, "нет шаблона для {$code}" );
			$this->assertNotSame( '', trim( (string) ( $templates[ $code ]['detail'] ?? '' ) ), "пустой detail для {$code}" );
		}
	}

	public function testScenariosTogetherCoverMostCodes(): void {
		$seen = array();

		foreach ( AnswersFactory::scenarioAnswers() as $answers ) {
			foreach ( ( new Evaluator() )->evaluate( $answers, AnswersFactory::config() )['findings'] as $f ) {
				$seen[ $f['code'] ] = true;
			}
		}

		$templates = array_keys( DefaultTemplates::get()['findings'] );
		$missing   = array_diff( $templates, array_keys( $seen ) );
		$this->assertLessThanOrEqual( 3, count( $missing ), 'сценарии не достигают кодов: ' . implode( ',', $missing ) );
	}
}
