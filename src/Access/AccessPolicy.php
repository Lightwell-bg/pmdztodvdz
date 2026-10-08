<?php
/**
 * Право доступа к оплаченной инструкции.
 *
 * Решение принимается на сервере при КАЖДОМ просмотре, генерации и
 * скачивании по живому состоянию заказа EDD (через OrderReader), а не по
 * кэшу, параметрам возврата из оплаты, email или номеру заказа.
 *
 * Две независимые проверки:
 *  1. paymentState() — оплачена ли именно наша позиция заказа
 *     (возвраты считаются по позиции и накопленной сумме, а не по статусу
 *     заказа partially_refunded);
 *  2. isOwner() — относится ли запрос к владельцу снимка: своя сессия,
 *     вошедший пользователь (user_id > 0) или grant конкретной покупки.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Access;

use Bginfo\PmzhDvzh\Commerce\OrderReader;

final class AccessPolicy {

	public const PAID       = 'paid';
	public const PENDING    = 'pending';
	public const FAILED     = 'failed';
	public const REFUNDED   = 'refunded';
	public const REVOKED    = 'revoked';
	public const DISPUTED   = 'disputed';
	public const MISMATCH   = 'mismatch';
	public const MISSING    = 'missing';
	/** EDD недоступен: состояние оплаты неизвестно, доступа нет, кэш не меняется. */
	public const UNAVAILABLE = 'unavailable';

	/** Допуск сравнения денежных сумм. */
	private const EPSILON = 0.005;

	public function __construct( private OrderReader $orders ) {
	}

	/** Доступен ли источник заказов (EDD загружен). */
	public function available(): bool {
		return $this->orders->available();
	}

	/**
	 * Состояние оплаты позиции, привязанной к снимку.
	 *
	 * @param array{order_id:int|string,order_item_id:int|string,download_id:int|string,price_id:int|string|null} $purchase
	 * @return array{state:string,access:bool,refunded:float,item_total:float,order_status:string,item_status:string}
	 */
	public function paymentState( array $purchase ): array {
		$out = array(
			'state'        => self::MISSING,
			'access'       => false,
			'refunded'     => 0.0,
			'item_total'   => 0.0,
			'order_status' => '',
			'item_status'  => '',
		);

		// EDD отключён или не загружен: состояние неизвестно, а не «заказа нет».
		if ( ! $this->orders->available() ) {
			$out['state'] = self::UNAVAILABLE;
			return $out;
		}

		$order = $this->orders->order( (int) $purchase['order_id'] );
		$item  = $this->orders->orderItem( (int) $purchase['order_item_id'] );

		if ( null === $order || null === $item ) {
			return $out;
		}

		$out['order_status'] = $order['status'];
		$out['item_status']  = $item['status'];
		$out['item_total']   = (float) $item['total'];

		// Позиция должна принадлежать именно этому заказу-продаже и нашему
		// товару/варианту — защита от подмены идентификаторов.
		$priceMatches = null === $purchase['price_id'] || '' === $purchase['price_id']
			? ( null === $item['price_id'] || 0 === (int) $item['price_id'] )
			: (int) $purchase['price_id'] === (int) $item['price_id'];

		if (
			'sale' !== $order['type']
			|| (int) $item['order_id'] !== (int) $order['id']
			|| (int) $item['product_id'] !== (int) $purchase['download_id']
			|| ! $priceMatches
		) {
			$out['state'] = self::MISMATCH;
			return $out;
		}

		$state = $this->stateFromOrderStatus( $order['status'] );

		if ( self::PAID === $state ) {
			$refunded        = abs( $this->orders->refundedTotalForItem( (int) $item['id'] ) );
			$out['refunded'] = $refunded;

			if ( 'refunded' === $item['status'] ) {
				$state = self::REFUNDED;
			} elseif ( 'revoked' === $item['status'] ) {
				$state = self::REVOKED;
			} elseif ( 'on_hold' === $item['status'] ) {
				$state = self::DISPUTED;
			} elseif ( ! in_array( $item['status'], $this->orders->deliverableItemStatuses(), true ) || (int) $item['quantity'] <= 0 ) {
				$state = self::FAILED;
			} elseif ( (float) $item['total'] > self::EPSILON && $refunded + self::EPSILON >= (float) $item['total'] ) {
				// Несколько частичных возвратов по позиции, вместе равных полному.
				$state = self::REFUNDED;
			}
			// Иначе: частичный возврат позиции (или частичный возврат заказа
			// без записи по позиции, как у Stripe) — доступ сохраняется.
		}

		$out['state']  = $state;
		$out['access'] = self::PAID === $state;

		return $out;
	}

	/**
	 * Статус заказа → наше состояние. Неизвестные статусы — без доступа.
	 */
	public function stateFromOrderStatus( string $status ): string {
		if ( in_array( $status, $this->orders->paidOrderStatuses(), true ) ) {
			return self::PAID;
		}

		return match ( $status ) {
			'pending', 'processing'                 => self::PENDING,
			'refunded'                              => self::REFUNDED,
			'revoked'                               => self::REVOKED,
			'on_hold'                               => self::DISPUTED,
			'failed', 'abandoned', 'cancelled'      => self::FAILED,
			default                                 => self::FAILED,
		};
	}

	/**
	 * Владелец снимка/покупки.
	 *
	 * @param array{session_id:int|string,user_id:int|string} $snapshot
	 * @param array{id:int|string,user_id:int|string}|null    $purchase
	 * @param array{session_id:int,user_id:int,grant_purchase_ids:list<int>} $ctx
	 */
	public static function isOwner( array $snapshot, ?array $purchase, array $ctx ): bool {
		if ( $ctx['session_id'] > 0 && (int) $snapshot['session_id'] === $ctx['session_id'] ) {
			return true;
		}

		$uid = $ctx['user_id'];

		if ( $uid > 0 ) {
			if ( (int) $snapshot['user_id'] === $uid ) {
				return true;
			}

			if ( null !== $purchase && (int) $purchase['user_id'] === $uid ) {
				return true;
			}
		}

		// Grant открывает только свою покупку, а не анкеты сессии.
		return null !== $purchase && in_array( (int) $purchase['id'], $ctx['grant_purchase_ids'], true );
	}
}
