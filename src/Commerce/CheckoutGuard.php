<?php
/**
 * Проверка корзины при оформлении (`edd_checkout_error_checks`): наш
 * товар нельзя купить без привязки к снимку анкеты.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Settings;

final class CheckoutGuard {

	public function __construct(
		private EddGate $gate,
		private Settings $settings,
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private AccessPolicy $policy
	) {
	}

	public function register(): void {
		add_action( 'edd_checkout_error_checks', array( $this, 'check' ), 10, 2 );
	}

	/**
	 * @param mixed $validData
	 * @param mixed $post
	 */
	public function check( $validData = null, $post = null ): void {
		$download = $this->gate->downloadId();
		$items    = CartService::ourItems( $download );

		if ( empty( $items ) || ! function_exists( 'edd_set_error' ) ) {
			return;
		}

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

	private function formMessage(): string {
		$url = $this->settings->pageUrl();

		return 'Сначала заполните анкету' . ( '' !== $url ? ' на странице ' . esc_url_raw( $url ) : '' ) . ' и перейдите к оплате оттуда.';
	}
}
