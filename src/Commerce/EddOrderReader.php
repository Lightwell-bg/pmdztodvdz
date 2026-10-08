<?php
/**
 * Чтение живого состояния заказов через публичное API EDD 3.x.
 * Все вызовы защищены: при отсутствии EDD возвращается «нет данных»,
 * что AccessPolicy трактует как отсутствие доступа.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

final class EddOrderReader implements OrderReader {

	public function available(): bool {
		return function_exists( 'edd_get_order' ) && function_exists( 'edd_get_order_item' ) && function_exists( 'edd_get_order_items' );
	}

	public function order( int $orderId ): ?array {
		if ( $orderId <= 0 || ! function_exists( 'edd_get_order' ) ) {
			return null;
		}

		$o = edd_get_order( $orderId );

		if ( ! is_object( $o ) || empty( $o->id ) ) {
			return null;
		}

		return array(
			'id'          => (int) $o->id,
			'type'        => (string) $o->type,
			'status'      => (string) $o->status,
			'user_id'     => (int) $o->user_id,
			'customer_id' => (int) $o->customer_id,
			'email'       => (string) $o->email,
			'total'       => (float) $o->total,
		);
	}

	public function orderItem( int $orderItemId ): ?array {
		if ( $orderItemId <= 0 || ! function_exists( 'edd_get_order_item' ) ) {
			return null;
		}

		$i = edd_get_order_item( $orderItemId );

		return is_object( $i ) && ! empty( $i->id ) ? self::mapItem( $i ) : null;
	}

	public function orderItems( int $orderId ): array {
		if ( $orderId <= 0 || ! function_exists( 'edd_get_order_items' ) ) {
			return array();
		}

		$items = edd_get_order_items( array( 'order_id' => $orderId, 'number' => 200 ) );
		$out   = array();

		foreach ( is_array( $items ) ? $items : array() as $i ) {
			if ( is_object( $i ) ) {
				$out[] = self::mapItem( $i );
			}
		}

		return $out;
	}

	public function refundedTotalForItem( int $orderItemId ): float {
		if ( $orderItemId <= 0 || ! function_exists( 'edd_get_order_items' ) ) {
			return 0.0;
		}

		$refunds = edd_get_order_items( array( 'parent' => $orderItemId, 'number' => 200 ) );
		$sum     = 0.0;

		foreach ( is_array( $refunds ) ? $refunds : array() as $r ) {
			if ( is_object( $r ) ) {
				$sum += abs( (float) $r->total );
			}
		}

		return $sum;
	}

	public function paidOrderStatuses(): array {
		return array( 'complete', 'partially_refunded' );
	}

	public function deliverableItemStatuses(): array {
		if ( function_exists( 'edd_get_deliverable_order_item_statuses' ) ) {
			$s = edd_get_deliverable_order_item_statuses();

			if ( is_array( $s ) && ! empty( $s ) ) {
				return array_values( array_map( 'strval', $s ) );
			}
		}

		return array( 'complete', 'partially_refunded' );
	}

	/**
	 * @return array{id:int,order_id:int,product_id:int,price_id:?int,status:string,total:float,quantity:int,cart_index:int}
	 */
	private static function mapItem( object $i ): array {
		return array(
			'id'         => (int) $i->id,
			'order_id'   => (int) $i->order_id,
			'product_id' => (int) $i->product_id,
			'price_id'   => null === $i->price_id || '' === $i->price_id ? null : (int) $i->price_id,
			'status'     => (string) $i->status,
			'total'      => (float) $i->total,
			'quantity'   => (int) $i->quantity,
			'cart_index' => (int) $i->cart_index,
		);
	}
}
