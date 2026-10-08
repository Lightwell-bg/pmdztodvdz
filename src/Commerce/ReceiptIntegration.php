<?php
/**
 * Доступ гостя к оплаченной инструкции: ссылка в письме-квитанции EDD и
 * кнопка на странице квитанции.
 *
 * Письмо получает персональную ссылку-токен (новая при каждом письме).
 * Страница квитанции открывается по ссылке EDD, поэтому токен на ней не
 * выдаётся: кнопка ведёт на страницу анкеты без токена.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Access\GrantManager;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Settings;

final class ReceiptIntegration {

	/** Кнопка выводится один раз за запрос (на странице квитанции срабатывают оба хука). */
	private bool $buttonRendered = false;

	public function __construct(
		private Settings $settings,
		private AccessPolicy $policy,
		private PurchaseRepository $purchases,
		private SnapshotRepository $snapshots,
		private GrantManager $grants,
		private EventRepository $events
	) {
	}

	public function register(): void {
		add_filter( 'edd_order_receipt', array( $this, 'appendLink' ), 10, 2 );
		add_action( 'edd_order_receipt_after_table', array( $this, 'renderButton' ), 10, 1 );
		// Страница подтверждения после оплаты (блок edd/confirmation) вызывает только этот хук.
		add_action( 'edd_order_receipt_order_details', array( $this, 'renderButton' ), 10, 1 );
	}

	/**
	 * Добавляет ссылку в письмо, если в заказе есть наша оплаченная покупка.
	 *
	 * @param mixed $body
	 * @param mixed $order
	 * @return mixed
	 */
	public function appendLink( $body, $order = null ) {
		if ( ! is_string( $body ) || ! is_object( $order ) || empty( $order->id ) ) {
			return $body;
		}

		$page = $this->settings->pageUrl();

		if ( '' === $page ) {
			return $body;
		}

		$extra = '';

		foreach ( $this->paidPurchases( (int) $order->id ) as $purchase ) {
			$token = $this->grants->issueLink( $purchase );

			if ( null === $token ) {
				continue;
			}

			$url    = $page . '#dvzh-k=' . $token;
			$extra .= '<p>Ваша персональная инструкция готова. Ссылка для доступа с любого устройства (действует '
				. (int) $this->settings->get( 'link_ttl_days', 30 ) . ' дн.): <a href="' . esc_url( $url ) . '">' . esc_html( 'Открыть инструкцию' ) . '</a><br>'
				. esc_html( $url ) . '</p>';

			$this->purchases->markReceiptLinkSent( (int) $purchase['id'] );
			$this->events->log( 'receipt_link_issued', (int) $purchase['id'], (int) $order->id, '' );
		}

		return $body . $extra;
	}

	/**
	 * Кнопка на странице квитанции (без токена доступа).
	 *
	 * @param mixed $order
	 */
	public function renderButton( $order = null ): void {
		if ( $this->buttonRendered || ! is_object( $order ) || empty( $order->id ) ) {
			return;
		}

		$page = $this->settings->pageUrl();

		if ( '' === $page || empty( $this->paidPurchases( (int) $order->id ) ) ) {
			return;
		}

		$this->buttonRendered = true;

		echo '<p class="bgdvzh-receipt"><a class="button" href="' . esc_url( $page . '#anketa-dvzh' ) . '">' . esc_html( 'Открыть инструкцию' ) . '</a><br><small>'
			. esc_html( 'Ссылка для доступа с другого устройства отправлена на email заказа.' ) . '</small></p>';
	}

	/**
	 * Оплаченные (по живому EDD) покупки заказа с неочищенным снимком.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function paidPurchases( int $orderId ): array {
		$out = array();

		foreach ( $this->purchases->byOrder( $orderId ) as $purchase ) {
			$snapshot = $this->snapshots->findById( (int) $purchase['snapshot_id'] );

			if ( null === $snapshot || ! empty( $snapshot['purged_at'] ) ) {
				continue;
			}

			if ( $this->policy->paymentState( $purchase )['access'] ) {
				$out[] = $purchase;
			}
		}

		return $out;
	}
}
