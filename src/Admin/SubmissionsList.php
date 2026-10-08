<?php
/**
 * Таблица анкет (WP_List_Table, 20 на страницу). Ответы, токены и ссылки
 * доступа здесь не показываются.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class SubmissionsList extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct(
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private DocumentRepository $documents
	) {
		parent::__construct(
			array(
				'singular' => 'анкета',
				'plural'   => 'анкеты',
				'ajax'     => false,
			)
		);
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		return array(
			'created_at' => 'Дата',
			'number'     => '№',
			'result'     => 'Результат',
			'summary'    => 'Кратко',
			'order'      => 'Заказ',
			'payment'    => 'Оплата',
			'document'   => 'Документ',
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$page = max( 1, $this->get_pagenum() );
		$data = $this->snapshots->paginate( $page, self::PER_PAGE );

		$items = array();

		foreach ( $data['items'] as $row ) {
			$purchases = $this->purchases->bySnapshot( (int) $row['id'] );
			$docStates = array();

			foreach ( $purchases as $p ) {
				$doc         = $this->documents->findByPurchase( (int) $p['id'] );
				$docStates[] = null === $doc ? '' : (string) $doc['state'];
			}

			$row['_purchases'] = $purchases;
			$row['_docs']      = $docStates;
			$items[]           = $row;
		}

		$this->items = $items;

		$this->set_pagination_args(
			array(
				'total_items' => $data['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function no_items(): void {
		echo esc_html( 'Анкет пока нет.' );
	}

	/** @param array<string,mixed> $item */
	protected function column_created_at( $item ): string {
		return esc_html( Display::localTime( (string) $item['created_at'] ) );
	}

	/** @param array<string,mixed> $item */
	protected function column_number( $item ): string {
		$label = Display::shortNumber( (string) $item['public_id'] );
		$out   = '<a href="' . esc_url( SubmissionView::url( (int) $item['id'] ) ) . '"><code>' . esc_html( $label ) . '</code></a>';

		if ( ! empty( $item['purged_at'] ) ) {
			$out .= ' <em>' . esc_html( '(данные удалены)' ) . '</em>';
		}

		return $out;
	}

	/** @param array<string,mixed> $item */
	protected function column_result( $item ): string {
		return esc_html( Display::resultLabel( (string) $item['status'] ) );
	}

	/** @param array<string,mixed> $item */
	protected function column_summary( $item ): string {
		return esc_html( (string) $item['summary'] );
	}

	/** @param array<string,mixed> $item */
	protected function column_order( $item ): string {
		$links = array();

		foreach ( (array) $item['_purchases'] as $p ) {
			$id      = (int) $p['order_id'];
			$links[] = '<a href="' . esc_url( Display::orderUrl( $id ) ) . '">#' . esc_html( (string) $id ) . '</a>';
		}

		return empty( $links ) ? '—' : implode( ', ', $links );
	}

	/** @param array<string,mixed> $item */
	protected function column_payment( $item ): string {
		$parts = array();

		foreach ( (array) $item['_purchases'] as $p ) {
			$parts[] = esc_html( Display::paymentLabel( (string) $p['state'] ) );
		}

		return empty( $parts ) ? esc_html( Display::paymentLabel( '' ) ) : implode( '<br>', $parts );
	}

	/** @param array<string,mixed> $item */
	protected function column_document( $item ): string {
		$parts = array();

		foreach ( (array) $item['_docs'] as $state ) {
			$parts[] = esc_html( Display::documentLabel( (string) $state ) );
		}

		return empty( $parts ) ? '—' : implode( '<br>', $parts );
	}

	/** @param array<string,mixed> $item */
	protected function column_default( $item, $column_name ): string {
		return '';
	}
}
