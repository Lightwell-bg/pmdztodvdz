<?php
/**
 * Экран просмотра одной анкеты: ответы, замечания, предпросмотр, покупки с
 * живым состоянием оплаты, события, состояние документа и два действия
 * («Сгенерировать PDF заново», «Удалить данные анкеты»). Токены, cookie и
 * ссылки доступа нигде не показываются.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Commerce\EddGate;
use Bginfo\PmzhDvzh\Cron\CronHandler;
use Bginfo\PmzhDvzh\Instruction\Composer;
use Bginfo\PmzhDvzh\Instruction\TemplateStore;
use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotPurger;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Json;
use Bginfo\PmzhDvzh\Support\Settings;

final class SubmissionView {

	public const PAGE         = 'bgdvzh';
	public const ACTION_REGEN = 'bgdvzh_regen_pdf';
	public const ACTION_PURGE = 'bgdvzh_purge_snapshot';

	public function __construct(
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private DocumentRepository $documents,
		private EventRepository $events,
		private AccessPolicy $policy,
		private TemplateStore $templates,
		private Settings $settings,
		private SnapshotPurger $purger
	) {
	}

	/** Адрес экрана просмотра с nonce. */
	public static function url( int $snapshotId, string $notice = '' ): string {
		$args = array(
			'page'   => self::PAGE,
			'action' => 'view',
			'id'     => $snapshotId,
		);

		if ( '' !== $notice ) {
			$args['bgdvzh_notice'] = $notice;
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin.php' ) ), 'bgdvzh_view_' . $snapshotId );
	}

	/** admin-post: «Сгенерировать PDF заново». */
	public function handleRegen(): void {
		$this->guard();

		$snapshotId = isset( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверяется ниже.
		$purchaseId = isset( $_POST['purchase_id'] ) ? (int) $_POST['purchase_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::ACTION_REGEN . '_' . $snapshotId . '_' . $purchaseId );

		$snapshot = $this->snapshots->findById( $snapshotId );
		$purchase = $this->purchases->byId( $purchaseId );
		$notice   = 'regen_failed';

		// Покупка должна принадлежать именно этой анкете (идентификаторы из формы не доверяем).
		if ( null !== $snapshot && null !== $purchase && (int) $purchase['snapshot_id'] === (int) $snapshot['id'] && empty( $snapshot['purged_at'] ) ) {
			$this->documents->ensureRow( $purchaseId );
			$this->documents->resetForRegenerate( $purchaseId );
			$this->events->log( 'admin_regenerate_pdf', $purchaseId, (int) $purchase['order_id'], 'user:' . get_current_user_id() );
			wp_schedule_single_event( time() + 5, 'bgdvzh_generate', array( $purchaseId ) );
			$notice = 'regen_ok';
		}

		wp_safe_redirect( self::url( $snapshotId, $notice ) );
		exit;
	}

	/** admin-post: «Удалить данные анкеты». */
	public function handlePurge(): void {
		$this->guard();

		$snapshotId = isset( $_POST['snapshot_id'] ) ? (int) $_POST['snapshot_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверяется ниже.

		check_admin_referer( self::ACTION_PURGE . '_' . $snapshotId );

		$snapshot = $this->snapshots->findById( $snapshotId );
		$notice   = 'purge_failed';

		if ( null === $snapshot ) {
			$notice = 'purge_failed';
		} elseif ( empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$notice = 'purge_unconfirmed';
		} else {
			$this->purger->purge( $snapshotId, 'admin:' . get_current_user_id() );
			$notice = 'purge_ok';
		}

		wp_safe_redirect( self::url( $snapshotId, $notice ) );
		exit;
	}

	public function render(): void {
		$this->guard();

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- проверяется ниже.

		check_admin_referer( 'bgdvzh_view_' . $id );

		$snapshot = $this->snapshots->findById( $id );

		echo '<div class="wrap"><h1>' . esc_html( 'Анкета' ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">&larr; ' . esc_html( 'К списку анкет' ) . '</a></p>';
		$this->notice();

		if ( null === $snapshot ) {
			echo '<p>' . esc_html( 'Анкета не найдена.' ) . '</p></div>';
			return;
		}

		$purged    = ! empty( $snapshot['purged_at'] );
		$purchases = $this->purchases->bySnapshot( $id );

		$this->header( $snapshot, $purged );

		if ( $purged ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( 'Данные анкеты удалены ' . Display::localTime( (string) $snapshot['purged_at'] ) . ': ответы, результат, инструкция и PDF недоступны. Заказы и записи об оплате сохранены.' ) . '</p></div>';
		} else {
			$answers = Json::decode( (string) $snapshot['answers'] );
			$result  = Json::decode( (string) $snapshot['result'] );
			$preview = Json::decode( (string) $snapshot['preview'] );

			$this->answers( $answers );
			$this->findings( $snapshot, $result );
			$this->preview( $preview );
		}

		$this->purchasesBlock( $snapshot, $purchases, $purged );
		$this->eventsBlock( $purchases );

		if ( ! $purged ) {
			$this->purgeForm( $id );
		}

		echo '</div>';
	}

	/** @param array<string,mixed> $snapshot */
	private function header( array $snapshot, bool $purged ): void {
		$integrity = $purged ? '—' : ( SnapshotRepository::hashMatches( $snapshot ) ? 'контрольная сумма совпадает' : 'КОНТРОЛЬНАЯ СУММА НЕ СОВПАДАЕТ' );

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';

		foreach ( array(
			'№'                      => Display::shortNumber( (string) $snapshot['public_id'] ),
			'Создана'                => Display::localTime( (string) $snapshot['created_at'] ),
			'Результат'              => Display::resultLabel( (string) $snapshot['status'] ),
			'Кратко'                 => (string) $snapshot['summary'],
			'Версия правил'          => (string) $snapshot['rules_version'],
			'Версия шаблонов'        => (string) $snapshot['templates_version'],
			'Перешла к оплате'       => Display::localTime( isset( $snapshot['locked_at'] ) ? (string) $snapshot['locked_at'] : null ),
			'Целостность снимка'     => $integrity,
		) as $label => $value ) {
			echo '<tr><th scope="row" style="width:220px">' . esc_html( (string) $label ) . '</th><td>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $answers */
	private function answers( array $answers ): void {
		echo '<h2>' . esc_html( 'Ответы' ) . '</h2>';

		foreach ( AnswersFormatter::sections( $answers ) as $section ) {
			echo '<h3>' . esc_html( $section['title'] ) . '</h3><table class="widefat striped" style="max-width:900px"><tbody>';

			foreach ( $section['rows'] as $row ) {
				$value = '' === $row[1] ? '—' : $row[1];
				echo '<tr><th scope="row" style="width:280px">' . esc_html( $row[0] ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @param array<string,mixed> $result
	 */
	private function findings( array $snapshot, array $result ): void {
		echo '<h2>' . esc_html( 'Замечания расчёта' ) . '</h2>';

		$set      = $this->templates->get( (string) $snapshot['templates_version'] ) ?? $this->templates->current();
		$composer = new Composer(
			$set['data'],
			array(
				'fee_text'           => (string) $this->settings->get( 'fee_text', '' ),
				'fee_verified_at'    => (string) $this->settings->get( 'fee_verified_at', '' ),
				'income_guide_text'  => (string) $this->settings->get( 'income_guide_text', '' ),
				'income_verified_at' => (string) $this->settings->get( 'income_verified_at', '' ),
			)
		);

		$findings = isset( $result['findings'] ) && is_array( $result['findings'] ) ? $result['findings'] : array();

		if ( empty( $findings ) ) {
			echo '<p>' . esc_html( 'Нет данных.' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html( 'Важность' ) . '</th><th>' . esc_html( 'Код' ) . '</th><th>' . esc_html( 'Текст' ) . '</th></tr></thead><tbody>';

		foreach ( $findings as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['code'], $f['severity'] ) ) {
				continue;
			}

			$f['params'] = isset( $f['params'] ) && is_array( $f['params'] ) ? $f['params'] : array();
			$text        = $composer->text( $f, 'short' );

			if ( '' === $text ) {
				$text = $composer->text( $f, 'detail' );
			}

			echo '<tr><td>' . esc_html( Display::severityLabel( (string) $f['severity'] ) ) . '</td><td><code>' . esc_html( (string) $f['code'] ) . '</code></td><td>' . esc_html( '' === $text ? '—' : $text ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $preview */
	private function preview( array $preview ): void {
		echo '<h2>' . esc_html( 'Предварительный результат (что видел пользователь до оплаты)' ) . '</h2>';

		if ( empty( $preview ) ) {
			echo '<p>' . esc_html( 'Нет данных.' ) . '</p>';
			return;
		}

		echo '<div style="max-width:900px;background:#fff;border:1px solid #c3c4c7;padding:8px 16px">';
		echo '<h3>' . esc_html( (string) ( $preview['title'] ?? '' ) ) . '</h3><p>' . esc_html( (string) ( $preview['intro'] ?? '' ) ) . '</p>';

		if ( ! empty( $preview['reasons'] ) && is_array( $preview['reasons'] ) ) {
			echo '<ul style="list-style:disc;margin-left:20px">';

			foreach ( $preview['reasons'] as $reason ) {
				echo '<li>' . esc_html( (string) $reason ) . '</li>';
			}

			echo '</ul>';
		}

		echo '<p><em>' . esc_html( 'Дата расчёта: ' . (string) ( $preview['calc_date'] ?? '' ) . ', дата подачи: ' . (string) ( $preview['filing_date'] ?? '' ) ) . '</em></p></div>';
	}

	/**
	 * @param array<string,mixed>       $snapshot
	 * @param list<array<string,mixed>> $purchases
	 */
	private function purchasesBlock( array $snapshot, array $purchases, bool $purged ): void {
		echo '<h2>' . esc_html( 'Покупки' ) . '</h2>';

		if ( empty( $purchases ) ) {
			echo '<p>' . esc_html( 'Покупок по этой анкете нет.' ) . '</p>';
			return;
		}

		$eddOk = EddGate::eddAvailable();

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( 'Заказ' ) . '</th><th>' . esc_html( 'Позиция' ) . '</th><th>' . esc_html( 'Кэш' ) . '</th><th>' . esc_html( 'Сейчас в EDD' ) . '</th><th>' . esc_html( 'Сумма / возврат' ) . '</th><th>' . esc_html( 'Оплачена' ) . '</th><th>' . esc_html( 'Документ' ) . '</th><th>' . esc_html( 'Действие' ) . '</th></tr></thead><tbody>';

		foreach ( $purchases as $p ) {
			$live  = $eddOk ? $this->policy->paymentState( $p ) : null;
			$doc   = $this->documents->findByPurchase( (int) $p['id'] );
			$state = null === $doc ? '' : (string) $doc['state'];

			echo '<tr>';
			echo '<td><a href="' . esc_url( Display::orderUrl( (int) $p['order_id'] ) ) . '">#' . esc_html( (string) (int) $p['order_id'] ) . '</a></td>';
			echo '<td>' . esc_html( '#' . (int) $p['order_item_id'] . ( null !== $p['price_id'] ? ', вариант ' . (int) $p['price_id'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( Display::paymentLabel( (string) $p['state'] ) . ( '' !== (string) $p['state_reason'] ? ' (' . $p['state_reason'] . ')' : '' ) ) . '</td>';
			echo '<td>' . esc_html( null === $live ? 'EDD недоступен' : Display::paymentLabel( $live['state'] ) . ' [заказ: ' . $live['order_status'] . ', позиция: ' . $live['item_status'] . ']' ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) $p['item_total'], 2, ',', ' ' ) . ' / ' . number_format( (float) $p['refunded_total'], 2, ',', ' ' ) ) . '</td>';
			echo '<td>' . esc_html( Display::localTime( isset( $p['paid_at'] ) ? (string) $p['paid_at'] : null ) ) . '</td>';
			echo '<td>' . esc_html( Display::documentLabel( $state ) . ( null !== $doc ? ', попыток: ' . (int) $doc['attempts'] : '' ) );

			if ( null !== $doc && '' !== (string) $doc['last_error'] ) {
				echo '<br><small>' . esc_html( (string) $doc['last_error'] ) . '</small>';
			}

			echo '</td><td>';

			if ( ! $purged && null !== $live && $live['access'] ) {
				$this->regenForm( (int) $snapshot['id'], (int) $p['id'] );
			} else {
				echo '—';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function regenForm( int $snapshotId, int $purchaseId ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REGEN ) . '">';
		echo '<input type="hidden" name="snapshot_id" value="' . esc_attr( (string) $snapshotId ) . '">';
		echo '<input type="hidden" name="purchase_id" value="' . esc_attr( (string) $purchaseId ) . '">';
		wp_nonce_field( self::ACTION_REGEN . '_' . $snapshotId . '_' . $purchaseId );
		echo '<button type="submit" class="button">' . esc_html( 'Сгенерировать PDF заново' ) . '</button></form>';
	}

	/** @param list<array<string,mixed>> $purchases */
	private function eventsBlock( array $purchases ): void {
		echo '<h2>' . esc_html( 'События' ) . '</h2>';

		$events = array();

		foreach ( $purchases as $p ) {
			foreach ( $this->events->forPurchase( (int) $p['id'], 50 ) as $e ) {
				$events[] = $e;
			}
		}

		usort( $events, static fn( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] );

		if ( empty( $events ) ) {
			echo '<p>' . esc_html( 'Событий нет.' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html( 'Время' ) . '</th><th>' . esc_html( 'Событие' ) . '</th><th>' . esc_html( 'Детали' ) . '</th></tr></thead><tbody>';

		foreach ( array_slice( $events, 0, 100 ) as $e ) {
			echo '<tr><td>' . esc_html( Display::localTime( (string) $e['created_at'] ) ) . '</td><td><code>' . esc_html( (string) $e['event'] ) . '</code></td><td>' . esc_html( (string) $e['detail'] ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function purgeForm( int $snapshotId ): void {
		echo '<h2>' . esc_html( 'Удаление данных' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PURGE ) . '">';
		echo '<input type="hidden" name="snapshot_id" value="' . esc_attr( (string) $snapshotId ) . '">';
		wp_nonce_field( self::ACTION_PURGE . '_' . $snapshotId );
		echo '<p>' . esc_html( 'Удаляются ответы, результат, предпросмотр, инструкция и PDF; доступ по ссылкам отзывается. Заказ EDD и записи о покупках остаются. Действие необратимо.' ) . '</p>';
		echo '<p><label><input type="checkbox" name="confirm" value="1"> ' . esc_html( 'Я понимаю, что данные анкеты будут удалены безвозвратно' ) . '</label></p>';
		echo '<button type="submit" class="button button-link-delete">' . esc_html( 'Удалить данные анкеты' ) . '</button></form>';
	}

	private function notice(): void {
		$key = isset( $_GET['bgdvzh_notice'] ) ? sanitize_key( wp_unslash( $_GET['bgdvzh_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только выбор текста сообщения.

		$map = array(
			'regen_ok'          => array( 'success', 'PDF поставлен в очередь на повторную генерацию из того же снимка.' ),
			'regen_failed'      => array( 'error', 'Не удалось запустить повторную генерацию: покупка не найдена или данные анкеты удалены.' ),
			'purge_ok'          => array( 'success', 'Данные анкеты удалены.' ),
			'purge_unconfirmed' => array( 'warning', 'Отметьте подтверждение, чтобы удалить данные.' ),
			'purge_failed'      => array( 'error', 'Не удалось удалить данные: анкета не найдена.' ),
		);

		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] ) . '</p></div>';
		}
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}
	}
}
