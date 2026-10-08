<?php
/**
 * Ссылки и grant-cookie для гостей: открывают только одну покупку.
 *
 * Письмо содержит ссылку с токеном `link` (во фрагменте адреса). Страница
 * обменивает его на `cookie`-grant и кладёт в cookie `bgdvzh_g`
 * (до 5 токенов через точку).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Access;

use Bginfo\PmzhDvzh\Storage\GrantRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Cookies;
use Bginfo\PmzhDvzh\Support\Settings;

final class GrantManager {

	public const COOKIE     = 'bgdvzh_g';
	public const MAX_TOKENS = 5;

	/** @var list<int>|null */
	private ?array $purchaseIds = null;

	public function __construct(
		private GrantRepository $grants,
		private PurchaseRepository $purchases,
		private SnapshotRepository $snapshots,
		private Settings $settings
	) {
	}

	private function ttlDays(): int {
		return max( 1, (int) $this->settings->get( 'link_ttl_days', 30 ) );
	}

	/**
	 * Выпускает токен ссылки для письма. Возвращает сырой токен.
	 *
	 * @param array<string,mixed> $purchase
	 */
	public function issueLink( array $purchase ): ?string {
		return $this->grants->issue( $purchase, GrantRepository::KIND_LINK, $this->ttlDays() );
	}

	/**
	 * Обмен токена ссылки на grant-cookie.
	 *
	 * @return string|null public_id снимка либо null (токен неверен/просрочен/отозван).
	 */
	public function exchange( string $rawToken ): ?string {
		$link = $this->grants->findValid( trim( $rawToken ), GrantRepository::KIND_LINK );

		if ( null === $link ) {
			return null;
		}

		$purchase = $this->purchases->byId( (int) $link['purchase_id'] );

		if ( null === $purchase ) {
			return null;
		}

		$snapshot = $this->snapshots->findById( (int) $purchase['snapshot_id'] );

		if ( null === $snapshot ) {
			return null;
		}

		$cookieToken = $this->grants->issue( $purchase, GrantRepository::KIND_COOKIE, $this->ttlDays() );

		if ( null === $cookieToken ) {
			return null;
		}

		$this->grants->markUsed( (int) $link['id'] );

		$tokens   = $this->cookieTokens();
		$tokens[] = $cookieToken;
		$tokens   = array_slice( $tokens, -self::MAX_TOKENS );

		Cookies::set( self::COOKIE, implode( '.', $tokens ), time() + $this->ttlDays() * DAY_IN_SECONDS );
		$this->purchaseIds = null;

		return (string) $snapshot['public_id'];
	}

	/**
	 * Покупки, открытые действующими grant-cookie запроса.
	 *
	 * @return list<int>
	 */
	public function currentPurchaseIds(): array {
		if ( null !== $this->purchaseIds ) {
			return $this->purchaseIds;
		}

		$ids = array();

		foreach ( $this->cookieTokens() as $token ) {
			$grant = $this->grants->findValid( $token, GrantRepository::KIND_COOKIE );

			if ( null !== $grant ) {
				$ids[] = (int) $grant['purchase_id'];
			}
		}

		$this->purchaseIds = array_values( array_unique( $ids ) );

		return $this->purchaseIds;
	}

	public function revokeForPurchase( int $purchaseId ): void {
		$this->grants->revokeForPurchase( $purchaseId );
		$this->purchaseIds = null;
	}

	/** @return list<string> */
	private function cookieTokens(): array {
		$raw = Cookies::get( self::COOKIE );

		if ( '' === $raw ) {
			return array();
		}

		$out = array();

		foreach ( explode( '.', $raw ) as $part ) {
			if ( Tokens::isWellFormed( $part ) && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}

		return array_slice( $out, -self::MAX_TOKENS );
	}
}
