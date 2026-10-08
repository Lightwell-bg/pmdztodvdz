<?php
/**
 * Экран «Правила и источники»: только чтение. Правила зашиты в код плагина
 * (Rules\Evaluator), поэтому их изменение требует обновления плагина.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Rules\RulesMeta;

final class RulesPage {

	public const SLUG = 'bgdvzh-rules';

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html( 'ПМЖ → ДВЖ: правила и источники' ) . '</h1>';
		echo '<p>' . esc_html( 'Версия правил: ' ) . '<code>' . esc_html( RulesMeta::VERSION ) . '</code>. ' . esc_html( 'Источники проверены: ' . Display::date( RulesMeta::VERIFIED_AT ) . '.' ) . '</p>';
		echo '<p>' . esc_html( 'Правила расчёта встроены в код плагина и здесь не редактируются. Чтобы изменить правила (например, после изменения закона), нужно обновить плагин: новая версия правил записывается в каждую новую анкету, а уже купленные инструкции остаются привязанными к тем правилам, по которым были рассчитаны. Тексты пояснений редактируются на экране «Шаблоны».' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( 'Источник' ) . '</th><th>' . esc_html( 'Что из него используется' ) . '</th><th>' . esc_html( 'Адрес' ) . '</th><th>' . esc_html( 'Проверен' ) . '</th></tr></thead><tbody>';

		foreach ( RulesMeta::sources() as $source ) {
			echo '<tr><td>' . esc_html( $source['title'] ) . '</td><td>' . esc_html( $source['note'] ) . '</td><td><a href="' . esc_url( $source['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $source['url'] ) . '</a></td><td>' . esc_html( Display::date( $source['verified_at'] ) ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
