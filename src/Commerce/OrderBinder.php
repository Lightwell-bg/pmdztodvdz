<?php
/**
 * Привязка заказа EDD к снимку анкеты в момент создания заказа
 * (`edd_built_order`). Идемпотентна: при возобновлении заказа EDD
 * вызывает хук повторно и пересоздаёт позиции с новыми id, поэтому
 * запись покупки обновляется по паре (order_id, snapshot_id).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;

final class OrderBinder {

	public function __construct(
		private EddGate $gate,
		private OrderReader $reader,
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private EventRepository $events,
		private OrderEvents $orderEvents
	) {
	}

	public function register(): void {
		add_action( 'edd_built_order', array( $this, 'bind' ), 10, 2 );
	}

	/**
	 * @param mixed $orderId
	 * @param mixed $orderData
	 */
	public function bind( $orderId, $orderData = array() ): void {
		$orderId  = (int) $orderId;
		$download = $this->gate->downloadId();

		if ( $orderId <= 0 || $download <= 0 || ! is_array( $orderData ) || empty( $orderData['cart_details'] ) || ! is_array( $orderData['cart_details'] ) ) {
			return;
		}

		$order = $this->reader->order( $orderId );

		if ( null === $order || 'sale' !== $order['type'] ) {
			return;
		}

		$items = $this->reader->orderItems( $orderId );

		foreach ( $orderData['cart_details'] as $index => $detail ) {
			if ( ! is_array( $detail ) || (int) ( $detail['id'] ?? 0 ) !== $download ) {
				continue;
			}

			$options = $detail['item_number']['options'] ?? array();
			$ref     = is_array( $options ) && isset( $options[ CartService::OPTION_REF ] ) && is_string( $options[ CartService::OPTION_REF ] )
				? $options[ CartService::OPTION_REF ]
				: '';

			$parsed   = '' === $ref ? null : Tokens::parseCartRef( $ref );
			$snapshot = null === $parsed ? null : $this->snapshots->findByPublicId( $parsed[0] );

			if ( null === $snapshot || ! Tokens::cartRefValid( $ref, (int) $snapshot['session_id'], CartService::refKey() ) ) {
				$this->events->log( 'unbound_item', 0, $orderId, 'cart_index ' . (int) $index );
				continue;
			}

			$item = $this->findItem( $items, (int) $index, $download );

			if ( null === $item ) {
				$this->events->log( 'item_not_found', 0, $orderId, 'cart_index ' . (int) $index );
				continue;
			}

			$purchase = $this->purchases->upsertForOrderSnapshot(
				$orderId,
				(int) $snapshot['id'],
				array(
					'order_item_id' => $item['id'],
					'download_id'   => $download,
					'price_id'      => $item['price_id'],
					'user_id'       => $order['user_id'],
					'session_id'    => (int) $snapshot['session_id'],
					'item_total'    => $item['total'],
				)
			);

			if ( null === $purchase ) {
				$this->events->log( 'bind_failed', 0, $orderId, 'snapshot ' . (int) $snapshot['id'] );
				continue;
			}

			$this->events->log( 'bound', (int) $purchase['id'], $orderId, 'order_item ' . $item['id'] );
			$this->orderEvents->syncPurchase( $purchase, 'bound' );
		}
	}

	/**
	 * Позиция заказа по cart_index, при несовпадении — единственная позиция нашего товара.
	 *
	 * @param list<array<string,mixed>> $items
	 * @return array<string,mixed>|null
	 */
	private function findItem( array $items, int $cartIndex, int $download ): ?array {
		$ours = array();

		foreach ( $items as $item ) {
			if ( (int) $item['product_id'] !== $download ) {
				continue;
			}

			if ( (int) $item['cart_index'] === $cartIndex ) {
				return $item;
			}

			$ours[] = $item;
		}

		return 1 === count( $ours ) ? $ours[0] : null;
	}
}
