<?php
/**
 * Кто делает запрос: сессия анкеты, пользователь WordPress и покупки,
 * открытые grant-cookie. Используется с AccessPolicy::isOwner().
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Access;

final class RequestContext {

	/**
	 * @param list<int> $grantPurchaseIds
	 */
	public function __construct(
		public readonly int $sessionId,
		public readonly int $userId,
		public readonly array $grantPurchaseIds
	) {
	}

	/**
	 * Формат для AccessPolicy::isOwner().
	 *
	 * @return array{session_id:int,user_id:int,grant_purchase_ids:list<int>}
	 */
	public function toArray(): array {
		return array(
			'session_id'         => $this->sessionId,
			'user_id'            => $this->userId,
			'grant_purchase_ids' => $this->grantPurchaseIds,
		);
	}

	/** Есть ли личность владельца по сессии или пользователю (без grant). */
	public function hasOwnerIdentity(): bool {
		return $this->sessionId > 0 || $this->userId > 0;
	}
}
