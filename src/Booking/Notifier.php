<?php
/**
 * Очередь писем по записям на консультацию (журнал notifications).
 *
 * UNIQUE (booking_id, type, seq) делает постановку идемпотентной: повторный
 * queue() с теми же параметрами тихо ничего не делает, поэтому повторные
 * события оплаты не порождают вторых писем.
 *
 * Тексты писем здесь НЕ формируются: отправитель подключается снаружи
 * (setSender() либо фильтр `bgdvzh_notification_sender`) и получает строку
 * уведомления и запись. Пока отправитель не задан, письма остаются в статусе
 * queued и уходят после подключения (повтор из cron — pending()).
 *
 * Отправитель: callable( array $notification, array $booking ): true|string
 * (true — отправлено, строка — текст ошибки, строка с префиксом `skip:` —
 * письмо больше не актуально, статус skipped без повторов).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\Schema;
use Bginfo\PmzhDvzh\Support\Clock;

final class Notifier {

	public const MAX_ATTEMPTS = 5;

	public const CUSTOMER_TYPES = array(
		'customer_confirmation', 'customer_updated', 'customer_cancelled', 'customer_meet_link', 'customer_reminder', 'customer_written_answer',
		'customer_confirmation_resend',
	);

	public const ADMIN_TYPES = array(
		'admin_new_paid', 'admin_time_conflict', 'admin_credit_conflict', 'admin_refunded', 'admin_disputed', 'admin_rescheduled', 'admin_mail_failed', 'admin_paid_after_cancel',
	);

	private object $db;
	private string $table;

	/** @var callable|null */
	private $sender = null;

	/**
	 * @param \wpdb|object|null $db
	 * @param callable():string $adminEmail Возвращает адрес администратора (из настроек или option admin_email).
	 */
	public function __construct( private BookingRepository $bookings, private $adminEmail, ?object $db = null, ?string $table = null ) {
		$this->db    = $db ?? $GLOBALS['wpdb'];
		$this->table = $table ?? Schema::table( 'notifications' );
	}

	/** @param callable(array<string,mixed>,array<string,mixed>):(true|string) $sender */
	public function setSender( callable $sender ): void {
		$this->sender = $sender;
	}

	/**
	 * Ставит письмо в очередь и пробует отправить сразу (тот же запрос).
	 *
	 * @return int id уведомления; 0 — такое уже есть (дубль) или запись не сохранена.
	 */
	public function queue( int $bookingId, string $type, int $seq = 0 ): int {
		if ( ! in_array( $type, self::CUSTOMER_TYPES, true ) && ! in_array( $type, self::ADMIN_TYPES, true ) ) {
			return 0;
		}

		$booking   = $this->bookings->get( $bookingId );
		$recipient = in_array( $type, self::ADMIN_TYPES, true )
			? (string) call_user_func( $this->adminEmail )
			: ( null === $booking ? '' : (string) $booking['email'] );

		$prev = $this->db->suppress_errors( true );
		$res  = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->table} (booking_id, type, seq, recipient, status, attempts, last_error, created_at) VALUES (%d, %s, %d, %s, 'queued', 0, '', %s)",
				$bookingId,
				$type,
				max( 0, $seq ),
				mb_substr( $recipient, 0, 190 ),
				Clock::now()
			)
		);
		$err = (string) $this->db->last_error;
		$this->db->suppress_errors( $prev );

		if ( false === $res || '' !== $err || 0 === (int) $res ) {
			// Дубль по UNIQUE — штатный повтор; прочие ошибки письмо не создают.
			return 0;
		}

		$id = (int) $this->db->insert_id;

		if ( null !== $booking ) {
			$this->dispatch( $id );
		}

		return $id;
	}

	/**
	 * Отправляет уведомление, если оно в очереди или в статусе failed с остатком попыток.
	 *
	 * @return string Итоговый статус ('' — уведомление не найдено).
	 */
	public function dispatch( int $id ): string {
		$row = $this->find( $id );

		if ( null === $row ) {
			return '';
		}

		if ( ! in_array( $row['status'], array( 'queued', 'failed' ), true ) || (int) $row['attempts'] >= self::MAX_ATTEMPTS ) {
			return (string) $row['status'];
		}

		$sender = $this->resolveSender();

		if ( null === $sender ) {
			return (string) $row['status'];
		}

		$booking = $this->bookings->get( (int) $row['booking_id'] );

		if ( null === $booking ) {
			$this->mark( $id, 'skipped', 'booking missing' );

			return 'skipped';
		}

		// Атомарный захват попытки: параллельный процесс не отправит то же письмо второй раз.
		$claimed = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET attempts = attempts + 1 WHERE id = %d AND status IN ('queued','failed') AND attempts = %d",
				$id,
				(int) $row['attempts']
			)
		);

		if ( 1 !== (int) $claimed ) {
			return (string) $row['status'];
		}

		try {
			$result = $sender( $row, $booking );
		} catch ( \Throwable $e ) {
			$result = 'exception ' . get_class( $e );
		}

		if ( true === $result ) {
			$this->mark( $id, 'sent', '' );

			return 'sent';
		}

		// Отправитель сообщает, что письмо больше не актуально (напоминание после начала встречи и т. п.).
		if ( is_string( $result ) && 0 === strpos( $result, 'skip:' ) ) {
			$this->mark( $id, 'skipped', $result );

			return 'skipped';
		}

		$this->mark( $id, 'failed', is_string( $result ) ? $result : 'send failed' );

		// Сбой письма — сообщаем администратору (тип уникален по id уведомления).
		if ( 'admin_mail_failed' !== $row['type'] && (int) $row['attempts'] + 1 >= self::MAX_ATTEMPTS ) {
			$this->queue( (int) $row['booking_id'], 'admin_mail_failed', $id );
		}

		return 'failed';
	}

	/**
	 * Уведомления к отправке (очередь и сбои с остатком попыток) — для cron.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function pending( int $limit = 50 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE status IN ('queued','failed') AND attempts < %d ORDER BY id ASC LIMIT %d",
				self::MAX_ATTEMPTS,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** Подключён ли отправитель писем. */
	public function hasSender(): bool {
		return null !== $this->resolveSender();
	}

	/**
	 * Повторная отправка очереди (cron). Без отправителя ничего не делает.
	 *
	 * @return int Сколько уведомлений обработано.
	 */
	public function retryPending( int $limit = 20 ): int {
		if ( ! $this->hasSender() ) {
			return 0;
		}

		$n = 0;

		foreach ( $this->pending( $limit ) as $row ) {
			$this->dispatch( (int) $row['id'] );
			++$n;
		}

		return $n;
	}

	/** @return list<array<string,mixed>> */
	public function forBooking( int $bookingId ): array {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY id ASC", $bookingId ), ARRAY_A );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** Сколько уведомлений с таким ключом в журнале (для тестов и админки). */
	public function count( int $bookingId, string $type ): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE booking_id = %d AND type = %s", $bookingId, $type ) );
	}

	/** Вручную вернуть письмо в очередь (кнопка админки): сбрасывает попытки у failed. */
	public function retry( int $id ): void {
		$this->db->query( $this->db->prepare( "UPDATE {$this->table} SET status = 'queued', attempts = 0, last_error = '' WHERE id = %d AND status = 'failed'", $id ) );
		$this->dispatch( $id );
	}

	// -----------------------------------------------------------------

	/** @return array<string,mixed>|null */
	private function find( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private function mark( int $id, string $status, string $error ): void {
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET status = %s, last_error = %s, sent_at = " . ( 'sent' === $status ? '%s' : 'NULL' ) . ' WHERE id = %d',
				...( 'sent' === $status ? array( $status, mb_substr( $error, 0, 255 ), Clock::now(), $id ) : array( $status, mb_substr( $error, 0, 255 ), $id ) )
			)
		);
	}

	/** @return callable|null */
	private function resolveSender(): ?callable {
		if ( null !== $this->sender ) {
			return $this->sender;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'bgdvzh_notification_sender', null, $this );

			if ( is_callable( $filtered ) ) {
				return $filtered;
			}
		}

		return null;
	}
}
