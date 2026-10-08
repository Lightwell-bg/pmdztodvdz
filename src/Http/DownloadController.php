<?php
/**
 * Скачивание PDF: `GET /?bgdvzh_download=<public_id>` на template_redirect.
 *
 * Порядок: владелец по cookie (сессия, пользователь, grant своей покупки)
 * → оплата по живому состоянию EDD → целостность снимка →
 * DocumentService::ensure(). Без доступа — страница с понятным текстом
 * без технических деталей.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Http;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Access\GrantManager;
use Bginfo\PmzhDvzh\Access\RequestContext;
use Bginfo\PmzhDvzh\Access\SessionManager;
use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Pdf\DocumentService;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Clock;

final class DownloadController {

	public const QUERY_VAR = 'bgdvzh_download';

	public function __construct(
		private SessionManager $sessions,
		private GrantManager $grants,
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private AccessPolicy $policy,
		private DocumentService $documents,
		private EventRepository $events
	) {
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybeServe' ), 5 );
	}

	public function maybeServe(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! is_string( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

		if ( 'GET' !== $method ) {
			$this->page( 405, 'Запрос не поддерживается.' );
		}

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';

		if ( '' !== $origin && ! Tokens::sameOrigin( $origin, null, home_url() ) ) {
			$this->page( 403, 'Запрос отклонён.' );
		}

		$publicId = strtolower( trim( (string) wp_unslash( $_GET[ self::QUERY_VAR ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$snapshot = Tokens::isWellFormed( $publicId, 32 ) ? $this->snapshots->findByPublicId( $publicId ) : null;

		if ( null === $snapshot ) {
			$this->page( 404, 'Документ не найден.' );
		}

		$purchases = $this->purchases->bySnapshot( (int) $snapshot['id'] );
		$ctx       = new RequestContext( $this->sessions->sessionId(), (int) get_current_user_id(), $this->grants->currentPurchaseIds() );
		$ctxOwner  = new RequestContext( $ctx->sessionId, $ctx->userId, array() );
		$isOwner   = AccessPolicy::isOwner( $snapshot, null, $ctxOwner->toArray() );

		$paid = null;

		foreach ( $purchases as $p ) {
			if ( ! $isOwner && ! AccessPolicy::isOwner( $snapshot, $p, $ctx->toArray() ) ) {
				continue;
			}

			if ( $this->policy->paymentState( $p )['access'] ) {
				$paid = $p;
				break;
			}
		}

		if ( null === $paid && $isOwner && ! empty( $purchases ) && ! $this->policy->available() ) {
			$this->page( 503, 'Инструкция временно недоступна. Попробуйте позже.' );
		}

		if ( null === $paid ) {
			// Не различаем «чужое» и «не оплачено», чтобы не раскрывать существование.
			$this->page( 403, 'Доступ к документу не подтверждён. Откройте страницу анкеты и убедитесь, что оплата прошла.' );
		}

		if ( ! empty( $snapshot['purged_at'] ) ) {
			$this->page( 410, 'Данные этой анкеты удалены.' );
		}

		if ( ! SnapshotRepository::hashMatches( $snapshot ) ) {
			$this->events->log( 'content_hash_mismatch', (int) $paid['id'], (int) $paid['order_id'], 'download' );
			$this->page( 500, 'Документ временно недоступен. Инструкция доступна на странице анкеты.' );
		}

		$res = $this->documents->ensure( $paid );

		if ( 'done' === $res['state'] && is_string( $res['pdf'] ) ) {
			$this->send( $res['pdf'], $snapshot );
		}

		if ( 'failed' === $res['state'] ) {
			$this->page( 500, 'Не удалось подготовить PDF. Инструкция доступна на странице анкеты, там же можно повторить создание документа.' );
		}

		// Генерация идёт: страница сама повторит запрос.
		$this->page( 202, 'Документ готовится. Страница обновится автоматически.', true );
	}

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function send( string $pdf, array $snapshot ): never {
		$ts   = Clock::toTimestamp( (string) $snapshot['created_at'] );
		$date = function_exists( 'wp_date' ) && $ts > 0 ? (string) wp_date( 'Y-m-d', $ts ) : gmdate( 'Y-m-d' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="instrukciya-dvzh-' . $date . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Cache-Control: no-store, private' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- бинарный PDF.
		exit;
	}

	private function page( int $status, string $message, bool $refresh = false ): never {
		status_header( $status );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Referrer-Policy: no-referrer' );

		if ( $refresh ) {
			header( 'Retry-After: 5' );
			header( 'Content-Type: text/html; charset=utf-8' );

			echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="5"><title>'
				. esc_html( 'Документ готовится' ) . '</title></head><body><p>' . esc_html( $message ) . '</p></body></html>';
			exit;
		}

		wp_die( esc_html( $message ), esc_html( 'Документ' ), array( 'response' => $status ) );
		exit;
	}
}
