<?php
/**
 * Зачёт инструкции в цене позиции консультации.
 *
 * Применение — только фильтр `edd_cart_item_price($price, $download_id, $options)`
 * (EDD 3.2–3.7: EDD_Cart::get_item_price). Именно эта цена попадает в
 * cart_details[i]['item_price'] и затем в сумму позиции заказа
 * (edd_build_order → order item amount/subtotal/total), поэтому заказ
 * хранит уже уменьшенную сумму, а отрицательные комиссии не нужны.
 *
 * Сумма зачёта берётся из БД (строка credits в состоянии reserved этой
 * брони), а не из опций корзины. Название позиции дополняется фильтром
 * `edd_get_cart_item_name($title, $download_id, $item)` — его вызывают
 * edd_get_cart_item_name() в корзине оформления и платёжных шлюзах.
 * Название строки заказа берётся EDD из get_the_title(), поэтому оно не меняется.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;

final class ConsultPriceFilter {

	public const OPTION_BOOKING = 'bgdvzh_booking';

	/** @var array<string,?float> Кэш на запрос: public_id брони => зачёт. */
	private array $cache = array();

	public function __construct(
		private ConsultGate $gate,
		private BookingRepository $bookings,
		private CreditRepository $credits,
		private SnapshotRepository $snapshots
	) {
	}

	public function register(): void {
		add_filter( 'edd_cart_item_price', array( $this, 'price' ), 10, 3 );
		add_filter( 'edd_get_cart_item_name', array( $this, 'name' ), 10, 3 );
	}

	public function reset(): void {
		$this->cache = array();
	}

	/**
	 * @param mixed $price
	 * @param mixed $downloadId
	 * @param mixed $options
	 * @return mixed
	 */
	public function price( $price, $downloadId = 0, $options = array() ) {
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			return $price;
		}

		$credit = $this->creditFor( (int) $downloadId, is_array( $options ) ? $options : array() );

		if ( null === $credit ) {
			return $price;
		}

		return CreditCalculator::format( CreditCalculator::itemPrice( (float) $price, $credit ) );
	}

	/**
	 * @param mixed $title
	 * @param mixed $downloadId
	 * @param mixed $item
	 * @return mixed
	 */
	public function name( $title, $downloadId = 0, $item = array() ) {
		if ( ! is_string( $title ) || ! is_array( $item ) ) {
			return $title;
		}

		$options = isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array();
		$credit  = $this->creditFor( (int) $downloadId, $options );

		if ( null === $credit ) {
			return $title;
		}

		return $title . ' (с зачётом инструкции ' . $this->gate->display( CreditCalculator::format( $credit ) ) . ')';
	}

	/**
	 * Зачёт брони из БД или null, если для позиции он не действует.
	 *
	 * @param array<string,mixed> $options Опции позиции корзины.
	 */
	public function creditFor( int $downloadId, array $options ): ?float {
		if ( $downloadId <= 0 || $downloadId !== $this->gate->downloadId() ) {
			return null;
		}

		$ref    = isset( $options[ CartService::OPTION_REF ] ) && is_string( $options[ CartService::OPTION_REF ] ) ? $options[ CartService::OPTION_REF ] : '';
		$public = isset( $options[ self::OPTION_BOOKING ] ) && is_string( $options[ self::OPTION_BOOKING ] ) ? strtolower( $options[ self::OPTION_BOOKING ] ) : '';

		if ( '' === $ref || '' === $public ) {
			return null;
		}

		$key = $public . '|' . $ref;

		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}

		return $this->cache[ $key ] = $this->load( $ref, $public );
	}

	private function load( string $ref, string $publicBooking ): ?float {
		$parsed  = Tokens::parseCartRef( $ref );
		$booking = $this->bookings->byPublicId( $publicBooking );

		if ( null === $parsed || null === $booking || 'held' !== $booking['status'] || (int) $booking['credit_purchase_id'] <= 0 ) {
			return null;
		}

		$snapshot = $this->snapshots->findByPublicId( $parsed[0] );

		if (
			null === $snapshot
			|| (int) $snapshot['id'] !== (int) $booking['snapshot_id']
			|| ! Tokens::cartRefValid( $ref, (int) $snapshot['session_id'], CartService::refKey() )
		) {
			return null;
		}

		$row = $this->credits->byBooking( (int) $booking['id'] );

		if (
			null === $row
			|| 'reserved' !== $row['state']
			|| (int) $row['booking_id'] !== (int) $booking['id']
			|| (int) $row['instruction_purchase_id'] !== (int) $booking['credit_purchase_id']
			|| (float) $row['amount'] <= 0
		) {
			return null;
		}

		return round( (float) $row['amount'], 2 );
	}
}
