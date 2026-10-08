<?php
/**
 * Тесты расчёта зачёта: режимы с налогом и без, скидки, возвраты, округление.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Commerce;

use Bginfo\PmzhDvzh\Commerce\CreditCalculator;
use PHPUnit\Framework\TestCase;

final class CreditCalculatorTest extends TestCase {

	public function test_incl_uses_item_total(): void {
		$item = array( 'subtotal' => 8.33, 'discount' => 0, 'total' => 9.99 );

		$this->assertEqualsWithDelta( 9.99, CreditCalculator::amount( $item, array(), 'incl' ), 0.0001 );
	}

	public function test_incl_discount_is_already_in_total(): void {
		$item = array( 'subtotal' => 10.00, 'discount' => 1.00, 'total' => 9.00 );

		$this->assertEqualsWithDelta( 9.00, CreditCalculator::amount( $item, array(), 'incl' ), 0.0001 );
	}

	public function test_excl_subtracts_discount_from_subtotal(): void {
		$item = array( 'subtotal' => 10.00, 'discount' => 1.00, 'total' => 10.80 );

		$this->assertEqualsWithDelta( 9.00, CreditCalculator::amount( $item, array(), 'excl' ), 0.0001, 'налог в зачёт не входит' );
	}

	public function test_incl_partial_refund(): void {
		$item = array( 'subtotal' => 9.99, 'discount' => 0, 'total' => 9.99 );

		$this->assertEqualsWithDelta( 6.99, CreditCalculator::amount( $item, array( array( 'total' => -3.00 ) ), 'incl' ), 0.0001, 'знак возврата не важен' );
		$this->assertEqualsWithDelta( 6.99, CreditCalculator::amount( $item, array( array( 'total' => 3.00 ) ), 'incl' ), 0.0001 );
	}

	public function test_incl_multiple_refunds(): void {
		$item    = array( 'subtotal' => 9.99, 'discount' => 0, 'total' => 9.99 );
		$refunds = array( array( 'total' => 3.00 ), array( 'total' => 2.50 ) );

		$this->assertEqualsWithDelta( 4.49, CreditCalculator::amount( $item, $refunds, 'incl' ), 0.0001 );
	}

	public function test_excl_refund_uses_subtotal_minus_discount(): void {
		$item    = array( 'subtotal' => 10.00, 'discount' => 1.00, 'total' => 10.80 );
		$refunds = array( array( 'subtotal' => -4.00, 'discount' => -0.40, 'total' => -4.32 ) );

		$this->assertEqualsWithDelta( 5.40, CreditCalculator::amount( $item, $refunds, 'excl' ), 0.0001 );
	}

	public function test_full_refund_gives_zero_and_never_negative(): void {
		$item = array( 'subtotal' => 9.99, 'discount' => 0, 'total' => 9.99 );

		$this->assertSame( 0.0, CreditCalculator::amount( $item, array( array( 'total' => 9.99 ) ), 'incl' ) );
		$this->assertSame( 0.0, CreditCalculator::amount( $item, array( array( 'total' => 20.00 ) ), 'incl' ) );
		$this->assertSame( 0.0, CreditCalculator::amount( $item, array( array( 'subtotal' => 12.00, 'discount' => 0 ) ), 'excl' ) );
	}

	public function test_float_noise_does_not_leak(): void {
		$item = array( 'total' => 0.3 );

		$this->assertSame( 0.2, CreditCalculator::amount( $item, array( array( 'total' => 0.1 ) ), 'incl' ) );
	}

	public function test_rounding_to_two_places(): void {
		$this->assertSame( 10.0, CreditCalculator::amount( array( 'total' => 9.995 ), array(), 'incl' ) );
		$this->assertSame( 9.99, CreditCalculator::amount( array( 'total' => 9.9949 ), array(), 'incl' ) );
		$this->assertSame( '9.99', CreditCalculator::format( 9.994 ) );
	}

	public function test_applicability_is_strict_on_both_sides(): void {
		$this->assertTrue( CreditCalculator::isApplicable( 9.99, 49.0 ) );
		$this->assertFalse( CreditCalculator::isApplicable( 0.0, 49.0 ), 'нулевой зачёт' );
		$this->assertFalse( CreditCalculator::isApplicable( 0.004, 49.0 ), 'меньше цента' );
		$this->assertFalse( CreditCalculator::isApplicable( 49.0, 49.0 ), 'зачёт равен цене' );
		$this->assertFalse( CreditCalculator::isApplicable( 60.0, 49.0 ), 'зачёт больше цены' );
	}

	public function test_item_price_has_floor(): void {
		$this->assertSame( 39.01, CreditCalculator::itemPrice( 49.0, 9.99 ) );
		$this->assertSame( 0.01, CreditCalculator::itemPrice( 49.0, 48.995 ) );
		$this->assertSame( 0.01, CreditCalculator::itemPrice( 10.0, 20.0 ) );
	}
}
