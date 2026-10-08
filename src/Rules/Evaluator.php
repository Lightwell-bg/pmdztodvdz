<?php
/**
 * Серверная оценка анкеты. Чистая логика без WordPress.
 *
 * Вход — нормализованные ответы (см. Questionnaire\Validator, формат
 * описан в docs/ANSWERS_FORMAT.md) и конфигурация (дата расчёта,
 * ориентир дохода). Выход — массив результата, который целиком
 * сохраняется в неизменяемом снимке.
 *
 * Итоговый статус:
 *  - obstacle — есть хотя бы одно препятствие («Есть препятствия для подачи сейчас»);
 *  - check    — препятствий нет, но есть хотя бы один пункт для проверки;
 *  - ok       — «Основные условия предварительно подтверждаются».
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Rules;

use DateTimeImmutable;

final class Evaluator {

	public const STATUS_OK       = 'ok';
	public const STATUS_CHECK    = 'check';
	public const STATUS_OBSTACLE = 'obstacle';

	private const SEVERITY_RANK = array(
		'obstacle' => 3,
		'check'    => 2,
		'ok'       => 1,
		'info'     => 0,
	);

	/** @var list<array<string,mixed>> */
	private array $findings = array();

	/**
	 * @param array<string,mixed> $answers Нормализованные ответы.
	 * @param array{today:string,income_min_monthly?:?float,income_currency?:string,income_verified_at?:string} $config
	 *
	 * @return array<string,mixed>
	 */
	public function evaluate( array $answers, array $config ): array {
		$this->findings = array();

		$today   = DateMath::parse( $config['today'] ) ?? DateMath::make( 2026, 1, 1 );
		$planned = DateMath::parse( (string) ( $answers['filing']['planned_date'] ?? '' ) );

		if ( null !== $planned && $planned < $today ) {
			$planned = null; // Дата в прошлом — расчёт на сегодня (валидатор предупреждает).
		}

		$filing      = $planned ?? $today;
		$windowStart = DateMath::addYears( $filing, -5 );

		if ( null === $planned ) {
			$this->add( 'FILING_DATE_DEFAULT', 'info', 'filing', array( 'date' => DateMath::human( $filing ) ), array() );
		}

		$this->applicant( $answers );
		$timeline = $this->residence( $answers, $windowStart, $filing );
		$absences = $this->trips( $answers, $windowStart, $filing );
		$this->special( $answers, $timeline, $windowStart, $filing );
		$this->housing( $answers );
		$this->health( $answers, $filing );
		$this->funds( $answers, $config );
		$this->documents( $answers, $filing );

		$status = self::STATUS_OK;

		foreach ( $this->findings as $f ) {
			if ( 'obstacle' === $f['severity'] ) {
				$status = self::STATUS_OBSTACLE;
				break;
			}

			if ( 'check' === $f['severity'] ) {
				$status = self::STATUS_CHECK;
			}
		}

		$findings = $this->findings;
		usort(
			$findings,
			static fn( array $a, array $b ): int => ( self::SEVERITY_RANK[ $b['severity'] ] <=> self::SEVERITY_RANK[ $a['severity'] ] ) ?: ( $a['order'] <=> $b['order'] )
		);

		return array(
			'status'        => $status,
			'rules_version' => RulesMeta::VERSION,
			'verified_at'   => RulesMeta::VERIFIED_AT,
			'calc_date'     => DateMath::format( $today ),
			'filing_date'   => DateMath::format( $filing ),
			'filing_is_default' => null === $planned,
			'window_start'  => DateMath::format( $windowStart ),
			'findings'      => $findings,
			'timeline'      => $timeline,
			'absences'      => $absences,
			'log'           => array_merge( $timeline['log'], $absences['log'] ),
		);
	}

	/**
	 * Коды 1–3 пояснений для предварительного результата: сначала
	 * препятствия, затем проверки; если их нет — подтверждённые пункты.
	 *
	 * @param array<string,mixed> $result
	 * @return list<array<string,mixed>>
	 */
	public static function previewFindings( array $result, int $limit = 3 ): array {
		$out = array();

		foreach ( array( 'obstacle', 'check', 'ok' ) as $severity ) {
			// Подтверждённые пункты показываем, только если нет проблем.
			if ( 'ok' === $severity && ! empty( $out ) ) {
				break;
			}

			foreach ( $result['findings'] as $f ) {
				if ( $f['severity'] === $severity && count( $out ) < $limit ) {
					$out[] = $f;
				}
			}
		}

		return $out;
	}

	// ---------------------------------------------------------------------

	/** @param array<string,mixed> $a */
	private function applicant( array $a ): void {
		$ap = $a['applicant'] ?? array();

		if ( in_array( $ap['eu_status'] ?? 'none', array( 'eu_citizen', 'bg_citizen' ), true ) ) {
			$this->add( 'APP_EU_CITIZEN', 'check', 'applicant', array(), array( 'zchrb_24g_1' ) );
		} elseif ( 'unknown' === ( $ap['eu_status'] ?? 'none' ) ) {
			$this->add( 'APP_EU_STATUS_UNKNOWN', 'check', 'applicant', array(), array() );
		}

		$age = $ap['age'] ?? null;

		if ( null === $age ) {
			$this->add( 'APP_AGE_UNKNOWN', 'info', 'applicant', array(), array() );
		} elseif ( (int) $age < 18 ) {
			$this->add( 'APP_MINOR', 'check', 'applicant', array( 'age' => (int) $age ), array( 'ppz_51' ) );
		}

		if ( 'family' === ( $ap['family'] ?? 'self' ) ) {
			$this->add( 'APP_FAMILY', 'info', 'applicant', array(), array() );
		}
	}

	/**
	 * @param array<string,mixed> $a
	 * @return array<string,mixed>
	 */
	private function residence( array $a, DateTimeImmutable $windowStart, DateTimeImmutable $filing ): array {
		$pm       = $a['pmzh'] ?? array();
		$statuses = array();

		$pmFrom = 'unknown' === ( $pm['date_precision'] ?? 'unknown' ) ? null : DateMath::parse( (string) ( $pm['decision_date'] ?? '' ) );

		if ( null === $pmFrom ) {
			$this->add( 'PMZH_DATE_UNKNOWN', 'check', 'residence', array(), array( 'ppz_51' ) );
		} else {
			$statuses[] = array(
				'kind'   => 'pmzh',
				'from'   => $pmFrom,
				'to'     => null,
				'end'    => 'ongoing',
				'approx' => 'approx' === ( $pm['date_precision'] ?? '' ),
			);
		}

		if ( '' === trim( (string) ( $pm['basis'] ?? '' ) ) && '' === trim( (string) ( $pm['basis_article'] ?? '' ) ) ) {
			$this->add( 'PMZH_BASIS_UNKNOWN', 'info', 'residence', array(), array() );
		}

		$anyStatusDatesUnknown = false;

		foreach ( (array) ( $a['statuses'] ?? array() ) as $s ) {
			$precision = (string) ( $s['precision'] ?? 'exact' );
			$from      = 'unknown' === $precision ? null : DateMath::parse( (string) ( $s['from'] ?? '' ) );
			$to        = 'unknown' === $precision ? null : DateMath::parse( (string) ( $s['to'] ?? '' ) );
			$end       = (string) ( $s['end'] ?? 'unknown' );

			if ( null === $from || ( 'ended' === $end && null === $to ) ) {
				$anyStatusDatesUnknown = true;
			}

			$statuses[] = array(
				'kind'   => (string) ( $s['kind'] ?? 'other' ),
				'from'   => $from,
				'to'     => $to,
				'end'    => $end,
				'approx' => 'approx' === $precision,
			);
		}

		$h    = $a['history'] ?? array();
		$gaps = array();

		if ( 'yes' === ( $h['gap_without_permit'] ?? 'no' ) ) {
			foreach ( (array) ( $h['gaps'] ?? array() ) as $g ) {
				$gaps[] = array(
					'from' => DateMath::parse( (string) ( $g['from'] ?? '' ) ),
					'to'   => DateMath::parse( (string) ( $g['to'] ?? '' ) ),
				);
			}

			if ( empty( $gaps ) ) {
				$gaps[] = array( 'from' => null, 'to' => null );
			}
		}

		$complete = 'yes' === ( $a['statuses_complete'] ?? 'unknown' );
		$timeline = ( new ResidenceTimeline() )->analyse( $statuses, $gaps, $complete, $windowStart, $filing );

		$sources = array( 'zchrb_24g_1', 'ppz_51' );

		if ( $timeline['insufficient'] ) {
			$this->add( 'RES_INSUFFICIENT', 'obstacle', 'residence', array(
				'earliest_start'  => DateMath::human( DateMath::parse( $timeline['earliest_start'] ) ),
				'earliest_filing' => DateMath::human( DateMath::parse( $timeline['earliest_filing'] ) ),
			), $sources );
		} elseif ( $timeline['head_uncovered'] ) {
			$this->add( 'RES_HEAD_UNCOVERED', 'check', 'residence', array(
				'window_start' => DateMath::human( $windowStart ),
			), $sources );
		}

		$internal = $timeline['uncovered'];

		if ( $timeline['head_uncovered'] ) {
			array_shift( $internal );
		}

		if ( ! empty( $internal ) ) {
			$this->add( 'RES_UNCOVERED', 'check', 'residence', array(
				'periods' => implode( '; ', array_map(
					static fn( array $u ): string => DateMath::human( DateMath::parse( $u['from'] ) ) . ' — ' . DateMath::human( DateMath::addDays( DateMath::parse( $u['to'] ) ?? $filing, -1 ) ),
					$internal
				) ),
			), $sources );
		}

		if ( $timeline['confirmed_gap_in_window'] ) {
			$this->add( 'RES_GAP_CONFIRMED', 'obstacle', 'residence', array(), $sources );
		} elseif ( $timeline['gap_dates_unknown'] ) {
			$this->add( 'RES_GAP_DATES_UNKNOWN', 'check', 'residence', array(), $sources );
		}

		if ( 'yes' === ( $h['pending_decision'] ?? 'no' ) || 'unknown' === ( $h['pending_decision'] ?? 'no' ) ) {
			$this->add( 'RES_PENDING', 'check', 'residence', array(), $sources );
		}

		if ( 'unknown' === ( $h['gap_without_permit'] ?? 'no' ) ) {
			$this->add( 'RES_GAP_UNKNOWN', 'check', 'residence', array(), $sources );
		}

		$lateOrRefusal = array( $h['late_renewal'] ?? 'no', $h['refusal'] ?? 'no' );

		if ( in_array( 'yes', $lateOrRefusal, true ) || in_array( 'unknown', $lateOrRefusal, true ) ) {
			$this->add( 'RES_LATE_OR_REFUSAL', 'check', 'residence', array(), $sources );
		}

		if ( $anyStatusDatesUnknown ) {
			$this->add( 'RES_STATUS_DATES_UNKNOWN', 'check', 'residence', array(), $sources );
		}

		if ( $timeline['approx_near_window'] ) {
			$this->add( 'RES_APPROX_NEAR_WINDOW', 'check', 'residence', array( 'window_start' => DateMath::human( $windowStart ) ), $sources );
		}

		if ( in_array( $h['old_documents'] ?? 'unknown', array( 'partial', 'none', 'unknown' ), true ) ) {
			$this->add( 'RES_OLD_DOCS', 'info', 'residence', array(), array( 'ppz_51' ) );
		}

		$residenceIssues = array_filter(
			$this->findings,
			static fn( array $f ): bool => 'residence' === $f['area'] && in_array( $f['severity'], array( 'check', 'obstacle' ), true )
		);

		if ( empty( $residenceIssues ) ) {
			$this->add( 'RES_OK', 'ok', 'residence', array(
				'window_start' => DateMath::human( $windowStart ),
				'filing'       => DateMath::human( $filing ),
			), $sources );
		}

		return $timeline;
	}

	/**
	 * @param array<string,mixed> $a
	 * @return array<string,mixed>
	 */
	private function trips( array $a, DateTimeImmutable $windowStart, DateTimeImmutable $filing ): array {
		$input = array();

		foreach ( (array) ( $a['trips'] ?? array() ) as $t ) {
			$precision = (string) ( $t['precision'] ?? 'exact' );
			$input[]   = array(
				'out'    => 'unknown' === $precision ? null : DateMath::parse( (string) ( $t['out'] ?? '' ) ),
				'back'   => 'unknown' === $precision ? null : DateMath::parse( (string) ( $t['back'] ?? '' ) ),
				'approx' => 'approx' === $precision,
			);
		}

		$calc    = ( new AbsenceCalculator() )->calculate( $input, $windowStart, $filing );
		$sources = array( 'zchrb_24g_8' );
		$issues  = 0;

		foreach ( $calc['trips'] as $row ) {
			$backDate = DateMath::parse( (string) $row['back'] );
			// Неопределённость даты возвращения: 31 сут. для приблизительных дат, 1 для точных.
			$u        = $row['approx'] ? AbsenceCalculator::APPROX_END_UNCERTAINTY : AbsenceCalculator::EXACT_END_UNCERTAINTY;
			$relevant = null === $backDate || DateMath::addDays( $backDate, $u ) > $windowStart;

			if ( ! $relevant ) {
				continue;
			}

			// Поездка могла закончиться до начала окна (в пределах неопределённости):
			// «превышение» тогда не подтверждено.
			$maybeOutside = null !== $backDate && DateMath::addDays( $backDate, -$u ) <= $windowStart;

			$params = array(
				'n'     => $row['index'] + 1,
				'out'   => DateMath::human( DateMath::parse( (string) $row['out'] ) ),
				'back'  => DateMath::human( DateMath::parse( (string) $row['back'] ) ),
				'limit' => DateMath::human( DateMath::parse( (string) $row['limit_date'] ) ),
			);

			if ( 'exceeds' === $row['single_class'] && ( $maybeOutside || ( ! empty( $row['crosses_window_possible'] ) && ! $row['crosses_window'] ) ) ) {
				$this->add( 'TRIP_SINGLE_BOUNDARY', 'check', 'trips', $params, $sources );
				++$issues;
			} elseif ( 'exceeds' === $row['single_class'] ) {
				$params['earliest_filing'] = DateMath::human( DateMath::addYears( DateMath::parse( (string) $row['back'] ), 5 ) );
				$this->add( 'TRIP_SINGLE_EXCEEDS', 'obstacle', 'trips', $params, $sources );
				++$issues;
			} elseif ( 'boundary' === $row['single_class'] ) {
				$this->add( 'TRIP_SINGLE_BOUNDARY', 'check', 'trips', $params, $sources );
				++$issues;
			}

			if ( $row['crosses_window'] ) {
				$this->add( 'TRIP_CROSSES_WINDOW', 'info', 'trips', $params + array( 'window_start' => DateMath::human( $windowStart ) ), $sources );
			}
		}

		if ( ! empty( $calc['invalid'] ) ) {
			$this->add( 'TRIP_DATES_INVALID', 'check', 'trips', array(
				'list' => implode( ', ', array_map( static fn( int $i ): string => (string) ( $i + 1 ), $calc['invalid'] ) ),
			), $sources );
			++$issues;
		}

		if ( ! empty( $calc['overlaps'] ) ) {
			$this->add( 'TRIP_OVERLAP', 'check', 'trips', array(
				'list' => implode( '; ', array_map( static fn( array $p ): string => ( $p[0] + 1 ) . ' и ' . ( $p[1] + 1 ), $calc['overlaps'] ) ),
			), $sources );
			++$issues;
		}

		$total = $calc['total'];
		$tp    = array(
			'nights'    => $total['nights'],
			'lower'     => $total['lower'],
			'upper'     => $total['upper'],
			'limit_min' => $total['limit_min'],
			'limit_max' => $total['limit_max'],
		);

		if ( 'exceeds' === $total['class'] ) {
			$this->add( 'TRIP_TOTAL_EXCEEDS', 'obstacle', 'trips', $tp, $sources );
			++$issues;
		} elseif ( 'boundary' === $total['class'] ) {
			$this->add( 'TRIP_TOTAL_BOUNDARY', 'check', 'trips', $tp, $sources );
			++$issues;
		}

		$any      = (string) ( $a['trips_any'] ?? 'unknown' );
		$complete = (string) ( $a['trips_complete'] ?? 'unknown' );

		$noTripsListed = empty( $calc['trips'] );

		if ( 'unknown' === $any || ( 'yes' === $any && ( 'complete' !== $complete || $noTripsListed ) ) ) {
			$this->add( 'TRIP_INCOMPLETE', 'check', 'trips', array( 'window_start' => DateMath::human( $windowStart ) ), $sources );
			++$issues;
		}

		if ( 0 === $issues ) {
			$this->add( 'TRIP_OK', 'ok', 'trips', $tp, $sources );
		}

		return $calc;
	}

	/**
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $timeline
	 */
	private function special( array $a, array $timeline, DateTimeImmutable $windowStart, DateTimeImmutable $filing ): void {
		$sp    = $a['special'] ?? array();
		$kinds = $timeline['special_kinds'];

		if ( in_array( 'study_24v', $kinds, true ) || 'yes' === ( $sp['study_24v'] ?? 'no' ) ) {
			$this->add( 'SPEC_STUDY', 'check', 'special', $this->studyParams( $a, $windowStart, $filing ), array( 'zchrb_24g_3' ) );
		} elseif ( 'unknown' === ( $sp['study_24v'] ?? 'no' ) ) {
			$this->add( 'SPEC_STUDY_UNKNOWN', 'check', 'special', array(), array( 'zchrb_24g_3' ) );
		}

		if ( 'yes' === ( $sp['studied_other_basis'] ?? 'no' ) && ! in_array( 'study_24v', $kinds, true ) && 'yes' !== ( $sp['study_24v'] ?? 'no' ) ) {
			$this->add( 'SPEC_STUDY_OTHER_BASIS', 'info', 'special', array(), array( 'zchrb_24g_3' ) );
		}

		if ( in_array( 'blue_card', $kinds, true ) || in_array( $sp['blue_card'] ?? 'no', array( 'yes', 'unknown' ), true ) ) {
			$this->add( 'SPEC_BLUE_CARD', 'check', 'special', array(), array( 'zchrb_33m', 'ppz_52' ) );
		}

		$protection = (string) ( $sp['protection'] ?? 'none' );

		if ( in_array( 'intl_protection', $kinds, true ) || 'international' === $protection ) {
			$this->add( 'SPEC_INTL_PROTECTION', 'check', 'special', array(), array( 'zchrb_24g_45' ) );
		}

		if ( in_array( 'temp_protection', $kinds, true ) || 'temporary' === $protection ) {
			$this->add( 'SPEC_TEMP_PROTECTION', 'check', 'special', array(), array( 'zchrb_24g_45', 'zchrb_24g_2' ) );
		}

		if ( 'unknown' === $protection ) {
			$this->add( 'SPEC_PROTECTION_UNKNOWN', 'check', 'special', array(), array( 'zchrb_24g_45' ) );
		}

		if ( in_array( $sp['temporary_basis'] ?? 'no', array( 'yes', 'unknown' ), true ) ) {
			$this->add( 'SPEC_TEMPORARY_BASIS', 'check', 'special', array(), array( 'zchrb_24g_2' ) );
		}

		if ( in_array( $sp['other_eu_status'] ?? 'none', array( 'residence', 'ltr', 'unknown' ), true ) ) {
			$this->add( 'SPEC_OTHER_EU', 'check', 'special', array( 'kind' => (string) $sp['other_eu_status'] ), array( 'zchrb_24g_1', 'zchrb_40' ) );
		}

		if ( in_array( 'other', $kinds, true ) ) {
			$this->add( 'SPEC_OTHER_KIND', 'check', 'special', array(), array( 'zchrb_24g_2' ) );
		}
	}

	/**
	 * Иллюстративный расчёт половинного зачёта учёбы внутри окна.
	 * Толкование (как продлевается окно назад) не установлено, поэтому
	 * результат — только ориентир для проверки, а не вывод.
	 *
	 * @param array<string,mixed> $a
	 * @return array<string,mixed>
	 */
	private function studyParams( array $a, DateTimeImmutable $windowStart, DateTimeImmutable $filing ): array {
		$studyDays = 0;

		foreach ( (array) ( $a['statuses'] ?? array() ) as $s ) {
			if ( 'study_24v' !== ( $s['kind'] ?? '' ) || 'unknown' === ( $s['precision'] ?? 'exact' ) ) {
				continue;
			}

			$from = DateMath::parse( (string) ( $s['from'] ?? '' ) );
			$to   = DateMath::parse( (string) ( $s['to'] ?? '' ) );

			if ( null === $from || null === $to ) {
				continue;
			}

			$f = DateMath::max( $from, $windowStart );
			$t = DateMath::min( $to, $filing );

			if ( $t > $f ) {
				$studyDays += DateMath::daysBetween( $f, $t );
			}
		}

		return array(
			'study_days' => $studyDays,
			'half_days'  => intdiv( $studyDays, 2 ),
		);
	}

	/** @param array<string,mixed> $a */
	private function housing( array $a ): void {
		$h      = $a['housing'] ?? array();
		$tenure = (string) ( $h['tenure'] ?? 'unknown' );
		$proofs = array_values( array_diff( (array) ( $h['proofs'] ?? array() ), array( 'none', 'unknown' ) ) );
		$src    = array( 'ppz_51', 'iisda_22087' );

		if ( 'unknown' === $tenure ) {
			$this->add( 'HOUSING_UNKNOWN', 'check', 'housing', array(), $src );
			return;
		}

		if ( empty( $proofs ) ) {
			$this->add( 'HOUSING_NO_PROOF', 'check', 'housing', array(), $src );
			return;
		}

		$hasDeclaration = in_array( 'owner_declaration', $proofs, true );

		if ( 'own' === $tenure ) {
			// Собственное жильё подтверждает только нотариальный акт.
			if ( ! in_array( 'notarial_act', $proofs, true ) ) {
				$this->add( 'HOUSING_NO_PROOF', 'check', 'housing', array(), $src );
				return;
			}
		} elseif ( 'rent' === $tenure ) {
			// Аренда: нужны и договор, и декларация собственника.
			if ( ! in_array( 'lease', $proofs, true ) ) {
				$this->add( 'HOUSING_RENT_LEASE', 'check', 'housing', array(), $src );
				return;
			}

			if ( ! $hasDeclaration ) {
				$this->add( 'HOUSING_RENT_DECLARATION', 'check', 'housing', array(), $src );
				return;
			}
		} elseif ( ! $hasDeclaration ) {
			// Семья и иное основание: нужна декларация владельца; одна регистрация по адресу не доказывает жильё.
			$this->add( 'HOUSING_OTHER_BASIS', 'check', 'housing', array(), $src );
			return;
		}

		$this->add( 'HOUSING_OK', 'ok', 'housing', array( 'tenure' => $tenure ), $src );
	}

	/** @param array<string,mixed> $a */
	private function health( array $a, DateTimeImmutable $filing ): void {
		$h    = $a['health'] ?? array();
		$nzok = (string) ( $h['nzok'] ?? 'unknown' );
		$src  = array( 'zchrb_24g_9', 'ppz_51' );

		if ( 'yes' === $nzok ) {
			$contrib = (string) ( $h['contributions'] ?? 'unknown' );

			if ( 'ok' === $contrib ) {
				$this->add( 'HEALTH_NZOK_OK', 'ok', 'health', array(), $src );
			} else {
				$this->add( 'HEALTH_NZOK_GAPS', 'check', 'health', array( 'unknown' => 'unknown' === $contrib ), $src );
			}

			return;
		}

		$other = (string) ( $h['other_cover'] ?? 'unknown' );

		if ( 'unknown' === $nzok || 'unknown' === $other ) {
			$this->add( 'HEALTH_UNKNOWN', 'check', 'health', array(), $src );
			return;
		}

		if ( 'none' === $other ) {
			$this->add( 'HEALTH_NONE', 'obstacle', 'health', array(), $src );
			return;
		}

		$until = DateMath::parse( (string) ( $h['cover_until'] ?? '' ) );

		if ( null === $until || $until <= DateMath::addYears( $filing, 1 ) ) {
			$this->add( 'HEALTH_POLICY_PERIOD', 'check', 'health', array(
				'until'  => DateMath::human( $until ),
				'filing' => DateMath::human( $filing ),
			), $src );
			return;
		}

		$this->add( 'HEALTH_POLICY_OK', 'ok', 'health', array( 'until' => DateMath::human( $until ) ), $src );
	}

	/**
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $config
	 */
	private function funds( array $a, array $config ): void {
		$f       = $a['funds'] ?? array();
		$sources = array_values( array_diff( (array) ( $f['income_sources'] ?? array() ), array( 'none', 'unknown' ) ) );
		$proofs  = array_values( array_diff( (array) ( $f['income_proofs'] ?? array() ), array( 'none' ) ) );
		$savings = (string) ( $f['savings'] ?? 'unknown' );
		$src     = array( 'zchrb_24g_9', 'ppz_51' );

		if ( empty( $sources ) ) {
			if ( in_array( 'unknown', (array) ( $f['income_sources'] ?? array() ), true ) ) {
				$this->add( 'FUNDS_UNKNOWN', 'check', 'funds', array(), $src );
			} elseif ( 'yes' === $savings ) {
				$this->add( 'FUNDS_ONLY_SAVINGS', 'check', 'funds', array(), $src );
			} elseif ( 'unknown' === $savings ) {
				$this->add( 'FUNDS_UNKNOWN', 'check', 'funds', array(), $src );
			} else {
				$this->add( 'FUNDS_NONE', 'obstacle', 'funds', array(), $src );
			}

			return;
		}

		if ( empty( $proofs ) ) {
			$this->add( 'FUNDS_NO_PROOF', 'check', 'funds', array(), $src );
			return;
		}

		$min      = $config['income_min_monthly'] ?? null;
		$currency = (string) ( $config['income_currency'] ?? '' );
		$amount   = $f['income_amount'] ?? null;
		$persons  = 1 + max( 0, (int) ( $f['dependents'] ?? 0 ) );

		if ( null === $min || $min <= 0 ) {
			$this->add( 'FUNDS_REGULAR_NO_THRESHOLD', 'check', 'funds', array(), $src );
			return;
		}

		if ( null === $amount || ( $f['income_currency'] ?? '' ) !== $currency ) {
			$this->add( 'FUNDS_AMOUNT_UNCLEAR', 'check', 'funds', array(
				'min'      => self::money( (float) $min ),
				'currency' => $currency,
				'persons'  => $persons,
			), $src );
			return;
		}

		$needed = (float) $min * $persons;

		if ( (float) $amount < $needed ) {
			$this->add( 'FUNDS_BELOW_GUIDE', 'check', 'funds', array(
				'amount'   => self::money( (float) $amount ),
				'needed'   => self::money( $needed ),
				'currency' => $currency,
				'persons'  => $persons,
				'verified' => (string) ( $config['income_verified_at'] ?? '' ),
			), $src );
			return;
		}

		$this->add( 'FUNDS_REGULAR_OK', 'ok', 'funds', array(
			'amount'   => self::money( (float) $amount ),
			'needed'   => self::money( $needed ),
			'currency' => $currency,
			'persons'  => $persons,
		), $src );
	}

	/** @param array<string,mixed> $a */
	private function documents( array $a, DateTimeImmutable $filing ): void {
		$d        = $a['documents'] ?? array();
		$passport = DateMath::parse( (string) ( $d['passport_until'] ?? '' ) );

		if ( null === $passport ) {
			$this->add( 'DOC_PASSPORT_UNKNOWN', 'check', 'documents', array(), array( 'ppz_51' ) );
		} elseif ( $passport <= $filing ) {
			$this->add( 'DOC_PASSPORT_EXPIRES', 'obstacle', 'documents', array(
				'until'  => DateMath::human( $passport ),
				'filing' => DateMath::human( $filing ),
			), array( 'ppz_51' ) );
		} elseif ( $passport <= DateMath::addMonths( $filing, 6 ) ) {
			$this->add( 'DOC_PASSPORT_SOON', 'check', 'documents', array( 'until' => DateMath::human( $passport ) ), array( 'ppz_51' ) );
		}

		$card = DateMath::parse( (string) ( $d['pmzh_card_until'] ?? '' ) );

		if ( null !== $card && $card <= DateMath::addMonths( $filing, 4 ) ) {
			$this->add( 'DOC_PMZH_CARD', 'info', 'documents', array( 'until' => DateMath::human( $card ) ), array( 'ppz_53' ) );
		}

		foreach ( array( 'adverse' => 'DOC_ADVERSE', 'entry_ban' => 'DOC_ENTRY_BAN', 'extra_check' => 'DOC_EXTRA' ) as $key => $code ) {
			if ( in_array( $d[ $key ] ?? 'no', array( 'yes', 'unknown' ), true ) ) {
				$this->add( $code, 'check', 'documents', array( 'unknown' => 'unknown' === ( $d[ $key ] ?? '' ) ), array( 'zchrb_40' ) );
			}
		}
	}

	/**
	 * @param array<string,mixed> $params
	 * @param list<string> $sources
	 */
	private function add( string $code, string $severity, string $area, array $params, array $sources ): void {
		$this->findings[] = array(
			'code'     => $code,
			'severity' => $severity,
			'area'     => $area,
			'params'   => $params,
			'sources'  => $sources,
			'order'    => count( $this->findings ),
		);
	}

	private static function money( float $v ): string {
		return number_format( $v, 2, ',', ' ' );
	}
}
