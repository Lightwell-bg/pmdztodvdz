<?php
/**
 * Таблица записей на консультацию (WP_List_Table, 20 на страницу).
 * Контакты, вопросы и ссылки доступа здесь не показываются.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Booking\Notifier;
use Bginfo\PmzhDvzh\Storage\BookingRepository;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ConsultationsList extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct(
		private BookingRepository $bookings,
		private Notifier $notifier
	) {
		parent::__construct(
			array(
				'singular' => 'запись',
				'plural'   => 'записи',
				'ajax'     => false,
			)
		);
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		return array(
			'when'   => 'Дата и время / срок',
			'format' => 'Формат',
			'status' => 'Статус',
			'order'  => 'Заказ',
			'amount' => 'Сумма',
			'credit' => 'Зачёт',
			'flags'  => 'Флаги',
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$page = max( 1, $this->get_pagenum() );
		$data = $this->bookings->listForAdmin( array(), $page, self::PER_PAGE );
		$rows = array();

		foreach ( $data['items'] as $b ) {
			$failed = false;

			foreach ( $this->notifier->forBooking( (int) $b['id'] ) as $n ) {
				if ( 'failed' === (string) $n['status'] ) {
					$failed = true;
					break;
				}
			}

			$b['_failed'] = $failed;
			$rows[]       = $b;
		}

		$this->items = $rows;

		$this->set_pagination_args(
			array(
				'total_items' => $data['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function no_items(): void {
		echo esc_html( 'Записей по этим условиям нет.' );
	}

	/** @param array<string,mixed> $item */
	protected function column_when( $item ): string {
		$url = ConsultationsPage::cardUrl( (int) $item['id'] );

		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( ConsultView::whenLabel( $item ) ) . '</strong></a><br><small>№ ' . esc_html( (string) $item['id'] ) . '</small>';
	}

	/** @param array<string,mixed> $item */
	protected function column_format( $item ): string {
		return esc_html( ConsultView::formatLabel( $item ) );
	}

	/** @param array<string,mixed> $item */
	protected function column_status( $item ): string {
		return esc_html( ConsultView::statusLabel( (string) $item['status'] ) );
	}

	/** @param array<string,mixed> $item */
	protected function column_order( $item ): string {
		$id = (int) $item['order_id'];

		return $id > 0 ? '<a href="' . esc_url( Display::orderUrl( $id ) ) . '">#' . esc_html( (string) $id ) . '</a>' : '—';
	}

	/** @param array<string,mixed> $item */
	protected function column_amount( $item ): string {
		$p = (array) ( ( (array) $item['terms'] )['price'] ?? array() );

		return '' === (string) ( $p['item'] ?? '' ) ? '—' : esc_html( (string) $p['item'] . ' ' . (string) ( $p['currency'] ?? '' ) );
	}

	/** @param array<string,mixed> $item */
	protected function column_credit( $item ): string {
		$p      = (array) ( ( (array) $item['terms'] )['price'] ?? array() );
		$credit = (string) ( $p['credit'] ?? '0' );

		return (float) $credit > 0 ? esc_html( $credit . ' ' . (string) ( $p['currency'] ?? '' ) ) : '—';
	}

	/** @param array<string,mixed> $item */
	protected function column_flags( $item ): string {
		$flags = ConsultView::flags( $item, ! empty( $item['_failed'] ) );

		return empty( $flags ) ? '' : '<span style="color:#b32d2e">' . esc_html( implode( ', ', $flags ) ) . '</span>';
	}

	/** @param array<string,mixed> $item */
	protected function column_default( $item, $column_name ): string {
		return '';
	}
}
