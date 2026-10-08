<?php
/**
 * Экран «Настройки консультаций»: товар EDD и цены форматов, консультант,
 * расписание, письменный разбор, форматы, резерв, перенос, напоминания,
 * адрес администратора и события аналитики.
 *
 * Вверху показываются причина, по которой консультация выключена (ConsultGate),
 * и ошибки расписания. Пока они есть, запись недоступна, а продажа
 * инструкции идёт как обычно. Товар здесь только выбирается: плагин его не
 * создаёт и не публикует.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Commerce\ConsultGate;
use Bginfo\PmzhDvzh\Http\ConsultController;
use Bginfo\PmzhDvzh\Support\ConsultSettings;
use Bginfo\PmzhDvzh\Support\Settings;

final class ConsultSettingsPage {

	public const SLUG   = 'bgdvzh-consult-settings';
	public const ACTION = 'bgdvzh_save_consult_settings';

	private const DAY_NAMES = array( 1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье' );

	public function __construct( private Settings $settings, private ConsultGate $gate ) {
	}

	/** Подключить скрипт выбора фото (только на этой странице). */
	public function registerAssets(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только выбор страницы.

		if ( self::SLUG !== $page || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'bgdvzh-consult-settings', BGINFO_PMZH_DVZH_URL . 'src/Admin/assets/consult-settings.js', array( 'jquery' ), BGINFO_PMZH_DVZH_VERSION, true );
	}

	// -----------------------------------------------------------------
	// Сохранение.

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверено выше.
		$in      = is_array( $post ) && isset( $post['consult'] ) && is_array( $post['consult'] ) ? $post['consult'] : array();
		$current = $this->settings->consultation();
		$notice  = 'saved';

		// Товар: только существующий download вне корзины.
		$download = isset( $in['download_id'] ) ? max( 0, (int) $in['download_id'] ) : (int) $current['download_id'];

		if ( $download > 0 ) {
			$p = get_post( $download );

			if ( ! $p instanceof \WP_Post || 'download' !== $p->post_type || 'trash' === $p->post_status ) {
				$download = (int) $current['download_id'];
				$notice  = 'bad_download';
			}
		}

		$in['download_id'] = $download;
		$changed           = $download !== (int) $current['download_id'];
		$prices            = $changed ? array() : $this->prices( $download );

		// Варианты цены: только существующие у выбранного (не изменённого) товара.
		$in['price_id'] = ! $changed && isset( $in['price_id'] ) && '' !== $in['price_id'] && array_key_exists( (int) $in['price_id'], $prices ) ? (int) $in['price_id'] : null;

		// Одна базовая цена для всех форматов: отдельных цен форматов в интерфейсе нет.
		$in['format_price_ids'] = array_fill_keys( ConsultSettings::FORMATS, null );

		// Расписание проще некуда: выходные даты (по одной в строке), без отпусков и интервалов по датам.
		$in['vacations'] = '';
		$in['exceptions'] = self::datesOnly( isset( $in['exceptions'] ) && is_string( $in['exceptions'] ) ? $in['exceptions'] : '' );

		// Шаг начала = длительность + перерыв (встречи встык с перерывом).
		$duration = isset( $in['duration_min'] ) ? (int) $in['duration_min'] : (int) $current['duration_min'];
		$break    = isset( $in['break_min'] ) ? (int) $in['break_min'] : (int) $current['break_min'];

		$in['step_min'] = max( 5, min( 240, $duration + $break ) );

		// Фото консультанта: только вложение-изображение.
		if ( isset( $in['consultant']['photo_id'] ) ) {
			$pid = max( 0, (int) $in['consultant']['photo_id'] );

			if ( $pid > 0 && ! wp_attachment_is_image( $pid ) ) {
				$pid = 0;
			}

			$in['consultant']['photo_id'] = $pid;
		}

		$this->settings->update( array( 'consult' => $in ) );

		if ( 'saved' === $notice && $changed && $this->isVariable( $download ) ) {
			$notice = 'choose_price';
		}

		$errors = $this->settings->consultationErrors();

		if ( 'saved' === $notice && ! empty( $errors ) ) {
			$notice = 'saved_errors';
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'bgdvzh_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Оставляет в каждой строке только дату YYYY-MM-DD (что после неё — отбрасывается);
	 * строка без даты сохраняется как есть, чтобы ошибку показала проверка расписания.
	 */
	public static function datesOnly( string $text ): string {
		$out = array();

		foreach ( preg_split( '/\R/u', $text ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$out[] = 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $line, $m ) ? $m[1] : mb_substr( $line, 0, 40 );
		}

		return implode( "\n", array_values( array_unique( $out ) ) );
	}

	// -----------------------------------------------------------------
	// Экран.

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		$c = $this->settings->consultation();

		echo '<div class="wrap"><h1>' . esc_html( 'ПМЖ → ДВЖ: настройки консультаций' ) . '</h1>';
		$this->notice();
		$this->status();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );

		$this->sectionProduct( $c );
		$this->sectionConsultant( $c );
		$this->sectionSchedule( $c );
		$this->sectionFormats( $c );
		$this->sectionAdmin( $c );

		submit_button( 'Сохранить' );
		echo '</form></div>';
	}

	private function status(): void {
		$reason = $this->gate->reason();
		$errors = $this->settings->consultationErrors();

		echo '<h2>' . esc_html( 'Готовность консультаций' ) . '</h2>';

		if ( null === $reason ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html( 'Консультации включены и готовы: посетители видят карточку записи.' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( 'Консультации отключены.' ) . '</strong> ' . esc_html( $reason ) . ' ' . esc_html( 'Продажа персональной инструкции при этом работает как обычно.' ) . '</p></div>';
		}

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html( 'Ошибки расписания и форматов:' ) . '</strong></p><ul style="list-style:disc;margin-left:24px">';

			foreach ( $errors as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}

			echo '</ul></div>';
		}
	}

	private function notice(): void {
		$key = isset( $_GET['bgdvzh_notice'] ) ? sanitize_key( wp_unslash( $_GET['bgdvzh_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только выбор текста сообщения.

		$map = array(
			'saved'        => array( 'success', 'Настройки сохранены.' ),
			'saved_errors' => array( 'warning', 'Настройки сохранены, но в них есть ошибки (см. вверху): пока они не исправлены, консультация отключена.' ),
			'choose_price' => array( 'warning', 'Настройки сохранены. У товара несколько вариантов цены: выберите базовый вариант и, при желании, цены форматов.' ),
			'bad_download' => array( 'error', 'Выбранный товар не найден или удалён: прежний выбор сохранён, остальные настройки записаны.' ),
		);

		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] ) . '</p></div>';
		}
	}

	/** @param array<string,mixed> $c */
	private function sectionProduct( array $c ): void {
		$download = (int) $c['download_id'];

		echo '<h2>' . esc_html( 'Включение и товар EDD' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html( 'Консультации' ) . '</th><td><input type="hidden" name="consult[enabled]" value="0"><label><input type="checkbox" name="consult[enabled]" value="1"' . checked( ! empty( $c['enabled'] ), true, false ) . '> ' . esc_html( 'Включить запись на консультации' ) . '</label></td></tr>';

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

		echo '<tr><th scope="row"><label for="bgdvzh_c_download">' . esc_html( 'Товар консультации' ) . '</label></th><td><select name="consult[download_id]" id="bgdvzh_c_download"><option value="0">' . esc_html( '— не выбран —' ) . '</option>';

		foreach ( $products as $p ) {
			$label = ( '' !== $p->post_title ? $p->post_title : '(без названия)' ) . ' [#' . $p->ID . ', ' . $p->post_status . ']';
			echo '<option value="' . esc_attr( (string) $p->ID ) . '"' . selected( $download, $p->ID, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select><p class="description">' . esc_html( 'Нужен отдельный товар, не тот же, что у персональной инструкции. Товар должен быть опубликован (черновик не годится). Если вы сменили товар, нажмите «Сохранить»: после этого появится выбор вариантов цены. Рекомендуемые значения вводятся вручную в EDD: инструкция 9,99 €, консультация 49 €.' ) . '</p></td></tr>';

		if ( $download > 0 && $this->isVariable( $download ) ) {
			$prices = $this->prices( $download );

			echo '<tr><th scope="row"><label for="bgdvzh_c_price">' . esc_html( 'Базовый вариант цены' ) . '</label></th><td>';
			$this->priceSelect( 'consult[price_id]', 'bgdvzh_c_price', $prices, null === $c['price_id'] ? null : (int) $c['price_id'], '— выберите вариант —' );
			echo '<p class="description">' . esc_html( 'Одна цена для всех форматов.' ) . '</p></td></tr>';

		} elseif ( $download > 0 ) {
			echo '<tr><th scope="row">' . esc_html( 'Цена' ) . '</th><td>' . esc_html( 'У товара одна цена, она применяется ко всем форматам.' ) . '</td></tr>';
		}

		echo '</table>';
	}

	/**
	 * @param array<int,array<string,mixed>> $prices
	 */
	private function priceSelect( string $name, string $id, array $prices, ?int $selected, string $empty ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"><option value="">' . esc_html( $empty ) . '</option>';

		foreach ( $prices as $pid => $price ) {
			$label = ( isset( $price['name'] ) ? (string) $price['name'] : '#' . $pid ) . ( isset( $price['amount'] ) ? ' — ' . (string) $price['amount'] : '' );
			echo '<option value="' . esc_attr( (string) $pid ) . '"' . selected( $selected, (int) $pid, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
	}

	/** @param array<string,mixed> $c */
	private function sectionConsultant( array $c ): void {
		$photo = (int) ( $c['consultant']['photo_id'] ?? 0 );
		$url   = $photo > 0 ? wp_get_attachment_image_url( $photo, 'thumbnail' ) : '';

		echo '<h2>' . esc_html( 'Консультант' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bgdvzh_c_name">' . esc_html( 'Имя' ) . '</label></th><td><input type="text" class="regular-text" maxlength="120" name="consult[consultant][name]" id="bgdvzh_c_name" value="' . esc_attr( (string) $c['consultant']['name'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="bgdvzh_c_exp">' . esc_html( 'Опыт (кратко)' ) . '</label></th><td><textarea class="large-text" rows="4" maxlength="600" name="consult[consultant][experience]" id="bgdvzh_c_exp">' . esc_textarea( (string) $c['consultant']['experience'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row">' . esc_html( 'Фото' ) . '</th><td><div id="bgdvzh-photo-preview" style="margin-bottom:8px">';

		if ( is_string( $url ) && '' !== $url ) {
			echo '<img src="' . esc_url( $url ) . '" alt="" style="max-width:120px;height:auto">';
		}

		echo '</div><input type="hidden" name="consult[consultant][photo_id]" id="bgdvzh-photo-id" value="' . esc_attr( (string) $photo ) . '">';
		echo '<button type="button" class="button" id="bgdvzh-photo-pick">' . esc_html( 'Выбрать фото' ) . '</button> <button type="button" class="button" id="bgdvzh-photo-clear">' . esc_html( 'Убрать' ) . '</button></td></tr>';
		echo '</table>';
	}

	/** @param array<string,mixed> $c */
	private function sectionSchedule( array $c ): void {
		echo '<h2>' . esc_html( 'Расписание' ) . '</h2><p class="description">' . esc_html( 'Время — по поясу консультанта. Границы интервалов и длительности кратны 5 минутам. По дням недели: 10:00-13:00, 14:00-18:00; пустое поле — выходной.' ) . '</p><table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="bgdvzh_c_tz">' . esc_html( 'Часовой пояс консультанта' ) . '</label></th><td><input type="text" class="regular-text" name="consult[tz]" id="bgdvzh_c_tz" value="' . esc_attr( (string) $c['tz'] ) . '"><p class="description">' . esc_html( 'Например, Europe/Sofia. Неверное значение не сохраняется.' ) . '</p></td></tr>';

		foreach ( self::DAY_NAMES as $dow => $name ) {
			$list = (array) ( $c['weekly'][ $dow ] ?? array() );
			echo '<tr><th scope="row"><label for="bgdvzh_c_wd' . esc_attr( (string) $dow ) . '">' . esc_html( $name ) . '</label></th><td><input type="text" class="regular-text" name="consult[weekly][' . esc_attr( (string) $dow ) . ']" id="bgdvzh_c_wd' . esc_attr( (string) $dow ) . '" value="' . esc_attr( implode( ', ', array_map( 'strval', $list ) ) ) . '"></td></tr>';
		}

		$dates = array();

		foreach ( (array) $c['exceptions'] as $ex ) {
			$dates[] = (string) ( $ex['date'] ?? '' );
		}

		echo '<tr><th scope="row"><label for="bgdvzh_c_exc">' . esc_html( 'Выходные даты' ) . '</label></th><td><textarea class="large-text code" rows="4" name="consult[exceptions]" id="bgdvzh_c_exc">' . esc_textarea( implode( "\n", $dates ) ) . '</textarea><p class="description">' . esc_html( 'По одной дате в строке: 2026-12-24. В эти дни записи нет.' ) . '</p></td></tr>';

		$this->numberRow( 'duration_min', 'Длительность встречи, мин.', (int) $c['duration_min'], 5, 600, 'Кратно 5.' );
		$this->numberRow( 'break_min', 'Перерыв после встречи, мин.', (int) $c['break_min'], 0, 240, 'Кратно 5. Время перерыва тоже занято; встречи идут встык с перерывом.' );
		$this->numberRow( 'written_per_day', 'Письменных разборов по email в день', (int) $c['written_per_day'], 0, 50, 'Сколько письменных разборов принимается на одну дату.' );
		echo '</table>';
	}

	/** @param array<string,mixed> $c */
	private function sectionAdmin( array $c ): void {
		echo '<h2>' . esc_html( 'Письма администратору' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="bgdvzh_c_mail">' . esc_html( 'Email администратора' ) . '</label></th><td><input type="email" class="regular-text" maxlength="190" name="consult[admin_email]" id="bgdvzh_c_mail" value="' . esc_attr( (string) $c['admin_email'] ) . '"><p class="description">' . esc_html( 'Сюда приходят уведомления об оплатах и сбоях; адрес показывается посетителю в письме («напишите на …»). Пусто — адрес администратора сайта.' ) . '</p></td></tr>';
		echo '</table>';
	}

	/** @param array<string,mixed> $c */
	private function sectionFormats( array $c ): void {
		echo '<h2>' . esc_html( 'Форматы' ) . '</h2><p class="description">' . esc_html( 'Текст «кто и как связывается» показывается посетителю и фиксируется в записи.' ) . '</p><table class="form-table" role="presentation">';

		foreach ( ConsultSettings::FORMATS as $f ) {
			$fc = (array) $c['formats'][ $f ];
			echo '<tr><th scope="row">' . esc_html( ConsultController::LABELS[ $f ] ?? $f ) . '</th><td><input type="hidden" name="consult[formats][' . esc_attr( $f ) . '][enabled]" value="0"><label><input type="checkbox" name="consult[formats][' . esc_attr( $f ) . '][enabled]" value="1"' . checked( ! empty( $fc['enabled'] ), true, false ) . '> ' . esc_html( 'Включён' ) . '</label><br>';
			echo '<input type="text" class="large-text" maxlength="300" name="consult[formats][' . esc_attr( $f ) . '][initiator_text]" value="' . esc_attr( (string) ( $fc['initiator_text'] ?? '' ) ) . '" aria-label="' . esc_attr( 'Текст для формата ' . $f ) . '"></td></tr>';
		}

		echo '</table>';
	}


	private function numberRow( string $key, string $label, int $value, int $min, int $max, string $hint ): void {
		echo '<tr><th scope="row"><label for="bgdvzh_c_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" name="consult[' . esc_attr( $key ) . ']" id="bgdvzh_c_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '"><p class="description">' . esc_html( $hint ) . '</p></td></tr>';
	}

	private function isVariable( int $downloadId ): bool {
		return $downloadId > 0 && function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $downloadId );
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
