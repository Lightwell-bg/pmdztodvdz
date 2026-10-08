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
use Bginfo\PmzhDvzh\Booking\BookingSync;
use Bginfo\PmzhDvzh\Booking\CreditRefundHandler;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\Schema;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;

final class OrderEvents {

	public const GENERATE_HOOK = 'bgdvzh_generate';

	/** @var array<int,bool> Защита от повторного входа по одному заказу. */
	private array $busy = array();

	private ?BookingSync $bookingSync = null;
	private ?BookingRepository $bookings = null;
	private ?CreditRepository $credits = null;
	private ?CreditRefundHandler $creditRefund = null;

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

		$this->syncConsultation( $purchase, $st );

		return $st;
	}

	/**
	 * Подключает сервисы консультаций (после создания BookingSync).
	 */
	public function withBooking( BookingSync $sync, BookingRepository $bookings, CreditRepository $credits, ?CreditRefundHandler $creditRefund = null ): void {
		$this->bookingSync  = $sync;
		$this->bookings     = $bookings;
		$this->credits      = $credits;
		$this->creditRefund = $creditRefund;
	}

	/**
	 * Консультация: приводит запись в соответствие с заказом (оплата, возврат,
	 * спор). Инструкция: полный возврат делает свободный зачёт недоступным.
	 *
	 * @param array<string,mixed>                   $purchase
	 * @param array{state:string,access:bool} $st
	 */
	private function syncConsultation( array $purchase, array $st ): void {
		if ( null === $this->bookingSync || null === $this->bookings || null === $this->credits || ! Schema::ready() ) {
			return;
		}

		$now = Clock::timestamp();

		if ( 'consultation' !== (string) ( $purchase['kind'] ?? 'instruction' ) ) {
			if ( in_array( $st['state'], array( AccessPolicy::REFUNDED, AccessPolicy::REVOKED ), true ) ) {
				if ( null !== $this->creditRefund ) {
					$this->creditRefund->handle( (int) $purchase['id'], (int) $purchase['order_id'], $now );
				} else {
					$this->credits->voidIfFree( (int) $purchase['id'], gmdate( 'Y-m-d H:i:s', $now ) );
				}
			}

			return;
		}

		$booking = (int) ( $purchase['booking_id'] ?? 0 ) > 0 ? $this->bookings->get( (int) $purchase['booking_id'] ) : null;

		if ( null === $booking ) {
			return;
		}

		// Запись привязана к другому заказу (повторная попытка оплаты): неоплаченный
		// второй заказ её не меняет; оплаченный забирает запись, если первый не оплачен.
		if ( (int) $booking['purchase_id'] > 0 && (int) $booking['purchase_id'] !== (int) $purchase['id'] ) {
			if ( AccessPolicy::PAID !== $st['state'] ) {
				return;
			}

			$old = $this->purchases->byId( (int) $booking['purchase_id'] );

			if ( null !== $old && $this->policy->paymentState( $old )['access'] ) {
				$this->events->log( 'consult_duplicate_payment', (int) $purchase['id'], (int) $purchase['order_id'], 'booking ' . (int) $booking['id'] );

				return;
			}

			$this->bookings->update(
				(int) $booking['id'],
				array(
					'order_id'      => (int) $purchase['order_id'],
					'order_item_id' => (int) $purchase['order_item_id'],
					'purchase_id'   => (int) $purchase['id'],
				)
			);
			$booking = $this->bookings->get( (int) $booking['id'] );

			if ( null === $booking ) {
				return;
			}
		}

		$this->bookingSync->sync( $booking, $now );
	}

	/** Строка документа pending и запасная отложенная задача. */
	public function queueDocument( int $purchaseId ): void {
		$this->documents->ensureRow( $purchaseId );

		if ( ! wp_next_scheduled( self::GENERATE_HOOK, array( $purchaseId ) ) ) {
			wp_schedule_single_event( time() + 5, self::GENERATE_HOOK, array( $purchaseId ) );
		}
	}
}
