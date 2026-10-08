<?php
/**
 * Версии шаблонов текстов. Строки только добавляются.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Json;

final class TemplateRepository {

	/** @return array{version:string,data:array<string,mixed>,created_at:string}|null */
	public function latest(): ?array {
		global $wpdb;

		$t   = Schema::table( 'templates' );
		$row = $wpdb->get_row( "SELECT version, data, created_at FROM {$t} ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** @return array{version:string,data:array<string,mixed>,created_at:string}|null */
	public function byVersion( string $version ): ?array {
		global $wpdb;

		$t   = Schema::table( 'templates' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT version, data, created_at FROM {$t} WHERE version = %s", $version ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function exists( string $version ): bool {
		global $wpdb;

		$t = Schema::table( 'templates' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE version = %s", $version ) ) > 0;
	}

	/** @param array<string,mixed> $data */
	public function insert( string $version, array $data, int $userId ): bool {
		global $wpdb;

		// Подавляем вывод ошибки уникального ключа: коллизию обрабатывает TemplateStore.
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			Schema::table( 'templates' ),
			array(
				'version'    => substr( $version, 0, 32 ),
				'data'       => Json::encode( $data ),
				'created_by' => max( 0, $userId ),
				'created_at' => Clock::now(),
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		return (bool) $ok;
	}

	/**
	 * Список версий (новые первыми), без тела.
	 *
	 * @return list<array{version:string,created_at:string,created_by:int}>
	 */
	public function listVersions( int $limit = 50 ): array {
		global $wpdb;

		$t    = Schema::table( 'templates' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT version, created_at, created_by FROM {$t} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{version:string,data:array<string,mixed>,created_at:string}
	 */
	private function hydrate( array $row ): array {
		return array(
			'version'    => (string) $row['version'],
			'data'       => Json::decode( (string) $row['data'] ),
			'created_at' => (string) $row['created_at'],
		);
	}
}
