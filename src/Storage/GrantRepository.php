<?php
/**
 * Grants: секретные ссылки (kind=link) и grant-cookie (kind=cookie),
 * каждый открывает ровно одну покупку. В БД только sha256 токена.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Support\Clock;

final class GrantRepository {

	public const KIND_LINK   = 'link';
	public const KIND_COOKIE = 'cookie';

	/**
	 * Выпускает grant. Возвращает сырой токен (в БД он не сохраняется).
	 *
	 * @param array<string,mixed> $purchase Строка purchases.
	 */
	public function issue( array $purchase, string $kind, int $ttlDays ): ?string {
		global $wpdb;

		$token = Tokens::random();

		$ok = $wpdb->insert(
			Schema::table( 'grants' ),
			array(
				'purchase_id'   => (int) $purchase['id'],
				'snapshot_id'   => (int) $purchase['snapshot_id'],
				'order_item_id' => (int) $purchase['order_item_id'],
				'kind'          => $kind,
				'token_hash'    => Tokens::hash( $token ),
				'created_at'    => Clock::now(),
				'expires_at'    => Clock::fromNow( max( 1, $ttlDays ) * DAY_IN_SECONDS ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? $token : null;
	}

	/**
	 * Действующий grant по сырому токену (не истёк, не отозван).
	 *
	 * @return array<string,mixed>|null
	 */
	public function findValid( string $token, ?string $kind = null ): ?array {
		global $wpdb;

		if ( ! Tokens::isWellFormed( $token ) ) {
			return null;
		}

		$t   = Schema::table( 'grants' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE token_hash = %s AND revoked_at IS NULL AND expires_at > %s",
				Tokens::hash( $token ),
				Clock::now()
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( null !== $kind && $kind !== $row['kind'] ) {
			return null;
		}

		return $row;
	}

	public function markUsed( int $id ): void {
		global $wpdb;

		$t = Schema::table( 'grants' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET used_at = %s WHERE id = %d AND used_at IS NULL", Clock::now(), $id ) );
	}

	public function revokeForPurchase( int $purchaseId ): void {
		global $wpdb;

		$t = Schema::table( 'grants' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET revoked_at = %s WHERE purchase_id = %d AND revoked_at IS NULL", Clock::now(), $purchaseId ) );
	}

	public function revokeForSnapshot( int $snapshotId ): void {
		global $wpdb;

		$t = Schema::table( 'grants' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET revoked_at = %s WHERE snapshot_id = %d AND revoked_at IS NULL", Clock::now(), $snapshotId ) );
	}

	/** @return int Число удалённых строк. */
	public function purgeExpired(): int {
		global $wpdb;

		$t = Schema::table( 'grants' );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE expires_at < %s", Clock::fromNow( -DAY_IN_SECONDS ) ) );
	}
}
