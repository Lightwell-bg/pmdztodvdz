<?php
/**
 * Привязка снимка к позиции заказа EDD. Таблица — кэш для админки и
 * запуска генерации; право доступа считает AccessPolicy по живому EDD.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Support\Clock;

final class PurchaseRepository {

	/** @return array<string,mixed>|null */
	public function byId( int $id ): ?array {
		global $wpdb;

		$t   = Schema::table( 'purchases' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function byOrderSnapshot( int $orderId, int $snapshotId ): ?array {
		global $wpdb;

		$t   = Schema::table( 'purchases' );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d AND snapshot_id = %d", $orderId, $snapshotId ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** @return list<array<string,mixed>> */
	public function byOrder( int $orderId ): array {
		global $wpdb;

		$t    = Schema::table( 'purchases' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id ASC", $orderId ), ARRAY_A );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** @return list<array<string,mixed>> */
	public function bySnapshot( int $snapshotId ): array {
		global $wpdb;

		$t    = Schema::table( 'purchases' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE snapshot_id = %d ORDER BY id ASC", $snapshotId ), ARRAY_A );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** @return array<string,mixed>|null */
	public function byOrderItem( int $orderItemId ): ?array {
		global $wpdb;

		$t   = Schema::table( 'purchases' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_item_id = %d", $orderItemId ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Покупки в данном кэш-состоянии (для сверки в cron).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listByState( string $state, int $limit = 100 ): array {
		global $wpdb;

		$t    = Schema::table( 'purchases' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE state = %s ORDER BY id ASC LIMIT %d", $state, $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Постраничный список для админки.
	 *
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function paginate( int $page, int $perPage = 20 ): array {
		global $wpdb;

		$t       = Schema::table( 'purchases' );
		$perPage = max( 1, min( 100, $perPage ) );
		$offset  = max( 0, ( $page - 1 ) * $perPage );
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		$rows    = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", $perPage, $offset ),
			ARRAY_A
		);

		return array( 'items' => is_array( $rows ) ? array_values( $rows ) : array(), 'total' => $total );
	}

	/**
	 * Идемпотентная привязка: ключ — пара (order_id, snapshot_id).
	 * При повторном edd_built_order позиции заказа получают новые id —
	 * тогда обновляется order_item_id.
	 *
	 * @param array{order_item_id:int,download_id:int,price_id:?int,user_id:int,session_id:int,item_total:float} $data
	 * @return array<string,mixed>|null
	 */
	public function upsertForOrderSnapshot( int $orderId, int $snapshotId, array $data ): ?array {
		global $wpdb;

		$t   = Schema::table( 'purchases' );
		$now = Clock::now();

		$existing = $this->byOrderSnapshot( $orderId, $snapshotId );

		if ( null === $existing ) {
			// Обычный INSERT: любую ошибку, кроме дубля ключа, нужно увидеть.
			$wpdb->suppress_errors( true );
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$t} (snapshot_id, order_id, order_item_id, download_id, price_id, user_id, session_id, state, item_total, created_at, updated_at) VALUES (%d, %d, %d, %d, " . ( null === $data['price_id'] ? 'NULL' : '%d' ) . ", %d, %d, 'pending', %s, %s, %s)",
					...array_merge(
						array( $snapshotId, $orderId, (int) $data['order_item_id'], (int) $data['download_id'] ),
						null === $data['price_id'] ? array() : array( (int) $data['price_id'] ),
						array( (int) $data['user_id'], (int) $data['session_id'], number_format( (float) $data['item_total'], 9, '.', '' ), $now, $now )
					)
				)
			);
			$insertError = (string) $wpdb->last_error;
			$wpdb->suppress_errors( false );

			if ( 1 === (int) $inserted ) {
				return $this->byOrderSnapshot( $orderId, $snapshotId );
			}

			// Гонка (дубль любого из уникальных ключей): строку нашей пары перечитываем и обновляем.
			$existing = $this->byOrderSnapshot( $orderId, $snapshotId );

			if ( null === $existing ) {
				( new EventRepository() )->log( 'bind_failed', 0, $orderId, 'insert: ' . $insertError );

				return null;
			}
		}

		$wpdb->suppress_errors( true );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET order_item_id = %d, download_id = %d, price_id = " . ( null === $data['price_id'] ? 'NULL' : '%d' ) . ", user_id = %d, session_id = %d, item_total = %s, updated_at = %s WHERE order_id = %d AND snapshot_id = %d",
				...array_merge(
					array( (int) $data['order_item_id'], (int) $data['download_id'] ),
					null === $data['price_id'] ? array() : array( (int) $data['price_id'] ),
					array( (int) $data['user_id'], (int) $data['session_id'], number_format( (float) $data['item_total'], 9, '.', '' ), $now, $orderId, $snapshotId )
				)
			)
		);
		$updateError = (string) $wpdb->last_error;
		$wpdb->suppress_errors( false );

		if ( false === $updated ) {
			( new EventRepository() )->log( 'bind_failed', (int) $existing['id'], $orderId, 'update: ' . $updateError );

			return null;
		}

		return $this->byId( (int) $existing['id'] );
	}

	/**
	 * Обновляет кэш состояния. paid_at выставляется при первом paid.
	 *
	 * @return bool true, если состояние изменилось (для журнала).
	 */
	public function updateCache( int $id, string $state, string $reason, float $itemTotal, float $refundedTotal ): bool {
		global $wpdb;

		$t   = Schema::table( 'purchases' );
		$now = Clock::now();

		$before = $this->byId( $id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET state = %s, state_reason = %s, item_total = %s, refunded_total = %s, updated_at = %s, paid_at = IF(%s = 'paid' AND paid_at IS NULL, %s, paid_at) WHERE id = %d",
				substr( $state, 0, 20 ),
				substr( $reason, 0, 64 ),
				number_format( $itemTotal, 9, '.', '' ),
				number_format( $refundedTotal, 9, '.', '' ),
				$now,
				$state,
				$now,
				$id
			)
		);

		return null === $before || (string) $before['state'] !== $state;
	}

	public function markReceiptLinkSent( int $id ): void {
		global $wpdb;

		$wpdb->update( Schema::table( 'purchases' ), array( 'receipt_link_sent' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}
}
