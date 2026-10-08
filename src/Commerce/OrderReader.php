<?php
/**
 * Чтение живого состояния заказов EDD. Реализация — EddOrderReader
 * (публичное API EDD 3.x); в юнит-тестах — фейк.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

interface OrderReader {

	/**
	 * Доступен ли источник заказов (EDD загружен). Недоступность — не то же
	 * самое, что «заказ не найден»: состояние в этом случае неизвестно.
	 */
	public function available(): bool;

	/**
	 * @return array{id:int,type:string,status:string,user_id:int,customer_id:int,email:string,total:float}|null
	 */
	public function order( int $orderId ): ?array;

	/**
	 * @return array{id:int,order_id:int,product_id:int,price_id:?int,status:string,total:float,quantity:int,cart_index:int}|null
	 */
	public function orderItem( int $orderItemId ): ?array;

	/**
	 * @return list<array{id:int,order_id:int,product_id:int,price_id:?int,status:string,total:float,quantity:int,cart_index:int}>
	 */
	public function orderItems( int $orderId ): array;

	/** Сумма всех возвратов по исходной позиции (положительное число). */
	public function refundedTotalForItem( int $orderItemId ): float;

	/** @return list<string> Статусы заказа, при которых заказ считается оплаченным. */
	public function paidOrderStatuses(): array;

	/**
	 * @return list<string> Статусы позиции, при которых её можно выдавать
	 *         (edd_get_deliverable_order_item_statuses(): complete, partially_refunded).
	 */
	public function deliverableItemStatuses(): array;
}
