<?php
/**
 * Полный возврат покупки ИНСТРУКЦИИ, из которой выдан зачёт в консультацию.
 *
 * Состояние зачёта решает судьбу:
 *   released → void (свободный зачёт больше недоступен);
 *   reserved → void (бронь ещё не оплачена; CheckoutGuard отвечает credit_changed,
 *              посетитель увидит цену заново);
 *   used / locked → консультация уже оплачена со скидкой: она не меняется,
 *              у записи ставится credit_conflict = 1, событие и письмо
 *              admin_credit_conflict (seq = 1, чтобы не пересечься с письмом
 *              при оплате, seq = 0). Админ решает вручную.
 *
 * Идемпотентно: повторный вызов ничего не меняет и не шлёт второе письмо.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;

final class CreditRefundHandler {

	public function __construct(
		private BookingRepository $bookings,
		private CreditRepository $credits,
		private Notifier $notifier,
		private ?EventRepository $events = null
	) {
	}

	/**
	 * @return string Что сделано: 'void', 'conflict', 'none'.
	 */
	public function handle( int $instructionPurchaseId, int $orderId, int $now ): string {
		$ts = gmdate( 'Y-m-d H:i:s', $now );

		$this->credits->voidIfFree( $instructionPurchaseId, $ts );
		$this->credits->voidReserved( $instructionPurchaseId, $ts );

		$credit = $this->credits->byInstruction( $instructionPurchaseId );

		if ( null === $credit || ! in_array( (string) $credit['state'], array( 'used', 'locked' ), true ) ) {
			return null !== $credit && 'void' === (string) $credit['state'] ? 'void' : 'none';
		}

		$booking = $this->bookings->get( (int) $credit['booking_id'] );

		if ( null === $booking ) {
			return 'none';
		}

		if ( 0 === (int) $booking['credit_conflict'] ) {
			$this->bookings->update( (int) $booking['id'], array( 'credit_conflict' => 1 ) );
			$this->events?->log( 'credit_conflict_instruction_refund', (int) $booking['purchase_id'], $orderId, 'booking ' . (int) $booking['id'] . ', instruction ' . $instructionPurchaseId );
		}

		$this->notifier->queue( (int) $booking['id'], 'admin_credit_conflict', 1 );

		return 'conflict';
	}
}
