<?php
/**
 * Готовность продажи: установлен ли подходящий EDD и настроен ли товар.
 * Пока ok() = false, покупка отключена (анкета и предпросмотр работают),
 * а уже оплаченные результаты показывают «временно недоступно».
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Support\Settings;

final class EddGate {

	public const MIN_VERSION = '3.2';

	public function __construct( private Settings $settings ) {
	}

	/** EDD ≥ 3.2 и нужные функции доступны (без проверки настроек товара). */
	public static function eddAvailable(): bool {
		foreach ( array( 'edd_get_order', 'edd_add_to_cart', 'edd_get_order_items', 'edd_get_checkout_uri' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return false;
			}
		}

		return defined( 'EDD_VERSION' ) && version_compare( (string) EDD_VERSION, self::MIN_VERSION, '>=' );
	}

	/**
	 * Товар продаётся анонимному покупателю: опубликован и EDD разрешает
	 * покупку. Статус проверяется явно, потому что EDD_Download::can_purchase()
	 * для пользователя с правом редактирования пропускает и черновик.
	 */
	public static function purchasable( int $downloadId ): bool {
		if ( $downloadId <= 0 || ! function_exists( 'get_post_status' ) || 'publish' !== get_post_status( $downloadId ) || ! class_exists( '\\EDD_Download' ) ) {
			return false;
		}

		$download = new \EDD_Download( $downloadId );

		return (bool) $download->can_purchase();
	}

	public function downloadId(): int {
		return (int) $this->settings->get( 'download_id', 0 );
	}

	/** price_id для вариативной цены; null для товара с одной ценой. */
	public function priceId(): ?int {
		if ( ! $this->isVariable() ) {
			return null;
		}

		$p = $this->settings->get( 'price_id', null );

		return null === $p ? null : (int) $p;
	}

	public function isVariable(): bool {
		$id = $this->downloadId();

		return $id > 0 && function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $id );
	}

	/** Причина недоступности для уведомления администратору; '' если всё готово. */
	public function reason(): string {
		if ( ! self::eddAvailable() ) {
			return 'Для продажи инструкции нужен плагин Easy Digital Downloads версии ' . self::MIN_VERSION . ' или новее. Пока он не установлен или устарел, покупка отключена.';
		}

		$id = $this->downloadId();

		if ( $id <= 0 ) {
			return 'Не выбран товар EDD для персональной инструкции. Выберите его в настройках плагина.';
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || 'download' !== $post->post_type || 'trash' === $post->post_status ) {
			return 'Выбранный в настройках товар EDD не найден или удалён. Выберите товар заново.';
		}

		if ( ! self::purchasable( $id ) ) {
			return 'Товар EDD для персональной инструкции не опубликован или недоступен для покупки (черновик не годится). Опубликуйте товар.';
		}

		if ( $this->isVariable() ) {
			$prices = function_exists( 'edd_get_variable_prices' ) ? edd_get_variable_prices( $id ) : array();
			$pid    = $this->priceId();

			if ( null === $pid || ! is_array( $prices ) || ! array_key_exists( $pid, $prices ) ) {
				return 'У товара EDD несколько вариантов цены, но вариант в настройках плагина не выбран или не существует.';
			}
		}

		return '';
	}

	public function ok(): bool {
		return '' === $this->reason();
	}

	/**
	 * Цена настроенного варианта в формате EDD (валюта по настройкам EDD).
	 * Берётся только из EDD, никогда от клиента. Пустая строка, если нельзя.
	 */
	public function priceHtml(): string {
		if ( ! $this->ok() || ! function_exists( 'edd_format_amount' ) || ! function_exists( 'edd_currency_filter' ) ) {
			return '';
		}

		$id = $this->downloadId();

		if ( $this->isVariable() ) {
			$prices = edd_get_variable_prices( $id );
			$pid    = (int) $this->priceId();
			$amount = isset( $prices[ $pid ]['amount'] ) ? $prices[ $pid ]['amount'] : null;
		} else {
			$amount = function_exists( 'edd_get_download_price' ) ? edd_get_download_price( $id ) : null;
		}

		if ( null === $amount || '' === $amount ) {
			return '';
		}

		return wp_kses( (string) edd_currency_filter( edd_format_amount( $amount ) ), array() );
	}
}
