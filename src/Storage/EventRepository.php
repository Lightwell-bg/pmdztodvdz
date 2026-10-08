<?php
/**
 * Журнал событий оплаты и доступа. Никаких ответов анкеты, токенов и
 * email в журнал не пишется.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Support\Clock;

final class EventRepository {

	public function log( string $event, int $purchaseId = 0, int $orderId = 0, string $detail = '' ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'events' ),
			array(
				'purchase_id' => max( 0, $purchaseId ),
				'order_id'    => max( 0, $orderId ),
				'event'       => substr( $event, 0, 48 ),
				'detail'      => mb_substr( $detail, 0, 255 ),
				'created_at'  => Clock::now(),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/** @return list<array<string,mixed>> */
	public function forPurchase( int $purchaseId, int $limit = 100 ): array {
		global $wpdb;

		$t    = Schema::table( 'events' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE purchase_id = %d ORDER BY id DESC LIMIT %d", $purchaseId, $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** @return list<array<string,mixed>> */
	public function forOrder( int $orderId, int $limit = 100 ): array {
		global $wpdb;

		$t    = Schema::table( 'events' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT %d", $orderId, $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** @return list<array<string,mixed>> */
	public function recent( int $limit = 100 ): array {
		global $wpdb;

		$t    = Schema::table( 'events' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** Журнал старше года не нужен. */
	public function purgeOlderThan( string $utcCutoff ): int {
		global $wpdb;

		$t = Schema::table( 'events' );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", $utcCutoff ) );
	}
}
