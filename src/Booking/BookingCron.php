<?php
/**
 * Пятиминутный проход по записям на консультацию.
 *
 * Корректность не зависит от этого прохода (подтверждение идёт из хуков
 * заказа и из GET /consult/booking); он лишь добивает пропущенное:
 *  1. резервы с истёкшим сроком — освобождает время и зачёт, если по ним
 *     нет живого заказа (по живому EDD);
 *  2. записи с заказом, но без подтверждения оплаты, — сверка с EDD;
 *  3. зачёты с истёкшим резервом у брони без живого заказа — освобождает;
 *  4. очистка истёкших единиц времени и мест;
 *  5. повтор очереди писем (если подключён отправитель);
 *  6. действие `bgdvzh_consult_tick` ($now) — точка расширения (напоминания и т.п.).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\SlotRepository;

final class BookingCron {

	public const HOOK_TICK = 'bgdvzh_consult_tick';

	public function __construct(
		private BookingRepository $bookings,
		private SlotRepository $slots,
		private CreditRepository $credits,
		private BookingSync $sync,
		private BookingService $service,
		private Notifier $notifier
	) {
	}

	/**
	 * @return array{expired_checked:int,resynced:int,credits_checked:int}
	 */
	public function tick( int $now ): array {
		$stats = array( 'expired_checked' => 0, 'resynced' => 0, 'credits_checked' => 0 );

		foreach ( $this->bookings->staleHeld( $now, 100 ) as $b ) {
			$this->sync->sync( $b, $now );
			++$stats['expired_checked'];
		}

		foreach ( $this->bookings->awaitingPayment( 100 ) as $b ) {
			$this->sync->sync( $b, $now );
			++$stats['resynced'];
		}

		foreach ( $this->credits->expiredReservations( $now, 50 ) as $row ) {
			$holder = $this->bookings->get( (int) $row['booking_id'] );

			if ( null === $holder ) {
				$this->credits->releaseReserved( (int) $row['booking_id'], gmdate( 'Y-m-d H:i:s', $now ) );
			} else {
				$this->service->releaseCreditIfStale( $holder, $this->sync->orderAlive( $holder, $now ), $now );
			}

			++$stats['credits_checked'];
		}

		$this->slots->purgeAllExpired( $now );
		$this->notifier->retryPending( 20 );

		if ( function_exists( 'do_action' ) ) {
			do_action( self::HOOK_TICK, $now );
		}

		return $stats;
	}
}
