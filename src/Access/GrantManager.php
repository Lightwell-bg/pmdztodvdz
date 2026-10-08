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

use Bginfo\PmzhDvzh\Storage\BookingRepository;
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

	private ?BookingRepository $bookings = null;

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

	/** Подключает репозиторий записей (для grant kind = booking). */
	public function withBookings( BookingRepository $bookings ): void {
		$this->bookings = $bookings;
	}

	/**
	 * Токен управления записью для письма-подтверждения (kind = 'booking').
	 * Срок не короче 60 дней: ссылка должна пережить дату консультации.
	 * Открывает только эту запись (/consult/booking, /reschedule, /ics) и
	 * инструкцию её снимка.
	 *
	 * @param array<string,mixed> $purchase Покупка консультации (kind = consultation).
	 */
	public function issueBookingLink( array $purchase ): ?string {
		if ( 'consultation' !== (string) ( $purchase['kind'] ?? '' ) ) {
			return null;
		}

		return $this->grants->issue( $purchase, GrantRepository::KIND_BOOKING, max( 60, $this->ttlDays() ) );
	}

	/**
	 * Обмен токена ссылки на grant-cookie.
	 *
	 * @return string|null public_id снимка либо null (токен неверен/просрочен/отозван).
	 */
	public function exchange( string $rawToken ): ?string {
		$res = $this->exchangeFull( $rawToken, GrantRepository::KIND_LINK );

		return null === $res ? null : $res['id'];
	}

	/**
	 * Обмен токена на grant-cookie с указанием вида ('link' или 'booking').
	 *
	 * @return array{id:string,booking:?string}|null id — public_id снимка; booking — public_id записи (для kind = booking).
	 */
	public function exchangeFull( string $rawToken, string $kind = GrantRepository::KIND_LINK ): ?array {
		if ( ! in_array( $kind, array( GrantRepository::KIND_LINK, GrantRepository::KIND_BOOKING ), true ) ) {
			return null;
		}

		$link = $this->grants->findValid( trim( $rawToken ), $kind );

		if ( null === $link ) {
			return null;
		}

		$purchase = $this->purchases->byId( (int) $link['purchase_id'] );

		if ( null === $purchase ) {
			return null;
		}

		$bookingPublic = null;

		if ( GrantRepository::KIND_BOOKING === $kind ) {
			$booking = null === $this->bookings || 'consultation' !== (string) ( $purchase['kind'] ?? '' ) ? null : $this->bookings->get( (int) $purchase['booking_id'] );

			if ( null === $booking || (int) $booking['snapshot_id'] !== (int) $purchase['snapshot_id'] ) {
				return null;
			}

			$bookingPublic = (string) $booking['public_id'];
		}

		$snapshot = $this->snapshots->findById( (int) $purchase['snapshot_id'] );

		if ( null === $snapshot ) {
			return null;
		}

		$cookieToken = $this->grants->issue( $purchase, GrantRepository::KIND_COOKIE, $this->ttlDays() );

		if ( null === $cookieToken ) {
			return null;
		}

		// Ссылка управления записью многоразовая (срок — на дату консультации), ссылка на инструкцию — по токену.
		if ( GrantRepository::KIND_BOOKING !== $kind ) {
			$this->grants->markUsed( (int) $link['id'] );
		}

		$tokens   = $this->cookieTokens();
		$tokens[] = $cookieToken;
		$tokens   = array_slice( $tokens, -self::MAX_TOKENS );

		Cookies::set( self::COOKIE, implode( '.', $tokens ), time() + $this->ttlDays() * DAY_IN_SECONDS );
		$this->purchaseIds = null;

		return array( 'id' => (string) $snapshot['public_id'], 'booking' => $bookingPublic );
	}

	/**
	 * Открывает ли действующий grant-cookie запись: покупка консультации этой
	 * записи среди grant-покупок запроса.
	 *
	 * @param array<string,mixed> $booking
	 */
	public function opensBooking( array $booking ): bool {
		return (int) $booking['purchase_id'] > 0 && in_array( (int) $booking['purchase_id'], $this->currentPurchaseIds(), true );
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
