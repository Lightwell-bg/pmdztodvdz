<?php
/**
 * Зачёт оплаченной инструкции в консультацию: проверка условий по живому EDD.
 *
 * Условия (docs/CONSULTATIONS.md §4):
 *  - покупка инструкции ЭТОГО снимка, живо оплачена (kind = 'instruction');
 *  - владелец подтверждён сессией или пользователем (> 0), не grant — это
 *    проверяет вызывающий и передаёт $ownerConfirmed;
 *  - валюта заказа инструкции совпадает с валютой EDD;
 *  - 0 < зачёт < цены консультации;
 *  - зачёт свободен: нет строки, она released либо reserved за ОДНОЙ ИЗ
 *    своих броней (повторный клик) или за чужой брони с истёкшим резервом
 *    без живого заказа (такой держатель освобождается перед резервом).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Commerce\CreditCalculator;
use Bginfo\PmzhDvzh\Commerce\OrderReader;
use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;

final class CreditService {

	public function __construct(
		private OrderReader $reader,
		private AccessPolicy $policy,
		private PurchaseRepository $purchases,
		private CreditRepository $credits,
		private BookingRepository $bookings,
		private BookingService $service,
		private BookingSync $sync
	) {
	}

	/**
	 * Подбирает зачёт для новой брони.
	 *
	 * @param int    $snapshotId     id снимка (строка snapshots).
	 * @param bool   $ownerConfirmed владелец по сессии/пользователю (не grant).
	 * @param string $currency       валюта EDD.
	 * @param string $taxMode        'incl'|'excl'.
	 * @param float  $consultPrice   цена консультации формата из EDD.
	 * @param int    $ownBookingId   id собственного активного резерва (его зачёт считается свободным), 0 — нет.
	 * @return array{eligible:bool,reason:string,instruction_purchase_id:int,amount:string,item:string}
	 */
	public function eligibility( int $snapshotId, bool $ownerConfirmed, string $currency, string $taxMode, float $consultPrice, int $ownBookingId, int $now ): array {
		$none = array( 'eligible' => false, 'reason' => '', 'instruction_purchase_id' => 0, 'amount' => '0.00', 'item' => CreditCalculator::format( $consultPrice ) );

		if ( ! $ownerConfirmed ) {
			$none['reason'] = 'owner';

			return $none;
		}

		$best = null;

		foreach ( $this->purchases->bySnapshot( $snapshotId ) as $p ) {
			if ( 'instruction' !== (string) ( $p['kind'] ?? 'instruction' ) ) {
				continue;
			}

			$st = $this->policy->paymentState( $p );

			if ( ! $st['access'] ) {
				continue;
			}

			if ( '' === $currency || $this->reader->orderCurrency( (int) $p['order_id'] ) !== strtoupper( $currency ) ) {
				$none['reason'] = 'currency';
				continue;
			}

			$item = $this->reader->itemAmounts( (int) $p['order_item_id'] );

			if ( null === $item ) {
				continue;
			}

			$credit = CreditCalculator::amount( $item, $this->reader->refundAmounts( (int) $p['order_item_id'] ), $taxMode );

			if ( ! CreditCalculator::isApplicable( $credit, $consultPrice ) ) {
				$none['reason'] = $credit <= 0 ? 'zero' : 'too_large';
				continue;
			}

			if ( ! $this->isFree( (int) $p['id'], $ownBookingId, $now ) ) {
				$none['reason'] = 'busy';
				continue;
			}

			if ( null === $best || $credit > $best['credit'] ) {
				$best = array( 'purchase' => (int) $p['id'], 'credit' => $credit );
			}
		}

		if ( null === $best ) {
			return $none;
		}

		return array(
			'eligible'                => true,
			'reason'                  => '',
			'instruction_purchase_id' => $best['purchase'],
			'amount'                  => CreditCalculator::format( $best['credit'] ),
			'item'                    => CreditCalculator::format( CreditCalculator::itemPrice( $consultPrice, $best['credit'] ) ),
		);
	}

	/**
	 * Зачёт по конкретной покупке инструкции по живому EDD (оплата и возвраты
	 * на текущий момент) либо null, если инструкция не оплачена или зачёт не применим.
	 */
	public function liveAmount( int $instructionPurchaseId, string $taxMode, float $consultPrice ): ?string {
		$p = $this->purchases->byId( $instructionPurchaseId );

		if ( null === $p || ! $this->policy->paymentState( $p )['access'] ) {
			return null;
		}

		$item = $this->reader->itemAmounts( (int) $p['order_item_id'] );

		if ( null === $item ) {
			return null;
		}

		$credit = CreditCalculator::amount( $item, $this->reader->refundAmounts( (int) $p['order_item_id'] ), $taxMode );

		return CreditCalculator::isApplicable( $credit, $consultPrice ) ? CreditCalculator::format( $credit ) : null;
	}

	/**
	 * Свободен ли зачёт покупки. Попутно освобождает истёкший резерв чужой
	 * брони без живого заказа (по живому состоянию EDD).
	 */
	public function isFree( int $instructionPurchaseId, int $ownBookingId, int $now ): bool {
		$row = $this->credits->byInstruction( $instructionPurchaseId );

		if ( null === $row ) {
			return true;
		}

		if ( 'reserved' === $row['state'] && (int) $row['booking_id'] !== $ownBookingId ) {
			$holder = $this->bookings->get( (int) $row['booking_id'] );

			if ( null !== $holder ) {
				$this->service->releaseCreditIfStale( $holder, $this->sync->orderAlive( $holder, $now ), $now );
			} else {
				// Запись-держатель исчезла: резерв осиротел.
				$this->credits->releaseReserved( (int) $row['booking_id'], gmdate( 'Y-m-d H:i:s', $now ) );
			}

			$row = $this->credits->byInstruction( $instructionPurchaseId );
		}

		if ( null === $row || 'released' === $row['state'] ) {
			return true;
		}

		return 'reserved' === $row['state'] && (int) $row['booking_id'] === $ownBookingId;
	}
}
