<?php
/**
 * Тесты полного возврата инструкции, давшей зачёт: reserved/released → void,
 * used/locked → конфликт зачёта без изменения оплаченной консультации.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Booking\CreditRefundHandler;
use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class CreditRefundHandlerTest extends BookingTestCase {

	private const CREDIT = array( 'instruction_purchase_id' => 5, 'amount' => '9.99' );

	private function handler(): CreditRefundHandler {
		return new CreditRefundHandler( $this->bookings, $this->credits, $this->notifier );
	}

	public function test_reserved_credit_is_voided_so_checkout_guard_rejects_it(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->assertSame( 'reserved', $this->creditRow( 5 )['state'] );

		$this->assertSame( 'void', $this->handler()->handle( 5, 100, $this->now + 10 ) );

		// CheckoutGuard требует состояние reserved: void даёт credit_changed.
		$this->assertSame( 'void', $this->creditRow( 5 )['state'] );
		$this->assertSame( 0, (int) $this->fresh( (int) $a['id'] )['credit_conflict'] );
		$this->assertSame( 0, $this->notes( (int) $a['id'], 'admin_credit_conflict' ), 'оплаты ещё не было — конфликта нет' );
	}

	public function test_released_credit_is_voided(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->credits->releaseReserved( (int) $a['id'], '2026-06-10 06:10:00' );
		$this->assertSame( 'released', $this->creditRow( 5 )['state'] );

		$this->handler()->handle( 5, 100, $this->now + 10 );

		$this->assertSame( 'void', $this->creditRow( 5 )['state'] );
	}

	public function test_used_credit_marks_conflict_and_keeps_paid_consultation(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->service->onPaid( $a, $this->now + 60 );
		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );

		$this->assertSame( 'conflict', $this->handler()->handle( 5, 100, $this->now + 120 ) );

		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( 1, (int) $row['credit_conflict'] );
		$this->assertSame( 'confirmed', $row['status'], 'оплаченная консультация не меняется' );
		$this->assertSame( 'used', $this->creditRow( 5 )['state'], 'состояние зачёта сохраняется' );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_credit_conflict' ) );

		$types = array();

		foreach ( $this->notifier->forBooking( (int) $a['id'] ) as $n ) {
			$types[] = $n['type'] . ':' . $n['seq'];
		}

		$this->assertContains( 'admin_credit_conflict:1', $types, 'seq = 1: не пересекается с письмом при оплате (seq 0)' );
	}

	public function test_repeated_events_do_not_duplicate_mail(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->service->onPaid( $a, $this->now + 60 );

		$this->handler()->handle( 5, 100, $this->now + 120 );
		$this->handler()->handle( 5, 100, $this->now + 130 );
		$this->handler()->handle( 5, 100, $this->now + 140 );

		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_credit_conflict' ) );
	}

	public function test_locked_credit_by_dispute_is_conflict_too(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->service->onPaid( $a, $this->now + 60 );
		$this->service->onDispute( $this->fresh( (int) $a['id'] ), $this->now + 70 );
		$this->assertSame( 'locked', $this->creditRow( 5 )['state'] );

		$this->assertSame( 'conflict', $this->handler()->handle( 5, 100, $this->now + 120 ) );
		$this->assertSame( 1, (int) $this->fresh( (int) $a['id'] )['credit_conflict'] );
	}

	public function test_conflict_does_not_collide_with_payment_time_conflict_mail(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->service->onPaid( $a, $this->now + 60 );
		$this->notifier->queue( (int) $a['id'], 'admin_credit_conflict', 0 );

		$this->handler()->handle( 5, 100, $this->now + 120 );

		$this->assertSame( 2, $this->notes( (int) $a['id'], 'admin_credit_conflict' ) );
	}

	public function test_unknown_instruction_purchase_does_nothing(): void {
		$this->assertSame( 'none', $this->handler()->handle( 999, 1, $this->now ) );
	}

	public function test_void_reserved_only_touches_reserved_rows(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->service->onPaid( $a, $this->now + 60 );

		$this->credits->voidReserved( 5, '2026-06-10 07:00:00' );

		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );
	}
}
