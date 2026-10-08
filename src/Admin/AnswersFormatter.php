<?php
/**
 * Превращает нормализованные ответы анкеты (docs/ANSWERS_FORMAT.md) в
 * читаемые разделы «подпись — значение» для экрана просмотра анкеты.
 * Чистая функция: без WP и без вывода. Значения не экранируются здесь,
 * экранирует экран при выводе. Многострочные значения разделены "\n".
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Questionnaire\Labels;

final class AnswersFormatter {

	private const EMPTY = '—';

	/**
	 * @param array<string,mixed> $a Нормализованные ответы.
	 * @return list<array{title:string,rows:list<array{0:string,1:string}>}>
	 */
	public static function sections( array $a ): array {
		$ap  = self::arr( $a, 'applicant' );
		$pm  = self::arr( $a, 'pmzh' );
		$hi  = self::arr( $a, 'history' );
		$sp  = self::arr( $a, 'special' );
		$ho  = self::arr( $a, 'housing' );
		$he  = self::arr( $a, 'health' );
		$fu  = self::arr( $a, 'funds' );
		$do  = self::arr( $a, 'documents' );
		$fi  = self::arr( $a, 'filing' );
		$ynu = 'yes_no_unknown';

		$gaps = array();

		foreach ( self::list( $hi, 'gaps' ) as $g ) {
			$gaps[] = self::date( $g['from'] ?? '' ) . ' — ' . self::date( $g['to'] ?? '' );
		}

		$trips = array();

		foreach ( self::list( $a, 'trips' ) as $t ) {
			$line = self::date( $t['out'] ?? '' ) . ' — ' . self::date( $t['back'] ?? '' );

			if ( '' !== self::text( $t['countries'] ?? '' ) ) {
				$line .= ', ' . self::text( $t['countries'] );
			}

			$line   .= ' (' . Labels::label( 'precision', self::text( $t['precision'] ?? '' ) ) . ')';
			$trips[] = $line;
		}

		$statuses = array();

		foreach ( self::list( $a, 'statuses' ) as $s ) {
			$line = Labels::label( 'status_kind', self::text( $s['kind'] ?? '' ) );

			if ( '' !== self::text( $s['basis'] ?? '' ) ) {
				$line .= ' («' . self::text( $s['basis'] ) . '»)';
			}

			$line .= ': с ' . self::date( $s['from'] ?? '' );
			$line .= ', ' . mb_strtolower( Labels::label( 'status_end', self::text( $s['end'] ?? '' ) ) );

			if ( '' !== self::text( $s['to'] ?? '' ) ) {
				$line .= ' ' . self::date( $s['to'] );
			}

			$line      .= ' (' . Labels::label( 'precision', self::text( $s['precision'] ?? '' ) ) . ')';
			$statuses[] = $line;
		}

		return array(
			array(
				'title' => '1. О заявителе',
				'rows'  => array(
					array( 'Гражданство', self::textOrDash( $ap['citizenship'] ?? '' ) ),
					array( 'Другие гражданства', self::textOrDash( $ap['other_citizenships'] ?? '' ) ),
					array( 'Гражданство ЕС/Болгарии', Labels::label( 'eu_status', self::text( $ap['eu_status'] ?? '' ) ) ),
					array( 'Возраст', isset( $ap['age'] ) && is_numeric( $ap['age'] ) ? (string) (int) $ap['age'] : 'не указан' ),
					array( 'Только за себя или семья', Labels::label( 'family', self::text( $ap['family'] ?? '' ) ) ),
				),
			),
			array(
				'title' => '2. ПМЖ и прежние статусы',
				'rows'  => array(
					array( 'Точность даты решения о ПМЖ', Labels::label( 'precision', self::text( $pm['date_precision'] ?? '' ) ) ),
					array( 'Дата решения о ПМЖ', self::dateOrDash( $pm['decision_date'] ?? '' ) ),
					array( 'Основание ПМЖ', self::textOrDash( $pm['basis'] ?? '' ) ),
					array( 'Статья и пункт', self::textOrDash( $pm['basis_article'] ?? '' ) ),
					array( 'Решение о ПМЖ сохранилось', Labels::label( $ynu, self::text( $pm['decision_doc'] ?? '' ) ) ),
					array( 'Статусы до ПМЖ', self::lines( $statuses ) ),
					array( 'Перечислены все статусы', Labels::label( $ynu, self::text( $a['statuses_complete'] ?? '' ) ) ),
					array( 'Были периоды без разрешения', Labels::label( $ynu, self::text( $hi['gap_without_permit'] ?? '' ) ) ),
					array( 'Периоды без разрешения', self::lines( $gaps ) ),
					array( 'Просроченные продления', Labels::label( $ynu, self::text( $hi['late_renewal'] ?? '' ) ) ),
					array( 'Отказы миграции', Labels::label( $ynu, self::text( $hi['refusal'] ?? '' ) ) ),
					array( 'Ожидание решения', Labels::label( $ynu, self::text( $hi['pending_decision'] ?? '' ) ) ),
					array( 'Старые документы', Labels::label( 'old_documents', self::text( $hi['old_documents'] ?? '' ) ) ),
				),
			),
			array(
				'title' => '3. Поездки',
				'rows'  => array(
					array( 'Выезжали из Болгарии', Labels::label( $ynu, self::text( $a['trips_any'] ?? '' ) ) ),
					array( 'Поездки', self::lines( $trips ) ),
					array( 'Полнота списка поездок', Labels::label( 'trips_complete', self::text( $a['trips_complete'] ?? '' ) ) ),
				),
			),
			array(
				'title' => '4. Особые основания',
				'rows'  => array(
					array( 'Разрешение для обучения (ст. 24в)', Labels::label( $ynu, self::text( $sp['study_24v'] ?? '' ) ) ),
					array( 'Учёба на другом основании', Labels::label( $ynu, self::text( $sp['studied_other_basis'] ?? '' ) ) ),
					array( 'Синяя карта ЕС', Labels::label( $ynu, self::text( $sp['blue_card'] ?? '' ) ) ),
					array( 'Защита', Labels::label( 'protection', self::text( $sp['protection'] ?? '' ) ) ),
					array( 'Только временное основание', Labels::label( $ynu, self::text( $sp['temporary_basis'] ?? '' ) ) ),
					array( 'Статус в другой стране ЕС', Labels::label( 'other_eu_status', self::text( $sp['other_eu_status'] ?? '' ) ) ),
				),
			),
			array(
				'title' => '5. Жильё и медицинское страхование',
				'rows'  => array(
					array( 'Город или область', self::textOrDash( $ho['region'] ?? '' ) ),
					array( 'Адресная регистрация там же', Labels::label( $ynu, self::text( $ho['registered_same'] ?? '' ) ) ),
					array( 'Жильё', Labels::label( 'tenure', self::text( $ho['tenure'] ?? '' ) ) ),
					array( 'Документы на жильё', Labels::labels( 'housing_proofs', self::strings( $ho['proofs'] ?? array() ) ) ),
					array( 'Застрахован в НЗОК', Labels::label( $ynu, self::text( $he['nzok'] ?? '' ) ) ),
					array( 'Взносы НЗОК', Labels::label( 'contributions', self::text( $he['contributions'] ?? '' ) ) ),
					array( 'Другое покрытие', Labels::label( 'other_cover', self::text( $he['other_cover'] ?? '' ) ) ),
					array( 'Покрытие действует до', self::dateOrDash( $he['cover_until'] ?? '' ) ),
				),
			),
			array(
				'title' => '6. Средства',
				'rows'  => array(
					array( 'Источники дохода', Labels::labels( 'income_sources', self::strings( $fu['income_sources'] ?? array() ) ) ),
					array( 'Средний доход в месяц', self::amount( $fu['income_amount'] ?? null, self::text( $fu['income_currency'] ?? '' ) ) ),
					array( 'Подтверждение дохода', Labels::labels( 'income_proofs', self::strings( $fu['income_proofs'] ?? array() ) ) ),
					array( 'Сбережения', Labels::label( $ynu, self::text( $fu['savings'] ?? '' ) ) ),
					array( 'Страна банка', Labels::label( 'bank_country', self::text( $fu['bank_country'] ?? '' ) ) ),
					array( 'Иждивенцы', isset( $fu['dependents'] ) && is_numeric( $fu['dependents'] ) ? (string) (int) $fu['dependents'] : 'не указано' ),
				),
			),
			array(
				'title' => '7. Документы, обстоятельства и дата подачи',
				'rows'  => array(
					array( 'Паспорт действует до', self::dateOrDash( $do['passport_until'] ?? '' ) ),
					array( 'Карта ПМЖ действует до', self::dateOrDash( $do['pmzh_card_until'] ?? '' ) ),
					array( 'Неблагоприятные решения миграции', Labels::label( $ynu, self::text( $do['adverse'] ?? '' ) ) ),
					array( 'Запрет на въезд', Labels::label( $ynu, self::text( $do['entry_ban'] ?? '' ) ) ),
					array( 'Иные обстоятельства для проверки', Labels::label( $ynu, self::text( $do['extra_check'] ?? '' ) ) ),
					array( 'Планируемая дата подачи', '' === self::text( $fi['planned_date'] ?? '' ) ? 'не указана (расчёт на дату отправки)' : self::date( $fi['planned_date'] ) ),
				),
			),
		);
	}

	/** @param array<string,mixed> $a @return array<string,mixed> */
	private static function arr( array $a, string $key ): array {
		return isset( $a[ $key ] ) && is_array( $a[ $key ] ) ? $a[ $key ] : array();
	}

	/**
	 * @param array<string,mixed> $a
	 * @return list<array<string,mixed>>
	 */
	private static function list( array $a, string $key ): array {
		$out = array();

		foreach ( self::arr( $a, $key ) as $item ) {
			if ( is_array( $item ) ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/** @param mixed $v */
	private static function text( $v ): string {
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** @param mixed $v */
	private static function textOrDash( $v ): string {
		$s = self::text( $v );

		return '' === $s ? self::EMPTY : $s;
	}

	/** @param mixed $v */
	private static function date( $v ): string {
		$s = self::text( $v );

		return '' === $s ? 'нет даты' : Display::date( $s );
	}

	/** @param mixed $v */
	private static function dateOrDash( $v ): string {
		$s = self::text( $v );

		return '' === $s ? self::EMPTY : Display::date( $s );
	}

	/** @param mixed $v @return list<string> */
	private static function strings( $v ): array {
		if ( ! is_array( $v ) ) {
			return array();
		}

		return array_values( array_map( 'strval', array_filter( $v, 'is_scalar' ) ) );
	}

	/** @param list<string> $lines */
	private static function lines( array $lines ): string {
		return empty( $lines ) ? self::EMPTY : implode( "\n", $lines );
	}

	/** @param mixed $amount */
	private static function amount( $amount, string $currency ): string {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return 'не указан';
		}

		$out = number_format( (float) $amount, 2, ',', ' ' );

		return '' === $currency ? $out : $out . ' ' . $currency;
	}
}
