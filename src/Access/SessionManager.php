<?php
/**
 * Cookie сессии анкеты `bgdvzh_s` ↔ строка таблицы sessions.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Access;

use Bginfo\PmzhDvzh\Storage\SessionRepository;
use Bginfo\PmzhDvzh\Support\Cookies;
use Bginfo\PmzhDvzh\Support\Settings;

final class SessionManager {

	public const COOKIE = 'bgdvzh_s';

	/** @var array<string,mixed>|null */
	private ?array $session = null;
	private bool $resolved  = false;

	public function __construct( private SessionRepository $repo, private Settings $settings ) {
	}

	private function ttlDays(): int {
		return max( 1, (int) $this->settings->get( 'retention_days_drafts', 30 ) );
	}

	/**
	 * Сессия по cookie запроса (без создания).
	 *
	 * @return array<string,mixed>|null
	 */
	public function current(): ?array {
		if ( $this->resolved ) {
			return $this->session;
		}

		$this->resolved = true;
		$token          = Cookies::get( self::COOKIE );

		if ( '' === $token ) {
			return null;
		}

		$session = $this->repo->findByToken( $token );

		if ( null === $session ) {
			return null;
		}

		$this->repo->touch( $session, $this->ttlDays() );

		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $uid > 0 && 0 === (int) $session['user_id'] ) {
			$this->repo->attachUser( (int) $session['id'], $uid );
			$session['user_id'] = $uid;
		}

		$this->session = $session;

		return $this->session;
	}

	/**
	 * Текущая сессия либо новая (cookie выставляется сразу). Идемпотентно.
	 *
	 * @return array<string,mixed>|null
	 */
	public function ensure(): ?array {
		$existing = $this->current();

		if ( null !== $existing ) {
			return $existing;
		}

		$uid     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$created = $this->repo->create( $uid, $this->ttlDays() );

		if ( null === $created ) {
			return null;
		}

		Cookies::set( self::COOKIE, (string) $created['token'], time() + $this->ttlDays() * DAY_IN_SECONDS );

		unset( $created['token'] );
		$this->session  = $created;
		$this->resolved = true;

		return $this->session;
	}

	/** csrf-токен действия для текущей сессии либо '' без сессии. */
	public function csrfFor( string $action, string $scope = '' ): string {
		$s = $this->current();

		return null === $s ? '' : Tokens::csrf( (string) $s['csrf_secret'], $action, $scope );
	}

	public function csrfValid( string $action, string $scope, string $given ): bool {
		$s = $this->current();

		return null !== $s && Tokens::csrfValid( (string) $s['csrf_secret'], $action, $scope, $given );
	}

	public function sessionId(): int {
		$s = $this->current();

		return null === $s ? 0 : (int) $s['id'];
	}
}
