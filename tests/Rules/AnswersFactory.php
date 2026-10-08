<?php
/**
 * Фабрика нормализованных ответов анкеты для тестов (формат: docs/ANSWERS_FORMAT.md).
 *
 * База — полностью «подходящий» кандидат на дату расчёта 2026-10-08:
 * ПМЖ с 2019-03-15, без поездок, жильё в собственности, ЗЗО, доход с документами.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Rules;

final class AnswersFactory {

	public const TODAY = '2026-10-08';

	/** @return array<string,mixed> */
	public static function base(): array {
		return array(
			'applicant'         => array(
				'citizenship'        => 'Украина',
				'other_citizenships' => '',
				'eu_status'          => 'none',
				'age'                => 40,
				'family'             => 'self',
			),
			'pmzh'              => array(
				'date_precision' => 'exact',
				'decision_date'  => '2019-03-15',
				'basis'          => 'Пять лет пребывания',
				'basis_article'  => 'чл. 25, ал. 1, т. 6',
				'decision_doc'   => 'yes',
			),
			'statuses'          => array(),
			'statuses_complete' => 'yes',
			'history'           => array(
				'gap_without_permit' => 'no',
				'gaps'               => array(),
				'late_renewal'       => 'no',
				'refusal'            => 'no',
				'pending_decision'   => 'no',
				'old_documents'      => 'all',
			),
			'trips_any'         => 'no',
			'trips'             => array(),
			'trips_complete'    => 'complete',
			'special'           => array(
				'study_24v'           => 'no',
				'studied_other_basis' => 'no',
				'blue_card'           => 'no',
				'protection'          => 'none',
				'temporary_basis'     => 'no',
				'other_eu_status'     => 'none',
			),
			'housing'           => array(
				'region'          => 'София',
				'registered_same' => 'yes',
				'tenure'          => 'own',
				'proofs'          => array( 'notarial_act' ),
			),
			'health'            => array(
				'nzok'          => 'yes',
				'contributions' => 'ok',
				'other_cover'   => 'unknown',
				'cover_until'   => '',
			),
			'funds'             => array(
				'income_sources'  => array( 'salary' ),
				'income_amount'   => 3000.0,
				'income_currency' => 'EUR',
				'income_proofs'   => array( 'employment_contract' ),
				'savings'         => 'no',
				'bank_country'    => 'bg',
				'dependents'      => 0,
			),
			'documents'         => array(
				'passport_until'  => '2030-01-01',
				'pmzh_card_until' => '',
				'adverse'         => 'no',
				'entry_ban'       => 'no',
				'extra_check'     => 'no',
			),
			'filing'            => array( 'planned_date' => '' ),
		);
	}

	/** @return array{today:string,income_min_monthly:float,income_currency:string,income_verified_at:string} */
	public static function config(): array {
		return array(
			'today'              => self::TODAY,
			'income_min_monthly' => 1000.0,
			'income_currency'    => 'EUR',
			'income_verified_at' => '2026-10-08',
		);
	}

	/**
	 * Накладывает изменения на базу. Ассоциативные массивы сливаются по
	 * ключам, списки (и скаляры) заменяются целиком.
	 *
	 * @param array<string,mixed> $override
	 * @return array<string,mixed>
	 */
	public static function with( array $override ): array {
		return self::merge( self::base(), $override );
	}

	/**
	 * @param array<mixed> $base
	 * @param array<mixed> $over
	 * @return array<mixed>
	 */
	private static function merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $value ) && ! array_is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/** @return array<string,mixed> Поездка в формате ответов. */
	public static function trip( string $out, string $back, string $precision = 'exact' ): array {
		return array(
			'out'       => $out,
			'back'      => $back,
			'countries' => 'Турция',
			'precision' => $precision,
		);
	}

	/** @return array<string,mixed> Статус в формате ответов. */
	public static function status( string $kind, string $from, string $end, string $to = '', string $precision = 'exact' ): array {
		return array(
			'kind'      => $kind,
			'basis'     => '',
			'precision' => $precision,
			'from'      => $from,
			'end'       => $end,
			'to'        => $to,
		);
	}

	/**
	 * Набор сценариев, покрывающих все ветки Evaluator (для проверки шаблонов).
	 *
	 * @return array<string,array<string,mixed>> Ключ — имя сценария, значение — ответы.
	 */
	public static function scenarios(): array {
		$trips = static fn( array $list ): array => array( 'trips_any' => 'yes', 'trips' => $list, 'trips_complete' => 'complete' );

		return array(
			'ok'                       => self::base(),
			'empty_everything'         => array(
				'applicant' => array( 'eu_status' => 'unknown', 'age' => null ),
				'pmzh'      => array( 'date_precision' => 'unknown', 'decision_date' => '', 'basis' => '', 'basis_article' => '' ),
				'statuses_complete' => 'unknown',
				'history'   => array( 'gap_without_permit' => 'unknown', 'pending_decision' => 'unknown', 'old_documents' => 'unknown', 'late_renewal' => 'yes', 'refusal' => 'unknown' ),
				'trips_any' => 'unknown',
				'special'   => array( 'study_24v' => 'unknown', 'blue_card' => 'unknown', 'protection' => 'unknown', 'temporary_basis' => 'unknown', 'other_eu_status' => 'unknown' ),
				'housing'   => array( 'tenure' => 'unknown', 'proofs' => array() ),
				'health'    => array( 'nzok' => 'unknown' ),
				'funds'     => array( 'income_sources' => array( 'unknown' ), 'income_proofs' => array(), 'savings' => 'unknown' ),
				'documents' => array( 'passport_until' => '', 'adverse' => 'unknown', 'entry_ban' => 'yes', 'extra_check' => 'yes' ),
			),
			'minor_family'             => array( 'applicant' => array( 'age' => 15, 'family' => 'family' ) ),
			'eu_citizen'               => array( 'applicant' => array( 'eu_status' => 'eu_citizen' ) ),
			'confirmed_gap'            => array( 'history' => array( 'gap_without_permit' => 'yes', 'gaps' => array( array( 'from' => '2023-05-10', 'to' => '2023-05-18' ) ) ) ),
			'gap_without_dates'        => array( 'history' => array( 'gap_without_permit' => 'yes', 'gaps' => array() ) ),
			'insufficient'             => array( 'pmzh' => array( 'decision_date' => '2023-05-10' ) ),
			'head_uncovered'           => array( 'pmzh' => array( 'decision_date' => '2023-05-10' ), 'statuses_complete' => 'no' ),
			'hole_between_statuses'    => array(
				'pmzh'     => array( 'decision_date' => '2023-05-19' ),
				'statuses' => array( self::status( 'vnzh', '2019-01-10', 'ended', '2023-05-09' ) ),
			),
			'status_dates_unknown'     => array(
				'statuses' => array( self::status( 'vnzh', '', 'unknown', '', 'unknown' ) ),
			),
			'approx_near_window'       => array(
				'pmzh'     => array( 'decision_date' => '2022-01-01' ),
				'statuses' => array( self::status( 'vnzh', '2021-10-01', 'ended', '2021-12-31', 'approx' ) ),
			),
			'trip_single_exceeds'      => $trips( array( self::trip( '2024-01-10', '2024-08-20' ) ) ),
			'trip_single_boundary'     => $trips( array( self::trip( '2024-01-10', '2024-07-10' ) ) ),
			'trip_total_exceeds'       => $trips(
				array(
					self::trip( '2022-01-10', '2022-03-17' ),
					self::trip( '2022-06-01', '2022-08-07' ),
					self::trip( '2022-10-01', '2022-12-07' ),
					self::trip( '2023-02-01', '2023-04-09' ),
					self::trip( '2023-06-01', '2023-08-08' ),
				)
			),
			'trip_total_boundary'      => $trips(
				array(
					self::trip( '2022-01-01', '2022-04-11' ),
					self::trip( '2023-01-01', '2023-04-11' ),
					self::trip( '2024-01-01', '2024-04-11' ),
				)
			),
			'trip_invalid_and_overlap' => $trips(
				array(
					self::trip( '2023-05-10', '2023-05-01' ),
					self::trip( '2023-01-01', '2023-03-01' ),
					self::trip( '2023-02-01', '2023-04-01' ),
					self::trip( '', '', 'unknown' ),
				)
			),
			'trip_crosses_window'      => $trips( array( self::trip( '2021-09-20', '2021-11-20' ) ) ),
			'trips_unknown'            => array( 'trips_any' => 'unknown' ),
			'trips_partial'            => array( 'trips_any' => 'yes', 'trips' => array(), 'trips_complete' => 'partial' ),
			'study'                    => array(
				'pmzh'     => array( 'decision_date' => '2023-01-02' ),
				'statuses' => array( self::status( 'study_24v', '2021-01-01', 'ended', '2023-01-01' ) ),
			),
			'study_flag'               => array( 'special' => array( 'study_24v' => 'yes' ) ),
			'study_other_basis'        => array( 'special' => array( 'studied_other_basis' => 'yes' ) ),
			'blue_card'                => array( 'special' => array( 'blue_card' => 'yes' ) ),
			'intl_protection'          => array( 'special' => array( 'protection' => 'international' ) ),
			'temp_protection'          => array( 'special' => array( 'protection' => 'temporary' ) ),
			'temporary_basis'          => array( 'special' => array( 'temporary_basis' => 'yes', 'other_eu_status' => 'ltr' ) ),
			'other_kind'               => array(
				'pmzh'     => array( 'decision_date' => '2024-01-02' ),
				'statuses' => array( self::status( 'other', '2019-01-01', 'ended', '2024-01-01' ) ),
			),
			'housing_rent_no_decl'     => array( 'housing' => array( 'tenure' => 'rent', 'proofs' => array( 'lease' ) ) ),
			'housing_no_proof'         => array( 'housing' => array( 'tenure' => 'family', 'proofs' => array( 'none' ) ) ),
			'housing_rent_ok'          => array( 'housing' => array( 'tenure' => 'rent', 'proofs' => array( 'lease', 'owner_declaration' ) ) ),
			'housing_rent_no_lease'    => array( 'housing' => array( 'tenure' => 'rent', 'proofs' => array( 'owner_declaration' ) ) ),
			'housing_other_basis'      => array( 'housing' => array( 'tenure' => 'family', 'proofs' => array( 'address_registration' ) ) ),
			'trip_approx_before_window' => $trips( array( self::trip( '2021-02-01', '2021-10-01', 'approx' ) ) ),
			'trips_yes_none_listed'    => array( 'trips_any' => 'yes', 'trips' => array(), 'trips_complete' => 'complete' ),
			'health_gaps'            => array( 'health' => array( 'contributions' => 'gaps' ) ),
			'health_none'              => array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'none' ) ),
			'health_policy_ok'         => array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'policy', 'cover_until' => '2028-01-01' ) ),
			'health_policy_short'      => array( 'health' => array( 'nzok' => 'no', 'other_cover' => 'policy', 'cover_until' => '2027-01-01' ) ),
			'funds_only_savings'       => array( 'funds' => array( 'income_sources' => array( 'none' ), 'income_proofs' => array(), 'savings' => 'yes' ) ),
			'funds_none'               => array( 'funds' => array( 'income_sources' => array( 'none' ), 'income_proofs' => array(), 'savings' => 'no' ) ),
			'funds_no_proof'           => array( 'funds' => array( 'income_proofs' => array( 'none' ) ) ),
			'funds_below_guide'        => array( 'funds' => array( 'income_amount' => 1500.0, 'dependents' => 2 ) ),
			'funds_unclear'            => array( 'funds' => array( 'income_amount' => null ) ),
			'passport_expired'         => array( 'documents' => array( 'passport_until' => '2026-09-01' ) ),
			'passport_soon'            => array( 'documents' => array( 'passport_until' => '2027-01-01' ) ),
			'card_soon'                => array( 'documents' => array( 'pmzh_card_until' => '2026-12-01' ) ),
			'adverse'                  => array( 'documents' => array( 'adverse' => 'yes', 'entry_ban' => 'yes', 'extra_check' => 'yes' ) ),
			'filing_future'            => array( 'filing' => array( 'planned_date' => '2027-03-15' ) ),
			'filing_past'              => array( 'filing' => array( 'planned_date' => '2026-01-01' ) ),
		);
	}

	/**
	 * Сценарии как готовые полные ответы.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function scenarioAnswers(): array {
		$out = array();

		foreach ( self::scenarios() as $name => $override ) {
			$out[ $name ] = 'ok' === $name ? $override : self::with( $override );
		}

		return $out;
	}
}
