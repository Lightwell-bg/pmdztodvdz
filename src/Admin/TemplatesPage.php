<?php
/**
 * Экран «Шаблоны»: тексты статусов, предложения, оговорок и пояснений по
 * каждому коду замечания. Сохранение создаёт новую версию набора;
 * уже купленные инструкции хранят собранный текст и не меняются.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Instruction\TemplateStore;
use Bginfo\PmzhDvzh\Storage\TemplateRepository;

final class TemplatesPage {

	public const SLUG   = 'bgdvzh-templates';
	public const ACTION = 'bgdvzh_save_templates';

	public function __construct( private TemplateStore $store, private TemplateRepository $repo ) {
	}

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверено выше.
		$data = TemplatesInput::fromPost( is_array( $post ) ? $post : array(), $this->store->current()['data'] );

		$version = $this->store->save( $data, get_current_user_id() );
		$notice  = null === $version ? 'failed' : 'saved';

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'bgdvzh_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		$current = $this->store->current();
		$d       = $current['data'];
		$notice  = isset( $_GET['bgdvzh_notice'] ) ? sanitize_key( wp_unslash( $_GET['bgdvzh_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только выбор текста сообщения.

		echo '<div class="wrap"><h1>' . esc_html( 'ПМЖ → ДВЖ: шаблоны текстов' ) . '</h1>';

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( 'Сохранено новой версией шаблонов.' ) . '</p></div>';
		} elseif ( 'failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'Не удалось сохранить шаблоны. Попробуйте ещё раз.' ) . '</p></div>';
		}

		echo '<p>' . esc_html( 'Текущая версия: ' ) . '<code>' . esc_html( $current['version'] ) . '</code>. ';
		echo esc_html( 'Каждое сохранение создаёт новую версию. Уже купленные инструкции сохраняют тот текст, который был при покупке, и не меняются. Только простой текст: HTML удаляется. В пояснениях можно использовать плейсхолдеры в фигурных скобках ({date}, {filing} и т. п.) так, как они стоят в исходных текстах.' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );

		echo '<h2>' . esc_html( 'Статусы предварительного результата' ) . '</h2>';
		$labels = array( 'ok' => 'Подходит', 'check' => 'Нужна проверка', 'obstacle' => 'Есть препятствие' );

		foreach ( $labels as $key => $label ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
			$this->input( 'status_titles[' . $key . ']', 'Заголовок', (string) ( $d['status_titles'][ $key ] ?? '' ) );
			$this->area( 'status_intro[' . $key . ']', 'Вступление', (string) ( $d['status_intro'][ $key ] ?? '' ), 3 );
		}

		$offer = (array) ( $d['offer'] ?? array() );

		echo '<h2>' . esc_html( 'Предложение инструкции' ) . '</h2>';
		$this->input( 'offer[title]', 'Заголовок предложения', (string) ( $offer['title'] ?? '' ) );
		$this->area( 'offer[lead]', 'Вводный абзац', (string) ( $offer['lead'] ?? '' ), 3 );
		$this->area( 'offer_items', 'Что входит (по одному пункту в строке)', implode( "\n", array_map( 'strval', (array) ( $offer['items'] ?? array() ) ) ), 6 );
		$this->area( 'offer[caveat_ok]', 'Оговорка при статусе «Подходит»', (string) ( $offer['caveat_ok'] ?? '' ), 3 );
		$this->area( 'offer[caveat_check]', 'Оговорка при статусе «Нужна проверка»', (string) ( $offer['caveat_check'] ?? '' ), 3 );
		$this->area( 'offer[caveat_obstacle]', 'Оговорка при статусе «Есть препятствие»', (string) ( $offer['caveat_obstacle'] ?? '' ), 3 );

		$offers = (array) ( $d['offers'] ?? array() );

		echo '<h2>' . esc_html( 'Карточки выбора: инструкция и консультация' ) . '</h2>';
		echo '<p class="description">' . esc_html( 'Тексты двух карточек после предварительного результата, предложение после покупки инструкции и пометка рекомендации. Только простой текст.' ) . '</p>';

		foreach ( array(
			'instruction_title'        => array( 'Инструкция: заголовок', false ),
			'instruction_text'         => array( 'Инструкция: описание', true ),
			'instruction_button'       => array( 'Инструкция: кнопка', false ),
			'consult_title'            => array( 'Консультация: заголовок', false ),
			'consult_text'             => array( 'Консультация: описание', true ),
			'consult_button'           => array( 'Консультация: кнопка', false ),
			'upsell_after_instruction' => array( 'Предложение консультации после покупки инструкции', true ),
			'consult_obstacle_note'    => array( 'Пояснение к консультации при препятствии', true ),
			'recommended_label'        => array( 'Пометка «Рекомендуем»', false ),
		) as $key => $field ) {
			if ( $field[1] ) {
				$this->area( 'offers[' . $key . ']', $field[0], (string) ( $offers[ $key ] ?? '' ), 3 );
			} else {
				$this->input( 'offers[' . $key . ']', $field[0], (string) ( $offers[ $key ] ?? '' ) );
			}
		}

		echo '<h2>' . esc_html( 'Общие тексты' ) . '</h2>';
		$this->area( 'disclaimer', 'Оговорка (disclaimer)', (string) ( $d['disclaimer'] ?? '' ), 4 );
		$this->area( 'method_note', 'Пояснение к методике подсчёта отсутствий', (string) ( $d['method_note'] ?? '' ), 8 );

		echo '<h2>' . esc_html( 'Пояснения по кодам замечаний' ) . '</h2>';
		echo '<p class="description">' . esc_html( '«Кратко» показывается в бесплатном предварительном результате, остальное входит в платную инструкцию.' ) . '</p>';

		$groups = array();

		foreach ( array_keys( (array) ( $d['findings'] ?? array() ) ) as $code ) {
			$groups[ TemplatesInput::area( (string) $code ) ][] = (string) $code;
		}

		foreach ( $groups as $group => $codes ) {
			echo '<h3>' . esc_html( $group ) . '</h3>';

			foreach ( $codes as $code ) {
				$f = (array) $d['findings'][ $code ];
				echo '<details style="margin:0 0 8px;border:1px solid #c3c4c7;background:#fff;padding:6px 12px"><summary><code>' . esc_html( $code ) . '</code></summary>';
				$this->area( 'findings[' . $code . '][short]', 'Кратко (до оплаты)', (string) ( $f['short'] ?? '' ), 3 );
				$this->area( 'findings[' . $code . '][detail]', 'Разбор (в инструкции)', (string) ( $f['detail'] ?? '' ), 4 );
				$this->area( 'findings[' . $code . '][action]', 'Что сделать', (string) ( $f['action'] ?? '' ), 3 );
				$this->area( 'findings[' . $code . '][question]', 'Вопрос для подразделения миграции', (string) ( $f['question'] ?? '' ), 2 );
				echo '</details>';
			}
		}

		submit_button( 'Сохранить новой версией' );
		echo '</form>';

		$this->versions( $current['version'] );
		echo '</div>';
	}

	private function versions( string $currentVersion ): void {
		echo '<h2>' . esc_html( 'Последние версии' ) . '</h2>';

		$rows = $this->repo->listVersions( 20 );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html( 'Сохранённых версий пока нет: используются тексты по умолчанию (' . TemplateStore::DEFAULT_VERSION . ').' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html( 'Версия' ) . '</th><th>' . esc_html( 'Дата' ) . '</th><th>' . esc_html( 'Автор' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user   = (int) $row['created_by'] > 0 ? get_userdata( (int) $row['created_by'] ) : false;
			$author = $user ? $user->display_name : '—';
			$mark   = $row['version'] === $currentVersion ? ' (текущая)' : '';
			echo '<tr><td><code>' . esc_html( $row['version'] ) . '</code>' . esc_html( $mark ) . '</td><td>' . esc_html( Display::localTime( $row['created_at'] ) ) . '</td><td>' . esc_html( $author ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function input( string $name, string $label, string $value ): void {
		echo '<p><label>' . esc_html( $label ) . '<br><input type="text" class="large-text" maxlength="' . esc_attr( (string) TemplatesInput::MAX_LEN ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></label></p>';
	}

	private function area( string $name, string $label, string $value, int $rows ): void {
		echo '<p><label>' . esc_html( $label ) . '<br><textarea class="large-text" rows="' . esc_attr( (string) $rows ) . '" maxlength="' . esc_attr( (string) TemplatesInput::MAX_LEN ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea></label></p>';
	}
}
