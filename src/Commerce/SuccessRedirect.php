<?php
/**
 * Со страницы успеха EDD — на страницу анкеты, если заказ целиком состоит
 * из нашего товара. Приоритет 20: после подтверждения Stripe (10).
 * Смешанные заказы остаются на стандартной квитанции.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Support\Settings;

final class SuccessRedirect {

	public function __construct( private EddGate $gate, private OrderReader $reader, private Settings $settings, private ?ConsultGate $consult = null ) {
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybeRedirect' ), 20 );
	}

	public function maybeRedirect(): void {
		if (
			! function_exists( 'edd_is_success_page' )
			|| ! edd_is_success_page()
			|| ! function_exists( 'edd_get_purchase_session' )
			|| ! function_exists( 'edd_get_order_by' )
		) {
			return;
		}

		$page = $this->settings->pageUrl();

		if ( '' === $page ) {
			return;
		}

		$session = edd_get_purchase_session();
		$key     = is_array( $session ) && ! empty( $session['purchase_key'] ) ? (string) $session['purchase_key'] : '';

		if ( '' === $key ) {
			return;
		}

		$order = edd_get_order_by( 'payment_key', $key );

		if ( ! is_object( $order ) || empty( $order->id ) || 'sale' !== (string) $order->type || in_array( (string) $order->status, array( 'failed', 'abandoned', 'cancelled' ), true ) ) {
			return;
		}

		$download = $this->gate->downloadId();
		$consult  = null === $this->consult ? 0 : $this->consult->downloadId();
		$items    = $this->reader->orderItems( (int) $order->id );

		if ( ( $download <= 0 && $consult <= 0 ) || empty( $items ) ) {
			return;
		}

		// Заказ целиком из наших товаров (инструкция и/или консультация).
		foreach ( $items as $item ) {
			$product = (int) $item['product_id'];

			if ( $product !== $download && $product !== $consult ) {
				return;
			}
		}

		wp_safe_redirect( $page . '#anketa-dvzh' );
		exit;
	}
}
