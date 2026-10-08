<?php
/**
 * Нормализация и серверная проверка ответов анкеты (docs/ANSWERS_FORMAT.md).
 * Чистая логика без WordPress.
 *
 * normalize()      — строгая проверка при отправке: типы, лимиты, обязательные
 *                    поля, перекрёстные проверки. Результат: нормализованные
 *                    ответы, ошибки (путь с точками → сообщение) и предупреждения.
 * normalizeDraft() — мягкая нормализация черновика: только типы и лимиты,
 *                    без обязательных полей и перекрёстных проверок.
 *
 * Пути ошибок: `applicant.citizenship`, `statuses.0.to`, `trips.3.back`,
 * `history.gaps.1.from` (индексы с нуля).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Questionnaire;

use Bginfo\PmzhDvzh\Rules\DateMath;
use DateTimeImmutable;

final class Validator {

	public const MAX_STATUSES = 20;
	public const MAX_TRIPS    = 150;
	public const MAX_GAPS     = 10;
	public const MAX_TEXT     = 200;
	public const MIN_DATE     = '1950-01-01';

	/** @var array<string,string> */
	private array $errors = array();

	/** @var array<string,string> */
	private array $warnings = array();

	private DateTimeImmutable $today;
	private DateTimeImmutable $minDate;

	private function __construct( private bool $strict, string $today ) {
		$this->today   = DateMath::parse( $today ) ?? DateMath::parse( gmdate( 'Y-m-d' ) ) ?? DateMath::make( 2026, 1, 1 );
		$this->minDate = DateMath::make( 1950, 1, 1 );
	}

	/**
	 * Строгая нормализация при отправке анкеты.
	 *
	 * @param array<string,mixed> $raw
	 * @return array{answers:array<string,mixed>,errors:array<string,string>,warnings:array<string,string>}
	 */
	public static function normalize( array $raw, string $today ): array {
		return ( new self( true, $today ) )->run( $raw );
	}

	/**
	 * Мягкая нормализация черновика. errors всегда пустой.
	 *
	 * @param array<string,mixed> $raw
	 * @return array{answers:array<string,mixed>,errors:array<string,string>,warnings:array<string,string>}
	 */
	public static function normalizeDraft( array $raw, string $today ): array {
		return ( new self( false, $today ) )->run( $raw );
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{answers:array<string,mixed>,errors:array<string,string>,warnings:array<string,string>}
	 */
	private function run( array $raw ): array {
		$ap = $this->section( $raw, 'applicant' );
		$pm = $this->section( $raw, 'pmzh' );
		$hi = $this->section( $raw, 'history' );
		$sp = $this->section( $raw, 'special' );
		$ho = $this->section( $raw, 'housing' );
		$he = $this->section( $raw, 'health' );
		$fu = $this->section( $raw, 'funds' );
		$do = $this->section( $raw, 'documents' );
		$fi = $this->section( $raw, 'filing' );

		$answers = array();

		// Шаг 1.
		$citizenship = $this->text( $ap['citizenship'] ?? '' );

		if ( '' === $citizenship ) {
			$this->needed( 'applicant.citizenship', 'Укажите ваше гражданство.' );
		}

		$answers['applicant'] = array(
			'citizenship'        => $citizenship,
			'other_citizenships' => $this->text( $ap['other_citizenships'] ?? '' ),
			'eu_status'          => $this->enum( $ap['eu_status'] ?? null, 'eu_status', 'unknown', 'applicant.eu_status' ),
			'age'                => $this->intOrNull( $ap['age'] ?? null, 0, 120, 'applicant.age', 'Возраст — число от 0 до 120.' ),
			'family'             => $this->enum( $ap['family'] ?? null, 'family', 'self', 'applicant.family' ),
		);

		// Шаг 2.
		$pmRawDate  = $pm['decision_date'] ?? '';
		$pmDefault  = '' !== trim( is_scalar( $pmRawDate ) ? (string) $pmRawDate : '' ) ? 'exact' : 'unknown';
		$pmPrec     = $this->enum( $pm['date_precision'] ?? null, 'precision', $pmDefault, 'pmzh.date_precision' );
		$pmDate     = $this->dateByPrecision( $pmRawDate, $pmPrec, 'pmzh.decision_date', 'Укажите дату решения о ПМЖ или отметьте «Нет точных данных».' );

		$answers['pmzh'] = array(
			'date_precision' => $pmPrec,
			'decision_date'  => $pmDate,
			'basis'          => $this->text( $pm['basis'] ?? '' ),
			'basis_article'  => $this->text( $pm['basis_article'] ?? '' ),
			'decision_doc'   => $this->ynu( $pm['decision_doc'] ?? null, 'pmzh.decision_doc' ),
		);

		$answers['statuses']          = $this->statuses( $raw['statuses'] ?? array() );
		$answers['statuses_complete'] = $this->ynu( $raw['statuses_complete'] ?? null, 'statuses_complete' );

		$gapYes = $this->ynu( $hi['gap_without_permit'] ?? null, 'history.gap_without_permit' );

		$answers['history'] = array(
			'gap_without_permit' => $gapYes,
			'gaps'               => 'yes' === $gapYes ? $this->gaps( $hi['gaps'] ?? array() ) : array(),
			'late_renewal'       => $this->ynu( $hi['late_renewal'] ?? null, 'history.late_renewal' ),
			'refusal'            => $this->ynu( $hi['refusal'] ?? null, 'history.refusal' ),
			'pending_decision'   => $this->ynu( $hi['pending_decision'] ?? null, 'history.pending_decision' ),
			'old_documents'      => $this->enum( $hi['old_documents'] ?? null, 'old_documents', 'unknown', 'history.old_documents' ),
		);

		// Шаг 3.
		$tripsAny = $this->ynu( $raw['trips_any'] ?? null, 'trips_any' );

		$answers['trips_any']      = $tripsAny;
		$answers['trips']          = 'yes' === $tripsAny ? $this->trips( $raw['trips'] ?? array() ) : array();
		$answers['trips_complete'] = 'yes' === $tripsAny
			? $this->enum( $raw['trips_complete'] ?? null, 'trips_complete', 'unknown', 'trips_complete' )
			: 'unknown';

		// Шаг 4.
		$answers['special'] = array(
			'study_24v'           => $this->ynu( $sp['study_24v'] ?? null, 'special.study_24v' ),
			'studied_other_basis' => $this->ynu( $sp['studied_other_basis'] ?? null, 'special.studied_other_basis' ),
			'blue_card'           => $this->ynu( $sp['blue_card'] ?? null, 'special.blue_card' ),
			'protection'          => $this->enum( $sp['protection'] ?? null, 'protection', 'unknown', 'special.protection' ),
			'temporary_basis'     => $this->ynu( $sp['temporary_basis'] ?? null, 'special.temporary_basis' ),
			'other_eu_status'     => $this->enum( $sp['other_eu_status'] ?? null, 'other_eu_status', 'unknown', 'special.other_eu_status' ),
		);

		// Шаг 5.
		$answers['housing'] = array(
			'region'          => $this->text( $ho['region'] ?? '' ),
			'registered_same' => $this->ynu( $ho['registered_same'] ?? null, 'housing.registered_same' ),
			'tenure'          => $this->enum( $ho['tenure'] ?? null, 'tenure', 'unknown', 'housing.tenure' ),
			'proofs'          => $this->enumList( $ho['proofs'] ?? array(), 'housing_proofs', 'housing.proofs' ),
		);

		$nzok       = $this->ynu( $he['nzok'] ?? null, 'health.nzok' );
		$otherCover = 'yes' === $nzok ? 'unknown' : $this->enum( $he['other_cover'] ?? null, 'other_cover', 'unknown', 'health.other_cover' );

		$answers['health'] = array(
			'nzok'        => $nzok,
			'contributions' => 'yes' === $nzok ? $this->enum( $he['contributions'] ?? null, 'contributions', 'unknown', 'health.contributions' ) : 'unknown',
			'other_cover' => $otherCover,
			'cover_until' => in_array( $otherCover, array( 'policy', 'other' ), true ) ? $this->date( $he['cover_until'] ?? '', 'health.cover_until', false, 15 ) : '',
		);

		// Шаг 6.
		$currencyKeys = array_merge( Labels::keys( 'currency' ), array( '' ) );
		$curRaw       = isset( $fu['income_currency'] ) && is_scalar( $fu['income_currency'] ) ? (string) $fu['income_currency'] : '';
		$currency     = $curRaw;

		if ( ! in_array( $curRaw, $currencyKeys, true ) ) {
			$currency = '';
			$this->fail( 'funds.income_currency', 'Выберите валюту из списка.' );
		}

		$bankRaw = isset( $fu['bank_country'] ) && is_scalar( $fu['bank_country'] ) ? (string) $fu['bank_country'] : '';
		$bank    = $bankRaw;

		if ( ! in_array( $bankRaw, Labels::keys( 'bank_country' ), true ) ) {
			$bank = '';
			$this->fail( 'funds.bank_country', 'Выберите страну банка из списка.' );
		}

		$answers['funds'] = array(
			'income_sources'  => $this->enumList( $fu['income_sources'] ?? array(), 'income_sources', 'funds.income_sources' ),
			'income_amount'   => $this->floatOrNull( $fu['income_amount'] ?? null, 0.0, 1000000.0, 'funds.income_amount' ),
			'income_currency' => $currency,
			'income_proofs'   => $this->enumList( $fu['income_proofs'] ?? array(), 'income_proofs', 'funds.income_proofs' ),
			'savings'         => $this->ynu( $fu['savings'] ?? null, 'funds.savings' ),
			'bank_country'    => $bank,
			'dependents'      => $this->intOrNull( $fu['dependents'] ?? null, 0, 20, 'funds.dependents', 'Число иждивенцев — от 0 до 20.' ),
		);

		// Шаг 7.
		$answers['documents'] = array(
			'passport_until'  => $this->date( $do['passport_until'] ?? '', 'documents.passport_until', false, 15 ),
			'pmzh_card_until' => $this->date( $do['pmzh_card_until'] ?? '', 'documents.pmzh_card_until', false, 15 ),
			'adverse'         => $this->ynu( $do['adverse'] ?? null, 'documents.adverse' ),
			'entry_ban'       => $this->ynu( $do['entry_ban'] ?? null, 'documents.entry_ban' ),
			'extra_check'     => $this->ynu( $do['extra_check'] ?? null, 'documents.extra_check' ),
		);

		$planned = $this->date( $fi['planned_date'] ?? '', 'filing.planned_date' );

		if ( $this->strict && '' !== $planned && null !== DateMath::parse( $planned ) && DateMath::parse( $planned ) < $this->today ) {
			$this->fail( 'filing.planned_date', 'Дата подачи не может быть в прошлом. Укажите будущую дату или оставьте поле пустым.' );
			$planned = '';
		}

		$answers['filing'] = array( 'planned_date' => $planned );

		if ( $this->strict ) {
			$this->tripWarnings( $answers['trips'], '' === $planned ? $this->today : ( DateMath::parse( $planned ) ?? $this->today ), 'yes' === $tripsAny );
		}

		return array(
			'answers'  => $answers,
			'errors'   => $this->strict ? $this->errors : array(),
			'warnings' => $this->strict ? $this->warnings : array(),
		);
	}

	// ---------------------------------------------------------------------
	// Повторяемые блоки.

	/**
	 * @param mixed $rawList
	 * @return list<array<string,mixed>>
	 */
	private function statuses( $rawList ): array {
		$out  = array();
		$list = $this->rows( $rawList, self::MAX_STATUSES, 'statuses', 'Можно указать не более ' . self::MAX_STATUSES . ' статусов.' );

		foreach ( $list as $i => $row ) {
			$p    = 'statuses.' . $i;
			$kind = isset( $row['kind'] ) && is_scalar( $row['kind'] ) ? (string) $row['kind'] : '';

			if ( ! in_array( $kind, Labels::keys( 'status_kind' ), true ) ) {
				if ( '' !== $kind ) {
					$this->fail( $p . '.kind', 'Выберите вид статуса из списка.' );
				} else {
					$this->needed( $p . '.kind', 'Выберите вид статуса.' );
				}

				$kind = 'other';
			}

			$precision = $this->enum( $row['precision'] ?? null, 'precision', 'exact', $p . '.precision' );
			$end       = $this->enum( $row['end'] ?? null, 'status_end', 'unknown', $p . '.end' );
			$from      = $this->dateByPrecision( $row['from'] ?? '', $precision, $p . '.from', 'Укажите дату начала статуса или отметьте «Нет точных данных».' );
			$to        = '';

			if ( 'ended' === $end ) {
				$to = $this->dateByPrecision( $row['to'] ?? '', $precision, $p . '.to', 'Укажите дату окончания статуса или отметьте «Нет точных данных».' );
			}

			if ( $this->strict && '' !== $from && '' !== $to && $to < $from ) {
				$this->fail( $p . '.to', 'Дата окончания не может быть раньше даты начала.' );
			}

			$out[] = array(
				'kind'      => $kind,
				'basis'     => $this->text( $row['basis'] ?? '' ),
				'precision' => $precision,
				'from'      => $from,
				'end'       => $end,
				'to'        => $to,
			);
		}

		return $out;
	}

	/**
	 * @param mixed $rawList
	 * @return list<array<string,string>>
	 */
	private function gaps( $rawList ): array {
		$out  = array();
		$list = $this->rows( $rawList, self::MAX_GAPS, 'history.gaps', 'Можно указать не более ' . self::MAX_GAPS . ' периодов.' );

		foreach ( $list as $i => $row ) {
			$p    = 'history.gaps.' . $i;
			$from = $this->date( $row['from'] ?? '', $p . '.from' );
			$to   = $this->date( $row['to'] ?? '', $p . '.to' );

			if ( '' === $from ) {
				$this->needed( $p . '.from', 'Укажите начало периода.' );
			}

			if ( '' === $to ) {
				$this->needed( $p . '.to', 'Укажите конец периода.' );
			}

			if ( $this->strict && '' !== $from && '' !== $to && $to < $from ) {
				$this->fail( $p . '.to', 'Конец периода не может быть раньше начала.' );
			}

			$out[] = array( 'from' => $from, 'to' => $to );
		}

		return $out;
	}

	/**
	 * @param mixed $rawList
	 * @return list<array<string,string>>
	 */
	private function trips( $rawList ): array {
		$out  = array();
		$list = $this->rows( $rawList, self::MAX_TRIPS, 'trips', 'Можно указать не более ' . self::MAX_TRIPS . ' поездок.' );

		foreach ( $list as $i => $row ) {
			$p         = 'trips.' . $i;
			$precision = $this->enum( $row['precision'] ?? null, 'precision', 'exact', $p . '.precision' );
			$outD      = $this->dateByPrecision( $row['out'] ?? '', $precision, $p . '.out', 'Укажите дату выезда или отметьте «Нет точных данных».' );
			$backD     = $this->dateByPrecision( $row['back'] ?? '', $precision, $p . '.back', 'Укажите дату возвращения или отметьте «Нет точных данных».' );

			if ( $this->strict ) {
				$todayStr = DateMath::format( $this->today );

				if ( '' !== $outD && '' !== $backD && $backD < $outD ) {
					$this->fail( $p . '.back', 'Дата возвращения не может быть раньше даты выезда.' );
				} elseif ( '' !== $backD && $backD > $todayStr ) {
					$this->fail( $p . '.back', 'Укажите только совершённые поездки: дата возвращения не может быть в будущем.' );
				}

				if ( '' !== $outD && $outD > $todayStr ) {
					$this->fail( $p . '.out', 'Укажите только совершённые поездки: дата выезда не может быть в будущем.' );
				}
			}

			$out[] = array(
				'out'       => $outD,
				'back'      => $backD,
				'countries' => $this->text( $row['countries'] ?? '' ),
				'precision' => $precision,
			);
		}

		if ( $this->strict ) {
			$this->tripOverlaps( $out );
		}

		return $out;
	}

	/**
	 * Пересечение: out_b < back_a && out_a < back_b (строго). Поездка может
	 * начаться в день возвращения из предыдущей.
	 *
	 * @param list<array<string,string>> $trips
	 */
	private function tripOverlaps( array $trips ): void {
		$n = count( $trips );

		for ( $j = 1; $j < $n; $j++ ) {
			if ( '' === $trips[ $j ]['out'] || '' === $trips[ $j ]['back'] || isset( $this->errors[ 'trips.' . $j . '.out' ] ) || isset( $this->errors[ 'trips.' . $j . '.back' ] ) ) {
				continue;
			}

			for ( $i = 0; $i < $j; $i++ ) {
				if ( '' === $trips[ $i ]['out'] || '' === $trips[ $i ]['back'] || $trips[ $i ]['back'] < $trips[ $i ]['out'] ) {
					continue;
				}

				if ( $trips[ $j ]['out'] < $trips[ $i ]['back'] && $trips[ $i ]['out'] < $trips[ $j ]['back'] ) {
					$this->fail( 'trips.' . $j . '.out', 'Поездка пересекается с поездкой №' . ( $i + 1 ) . '. Проверьте даты.' );
					break;
				}
			}
		}
	}

	/**
	 * @param list<array<string,string>> $trips
	 */
	private function tripWarnings( array $trips, DateTimeImmutable $filing, bool $any ): void {
		if ( ! $any ) {
			return;
		}

		if ( empty( $trips ) ) {
			$this->warnings['trips'] = 'Вы отметили, что выезжали из Болгарии, но не указали ни одной поездки.';
			return;
		}

		$windowStart = DateMath::format( DateMath::addYears( $filing, -5 ) );

		foreach ( $trips as $i => $t ) {
			if ( '' !== $t['back'] && $t['back'] < $windowStart ) {
				$this->warnings[ 'trips.' . $i ] = 'Поездка №' . ( $i + 1 ) . ' завершилась раньше пятилетнего периода и в расчёте не участвует.';
			}
		}
	}

	// ---------------------------------------------------------------------
	// Примитивы.

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function section( array $raw, string $key ): array {
		return isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
	}

	/**
	 * Список строк-массивов с обрезкой по лимиту.
	 *
	 * @param mixed $raw
	 * @return list<array<string,mixed>>
	 */
	private function rows( $raw, int $max, string $path, string $overMessage ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();

		foreach ( $raw as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		if ( count( $rows ) > $max ) {
			$this->fail( $path, $overMessage );
			$rows = array_slice( $rows, 0, $max );
		}

		return $rows;
	}

	/** Обязательное поле: ошибка только в строгом режиме. */
	private function needed( string $path, string $message ): void {
		$this->fail( $path, $message );
	}

	private function fail( string $path, string $message ): void {
		if ( $this->strict && ! isset( $this->errors[ $path ] ) ) {
			$this->errors[ $path ] = $message;
		}
	}

	/** @param mixed $v */
	private function text( $v ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}

		$s = (string) $v;

		if ( function_exists( 'mb_scrub' ) ) {
			$s = mb_scrub( $s );
		}

		$s = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $s );
		$s = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
		$s = trim( $s );

		return mb_strlen( $s ) > self::MAX_TEXT ? trim( mb_substr( $s, 0, self::MAX_TEXT ) ) : $s;
	}

	/** @param mixed $v */
	private function ynu( $v, string $path ): string {
		return $this->enum( $v, 'yes_no_unknown', 'unknown', $path );
	}

	/** @param mixed $v */
	private function enum( $v, string $group, string $default, string $path ): string {
		if ( null === $v || '' === $v || ! is_scalar( $v ) ) {
			return $default;
		}

		$s = (string) $v;

		if ( in_array( $s, Labels::keys( $group ), true ) ) {
			return $s;
		}

		$this->fail( $path, 'Выберите один из предложенных вариантов.' );

		return $default;
	}

	/**
	 * @param mixed $v
	 * @return list<string>
	 */
	private function enumList( $v, string $group, string $path ): array {
		if ( ! is_array( $v ) ) {
			return array();
		}

		$keys = Labels::keys( $group );
		$out  = array();

		foreach ( $v as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$s = (string) $item;

			if ( ! in_array( $s, $keys, true ) ) {
				$this->fail( $path, 'Выберите варианты из предложенного списка.' );
				continue;
			}

			if ( ! in_array( $s, $out, true ) ) {
				$out[] = $s;
			}
		}

		return $out;
	}

	/** @param mixed $v */
	private function intOrNull( $v, int $min, int $max, string $path, string $message ): ?int {
		if ( null === $v || '' === $v ) {
			return null;
		}

		if ( is_string( $v ) ) {
			$v = trim( $v );
		}

		if ( ! is_numeric( $v ) || (float) $v !== floor( (float) $v ) || (int) $v < $min || (int) $v > $max ) {
			$this->fail( $path, $message );
			return null;
		}

		return (int) $v;
	}

	/** @param mixed $v */
	private function floatOrNull( $v, float $min, float $max, string $path ): ?float {
		if ( null === $v || '' === $v ) {
			return null;
		}

		if ( is_string( $v ) ) {
			$v = str_replace( array( ' ', "\xC2\xA0", ',' ), array( '', '', '.' ), $v );
		}

		if ( ! is_numeric( $v ) || (float) $v < $min || (float) $v > $max ) {
			$this->fail( $path, 'Сумма — число от 0 до 1 000 000.' );
			return null;
		}

		return round( (float) $v, 2 );
	}

	/**
	 * Дата Y-m-d (при $allowMonth — также Y-m, день ставится 01).
	 *
	 * @param mixed $v
	 */
	private function date( $v, string $path, bool $allowMonth = false, int $maxYears = 3 ): string {
		if ( null === $v || ! is_scalar( $v ) ) {
			return '';
		}

		$s = trim( (string) $v );

		if ( '' === $s ) {
			return '';
		}

		if ( $allowMonth && 1 === preg_match( '/^\d{4}-\d{2}$/', $s ) ) {
			$s .= '-01';
		}

		$dt = DateMath::parse( $s );

		if ( null === $dt ) {
			$this->fail( $path, 'Укажите существующую дату.' );
			return '';
		}

		$max = DateMath::addYears( $this->today, $maxYears );

		if ( $dt < $this->minDate || $dt > $max ) {
			$this->fail( $path, 'Дата вне допустимого диапазона (с 01.01.1950 до ' . DateMath::human( $max ) . ').' );
			return '';
		}

		return $s;
	}

	/**
	 * Дата строки с точностью: unknown → '', approx → допускается Y-m,
	 * exact → Y-m-d. В строгом режиме пустая дата при exact/approx — ошибка.
	 *
	 * @param mixed $v
	 */
	private function dateByPrecision( $v, string $precision, string $path, string $requiredMessage ): string {
		if ( 'unknown' === $precision ) {
			return '';
		}

		$d = $this->date( $v, $path, 'approx' === $precision );

		if ( '' === $d && ! isset( $this->errors[ $path ] ) ) {
			$this->needed( $path, $requiredMessage );
		}

		return $d;
	}
}
