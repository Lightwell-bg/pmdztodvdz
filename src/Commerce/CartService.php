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

	public function __construct( private EddGate $gate ) {
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
