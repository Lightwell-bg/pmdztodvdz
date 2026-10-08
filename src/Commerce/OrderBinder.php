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
use Bginfo\PmzhDvzh\Booking\BookingSync;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Clock;

final class OrderBinder {

	public function __construct(
		private EddGate $gate,
		private OrderReader $reader,
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private EventRepository $events,
		private OrderEvents $orderEvents,
		private ?ConsultGate $consult = null,
		private ?BookingRepository $bookings = null,
		private ?BookingSync $sync = null
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
		$orderId     = (int) $orderId;
		$download    = $this->gate->downloadId();
		$consultDl   = null === $this->consult ? 0 : $this->consult->downloadId();

		if ( $orderId <= 0 || ( $download <= 0 && $consultDl <= 0 ) || ! is_array( $orderData ) || empty( $orderData['cart_details'] ) || ! is_array( $orderData['cart_details'] ) ) {
			return;
		}

		$order = $this->reader->order( $orderId );

		if ( null === $order || 'sale' !== $order['type'] ) {
			return;
		}

		$items = $this->reader->orderItems( $orderId );

		foreach ( $orderData['cart_details'] as $index => $detail ) {
			if ( is_array( $detail ) && $consultDl > 0 && (int) ( $detail['id'] ?? 0 ) === $consultDl ) {
				$this->bindConsultation( $orderId, (int) $index, $detail, $order, $items, $consultDl );
				continue;
			}

			if ( ! is_array( $detail ) || $download <= 0 || (int) ( $detail['id'] ?? 0 ) !== $download ) {
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
	 * Привязка позиции консультации: покупка kind = 'consultation' плюс
	 * ссылка order_id/order_item_id/purchase_id в записи. Идемпотентна.
	 *
	 * @param array<string,mixed>       $detail
	 * @param array<string,mixed>       $order
	 * @param list<array<string,mixed>> $items
	 */
	private function bindConsultation( int $orderId, int $index, array $detail, array $order, array $items, int $consultDl ): void {
		$options = $detail['item_number']['options'] ?? array();
		$options = is_array( $options ) ? $options : array();
		$ref     = isset( $options[ CartService::OPTION_REF ] ) && is_string( $options[ CartService::OPTION_REF ] ) ? $options[ CartService::OPTION_REF ] : '';
		$public  = isset( $options[ ConsultPriceFilter::OPTION_BOOKING ] ) && is_string( $options[ ConsultPriceFilter::OPTION_BOOKING ] ) ? strtolower( $options[ ConsultPriceFilter::OPTION_BOOKING ] ) : '';
		$parsed  = '' === $ref ? null : Tokens::parseCartRef( $ref );
		$snap    = null === $parsed ? null : $this->snapshots->findByPublicId( $parsed[0] );
		$booking = null === $this->bookings || '' === $public ? null : $this->bookings->byPublicId( $public );

		if (
			null === $snap
			|| null === $booking
			|| (int) $booking['snapshot_id'] !== (int) $snap['id']
			|| ! Tokens::cartRefValid( $ref, (int) $snap['session_id'], CartService::refKey() )
		) {
			$this->events->log( 'unbound_item', 0, $orderId, 'consult cart_index ' . $index );

			return;
		}

		$item = $this->findItem( $items, $index, $consultDl );

		if ( null === $item ) {
			$this->events->log( 'item_not_found', 0, $orderId, 'consult cart_index ' . $index );

			return;
		}

		$purchase = $this->purchases->upsertForOrderSnapshot(
			$orderId,
			(int) $snap['id'],
			array(
				'order_item_id' => $item['id'],
				'download_id'   => $consultDl,
				'price_id'      => $item['price_id'],
				'user_id'       => $order['user_id'],
				'session_id'    => (int) $snap['session_id'],
				'item_total'    => $item['total'],
				'kind'          => 'consultation',
				'booking_id'    => (int) $booking['id'],
			)
		);

		if ( null === $purchase ) {
			$this->events->log( 'bind_failed', 0, $orderId, 'consult snapshot ' . (int) $snap['id'] );

			return;
		}

		$existingOrder = (int) $booking['order_id'];

		// Запись уже связана с другим живым заказом: новый заказ только фиксируем.
		if ( $existingOrder > 0 && $existingOrder !== $orderId && null !== $this->sync && $this->sync->orderAlive( $booking, Clock::timestamp() ) ) {
			$this->events->log( 'consult_second_order', (int) $purchase['id'], $orderId, 'booking ' . (int) $booking['id'] );

			return;
		}

		$this->bookings->update(
			(int) $booking['id'],
			array(
				'order_id'      => $orderId,
				'order_item_id' => (int) $item['id'],
				'purchase_id'   => (int) $purchase['id'],
			)
		);

		$this->events->log( 'bound_consultation', (int) $purchase['id'], $orderId, 'booking ' . (int) $booking['id'] );
		$this->orderEvents->syncPurchase( $purchase, 'bound' );
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
