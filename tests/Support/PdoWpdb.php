<?php
/**
 * Мини-замена $wpdb на PDO + SQLite (в памяти) для тестов репозиториев.
 *
 * Реализует только то, что используют репозитории консультаций:
 * prepare (%d, %s, %f, %%), query, get_var/get_col/get_row/get_results
 * (ARRAY_A), insert/update/delete, last_error, insert_id, suppress_errors.
 * Тесты работают с настоящим SQL репозиториев; нужен php с расширением
 * pdo_sqlite (иначе тесты пропускаются).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Support;

final class PdoWpdb {

	public string $last_error = '';
	public int $insert_id     = 0;
	public int $rows_affected = 0;
	public string $prefix     = 'wp_';

	private \PDO $pdo;
	private bool $suppress = false;

	/** @var list<string> Все выполненные запросы (для проверок в тестах). */
	public array $log = array();

	public static function available(): bool {
		return extension_loaded( 'pdo_sqlite' );
	}

	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
	}

	public function exec_ddl( string $sql ): void {
		$this->pdo->exec( $sql );
	}

	public function suppress_errors( $suppress = true ): bool {
		$prev           = $this->suppress;
		$this->suppress = (bool) $suppress;

		return $prev;
	}

	/**
	 * Как в WordPress: %d, %s, %f, %%; аргументы — перечислением либо одним массивом.
	 *
	 * @param mixed ...$args
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return (string) preg_replace_callback(
			'/%[%dsf]/',
			function ( array $m ) use ( &$i, $args ): string {
				if ( '%%' === $m[0] ) {
					return '%';
				}

				if ( ! array_key_exists( $i, $args ) ) {
					throw new \LogicException( 'prepare: не хватает аргументов для ' . $m[0] );
				}

				$v = $args[ $i++ ];

				if ( '%d' === $m[0] ) {
					return (string) (int) $v;
				}

				if ( '%f' === $m[0] ) {
					return (string) (float) $v;
				}

				return $this->pdo->quote( null === $v ? '' : (string) $v );
			},
			$query
		);
	}

	/** @return int|false */
	public function query( string $sql ) {
		$this->last_error = '';
		$this->log[]      = $sql;

		try {
			$n                   = $this->pdo->exec( $sql );
			$this->rows_affected = (int) $n;
			$this->insert_id     = (int) $this->pdo->lastInsertId();

			return (int) $n;
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	public function get_var( string $sql ) {
		$row = $this->first( $sql, \PDO::FETCH_NUM );

		return null === $row ? null : $row[0];
	}

	/** @return list<mixed> */
	public function get_col( string $sql ): array {
		return $this->rows( $sql, \PDO::FETCH_COLUMN );
	}

	/** @return array<string,mixed>|null */
	public function get_row( string $sql, $output = 'ARRAY_A' ) {
		return $this->first( $sql, \PDO::FETCH_ASSOC );
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $sql, $output = 'ARRAY_A' ): array {
		return $this->rows( $sql, \PDO::FETCH_ASSOC );
	}

	/**
	 * @param array<string,mixed> $data
	 * @param mixed               $format
	 * @return int|false
	 */
	public function insert( string $table, array $data, $format = null ) {
		$cols = array();
		$vals = array();

		foreach ( $data as $col => $v ) {
			$cols[] = $col;
			$vals[] = null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : $this->pdo->quote( (string) $v ) );
		}

		return $this->query( "INSERT INTO $table (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where, $format = null ) {
		$cond = array();

		foreach ( $where as $col => $v ) {
			$cond[] = $col . ' = ' . ( is_int( $v ) ? (string) $v : $this->pdo->quote( (string) $v ) );
		}

		return $this->query( "DELETE FROM $table WHERE " . implode( ' AND ', $cond ) );
	}

	// -----------------------------------------------------------------

	/** @return array<int|string,mixed>|null */
	private function first( string $sql, int $mode ): ?array {
		$this->last_error = '';
		$this->log[]      = $sql;

		try {
			$st  = $this->pdo->query( $sql );
			$row = $st->fetch( $mode );

			return false === $row ? null : $row;
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();

			return null;
		}
	}

	/** @return list<mixed> */
	private function rows( string $sql, int $mode ): array {
		$this->last_error = '';
		$this->log[]      = $sql;

		try {
			return $this->pdo->query( $sql )->fetchAll( $mode );
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();

			return array();
		}
	}
}
