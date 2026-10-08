<?php
/**
 * Фронтенд: шорткод анкеты, подключение ассетов и ранний скрипт для токена в адресе.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Frontend;

use Bginfo\PmzhDvzh\Support\Settings;

final class Frontend {

	public const SHORTCODE = 'bginfo_pmzh_dvzh_questionnaire';

	private Settings $settings;

	/** Шорткод уже выведен на этой странице. */
	private bool $rendered = false;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_head', array( $this, 'early_fragment_script' ), 0 );
	}

	/** Есть ли шорткод в содержимом текущей записи. */
	private function is_questionnaire_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	public function enqueue(): void {
		if ( ! $this->is_questionnaire_page() ) {
			return;
		}

		$version  = defined( 'BGINFO_PMZH_DVZH_VERSION' ) ? (string) BGINFO_PMZH_DVZH_VERSION : '1.0.0';
		$base_url = defined( 'BGINFO_PMZH_DVZH_URL' ) ? (string) BGINFO_PMZH_DVZH_URL : plugins_url( '/', dirname( __DIR__, 2 ) . '/bginfo-pmzh-dvzh.php' );

		wp_enqueue_style( 'bgdvzh-questionnaire', $base_url . 'assets/questionnaire.css', array(), $version );
		wp_enqueue_script(
			'bgdvzh-questionnaire',
			$base_url . 'assets/questionnaire.js',
			array(),
			$version,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array();
		if ( method_exists( '\Bginfo\PmzhDvzh\Plugin', 'clientConfig' ) ) {
			$config = \Bginfo\PmzhDvzh\Plugin::clientConfig();
		}
		wp_add_inline_script(
			'bgdvzh-questionnaire',
			'window.BGDVZH_CONFIG = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}

	/** Токен доступа из фрагмента адреса: в sessionStorage и сразу убрать из адреса. */
	public function early_fragment_script(): void {
		if ( ! $this->is_questionnaire_page() ) {
			return;
		}
		?>
<script>
(function(){try{var h=location.hash||'';if(h.indexOf('#dvzh-k=')===0){var t=h.slice(8);if(/^[0-9a-fA-F]{64}$/.test(t)){try{sessionStorage.setItem('bgdvzh_k',t);}catch(e){}history.replaceState(null,'',location.pathname+location.search+'#anketa-dvzh');}}else if(h.indexOf('#dvzh-b=')===0){var b=h.slice(8);if(/^[0-9a-fA-F]{64}$/.test(b)){try{sessionStorage.setItem('bgdvzh_b',b);}catch(e){}history.replaceState(null,'',location.pathname+location.search+'#anketa-dvzh');}}}catch(e){}})();
</script>
		<?php
	}

	/** Шорткод: одинаковая для всех оболочка, без персональных данных. */
	public function render(): string {
		if ( $this->rendered ) {
			return '';
		}
		$this->rendered = true;

		return '<div class="bgdvzh" id="bgdvzh-app" data-bgdvzh>'
			. '<p class="bgdvzh-loading">Загружаем анкету…</p>'
			. '<noscript><p>Для анкеты нужен включённый JavaScript.</p></noscript>'
			. '</div>';
	}
}
