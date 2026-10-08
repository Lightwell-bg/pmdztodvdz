<?php
/**
 * Записи на консультацию (таблица bookings): CRUD без бизнес-логики.
 *
 * Интерфейс, который ожидает Booking\BookingService:
 * create / get / transition / update / activeHold. Остальное — выборки для
 * REST, cron и админки.
 *
 * Все изменения статуса идут условным UPDATE ... WHERE status IN (...),
 * поэтому повторные события и параллельные запросы идемпотентны.
 * Весь SQL — через prepare(); имена колонок берутся только из белого списка.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Json;

final class BookingRepository {

	/**
	 * Колонки, доступные для записи: тип ('i' — int, 's' — строка, 'j' — JSON)
	 * и максимальная длина строки (0 — без ограничения).
	 */
	private const COLS = array(
		'snapshot_id'        => array( 'i', 0 ),
		'session_id'         => array( 'i', 0 ),
		'user_id'            => array( 'i', 0 ),
		'format'             => array( 's', 16 ),
		'mode'               => array( 's', 16 ),
		'kind'               => array( 's', 8 ),
		'status'             => array( 's', 20 ),
		'status_reason'      => array( 's', 32 ),
		'pre_dispute_status' => array( 's', 20 ),
		'start_utc'          => array( 'i', 0 ),
		'end_utc'            => array( 'i', 0 ),
		'block_end_utc'      => array( 'i', 0 ),
		'written_date'       => array( 's', 10 ),
		'deadline_utc'       => array( 'i', 0 ),
		'hold_until'         => array( 'i', 0 ),
		'contact'            => array( 's', 190 ),
		'email'              => array( 's', 190 ),
		'question'           => array( 's', 0 ),
		'visitor_tz'         => array( 's', 64 ),
		'terms'              => array( 'j', 0 ),
		'meet_url'           => array( 's', 255 ),
		'order_id'           => array( 'i', 0 ),
		'order_item_id'      => array( 'i', 0 ),
		'purchase_id'        => array( 'i', 0 ),
		'credit_purchase_id' => array( 'i', 0 ),
		'credit_amount'      => array( 's', 32 ),
		'credit_conflict'    => array( 'i', 0 ),
		'ics_uid'            => array( 's', 64 ),
		'ics_sequence'       => array( 'i', 0 ),
		'reschedule_count'   => array( 'i', 0 ),
		'followups_used'     => array( 'i', 0 ),
		'reminder_sent_at'   => array( 's', 19 ),
		'paid_at'            => array( 's', 19 ),
		'admin_notes'        => array( 's', 0 ),
	);

	/** Колонки, которые после создания не меняются. */
	private const IMMUTABLE = array( 'snapshot_id', 'kind', 'format', 'ics_uid' );

	/** Целочисленные колонки результата (NULL остаётся NULL). */
	private const INT_COLS = array(
		'id', 'snapshot_id', 'session_id', 'user_id', 'start_utc', 'end_utc', 'block_end_utc', 'deadline_utc', 'hold_until',
		'order_id', 'order_item_id', 'purchase_id', 'credit_purchase_id', 'credit_conflict', 'ics_sequence', 'reschedule_count', 'followups_used', 'lock_until',
	);

	private object $db;
	private string $table;

	/** @param \wpdb|object|null $db */
	public function __construct( ?object $db = null, ?string $table = null ) {
		$this->db    = $db ?? $GLOBALS['wpdb'];
		$this->table = $table ?? Schema::table( 'bookings' );
	}

	// -----------------------------------------------------------------
	// Интерфейс BookingService.

	/**
	 * Создаёт запись. public_id и ics_uid генерируются здесь, created_at и
	 * updated_at — UTC. Возвращает строку через get().
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>|null
	 */
	public function create( array $data ): ?array {
		$data = array_intersect_key( $data, self::COLS );
		$now  = Clock::now();

		$data['status']        = (string) ( $data['status'] ?? 'held' );
		$data['ics_uid']       = bin2hex( random_bytes( 12 ) ) . '@' . self::host();
		$data['credit_amount'] = (string) ( $data['credit_amount'] ?? '0' );

		$cols = array( 'public_id', 'created_at', 'updated_at' );
		$vals = array( '%s', '%s', '%s' );
		$args = array( Tokens::publicId(), $now, $now );

		[ $setCols, $setVals, $setArgs ] = $this->assignments( $data );

		$cols = array_merge( $cols, $setCols );
		$vals = array_merge( $vals, $setVals );
		$args = array_merge( $args, $setArgs );

		$sql = "INSERT INTO {$this->table} (" . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $vals ) . ')';

		$prev = $this->db->suppress_errors( true );
		$res  = $this->db->query( $this->db->prepare( $sql, ...$args ) );
		$this->db->suppress_errors( $prev );

		if ( false === $res || 0 === (int) $res ) {
			return null;
		}

		return $this->get( (int) $this->db->insert_id );
	}

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		return $this->one( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ) );
	}

	/** @return array<string,mixed>|null */
	public function byPublicId( string $publicId ): ?array {
		if ( ! Tokens::isWellFormed( $publicId, 32 ) ) {
			return null;
		}

		return $this->one( $this->db->prepare( "SELECT * FROM {$this->table} WHERE public_id = %s", $publicId ) );
	}

	/**
	 * Условный переход статуса. true — только если ЭТОТ вызов перевёл запись
	 * из одного из $from в $to.
	 *
	 * @param list<string>        $from
	 * @param array<string,mixed> $fields
	 */
	public function transition( int $id, array $from, string $to, array $fields = array() ): bool {
		if ( empty( $from ) ) {
			return false;
		}

		$fields           = $this->mutable( $fields );
		$fields['status'] = $to;

		[ $cols, $vals, $args ] = $this->assignments( $fields );

		$set = array();

		foreach ( $cols as $i => $col ) {
			$set[] = $col . ' = ' . $vals[ $i ];
		}

		$set[]  = 'updated_at = %s';
		$args[] = Clock::now();
		$in     = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$args   = array_merge( $args, array( $id ), array_values( $from ) );

		$res = $this->db->query( $this->db->prepare( "UPDATE {$this->table} SET " . implode( ', ', $set ) . " WHERE id = %d AND status IN ($in)", ...$args ) );

		// Для перехода в другой статус число изменённых строк надёжно (status меняется);
		// при «переходе в тот же статус» (перенос) меняются updated_at и поля.
		return (int) $res > 0;
	}

	/**
	 * Взять блокировку записи на $ttl секунд (мутации BookingService идут под ней).
	 * Возвращает токен, если блокировка наша, иначе null. Просроченная блокировка
	 * (lock_until < now) перехватывается.
	 */
	public function acquireLock( int $id, int $now, int $ttl = 30 ): ?string {
		$token = bin2hex( random_bytes( 16 ) );

		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET lock_token = %s, lock_until = %d WHERE id = %d AND ( lock_until IS NULL OR lock_until < %d )",
				$token,
				$now + $ttl,
				$id,
				$now
			)
		);

		$have = $this->db->get_var( $this->db->prepare( "SELECT lock_token FROM {$this->table} WHERE id = %d", $id ) );

		return $have === $token ? $token : null;
	}

	/** Снять блокировку, только если она ещё наша. */
	public function releaseLock( int $id, string $token ): void {
		$this->db->query(
			$this->db->prepare( "UPDATE {$this->table} SET lock_token = '', lock_until = NULL WHERE id = %d AND lock_token = %s", $id, $token )
		);
	}

	/**
	 * Обновление полей без смены статуса.
	 *
	 * @param array<string,mixed> $fields
	 */
	public function update( int $id, array $fields ): void {
		$fields = $this->mutable( $fields );

		if ( empty( $fields ) ) {
			return;
		}

		[ $cols, $vals, $args ] = $this->assignments( $fields );

		$set = array();

		foreach ( $cols as $i => $col ) {
			$set[] = $col . ' = ' . $vals[ $i ];
		}

		$set[]  = 'updated_at = %s';
		$args[] = Clock::now();
		$args[] = $id;

		$this->db->query( $this->db->prepare( "UPDATE {$this->table} SET " . implode( ', ', $set ) . ' WHERE id = %d', ...$args ) );
	}

	/**
	 * Самый свежий действующий резерв сессии по анкете.
	 *
	 * @return array<string,mixed>|null
	 */
	public function activeHold( int $sessionId, int $snapshotId, int $now ): ?array {
		return $this->one(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE session_id = %d AND snapshot_id = %d AND status = 'held' AND hold_until >= %d ORDER BY id DESC LIMIT 1",
				$sessionId,
				$snapshotId,
				$now
			)
		);
	}

	// -----------------------------------------------------------------
	// Выборки.

	/**
	 * Записи анкеты (без истёкших резервов), новые первыми.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function bySnapshot( int $snapshotId ): array {
		return $this->many( $this->db->prepare( "SELECT * FROM {$this->table} WHERE snapshot_id = %d AND status <> 'expired' ORDER BY id DESC LIMIT 50", $snapshotId ) );
	}

	/** @return list<array<string,mixed>> */
	public function byOrder( int $orderId ): array {
		return $this->many( $this->db->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY id ASC", $orderId ) );
	}

	/** @return array<string,mixed>|null */
	public function byPurchase( int $purchaseId ): ?array {
		return $this->one( $this->db->prepare( "SELECT * FROM {$this->table} WHERE purchase_id = %d ORDER BY id DESC LIMIT 1", $purchaseId ) );
	}

	/**
	 * Резервы с истёкшим сроком (кандидаты на освобождение).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function staleHeld( int $now, int $limit = 100 ): array {
		return $this->many( $this->db->prepare( "SELECT * FROM {$this->table} WHERE status = 'held' AND hold_until < %d ORDER BY id ASC LIMIT %d", $now, $limit ) );
	}

	/**
	 * Записи, у которых есть заказ, но оплата ещё не подтверждена (held/expired):
	 * сверка с живым EDD, если хук пропущен.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function awaitingPayment( int $limit = 100 ): array {
		return $this->many(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE status IN ('held','expired') AND order_id > 0 AND created_at >= %s ORDER BY id DESC LIMIT %d",
				Clock::fromNow( -30 * 86400 ),
				$limit
			)
		);
	}

	/**
	 * Подтверждённые синхронные встречи в окне (from, to], напоминание не отправлено.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function dueReminders( int $from, int $to, int $limit = 100 ): array {
		return $this->many(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE status = 'confirmed' AND kind = 'sync' AND start_utc > %d AND start_utc <= %d AND reminder_sent_at IS NULL ORDER BY start_utc ASC LIMIT %d",
				$from,
				$to,
				$limit
			)
		);
	}

	/**
	 * Постраничный список для админки.
	 * Фильтры: status (строка или список), format, from_utc, to_utc, conflicts (bool).
	 *
	 * @param array<string,mixed> $filters
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public function listForAdmin( array $filters, int $page, int $perPage = 20 ): array {
		$perPage = max( 1, min( 100, $perPage ) );
		$offset  = max( 0, ( $page - 1 ) * $perPage );
		$where   = array( '1=1' );
		$args    = array();

		$statuses = array_values( array_filter( array_map( 'strval', (array) ( $filters['status'] ?? array() ) ), static fn( string $s ): bool => '' !== $s ) );

		if ( ! empty( $statuses ) ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$args    = array_merge( $args, $statuses );
		}

		if ( ! empty( $filters['format'] ) ) {
			$where[] = 'format = %s';
			$args[]  = (string) $filters['format'];
		}

		if ( ! empty( $filters['from_utc'] ) ) {
			$where[] = 'COALESCE(start_utc, deadline_utc) >= %d';
			$args[]  = (int) $filters['from_utc'];
		}

		if ( ! empty( $filters['to_utc'] ) ) {
			$where[] = 'COALESCE(start_utc, deadline_utc) < %d';
			$args[]  = (int) $filters['to_utc'];
		}

		if ( ! empty( $filters['conflicts'] ) ) {
			$where[] = "( credit_conflict = 1 OR status = 'needs_reschedule' )";
		}

		$cond  = implode( ' AND ', $where );
		$total = (int) $this->db->get_var( empty( $args ) ? "SELECT COUNT(*) FROM {$this->table} WHERE $cond" : $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE $cond", ...$args ) );
		$rows  = $this->many( $this->db->prepare( "SELECT * FROM {$this->table} WHERE $cond ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge( $args, array( $perPage, $offset ) ) ) );

		return array( 'items' => $rows, 'total' => $total );
	}

	/**
	 * Записи месяца (кроме истёкших резервов) для календаря админки.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function forMonth( int $year, int $month, string $tz ): array {
		try {
			$zone = new \DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}

		$first = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $zone );
		$next  = $first->modify( '+1 month' );

		return $this->many(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE status <> 'expired' AND ( ( start_utc >= %d AND start_utc < %d ) OR ( kind = 'written' AND written_date >= %s AND written_date < %s ) ) ORDER BY COALESCE(start_utc, deadline_utc) ASC LIMIT 500",
				$first->getTimestamp(),
				$next->getTimestamp(),
				$first->format( 'Y-m-d' ),
				$next->format( 'Y-m-d' )
			)
		);
	}

	// -----------------------------------------------------------------

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function mutable( array $fields ): array {
		return array_diff_key( array_intersect_key( $fields, self::COLS ), array_flip( self::IMMUTABLE ) );
	}

	/**
	 * Колонки, плейсхолдеры и аргументы для SET/VALUES. NULL пишется литералом.
	 *
	 * @param array<string,mixed> $fields
	 * @return array{0:list<string>,1:list<string>,2:list<mixed>}
	 */
	private function assignments( array $fields ): array {
		$cols = array();
		$vals = array();
		$args = array();

		foreach ( $fields as $col => $value ) {
			if ( ! isset( self::COLS[ $col ] ) ) {
				continue;
			}

			[ $type, $max ] = self::COLS[ $col ];

			$cols[] = $col;

			if ( null === $value ) {
				$vals[] = 'NULL';
				continue;
			}

			if ( 'i' === $type ) {
				$vals[] = '%d';
				$args[] = (int) $value;
			} elseif ( 'j' === $type ) {
				$vals[] = '%s';
				$args[] = Json::encode( $value );
			} else {
				$s = (string) $value;

				$vals[] = '%s';
				$args[] = $max > 0 ? mb_substr( $s, 0, $max ) : $s;
			}
		}

		return array( $cols, $vals, $args );
	}

	/** @return array<string,mixed>|null */
	private function one( string $sql ): ?array {
		$row = $this->db->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/** @return list<array<string,mixed>> */
	private function many( string $sql ): array {
		$rows = $this->db->get_results( $sql, ARRAY_A );
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->decode( $row );
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function decode( array $row ): array {
		foreach ( self::INT_COLS as $col ) {
			if ( array_key_exists( $col, $row ) && null !== $row[ $col ] ) {
				$row[ $col ] = (int) $row[ $col ];
			}
		}

		$row['terms'] = Json::decode( isset( $row['terms'] ) ? (string) $row['terms'] : '' );

		return $row;
	}

	private static function host(): string {
		$home = function_exists( 'home_url' ) ? (string) home_url() : '';
		$host = '' === $home ? '' : (string) parse_url( $home, PHP_URL_HOST );

		return '' === $host ? 'localhost' : $host;
	}
}
