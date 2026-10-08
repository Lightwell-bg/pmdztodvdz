<?php
/**
 * Тесты BookingSync: запись приводится в соответствие с живым состоянием
 * заказа EDD (оплата, возврат, спор, истечение), orderAlive.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Booking\BookingSync;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Tests\Access\FakeOrderReader;
use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class BookingSyncTest extends BookingTestCase {

	private FakeOrderReader $reader;
	private BookingSync $sync;
	private PurchaseRepository $purchases;

	protected function setUp(): void {
		parent::setUp();

		$this->reader    = FakeOrderReader::paidOrder( 'pending', 'pending' );
		$this->purchases = new PurchaseRepository();
		$this->sync      = new BookingSync( $this->reader, new AccessPolicy( $this->reader ), $this->purchases, $this->bookings, $this->service );
	}

	/** Запись с привязанным заказом #10 (позиция #100, товар 5, вариант 2). */
	private function linked( array $over = array() ): array {
		$b = $this->holdOk( $over );

		$this->db->insert(
			'wp_bgdvzh_purchases',
			array(
				'snapshot_id' => 1, 'order_id' => 10, 'order_item_id' => 100, 'download_id' => 5, 'price_id' => 2, 'state' => 'pending',
				'kind' => 'consultation', 'booking_id' => (int) $b['id'], 'created_at' => '2026-06-10 06:00:00', 'updated_at' => '2026-06-10 06:00:00',
			)
		);
		$this->bookings->update( (int) $b['id'], array( 'order_id' => 10, 'order_item_id' => 100, 'purchase_id' => (int) $this->db->insert_id ) );

		return $this->fresh( (int) $b['id'] );
	}

	private function setOrder( string $orderStatus, string $itemStatus ): void {
		$this->reader->orders[10]['status'] = $orderStatus;
		$this->reader->items[100]['status'] = $itemStatus;
	}

	public function test_paid_order_confirms_and_is_idempotent(): void {
		$b = $this->linked();
		$this->setOrder( 'complete', 'complete' );

		$res = $this->sync->sync( $b, $this->now + 60 );
		$this->assertSame( 'confirmed', $res['status'] );

		$this->sync->sync( $b, $this->now + 61 );
		$this->sync->sync( $this->fresh( (int) $b['id'] ), $this->now + 62 );

		$this->assertSame( 1, $this->notes( (int) $b['id'], 'customer_confirmation' ) );
	}

	public function test_pending_order_changes_nothing_before_expiry(): void {
		$b = $this->linked();

		$this->assertSame( 'held', $this->sync->sync( $b, $this->now + 60 )['status'] );
		$this->assertSame( 12, $this->unitCount( (int) $b['id'] ) );
	}

	public function test_failed_order_after_hold_expiry_releases_time(): void {
		$b = $this->linked();
		$this->setOrder( 'failed', 'failed' );

		$this->assertSame( 'held', $this->sync->sync( $b, $this->now + 100 )['status'], 'резерв ещё действует' );
		$this->assertSame( 'expired', $this->sync->sync( $b, $this->now + 1000 )['status'] );
		$this->assertSame( 0, $this->unitCount( (int) $b['id'] ) );
	}

	public function test_pending_order_keeps_booking_and_credit_without_time_cutoff(): void {
		$b = $this->linked( array( 'credit' => array( 'instruction_purchase_id' => 5, 'amount' => '9.99' ) ) );

		// Заказ pending жив, пока EDD сам не пометит его брошенным: срока отсечения нет.
		$this->assertSame( 'held', $this->sync->sync( $b, $this->now + 900 + 600 )['status'] );
		$this->assertSame( 'held', $this->sync->sync( $b, $this->now + 900 + 86400 )['status'] );
		$this->assertSame( 'reserved', $this->creditRow( 5 )['state'] );

		// EDD пометил заказ брошенным — запись истекает, зачёт освобождается.
		$this->setOrder( 'abandoned', 'abandoned' );
		$this->assertSame( 'expired', $this->sync->sync( $b, $this->now + 900 + 86401 )['status'] );
		$this->assertSame( 'released', $this->creditRow( 5 )['state'] );
	}

	public function test_paid_order_of_cancelled_booking_keeps_credit_and_notifies_admin(): void {
		$b = $this->linked( array( 'credit' => array( 'instruction_purchase_id' => 5, 'amount' => '9.99' ) ) );
		$this->service->cancel( $b, $this->now + 10 );
		$this->setOrder( 'complete', 'complete' );

		$res = $this->sync->sync( $this->fresh( (int) $b['id'] ), $this->now + 20 );
		$this->sync->sync( $res, $this->now + 21 );

		$this->assertSame( 'cancelled', $res['status'] );
		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );
		$this->assertSame( 1, $this->notes( (int) $b['id'], 'admin_paid_after_cancel' ) );
		$this->assertSame( 0, $this->unitCount( (int) $b['id'] ) );
	}

	public function test_dispute_won_restores_pre_dispute_status(): void {
		$b = $this->linked( array( 'credit' => array( 'instruction_purchase_id' => 5, 'amount' => '9.99' ) ) );
		$this->setOrder( 'complete', 'complete' );
		$this->sync->sync( $b, $this->now + 60 );
		$this->assertSame( 'confirmed', $this->fresh( (int) $b['id'] )['status'] );

		$this->service->onDispute( $this->fresh( (int) $b['id'] ), $this->now + 70 );
		$this->assertSame( 'disputed', $this->fresh( (int) $b['id'] )['status'] );
		$this->assertSame( 'confirmed', $this->fresh( (int) $b['id'] )['pre_dispute_status'] );

		$this->service->onPaid( $this->fresh( (int) $b['id'] ), $this->now + 80 );
		$this->assertSame( 'confirmed', $this->fresh( (int) $b['id'] )['status'] );

		// needs_reschedule остаётся needs_reschedule.
		$c = $this->linked( array( 'session_id' => 11, 'start_utc' => $this->start( 12 ) ) );
		$this->bookings->transition( (int) $c['id'], array( 'held' ), 'needs_reschedule' );
		$this->service->onDispute( $this->fresh( (int) $c['id'] ), $this->now + 70 );
		$this->service->onPaid( $this->fresh( (int) $c['id'] ), $this->now + 80 );
		$this->assertSame( 'needs_reschedule', $this->fresh( (int) $c['id'] )['status'] );
	}

	public function test_dispute_entered_while_held_confirms_units_when_won(): void {
		$b = $this->holdOk( array( 'session_id' => 12, 'start_utc' => $this->start( 11 ) ) );
		$this->service->onDispute( $b, $this->now + 70 );
		$this->assertSame( 'held', $this->fresh( (int) $b['id'] )['pre_dispute_status'] );

		$this->service->onPaid( $this->fresh( (int) $b['id'] ), $this->now + 80 );

		$row = $this->fresh( (int) $b['id'] );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( 12, $this->unitCount( (int) $b['id'], true ) );
		$this->assertSame( 1, $this->notes( (int) $b['id'], 'customer_confirmation' ) );
	}

	public function test_full_refund_releases_slot_and_decides_credit_by_instruction(): void {
		$b = $this->linked( array( 'credit' => array( 'instruction_purchase_id' => 5, 'amount' => '9.99' ) ) );
		$this->setOrder( 'complete', 'complete' );
		$this->sync->sync( $b, $this->now + 60 );

		// Покупка инструкции (id=5) в БД нет → считается недействительной → зачёт void.
		$this->setOrder( 'complete', 'refunded' );
		$res = $this->sync->sync( $this->fresh( (int) $b['id'] ), $this->now + 120 );

		$this->assertSame( 'refunded', $res['status'] );
		$this->assertSame( 0, $this->unitCount( (int) $b['id'] ) );
		$this->assertSame( 'void', $this->creditRow( 5 )['state'] );
	}

	public function test_refund_keeps_credit_when_instruction_is_still_paid(): void {
		// Живая оплаченная инструкция: заказ #20, позиция #200 (тот же товар 5, вариант 2).
		$this->reader->orders[20] = array_merge( $this->reader->orders[10], array( 'id' => 20, 'status' => 'complete' ) );
		$this->reader->items[200] = array_merge( $this->reader->items[100], array( 'id' => 200, 'order_id' => 20, 'status' => 'complete' ) );
		$this->db->insert(
			'wp_bgdvzh_purchases',
			array( 'snapshot_id' => 1, 'order_id' => 20, 'order_item_id' => 200, 'download_id' => 5, 'price_id' => 2, 'kind' => 'instruction', 'created_at' => '2026-06-10 06:00:00', 'updated_at' => '2026-06-10 06:00:00' )
		);
		$instructionPurchaseId = (int) $this->db->insert_id;

		$b = $this->linked( array( 'credit' => array( 'instruction_purchase_id' => $instructionPurchaseId, 'amount' => '9.99' ) ) );
		$this->setOrder( 'complete', 'complete' );
		$this->sync->sync( $b, $this->now + 60 );
		$this->assertTrue( $this->sync->instructionStillValid( $this->fresh( (int) $b['id'] ) ) );

		$this->setOrder( 'complete', 'refunded' );
		$this->sync->sync( $this->fresh( (int) $b['id'] ), $this->now + 120 );

		$this->assertSame( 'released', $this->creditRow( $instructionPurchaseId )['state'] );
	}

	public function test_dispute_and_resolution(): void {
		$b = $this->linked( array( 'credit' => array( 'instruction_purchase_id' => 5, 'amount' => '9.99' ) ) );
		$this->setOrder( 'complete', 'complete' );
		$this->sync->sync( $b, $this->now + 60 );

		$this->setOrder( 'on_hold', 'on_hold' );
		$this->assertSame( 'disputed', $this->sync->sync( $this->fresh( (int) $b['id'] ), $this->now + 120 )['status'] );
		$this->assertSame( 'locked', $this->creditRow( 5 )['state'] );

		$this->setOrder( 'complete', 'complete' );
		$this->assertSame( 'confirmed', $this->sync->sync( $this->fresh( (int) $b['id'] ), $this->now + 180 )['status'] );
		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );
	}

	public function test_partial_refund_keeps_booking(): void {
		$b = $this->linked();
		$this->setOrder( 'partially_refunded', 'partially_refunded' );
		$this->reader->refunds[100] = 5.0;
		$this->reader->items[100]['total'] = 20.0;

		$this->assertSame( 'confirmed', $this->sync->sync( $b, $this->now + 60 )['status'] );
	}

	public function test_edd_unavailable_changes_nothing(): void {
		$b = $this->linked();
		$this->reader->available = false;

		$this->assertSame( 'held', $this->sync->sync( $b, $this->now + 5000 )['status'], 'состояние оплаты неизвестно' );
		$this->assertTrue( $this->sync->orderAlive( $b, $this->now + 5000 ) );
	}

	public function test_without_purchase_stale_hold_expires(): void {
		$b = $this->holdOk();

		$this->assertSame( 'held', $this->sync->sync( $b, $this->now + 100 )['status'] );
		$this->assertSame( 'expired', $this->sync->sync( $b, $this->now + 1000 )['status'] );
	}

	public function test_order_alive_statuses(): void {
		$b = $this->linked();

		foreach ( array( 'complete' => true, 'processing' => true, 'partially_refunded' => true, 'on_hold' => true, 'failed' => false, 'abandoned' => false, 'cancelled' => false, 'refunded' => false ) as $status => $alive ) {
			$this->reader->orders[10]['status'] = $status;
			$this->assertSame( $alive, $this->sync->orderAlive( $b, $this->now + 5000 ), $status );
		}

		$this->assertFalse( $this->sync->orderAlive( $this->holdOk( array( 'session_id' => 77, 'start_utc' => $this->start( 12 ) ) ), $this->now ), 'заказа нет' );
	}
}
