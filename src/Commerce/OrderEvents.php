<?php
/**
 * События заказов EDD → пересчёт кэша покупок и запуск генерации PDF.
 *
 * Обработчики идемпотентны: каждый лишь пересчитывает состояние по живым
 * данным (AccessPolicy). Повторные и дублирующиеся вызовы хуков EDD
 * (например, edd_update_payment_status дважды) ничего не ломают.
 * Право доступа из кэша purchases.state никогда не берётся.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;

final class OrderEvents {

	public const GENERATE_HOOK = 'bgdvzh_generate';

	/** @var array<int,bool> Защита от повторного входа по одному заказу. */
	private array $busy = array();

	public function __construct(
		private OrderReader $reader,
		private AccessPolicy $policy,
		private PurchaseRepository $purchases,
		private DocumentRepository $documents,
		private EventRepository $events
	) {
	}

	public function register(): void {
		add_action( 'edd_transition_order_status', array( $this, 'onOrderStatus' ), 10, 3 );
		add_action( 'edd_transition_order_item_status', array( $this, 'onItemStatus' ), 10, 3 );
		add_action( 'edd_refund_order', array( $this, 'onRefund' ), 10, 3 );
		add_action( 'edd_complete_purchase', array( $this, 'onComplete' ), 10, 1 );
		add_action( 'edd_stripe_dispute_created', array( $this, 'onDispute' ), 10, 2 );
	}

	/** @param mixed $old @param mixed $new @param mixed $orderId */
	public function onOrderStatus( $old, $new, $orderId ): void {
		$this->syncOrder( (int) $orderId, 'status ' . (string) $old . '>' . (string) $new );
	}

	/** @param mixed $old @param mixed $new @param mixed $itemId */
	public function onItemStatus( $old, $new, $itemId ): void {
		$item = $this->reader->orderItem( (int) $itemId );

		if ( null !== $item ) {
			$this->syncOrder( $item['order_id'], 'item_status ' . (string) $old . '>' . (string) $new );
		}
	}

	/** @param mixed $orderId @param mixed $refundId @param mixed $all */
	public function onRefund( $orderId, $refundId = 0, $all = false ): void {
		$this->syncOrder( (int) $orderId, 'refund' );
	}

	/** @param mixed $orderId */
	public function onComplete( $orderId ): void {
		$this->syncOrder( (int) $orderId, 'complete' );
	}

	/** @param mixed $dispute @param mixed $orderId */
	public function onDispute( $dispute, $orderId = 0 ): void {
		$this->syncOrder( (int) $orderId, 'dispute' );
	}

	/** Пересчитывает все покупки заказа. */
	public function syncOrder( int $orderId, string $source = '' ): void {
		if ( $orderId <= 0 || isset( $this->busy[ $orderId ] ) ) {
			return;
		}

		$this->busy[ $orderId ] = true;

		try {
			foreach ( $this->purchases->byOrder( $orderId ) as $purchase ) {
				$this->syncPurchase( $purchase, $source );
			}
		} finally {
			unset( $this->busy[ $orderId ] );
		}
	}

	/**
	 * Пересчёт одной покупки по живому состоянию EDD.
	 *
	 * @param array<string,mixed> $purchase
	 * @return array{state:string,access:bool,refunded:float,item_total:float,order_status:string,item_status:string}
	 */
	public function syncPurchase( array $purchase, string $source = '' ): array {
		$st     = $this->policy->paymentState( $purchase );
		$reason = '' !== $st['item_status'] ? $st['item_status'] : $st['order_status'];

		// EDD недоступен — состояние неизвестно: кэш покупок не перезаписываем.
		if ( AccessPolicy::UNAVAILABLE === $st['state'] ) {
			return $st;
		}

		// Частичный возврат на уровне заказа без записи по позиции (Stripe).
		if ( $st['access'] && 'partially_refunded' === $st['order_status'] && $st['refunded'] <= 0.0 ) {
			$reason = 'partial_unattributed';

			if ( 'partial_unattributed' !== (string) $purchase['state_reason'] ) {
				$this->events->log( 'order_partial_refund_unattributed', (int) $purchase['id'], (int) $purchase['order_id'], 'Частичный возврат заказа без записи по позиции' );
			}
		}

		$changed = $this->purchases->updateCache( (int) $purchase['id'], $st['state'], $reason, $st['item_total'], $st['refunded'] );

		if ( $changed ) {
			$this->events->log( 'state_' . $st['state'], (int) $purchase['id'], (int) $purchase['order_id'], mb_substr( $source, 0, 120 ) );
		}

		if ( $st['access'] ) {
			$this->queueDocument( (int) $purchase['id'] );
		}

		return $st;
	}

	/** Строка документа pending и запасная отложенная задача. */
	public function queueDocument( int $purchaseId ): void {
		$this->documents->ensureRow( $purchaseId );

		if ( ! wp_next_scheduled( self::GENERATE_HOOK, array( $purchaseId ) ) ) {
			wp_schedule_single_event( time() + 5, self::GENERATE_HOOK, array( $purchaseId ) );
		}
	}
}
