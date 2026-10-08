<?php
/**
 * Уведомление администратору: EDD отсутствует/устарел либо не настроен
 * товар. Показывается только пользователям с правом manage_options.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Commerce\EddGate;

final class Notices {

	public function __construct( private EddGate $gate ) {
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reason = $this->gate->reason();

		if ( '' === $reason ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>BGINFO ПМЖ → ДВЖ:</strong> ' . esc_html( $reason ) . '</p></div>';
	}
}
