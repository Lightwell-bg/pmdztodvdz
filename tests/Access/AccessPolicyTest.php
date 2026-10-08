<?php
/**
 * Тесты AccessPolicy: состояние оплаты по живым данным EDD и владелец снимка.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Access;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessPolicyTest extends TestCase {

	private function state( FakeOrderReader $reader ): array {
		return ( new AccessPolicy( $reader ) )->paymentState( FakeOrderReader::purchase() );
	}

	/** @return array<string,array{0:string,1:string,2:string,3:bool}> */
	public static function statusProvider(): array {
		return array(
			'complete'            => array( 'complete', 'complete', AccessPolicy::PAID, true ),
			'partially_refunded'  => array( 'partially_refunded', 'partially_refunded', AccessPolicy::PAID, true ),
			'pending'             => array( 'pending', 'pending', AccessPolicy::PENDING, false ),
			'processing'          => array( 'processing', 'pending', AccessPolicy::PENDING, false ),
			'failed'              => array( 'failed', 'failed', AccessPolicy::FAILED, false ),
			'abandoned'           => array( 'abandoned', 'pending', AccessPolicy::FAILED, false ),
			'on_hold'             => array( 'on_hold', 'on_hold', AccessPolicy::DISPUTED, false ),
			'revoked'             => array( 'revoked', 'revoked', AccessPolicy::REVOKED, false ),
			'order refunded'      => array( 'refunded', 'complete', AccessPolicy::REFUNDED, false ),
			'неизвестный статус'  => array( 'weird', 'complete', AccessPolicy::FAILED, false ),
		);
	}

	#[DataProvider( 'statusProvider' )]
	public function test_order_status_mapping( string $orderStatus, string $itemStatus, string $expected, bool $access ): void {
		$st = $this->state( FakeOrderReader::paidOrder( $orderStatus, $itemStatus ) );

		$this->assertSame( $expected, $st['state'] );
		$this->assertSame( $access, $st['access'] );
	}

	public function test_item_status_refunded_blocks_access_even_if_order_complete(): void {
		$st = $this->state( FakeOrderReader::paidOrder( 'complete', 'refunded' ) );

		$this->assertSame( AccessPolicy::REFUNDED, $st['state'] );
		$this->assertFalse( $st['access'] );
	}

	public function test_item_on_hold_and_revoked_block_access(): void {
		$this->assertSame( AccessPolicy::DISPUTED, $this->state( FakeOrderReader::paidOrder( 'complete', 'on_hold' ) )['state'] );
		$this->assertSame( AccessPolicy::REVOKED, $this->state( FakeOrderReader::paidOrder( 'complete', 'revoked' ) )['state'] );
		$this->assertSame( AccessPolicy::FAILED, $this->state( FakeOrderReader::paidOrder( 'complete', 'failed' ) )['state'] );
	}

	public function test_two_partial_refunds_summing_to_full_are_refunded(): void {
		$reader                = FakeOrderReader::paidOrder( 'partially_refunded', 'partially_refunded' );
		$reader->refunds[100]  = 20.0; // 10 + 10 по двум записям возврата.
		$st                    = $this->state( $reader );

		$this->assertSame( AccessPolicy::REFUNDED, $st['state'] );
		$this->assertFalse( $st['access'] );
		$this->assertSame( 20.0, $st['refunded'] );
	}

	public function test_single_partial_refund_keeps_access(): void {
		$reader               = FakeOrderReader::paidOrder( 'partially_refunded', 'partially_refunded' );
		$reader->refunds[100] = 10.0;
		$st                   = $this->state( $reader );

		$this->assertSame( AccessPolicy::PAID, $st['state'] );
		$this->assertTrue( $st['access'] );
		$this->assertSame( 10.0, $st['refunded'] );
	}

	public function test_order_level_partial_refund_without_item_record_keeps_access(): void {
		$st = $this->state( FakeOrderReader::paidOrder( 'partially_refunded', 'complete' ) );

		$this->assertTrue( $st['access'] );
	}

	public function test_zero_total_item_is_paid_and_not_considered_refunded(): void {
		$reader                   = FakeOrderReader::paidOrder();
		$reader->items[100]['total'] = 0.0;
		$st                       = $this->state( $reader );

		$this->assertSame( AccessPolicy::PAID, $st['state'] );
		$this->assertTrue( $st['access'] );
	}

	public function test_zero_quantity_item_has_no_access(): void {
		$reader                      = FakeOrderReader::paidOrder();
		$reader->items[100]['quantity'] = 0;

		$this->assertFalse( $this->state( $reader )['access'] );
	}

	public function test_missing_order_or_item(): void {
		$policy = new AccessPolicy( new FakeOrderReader() );
		$st     = $policy->paymentState( FakeOrderReader::purchase() );

		$this->assertSame( AccessPolicy::MISSING, $st['state'] );
		$this->assertFalse( $st['access'] );

		$reader = FakeOrderReader::paidOrder();
		unset( $reader->items[100] );
		$this->assertSame( AccessPolicy::MISSING, $this->state( $reader )['state'] );
	}

	public function test_unavailable_edd_is_not_missing_and_gives_no_access(): void {
		$reader            = FakeOrderReader::paidOrder();
		$reader->available = false;
		$st                = $this->state( $reader );

		$this->assertSame( AccessPolicy::UNAVAILABLE, $st['state'] );
		$this->assertFalse( $st['access'] );
		$this->assertFalse( ( new AccessPolicy( $reader ) )->available() );

		$reader->available = true;
		$this->assertSame( AccessPolicy::PAID, $this->state( $reader )['state'] );
		$this->assertTrue( ( new AccessPolicy( $reader ) )->available() );

		// Пустой, но доступный источник — по-прежнему «заказа нет».
		$this->assertSame( AccessPolicy::MISSING, $this->state( new FakeOrderReader() )['state'] );
	}

	public function test_mismatch_cases(): void {
		$reader                         = FakeOrderReader::paidOrder();
		$reader->items[100]['product_id'] = 6;
		$this->assertSame( AccessPolicy::MISMATCH, $this->state( $reader )['state'], 'другой товар' );

		$reader                         = FakeOrderReader::paidOrder();
		$reader->items[100]['price_id'] = 3;
		$this->assertSame( AccessPolicy::MISMATCH, $this->state( $reader )['state'], 'другой вариант цены' );

		$reader                         = FakeOrderReader::paidOrder();
		$reader->items[100]['order_id'] = 11;
		$this->assertSame( AccessPolicy::MISMATCH, $this->state( $reader )['state'], 'позиция чужого заказа' );

		$reader                   = FakeOrderReader::paidOrder();
		$reader->orders[10]['type'] = 'refund';
		$st                       = $this->state( $reader );
		$this->assertSame( AccessPolicy::MISMATCH, $st['state'], 'заказ-возврат' );
		$this->assertFalse( $st['access'] );
	}

	public function test_purchase_without_price_requires_item_without_price(): void {
		$reader                         = FakeOrderReader::paidOrder();
		$reader->items[100]['price_id'] = null;
		$purchase                       = FakeOrderReader::purchase();
		$purchase['price_id']           = null;

		$this->assertTrue( ( new AccessPolicy( $reader ) )->paymentState( $purchase )['access'] );

		$reader->items[100]['price_id'] = 4;
		$this->assertSame( AccessPolicy::MISMATCH, ( new AccessPolicy( $reader ) )->paymentState( $purchase )['state'] );
	}

	// --- isOwner ---------------------------------------------------------

	/** @return array{session_id:int,user_id:int,grant_purchase_ids:list<int>} */
	private static function ctx( int $session = 0, int $user = 0, array $grants = array() ): array {
		return array( 'session_id' => $session, 'user_id' => $user, 'grant_purchase_ids' => $grants );
	}

	public function test_owner_by_same_session(): void {
		$snap = array( 'session_id' => 5, 'user_id' => 0 );

		$this->assertTrue( AccessPolicy::isOwner( $snap, null, self::ctx( 5 ) ) );
		$this->assertFalse( AccessPolicy::isOwner( $snap, null, self::ctx( 6 ) ) );
		$this->assertFalse( AccessPolicy::isOwner( $snap, null, self::ctx( 0 ) ), 'session_id 0 никогда не совпадает' );
	}

	public function test_user_id_zero_never_matches(): void {
		$snap     = array( 'session_id' => 5, 'user_id' => 0 );
		$purchase = array( 'id' => 1, 'user_id' => 0 );

		$this->assertFalse( AccessPolicy::isOwner( $snap, $purchase, self::ctx( 0, 0 ) ) );
		$this->assertFalse( AccessPolicy::isOwner( array( 'session_id' => 0, 'user_id' => 0 ), $purchase, self::ctx( 0, 0 ) ) );
	}

	public function test_owner_by_logged_in_user(): void {
		$snap = array( 'session_id' => 5, 'user_id' => 9 );

		$this->assertTrue( AccessPolicy::isOwner( $snap, null, self::ctx( 0, 9 ) ) );
		$this->assertFalse( AccessPolicy::isOwner( $snap, null, self::ctx( 0, 10 ) ) );
		$this->assertTrue( AccessPolicy::isOwner( array( 'session_id' => 5, 'user_id' => 0 ), array( 'id' => 1, 'user_id' => 9 ), self::ctx( 0, 9 ) ), 'владелец заказа' );
	}

	public function test_grant_opens_only_own_purchase(): void {
		$snap = array( 'session_id' => 5, 'user_id' => 0 );

		$this->assertTrue( AccessPolicy::isOwner( $snap, array( 'id' => 11, 'user_id' => 0 ), self::ctx( 0, 0, array( 11 ) ) ) );
		$this->assertFalse( AccessPolicy::isOwner( $snap, array( 'id' => 12, 'user_id' => 0 ), self::ctx( 0, 0, array( 11 ) ) ), 'чужая покупка' );
		$this->assertFalse( AccessPolicy::isOwner( $snap, null, self::ctx( 0, 0, array( 11 ) ) ), 'без покупки grant не даёт ничего' );
	}
}
