<?php
/**
 * REST API `bgdvzh/v1` (ARCHITECTURE §4).
 *
 * Правила для каждого маршрута:
 *  - метод: изменяющие запросы только POST;
 *  - Origin/Referer: POST — только свой сайт; любой запрос с чужим Origin
 *    (в том числе GET) отклоняется;
 *  - CSRF: заголовок X-Bgdvzh-Csrf, токен привязан к серверной сессии и
 *    области действия (draft, submit, checkout/retry/send_link + public_id);
 *  - владелец: AccessPolicy::isOwner (сессия, пользователь user_id > 0,
 *    либо grant своей покупки); nonce WP не заменяет эту проверку;
 *  - платный текст отдаётся только после AccessPolicy::paymentState = paid
 *    по живому состоянию EDD и проверки content_hash снимка;
 *  - ответы с no-store, noindex, no-referrer.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Http;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Access\AdminAccess;
use Bginfo\PmzhDvzh\Access\GrantManager;
use Bginfo\PmzhDvzh\Access\RequestContext;
use Bginfo\PmzhDvzh\Access\ResultAccess;
use Bginfo\PmzhDvzh\Access\SessionManager;
use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Commerce\CartService;
use Bginfo\PmzhDvzh\Commerce\EddGate;
use Bginfo\PmzhDvzh\Commerce\OrderEvents;
use Bginfo\PmzhDvzh\Commerce\OrderReader;
use Bginfo\PmzhDvzh\Instruction\Composer;
use Bginfo\PmzhDvzh\Instruction\HtmlRenderer;
use Bginfo\PmzhDvzh\Instruction\TemplateStore;
use Bginfo\PmzhDvzh\Pdf\DocumentService;
use Bginfo\PmzhDvzh\Questionnaire\Validator;
use Bginfo\PmzhDvzh\Rules\DateMath;
use Bginfo\PmzhDvzh\Rules\Evaluator;
use Bginfo\PmzhDvzh\Rules\RulesMeta;
use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\DraftRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Json;
use Bginfo\PmzhDvzh\Support\RateLimiter;
use Bginfo\PmzhDvzh\Support\Settings;

final class RestController {

	public const NAMESPACE_ = 'bgdvzh/v1';
	public const CSRF_HEADER = 'x_bgdvzh_csrf';

	/** Сколько секунд заказ pending считается «ожидаем оплату». */
	public const PENDING_WINDOW = 1800;

	/** Порядок «важности» состояний при выборе показываемой покупки. */
	private const STATE_RANK = array(
		AccessPolicy::PAID     => 0,
		AccessPolicy::PENDING  => 1,
		AccessPolicy::DISPUTED => 2,
		AccessPolicy::REFUNDED => 3,
		AccessPolicy::REVOKED  => 4,
		AccessPolicy::FAILED   => 5,
		AccessPolicy::MISMATCH => 6,
		AccessPolicy::MISSING  => 7,
		AccessPolicy::UNAVAILABLE => 8,
	);

	public function __construct(
		private Settings $settings,
		private SessionManager $sessions,
		private GrantManager $grants,
		private DraftRepository $drafts,
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private DocumentRepository $documentRepo,
		private EventRepository $events,
		private AccessPolicy $policy,
		private OrderReader $reader,
		private EddGate $gate,
		private CartService $cart,
		private OrderEvents $orderEvents,
		private DocumentService $documents,
		private TemplateStore $templates,
		private HtmlRenderer $renderer
	) {
	}

	private ?ConsultController $consult = null;

	/** Подключает консультации: поле `consultations` в /state и /result. */
	public function setConsult( ConsultController $consult ): void {
		$this->consult = $consult;
	}

	/**
	 * Записи на консультацию по снимку для /state и /result.
	 *
	 * @param array<string,mixed> $snapshot
	 * @param list<int>|null      $allowedPurchaseIds null — владелец; список — только grant.
	 * @return list<array<string,mixed>>
	 */
	private function consultations( array $snapshot, ?array $allowedPurchaseIds ): array {
		return null === $this->consult ? array() : $this->consult->listForSnapshot( $snapshot, $allowedPurchaseIds );
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'privateHeaders' ), 10, 3 );
	}

	public function registerRoutes(): void {
		$routes = array(
			'/session'        => array( 'POST', 'session' ),
			'/state'          => array( 'GET', 'state' ),
			'/draft'          => array( 'POST', 'draft' ),
			'/submit'         => array( 'POST', 'submit' ),
			'/checkout'       => array( 'POST', 'checkout' ),
			'/result'         => array( 'GET', 'result' ),
			'/retry-document' => array( 'POST', 'retryDocument' ),
			'/send-link'      => array( 'POST', 'sendLink' ),
			'/grant'          => array( 'POST', 'grant' ),
		);

		foreach ( $routes as $path => $def ) {
			register_rest_route(
				self::NAMESPACE_,
				$path,
				array(
					'methods'             => $def[0],
					'callback'            => array( $this, $def[1] ),
					'permission_callback' => array( $this, 'guard' ),
				)
			);
		}
	}

	/**
	 * Заголовки ответов нашего пространства имён.
	 *
	 * @param mixed $result
	 * @param mixed $server
	 * @param mixed $request
	 * @return mixed
	 */
	public function privateHeaders( $result, $server = null, $request = null ) {
		if ( ! $request instanceof \WP_REST_Request || 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE_ ) ) {
			return $result;
		}

		if ( $result instanceof \WP_HTTP_Response ) {
			$result->header( 'Cache-Control', 'no-store, private' );
			$result->header( 'Pragma', 'no-cache' );
			$result->header( 'X-Robots-Tag', 'noindex' );
			$result->header( 'Referrer-Policy', 'no-referrer' );
			$result->header( 'X-Content-Type-Options', 'nosniff' );
		}

		return $result;
	}

	/**
	 * Метод и происхождение запроса (permission_callback всех маршрутов).
	 *
	 * @return true|\WP_Error
	 */
	public function guard( \WP_REST_Request $request ) {
		return self::originGuard( $request );
	}

	/**
	 * Общая проверка метода и происхождения (используется и ConsultController).
	 *
	 * @return true|\WP_Error
	 */
	public static function originGuard( \WP_REST_Request $request ) {
		$origin  = $request->get_header( 'origin' );
		$referer = $request->get_header( 'referer' );
		$home    = home_url();

		if ( 'POST' === $request->get_method() ) {
			$ok = Tokens::sameOrigin( $origin, $referer, $home );
		} elseif ( 'GET' === $request->get_method() ) {
			// У GET заголовка Origin может не быть, но чужой Origin отклоняется.
			$ok = null === $origin || '' === $origin || Tokens::sameOrigin( $origin, null, $home );
		} else {
			$ok = false;
		}

		if ( ! $ok ) {
			return new \WP_Error( 'bgdvzh_forbidden_origin', 'Запрос отклонён. Откройте страницу анкеты на сайте и повторите действие.', array( 'status' => 403 ) );
		}

		return true;
	}

	// ---------------------------------------------------------------------
	// Маршруты.

	public function session( \WP_REST_Request $request ): \WP_REST_Response {
		if ( null === $this->sessions->current() ) {
			if ( ! RateLimiter::hit( 'session', RateLimiter::ipSubject(), 20, HOUR_IN_SECONDS ) ) {
				return $this->error( 429, 'rate_limited', 'Слишком много попыток. Попробуйте через час.' );
			}

			if ( null === $this->sessions->ensure() ) {
				return $this->error( 500, 'session_failed', 'Не удалось начать сеанс. Обновите страницу и повторите.' );
			}
		}

		return $this->json( array( 'ok' => true ) );
	}

	public function state( \WP_REST_Request $request ): \WP_REST_Response {
		$session = $this->sessions->current();

		// Без сеанса (первый визит гостя) — пустое состояние со статусом 200, а не 401:
		// клиент начнёт сеанс только при первом сохранении, а консоль браузера останется чистой.
		$sid    = null === $session ? 0 : (int) $session['id'];
		$uid    = $this->userId();
		$draft  = $sid > 0 ? $this->drafts->get( $sid ) : null;
		$rows   = $sid > 0 ? $this->snapshots->listBySession( $sid, 20 ) : array();
		$byUser = $uid > 0 ? $this->snapshots->listByUser( $uid, 20 ) : array();
		$seen   = array();
		$list   = array();

		foreach ( array_merge( $rows, $byUser ) as $row ) {
			if ( isset( $seen[ $row['id'] ] ) || ! empty( $row['purged_at'] ) ) {
				continue;
			}

			$seen[ $row['id'] ] = true;
			$list[]             = $row;
		}

		usort( $list, static fn( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] );

		$out   = array();
		$admin = AdminAccess::can();

		foreach ( array_slice( $list, 0, 20 ) as $row ) {
			$pub   = (string) $row['public_id'];
			$best  = $this->bestPurchase( $this->purchases->bySnapshot( (int) $row['id'] ), null );
			$best['payment'] = ResultAccess::decide( true, false, $admin, $best['payment'] );
			$out[] = array(
				'id'         => $pub,
				'created_at' => $this->formatDate( (string) $row['created_at'] ),
				'status'     => (string) $row['status'],
				'preview'    => Json::decode( (string) ( $row['preview'] ?? '' ) ),
				'payment'    => $best['payment'],
				'csrf'       => $this->snapshotCsrf( $pub ),
				'consultations' => $this->consultations( $row, null ),
			);
		}

		// Снимки, доступные только по grant-cookie (без csrf на действия владельца).
		$granted   = array();
		$grantedBy = array();

		foreach ( $this->grants->currentPurchaseIds() as $pid ) {
			$p = $this->purchases->byId( (int) $pid );

			if ( null === $p ) {
				continue;
			}

			$grantedBy[ (int) $p['snapshot_id'] ][] = (int) $p['id'];
		}

		foreach ( $grantedBy as $snapId => $pids ) {
			$row = $this->snapshots->findById( (int) $snapId );

			if ( null === $row || ! empty( $row['purged_at'] ) || isset( $seen[ $row['id'] ] ) ) {
				continue;
			}

			$best      = $this->bestPurchase( $this->purchases->bySnapshot( (int) $row['id'] ), $pids );
			$granted[] = array(
				'id'         => (string) $row['public_id'],
				'created_at' => $this->formatDate( (string) $row['created_at'] ),
				'status'     => (string) $row['status'],
				'preview'    => Json::decode( (string) ( $row['preview'] ?? '' ) ),
				'payment'    => $best['payment'],
				'consultations' => $this->consultations( $row, $pids ),
			);
		}

		return $this->json(
			array(
				'granted'          => $granted,
				'csrf'             => $sid > 0 ? array(
					'draft'  => $this->sessions->csrfFor( 'draft' ),
					'submit' => $this->sessions->csrfFor( 'submit' ),
				) : array(),
				'draft'            => null === $draft ? null : array(
					'data'     => $draft['data'],
					'step'     => $draft['step'],
					'revision' => $draft['revision'],
				),
				'snapshots'        => $out,
				'price_html'       => $this->gate->priceHtml(),
				'purchase_enabled' => $this->gate->ok(),
				'privacy_url'      => (string) $this->settings->get( 'privacy_url', '' ),
				'today'            => Clock::today(),
				'offers'           => $this->templates->offers(),
			)
		);
	}

	public function draft( \WP_REST_Request $request ): \WP_REST_Response {
		$session = $this->sessions->current();

		if ( null === $session ) {
			return $this->error( 401, 'no_session', 'Сеанс не начат. Обновите страницу.' );
		}

		if ( ! $this->sessions->csrfValid( 'draft', '', $this->csrfHeader( $request ) ) ) {
			return $this->error( 403, 'bad_csrf', 'Сеанс устарел. Обновите страницу и повторите.' );
		}

		$body = $this->body( $request );
		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
		$step = isset( $body['step'] ) ? (int) $body['step'] : 0;
		$rev  = isset( $body['revision'] ) ? (int) $body['revision'] : 0;

		if ( strlen( (string) wp_json_encode( $data ) ) > 200 * 1024 ) {
			return $this->error( 413, 'too_large', 'Слишком большой объём данных.' );
		}

		$norm = Validator::normalizeDraft( $data, Clock::today() );
		$res  = $this->drafts->save( (int) $session['id'], $norm['answers'], $step, $rev );

		if ( ! $res['ok'] ) {
			$cur = $res['draft'];

			return $this->json(
				array(
					'code'    => 'revision_conflict',
					'message' => 'Черновик изменён в другой вкладке.',
					'draft'   => null === $cur ? null : array( 'data' => $cur['data'], 'step' => $cur['step'], 'revision' => $cur['revision'] ),
				),
				409
			);
		}

		return $this->json( array( 'revision' => (int) $res['draft']['revision'] ) );
	}

	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$session = $this->sessions->current();

		if ( null === $session ) {
			return $this->error( 401, 'no_session', 'Сеанс не начат. Обновите страницу.' );
		}

		if ( ! $this->sessions->csrfValid( 'submit', '', $this->csrfHeader( $request ) ) ) {
			return $this->error( 403, 'bad_csrf', 'Сеанс устарел. Обновите страницу и повторите.' );
		}

		$sid = (int) $session['id'];

		if ( ! RateLimiter::hit( 'submit', RateLimiter::subject( 'session|' . $sid ), 30, HOUR_IN_SECONDS ) ) {
			return $this->error( 429, 'rate_limited', 'Слишком много отправок. Попробуйте позже.' );
		}

		$body = $this->body( $request );
		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

		if ( strlen( (string) wp_json_encode( $data ) ) > 200 * 1024 ) {
			return $this->error( 413, 'too_large', 'Слишком большой объём данных.' );
		}

		$today = Clock::today();
		$norm  = Validator::normalize( $data, $today );

		if ( ! empty( $norm['errors'] ) ) {
			return $this->json(
				array(
					'code'    => 'validation',
					'message' => 'Проверьте отмеченные поля.',
					'errors'  => $norm['errors'],
				),
				422
			);
		}

		$answers = $norm['answers'];

		try {
			$tpl      = $this->templates->current();
			$settings = $this->settings->all();
			$result   = ( new Evaluator() )->evaluate(
				$answers,
				array(
					'today'              => $today,
					'income_min_monthly' => null === $settings['income_min_monthly'] ? null : (float) $settings['income_min_monthly'],
					'income_currency'    => (string) $settings['income_currency'],
					'income_verified_at' => (string) $settings['income_verified_at'],
				)
			);

			$composer    = new Composer(
				$tpl['data'],
				array_intersect_key( $settings, array_flip( array( 'fee_text', 'fee_verified_at', 'income_guide_text', 'income_verified_at' ) ) )
			);
			$preview     = $composer->preview( $result, $answers );
			$instruction = $composer->instruction( $answers, $result, $tpl['version'] );

			$snapshot = $this->snapshots->create(
				$sid,
				$this->userId(),
				$answers,
				$result,
				$preview,
				$instruction,
				$this->summary( $answers, $result, $tpl['data'] ),
				RulesMeta::VERSION,
				$tpl['version']
			);
		} catch ( \Throwable $e ) {
			$this->events->log( 'submit_failed', 0, 0, get_class( $e ) );

			return $this->error( 500, 'submit_failed', 'Не удалось построить результат. Повторите попытку чуть позже.' );
		}

		if ( null === $snapshot ) {
			return $this->error( 500, 'submit_failed', 'Не удалось сохранить результат. Повторите попытку чуть позже.' );
		}

		// Черновик остаётся и обновляется; конфликт версий здесь не критичен.
		if ( isset( $body['revision'] ) ) {
			$this->drafts->save( $sid, $answers, isset( $body['step'] ) ? (int) $body['step'] : 0, (int) $body['revision'] );
		}

		return $this->json(
			array(
				'id'      => (string) $snapshot['public_id'],
				'preview' => $preview,
				'csrf'    => $this->snapshotCsrf( (string) $snapshot['public_id'] ),
			)
		);
	}

	public function checkout( \WP_REST_Request $request ): \WP_REST_Response {
		$publicId = $this->idParam( $request );
		$snapshot = '' === $publicId ? null : $this->snapshots->findByPublicId( $publicId );

		if ( null === $snapshot || ! $this->isSessionOrUserOwner( $snapshot ) ) {
			return $this->error( 404, 'not_found', 'Анкета не найдена.' );
		}

		if ( ! $this->sessions->csrfValid( 'checkout', $publicId, $this->csrfHeader( $request ) ) ) {
			return $this->error( 403, 'bad_csrf', 'Сеанс устарел. Обновите страницу и повторите.' );
		}

		if ( ! empty( $snapshot['purged_at'] ) ) {
			return $this->error( 410, 'purged', 'Данные этой анкеты удалены. Заполните анкету заново.' );
		}

		if ( ! $this->gate->ok() ) {
			return $this->error( 503, 'unavailable', 'Оплата временно недоступна. Попробуйте позже.' );
		}

		foreach ( $this->purchases->bySnapshot( (int) $snapshot['id'] ) as $purchase ) {
			$st = $this->policy->paymentState( $purchase );

			if ( $st['access'] ) {
				return $this->json( array( 'code' => 'already_paid', 'message' => 'Эта инструкция уже оплачена.' ), 409 );
			}

			if ( in_array( $st['order_status'], array( 'processing', 'on_hold' ), true ) ) {
				return $this->json( array( 'code' => 'in_progress', 'message' => 'Ожидаем подтверждения оплаты.' ), 409 );
			}
		}

		$this->snapshots->lock( (int) $snapshot['id'] );

		if ( ! $this->cart->replaceWithSnapshot( $snapshot ) ) {
			return $this->error( 500, 'cart_failed', 'Не удалось перейти к оплате. Повторите попытку.' );
		}

		return $this->json( array( 'redirect' => (string) edd_get_checkout_uri() ) );
	}

	public function result( \WP_REST_Request $request ): \WP_REST_Response {
		$publicId = $this->idParam( $request );
		$snapshot = '' === $publicId ? null : $this->snapshots->findByPublicId( $publicId );

		if ( null === $snapshot ) {
			return $this->error( 404, 'not_found', 'Анкета не найдена.' );
		}

		$purchases = $this->purchases->bySnapshot( (int) $snapshot['id'] );
		$ctx       = $this->context();
		$isOwner   = $this->isSessionOrUserOwner( $snapshot );
		$allowed   = null;

		if ( ! $isOwner ) {
			// Только grant: открывает свою покупку и ничего больше.
			$allowed = array();

			foreach ( $purchases as $p ) {
				if ( AccessPolicy::isOwner( $snapshot, $p, $ctx->toArray() ) ) {
					$allowed[] = (int) $p['id'];
				}
			}

			if ( empty( $allowed ) ) {
				return $this->error( 404, 'not_found', 'Анкета не найдена.' );
			}
		}

		if ( ! empty( $snapshot['purged_at'] ) ) {
			return $this->error( 410, 'purged', 'Данные этой анкеты удалены.' );
		}

		$best = $this->bestPurchase( $purchases, $allowed );
		$mode = ResultAccess::decide( $isOwner, ! $isOwner, AdminAccess::can(), $best['payment'] );
		$out  = array(
			'id'      => $publicId,
			'preview' => Json::decode( (string) $snapshot['preview'] ),
			'payment' => $mode,
			'consultations' => $this->consultations( $snapshot, $isOwner ? null : (array) $allowed ),
		);

		if ( $isOwner ) {
			$out['csrf'] = $this->snapshotCsrf( $publicId );
		}

		$purchase = $best['purchase'];

		if ( null !== $purchase && $best['state'] !== null ) {
			// Кэш подтягиваем к живому состоянию (на случай пропущенного хука).
			if ( AccessPolicy::UNAVAILABLE !== $best['payment'] && (string) $purchase['state'] !== $best['payment'] ) {
				$this->orderEvents->syncPurchase( $purchase, 'result' );
			}
		}

		if ( AccessPolicy::PAID === $best['payment'] && null !== $purchase ) {
			if ( ! SnapshotRepository::hashMatches( $snapshot ) ) {
				$this->events->log( 'content_hash_mismatch', (int) $purchase['id'], (int) $purchase['order_id'], 'result' );
				$out['payment'] = AccessPolicy::MISMATCH;

				return $this->json( $out );
			}

			$out['instruction_html'] = $this->renderer->render( Json::decode( (string) $snapshot['instruction'] ) );
			$out['document']         = array(
				'state'        => $this->documents->state( (int) $purchase['id'] ),
				'download_url' => $this->downloadUrl( $publicId ),
			);

			if ( 'pending' === $out['document']['state'] ) {
				$this->orderEvents->queueDocument( (int) $purchase['id'] );

				// Генерация без cron: JS один раз просит generate=1. ensure() сам
				// атомарно захватывает задачу, параллельные запросы не дублируют работу.
				if ( '1' === (string) $request->get_param( 'generate' ) ) {
					$res                      = $this->documents->ensure( $purchase );
					$out['document']['state'] = $res['state'];
				}
			}
		}

		if ( ResultAccess::ADMIN === $mode ) {
			// Бесплатный доступ администратора: инструкция открыта без оплаты, PDF строится «на лету».
			if ( ! SnapshotRepository::hashMatches( $snapshot ) ) {
				$this->events->log( 'content_hash_mismatch', 0, 0, 'result_admin' );
				$out['payment'] = AccessPolicy::MISMATCH;

				return $this->json( $out );
			}

			$out['instruction_html'] = $this->renderer->render( Json::decode( (string) $snapshot['instruction'] ) );
			$out['document']         = array(
				'state'        => 'done',
				'download_url' => add_query_arg( '_wpnonce', wp_create_nonce( 'bgdvzh_dl_' . $publicId ), $this->downloadUrl( $publicId ) ),
				'admin'        => true,
			);
		}

		return $this->json( $out );
	}

	public function retryDocument( \WP_REST_Request $request ): \WP_REST_Response {
		$publicId = $this->idParam( $request );
		$snapshot = '' === $publicId ? null : $this->snapshots->findByPublicId( $publicId );

		if ( null === $snapshot || ! $this->isSessionOrUserOwner( $snapshot ) ) {
			return $this->error( 404, 'not_found', 'Анкета не найдена.' );
		}

		if ( ! $this->sessions->csrfValid( 'retry', $publicId, $this->csrfHeader( $request ) ) ) {
			return $this->error( 403, 'bad_csrf', 'Сеанс устарел. Обновите страницу и повторите.' );
		}

		$best = $this->bestPurchase( $this->purchases->bySnapshot( (int) $snapshot['id'] ), null );

		if ( AccessPolicy::PAID !== $best['payment'] || null === $best['purchase'] || ! empty( $snapshot['purged_at'] ) ) {
			return $this->error( 403, 'not_paid', 'Документ доступен только после подтверждения оплаты.' );
		}

		$pid = (int) $best['purchase']['id'];

		$this->documentRepo->ensureRow( $pid );
		$this->documentRepo->resetFailed( $pid, 60 );
		$this->orderEvents->queueDocument( $pid );

		return $this->json(
			array(
				'document' => array(
					'state'        => $this->documents->state( $pid ),
					'download_url' => $this->downloadUrl( $publicId ),
				),
			)
		);
	}

	public function sendLink( \WP_REST_Request $request ): \WP_REST_Response {
		$publicId = $this->idParam( $request );
		$snapshot = '' === $publicId ? null : $this->snapshots->findByPublicId( $publicId );

		if ( null === $snapshot || ! $this->isSessionOrUserOwner( $snapshot ) ) {
			return $this->error( 404, 'not_found', 'Анкета не найдена.' );
		}

		if ( ! $this->sessions->csrfValid( 'send_link', $publicId, $this->csrfHeader( $request ) ) ) {
			return $this->error( 403, 'bad_csrf', 'Сеанс устарел. Обновите страницу и повторите.' );
		}

		$best     = $this->bestPurchase( $this->purchases->bySnapshot( (int) $snapshot['id'] ), null );
		$purchase = $best['purchase'];

		if ( AccessPolicy::PAID !== $best['payment'] || null === $purchase || ! empty( $snapshot['purged_at'] ) ) {
			return $this->error( 403, 'not_paid', 'Ссылка доступна только после подтверждения оплаты.' );
		}

		if ( ! RateLimiter::hit( 'send_link', RateLimiter::subject( 'purchase|' . (int) $purchase['id'] ), 3, DAY_IN_SECONDS ) ) {
			return $this->error( 429, 'rate_limited', 'Ссылку можно отправлять не чаще трёх раз в сутки.' );
		}

		$page  = $this->settings->pageUrl();
		$order = $this->reader->order( (int) $purchase['order_id'] );

		if ( '' === $page || null === $order || ! is_email( $order['email'] ) ) {
			return $this->error( 500, 'mail_failed', 'Не удалось отправить письмо. Обратитесь в поддержку сайта.' );
		}

		$token = $this->grants->issueLink( $purchase );

		if ( null === $token ) {
			return $this->error( 500, 'mail_failed', 'Не удалось подготовить ссылку. Повторите позже.' );
		}

		$url  = $page . '#dvzh-k=' . $token;
		$days = (int) $this->settings->get( 'link_ttl_days', 30 );
		$text = "Ваша персональная инструкция по переходу на ДВЖ доступна по ссылке (действует {$days} дн.):\n\n{$url}\n\nЕсли вы не запрашивали это письмо, просто проигнорируйте его.";
		$sent = wp_mail( $order['email'], 'Ссылка на вашу персональную инструкцию', $text );

		$this->events->log( $sent ? 'link_mailed' : 'link_mail_failed', (int) $purchase['id'], (int) $purchase['order_id'], '' );

		if ( ! $sent ) {
			return $this->error( 502, 'mail_failed', 'Не удалось отправить письмо. Повторите позже.' );
		}

		return $this->json( array( 'ok' => true, 'message' => 'Ссылка отправлена на email, указанный при оформлении заказа.' ) );
	}

	public function grant( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! RateLimiter::hit( 'grant', RateLimiter::ipSubject(), 10, 10 * MINUTE_IN_SECONDS ) ) {
			return $this->error( 429, 'rate_limited', 'Слишком много попыток. Попробуйте через несколько минут.' );
		}

		$body  = $this->body( $request );
		$token = isset( $body['token'] ) && is_string( $body['token'] ) ? trim( $body['token'] ) : '';
		$kind  = isset( $body['kind'] ) && is_string( $body['kind'] ) ? $body['kind'] : 'link';
		$res   = Tokens::isWellFormed( $token ) ? $this->grants->exchangeFull( $token, $kind ) : null;

		if ( null === $res ) {
			return $this->error( 404, 'invalid_link', 'Ссылка недействительна или устарела. Запросите новую на странице анкеты или в письме с заказом.' );
		}

		// Для kind = booking дополнительно возвращается public_id записи.
		return $this->json( null === $res['booking'] ? array( 'id' => $res['id'] ) : array( 'id' => $res['id'], 'booking' => $res['booking'] ) );
	}

	// ---------------------------------------------------------------------
	// Вспомогательное.

	public function context(): RequestContext {
		return new RequestContext( $this->sessions->sessionId(), $this->userId(), $this->grants->currentPurchaseIds() );
	}

	private function userId(): int {
		return (int) get_current_user_id();
	}

	/**
	 * Владелец по сессии/пользователю (grant не учитывается).
	 *
	 * @param array<string,mixed> $snapshot
	 */
	private function isSessionOrUserOwner( array $snapshot ): bool {
		$ctx = new RequestContext( $this->sessions->sessionId(), $this->userId(), array() );

		return AccessPolicy::isOwner( $snapshot, null, $ctx->toArray() );
	}

	/**
	 * Лучшая (по важности) покупка снимка по живому состоянию EDD.
	 *
	 * @param list<array<string,mixed>> $purchases
	 * @param list<int>|null            $allowedIds null — любые.
	 * @return array{payment:string,purchase:?array<string,mixed>,state:?array<string,mixed>}
	 */
	private function bestPurchase( array $purchases, ?array $allowedIds ): array {
		$best = array( 'payment' => 'none', 'purchase' => null, 'state' => null );
		$rank = 99;

		foreach ( $purchases as $p ) {
			if ( null !== $allowedIds && ! in_array( (int) $p['id'], $allowedIds, true ) ) {
				continue;
			}

			$st = $this->policy->paymentState( $p );

			// Брошенный checkout (заказ pending давно) не показываем как «ожидаем оплату».
			if ( ! self::isPendingReportable( $st['state'], $st['order_status'], (string) ( $p['created_at'] ?? '' ), Clock::timestamp() ) ) {
				continue;
			}

			$r = self::STATE_RANK[ $st['state'] ] ?? 98;

			if ( $r < $rank ) {
				$rank = $r;
				$best = array( 'payment' => $st['state'], 'purchase' => $p, 'state' => $st );
			}
		}

		return $best;
	}

	/**
	 * Можно ли показывать покупку клиенту с данным состоянием.
	 * Состояние pending показывается, только если заказ в статусе processing,
	 * либо в статусе pending и покупка создана не более PENDING_WINDOW секунд
	 * назад; иначе (брошенный checkout) оплата считается отсутствующей.
	 * Остальные состояния показываются всегда.
	 */
	public static function isPendingReportable( string $state, string $orderStatus, string $createdAtUtc, int $now ): bool {
		if ( AccessPolicy::PENDING !== $state || 'processing' === $orderStatus ) {
			return true;
		}

		$created = Clock::toTimestamp( $createdAtUtc );

		return 'pending' === $orderStatus && $created > 0 && ( $now - $created ) <= self::PENDING_WINDOW;
	}

	/** @return array{checkout:string,retry:string,send_link:string,consult_hold:string} */
	private function snapshotCsrf( string $publicId ): array {
		return array(
			'checkout'  => $this->sessions->csrfFor( 'checkout', $publicId ),
			'retry'     => $this->sessions->csrfFor( 'retry', $publicId ),
			'send_link' => $this->sessions->csrfFor( 'send_link', $publicId ),
			'consult_hold' => $this->sessions->csrfFor( 'consult_hold', $publicId ),
		);
	}

	/**
	 * Короткая строка для списка в админке: статус, регион, дата подачи.
	 * Без гражданства и имён.
	 *
	 * @param array<string,mixed> $answers
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $templates
	 */
	private function summary( array $answers, array $result, array $templates ): string {
		$status = (string) $result['status'];
		$title  = (string) ( $templates['status_titles'][ $status ] ?? $status );
		$region = trim( (string) ( $answers['housing']['region'] ?? '' ) );
		$filing = DateMath::human( DateMath::parse( (string) $result['filing_date'] ) );

		return mb_substr( $title . '; ' . ( '' !== $region ? $region : 'регион не указан' ) . '; подача ' . $filing, 0, 255 );
	}

	private function formatDate( string $utc ): string {
		$ts = Clock::toTimestamp( $utc );

		return 0 === $ts ? '' : (string) wp_date( 'd.m.Y H:i', $ts );
	}

	private function downloadUrl( string $publicId ): string {
		return add_query_arg( DownloadController::QUERY_VAR, $publicId, home_url( '/' ) );
	}

	private function idParam( \WP_REST_Request $request ): string {
		$id = $request->get_param( 'id' );

		if ( null === $id ) {
			$id = $this->body( $request )['id'] ?? '';
		}

		$id = is_string( $id ) ? strtolower( trim( $id ) ) : '';

		return Tokens::isWellFormed( $id, 32 ) ? $id : '';
	}

	private function csrfHeader( \WP_REST_Request $request ): string {
		$h = $request->get_header( self::CSRF_HEADER );

		return is_string( $h ) ? trim( $h ) : '';
	}

	/** @return array<string,mixed> */
	private function body( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();

		if ( is_array( $json ) ) {
			return $json;
		}

		$form = $request->get_body_params();

		return is_array( $form ) ? $form : array();
	}

	/** @param array<string,mixed> $data */
	private function json( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	private function error( int $status, string $code, string $message ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'code' => $code, 'message' => $message ), $status );
	}
}
