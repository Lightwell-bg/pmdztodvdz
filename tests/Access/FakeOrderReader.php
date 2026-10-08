<?php
/**
 * Фейковый читатель заказов для тестов AccessPolicy.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Access;

use Bginfo\PmzhDvzh\Commerce\OrderReader;

final class FakeOrderReader implements OrderReader {

	/** @var array<int,array<string,mixed>> */
	public array $orders = array();

	/** @var array<int,array<string,mixed>> */
	public array $items = array();

	/** @var array<int,float> */
	public array $refunds = array();

	/** Доступен ли EDD (false — имитация отключённого плагина). */
	public bool $available = true;

	public function available(): bool {
		return $this->available;
	}

	/** Заказ #10 (sale, complete) с позицией #100 (товар 5, вариант 2, 20.00). */
	public static function paidOrder( string $orderStatus = 'complete', string $itemStatus = 'complete' ): self {
		$f                = new self();
		$f->orders[10]    = array( 'id' => 10, 'type' => 'sale', 'status' => $orderStatus, 'user_id' => 0, 'customer_id' => 1, 'email' => 'a@example.com', 'total' => 20.0 );
		$f->items[100]    = array( 'id' => 100, 'order_id' => 10, 'product_id' => 5, 'price_id' => 2, 'status' => $itemStatus, 'total' => 20.0, 'quantity' => 1, 'cart_index' => 0 );

		return $f;
	}

	/** @return array{order_id:int,order_item_id:int,download_id:int,price_id:?int} */
	public static function purchase(): array {
		return array( 'order_id' => 10, 'order_item_id' => 100, 'download_id' => 5, 'price_id' => 2 );
	}

	public function order( int $orderId ): ?array {
		return $this->orders[ $orderId ] ?? null;
	}

	public function orderItem( int $orderItemId ): ?array {
		return $this->items[ $orderItemId ] ?? null;
	}

	public function orderItems( int $orderId ): array {
		return array_values( array_filter( $this->items, static fn( array $i ): bool => $i['order_id'] === $orderId ) );
	}

	public function refundedTotalForItem( int $orderItemId ): float {
		return $this->refunds[ $orderItemId ] ?? 0.0;
	}

	/** @var array<int,string> Валюта по id заказа (по умолчанию EUR). */
	public array $currencies = array();

	/** @var array<int,array{subtotal:float,discount:float,tax:float,total:float}> */
	public array $amounts = array();

	/** @var array<int,list<array{subtotal:float,discount:float,total:float}>> */
	public array $refundItems = array();

	public function orderCurrency( int $orderId ): string {
		return $this->currencies[ $orderId ] ?? 'EUR';
	}

	public function itemAmounts( int $orderItemId ): ?array {
		if ( isset( $this->amounts[ $orderItemId ] ) ) {
			return $this->amounts[ $orderItemId ];
		}

		$i = $this->items[ $orderItemId ] ?? null;

		return null === $i ? null : array( 'subtotal' => (float) $i['total'], 'discount' => 0.0, 'tax' => 0.0, 'total' => (float) $i['total'] );
	}

	public function refundAmounts( int $orderItemId ): array {
		return $this->refundItems[ $orderItemId ] ?? array();
	}

	public function paidOrderStatuses(): array {
		return array( 'complete', 'partially_refunded' );
	}

	public function deliverableItemStatuses(): array {
		return array( 'complete', 'partially_refunded' );
	}
}
