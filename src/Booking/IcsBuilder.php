<?php
/**
 * Файл календаря (.ics, RFC 5545) для синхронной консультации.
 *
 * В файл не попадают контакт, вопрос и email посетителя: только метка формата.
 * METHOD:PUBLISH для действующей записи, METHOD:CANCEL и STATUS:CANCELLED для
 * отменённой или возвращённой. UID постоянный (ics_uid), SEQUENCE растёт при
 * переносе и отмене, поэтому календарь обновляет событие, а не создаёт второе.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Http\ConsultController;

final class IcsBuilder {

	/** Максимум октетов в строке до перевода (RFC 5545, 3.1). */
	public const FOLD_OCTETS = 75;

	/** Статусы записи, для которых выпускается METHOD:CANCEL. */
	private const CANCELLED = array( 'cancelled', 'refunded' );

	/**
	 * Собрать .ics для записи. Для письменного формата (без времени встречи)
	 * возвращается пустая строка.
	 *
	 * @param array<string,mixed> $booking Строка bookings (terms уже массив).
	 */
	public static function build( array $booking ): string {
		$start = (int) ( $booking['start_utc'] ?? 0 );
		$end   = (int) ( $booking['end_utc'] ?? 0 );

		if ( $start <= 0 || $end <= $start ) {
			return '';
		}

		$cancel = in_array( (string) ( $booking['status'] ?? '' ), self::CANCELLED, true );
		$format = (string) ( $booking['format'] ?? '' );
		$label  = ConsultController::LABELS[ $format ] ?? $format;
		$uid    = (string) ( $booking['ics_uid'] ?? '' );

		if ( '' === $uid ) {
			$uid = 'booking-' . (int) ( $booking['id'] ?? 0 ) . '@localhost';
		}

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Bginfo//PMZH DVZH//RU',
			'CALSCALE:GREGORIAN',
			'METHOD:' . ( $cancel ? 'CANCEL' : 'PUBLISH' ),
			'BEGIN:VEVENT',
			'UID:' . self::escape( $uid ),
			'SEQUENCE:' . max( 0, (int) ( $booking['ics_sequence'] ?? 0 ) ),
			'DTSTAMP:' . self::utc( self::stamp( $booking ) ),
			'DTSTART:' . self::utc( $start ),
			'DTEND:' . self::utc( $end ),
			'SUMMARY:' . self::escape( 'Консультация ПМЖ → ДВЖ (' . $label . ')' ),
			'STATUS:' . ( $cancel ? 'CANCELLED' : 'CONFIRMED' ),
			'TRANSP:OPAQUE',
			'END:VEVENT',
			'END:VCALENDAR',
		);

		$out = '';

		foreach ( $lines as $line ) {
			$out .= self::fold( $line );
		}

		return $out;
	}

	/** Экранирование текстового значения: обратная косая, точка с запятой, запятая, перевод строки. */
	public static function escape( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( array( ';', ',' ), array( '\\;', '\\,' ), $text );

		return str_replace( "\n", '\\n', $text );
	}

	/**
	 * Складывание строки по 75 октетов: продолжение начинается с пробела
	 * (он тоже входит в лимит). Многобайтовые символы не разрываются.
	 * Возвращает строки, разделённые CRLF, с CRLF в конце.
	 */
	public static function fold( string $line ): string {
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $chars ) {
			$chars = str_split( $line );
		}

		$out   = '';
		$cur   = '';
		$bytes = 0;
		$limit = self::FOLD_OCTETS;

		foreach ( $chars as $ch ) {
			$len = strlen( $ch );

			if ( $bytes + $len > $limit ) {
				$out  .= $cur . "\r\n";
				$cur   = ' ';
				$bytes = 1;
			}

			$cur   .= $ch;
			$bytes += $len;
		}

		return $out . $cur . "\r\n";
	}

	/** Момент в формате UTC для iCalendar: 20260611T070000Z. */
	private static function utc( int $ts ): string {
		return gmdate( 'Ymd\THis\Z', $ts );
	}

	/** Время последнего изменения записи (updated_at, UTC); иначе текущее. */
	private static function stamp( array $booking ): int {
		$raw = (string) ( $booking['updated_at'] ?? '' );
		$ts  = '' === $raw ? false : strtotime( $raw . ' UTC' );

		return false === $ts ? time() : $ts;
	}
}
