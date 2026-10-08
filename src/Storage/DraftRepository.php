<?php
/**
 * Черновик анкеты: одна изменяемая строка на сессию с оптимистической
 * блокировкой по revision (две вкладки не затирают друг друга молча).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Json;

final class DraftRepository {

	/**
	 * @return array{data:array<string,mixed>,step:int,revision:int,updated_at:string}|null
	 */
	public function get( int $sessionId ): ?array {
		global $wpdb;

		$t   = Schema::table( 'drafts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE session_id = %d", $sessionId ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'data'       => Json::decode( (string) $row['data'] ),
			'step'       => (int) $row['step'],
			'revision'   => (int) $row['revision'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/**
	 * Сохраняет черновик, если revision клиента совпадает с текущей.
	 *
	 * @param array<string,mixed> $data
	 * @return array{ok:bool,draft:array{data:array<string,mixed>,step:int,revision:int,updated_at:string}|null}
	 */
	public function save( int $sessionId, array $data, int $step, int $expectedRevision ): array {
		global $wpdb;

		$t    = Schema::table( 'drafts' );
		$json = Json::encode( $data );
		$now  = Clock::now();
		$step = max( 0, min( 20, $step ) );

		if ( $expectedRevision <= 0 ) {
			// Первая запись. Гонка двух вкладок: INSERT IGNORE по UNIQUE(session_id).
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$t} (session_id, data, step, revision, updated_at) VALUES (%d, %s, %d, 1, %s)",
					$sessionId,
					$json,
					$step,
					$now
				)
			);

			return array( 'ok' => 1 === (int) $inserted, 'draft' => $this->get( $sessionId ) );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET data = %s, step = %d, revision = revision + 1, updated_at = %s WHERE session_id = %d AND revision = %d",
				$json,
				$step,
				$now,
				$sessionId,
				$expectedRevision
			)
		);

		return array( 'ok' => 1 === (int) $updated, 'draft' => $this->get( $sessionId ) );
	}

	public function deleteForSession( int $sessionId ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'drafts' ), array( 'session_id' => $sessionId ), array( '%d' ) );
	}

	/** @return int Число удалённых черновиков. */
	public function purgeOlderThan( string $utcCutoff ): int {
		global $wpdb;

		$t = Schema::table( 'drafts' );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE updated_at < %s", $utcCutoff ) );
	}
}
