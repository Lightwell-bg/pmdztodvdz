<?php
/**
 * История сообщений по записи (таблица messages): письменные ответы
 * консультанта (direction = 'out'). Тексты видит только админ.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Support\Clock;

final class MessageRepository {

	private object $db;
	private string $table;

	/** @param \wpdb|object|null $db */
	public function __construct( ?object $db = null, ?string $table = null ) {
		$this->db    = $db ?? $GLOBALS['wpdb'];
		$this->table = $table ?? Schema::table( 'messages' );
	}

	/** Добавить исходящий ответ. Возвращает id (0 — не сохранено). */
	public function addOut( int $bookingId, string $subject, string $body, int $authorId ): int {
		$res = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->table} (booking_id, direction, subject, body, author_id, created_at, mail_status) VALUES (%d, 'out', %s, %s, %d, %s, 'queued')",
				$bookingId,
				mb_substr( $subject, 0, 255 ),
				$body,
				$authorId,
				Clock::now()
			)
		);

		return false === $res || 0 === (int) $res ? 0 : (int) $this->db->insert_id;
	}

	public function countOut( int $bookingId ): int {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE booking_id = %d AND direction = 'out'", $bookingId ) );
	}

	/**
	 * Исходящий ответ с порядковым номером n (1 — первый).
	 *
	 * @return array<string,mixed>|null
	 */
	public function nthOut( int $bookingId, int $n ): ?array {
		if ( $n < 1 ) {
			return null;
		}

		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table} WHERE booking_id = %d AND direction = 'out' ORDER BY id ASC LIMIT 1 OFFSET %d", $bookingId, $n - 1 ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** @return list<array<string,mixed>> */
	public function forBooking( int $bookingId ): array {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY id ASC", $bookingId ), ARRAY_A );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	public function setMailStatus( int $id, string $status ): void {
		$this->db->query( $this->db->prepare( "UPDATE {$this->table} SET mail_status = %s WHERE id = %d", mb_substr( $status, 0, 10 ), $id ) );
	}
}
