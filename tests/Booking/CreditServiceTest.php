<?php
/**
 * Тесты CreditService: условия зачёта по живому состоянию EDD.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Booking\BookingSync;
use Bginfo\PmzhDvzh\Booking\CreditService;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Tests\Access\FakeOrderReader;
use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class CreditServiceTest extends BookingTestCase {

	private FakeOrderReader $reader;
	private CreditService $credit;
	private int $instructionPurchase;

	protected function setUp(): void {
		parent::setUp();

		// Оплаченная инструкция: заказ #10, позиция #100, 9.99 EUR, снимок 1.
		$this->reader                = FakeOrderReader::paidOrder();
		$this->reader->items[100]['total'] = 9.99;
		$policy                      = new AccessPolicy( $this->reader );
		$purchases                   = new PurchaseRepository();
		$sync                        = new BookingSync( $this->reader, $policy, $purchases, $this->bookings, $this->service );
		$this->credit                = new CreditService( $this->reader, $policy, $purchases, $this->credits, $this->bookings, $this->service, $sync );
		$this->instructionPurchase   = $this->purchase( 1, 10, 100, 'instruction' );
	}

	private function purchase( int $snapshot, int $order, int $item, string $kind ): int {
		$this->db->insert(
			'wp_bgdvzh_purchases',
			array(
				'snapshot_id' => $snapshot, 'order_id' => $order, 'order_item_id' => $item, 'download_id' => 5, 'price_id' => 2,
				'kind' => $kind, 'created_at' => '2026-06-10 06:00:00', 'updated_at' => '2026-06-10 06:00:00',
			)
		);

		return (int) $this->db->insert_id;
	}

	/** @return array{eligible:bool,reason:string,instruction_purchase_id:int,amount:string,item:string} */
	private function check( bool $owner = true, string $currency = 'EUR', string $tax = 'incl', float $price = 49.0, int $own = 0, int $snapshot = 1 ): array {
		return $this->credit->eligibility( $snapshot, $owner, $currency, $tax, $price, $own, $this->now );
	}

	public function test_paid_instruction_of_same_snapshot_gives_credit(): void {
		$r = $this->check();

		$this->assertTrue( $r['eligible'] );
		$this->assertSame( '9.99', $r['amount'] );
		$this->assertSame( '39.01', $r['item'] );
		$this->assertSame( $this->instructionPurchase, $r['instruction_purchase_id'] );
	}

	public function test_live_amount_follows_refunds_and_payment_state(): void {
		$this->assertSame( '9.99', $this->credit->liveAmount( $this->instructionPurchase, 'incl', 49.0 ) );

		$this->reader->refundItems[100] = array( array( 'subtotal' => 3.0, 'discount' => 0.0, 'total' => 3.0 ) );
		$this->assertSame( '6.99', $this->credit->liveAmount( $this->instructionPurchase, 'incl', 49.0 ) );

		$this->reader->orders[10]['status'] = 'failed';
		$this->reader->items[100]['status'] = 'failed';
		$this->assertNull( $this->credit->liveAmount( $this->instructionPurchase, 'incl', 49.0 ) );
		$this->assertNull( $this->credit->liveAmount( 99999, 'incl', 49.0 ) );
	}

	public function test_other_snapshot_gives_no_credit(): void {
		$this->assertFalse( $this->check( true, 'EUR', 'incl', 49.0, 0, 2 )['eligible'] );
	}

	public function test_owner_must_be_confirmed_by_session_or_user(): void {
		$r = $this->check( false );

		$this->assertFalse( $r['eligible'] );
		$this->assertSame( 'owner', $r['reason'] );
		$this->assertSame( '49.00', $r['item'] );
	}

	public function test_unpaid_or_refunded_instruction_gives_no_credit(): void {
		foreach ( array( array( 'pending', 'pending' ), array( 'complete', 'refunded' ), array( 'failed', 'failed' ) ) as [$order, $item] ) {
			$this->reader->orders[10]['status'] = $order;
			$this->reader->items[100]['status'] = $item;

			$this->assertFalse( $this->check()['eligible'], $order . '/' . $item );
		}
	}

	public function test_currency_must_match(): void {
		$this->reader->currencies[10] = 'USD';
		$r = $this->check();

		$this->assertFalse( $r['eligible'] );
		$this->assertSame( 'currency', $r['reason'] );
		$this->assertFalse( $this->check( true, '' )['eligible'] );
	}

	public function test_partial_refund_reduces_credit(): void {
		$this->reader->refundItems[100] = array( array( 'subtotal' => 3.0, 'discount' => 0.0, 'total' => 3.0 ) );

		$this->assertSame( '6.99', $this->check()['amount'] );
	}

	public function test_credit_must_be_less_than_price_and_positive(): void {
		$this->assertSame( 'too_large', $this->check( true, 'EUR', 'incl', 9.99 )['reason'] );
		$this->assertFalse( $this->check( true, 'EUR', 'incl', 5.0 )['eligible'] );

		$this->reader->items[100]['total'] = 0.0;
		$this->assertSame( 'zero', $this->check()['reason'] );
	}

	public function test_excl_mode_uses_subtotal_minus_discount(): void {
		$this->reader->amounts[100] = array( 'subtotal' => 10.0, 'discount' => 1.0, 'tax' => 1.8, 'total' => 10.8 );

		$this->assertSame( '9.00', $this->check( true, 'EUR', 'excl' )['amount'] );
		$this->assertSame( '10.80', $this->check( true, 'EUR', 'incl' )['amount'] );
	}

	public function test_consultation_purchase_does_not_give_credit(): void {
		$this->db->query( "DELETE FROM wp_bgdvzh_purchases WHERE id = {$this->instructionPurchase}" );
		$this->purchase( 1, 10, 100, 'consultation' );

		$this->assertFalse( $this->check()['eligible'] );
	}

	public function test_busy_credit_is_not_offered_except_to_its_own_hold(): void {
		$holder = $this->holdOk();
		$this->assertTrue( $this->credits->reserve( $this->instructionPurchase, (int) $holder['id'], '9.99', 'EUR', 'incl', $this->now + 900, '2026-06-10 06:00:00' ) );

		$other = $this->check();
		$this->assertFalse( $other['eligible'] );
		$this->assertSame( 'busy', $other['reason'] );

		$own = $this->check( true, 'EUR', 'incl', 49.0, (int) $holder['id'] );
		$this->assertTrue( $own['eligible'], 'повторный клик той же сессии: её собственный резерв считается свободным' );
	}

	public function test_used_and_void_credit_are_not_offered(): void {
		$holder = $this->paid( array( 'credit' => array( 'instruction_purchase_id' => $this->instructionPurchase, 'amount' => '9.99' ) ) );
		$this->assertSame( 'used', $this->creditRow( $this->instructionPurchase )['state'] );
		$this->assertFalse( $this->check()['eligible'] );

		$this->service->onFullRefund( $holder, false, $this->now + 100 );
		$this->assertSame( 'void', $this->creditRow( $this->instructionPurchase )['state'] );
		$this->assertFalse( $this->check()['eligible'] );
	}

	public function test_released_credit_is_offered_again(): void {
		$holder = $this->paid( array( 'credit' => array( 'instruction_purchase_id' => $this->instructionPurchase, 'amount' => '9.99' ) ) );
		$this->service->onFullRefund( $holder, true, $this->now + 100 );

		$this->assertSame( 'released', $this->creditRow( $this->instructionPurchase )['state'] );
		$this->assertTrue( $this->check()['eligible'] );
	}

	public function test_stale_holder_is_released_before_reserve(): void {
		$holder = $this->holdOk( array( 'session_id' => 11, 'credit' => array( 'instruction_purchase_id' => $this->instructionPurchase, 'amount' => '9.99' ) ) );
		$this->assertSame( 'reserved', $this->creditRow( $this->instructionPurchase )['state'] );

		// Срок резерва ещё не вышел.
		$this->assertFalse( $this->check()['eligible'] );

		// Резерв истёк и заказа нет: зачёт освобождается по живому состоянию.
		$late = $this->credit->eligibility( 1, true, 'EUR', 'incl', 49.0, 0, $this->now + 1000 );
		$this->assertTrue( $late['eligible'] );
		$this->assertSame( 'released', $this->creditRow( $this->instructionPurchase )['state'] );
		$this->assertSame( 'held', $this->fresh( (int) $holder['id'] )['status'], 'саму запись освобождение зачёта не трогает' );
	}

	public function test_stale_holder_with_live_order_keeps_credit(): void {
		$holder = $this->holdOk( array( 'session_id' => 11, 'credit' => array( 'instruction_purchase_id' => $this->instructionPurchase, 'amount' => '9.99' ) ) );

		// У держателя есть живой заказ #11 (processing).
		$this->reader->orders[11] = array_merge( $this->reader->orders[10], array( 'id' => 11, 'status' => 'processing' ) );
		$this->bookings->update( (int) $holder['id'], array( 'order_id' => 11 ) );

		$late = $this->credit->eligibility( 1, true, 'EUR', 'incl', 49.0, 0, $this->now + 5000 );

		$this->assertFalse( $late['eligible'] );
		$this->assertSame( 'reserved', $this->creditRow( $this->instructionPurchase )['state'] );
	}

	public function test_orphan_reservation_is_released(): void {
		$this->credits->reserve( $this->instructionPurchase, 9999, '9.99', 'EUR', 'incl', $this->now + 900, '2026-06-10 06:00:00' );

		$this->assertTrue( $this->check()['eligible'], 'записи-держателя нет — резерв осиротел' );
	}
}
