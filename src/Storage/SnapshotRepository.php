<?php
/**
 * Неизменяемые снимки: ответы, результат, предпросмотр и собранная
 * инструкция. После создания меняются только locked_at и purged_at.
 *
 * content_hash = sha256 от четырёх JSON-строк ровно в том виде, в каком
 * они лежат в БД (через "\n"), поэтому проверка не зависит от
 * повторного кодирования (порядок ключей, точность чисел).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Json;

final class SnapshotRepository {

	public static function contentHash( string $answersJson, string $resultJson, string $previewJson, string $instructionJson ): string {
		return hash( 'sha256', $answersJson . "\n" . $resultJson . "\n" . $previewJson . "\n" . $instructionJson );
	}

	/**
	 * Создаёт снимок.
	 *
	 * @param array<string,mixed> $answers
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $preview
	 * @param array<string,mixed> $instruction
	 * @return array<string,mixed>|null Строка снимка (JSON-колонки в виде строк).
	 */
	public function create(
		int $sessionId,
		int $userId,
		array $answers,
		array $result,
		array $preview,
		array $instruction,
		string $summary,
		string $rulesVersion,
		string $templatesVersion
	): ?array {
		global $wpdb;

		$a = Json::encode( $answers );
		$r = Json::encode( $result );
		$p = Json::encode( $preview );
		$i = Json::encode( $instruction );

		$publicId = Tokens::publicId();

		$ok = $wpdb->insert(
			Schema::table( 'snapshots' ),
			array(
				'public_id'         => $publicId,
				'session_id'        => $sessionId,
				'user_id'           => max( 0, $userId ),
				'status'            => substr( (string) ( $result['status'] ?? '' ), 0, 16 ),
				'answers'           => $a,
				'result'            => $r,
				'preview'           => $p,
				'instruction'       => $i,
				'summary'           => mb_substr( $summary, 0, 255 ),
				'rules_version'     => substr( $rulesVersion, 0, 32 ),
				'templates_version' => substr( $templatesVersion, 0, 32 ),
				'content_hash'      => self::contentHash( $a, $r, $p, $i ),
				'created_at'        => Clock::now(),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? $this->findById( (int) $wpdb->insert_id ) : null;
	}

	/** @return array<string,mixed>|null */
	public function findById( int $id ): ?array {
		global $wpdb;

		$t   = Schema::table( 'snapshots' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function findByPublicId( string $publicId ): ?array {
		global $wpdb;

		if ( ! Tokens::isWellFormed( $publicId, 32 ) ) {
			return null;
		}

		$t   = Schema::table( 'snapshots' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE public_id = %s", $publicId ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Список без тяжёлых колонок (answers/instruction не читаются).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listBySession( int $sessionId, int $limit = 20 ): array {
		global $wpdb;

		if ( $sessionId <= 0 ) {
			return array();
		}

		$t    = Schema::table( 'snapshots' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, public_id, session_id, user_id, status, preview, summary, rules_version, templates_version, created_at, locked_at, purged_at FROM {$t} WHERE session_id = %d ORDER BY id DESC LIMIT %d",
				$sessionId,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** @return list<array<string,mixed>> */
	public function listByUser( int $userId, int $limit = 20 ): array {
		global $wpdb;

		if ( $userId <= 0 ) {
			return array();
		}

		$t    = Schema::table( 'snapshots' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, public_id, session_id, user_id, status, preview, summary, rules_version, templates_version, created_at, locked_at, purged_at FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT %d",
				$userId,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Постраничный список для админки (без answers/instruction).
	 *
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function paginate( int $page, int $perPage = 20 ): array {
		global $wpdb;

		$t       = Schema::table( 'snapshots' );
		$perPage = max( 1, min( 100, $perPage ) );
		$offset  = max( 0, ( $page - 1 ) * $perPage );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, public_id, session_id, user_id, status, summary, rules_version, templates_version, created_at, locked_at, purged_at FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d",
				$perPage,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => is_array( $rows ) ? array_values( $rows ) : array(),
			'total' => $total,
		);
	}

	/** Фиксирует момент первого перехода к оплате (идемпотентно). */
	public function lock( int $id ): void {
		global $wpdb;

		$t = Schema::table( 'snapshots' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET locked_at = %s WHERE id = %d AND locked_at IS NULL", Clock::now(), $id ) );
	}

	/** Проверка целостности хранимых данных платной части. */
	public static function hashMatches( array $row ): bool {
		if ( ! empty( $row['purged_at'] ) || null === ( $row['answers'] ?? null ) || null === ( $row['instruction'] ?? null ) ) {
			return false;
		}

		$calc = self::contentHash(
			(string) $row['answers'],
			(string) ( $row['result'] ?? '' ),
			(string) ( $row['preview'] ?? '' ),
			(string) $row['instruction']
		);

		return hash_equals( (string) $row['content_hash'], $calc );
	}

	/**
	 * Шаг 1 очистки: атомарно ставит purged_at (только если ещё не стоит).
	 * После этого генерация PDF и выдача текста для снимка запрещены.
	 *
	 * @return bool true, если именно этот вызов пометил снимок.
	 */
	public function markPurged( int $id ): bool {
		global $wpdb;

		$t = Schema::table( 'snapshots' );

		return 1 === (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$t} SET purged_at = %s WHERE id = %d AND purged_at IS NULL", Clock::now(), $id )
		);
	}

	/** Шаг 2 очистки: обнуляет ответы, результат, предпросмотр и инструкцию помеченного снимка. */
	public function wipeData( int $id ): void {
		global $wpdb;

		$t = Schema::table( 'snapshots' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET answers = NULL, result = NULL, preview = NULL, instruction = NULL WHERE id = %d AND purged_at IS NOT NULL", $id ) );
	}

	/**
	 * Очистка данных снимка: ответы, результат, предпросмотр и инструкция
	 * обнуляются, строка остаётся (заказ EDD и покупки не затрагиваются).
	 */
	public function purge( int $id ): void {
		global $wpdb;

		$t = Schema::table( 'snapshots' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET answers = NULL, result = NULL, preview = NULL, instruction = NULL, purged_at = %s WHERE id = %d AND purged_at IS NULL",
				Clock::now(),
				$id
			)
		);
	}

	/**
	 * Кандидаты на автоочистку: старше границы, ещё не очищенные и без покупок,
	 * которые когда-либо оплачивались (paid_at) или находятся в состояниях
	 * paid/pending/disputed/refunded/revoked. Окончательное решение принимает
	 * SnapshotPurger::canAutoPurge() по живому состоянию EDD.
	 *
	 * @return list<int>
	 */
	public function findUnpaidOlderThan( string $utcCutoff, int $limit = 200 ): array {
		global $wpdb;

		$t = Schema::table( 'snapshots' );
		$p = Schema::table( 'purchases' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.id FROM {$t} s WHERE s.purged_at IS NULL AND s.created_at < %s AND NOT EXISTS (SELECT 1 FROM {$p} p WHERE p.snapshot_id = s.id AND (p.paid_at IS NOT NULL OR p.state IN ('paid','pending','disputed','refunded','revoked'))) ORDER BY s.id ASC LIMIT %d",
				$utcCutoff,
				$limit
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
