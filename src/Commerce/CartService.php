<?php
/**
 * Корзина EDD: «ровно одна позиция нашего товара, привязанная к снимку».
 *
 * replaceWithSnapshot() идемпотентна: двойной клик или повторный запрос
 * сначала убирает все наши позиции, затем добавляет одну с актуальным
 * bgdvzh_ref (корзина хранит последний снимок, отправленный на оплату).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Access\Tokens;

final class CartService {

	public const OPTION_REF = 'bgdvzh_ref';

	public function __construct( private EddGate $gate, private ?ConsultGate $consult = null ) {
	}

	/**
	 * Кладёт в корзину консультацию брони, убирая прежние позиции консультации
	 * и инструкцию этого же снимка (консультация включает инструкцию).
	 * Обычные товары корзины не трогает. Цена позиции приходит из EDD
	 * (фильтр edd_cart_item_price применяет зачёт), здесь она возвращается
	 * для сверки с показанной посетителю.
	 *
	 * @param array<string,mixed> $snapshot Строка снимка (public_id, session_id).
	 * @param array<string,mixed> $booking  Запись (public_id, format).
	 * @return array{ok:bool,price:?string,error:string}
	 */
	public function replaceWithConsultation( array $snapshot, array $booking ): array {
		$fail = array( 'ok' => false, 'price' => null, 'error' => 'unavailable' );

		if (
			null === $this->consult
			|| ! $this->consult->ok()
			|| ! function_exists( 'edd_add_to_cart' )
			|| ! function_exists( 'edd_remove_from_cart' )
			|| ! function_exists( 'edd_get_cart_item_price' )
		) {
			return $fail;
		}

		$download = $this->consult->downloadId();

		foreach ( array_reverse( self::ourItems( $download ) ) as $row ) {
			edd_remove_from_cart( $row['key'] );
		}

		foreach ( array_reverse( self::ourItems( $this->gate->downloadId() ) ) as $row ) {
			$opt    = isset( $row['item']['options'] ) && is_array( $row['item']['options'] ) ? $row['item']['options'] : array();
			$ref    = isset( $opt[ self::OPTION_REF ] ) && is_string( $opt[ self::OPTION_REF ] ) ? Tokens::parseCartRef( $opt[ self::OPTION_REF ] ) : null;

			if ( null !== $ref && $ref[0] === (string) $snapshot['public_id'] ) {
				edd_remove_from_cart( $row['key'] );
			}
		}

		$options = array(
			self::OPTION_REF                         => Tokens::cartRef( (string) $snapshot['public_id'], (int) $snapshot['session_id'], self::refKey() ),
			ConsultPriceFilter::OPTION_BOOKING => (string) $booking['public_id'],
		);

		$priceId = $this->consult->priceIdFor( (string) $booking['format'] );

		if ( null !== $priceId ) {
			$options['price_id'] = $priceId;
		}

		$added = edd_add_to_cart( $download, $options );

		if ( null === $added || false === $added ) {
			return $fail;
		}

		$price = edd_get_cart_item_price( $download, $options );

		if ( ! is_numeric( $price ) ) {
			foreach ( array_reverse( self::ourItems( $download ) ) as $row ) {
				edd_remove_from_cart( $row['key'] );
			}

			return array( 'ok' => false, 'price' => null, 'error' => 'price' );
		}

		return array( 'ok' => true, 'price' => CreditCalculator::format( (float) $price ), 'error' => '' );
	}

	/** Ключ подписи ссылки снимка в корзине. */
	public static function refKey(): string {
		return wp_salt( 'auth' );
	}

	/**
	 * Позиции корзины с нашим товаром.
	 *
	 * @return list<array{key:int,item:array<string,mixed>}>
	 */
	public static function ourItems( int $downloadId ): array {
		if ( $downloadId <= 0 || ! function_exists( 'edd_get_cart_contents' ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) edd_get_cart_contents() as $key => $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && (int) $item['id'] === $downloadId ) {
				$out[] = array( 'key' => (int) $key, 'item' => $item );
			}
		}

		return $out;
	}

	/**
	 * Кладёт в корзину нашу позицию для снимка, убирая прежние.
	 *
	 * @param array<string,mixed> $snapshot Строка снимка (public_id, session_id).
	 */
	public function replaceWithSnapshot( array $snapshot ): bool {
		if ( ! $this->gate->ok() || ! function_exists( 'edd_add_to_cart' ) || ! function_exists( 'edd_remove_from_cart' ) ) {
			return false;
		}

		$download = $this->gate->downloadId();
		$existing = self::ourItems( $download );

		// Удаляем с конца, чтобы индексы оставшихся позиций не сдвигались.
		foreach ( array_reverse( $existing ) as $row ) {
			edd_remove_from_cart( $row['key'] );
		}

		$options = array(
			self::OPTION_REF => Tokens::cartRef( (string) $snapshot['public_id'], (int) $snapshot['session_id'], self::refKey() ),
		);

		$priceId = $this->gate->priceId();

		if ( null !== $priceId ) {
			$options['price_id'] = $priceId;
		}

		$added = edd_add_to_cart( $download, $options );

		return null !== $added && false !== $added;
	}
}
