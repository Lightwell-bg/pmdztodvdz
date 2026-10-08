<?php
/**
 * Сессии анкеты. В БД лежит только sha256 токена cookie; csrf_secret
 * нужен в открытом виде для HMAC и никогда не отдаётся клиенту.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Support\Clock;

final class SessionRepository {

	/**
	 * Создаёт сессию. Сырой токен возвращается один раз (ключ `token`).
	 *
	 * @return array<string,mixed>|null
	 */
	public function create( int $userId, int $ttlDays ): ?array {
		global $wpdb;

		$token  = Tokens::random();
		$secret = Tokens::random();
		$now    = Clock::now();

		$ok = $wpdb->insert(
			Schema::table( 'sessions' ),
			array(
				'token_hash'   => Tokens::hash( $token ),
				'csrf_secret'  => $secret,
				'user_id'      => max( 0, $userId ),
				'created_at'   => $now,
				'last_seen_at' => $now,
				'expires_at'   => Clock::fromNow( $ttlDays * DAY_IN_SECONDS ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return null;
		}

		$row = $this->get( (int) $wpdb->insert_id );

		if ( null === $row ) {
			return null;
		}

		$row['token'] = $token;

		return $row;
	}

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		global $wpdb;

		$t   = Schema::table( 'sessions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Сессия по токену cookie. Просроченная не возвращается.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findByToken( string $token ): ?array {
		global $wpdb;

		if ( ! Tokens::isWellFormed( $token ) ) {
			return null;
		}

		$t   = Schema::table( 'sessions' );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE token_hash = %s AND expires_at > %s", Tokens::hash( $token ), Clock::now() ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** Продлевает сессию (не чаще раза в час, чтобы не писать в БД на каждый запрос). */
	public function touch( array $session, int $ttlDays ): void {
		global $wpdb;

		if ( Clock::timestamp() - Clock::toTimestamp( (string) $session['last_seen_at'] ) < HOUR_IN_SECONDS ) {
			return;
		}

		$wpdb->update(
			Schema::table( 'sessions' ),
			array(
				'last_seen_at' => Clock::now(),
				'expires_at'   => Clock::fromNow( $ttlDays * DAY_IN_SECONDS ),
			),
			array( 'id' => (int) $session['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Привязывает вошедшего пользователя к сессии (user_id не перезаписывается другим). */
	public function attachUser( int $sessionId, int $userId ): void {
		global $wpdb;

		if ( $userId <= 0 ) {
			return;
		}

		$t = Schema::table( 'sessions' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET user_id = %d WHERE id = %d AND user_id = 0", $userId, $sessionId ) );
	}

	/**
	 * Удаляет истёкшие сессии. Сессии, на которые ссылаются снимки,
	 * остаются (иначе освободившийся id мог бы быть выдан новой сессии и
	 * дать ей чужие снимки); у очищенных снимков session_id обнуляется.
	 *
	 * @return int Число удалённых сессий.
	 */
	public function purgeExpired(): int {
		global $wpdb;

		$t   = Schema::table( 'sessions' );
		$s   = Schema::table( 'snapshots' );
		$d   = Schema::table( 'drafts' );
		$now = Clock::now();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$s} SET session_id = 0 WHERE purged_at IS NOT NULL AND session_id IN (SELECT id FROM (SELECT id FROM {$t} WHERE expires_at < %s) AS x)",
				$now
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$d} WHERE session_id IN (SELECT id FROM (SELECT id FROM {$t} WHERE expires_at < %s) AS x)",
				$now
			)
		);

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$t} WHERE expires_at < %s AND NOT EXISTS (SELECT 1 FROM {$s} WHERE {$s}.session_id = {$t}.id)",
				$now
			)
		);

		return (int) $deleted;
	}
}
