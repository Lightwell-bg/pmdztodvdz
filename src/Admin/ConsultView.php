<?php
/**
 * Чистые помощники экранов «Консультации» (без WordPress и без вывода):
 * подписи, допустимые действия, проверка Meet-ссылки и лимитов письменных
 * ответов, сетка календаря месяца, периоды фильтра, заметки админа.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Http\ConsultController;

final class ConsultView {

	/** Ссылка Google Meet: строго https://meet.google.com/abc-defg-hij. */
	public const MEET_PATTERN = '/^https:\/\/meet\.google\.com\/[a-z]{3}-[a-z]{4}-[a-z]{3}\z/';

	public const STATUSES = array(
		'held'             => 'Резерв (ждём оплату)',
		'expired'          => 'Резерв истёк',
		'confirmed'        => 'Подтверждена',
		'needs_reschedule' => 'Оплачено, нужен перенос',
		'disputed'         => 'Спор по оплате',
		'refunded'         => 'Возврат',
		'cancelled'        => 'Отменена',
		'completed'        => 'Проведена',
	);

	public static function statusLabel( string $status ): string {
		return self::STATUSES[ $status ] ?? $status;
	}

	/** @param array<string,mixed> $booking */
	public static function formatLabel( array $booking ): string {
		$format = (string) ( $booking['format'] ?? '' );
		$label  = ConsultController::LABELS[ $format ] ?? $format;

		if ( in_array( $format, array( 'telegram', 'whatsapp' ), true ) ) {
			$label .= 'call' === (string) ( $booking['mode'] ?? '' ) ? ' (звонок)' : ' (переписка)';
		}

		return $label;
	}

	public static function validMeetUrl( string $url ): bool {
		return 1 === preg_match( self::MEET_PATTERN, $url );
	}

	/** Часовой пояс консультанта из условий записи (иначе Europe/Sofia). */
	public static function zone( array $booking ): \DateTimeZone {
		$name = (string) ( ( (array) ( $booking['terms'] ?? array() ) )['consultant_tz'] ?? '' );

		try {
			return new \DateTimeZone( '' === $name ? 'Europe/Sofia' : $name );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'Europe/Sofia' );
		}
	}

	/**
	 * Время встречи либо срок письменного ответа в поясе консультанта.
	 *
	 * @param array<string,mixed> $booking
	 */
	public static function whenLabel( array $booking ): string {
		$tz = self::zone( $booking );

		if ( 'written' === (string) ( $booking['kind'] ?? '' ) ) {
			$d = (int) ( $booking['deadline_utc'] ?? 0 );

			return $d > 0 ? 'до ' . self::stamp( $d, $tz ) : '—';
		}

		$s = (int) ( $booking['start_utc'] ?? 0 );

		return $s > 0 ? self::stamp( $s, $tz ) : '—';
	}

	public static function stamp( int $ts, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'd.m.Y H:i T' );
	}

	/**
	 * Метки-флаги строки списка.
	 *
	 * @param array<string,mixed> $booking
	 * @return list<string>
	 */
	public static function flags( array $booking, bool $mailFailed = false ): array {
		$out = array();

		if ( 'needs_reschedule' === (string) $booking['status'] ) {
			$out[] = 'конфликт времени';
		}

		if ( ! empty( $booking['credit_conflict'] ) ) {
			$out[] = 'конфликт зачёта';
		}

		if ( $mailFailed ) {
			$out[] = 'ошибка письма';
		}

		return $out;
	}

	/**
	 * Действия, доступные админу для статуса записи (кроме заметок и повтора письма).
	 *
	 * @param array<string,mixed> $booking
	 * @return list<string> reschedule, cancel, meet, complete, answer, credit_resolved
	 */
	public static function allowedActions( array $booking ): array {
		$status  = (string) $booking['status'];
		$written = 'written' === (string) $booking['kind'];
		$out     = array();

		if ( in_array( $status, array( 'confirmed', 'needs_reschedule' ), true ) ) {
			$out[] = 'reschedule';
		}

		if ( in_array( $status, array( 'held', 'confirmed', 'needs_reschedule' ), true ) ) {
			$out[] = 'cancel';
		}

		if ( 'confirmed' === $status && 'meet' === (string) $booking['format'] ) {
			$out[] = 'meet';
		}

		if ( 'confirmed' === $status ) {
			$out[] = 'complete';
		}

		if ( $written && in_array( $status, array( 'confirmed', 'completed' ), true ) ) {
			$out[] = 'answer';
		}

		if ( ! empty( $booking['credit_conflict'] ) ) {
			$out[] = 'credit_resolved';
		}

		return $out;
	}

	/**
	 * Версия состояния записи: форма действия несёт её и устаревшая страница
	 * не может изменить новое состояние.
	 *
	 * @param array<string,mixed> $booking
	 */
	public static function revision( array $booking ): string {
		return substr( md5( (string) $booking['status'] . '|' . (int) $booking['ics_sequence'] . '|' . (int) $booking['reschedule_count'] . '|' . (string) $booking['meet_url'] . '|' . (int) $booking['credit_conflict'] ), 0, 12 );
	}

	/**
	 * Можно ли отправить ещё один письменный ответ. Первый ответ — всегда,
	 * дальше не больше written.followups уточнений до deadline + followup_days.
	 *
	 * @param array<string,mixed> $booking
	 * @return string|null Текст ошибки; null — можно.
	 */
	public static function writtenCheck( array $booking, int $answersSent, int $now ): ?string {
		if ( 'written' !== (string) $booking['kind'] ) {
			return 'Письменный ответ доступен только для формата email.';
		}

		if ( ! in_array( (string) $booking['status'], array( 'confirmed', 'completed' ), true ) ) {
			return 'Ответ можно отправить только по оплаченной подтверждённой записи.';
		}

		if ( $answersSent <= 0 ) {
			return null;
		}

		$w       = (array) ( ( (array) $booking['terms'] )['written'] ?? array() );
		$allowed = max( 0, (int) ( $w['followups'] ?? 0 ) );
		$used    = (int) $booking['followups_used'];

		if ( $used >= $allowed ) {
			return 'Лимит уточнений исчерпан: использовано ' . $used . ' из ' . $allowed . '.';
		}

		$until = (int) $booking['deadline_utc'] + max( 1, (int) ( $w['followup_days'] ?? 7 ) ) * 86400;

		if ( $now > $until ) {
			return 'Срок уточнений истёк ' . self::stamp( $until, self::zone( $booking ) ) . '.';
		}

		return null;
	}

	/**
	 * Добавить строку-событие в заметки админа.
	 */
	public static function appendNote( string $notes, string $line, int $now ): string {
		$line  = trim( (string) preg_replace( '/\s+/u', ' ', $line ) );
		$entry = gmdate( 'Y-m-d H:i', $now ) . ' UTC — ' . $line;

		return '' === trim( $notes ) ? $entry : rtrim( $notes ) . "\n" . $entry;
	}
}
