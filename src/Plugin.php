<?php
/**
 * Точка входа: ручная сборка зависимостей (без DI-контейнера) и регистрация хуков.
 *
 * Сервисы доступны через Plugin::instance()->services (для страниц админки):
 * settings, sessions, grants, drafts, snapshots, purchases, documentRepo,
 * grantRepo, events, policy, reader, gate, templates, templateRepo,
 * renderer, documents, orderEvents, purger.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Access\GrantManager;
use Bginfo\PmzhDvzh\Access\SessionManager;
use Bginfo\PmzhDvzh\Admin\ConsultationsPage;
use Bginfo\PmzhDvzh\Admin\Notices;
use Bginfo\PmzhDvzh\Booking\BookingCron;
use Bginfo\PmzhDvzh\Booking\BookingService;
use Bginfo\PmzhDvzh\Booking\BookingSync;
use Bginfo\PmzhDvzh\Booking\CreditRefundHandler;
use Bginfo\PmzhDvzh\Booking\CreditService;
use Bginfo\PmzhDvzh\Booking\Mailer;
use Bginfo\PmzhDvzh\Booking\Notifier;
use Bginfo\PmzhDvzh\Booking\Reminders;
use Bginfo\PmzhDvzh\Commerce\CartService;
use Bginfo\PmzhDvzh\Commerce\CheckoutGuard;
use Bginfo\PmzhDvzh\Commerce\ConsultGate;
use Bginfo\PmzhDvzh\Commerce\ConsultPriceFilter;
use Bginfo\PmzhDvzh\Commerce\EddGate;
use Bginfo\PmzhDvzh\Commerce\EddOrderReader;
use Bginfo\PmzhDvzh\Commerce\OrderBinder;
use Bginfo\PmzhDvzh\Commerce\OrderEvents;
use Bginfo\PmzhDvzh\Commerce\ReceiptIntegration;
use Bginfo\PmzhDvzh\Commerce\SuccessRedirect;
use Bginfo\PmzhDvzh\Cron\CronHandler;
use Bginfo\PmzhDvzh\Http\ConsultController;
use Bginfo\PmzhDvzh\Http\DownloadController;
use Bginfo\PmzhDvzh\Http\RestController;
use Bginfo\PmzhDvzh\Instruction\HtmlRenderer;
use Bginfo\PmzhDvzh\Instruction\TemplateStore;
use Bginfo\PmzhDvzh\Pdf\DocumentService;
use Bginfo\PmzhDvzh\Pdf\PdfRenderer;
use Bginfo\PmzhDvzh\Schedule\ScheduleConfig;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\SlotRepository;
use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\DraftRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\GrantRepository;
use Bginfo\PmzhDvzh\Storage\MessageRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\Schema;
use Bginfo\PmzhDvzh\Storage\SessionRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotPurger;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Storage\TemplateRepository;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Settings;

final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string,object> */
	public array $services = array();

	private function __construct() {
	}

	public static function instance(): ?Plugin {
		return self::$instance;
	}

	public static function activate(): void {
		Schema::install();

		// Расписание bgdvzh_5min ещё не зарегистрировано: активация идёт до plugins_loaded.
		add_filter( 'cron_schedules', array( CronHandler::class, 'addSchedule' ) );
		CronHandler::ensureScheduled();
	}

	/** Деактивация снимает только cron; данные не трогает. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( CronHandler::HOOK );
	}

	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->wire();
	}

	private function wire(): void {
		// После замены ZIP схема обновляется при первом же запросе (покупки пишут новые колонки).
		if ( ! Schema::ready() && ! get_transient( 'bgdvzh_upgrading' ) ) {
			set_transient( 'bgdvzh_upgrading', 1, 60 );
			Schema::maybeUpgrade();
			delete_transient( 'bgdvzh_upgrading' );
		}

		$settings = new Settings();

		$sessionRepo  = new SessionRepository();
		$drafts       = new DraftRepository();
		$snapshots    = new SnapshotRepository();
		$purchases    = new PurchaseRepository();
		$grantRepo    = new GrantRepository();
		$documentRepo = new DocumentRepository();
		$events       = new EventRepository();
		$templateRepo = new TemplateRepository();

		$reader    = new EddOrderReader();
		$policy    = new AccessPolicy( $reader );
		$gate      = new EddGate( $settings );
		$sessions  = new SessionManager( $sessionRepo, $settings );
		$grants    = new GrantManager( $grantRepo, $purchases, $snapshots, $settings );
		$templates = new TemplateStore( $templateRepo );
		$renderer  = new HtmlRenderer();

		$documents   = new DocumentService( $documentRepo, $snapshots, $events, $renderer, new PdfRenderer() );
		$orderEvents = new OrderEvents( $reader, $policy, $purchases, $documentRepo, $events );
		$purger      = new SnapshotPurger( $snapshots, $purchases, $grantRepo, $documentRepo, $documents, $events );

		// Консультации.
		global $wpdb;

		$consultGate    = new ConsultGate( $settings );
		$consultCfg     = $settings->consultation();
		$bookingRepo    = new BookingRepository( $wpdb );
		$slotRepo       = new SlotRepository( $wpdb );
		$creditRepo     = new CreditRepository( $wpdb );
		$notifier       = new Notifier(
			$bookingRepo,
			static function () use ( $consultCfg ): string {
				$mail = (string) ( $consultCfg['admin_email'] ?? '' );

				return '' !== $mail ? $mail : (string) get_option( 'admin_email', '' );
			},
			$wpdb
		);
		$bookingService = new BookingService( $bookingRepo, $slotRepo, $creditRepo, $notifier, new ScheduleConfig( $consultCfg ), $consultCfg );
		$bookingSync    = new BookingSync( $reader, $policy, $purchases, $bookingRepo, $bookingService );
		$creditService  = new CreditService( $reader, $policy, $purchases, $creditRepo, $bookingRepo, $bookingService, $bookingSync );
		$bookingCron    = new BookingCron( $bookingRepo, $slotRepo, $creditRepo, $bookingSync, $bookingService, $notifier );
		$cart           = new CartService( $gate, $consultGate );

		$messageRepo   = new MessageRepository( $wpdb );
		$creditRefund  = new CreditRefundHandler( $bookingRepo, $creditRepo, $notifier, $events );
		$orderEvents->withBooking( $bookingSync, $bookingRepo, $creditRepo, $creditRefund );
		$grants->withBookings( $bookingRepo );

		// Письма по записям: отправитель, напоминания (действие bgdvzh_consult_tick).
		$mailer = new Mailer(
			static function ( array $booking ) use ( $grants, $purchases, $settings ): array {
				$none = array( 'instruction' => '', 'manage' => '' );
				$page = $settings->pageUrl();

				if ( '' === $page || (int) $booking['id'] <= 0 ) {
					return $none;
				}

				$purchase = $purchases->byBooking( (int) $booking['id'] );

				if ( null === $purchase ) {
					return $none;
				}

				$instruction = $grants->issueLink( $purchase );
				$manage      = $grants->issueBookingLink( $purchase );

				return array(
					'instruction' => null === $instruction ? '' : $page . '#dvzh-k=' . $instruction,
					'manage'      => null === $manage ? '' : $page . '#dvzh-b=' . $manage,
				);
			},
			static fn( int $bookingId ): string => ConsultationsPage::cardUrl( $bookingId ),
			$messageRepo,
			null,
			static function () use ( $consultCfg ): string {
				$mail = (string) ( $consultCfg['admin_email'] ?? '' );

				return '' !== $mail ? $mail : (string) get_option( 'admin_email', '' );
			}
		);
		$notifier->setSender( $mailer );
		( new Reminders( $bookingRepo, $notifier, $consultCfg ) )->register();

		$this->services = array(
			'settings'     => $settings,
			'sessions'     => $sessions,
			'grants'       => $grants,
			'drafts'       => $drafts,
			'snapshots'    => $snapshots,
			'purchases'    => $purchases,
			'documentRepo' => $documentRepo,
			'grantRepo'    => $grantRepo,
			'events'       => $events,
			'policy'       => $policy,
			'reader'       => $reader,
			'gate'         => $gate,
			'templates'    => $templates,
			'templateRepo' => $templateRepo,
			'renderer'     => $renderer,
			'documents'    => $documents,
			'orderEvents'  => $orderEvents,
			'purger'       => $purger,
			'consultGate'    => $consultGate,
			'bookings'       => $bookingRepo,
			'slots'          => $slotRepo,
			'credits'        => $creditRepo,
			'notifier'       => $notifier,
			'bookingService' => $bookingService,
			'bookingSync'    => $bookingSync,
			'creditService'  => $creditService,
			'messages'       => $messageRepo,
		);

		$consultController = new ConsultController(
			$settings,
			$sessions,
			$grants,
			$snapshots,
			$purchases,
			$bookingRepo,
			$slotRepo,
			$bookingService,
			$bookingSync,
			$creditService,
			$consultGate,
			$cart,
			$policy,
			$events
		);
		$consultController->setTemplates( $templates );
		$consultController->register();

		$rest = new RestController(
			$settings,
			$sessions,
			$grants,
			$drafts,
			$snapshots,
			$purchases,
			$documentRepo,
			$events,
			$policy,
			$reader,
			$gate,
			$cart,
			$orderEvents,
			$documents,
			$templates,
			$renderer
		);
		$rest->setConsult( $consultController );
		$rest->register();

		( new DownloadController( $sessions, $grants, $snapshots, $purchases, $policy, $documents, $events ) )->register();

		( new CronHandler(
			$settings,
			$documentRepo,
			$documents,
			$purchases,
			$snapshots,
			$drafts,
			$sessionRepo,
			$grantRepo,
			$events,
			$policy,
			$orderEvents,
			$purger,
			$bookingCron
		) )->register();

		add_filter( 'cron_schedules', array( CronHandler::class, 'addSchedule' ) );

		( new Notices( $gate ) )->register();

		if ( is_admin() ) {
			( new \Bginfo\PmzhDvzh\Admin\Menu( $this->services ) )->register();
		}

		// Интеграция с EDD подключается на init: к этому моменту EDD точно загружен.
		add_action(
			'init',
			static function () use ( $settings, $gate, $reader, $snapshots, $purchases, $policy, $events, $grants, $orderEvents, $consultGate, $bookingRepo, $creditRepo, $bookingSync, $creditService ): void {
				if ( ! EddGate::eddAvailable() ) {
					return;
				}

				( new CheckoutGuard( $gate, $settings, $snapshots, $purchases, $policy, $consultGate, $bookingRepo, $creditRepo, $bookingSync, $creditService ) )->register();
				( new OrderBinder( $gate, $reader, $snapshots, $purchases, $events, $orderEvents, $consultGate, $bookingRepo, $bookingSync ) )->register();
				$orderEvents->register();
				( new ReceiptIntegration( $settings, $policy, $purchases, $snapshots, $grants, $events ) )->register();
				( new SuccessRedirect( $gate, $reader, $settings, $consultGate ) )->register();
				( new ConsultPriceFilter( $consultGate, $bookingRepo, $creditRepo, $snapshots ) )->register();
			},
			5
		);

		add_action( 'admin_init', array( __CLASS__, 'maintain' ) );

		if ( class_exists( '\\Bginfo\\PmzhDvzh\\Frontend\\Frontend' ) ) {
			$frontend = '\\Bginfo\\PmzhDvzh\\Frontend\\Frontend';
			( new $frontend( $settings ) )->register();
		}
	}

	/** Обновление схемы БД и восстановление расписания (после замены ZIP без реактивации). */
	public static function maintain(): void {
		Schema::maybeUpgrade();
		CronHandler::ensureScheduled();
	}

	/**
	 * Конфигурация для JS анкеты (window.BGDVZH_CONFIG).
	 *
	 * Ключи: rest (базовый URL REST со слэшем), nonce (wp_rest, только для
	 * вошедших, иначе пустая строка), page_url, today (Y-m-d по часовому
	 * поясу сайта), purchase_enabled, privacy_url.
	 *
	 * @return array<string,mixed>
	 */
	public static function clientConfig(): array {
		$settings = self::$instance && isset( self::$instance->services['settings'] ) ? self::$instance->services['settings'] : new Settings();
		$gate     = self::$instance && isset( self::$instance->services['gate'] ) ? self::$instance->services['gate'] : new EddGate( $settings );

		return array(
			'rest'             => esc_url_raw( rest_url( 'bgdvzh/v1/' ) ),
			'nonce'            => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'page_url'         => $settings->pageUrl(),
			'today'            => Clock::today(),
			'purchase_enabled' => $gate->ok(),
			'privacy_url'      => (string) $settings->get( 'privacy_url', '' ),
			'consult_enabled'  => self::$instance && isset( self::$instance->services['consultGate'] ) ? self::$instance->services['consultGate']->ok() : false,
			'analytics_events' => ! empty( $settings->consultation()['analytics_events'] ),
		);
	}
}
