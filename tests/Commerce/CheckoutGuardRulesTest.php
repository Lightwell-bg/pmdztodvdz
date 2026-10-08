<?php
/**
 * Чистые правила CheckoutGuard для консультаций: состав корзины и проверка позиции.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Commerce;

use Bginfo\PmzhDvzh\Commerce\CheckoutGuard;
use PHPUnit\Framework\TestCase;

final class CheckoutGuardRulesTest extends TestCase {

	private const NOW = 1_780_000_000;

	/** @param array<string,mixed> $over */
	private function booking( array $over = array() ): array {
		return array_merge(
			array(
				'id'                 => 7,
				'snapshot_id'        => 3,
				'status'             => 'held',
				'hold_until'         => self::NOW + 600,
				'credit_purchase_id' => 0,
			),
			$over
		);
	}

	private function error( ?array $booking, ?array $credit = null, ?int $snap = 3, ?int $price = 2, ?int $expect = 2, bool $alive = false ): ?string {
		return CheckoutGuard::consultItemError( array( 'ref_snapshot_id' => $snap, 'price_id' => $price ), $booking, $credit, $expect, $alive, self::NOW );
	}

	public function test_valid_item_passes(): void {
		$this->assertNull( $this->error( $this->booking() ) );
	}

	public function test_without_plugin_context_is_rejected(): void {
		$this->assertSame( 'ref', $this->error( $this->booking(), null, null ) );
		$this->assertSame( 'booking', $this->error( null ) );
	}

	public function test_booking_of_other_snapshot_is_rejected(): void {
		$this->assertSame( 'snapshot', $this->error( $this->booking( array( 'snapshot_id' => 4 ) ) ) );
	}

	public function test_only_held_booking_can_be_paid(): void {
		foreach ( array( 'expired', 'confirmed', 'cancelled', 'refunded' ) as $status ) {
			$this->assertSame( 'booking_state', $this->error( $this->booking( array( 'status' => $status ) ) ), $status );
		}
	}

	public function test_expired_hold_is_rejected_unless_order_is_alive(): void {
		$expired = $this->booking( array( 'hold_until' => self::NOW - 1 ) );

		$this->assertSame( 'expired', $this->error( $expired ) );
		$this->assertNull( $this->error( $expired, null, 3, 2, 2, true ), 'по брони уже идёт оплата' );
	}

	public function test_wrong_price_id_is_rejected(): void {
		$this->assertSame( 'price', $this->error( $this->booking(), null, 3, 5, 2 ) );
		$this->assertSame( 'price', $this->error( $this->booking(), null, 3, null, 2 ) );
		$this->assertNull( $this->error( $this->booking(), null, 3, null, null ), 'товар без вариантов цены' );
	}

	public function test_credit_must_be_reserved_for_this_booking(): void {
		$b = $this->booking( array( 'credit_purchase_id' => 5 ) );

		$this->assertSame( 'credit_changed', $this->error( $b, null ) );
		$this->assertSame( 'credit_changed', $this->error( $b, array( 'state' => 'released', 'booking_id' => 7, 'instruction_purchase_id' => 5 ) ) );
		$this->assertSame( 'credit_changed', $this->error( $b, array( 'state' => 'reserved', 'booking_id' => 8, 'instruction_purchase_id' => 5 ) ) );
		$this->assertSame( 'credit_changed', $this->error( $b, array( 'state' => 'reserved', 'booking_id' => 7, 'instruction_purchase_id' => 6 ) ) );
		$this->assertNull( $this->error( $b, array( 'state' => 'reserved', 'booking_id' => 7, 'instruction_purchase_id' => 5 ) ) );
	}

	public function test_booking_without_credit_ignores_credit_row(): void {
		$this->assertNull( $this->error( $this->booking(), array( 'state' => 'used', 'booking_id' => 99, 'instruction_purchase_id' => 5 ) ) );
	}

	public function test_mixed_cart_rules(): void {
		$c = static fn( int $qty, ?string $snap ): array => array( 'qty' => $qty, 'snapshot_public' => $snap );
		$i = static fn( ?string $snap ): array => array( 'snapshot_public' => $snap );

		$this->assertNull( CheckoutGuard::cartConflict( array( $c( 1, 'aaa' ) ), array() ) );
		$this->assertSame( 'consult_multiple', CheckoutGuard::cartConflict( array( $c( 1, 'aaa' ), $c( 1, 'bbb' ) ), array() ) );
		$this->assertSame( 'consult_quantity', CheckoutGuard::cartConflict( array( $c( 2, 'aaa' ) ), array() ) );
		$this->assertSame( 'consult_with_instruction', CheckoutGuard::cartConflict( array( $c( 1, 'aaa' ) ), array( $i( 'aaa' ) ) ) );
		$this->assertNull( CheckoutGuard::cartConflict( array( $c( 1, 'aaa' ) ), array( $i( 'bbb' ) ) ), 'инструкция другой анкеты не мешает' );
		$this->assertNull( CheckoutGuard::cartConflict( array( $c( 1, null ) ), array( $i( null ) ) ), 'без ссылки снимка сравнивать нечего — отказ даст проверка ref' );
	}

	public function test_messages_exist_for_all_codes(): void {
		foreach ( array( 'ref', 'booking', 'snapshot', 'booking_state', 'expired', 'price', 'credit_changed', 'consult_multiple', 'consult_with_instruction' ) as $code ) {
			$this->assertNotSame( '', CheckoutGuard::message( $code ) );
		}
	}
}
