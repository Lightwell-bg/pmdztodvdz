<?php
/**
 * Настройки консультаций (поддерево `consult` опции bgdvzh_settings):
 * значения по умолчанию, очистка ввода и разбор текстовых полей админки
 * (расписание по дням недели, исключения и отпуска построчно).
 *
 * Значения не округляются «молча»: минуты и границы интервалов, не кратные
 * 5, сохраняются как есть, а ошибку показывает errors() через
 * Schedule\ScheduleConfig — консультация при этом выключена (ConsultGate).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Support;

use Bginfo\PmzhDvzh\Schedule\ScheduleConfig;

final class ConsultSettings {

	public const FORMATS = array( 'telegram', 'whatsapp', 'phone', 'meet', 'email' );

	private const DAYS = array(
		'пн' => 1, 'вт' => 2, 'ср' => 3, 'чт' => 4, 'пт' => 5, 'сб' => 6, 'вс' => 7,
		'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
	);

	/** @return array<string,mixed> */
	public static function defaults(): array {
		$weekly = array();

		foreach ( array( 1, 2, 3, 4, 5 ) as $d ) {
			$weekly[ $d ] = array( '10:00-13:00', '14:00-18:00' );
		}

		return array(
			'enabled'               => false,
			'download_id'           => 0,
			'price_id'              => null,
			'format_price_ids'      => array_fill_keys( self::FORMATS, null ),
			'consultant'            => array( 'name' => '', 'experience' => '', 'photo_id' => 0 ),
			'tz'                    => 'Europe/Sofia',
			'weekly'                => $weekly,
			'exceptions'            => array(),
			'vacations'             => array(),
			'duration_min'          => 45,
			'break_min'             => 15,
			'step_min'              => 15,
			'min_notice_hours'      => 24,
			'horizon_days'          => 30,
			'written_per_day'       => 2,
			'written_business_days' => 2,
			'written_scope'         => 'Письменный ответ по анкете и дополнительным вопросам',
			'written_followups'     => 1,
			'written_followup_days' => 7,
			'formats'               => array(
				'telegram' => array( 'enabled' => true, 'initiator_text' => 'Консультант напишет вам в Telegram в назначенное время' ),
				'whatsapp' => array( 'enabled' => true, 'initiator_text' => 'Консультант напишет вам в WhatsApp в назначенное время' ),
				'phone'    => array( 'enabled' => true, 'initiator_text' => 'Консультант позвонит вам в назначенное время' ),
				'meet'     => array( 'enabled' => true, 'initiator_text' => 'Ссылку на Google Meet консультант пришлёт отдельным письмом до встречи' ),
				'email'    => array( 'enabled' => true, 'initiator_text' => 'Ответ придёт на ваш email не позднее указанного срока' ),
			),
			'hold_minutes'          => 15,
			'reschedule_hours'      => 24,
			'reschedule_max'        => 1,
			'reminder_hours'        => 24,
			'admin_email'           => '',
			'analytics_events'      => false,
		);
	}

	/**
	 * Сохранённые настройки поверх значений по умолчанию (формат и вложенные
	 * группы дополняются по ключам; расписание заменяется целиком).
	 *
	 * @param mixed $stored
	 * @return array<string,mixed>
	 */
	public static function merge( $stored ): array {
		$def    = self::defaults();
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array_merge( $def, array_intersect_key( $stored, $def ) );

		foreach ( array( 'consultant', 'format_price_ids' ) as $group ) {
			$out[ $group ] = array_merge( $def[ $group ], is_array( $stored[ $group ] ?? null ) ? array_intersect_key( $stored[ $group ], $def[ $group ] ) : array() );
		}

		$formats = array();

		foreach ( self::FORMATS as $f ) {
			$given         = is_array( $stored['formats'][ $f ] ?? null ) ? $stored['formats'][ $f ] : array();
			$formats[ $f ] = array_merge( $def['formats'][ $f ], array_intersect_key( $given, $def['formats'][ $f ] ) );
		}

		$out['formats'] = $formats;

		foreach ( array( 'weekly', 'exceptions', 'vacations' ) as $k ) {
			$out[ $k ] = is_array( $out[ $k ] ) ? $out[ $k ] : $def[ $k ];
		}

		return $out;
	}

	/**
	 * Очистка ввода. Ключи, которых нет во входе, остаются из $current.
	 * Логические значения меняются, только если ключ передан (форма
	 * всегда отправляет скрытое поле 0 рядом с чекбоксом).
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $current
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input, array $current = array() ): array {
		$out = self::merge( $current );

		if ( array_key_exists( 'enabled', $input ) ) {
			$out['enabled'] = self::bool( $input['enabled'] );
		}

		if ( array_key_exists( 'download_id', $input ) ) {
			$out['download_id'] = max( 0, (int) $input['download_id'] );
		}

		if ( array_key_exists( 'price_id', $input ) ) {
			$out['price_id'] = self::nullableId( $input['price_id'] );
		}

		if ( is_array( $input['format_price_ids'] ?? null ) ) {
			foreach ( self::FORMATS as $f ) {
				if ( array_key_exists( $f, $input['format_price_ids'] ) ) {
					$out['format_price_ids'][ $f ] = self::nullableId( $input['format_price_ids'][ $f ] );
				}
			}
		}

		if ( is_array( $input['consultant'] ?? null ) ) {
			$c = $input['consultant'];

			if ( array_key_exists( 'name', $c ) ) {
				$out['consultant']['name'] = self::line( $c['name'], 120 );
			}

			if ( array_key_exists( 'experience', $c ) ) {
				$out['consultant']['experience'] = self::text( $c['experience'], 600 );
			}

			if ( array_key_exists( 'photo_id', $c ) ) {
				$out['consultant']['photo_id'] = max( 0, (int) $c['photo_id'] );
			}
		}

		if ( array_key_exists( 'tz', $input ) ) {
			$tz = is_scalar( $input['tz'] ) ? trim( (string) $input['tz'] ) : '';

			if ( in_array( $tz, \DateTimeZone::listIdentifiers(), true ) ) {
				$out['tz'] = $tz;
			}
		}

		if ( array_key_exists( 'weekly', $input ) ) {
			$out['weekly'] = is_array( $input['weekly'] ) ? self::weeklyFromArray( $input['weekly'] ) : self::weeklyFromText( (string) $input['weekly'] );
		}

		if ( array_key_exists( 'exceptions', $input ) ) {
			$out['exceptions'] = is_array( $input['exceptions'] ) ? self::exceptionsFromArray( $input['exceptions'] ) : self::exceptionsFromText( (string) $input['exceptions'] );
		}

		if ( array_key_exists( 'vacations', $input ) ) {
			$out['vacations'] = is_array( $input['vacations'] ) ? self::vacationsFromArray( $input['vacations'] ) : self::vacationsFromText( (string) $input['vacations'] );
		}

		$ints = array(
			'duration_min'          => array( 1, 600 ),
			'break_min'             => array( 0, 240 ),
			'step_min'              => array( 1, 240 ),
			'min_notice_hours'      => array( 0, 720 ),
			'horizon_days'          => array( 1, 365 ),
			'written_per_day'       => array( 0, 50 ),
			'written_business_days' => array( 1, 30 ),
			'written_followups'     => array( 0, 10 ),
			'written_followup_days' => array( 1, 90 ),
			'hold_minutes'          => array( 5, 120 ),
			'reschedule_hours'      => array( 0, 720 ),
			'reschedule_max'        => array( 0, 10 ),
			'reminder_hours'        => array( 1, 168 ),
		);

		foreach ( $ints as $key => $range ) {
			if ( array_key_exists( $key, $input ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}

		if ( array_key_exists( 'written_scope', $input ) ) {
			$out['written_scope'] = self::line( $input['written_scope'], 300 );
		}

		if ( is_array( $input['formats'] ?? null ) ) {
			foreach ( self::FORMATS as $f ) {
				$given = $input['formats'][ $f ] ?? null;

				if ( ! is_array( $given ) ) {
					continue;
				}

				if ( array_key_exists( 'enabled', $given ) ) {
					$out['formats'][ $f ]['enabled'] = self::bool( $given['enabled'] );
				}

				if ( array_key_exists( 'initiator_text', $given ) ) {
					$out['formats'][ $f ]['initiator_text'] = self::line( $given['initiator_text'], 300 );
				}
			}
		}

		if ( array_key_exists( 'admin_email', $input ) ) {
			$mail                = is_scalar( $input['admin_email'] ) ? trim( (string) $input['admin_email'] ) : '';
			$out['admin_email']  = ( '' !== $mail && false !== filter_var( $mail, FILTER_VALIDATE_EMAIL ) ) ? mb_substr( $mail, 0, 190 ) : '';
		}

		if ( array_key_exists( 'analytics_events', $input ) ) {
			$out['analytics_events'] = self::bool( $input['analytics_events'] );
		}

		return $out;
	}

	/**
	 * Ошибки конфигурации: нарушения ScheduleConfig (кратность 5 минутам,
	 * формат интервалов, даты) и «нет ни одного формата».
	 *
	 * @param array<string,mixed> $c Результат merge()/sanitize().
	 * @return list<string>
	 */
	public static function errors( array $c ): array {
		$errors = ( new ScheduleConfig( $c ) )->errors();
		$any    = false;

		foreach ( self::FORMATS as $f ) {
			if ( ! empty( $c['formats'][ $f ]['enabled'] ) ) {
				$any = true;
			}
		}

		if ( ! $any ) {
			$errors[] = 'Не включён ни один формат консультации.';
		}

		return $errors;
	}

	// -----------------------------------------------------------------
	// Текстовые поля.

	/**
	 * Строки вида «1: 10:00-13:00, 14:00-18:00» (день 1-7 или пн-вс).
	 *
	 * @return array<int,list<string>>
	 */
	public static function weeklyFromText( string $text ): array {
		$out = array();

		foreach ( preg_split( '/\R/u', $text ) ?: array() as $line ) {
			if ( 1 !== preg_match( '/^\s*([^\s:]+)\s*:?\s*(.*)$/u', $line, $m ) ) {
				continue;
			}

			$key = mb_strtolower( $m[1] );
			$dow = ctype_digit( $key ) ? (int) $key : ( self::DAYS[ $key ] ?? 0 );

			if ( $dow < 1 || $dow > 7 ) {
				continue;
			}

			$out[ $dow ] = array_merge( $out[ $dow ] ?? array(), self::splitIntervals( $m[2] ) );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * @param array<int|string,mixed> $in день => строка или список интервалов
	 * @return array<int,list<string>>
	 */
	public static function weeklyFromArray( array $in ): array {
		$out = array();

		foreach ( $in as $dow => $list ) {
			$dow = (int) $dow;

			if ( $dow < 1 || $dow > 7 ) {
				continue;
			}

			$out[ $dow ] = self::splitIntervals( is_array( $list ) ? implode( ',', array_map( 'strval', $list ) ) : (string) $list );
		}

		ksort( $out );

		return $out;
	}

	/** @param array<int,list<string>> $weekly */
	public static function weeklyToText( array $weekly ): string {
		$lines = array();

		foreach ( $weekly as $dow => $list ) {
			if ( ! empty( $list ) ) {
				$lines[] = (int) $dow . ': ' . implode( ', ', $list );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Строки «2026-12-24» (выходной) или «2026-12-31: 10:00-12:00, 14:00-16:00».
	 *
	 * @return list<array{date:string,intervals:list<string>}>
	 */
	public static function exceptionsFromText( string $text ): array {
		$out = array();

		foreach ( preg_split( '/\R/u', $text ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})\s*[:\s]?\s*(.*)$/u', $line, $m ) ) {
				$out[] = array( 'date' => mb_substr( $line, 0, 40 ), 'intervals' => array() );
				continue;
			}

			$out[] = array( 'date' => $m[1], 'intervals' => self::splitIntervals( $m[2] ) );
		}

		return $out;
	}

	/**
	 * @param array<int|string,mixed> $in
	 * @return list<array{date:string,intervals:list<string>}>
	 */
	public static function exceptionsFromArray( array $in ): array {
		$out = array();

		foreach ( $in as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$list  = $row['intervals'] ?? array();
			$out[] = array(
				'date'      => mb_substr( trim( (string) ( $row['date'] ?? '' ) ), 0, 40 ),
				'intervals' => self::splitIntervals( is_array( $list ) ? implode( ',', array_map( 'strval', $list ) ) : (string) $list ),
			);
		}

		return $out;
	}

	/** @param list<array{date:string,intervals:list<string>}> $exceptions */
	public static function exceptionsToText( array $exceptions ): string {
		$lines = array();

		foreach ( $exceptions as $ex ) {
			$iv      = (array) ( $ex['intervals'] ?? array() );
			$lines[] = (string) ( $ex['date'] ?? '' ) . ( empty( $iv ) ? '' : ': ' . implode( ', ', $iv ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Строки «2026-08-01 - 2026-08-15» либо одна дата.
	 *
	 * @return list<array{from:string,to:string}>
	 */
	public static function vacationsFromText( string $text ): array {
		$out = array();

		foreach ( preg_split( '/\R/u', $text ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			preg_match_all( '/\d{4}-\d{2}-\d{2}/', $line, $m );
			$dates = $m[0];

			if ( empty( $dates ) ) {
				$out[] = array( 'from' => mb_substr( $line, 0, 40 ), 'to' => '' );
			} else {
				$out[] = array( 'from' => $dates[0], 'to' => $dates[1] ?? $dates[0] );
			}
		}

		return $out;
	}

	/**
	 * @param array<int|string,mixed> $in
	 * @return list<array{from:string,to:string}>
	 */
	public static function vacationsFromArray( array $in ): array {
		$out = array();

		foreach ( $in as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array( 'from' => mb_substr( trim( (string) ( $row['from'] ?? '' ) ), 0, 40 ), 'to' => mb_substr( trim( (string) ( $row['to'] ?? '' ) ), 0, 40 ) );
			}
		}

		return $out;
	}

	/** @param list<array{from:string,to:string}> $vacations */
	public static function vacationsToText( array $vacations ): string {
		$lines = array();

		foreach ( $vacations as $v ) {
			$from = (string) ( $v['from'] ?? '' );
			$to   = (string) ( $v['to'] ?? '' );
			$lines[] = $from === $to || '' === $to ? $from : $from . ' - ' . $to;
		}

		return implode( "\n", $lines );
	}

	// -----------------------------------------------------------------

	/** @return list<string> */
	private static function splitIntervals( string $s ): array {
		$out = array();

		foreach ( preg_split( '/[,;]/', $s ) ?: array() as $part ) {
			$part = trim( $part );

			if ( '' !== $part && 1 === preg_match( '/\d/', $part ) ) {
				$out[] = mb_substr( $part, 0, 20 );
			}
		}

		return $out;
	}

	/** @param mixed $v */
	private static function bool( $v ): bool {
		return true === $v || 1 === $v || '1' === $v || 'on' === $v || 'yes' === $v || 'true' === $v;
	}

	/** @param mixed $v */
	private static function nullableId( $v ): ?int {
		return ( null === $v || '' === $v || ! is_numeric( $v ) ) ? null : max( 0, (int) $v );
	}

	/** @param mixed $v */
	private static function line( $v, int $max ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $s ) : trim( strip_tags( $s ) );

		return mb_substr( $s, 0, $max );
	}

	/** @param mixed $v */
	private static function text( $v, int $max ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $s ) : trim( strip_tags( $s ) );

		return mb_substr( $s, 0, $max );
	}
}
