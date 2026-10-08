<?php
/**
 * Plugin Name:       BGINFO — Переход ПМЖ → ДВЖ
 * Description:       Анкета для граждан третьих стран с ПМЖ в Болгарии: предварительная оценка условий перехода на статус долгосрочно пребывающего (ДВЖ) и платная персональная инструкция (оплата через Easy Digital Downloads).
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            BGINFO
 * Text Domain:       bginfo-pmzh-dvzh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Файл должен разбираться и на старом PHP (до 8.1), чтобы показать
 * понятное уведомление вместо фатальной ошибки: никакого синтаксиса 8.x здесь.
 *
 * @package Bginfo\PmzhDvzh
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BGINFO_PMZH_DVZH_VERSION', '0.2.0' );
define( 'BGINFO_PMZH_DVZH_FILE', __FILE__ );
define( 'BGINFO_PMZH_DVZH_DIR', plugin_dir_path( __FILE__ ) );
define( 'BGINFO_PMZH_DVZH_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>' . esc_html(
				'Плагин «BGINFO — Переход ПМЖ → ДВЖ» требует PHP 8.1 или новее. Сейчас на сайте PHP ' . PHP_VERSION . '. Плагин не загружен.'
			) . '</p></div>';
		}
	);

	return;
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Bginfo\\PmzhDvzh\\';

		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = BGINFO_PMZH_DVZH_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Bginfo\\PmzhDvzh\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Bginfo\\PmzhDvzh\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Bginfo\\PmzhDvzh\\Plugin', 'boot' ) );
