<?php
/**
 * Зачёт стоимости купленной инструкции в консультацию.
 *
 * Одна строка на покупку инструкции (UNIQUE instruction_purchase_id).
 * Состояния:
 *   reserved(booking) — зачёт закреплён за бронью на время оформления;
 *   used(booking)     — консультация с зачётом оплачена;
 *   locked(booking)   — по консультации открыт спор, зачёт заблокирован;
 *   released          — свободен (неоплаченная бронь закончилась, либо
 *                       полный возврат консультации при действующей инструкции);
 *   void              — зачёт больше недоступен (исходная инструкция возвращена).
 *
 * Все переходы — одним условным UPDATE по строке; результат всегда
 * перепроверяется чтением (в MySQL affected_rows не считает строки без
 * изменений, поэтому на него не полагаемся).
 *
 * Решение «можно ли освобождать» (истёк срок И у брони нет живого заказа)
 * принимает CreditService по живому состоянию EDD — здесь только атомика.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

final class CreditRepository {

	private string $table;

	/** @param \wpdb|object $db */
	public function __construct( private object $db, ?string $table = null ) {
		$this->table = $table ?? Schema::table( 'credits' );
	}

	/** @return array<string,mixed>|null */
	public function byInstruction( int $instructionPurchaseId ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE instruction_purchase_id = %d", $instructionPurchaseId ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function byBooking( int $bookingId ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE booking_id = %d", $bookingId ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Закрепить зачёт за бронью. Успех, только если строки нет, она
	 * released, или уже reserved этой же бронью (повторный клик).
	 */
	public function reserve( int $instructionPurchaseId, int $bookingId, string $amount, string $currency, string $taxMode, int $holdUntil, string $now ): bool {
		$prev = $this->db->suppress_errors( true );

		$this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->table} (instruction_purchase_id, booking_id, amount, currency, tax_mode, state, hold_until, updated_at) VALUES (%d, %d, %s, %s, %s, 'reserved', %d, %s)",
				$instructionPurchaseId,
				$bookingId,
				$amount,
				$currency,
				$taxMode,
				$holdUntil,
				$now
			)
		);

		$this->db->suppress_errors( $prev );

		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET booking_id = %d, amount = %s, currency = %s, tax_mode = %s, state = 'reserved', hold_until = %d, updated_at = %s
				 WHERE instruction_purchase_id = %d AND ( state = 'released' OR ( state = 'reserved' AND booking_id = %d ) )",
				$bookingId,
				$amount,
				$currency,
				$taxMode,
				$holdUntil,
				$now,
				$instructionPurchaseId,
				$bookingId
			)
		);

		$row = $this->byInstruction( $instructionPurchaseId );

		return null !== $row && 'reserved' === $row['state'] && (int) $row['booking_id'] === $bookingId;
	}

	/** Освободить резерв конкретной брони (только reserved → released). */
	public function releaseReserved( int $bookingId, string $now ): void {
		$this->db->query(
			$this->db->prepare( "UPDATE {$this->table} SET state = 'released', updated_at = %s WHERE booking_id = %d AND state = 'reserved'", $now, $bookingId )
		);
	}

	/**
	 * Оплата подтверждена: зачёт этой брони → used. Допускается переход
	 * из reserved (своего) и из released (резерв истёк, но никто не занял:
	 * оплаченная сумма уже учла зачёт). Если зачёт занят/использован
	 * другой бронью — false (конфликт: фиксируется, без двойного учёта).
	 */
	public function markUsed( int $instructionPurchaseId, int $bookingId, string $now ): bool {
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET state = 'used', booking_id = %d, updated_at = %s
				 WHERE instruction_purchase_id = %d AND ( ( booking_id = %d AND state IN ('reserved','used') ) OR state = 'released' )",
				$bookingId,
				$now,
				$instructionPurchaseId,
				$bookingId
			)
		);

		$row = $this->byInstruction( $instructionPurchaseId );

		return null !== $row && (int) $row['booking_id'] === $bookingId && in_array( $row['state'], array( 'used', 'locked' ), true );
	}

	/** Спор по консультации: used → locked. */
	public function lock( int $bookingId, string $now ): void {
		$this->db->query(
			$this->db->prepare( "UPDATE {$this->table} SET state = 'locked', updated_at = %s WHERE booking_id = %d AND state = 'used'", $now, $bookingId )
		);
	}

	/** Спор решён в пользу продавца: locked → used. */
	public function unlock( int $bookingId, string $now ): void {
		$this->db->query(
			$this->db->prepare( "UPDATE {$this->table} SET state = 'used', updated_at = %s WHERE booking_id = %d AND state = 'locked'", $now, $bookingId )
		);
	}

	/**
	 * Полный возврат консультации: зачёт снова доступен, только если
	 * исходная инструкция живо оплачена ($instructionValid), иначе void.
	 */
	public function afterRefund( int $bookingId, bool $instructionValid, string $now ): void {
		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET state = %s, updated_at = %s WHERE booking_id = %d AND state IN ('used','locked','reserved')",
				$instructionValid ? 'released' : 'void',
				$now,
				$bookingId
			)
		);
	}

	/** Исходная инструкция возвращена полностью: свободный зачёт → void. */
	public function voidIfFree( int $instructionPurchaseId, string $now ): void {
		$this->db->query(
			$this->db->prepare( "UPDATE {$this->table} SET state = 'void', updated_at = %s WHERE instruction_purchase_id = %d AND state = 'released'", $now, $instructionPurchaseId )
		);
	}

	/** Исходная инструкция возвращена, зачёт только закреплён за неоплаченной бронью: reserved → void. */
	public function voidReserved( int $instructionPurchaseId, string $now ): void {
		$this->db->query(
			$this->db->prepare( "UPDATE {$this->table} SET state = 'void', updated_at = %s WHERE instruction_purchase_id = %d AND state = 'reserved'", $now, $instructionPurchaseId )
		);
	}

	/**
	 * Резервы с истёкшим сроком (кандидаты на освобождение после проверки
	 * живого заказа брони).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function expiredReservations( int $nowTs, int $limit = 50 ): array {
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$this->table} WHERE state = 'reserved' AND hold_until < %d ORDER BY id LIMIT %d", $nowTs, $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
