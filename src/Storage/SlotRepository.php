<?php
/**
 * Атомарная занятость времени и мест письменных разборов.
 *
 * Модель (docs/ARCHITECTURE.md, раздел «Консультации»):
 *  - booking_units(unit_utc PK, booking_id, expires_at NULL|unix):
 *    5-минутные единицы. expires_at не NULL — временный резерв,
 *    NULL — подтверждённая встреча;
 *  - written_seats(local_date, seat_no) PK, booking_id, expires_at — то
 *    же для мест письменных разборов на дату.
 *
 * Гарантии строятся на UNIQUE/PK и на условиях по САМОЙ строке:
 *  - чужие истёкшие резервы удаляет тот, кто занимает время:
 *    DELETE … WHERE expires_at IS NOT NULL AND expires_at < now;
 *  - подтверждение оплаты снимает срок: UPDATE … SET expires_at = NULL.
 * Обе операции работают с одними и теми же строками, поэтому InnoDB
 * упорядочивает их блокировками строк (UPDATE/DELETE перепроверяют
 * условие на актуальной версии строки). Если удаление прошло первым —
 * подтверждение видит нехватку единиц и пытается занять их заново;
 * если первым прошло подтверждение — удаление строку не трогает.
 * Многострочный INSERT атомарен на уровне оператора: при конфликте
 * ключа не вставляется ни одна строка.
 *
 * Deadlock (1213) и lock wait timeout (1205) — до 3 повторов с паузой.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

final class SlotRepository {

	public const MAX_RETRIES = 3;

	private string $units;
	private string $seats;

	/** @param \wpdb|object $db */
	public function __construct( private object $db, ?string $unitsTable = null, ?string $seatsTable = null ) {
		$this->units = $unitsTable ?? Schema::table( 'units' );
		$this->seats = $seatsTable ?? Schema::table( 'seats' );
	}

	// -----------------------------------------------------------------
	// Единицы времени.

	/**
	 * Занятые (действующие) единицы в диапазоне.
	 *
	 * @return array<int,true>
	 */
	public function busyUnits( int $from, int $to, int $now ): array {
		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT unit_utc FROM {$this->units} WHERE unit_utc >= %d AND unit_utc < %d AND ( expires_at IS NULL OR expires_at >= %d )",
				$from,
				$to,
				$now
			)
		);

		$out = array();

		foreach ( (array) $rows as $u ) {
			$out[ (int) $u ] = true;
		}

		return $out;
	}

	/**
	 * Занять единицы для брони. $expiresAt = null — сразу подтверждённая.
	 *
	 * @param list<int> $units
	 * @return bool false — время занято (или ошибка БД после повторов).
	 */
	public function claimUnits( int $bookingId, array $units, ?int $expiresAt, int $now ): bool {
		if ( empty( $units ) ) {
			return false;
		}

		return $this->retry(
			function () use ( $bookingId, $units, $expiresAt, $now ): ?bool {
				$this->purgeExpiredUnits( $units, $now );

				$values = array();

				foreach ( $units as $u ) {
					$values[] = null === $expiresAt
						? $this->db->prepare( '(%d, %d, NULL)', $u, $bookingId )
						: $this->db->prepare( '(%d, %d, %d)', $u, $bookingId, $expiresAt );
				}

				return $this->exec( "INSERT INTO {$this->units} (unit_utc, booking_id, expires_at) VALUES " . implode( ',', $values ) );
			}
		);
	}

	/**
	 * Подтвердить единицы брони (снять срок). Если часть единиц уже
	 * отдана другим (резерв истёк и время заняли), пробует занять
	 * недостающие; при неудаче освобождает свои и возвращает false.
	 *
	 * Идемпотентно: повторный вызов для подтверждённой брони — true.
	 *
	 * @param list<int> $units
	 */
	public function confirmUnits( int $bookingId, array $units, int $now ): bool {
		if ( empty( $units ) ) {
			return false;
		}

		$in = implode( ',', array_map( 'intval', $units ) );

		$ok = $this->retry(
			fn(): ?bool => $this->exec(
				$this->db->prepare( "UPDATE {$this->units} SET expires_at = NULL WHERE booking_id = %d AND unit_utc IN ($in)", $bookingId )
			)
		);

		if ( ! $ok ) {
			return false;
		}

		// Строки с NULL никто, кроме нас, удалить не может — счёт надёжен.
		$have = array_map(
			'intval',
			(array) $this->db->get_col( $this->db->prepare( "SELECT unit_utc FROM {$this->units} WHERE booking_id = %d AND expires_at IS NULL AND unit_utc IN ($in)", $bookingId ) )
		);

		$missing = array_values( array_diff( $units, $have ) );

		if ( empty( $missing ) ) {
			return true;
		}

		if ( $this->claimUnits( $bookingId, $missing, null, $now ) ) {
			return true;
		}

		$this->releaseUnits( $bookingId );

		return false;
	}

	/**
	 * Перенос подтверждённой брони: сначала занять недостающие новые
	 * единицы, затем освободить старые, не входящие в новый интервал.
	 * Старое время не освобождается, пока новое не занято.
	 *
	 * @param list<int> $newUnits
	 */
	public function transferUnits( int $bookingId, array $newUnits, ?int $expiresAt, int $now ): bool {
		if ( empty( $newUnits ) ) {
			return false;
		}

		$owned = array_map(
			'intval',
			(array) $this->db->get_col( $this->db->prepare( "SELECT unit_utc FROM {$this->units} WHERE booking_id = %d", $bookingId ) )
		);
		$toAdd = array_values( array_diff( $newUnits, $owned ) );

		if ( ! empty( $toAdd ) && ! $this->claimUnits( $bookingId, $toAdd, $expiresAt, $now ) ) {
			return false;
		}

		$keep = implode( ',', array_map( 'intval', $newUnits ) );

		$this->retry(
			fn(): ?bool => $this->exec(
				$this->db->prepare( "DELETE FROM {$this->units} WHERE booking_id = %d AND unit_utc NOT IN ($keep)", $bookingId )
			)
		);

		if ( null === $expiresAt ) {
			// Общие с прежним интервалом единицы тоже подтверждаем.
			$this->retry(
				fn(): ?bool => $this->exec(
					$this->db->prepare( "UPDATE {$this->units} SET expires_at = NULL WHERE booking_id = %d", $bookingId )
				)
			);
		}

		return true;
	}

	public function releaseUnits( int $bookingId ): void {
		$this->retry( fn(): ?bool => $this->exec( $this->db->prepare( "DELETE FROM {$this->units} WHERE booking_id = %d", $bookingId ) ) );
	}

	/** Сколько единиц у брони (для проверок и админки). */
	public function countUnits( int $bookingId, bool $confirmedOnly = false ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->units} WHERE booking_id = %d" . ( $confirmedOnly ? ' AND expires_at IS NULL' : '' ),
				$bookingId
			)
		);
	}

	/** Удаление всех истёкших резервов (необязательная уборка; корректность от неё не зависит). */
	public function purgeAllExpired( int $now ): void {
		$this->retry( fn(): ?bool => $this->exec( $this->db->prepare( "DELETE FROM {$this->units} WHERE expires_at IS NOT NULL AND expires_at < %d", $now ) ) );
		$this->retry( fn(): ?bool => $this->exec( $this->db->prepare( "DELETE FROM {$this->seats} WHERE expires_at IS NOT NULL AND expires_at < %d", $now ) ) );
	}

	/** @param list<int> $units */
	private function purgeExpiredUnits( array $units, int $now ): void {
		$in = implode( ',', array_map( 'intval', $units ) );

		$this->retry( fn(): ?bool => $this->exec( $this->db->prepare( "DELETE FROM {$this->units} WHERE unit_utc IN ($in) AND expires_at IS NOT NULL AND expires_at < %d", $now ) ) );
	}

	// -----------------------------------------------------------------
	// Места письменных разборов.

	/** Действующих мест на дату. */
	public function seatsUsed( string $date, int $now ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->seats} WHERE local_date = %s AND ( expires_at IS NULL OR expires_at >= %d )",
				$date,
				$now
			)
		);
	}

	/**
	 * Занять место на дату (номер 1..limit). Идемпотентно: если у брони
	 * уже есть место на эту дату — вернёт его номер.
	 */
	public function claimSeat( int $bookingId, string $date, int $limit, ?int $expiresAt, int $now ): ?int {
		$existing = $this->db->get_var(
			$this->db->prepare(
				"SELECT seat_no FROM {$this->seats} WHERE booking_id = %d AND local_date = %s AND ( expires_at IS NULL OR expires_at >= %d )",
				$bookingId,
				$date,
				$now
			)
		);

		if ( null !== $existing ) {
			return (int) $existing;
		}

		$this->retry(
			fn(): ?bool => $this->exec(
				$this->db->prepare( "DELETE FROM {$this->seats} WHERE local_date = %s AND expires_at IS NOT NULL AND expires_at < %d", $date, $now )
			)
		);

		for ( $n = 1; $n <= $limit; $n++ ) {
			$sql = null === $expiresAt
				? $this->db->prepare( "INSERT INTO {$this->seats} (local_date, seat_no, booking_id, expires_at) VALUES (%s, %d, %d, NULL)", $date, $n, $bookingId )
				: $this->db->prepare( "INSERT INTO {$this->seats} (local_date, seat_no, booking_id, expires_at) VALUES (%s, %d, %d, %d)", $date, $n, $bookingId, $expiresAt );

			if ( $this->retry( fn(): ?bool => $this->exec( $sql ) ) ) {
				return $n;
			}
		}

		return null;
	}

	/** Подтвердить место; если резерв истёк и место отдано — занять заново. */
	public function confirmSeat( int $bookingId, string $date, int $limit, int $now ): bool {
		$updated = $this->retry(
			fn(): ?bool => $this->exec(
				$this->db->prepare( "UPDATE {$this->seats} SET expires_at = NULL WHERE booking_id = %d AND local_date = %s", $bookingId, $date )
			)
		);

		if ( ! $updated ) {
			return false;
		}

		$have = $this->db->get_var(
			$this->db->prepare( "SELECT seat_no FROM {$this->seats} WHERE booking_id = %d AND local_date = %s AND expires_at IS NULL", $bookingId, $date )
		);

		if ( null !== $have ) {
			return true;
		}

		return null !== $this->claimSeat( $bookingId, $date, $limit, null, $now );
	}

	/** Перенос письменного разбора на другую дату: сначала новое место. */
	public function transferSeat( int $bookingId, string $newDate, int $limit, int $now ): bool {
		$old = $this->db->get_var( $this->db->prepare( "SELECT local_date FROM {$this->seats} WHERE booking_id = %d", $bookingId ) );

		if ( $old === $newDate ) {
			return true;
		}

		if ( null === $this->claimSeat( $bookingId, $newDate, $limit, null, $now ) ) {
			return false;
		}

		$this->retry(
			fn(): ?bool => $this->exec(
				$this->db->prepare( "DELETE FROM {$this->seats} WHERE booking_id = %d AND local_date <> %s", $bookingId, $newDate )
			)
		);

		return true;
	}

	public function releaseSeat( int $bookingId ): void {
		$this->retry( fn(): ?bool => $this->exec( $this->db->prepare( "DELETE FROM {$this->seats} WHERE booking_id = %d", $bookingId ) ) );
	}

	// -----------------------------------------------------------------

	/**
	 * Выполнить запрос. true — успех, false — конфликт уникального ключа,
	 * null — повторяемая ошибка (deadlock/lock timeout). Прочие ошибки — false.
	 */
	private function exec( string $sql ): ?bool {
		$prev = $this->db->suppress_errors( true );
		$res  = $this->db->query( $sql );
		$err  = (string) $this->db->last_error;
		$this->db->suppress_errors( $prev );

		if ( false !== $res && '' === $err ) {
			return true;
		}

		if ( self::isRetryable( $err ) ) {
			return null;
		}

		return false;
	}

	/**
	 * @param callable(): ?bool $op
	 */
	private function retry( callable $op ): bool {
		for ( $i = 0; $i <= self::MAX_RETRIES; $i++ ) {
			$r = $op();

			if ( null !== $r ) {
				return $r;
			}

			usleep( random_int( 20000, 80000 ) * ( $i + 1 ) );
		}

		return false;
	}

	public static function isRetryable( string $error ): bool {
		return 1 === preg_match( '/deadlock|lock wait timeout|database is locked|SQLSTATE\[40001\]/i', $error );
	}

	public static function isDuplicate( string $error ): bool {
		return 1 === preg_match( '/duplicate entry|unique constraint|SQLSTATE\[23000\]/i', $error );
	}
}
