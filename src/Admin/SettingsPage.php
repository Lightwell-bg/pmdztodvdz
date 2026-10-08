<?php
/**
 * Экран «Настройки»: товар EDD и вариант цены, страница анкеты, политика,
 * сроки хранения, такса, ориентир дохода, удаление данных при деинсталляции.
 * Товар здесь только выбирается: плагин его не создаёт и не публикует.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Commerce\EddGate;
use Bginfo\PmzhDvzh\Support\Settings;

final class SettingsPage {

	public const SLUG   = 'bgdvzh-settings';
	public const ACTION = 'bgdvzh_save_settings';

	public function __construct( private Settings $settings, private EddGate $gate ) {
	}

	/** Обработчик admin-post: сохранение формы. */
	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$in      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверено выше.
		$current = $this->settings->all();
		$input   = array();
		$notice  = 'saved';

		foreach ( array( 'privacy_url', 'retention_days_drafts', 'retention_days_unpaid', 'link_ttl_days', 'fee_text', 'fee_verified_at', 'income_min_monthly', 'income_currency', 'income_verified_at', 'income_guide_text' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$input[ $key ] = $in[ $key ];
			}
		}

		$input['delete_on_uninstall'] = ! empty( $in['delete_on_uninstall'] ) ? '1' : '';

		// Товар: только существующий download, не в корзине.
		$newDownload = isset( $in['download_id'] ) ? max( 0, (int) $in['download_id'] ) : (int) $current['download_id'];

		if ( $newDownload > 0 ) {
			$post = get_post( $newDownload );

			if ( ! $post instanceof \WP_Post || 'download' !== $post->post_type || 'trash' === $post->post_status ) {
				$newDownload = (int) $current['download_id'];
				$notice      = 'bad_download';
			}
		}

		$input['download_id'] = $newDownload;

		// Вариант цены: только если товар не менялся и вариант существует.
		$priceId = null;

		if ( $newDownload > 0 && $newDownload === (int) $current['download_id'] && isset( $in['price_id'] ) && '' !== $in['price_id'] ) {
			$prices = $this->prices( $newDownload );

			if ( array_key_exists( (int) $in['price_id'], $prices ) ) {
				$priceId = (int) $in['price_id'];
			}
		}

		$input['price_id'] = $priceId;

		// Страница: только существующая страница сайта.
		$pageId = isset( $in['page_id'] ) ? max( 0, (int) $in['page_id'] ) : 0;

		if ( $pageId > 0 && 'page' !== get_post_type( $pageId ) ) {
			$pageId = 0;
		}

		$input['page_id'] = $pageId;

		$this->settings->update( $input );

		if ( 'saved' === $notice && $newDownload !== (int) $current['download_id'] && $this->isVariable( $newDownload ) ) {
			$notice = 'choose_price';
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'bgdvzh_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		$s        = $this->settings->all();
		$download = (int) $s['download_id'];

		echo '<div class="wrap"><h1>' . esc_html( 'ПМЖ → ДВЖ: настройки' ) . '</h1>';
		$this->notice();
		$this->gateStatus();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );

		echo '<h2>' . esc_html( 'Продажа инструкции (Easy Digital Downloads)' ) . '</h2><table class="form-table" role="presentation">';

		// Товар.
		echo '<tr><th scope="row"><label for="bgdvzh_download_id">' . esc_html( 'Товар EDD' ) . '</label></th><td>';

		$products = get_posts(
			array(
				'post_type'      => 'download',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		echo '<select name="download_id" id="bgdvzh_download_id"><option value="0">' . esc_html( '— не выбран —' ) . '</option>';

		foreach ( $products as $p ) {
			$label = ( '' !== $p->post_title ? $p->post_title : '(без названия)' ) . ' [#' . $p->ID . ', ' . $p->post_status . ']';
			echo '<option value="' . esc_attr( (string) $p->ID ) . '"' . selected( $download, $p->ID, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select><p class="description">' . esc_html( 'Выбирается существующий товар. Плагин не создаёт и не публикует товары. Если вы сменили товар, нажмите «Сохранить»: после этого появится выбор варианта цены.' ) . '</p></td></tr>';

		// Вариант цены.
		if ( $download > 0 && $this->isVariable( $download ) ) {
			echo '<tr><th scope="row"><label for="bgdvzh_price_id">' . esc_html( 'Вариант цены' ) . '</label></th><td><select name="price_id" id="bgdvzh_price_id"><option value="">' . esc_html( '— выберите вариант —' ) . '</option>';

			foreach ( $this->prices( $download ) as $pid => $price ) {
				$name   = isset( $price['name'] ) ? (string) $price['name'] : ( '#' . $pid );
				$amount = isset( $price['amount'] ) ? ' — ' . (string) $price['amount'] : '';
				echo '<option value="' . esc_attr( (string) $pid ) . '"' . selected( (string) $s['price_id'], (string) $pid, false ) . '>' . esc_html( $name . $amount ) . '</option>';
			}

			echo '</select><p class="description">' . esc_html( 'У выбранного товара несколько вариантов цены: укажите тот, который продаётся как персональная инструкция.' ) . '</p></td></tr>';
		}

		// Страница.
		echo '<tr><th scope="row"><label for="bgdvzh_page_id">' . esc_html( 'Страница с анкетой' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'page_id',
				'id'                => 'bgdvzh_page_id',
				'selected'          => (int) $s['page_id'],
				'show_option_none'  => '— не выбрана —',
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html( 'Страница, где размещён шорткод [bginfo_pmzh_dvzh_questionnaire]. Используется в ссылках из писем и после оплаты.' ) . '</p></td></tr>';

		// Политика.
		echo '<tr><th scope="row"><label for="bgdvzh_privacy_url">' . esc_html( 'Адрес политики конфиденциальности' ) . '</label></th><td><input type="url" class="regular-text" name="privacy_url" id="bgdvzh_privacy_url" value="' . esc_attr( (string) $s['privacy_url'] ) . '"><p class="description">' . esc_html( 'Укажите адрес действующей политики сайта. Ссылка показывается на сводке анкеты.' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html( 'Сроки' ) . '</h2><table class="form-table" role="presentation">';
		$this->numberRow( 'retention_days_drafts', 'Хранение черновиков, дней', (int) $s['retention_days_drafts'], 1, 365, 'Сессия и черновик удаляются через столько дней без активности.' );
		$this->numberRow( 'retention_days_unpaid', 'Хранение неоплаченных анкет, дней', (int) $s['retention_days_unpaid'], 1, 730, 'Ответы неоплаченных анкет обнуляются через столько дней. Оплаченные анкеты не очищаются автоматически.' );
		$this->numberRow( 'link_ttl_days', 'Срок действия ссылки из письма, дней', (int) $s['link_ttl_days'], 1, 365, 'Для доступа к купленной инструкции с другого устройства.' );
		echo '</table>';

		echo '<h2>' . esc_html( 'Справочные данные в инструкции' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bgdvzh_fee_text">' . esc_html( 'Такса за статут' ) . '</label></th><td><input type="text" class="large-text" name="fee_text" id="bgdvzh_fee_text" value="' . esc_attr( (string) $s['fee_text'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="bgdvzh_fee_verified_at">' . esc_html( 'Такса проверена' ) . '</label></th><td><input type="date" name="fee_verified_at" id="bgdvzh_fee_verified_at" value="' . esc_attr( (string) $s['fee_verified_at'] ) . '"><p class="description">' . esc_html( 'Дата, когда вы сверили таксу с официальным источником.' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="bgdvzh_income_min_monthly">' . esc_html( 'Ориентир дохода в месяц на человека' ) . '</label></th><td><input type="text" inputmode="decimal" name="income_min_monthly" id="bgdvzh_income_min_monthly" value="' . esc_attr( null === $s['income_min_monthly'] ? '' : (string) $s['income_min_monthly'] ) . '"> ';
		echo '<select name="income_currency" aria-label="' . esc_attr( 'Валюта' ) . '">';

		foreach ( array( 'EUR', 'BGN' ) as $cur ) {
			echo '<option value="' . esc_attr( $cur ) . '"' . selected( (string) $s['income_currency'], $cur, false ) . '>' . esc_html( $cur ) . '</option>';
		}

		echo '</select><p class="description">' . esc_html( 'Необязательно. Закон требует «достаточные средства не ниже минимальной заработной платы и минимальной пенсии»; здесь вы можете задать собственный ориентир. Пока поле пустое, сравнение дохода не выполняется.' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="bgdvzh_income_verified_at">' . esc_html( 'Ориентир дохода проверен' ) . '</label></th><td><input type="date" name="income_verified_at" id="bgdvzh_income_verified_at" value="' . esc_attr( (string) $s['income_verified_at'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="bgdvzh_income_guide_text">' . esc_html( 'Пояснение к ориентиру дохода' ) . '</label></th><td><textarea class="large-text" rows="3" maxlength="1000" name="income_guide_text" id="bgdvzh_income_guide_text">' . esc_textarea( (string) $s['income_guide_text'] ) . '</textarea></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html( 'Деинсталляция' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html( 'Данные плагина' ) . '</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1"' . checked( ! empty( $s['delete_on_uninstall'] ), true, false ) . '> ' . esc_html( 'Удалить таблицы и настройки при удалении плагина' ) . '</label><p class="description">' . esc_html( 'По умолчанию выключено: при удалении плагина анкеты, купленные инструкции и PDF сохраняются. Включайте, только если точно не нужны.' ) . '</p></td></tr></table>';

		submit_button( 'Сохранить' );
		echo '</form></div>';
	}

	/** Статус готовности продажи и инструкция по созданию товара. */
	private function gateStatus(): void {
		$reason = $this->gate->reason();

		echo '<h2>' . esc_html( 'Готовность продажи' ) . '</h2>';

		if ( '' === $reason ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( 'Всё готово: EDD подключён, товар выбран. Кнопка «Перейти к оплате» на странице анкеты включена.' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( 'Продажа отключена.' ) . '</strong> ' . esc_html( $reason ) . '</p></div>';
		}

		if ( (int) $this->settings->get( 'download_id', 0 ) <= 0 ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html( 'Как создать товар в EDD' ) . '</strong></p><ol>';

			foreach ( array(
				'Откройте «Загрузки → Добавить» (Downloads → Add New) в меню WordPress.',
				'Название: например, «Персональная инструкция по переходу на ДВЖ». Описание можно не заполнять.',
				'В блоке «Цены» укажите цену. Обычную цену (до скидки) задавайте, если она у вас используется. Вариативные цены не нужны: достаточно одной.',
				'Файлы к товару не прикрепляйте: инструкция формируется плагином и выдаётся на странице анкеты.',
				'Сохраните товар как черновик или опубликуйте скрытым от каталога (на ваше усмотрение). Плагин сам ничего не создаёт и не публикует.',
				'Вернитесь на эту страницу, выберите товар в списке выше и нажмите «Сохранить».',
			) as $step ) {
				echo '<li>' . esc_html( $step ) . '</li>';
			}

			echo '</ol></div>';
		}
	}

	private function notice(): void {
		$key = isset( $_GET['bgdvzh_notice'] ) ? sanitize_key( wp_unslash( $_GET['bgdvzh_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только выбор текста сообщения.

		$map = array(
			'saved'        => array( 'success', 'Настройки сохранены.' ),
			'choose_price' => array( 'warning', 'Настройки сохранены. У товара несколько вариантов цены: выберите вариант ниже.' ),
			'bad_download' => array( 'error', 'Выбранный товар не найден или удалён: прежний выбор сохранён, остальные настройки записаны.' ),
		);

		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] ) . '</p></div>';
		}
	}

	private function numberRow( string $name, string $label, int $value, int $min, int $max, string $hint ): void {
		echo '<tr><th scope="row"><label for="bgdvzh_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" name="' . esc_attr( $name ) . '" id="bgdvzh_' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"><p class="description">' . esc_html( $hint ) . '</p></td></tr>';
	}

	private function isVariable( int $downloadId ): bool {
		return function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $downloadId );
	}

	/** @return array<int,array<string,mixed>> */
	private function prices( int $downloadId ): array {
		if ( ! $this->isVariable( $downloadId ) || ! function_exists( 'edd_get_variable_prices' ) ) {
			return array();
		}

		$prices = edd_get_variable_prices( $downloadId );

		return is_array( $prices ) ? $prices : array();
	}
}
