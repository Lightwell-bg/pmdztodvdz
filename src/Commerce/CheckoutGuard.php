<?php
/**
 * Проверка корзины при оформлении (`edd_checkout_error_checks`): наш
 * товар (инструкция или консультация) нельзя купить без привязки к снимку
 * анкеты, а консультацию — без действующего резерва и зачёта.
 *
 * Чистые правила (cartConflict, consultItemError) вынесены в статические
 * методы и покрыты юнит-тестами без WordPress.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Booking\BookingSync;
use Bginfo\PmzhDvzh\Booking\CreditService;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Settings;

final class CheckoutGuard {

	/** Тексты ошибок консультации по коду. */
	private const MESSAGES = array(
		'gate'                    => 'Покупка консультации сейчас недоступна. Попробуйте позже.',
		'consult_multiple'        => 'В корзине может быть только одна консультация.',
		'consult_quantity'        => 'Консультацию можно купить только в одном экземпляре.',
		'consult_with_instruction' => 'Консультация уже включает персональную инструкцию. Уберите инструкцию из корзины.',
		'ref'                     => 'Консультацию можно оформить только со страницы анкеты после выбора времени.',
		'booking'                 => 'Запись на консультацию не найдена. Вернитесь на страницу анкеты и выберите время заново.',
		'snapshot'                => 'Запись относится к другой анкете. Вернитесь на страницу анкеты и выберите время заново.',
		'booking_state'           => 'Эта запись уже оформлена или закрыта. Откройте страницу анкеты.',
		'expired'                 => 'Время резерва истекло. Вернитесь на страницу анкеты и выберите время заново.',
		'price'                   => 'Не удалось определить вариант консультации. Вернитесь на страницу анкеты и повторите оплату.',
		'price_changed'           => 'Цена консультации изменилась. Вернитесь на страницу анкеты и проверьте условия.',
		'credit_changed'          => 'Сумма зачёта изменилась — вернитесь на страницу анкеты и оформите консультацию заново.',
	);

	public function __construct(
		private EddGate $gate,
		private Settings $settings,
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private AccessPolicy $policy,
		private ?ConsultGate $consult = null,
		private ?BookingRepository $bookings = null,
		private ?CreditRepository $credits = null,
		private ?BookingSync $sync = null,
		private ?CreditService $creditService = null
	) {
	}

	public function register(): void {
		add_action( 'edd_checkout_error_checks', array( $this, 'check' ), 10, 2 );
	}

	// -----------------------------------------------------------------
	// Чистые правила.

	/**
	 * Правила корзины, в которой есть консультация.
	 *
	 * @param list<array{qty:int,snapshot_public:?string}> $consultItems     Позиции консультации.
	 * @param list<array{snapshot_public:?string}>        $instructionItems Позиции инструкции.
	 * @return string|null Код ошибки (ключ MESSAGES) либо null.
	 */
	public static function cartConflict( array $consultItems, array $instructionItems ): ?string {
		if ( count( $consultItems ) > 1 ) {
			return 'consult_multiple';
		}

		foreach ( $consultItems as $c ) {
			if ( 1 !== (int) $c['qty'] ) {
				return 'consult_quantity';
			}

			foreach ( $instructionItems as $i ) {
				if ( null !== $c['snapshot_public'] && $i['snapshot_public'] === $c['snapshot_public'] ) {
					return 'consult_with_instruction';
				}
			}
		}

		return null;
	}

	/**
	 * Проверка одной позиции консультации.
	 *
	 * @param array{ref_snapshot_id:?int,price_id:?int}  $item              ref_snapshot_id — id снимка из ПОДПИСАННОЙ ссылки (null — ссылка неверна).
	 * @param array<string,mixed>|null                   $booking           Запись по bgdvzh_booking.
	 * @param array<string,mixed>|null                   $credit            Строка credits брони.
	 * @param int|null                                   $expectedPriceId   price_id формата из настроек.
	 * @return string|null Код ошибки (ключ MESSAGES) либо null.
	 */
	public static function consultItemError( array $item, ?array $booking, ?array $credit, ?int $expectedPriceId, bool $orderAlive, int $now ): ?string {
		if ( null === $item['ref_snapshot_id'] ) {
			return 'ref';
		}

		if ( null === $booking ) {
			return 'booking';
		}

		if ( (int) $booking['snapshot_id'] !== (int) $item['ref_snapshot_id'] ) {
			return 'snapshot';
		}

		if ( 'held' !== $booking['status'] ) {
			return 'booking_state';
		}

		if ( (int) $booking['hold_until'] < $now && ! $orderAlive ) {
			return 'expired';
		}

		if ( $expectedPriceId !== $item['price_id'] ) {
			return 'price';
		}

		if ( (int) $booking['credit_purchase_id'] > 0 ) {
			if (
				null === $credit
				|| 'reserved' !== $credit['state']
				|| (int) $credit['booking_id'] !== (int) $booking['id']
				|| (int) $credit['instruction_purchase_id'] !== (int) $booking['credit_purchase_id']
			) {
				return 'credit_changed';
			}
		}

		return null;
	}

	public static function message( string $code ): string {
		return self::MESSAGES[ $code ] ?? 'Не удалось оформить заказ. Вернитесь на страницу анкеты и повторите оплату.';
	}

	// -----------------------------------------------------------------

	/**
	 * @param mixed $validData
	 * @param mixed $post
	 */
	public function check( $validData = null, $post = null ): void {
		$instruction = CartService::ourItems( $this->gate->downloadId() );
		$consult     = null === $this->consult ? array() : CartService::ourItems( $this->consult->downloadId() );

		if ( ( empty( $instruction ) && empty( $consult ) ) || ! function_exists( 'edd_set_error' ) ) {
			return;
		}

		if ( ! empty( $consult ) && ! $this->checkConsultation( $consult, $instruction ) ) {
			return;
		}

		if ( ! empty( $instruction ) ) {
			$this->checkInstruction( $instruction );
		}
	}

	/**
	 * @param list<array{key:int,item:array<string,mixed>}> $consult
	 * @param list<array{key:int,item:array<string,mixed>}> $instruction
	 * @return bool true — ошибок нет.
	 */
	private function checkConsultation( array $consult, array $instruction ): bool {
		$fail = static function ( string $code ): bool {
			edd_set_error( 'bgdvzh_consult_' . $code, CheckoutGuard::message( $code ) );

			return false;
		};

		if ( null === $this->consult || null === $this->bookings || null === $this->credits || null === $this->sync || ! $this->consult->ok() ) {
			return $fail( 'gate' );
		}

		$facts = array();

		foreach ( $consult as $row ) {
			$facts[] = array( 'qty' => (int) ( $row['item']['quantity'] ?? 1 ), 'snapshot_public' => $this->snapshotPublicOf( $row['item'] ) );
		}

		$instrFacts = array();

		foreach ( $instruction as $row ) {
			$instrFacts[] = array( 'snapshot_public' => $this->snapshotPublicOf( $row['item'] ) );
		}

		$conflict = self::cartConflict( $facts, $instrFacts );

		if ( null !== $conflict ) {
			return $fail( $conflict );
		}

		$item    = $consult[0]['item'];
		$options = isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array();
		$ref     = isset( $options[ CartService::OPTION_REF ] ) && is_string( $options[ CartService::OPTION_REF ] ) ? $options[ CartService::OPTION_REF ] : '';
		$public  = isset( $options[ ConsultPriceFilter::OPTION_BOOKING ] ) && is_string( $options[ ConsultPriceFilter::OPTION_BOOKING ] ) ? strtolower( $options[ ConsultPriceFilter::OPTION_BOOKING ] ) : '';
		$parsed  = '' === $ref ? null : Tokens::parseCartRef( $ref );
		$snap    = null === $parsed ? null : $this->snapshots->findByPublicId( $parsed[0] );
		$snapId  = null !== $snap && empty( $snap['purged_at'] ) && Tokens::cartRefValid( $ref, (int) $snap['session_id'], CartService::refKey() ) ? (int) $snap['id'] : null;
		$booking = '' === $public ? null : $this->bookings->byPublicId( $public );
		$credit  = null === $booking ? null : $this->credits->byBooking( (int) $booking['id'] );
		$now     = Clock::timestamp();
		$alive   = null !== $booking && $this->sync->orderAlive( $booking, $now );
		$price   = isset( $options['price_id'] ) && is_numeric( $options['price_id'] ) ? (int) $options['price_id'] : null;
		$expect  = null === $booking ? null : $this->consult->priceIdFor( (string) $booking['format'] );

		$error = self::consultItemError( array( 'ref_snapshot_id' => $snapId, 'price_id' => $price ), $booking, $credit, $expect, $alive, $now );

		if ( null !== $error ) {
			return $fail( $error );
		}

		// Зачёт пересчитывается по живому EDD (возврат по инструкции мог пройти после резерва).
		if ( (int) $booking['credit_purchase_id'] > 0 && null !== $this->creditService ) {
			$terms = (array) $booking['terms'];
			$live  = $this->creditService->liveAmount(
				(int) $booking['credit_purchase_id'],
				(string) ( $terms['price']['tax_mode'] ?? 'incl' ),
				(float) ( $terms['price']['base'] ?? 0 )
			);

			if ( null === $live || CreditCalculator::format( (float) ( $credit['amount'] ?? 0 ) ) !== $live ) {
				$this->credits->releaseReserved( (int) $booking['id'], gmdate( 'Y-m-d H:i:s', $now ) );

				return $fail( 'credit_changed' );
			}
		}

		// Итоговая цена позиции (с зачётом) должна совпасть с показанной посетителю.
		$shown = (string) ( $booking['terms']['price']['item'] ?? '' );

		if ( '' !== $shown && function_exists( 'edd_get_cart_item_price' ) ) {
			$actual = edd_get_cart_item_price( $this->consult->downloadId(), $options );

			if ( ! is_numeric( $actual ) || CreditCalculator::format( (float) $actual ) !== CreditCalculator::format( (float) $shown ) ) {
				return $fail( 'price_changed' );
			}
		}

		return true;
	}

	/** @param list<array{key:int,item:array<string,mixed>}> $items */
	private function checkInstruction( array $items ): void {
		$message = $this->formMessage();

		if ( ! $this->gate->ok() ) {
			edd_set_error( 'bgdvzh_gate', 'Покупка персональной инструкции сейчас недоступна. Попробуйте позже.' );
			return;
		}

		if ( count( $items ) > 1 ) {
			edd_set_error( 'bgdvzh_multiple', 'В корзине может быть только одна персональная инструкция. ' . $message );
			return;
		}

		$item = $items[0]['item'];

		if ( 1 !== (int) ( $item['quantity'] ?? 1 ) ) {
			edd_set_error( 'bgdvzh_quantity', 'Персональную инструкцию можно купить только в одном экземпляре.' );
			return;
		}

		$options = isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array();
		$ref     = isset( $options[ CartService::OPTION_REF ] ) && is_string( $options[ CartService::OPTION_REF ] ) ? $options[ CartService::OPTION_REF ] : '';
		$parsed  = '' === $ref ? null : Tokens::parseCartRef( $ref );
		$snap    = null === $parsed ? null : $this->snapshots->findByPublicId( $parsed[0] );

		if (
			null === $snap
			|| ! empty( $snap['purged_at'] )
			|| ! Tokens::cartRefValid( $ref, (int) $snap['session_id'], CartService::refKey() )
		) {
			edd_set_error( 'bgdvzh_ref', $message );
			return;
		}

		$expectedPrice = $this->gate->priceId();
		$givenPrice    = isset( $options['price_id'] ) && is_numeric( $options['price_id'] ) ? (int) $options['price_id'] : null;

		if ( $expectedPrice !== $givenPrice ) {
			edd_set_error( 'bgdvzh_price', 'Не удалось определить вариант товара. Вернитесь на страницу анкеты и повторите оплату.' );
			return;
		}

		foreach ( $this->purchases->bySnapshot( (int) $snap['id'] ) as $purchase ) {
			$state = $this->policy->paymentState( $purchase );

			if ( $state['access'] ) {
				edd_set_error( 'bgdvzh_paid', 'Эта инструкция уже оплачена. Откройте её на странице анкеты.' );
				return;
			}
		}
	}

	/**
	 * public_id снимка из позиции корзины (без проверки подписи — для правил состава корзины).
	 *
	 * @param array<string,mixed> $item
	 */
	private function snapshotPublicOf( array $item ): ?string {
		$options = isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array();
		$ref     = isset( $options[ CartService::OPTION_REF ] ) && is_string( $options[ CartService::OPTION_REF ] ) ? Tokens::parseCartRef( $options[ CartService::OPTION_REF ] ) : null;

		return null === $ref ? null : $ref[0];
	}

	private function formMessage(): string {
		$url = $this->settings->pageUrl();

		return 'Сначала заполните анкету' . ( '' !== $url ? ' на странице ' . esc_url_raw( $url ) : '' ) . ' и перейдите к оплате оттуда.';
	}
}
