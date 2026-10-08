<?php
/**
 * REST `bgdvzh/v1/consult/*` (docs/CONSULTATIONS.md §6).
 *
 * Те же правила, что у RestController: общий guard (метод и Origin),
 * ответы no-store (фильтр rest_post_dispatch RestController покрывает всё
 * пространство имён), CSRF сессии со scope, владелец — сессия или пользователь
 * (user_id > 0), grant kind = 'booking' открывает только свою запись.
 *
 * Наружу не уходят: admin_notes, служебные поля, данные чужих записей.
 * Цены берутся только из EDD; от клиента принимается лишь формат.
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
use Bginfo\PmzhDvzh\Booking\BookingService;
use Bginfo\PmzhDvzh\Booking\BookingSync;
use Bginfo\PmzhDvzh\Booking\ContactValidator;
use Bginfo\PmzhDvzh\Booking\CreditService;
use Bginfo\PmzhDvzh\Commerce\CartService;
use Bginfo\PmzhDvzh\Commerce\ConsultGate;
use Bginfo\PmzhDvzh\Commerce\CreditCalculator;
use Bginfo\PmzhDvzh\Instruction\TemplateStore;
use Bginfo\PmzhDvzh\Schedule\SlotGenerator;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\Schema;
use Bginfo\PmzhDvzh\Storage\SlotRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\RateLimiter;
use Bginfo\PmzhDvzh\Support\Settings;

final class ConsultController {

	public const LABELS = array(
		'telegram' => 'Telegram',
		'whatsapp' => 'WhatsApp',
		'phone'    => 'Телефонный звонок',
		'meet'     => 'Google Meet',
		'email'    => 'Письменный разбор по email',
	);

	/** Ошибки BookingService → [HTTP-статус, код, текст]. */
	private const ERRORS = array(
		'format_unavailable'  => array( 422, 'format_unavailable', 'Этот способ консультации сейчас недоступен. Выберите другой.' ),
		'schedule_invalid'    => array( 503, 'unavailable', 'Запись на консультацию временно недоступна. Попробуйте позже.' ),
		'time_unavailable'    => array( 409, 'time_unavailable', 'Это время уже недоступно. Выберите другое время.' ),
		'time_taken'          => array( 409, 'time_taken', 'Это время только что заняли. Выберите другое время.' ),
		'date_unavailable'    => array( 409, 'date_unavailable', 'Эта дата недоступна. Выберите другую дату.' ),
		'date_full'           => array( 409, 'date_full', 'На эту дату мест больше нет. Выберите другую дату.' ),
		'credit_busy'         => array( 409, 'credit_busy', 'Зачёт инструкции сейчас используется в другой записи. Обновите страницу и проверьте цену.' ),
		'payment_in_progress' => array( 409, 'in_progress', 'Оплата предыдущей записи ещё идёт. Дождитесь подтверждения.' ),
		'storage'             => array( 500, 'storage', 'Не удалось сохранить запись. Повторите попытку чуть позже.' ),
	);

	public function __construct(
		private Settings $settings,
		private SessionManager $sessions,
		private GrantManager $grants,
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private BookingRepository $bookings,
		private SlotRepository $slots,
		private BookingService $service,
		private BookingSync $sync,
		private CreditService $creditService,
		private ConsultGate $gate,
		private CartService $cart,
		private AccessPolicy $policy,
		private EventRepository $events
	) {
	}

	private ?TemplateStore $templates = null;

	/** Подключает тексты карточек (шаблоны `offers`) для /consult/options. */
	public function setTemplates( TemplateStore $templates ): void {
		$this->templates = $templates;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$routes = array(
			'/consult/options'      => array( 'GET', 'options' ),
			'/consult/availability' => array( 'GET', 'availability' ),
			'/consult/hold'         => array( 'POST', 'hold' ),
			'/consult/checkout'     => array( 'POST', 'checkout' ),
			'/consult/booking'      => array( 'GET', 'booking' ),
			'/consult/ics'          => array( 'GET', 'ics' ),
		);

		foreach ( $routes as $path => $def ) {
			register_rest_route(
				RestController::NAMESPACE_,
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
	 * Тот же guard, что у RestController (метод и Origin), плюс готовность схемы БД.
	 *
	 * @return true|\WP_Error
	 */
	public function guard( \WP_REST_Request $request ) {
		$ok = RestController::originGuard( $request );

		if ( true !== $ok ) {
			return $ok;
		}

		if ( ! Schema::ready() ) {
			return new \WP_Error( 'bgdvzh_unavailable', 'Запись на консультацию временно недоступна. Попробуйте позже.', array( 'status' => 503 ) );
		}

		return true;
	}

	// -----------------------------------------------------------------
	// GET /consult/options

	public function options( \WP_REST_Request $request ): \WP_REST_Response {
		$snapshot = $this->ownedSnapshot( $request );

		if ( null === $snapshot ) {
			return $this->error( 404, 'not_found', 'Анкета не найдена.' );
		}

		if ( ! empty( $snapshot['purged_at'] ) ) {
			return $this->error( 410, 'purged', 'Данные этой анкеты удалены. Заполните анкету заново.' );
		}

		$reason = $this->gate->reason();

		if ( null !== $reason ) {
			return $this->json( array( 'enabled' => false, 'reason_public' => 'Консультации сейчас недоступны.', 'formats' => array() ) );
		}

		$cfg      = $this->gate->config();
		$now      = Clock::timestamp();
		$sid      = $this->sessions->sessionId();
		$own      = $sid > 0 ? $this->bookings->activeHold( $sid, (int) $snapshot['id'], $now ) : null;
		$ownId    = null === $own ? 0 : (int) $own['id'];
		$currency = $this->gate->currency();
		$taxMode  = $this->gate->taxMode();
		$formats  = array();
		$first    = null;

		foreach ( $this->gate->enabledFormats() as $format ) {
			$base = $this->gate->priceFor( $format );

			if ( null === $base ) {
				continue;
			}

			$credit = $this->creditService->eligibility( (int) $snapshot['id'], true, $currency, $taxMode, (float) $base, $ownId, $now );
			$price  = $this->priceBlock( $base, $credit['eligible'] ? $credit['amount'] : '0.00', $credit['item'], $currency, $taxMode );
			$first  = $first ?? $price;
			$sync   = in_array( $format, BookingService::SYNC_FORMATS, true );
			$entry  = array(
				'key'            => $format,
				'label'          => self::LABELS[ $format ] ?? $format,
				'sync'           => $sync,
				'modes'          => in_array( $format, array( 'telegram', 'whatsapp' ), true ) ? array( 'text', 'call' ) : array( $sync ? 'call' : 'written' ),
				'initiator_text' => (string) ( $cfg['formats'][ $format ]['initiator_text'] ?? '' ),
				'price'          => $price,
			);

			if ( $sync ) {
				$entry['duration_min'] = (int) $cfg['duration_min'];
			} else {
				$entry['written'] = array(
					'scope'         => (string) $cfg['written_scope'],
					'followups'     => (int) $cfg['written_followups'],
					'followup_days' => (int) $cfg['written_followup_days'],
					'business_days' => (int) $cfg['written_business_days'],
				);
			}

			$formats[] = $entry;
		}

		if ( empty( $formats ) ) {
			return $this->json( array( 'enabled' => false, 'reason_public' => 'Консультации сейчас недоступны.', 'formats' => array() ) );
		}

		$photoId  = (int) ( $cfg['consultant']['photo_id'] ?? 0 );
		$photoUrl = $photoId > 0 && function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $photoId, 'medium' ) : '';
		$paid     = false;

		foreach ( $this->purchases->bySnapshot( (int) $snapshot['id'] ) as $p ) {
			if ( 'instruction' === (string) ( $p['kind'] ?? 'instruction' ) && $this->policy->paymentState( $p )['access'] ) {
				$paid = true;
				break;
			}
		}

		return $this->json(
			array(
				'enabled'                  => true,
				'formats'                  => $formats,
				'consultant'               => array(
					'name'       => (string) ( $cfg['consultant']['name'] ?? '' ),
					'experience' => (string) ( $cfg['consultant']['experience'] ?? '' ),
					'photo_url'  => is_string( $photoUrl ) ? $photoUrl : '',
				),
				'price'                    => $first,
				'recommended'              => in_array( (string) $snapshot['status'], array( 'check', 'obstacle' ), true ),
				'already_paid_instruction' => $paid,
				'consultant_tz'            => (string) $cfg['tz'],
				'offers'                   => null === $this->templates ? array() : $this->templates->offers(),
				'hold_minutes'             => (int) $cfg['hold_minutes'],
				'csrf'                     => array( 'hold' => $this->sessions->csrfFor( 'consult_hold', (string) $snapshot['public_id'] ) ),
			)
		);
	}

	// -----------------------------------------------------------------
	// GET /consult/availability

	public function availability( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->gate->ok() ) {
			return $this->error( 503, 'unavailable', 'Запись на консультацию временно недоступна.' );
		}

		if ( ! RateLimiter::hit( 'consult_av', RateLimiter::ipSubject(), 90, 10 * MINUTE_IN_SECONDS ) ) {
			return $this->error( 429, 'rate_limited', 'Слишком много запросов. Попробуйте через несколько минут.' );
		}

		$format = strtolower( trim( (string) $request->get_param( 'format' ) ) );

		if ( ! in_array( $format, $this->gate->enabledFormats(), true ) ) {
			return $this->error( 422, 'format_unavailable', 'Этот способ консультации сейчас недоступен.' );
		}

		$schedule = $this->gate->scheduleConfig();
		$gen      = new SlotGenerator( $schedule );
		$now      = Clock::timestamp();
		$days     = max( 1, min( 31, (int) ( $request->get_param( 'days' ) ?: 14 ) ) );
		$from     = $this->fromDate( $request->get_param( 'from' ), $schedule->tz, $now );
		$to       = ( new \DateTimeImmutable( $from . ' 12:00:00', $schedule->tz ) )->modify( '+' . $days . ' day' )->format( 'Y-m-d' );
		$written  = in_array( $format, BookingService::WRITTEN_FORMATS, true );
		$out      = array( 'format' => $format, 'kind' => $written ? 'written' : 'sync', 'consultant_tz' => $schedule->tz->getName(), 'from' => $from, 'days' => $days );

		if ( $written ) {
			$limit = max( 0, (int) ( $this->gate->config()['written_per_day'] ?? 2 ) );
			$all   = array();

			foreach ( $gen->writtenDates( $now ) as $date ) {
				if ( $this->slots->seatsUsed( $date, $now ) >= $limit ) {
					continue;
				}

				$deadline = $gen->writtenDeadline( $date );

				if ( null !== $deadline ) {
					$all[] = array( 'date' => $date, 'deadline_utc' => $deadline );
				}
			}

			$out['dates']      = array_values( array_filter( $all, static fn( array $d ): bool => $d['date'] >= $from && $d['date'] < $to ) );
			$out['next_dates'] = empty( $out['dates'] ) ? array_slice( array_values( array_filter( $all, static fn( array $d ): bool => $d['date'] >= $to ) ), 0, 5 ) : array();

			return $this->json( $out );
		}

		$candidates = $gen->candidates( $now );
		$busy       = array();

		if ( ! empty( $candidates ) ) {
			$busy = $this->slots->busyUnits( $candidates[0]['start'] - 3600, $candidates[ count( $candidates ) - 1 ]['block_end'] + 3600, $now );
		}

		$slots = array();
		$next  = array();

		foreach ( $candidates as $c ) {
			if ( ! SlotGenerator::isFree( $c, $busy ) ) {
				continue;
			}

			if ( $c['date'] >= $from && $c['date'] < $to ) {
				$slots[] = array( 'start_utc' => $c['start'], 'date_local' => $c['date'] );
			} elseif ( $c['date'] >= $to && count( $next ) < 5 && ! in_array( $c['date'], $next, true ) ) {
				$next[] = $c['date'];
			}
		}

		$out['slots']      = $slots;
		$out['next_dates'] = empty( $slots ) ? $next : array();

		return $this->json( $out );
	}

	// -----------------------------------------------------------------
	// POST /consult/hold

	public function hold( \WP_REST_Request $request ): \WP_REST_Response {
		$snapshot = $this->ownedSnapshot( $request );

		if ( null === $snapshot ) {
			return $this->error( 404, 'not_found', 'Анкета не найдена.' );
		}

		if ( ! $this->sessions->csrfValid( 'consult_hold', (string) $snapshot['public_id'], $this->csrfHeader( $request ) ) ) {
			return $this->error( 403, 'bad_csrf', 'Сеанс устарел. Обновите страницу и повторите.' );
		}

		if ( ! empty( $snapshot['purged_at'] ) ) {
			return $this->error( 410, 'purged', 'Данные этой анкеты удалены. Заполните анкету заново.' );
		}

		if ( ! $this->gate->ok() ) {
			return $this->error( 503, 'unavailable', 'Запись на консультацию временно недоступна. Попробуйте позже.' );
		}

		$sid = $this->sessions->sessionId();

		if (
			! RateLimiter::hit( 'consult_hold_s', RateLimiter::subject( 'session|' . $sid ), 5, HOUR_IN_SECONDS )
			|| ! RateLimiter::hit( 'consult_hold_ip', RateLimiter::ipSubject(), 20, HOUR_IN_SECONDS )
		) {
			return $this->error( 429, 'rate_limited', 'Слишком много попыток записи. Попробуйте позже.' );
		}

		$body   = $this->body( $request );
		$format = isset( $body['format'] ) && is_string( $body['format'] ) ? strtolower( trim( $body['format'] ) ) : '';

		if ( ! in_array( $format, $this->gate->enabledFormats(), true ) ) {
			return $this->error( 422, 'format_unavailable', 'Этот способ консультации сейчас недоступен. Выберите другой.' );
		}

		$errors  = array();
		$email   = isset( $body['email'] ) && is_string( $body['email'] ) ? trim( $body['email'] ) : '';
		$contact = ContactValidator::contact( $format, isset( $body['contact'] ) && is_string( $body['contact'] ) ? $body['contact'] : '', $email );
		$q       = ContactValidator::question( isset( $body['question'] ) && is_string( $body['question'] ) ? $body['question'] : '' );
		$tz      = isset( $body['visitor_tz'] ) && is_string( $body['visitor_tz'] ) ? trim( $body['visitor_tz'] ) : '';

		if ( ! ContactValidator::validEmail( $email ) ) {
			$errors['email'] = 'Укажите корректный адрес email.';
		}

		if ( ! $contact['ok'] ) {
			$errors['contact'] = $contact['error'];
		}

		if ( ! $q['ok'] ) {
			$errors['question'] = $q['error'];
		}

		if ( ! ContactValidator::visitorTz( $tz ) ) {
			$errors['visitor_tz'] = 'Не удалось определить ваш часовой пояс.';
		}

		$mode = isset( $body['mode'] ) && is_string( $body['mode'] ) ? $body['mode'] : '';

		if ( in_array( $format, array( 'telegram', 'whatsapp' ), true ) && ! in_array( $mode, array( 'text', 'call' ), true ) ) {
			$errors['mode'] = 'Выберите режим: переписка или звонок.';
		}

		$written = in_array( $format, BookingService::WRITTEN_FORMATS, true );

		if ( $written && ( ! isset( $body['date'] ) || ! is_string( $body['date'] ) ) ) {
			$errors['date'] = 'Выберите дату.';
		}

		if ( ! $written && ( ! isset( $body['start_utc'] ) || ! is_numeric( $body['start_utc'] ) ) ) {
			$errors['start_utc'] = 'Выберите время.';
		}

		if ( ! empty( $errors ) ) {
			return $this->json( array( 'code' => 'validation', 'message' => 'Проверьте отмеченные поля.', 'errors' => $errors ), 422 );
		}

		$base = $this->gate->priceFor( $format );

		if ( null === $base ) {
			return $this->error( 503, 'unavailable', 'Цена консультации сейчас недоступна. Попробуйте позже.' );
		}

		$now      = Clock::timestamp();
		$prev     = $this->bookings->activeHold( $sid, (int) $snapshot['id'], $now );
		$currency = $this->gate->currency();
		$taxMode  = $this->gate->taxMode();
		$credit   = $this->creditService->eligibility( (int) $snapshot['id'], true, $currency, $taxMode, (float) $base, null === $prev ? 0 : (int) $prev['id'], $now );

		$res = $this->service->hold(
			array(
				'format'           => $format,
				'mode'             => $mode,
				'start_utc'        => $written ? 0 : (int) $body['start_utc'],
				'date'             => $written ? (string) $body['date'] : '',
				'contact'          => $contact['contact'],
				'email'            => $email,
				'question'         => $q['question'],
				'visitor_tz'       => $tz,
				'snapshot_id'      => (int) $snapshot['id'],
				'session_id'       => $sid,
				'user_id'          => (int) get_current_user_id(),
				'prev_order_alive' => null !== $prev && $this->sync->orderAlive( $prev, $now ),
				'price'            => array(
					'base'     => $base,
					'item'     => $credit['item'],
					'currency' => $currency,
					'tax_mode' => $taxMode,
					'price_id' => $this->gate->priceIdFor( $format ),
				),
				'credit'           => $credit['eligible'] ? array( 'instruction_purchase_id' => $credit['instruction_purchase_id'], 'amount' => $credit['amount'] ) : null,
			),
			$now
		);

		if ( ! $res['ok'] ) {
			$err = self::ERRORS[ $res['error'] ?? 'storage' ] ?? self::ERRORS['storage'];

			return $this->error( $err[0], $err[1], $err[2] );
		}

		$booking = $res['booking'];

		return $this->json(
			array(
				'booking' => $this->bookingView( $booking, $snapshot ),
				'csrf'    => $this->bookingCsrf( (string) $booking['public_id'] ),
			)
		);
	}

	// -----------------------------------------------------------------
	// POST /consult/checkout

	public function checkout( \WP_REST_Request $request ): \WP_REST_Response {
		$booking = $this->bookingParam( $request );
		$snap    = null === $booking ? null : $this->snapshots->findById( (int) $booking['snapshot_id'] );

		if ( null === $booking || null === $snap || ! $this->isSnapshotOwner( $snap ) ) {
			return $this->error( 404, 'not_found', 'Запись не найдена.' );
		}

		if ( ! $this->sessions->csrfValid( 'consult_checkout', (string) $booking['public_id'], $this->csrfHeader( $request ) ) ) {
			return $this->error( 403, 'bad_csrf', 'Сеанс устарел. Обновите страницу и повторите.' );
		}

		if ( ! RateLimiter::hit( 'consult_checkout', RateLimiter::subject( 'session|' . $this->sessions->sessionId() ), 20, HOUR_IN_SECONDS ) ) {
			return $this->error( 429, 'rate_limited', 'Слишком много попыток. Попробуйте позже.' );
		}

		if ( ! empty( $snap['purged_at'] ) ) {
			return $this->error( 410, 'purged', 'Данные этой анкеты удалены. Заполните анкету заново.' );
		}

		if ( ! $this->gate->ok() ) {
			return $this->error( 503, 'unavailable', 'Оплата консультации временно недоступна. Попробуйте позже.' );
		}

		$now     = Clock::timestamp();
		$booking = $this->sync->sync( $booking, $now ) ?? $booking;

		if ( 'held' !== $booking['status'] ) {
			return $this->json( array( 'code' => 'hold_expired', 'message' => 'Время резерва истекло или запись уже оформлена. Выберите время заново.', 'status' => (string) $booking['status'] ), 409 );
		}

		if ( (int) $booking['hold_until'] < $now ) {
			$code = $this->sync->orderAlive( $booking, $now ) ? 'in_progress' : 'hold_expired';

			return $this->json( array( 'code' => $code, 'message' => 'in_progress' === $code ? 'Ожидаем подтверждения оплаты.' : 'Время резерва истекло. Выберите время заново.' ), 409 );
		}

		// Цена из EDD не должна измениться с момента резерва (иначе показанная цена неверна).
		$terms = (array) $booking['terms'];
		$base  = $this->gate->priceFor( (string) $booking['format'] );

		if ( null === $base || CreditCalculator::format( (float) $base ) !== CreditCalculator::format( (float) ( $terms['price']['base'] ?? 0 ) ) ) {
			return $this->priceChanged( $booking, $snap );
		}

		$added = $this->cart->replaceWithConsultation( $snap, $booking );

		if ( ! $added['ok'] ) {
			return $this->error( 500, 'cart_failed', 'Не удалось перейти к оплате. Повторите попытку.' );
		}

		if ( CreditCalculator::format( (float) $added['price'] ) !== CreditCalculator::format( (float) ( $terms['price']['item'] ?? 0 ) ) ) {
			// Показанная цена не совпала с ценой корзины: корзину очищаем, посетитель видит актуальные данные.
			if ( function_exists( 'edd_get_cart_contents' ) ) {
				foreach ( array_reverse( CartService::ourItems( $this->gate->downloadId() ) ) as $row ) {
					edd_remove_from_cart( $row['key'] );
				}
			}

			return $this->priceChanged( $booking, $snap, (string) $added['price'] );
		}

		$this->snapshots->lock( (int) $snap['id'] );

		return $this->json( array( 'redirect' => (string) edd_get_checkout_uri() ) );
	}

	// -----------------------------------------------------------------
	// GET /consult/booking

	public function booking( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! RateLimiter::hit( 'consult_booking', RateLimiter::ipSubject(), 200, HOUR_IN_SECONDS ) ) {
			return $this->error( 429, 'rate_limited', 'Слишком много запросов. Попробуйте позже.' );
		}

		$booking = $this->bookingParam( $request, 'b' );
		$snap    = null === $booking ? null : $this->snapshots->findById( (int) $booking['snapshot_id'] );

		if ( null === $booking || null === $snap || ! $this->canAccessBooking( $booking, $snap ) ) {
			return $this->error( 404, 'not_found', 'Запись не найдена.' );
		}

		$booking = $this->sync->sync( $booking, Clock::timestamp() ) ?? $booking;
		$data    = array( 'booking' => $this->bookingView( $booking, $snap, true ) );

		if ( $this->isSnapshotOwner( $snap ) ) {
			$data['csrf'] = $this->bookingCsrf( (string) $booking['public_id'] );
		}

		return $this->json( $data );
	}

	// -----------------------------------------------------------------
	// GET /consult/ics

	public function ics( \WP_REST_Request $request ): \WP_REST_Response {
		$booking = $this->bookingParam( $request, 'b' );
		$snap    = null === $booking ? null : $this->snapshots->findById( (int) $booking['snapshot_id'] );

		if ( null === $booking || null === $snap || ! $this->canAccessBooking( $booking, $snap ) ) {
			return $this->error( 404, 'not_found', 'Запись не найдена.' );
		}

		$booking = $this->sync->sync( $booking, Clock::timestamp() ) ?? $booking;

		if ( 'sync' !== $booking['kind'] || ! in_array( $booking['status'], array( 'confirmed', 'cancelled' ), true ) ) {
			return $this->error( 404, 'not_found', 'Календарное событие недоступно для этой записи.' );
		}

		$builder = '\\Bginfo\\PmzhDvzh\\Booking\\IcsBuilder';

		if ( ! class_exists( $builder ) ) {
			return $this->error( 501, 'not_implemented', 'Календарное событие пока недоступно.' );
		}

		$body = (string) call_user_func( array( $builder, 'build' ), $booking );
		$name = 'konsultaciya-' . substr( (string) $booking['public_id'], 0, 8 ) . '.ics';

		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result ) use ( $body, $name ) {
				if ( $served || ! $result instanceof \WP_REST_Response || '__bgdvzh_ics__' !== $result->get_data() ) {
					return $served;
				}

				header( 'Content-Type: text/calendar; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $name . '"' );
				header( 'Cache-Control: no-store, private' );
				header( 'X-Content-Type-Options: nosniff' );
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- календарь text/calendar, значения экранирует IcsBuilder.

				return true;
			},
			10,
			2
		);

		return new \WP_REST_Response( '__bgdvzh_ics__', 200 );
	}

	// -----------------------------------------------------------------
	// Для RestController (/state, /result).

	/**
	 * Краткий список записей анкеты без контактов и вопросов.
	 *
	 * @param array<string,mixed> $snapshot
	 * @param list<int>|null      $allowedPurchaseIds null — владелец анкеты; список — доступ по grant.
	 * @return list<array<string,mixed>>
	 */
	public function listForSnapshot( array $snapshot, ?array $allowedPurchaseIds ): array {
		$now = Clock::timestamp();
		$out = array();

		// Таблицы консультаций ещё не созданы (обновление плагина без захода в админку).
		if ( ! Schema::ready() ) {
			return array();
		}

		foreach ( $this->bookings->bySnapshot( (int) $snapshot['id'] ) as $b ) {
			if ( null !== $allowedPurchaseIds && ! in_array( (int) $b['purchase_id'], $allowedPurchaseIds, true ) ) {
				continue;
			}

			if ( 'held' === $b['status'] && (int) $b['hold_until'] < $now && (int) $b['order_id'] <= 0 ) {
				continue;
			}

			$out[] = array(
				'id'           => (string) $b['public_id'],
				'status'       => (string) $b['status'],
				'format'       => (string) $b['format'],
				'mode'         => (string) $b['mode'],
				'start_utc'    => $b['start_utc'],
				'deadline_utc' => $b['deadline_utc'],
				'date'         => $b['written_date'],
				'hold_until'   => 'held' === $b['status'] ? (int) $b['hold_until'] : null,
			);
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Вид записи для посетителя.

	/**
	 * Публичное представление записи (без admin_notes и служебных полей).
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,mixed> $snapshot
	 * @return array<string,mixed>
	 */
	public function bookingView( array $b, array $snapshot, bool $withExtras = false ): array {
		$terms   = (array) $b['terms'];
		$now     = Clock::timestamp();
		$written = 'written' === $b['kind'];
		$price   = (array) ( $terms['price'] ?? array() );
		$view    = array(
			'id'             => (string) $b['public_id'],
			'snapshot'       => (string) $snapshot['public_id'],
			'status'         => (string) $b['status'],
			'format'         => (string) $b['format'],
			'mode'           => (string) $b['mode'],
			'kind'           => (string) $b['kind'],
			'times'          => array(
				'start_utc'    => $b['start_utc'],
				'end_utc'      => $b['end_utc'],
				'deadline_utc' => $b['deadline_utc'],
			),
			'consultant_tz'  => (string) ( $terms['consultant_tz'] ?? '' ),
			'visitor_tz'     => (string) $b['visitor_tz'],
			'duration_min'   => $written ? null : ( $terms['duration_min'] ?? null ),
			'written'        => $written ? ( $terms['written'] ?? null ) : null,
			'contact'        => (string) $b['contact'],
			'email'          => (string) $b['email'],
			'question'       => (string) $b['question'],
			'initiator_text' => (string) ( $terms['initiator'] ?? '' ),
			'hold_until'     => 'held' === $b['status'] ? (int) $b['hold_until'] : null,
			'price'          => array(
				'base'     => (string) ( $price['base'] ?? '' ),
				'credit'   => (string) ( $price['credit'] ?? '0' ),
				'item'     => (string) ( $price['item'] ?? '' ),
				'currency' => (string) ( $price['currency'] ?? '' ),
				'display'  => '' === (string) ( $price['item'] ?? '' ) ? '' : $this->gate->display( (string) $price['item'] ),
			),
		);

		if ( '' !== (string) $b['meet_url'] ) {
			$view['meet_url'] = (string) $b['meet_url'];
		}

		if ( ! $withExtras ) {
			return $view;
		}

		$view['instruction']   = $this->instructionInfo( $b, $snapshot );
		$view['contact_email'] = $this->contactEmail();

		if ( 'sync' === $b['kind'] && in_array( $b['status'], array( 'confirmed', 'cancelled' ), true ) && function_exists( 'rest_url' ) ) {
			$view['ics_url'] = rest_url( RestController::NAMESPACE_ . '/consult/ics' ) . '?b=' . rawurlencode( (string) $b['public_id'] );
		}

		return $view;
	}

	/** Адрес для переносов/отмен: admin_email из настроек консультаций либо адрес администратора сайта. */
	private function contactEmail(): string {
		$mail = (string) ( $this->gate->config()['admin_email'] ?? '' );

		if ( '' === $mail && function_exists( 'get_option' ) ) {
			$mail = (string) get_option( 'admin_email', '' );
		}

		return $mail;
	}

	/**
	 * Инструкция доступна, если по анкете есть живая оплата (консультации или инструкции).
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,mixed> $snapshot
	 * @return array{available:bool,result_id:string}
	 */
	private function instructionInfo( array $b, array $snapshot ): array {
		$available = false;

		if ( empty( $snapshot['purged_at'] ) ) {
			foreach ( $this->purchases->bySnapshot( (int) $snapshot['id'] ) as $p ) {
				if ( $this->policy->paymentState( $p )['access'] ) {
					$available = true;
					break;
				}
			}
		}

		return array( 'available' => $available, 'result_id' => (string) $snapshot['public_id'] );
	}

	/** @return array{checkout:string} */
	private function bookingCsrf( string $publicId ): array {
		return array(
			'checkout' => $this->sessions->csrfFor( 'consult_checkout', $publicId ),
		);
	}

	// -----------------------------------------------------------------
	// Вспомогательное.

	/**
	 * @param array<string,mixed> $booking
	 * @param array<string,mixed> $snap
	 */
	private function priceChanged( array $booking, array $snap, string $cartPrice = '' ): \WP_REST_Response {
		return $this->json(
			array(
				'code'    => 'price_changed',
				'message' => 'Цена консультации изменилась. Проверьте условия и повторите.',
				'price'   => $cartPrice,
				'booking' => $this->bookingView( $booking, $snap ),
			),
			409
		);
	}

	/**
	 * @param array<string,mixed> $booking
	 * @param array<string,mixed> $snap
	 */
	private function canAccessBooking( array $booking, array $snap ): bool {
		return $this->isSnapshotOwner( $snap ) || $this->grants->opensBooking( $booking );
	}

	/** @return array<string,mixed>|null Снимок, принадлежащий запросу (сессия или пользователь). */
	private function ownedSnapshot( \WP_REST_Request $request ): ?array {
		$id = $request->get_param( 'id' );

		if ( null === $id ) {
			$id = $this->body( $request )['id'] ?? '';
		}

		$id   = is_string( $id ) ? strtolower( trim( $id ) ) : '';
		$snap = Tokens::isWellFormed( $id, 32 ) ? $this->snapshots->findByPublicId( $id ) : null;

		return null !== $snap && $this->isSnapshotOwner( $snap ) ? $snap : null;
	}

	/** @param array<string,mixed> $snapshot */
	private function isSnapshotOwner( array $snapshot ): bool {
		$ctx = new RequestContext( $this->sessions->sessionId(), (int) get_current_user_id(), array() );

		return AccessPolicy::isOwner( $snapshot, null, $ctx->toArray() );
	}

	/** @return array<string,mixed>|null */
	private function bookingParam( \WP_REST_Request $request, string $key = 'b' ): ?array {
		$id = $request->get_param( $key );

		if ( null === $id ) {
			$id = $this->body( $request )[ $key ] ?? '';
		}

		$id = is_string( $id ) ? strtolower( trim( $id ) ) : '';

		return Tokens::isWellFormed( $id, 32 ) ? $this->bookings->byPublicId( $id ) : null;
	}

	/** @param mixed $raw */
	private function fromDate( $raw, \DateTimeZone $tz, int $now ): string {
		$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' );

		if ( is_string( $raw ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) && \Bginfo\PmzhDvzh\Schedule\ScheduleConfig::isDate( $raw ) && $raw > $today ) {
			return $raw;
		}

		return $today;
	}

	/** @return array{base:string,credit:string,item:string,currency:string,tax_note:string,display:string,display_base:string,display_credit:string} */
	private function priceBlock( string $base, string $credit, string $item, string $currency, string $taxMode ): array {
		return array(
			'base'           => $base,
			'credit'         => $credit,
			'item'           => $item,
			'currency'       => $currency,
			'tax_note'       => $this->gate->needsTaxNote() ? 'Плюс налог, если применяется; итог рассчитает оформление заказа.' : '',
			'display'        => $this->gate->display( $item ),
			'display_base'   => $this->gate->display( $base ),
			'display_credit' => (float) $credit > 0 ? $this->gate->display( $credit ) : '',
		);
	}

	private function csrfHeader( \WP_REST_Request $request ): string {
		$h = $request->get_header( RestController::CSRF_HEADER );

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
