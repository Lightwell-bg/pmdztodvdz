<?php
/**
 * Синхронизация записи на консультацию с живым состоянием заказа EDD.
 *
 * Вызывается из OrderEvents (хуки EDD), из GET /consult/booking и из cron,
 * поэтому подтверждение записи не зависит ни от одного из этих путей.
 * Все переходы условные (BookingService), повторные вызовы безопасны.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Commerce\OrderReader;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;

final class BookingSync {

	/** Статусы заказа, при которых оплата идёт или состоялась. */
	private const ALIVE = array( 'pending', 'processing', 'complete', 'partially_refunded', 'on_hold' );

	public function __construct(
		private OrderReader $reader,
		private AccessPolicy $policy,
		private PurchaseRepository $purchases,
		private BookingRepository $bookings,
		private BookingService $service
	) {
	}

	/**
	 * Есть ли у записи живой заказ (по живому EDD). Если EDD недоступен —
	 * состояние неизвестно, резерв и зачёт не освобождаем.
	 *
	 * @param array<string,mixed> $booking
	 */
	public function orderAlive( array $booking, int $now ): bool {
		$orderId = (int) $booking['order_id'];

		if ( $orderId <= 0 ) {
			return false;
		}

		if ( ! $this->reader->available() ) {
			return true;
		}

		$order = $this->reader->order( $orderId );

		if ( null === $order ) {
			return false;
		}

		// Заказ pending тоже живой, пока EDD сам не пометит его брошенным:
		// оплата могла начаться и пройти позже конца резерва.
		return in_array( $order['status'], self::ALIVE, true );
	}

	/**
	 * Приводит запись в соответствие с заказом и возвращает свежую строку.
	 *
	 * @param array<string,mixed> $booking
	 * @return array<string,mixed>|null
	 */
	public function sync( array $booking, int $now ): ?array {
		$id       = (int) $booking['id'];
		$purchase = (int) $booking['purchase_id'] > 0 ? $this->purchases->byId( (int) $booking['purchase_id'] ) : null;

		if ( null === $purchase ) {
			$this->service->expireIfStale( $booking, $this->orderAlive( $booking, $now ), $now );

			return $this->bookings->get( $id );
		}

		$st = $this->policy->paymentState( $purchase );

		switch ( $st['state'] ) {
			case AccessPolicy::UNAVAILABLE:
				break;
			case AccessPolicy::PAID:
				$this->service->onPaid( $booking, $now );
				break;
			case AccessPolicy::REFUNDED:
				$this->service->onFullRefund( $booking, $this->instructionStillValid( $booking ), $now );
				break;
			case AccessPolicy::DISPUTED:
				$this->service->onDispute( $booking, $now );
				break;
			default:
				$this->service->expireIfStale( $booking, $this->orderAlive( $booking, $now ), $now );
		}

		return $this->bookings->get( $id );
	}

	/** Живое состояние покупки инструкции, давшей зачёт: оплачена и не возвращена. */
	public function instructionStillValid( array $booking ): bool {
		$pid = (int) $booking['credit_purchase_id'];

		if ( $pid <= 0 ) {
			return false;
		}

		$purchase = $this->purchases->byId( $pid );

		return null !== $purchase && $this->policy->paymentState( $purchase )['access'];
	}
}
