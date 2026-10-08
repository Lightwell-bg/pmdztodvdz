<?php
/**
 * Экран «Консультации»: список с фильтрами, календарь месяца, карточка записи
 * и действия админа. Права manage_options, nonce на каждом действии, все
 * изменения только POST через admin-post.php.
 *
 * Токены и ссылки доступа посетителя нигде не показываются. Заметки админа
 * видны только здесь и в REST не попадают.
 *
 * Защита от устаревшей страницы: форма действия несёт версию состояния записи
 * (ConsultView::revision), для письменных ответов — число уже отправленных
 * ответов; при расхождении действие не выполняется.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Booking\BookingService;
use Bginfo\PmzhDvzh\Booking\Notifier;
use Bginfo\PmzhDvzh\Commerce\ConsultGate;
use Bginfo\PmzhDvzh\Schedule\SlotGenerator;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\MessageRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SlotRepository;
use Bginfo\PmzhDvzh\Support\Clock;

final class ConsultationsPage {

	public const SLUG   = 'bgdvzh-consultations';
	public const ACTION = 'bgdvzh_consult_act';

	private const OPS = array( 'reschedule', 'cancel', 'meet', 'complete', 'answer', 'retry', 'credit_resolved', 'notes' );

	private const NOTICES = array(
		'ok'               => array( 'success', 'Готово.' ),
		'bad_request'      => array( 'error', 'Запрос не распознан или запись не найдена.' ),
		'stale'            => array( 'warning', 'Запись изменилась, пока страница была открыта. Проверьте состояние и повторите действие.' ),
		'not_allowed'      => array( 'error', 'Это действие недоступно для текущего статуса записи.' ),
		'need_confirm'     => array( 'error', 'Отметьте подтверждение отмены.' ),
		'bad_meet'         => array( 'error', 'Ссылка должна иметь вид https://meet.google.com/abc-defg-hij.' ),
		'empty_answer'     => array( 'error', 'Введите тему и текст ответа.' ),
		'failed'           => array( 'error', 'Не удалось выполнить действие. Попробуйте ещё раз.' ),
		'no_slot'          => array( 'error', 'Выберите новое время из списка.' ),
		'time_unavailable' => array( 'error', 'Это время больше недоступно по расписанию. Выберите другое.' ),
		'time_taken'       => array( 'error', 'Это время уже занято. Выберите другое.' ),
		'date_unavailable' => array( 'error', 'Эта дата больше недоступна. Выберите другую.' ),
		'date_full'        => array( 'error', 'На эту дату мест нет. Выберите другую.' ),
		'not_reschedulable' => array( 'error', 'Запись в этом статусе перенести нельзя.' ),
	);

	private const NOTIFICATION_LABELS = array(
		'customer_confirmation'        => 'Посетителю: подтверждение',
		'customer_confirmation_resend' => 'Посетителю: подтверждение (повтор)',
		'customer_updated'             => 'Посетителю: данные обновлены',
		'customer_cancelled'           => 'Посетителю: отмена',
		'customer_meet_link'           => 'Посетителю: ссылка Meet',
		'customer_reminder'            => 'Посетителю: напоминание',
		'customer_written_answer'      => 'Посетителю: письменный ответ',
		'admin_new_paid'               => 'Админу: новая оплата',
		'admin_time_conflict'          => 'Админу: конфликт времени',
		'admin_credit_conflict'        => 'Админу: конфликт зачёта',
		'admin_refunded'               => 'Админу: возврат',
		'admin_disputed'               => 'Админу: спор',
		'admin_rescheduled'            => 'Админу: перенос',
		'admin_paid_after_cancel'      => 'Админу: оплата после отмены',
		'admin_mail_failed'            => 'Админу: сбой письма',
	);

	private BookingRepository $bookings;
	private Notifier $notifier;
	private MessageRepository $messages;
	private BookingService $service;
	private SlotRepository $slots;
	private ConsultGate $gate;
	private PurchaseRepository $purchases;
	private AccessPolicy $policy;

	/** @param array<string,object> $services Plugin::instance()->services */
	public function __construct( array $services ) {
		$this->bookings  = $services['bookings'];
		$this->notifier  = $services['notifier'];
		$this->messages  = $services['messages'];
		$this->service   = $services['bookingService'];
		$this->slots     = $services['slots'];
		$this->gate      = $services['consultGate'];
		$this->purchases = $services['purchases'];
		$this->policy    = $services['policy'];
	}

	/** Адрес карточки записи (просмотр, без изменения состояния). */
	public static function cardUrl( int $bookingId ): string {
		return add_query_arg( array( 'page' => self::SLUG, 'action' => 'view', 'id' => $bookingId ), admin_url( 'admin.php' ) );
	}

	// -----------------------------------------------------------------
	// Действия (POST).

	public function handleAction(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверяется ниже.
		$id = isset( $_POST['booking'] ) ? (int) $_POST['booking'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::ACTION . '_' . $op . '_' . $id );

		$booking = in_array( $op, self::OPS, true ) && $id > 0 ? $this->bookings->get( $id ) : null;

		if ( null === $booking ) {
			$this->redirect( $id, 'bad_request' );
		}

		$in     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- проверено выше.
		$in     = is_array( $in ) ? $in : array();
		$notice = $this->perform( $op, $booking, $in, Clock::timestamp() );

		$this->redirect( $id, $notice );
	}

	/**
	 * Выполнить действие и вернуть код сообщения.
	 *
	 * @param array<string,mixed> $booking
	 * @param array<string,mixed> $in
	 */
	private function perform( string $op, array $booking, array $in, int $now ): string {
		$id      = (int) $booking['id'];
		$allowed = ConsultView::allowedActions( $booking );

		if ( in_array( $op, array( 'reschedule', 'cancel', 'meet', 'complete', 'credit_resolved' ), true ) ) {
			if ( ! hash_equals( ConsultView::revision( $booking ), isset( $in['rev'] ) && is_string( $in['rev'] ) ? $in['rev'] : '' ) ) {
				return 'stale';
			}

			if ( ! in_array( $op, $allowed, true ) ) {
				return 'not_allowed';
			}
		}

		switch ( $op ) {
			case 'reschedule':
				$to = 'written' === (string) $booking['kind']
					? array( 'date' => isset( $in['date'] ) && is_string( $in['date'] ) ? $in['date'] : '' )
					: array( 'start_utc' => isset( $in['slot'] ) ? (int) $in['slot'] : 0 );

				if ( empty( $to['date'] ) && empty( $to['start_utc'] ) ) {
					return 'no_slot';
				}

				$res = $this->service->reschedule( $booking, $to, 'admin', $now );

				if ( ! $res['ok'] ) {
					return isset( self::NOTICES[ $res['error'] ?? '' ] ) ? (string) $res['error'] : 'failed';
				}

				$this->note( $booking, 'Перенос админом: ' . ConsultView::whenLabel( (array) $this->bookings->get( $id ) ), $now );

				return 'ok';

			case 'cancel':
				if ( empty( $in['confirm'] ) ) {
					return 'need_confirm';
				}

				if ( ! $this->service->cancel( $booking ) ) {
					return 'failed';
				}

				$this->note( $booking, 'Отмена админом (деньги не возвращались)', $now );

				return 'ok';

			case 'meet':
				$url = isset( $in['meet_url'] ) && is_string( $in['meet_url'] ) ? trim( $in['meet_url'] ) : '';

				if ( ! ConsultView::validMeetUrl( $url ) ) {
					return 'bad_meet';
				}

				$this->bookings->update( $id, array( 'meet_url' => $url ) );
				$this->notifier->queue( $id, 'customer_meet_link', $this->notifier->count( $id, 'customer_meet_link' ) + 1 );
				$this->note( $booking, 'Добавлена ссылка Meet', $now );

				return 'ok';

			case 'complete':
				return $this->service->complete( $booking ) ? 'ok' : 'failed';

			case 'answer':
				return $this->answer( $booking, $in, $now );

			case 'retry':
				$nid = isset( $in['notification'] ) ? (int) $in['notification'] : 0;

				foreach ( $this->notifier->forBooking( $id ) as $n ) {
					if ( (int) $n['id'] === $nid && 'failed' === (string) $n['status'] ) {
						$this->notifier->retry( $nid );

						return 'ok';
					}
				}

				return 'bad_request';

			case 'credit_resolved':
				$this->bookings->update( $id, array( 'credit_conflict' => 0 ) );
				$this->note( $booking, 'Конфликт зачёта разрешён вручную', $now );

				return 'ok';

			case 'notes':
				$text = isset( $in['admin_notes'] ) && is_string( $in['admin_notes'] ) ? mb_substr( sanitize_textarea_field( $in['admin_notes'] ), 0, 10000 ) : '';
				$this->bookings->update( $id, array( 'admin_notes' => $text ) );

				return 'ok';
		}

		return 'bad_request';
	}

	/**
	 * Письменный ответ: сообщение в историю и письмо (seq = номер ответа).
	 *
	 * @param array<string,mixed> $booking
	 * @param array<string,mixed> $in
	 */
	private function answer( array $booking, array $in, int $now ): string {
		$id   = (int) $booking['id'];
		$sent = $this->messages->countOut( $id );

		if ( (int) ( $in['answers'] ?? -1 ) !== $sent ) {
			return 'stale';
		}

		if ( null !== ConsultView::writtenCheck( $booking, $sent, $now ) ) {
			return 'not_allowed';
		}

		$subject = isset( $in['subject'] ) && is_string( $in['subject'] ) ? mb_substr( trim( sanitize_text_field( $in['subject'] ) ), 0, 200 ) : '';
		$body    = isset( $in['body'] ) && is_string( $in['body'] ) ? trim( mb_substr( sanitize_textarea_field( $in['body'] ), 0, 20000 ) ) : '';

		if ( '' === $subject || '' === $body ) {
			return 'empty_answer';
		}

		if ( $this->messages->addOut( $id, $subject, $body, (int) get_current_user_id() ) <= 0 ) {
			return 'failed';
		}

		if ( $sent >= 1 ) {
			$this->bookings->update( $id, array( 'followups_used' => (int) $booking['followups_used'] + 1 ) );
		}

		$this->notifier->queue( $id, 'customer_written_answer', $sent + 1 );
		$this->note( $booking, 'Письменный ответ № ' . ( $sent + 1 ), $now );

		return 'ok';
	}

	/** @param array<string,mixed> $booking */
	private function note( array $booking, string $line, int $now ): void {
		$fresh = $this->bookings->get( (int) $booking['id'] );
		$notes = null === $fresh ? '' : (string) ( $fresh['admin_notes'] ?? '' );

		$this->bookings->update( (int) $booking['id'], array( 'admin_notes' => ConsultView::appendNote( $notes, $line, $now ) ) );
	}

	private function redirect( int $id, string $notice ): void {
		$args = array( 'page' => self::SLUG, 'bgdvzh_notice' => $notice );

		if ( $id > 0 ) {
			$args['action'] = 'view';
			$args['id']     = $id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Экраны.

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав.' ), '', array( 'response' => 403 ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение.

		echo '<div class="wrap"><h1>' . esc_html( 'ПМЖ → ДВЖ: консультации' ) . '</h1>';
		$this->notice();

		if ( 'view' === $action ) {
			$id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$booking = $id > 0 ? $this->bookings->get( $id ) : null;

			if ( null === $booking ) {
				echo '<p>' . esc_html( 'Запись не найдена.' ) . '</p><p><a href="' . esc_url( $this->listUrl() ) . '">' . esc_html( '← К списку' ) . '</a></p>';
			} else {
				$this->card( $booking );
			}
		} else {
			$table = new ConsultationsList( $this->bookings, $this->notifier );
			$table->prepare_items();
			$table->display();
		}

		echo '</div>';
	}

	private function listUrl(): string {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	private function notice(): void {
		$key = isset( $_GET['bgdvzh_notice'] ) ? sanitize_key( wp_unslash( $_GET['bgdvzh_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только выбор текста сообщения.

		if ( isset( self::NOTICES[ $key ] ) ) {
			echo '<div class="notice notice-' . esc_attr( self::NOTICES[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( self::NOTICES[ $key ][1] ) . '</p></div>';
		}
	}

	// -----------------------------------------------------------------
	// Карточка.

	/** @param array<string,mixed> $b */
	private function card( array $b ): void {
		$id      = (int) $b['id'];
		$terms   = (array) $b['terms'];
		$now     = Clock::timestamp();
		$allowed = ConsultView::allowedActions( $b );
		$rev     = ConsultView::revision( $b );

		echo '<p><a href="' . esc_url( $this->listUrl() ) . '">' . esc_html( '← К списку' ) . '</a></p>';
		echo '<h2>' . esc_html( 'Запись № ' . $id . ': ' . ConsultView::formatLabel( $b ) ) . '</h2>';

		if ( 'needs_reschedule' === (string) $b['status'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( 'Конфликт времени: оплачено, но время уже было занято. Выберите новое время ниже («Перенести») — посетитель получит письмо.' ) . '</strong></p></div>';
		}

		if ( ! empty( $b['credit_conflict'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( 'Конфликт зачёта: зачёт стоимости инструкции использован повторно либо исходная инструкция возвращена. Оплата консультации и доступ сохранены; решите вопрос вручную.' ) . '</strong></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->formHead( 'credit_resolved', $id, $rev );
			submit_button( 'Разобрано', 'secondary', '', false );
			echo '</form></div>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->kv( 'Статус', ConsultView::statusLabel( (string) $b['status'] ) . ( '' !== (string) $b['status_reason'] ? ' (' . (string) $b['status_reason'] . ')' : '' ) );
		$this->kv( 'Время / срок', ConsultView::whenLabel( $b ) . ' — ' . ConsultView::zone( $b )->getName() );
		$this->kv( 'Часовой пояс посетителя', (string) $b['visitor_tz'] );
		$this->kv( 'Контакт', (string) $b['contact'] );
		$this->kv( 'Email', (string) $b['email'] );
		$this->kv( 'Вопрос', (string) $b['question'], true );

		if ( '' !== (string) $b['meet_url'] ) {
			$this->kv( 'Ссылка Meet', (string) $b['meet_url'] );
		}

		$snapshotUrl = SubmissionView::url( (int) $b['snapshot_id'] );
		echo '<tr><th scope="row">' . esc_html( 'Анкета' ) . '</th><td><a href="' . esc_url( $snapshotUrl ) . '">' . esc_html( 'Открыть анкету (снимок № ' . (int) $b['snapshot_id'] . ')' ) . '</a></td></tr>';

		$purchase = (int) $b['purchase_id'] > 0 ? $this->purchases->byId( (int) $b['purchase_id'] ) : null;
		$orderCell = (int) $b['order_id'] > 0 ? '<a href="' . esc_url( Display::orderUrl( (int) $b['order_id'] ) ) . '">' . esc_html( 'Заказ EDD #' . (int) $b['order_id'] ) . '</a>' : esc_html( 'Заказа пока нет' );

		if ( null !== $purchase ) {
			$orderCell .= ' — ' . esc_html( Display::paymentLabel( $this->policy->paymentState( $purchase )['state'] ) );
		}

		echo '<tr><th scope="row">' . esc_html( 'Покупка' ) . '</th><td>' . $orderCell . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- части экранированы выше.
		echo '</tbody></table>';

		$this->termsBlock( $b, $terms );
		$this->actions( $b, $allowed, $rev, $now );
		$this->failedMailBlock( $b );
		$this->messagesBlock( $b );

		echo '<h3>' . esc_html( 'Заметки (видны только администратору)' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->formHead( 'notes', $id );
		echo '<textarea name="admin_notes" rows="6" class="large-text" maxlength="10000">' . esc_textarea( (string) ( $b['admin_notes'] ?? '' ) ) . '</textarea>';
		submit_button( 'Сохранить заметки', 'secondary' );
		echo '</form>';
	}

	/**
	 * @param array<string,mixed> $b
	 * @param array<string,mixed> $t
	 */
	private function termsBlock( array $b, array $t ): void {
		$p = (array) ( $t['price'] ?? array() );

		echo '<h3>' . esc_html( 'Условия записи и сумма' ) . '</h3><table class="widefat striped" style="max-width:720px"><tbody>';
		$this->tr( 'Способ связи', (string) ( $t['initiator'] ?? '' ) );

		if ( ! empty( $t['written'] ) ) {
			$w = (array) $t['written'];
			$this->tr( 'Состав', (string) ( $w['scope'] ?? '' ) );
			$this->tr( 'Срок ответа', (int) ( $w['business_days'] ?? 0 ) . ' раб. дн. после выбранной даты' );
			$this->tr( 'Уточнения', 'использовано ' . (int) $b['followups_used'] . ' из ' . (int) ( $w['followups'] ?? 0 ) . ', в течение ' . (int) ( $w['followup_days'] ?? 0 ) . ' дн. после срока ответа' );
		}

		$this->tr( 'Цена', 'база ' . (string) ( $p['base'] ?? '' ) . ', зачёт ' . (string) ( $p['credit'] ?? '0' ) . ', к оплате ' . (string) ( $p['item'] ?? '' ) . ' ' . (string) ( $p['currency'] ?? '' ) );
		echo '</tbody></table>';
	}

	/**
	 * @param array<string,mixed> $b
	 * @param list<string>        $allowed
	 */
	private function actions( array $b, array $allowed, string $rev, int $now ): void {
		$id = (int) $b['id'];

		echo '<h3>' . esc_html( 'Действия' ) . '</h3>';

		if ( empty( $allowed ) ) {
			echo '<p>' . esc_html( 'Для этого статуса действий нет.' ) . '</p>';
		}

		if ( in_array( 'reschedule', $allowed, true ) ) {
			$this->rescheduleForm( $b, $rev, $now );
		}

		if ( in_array( 'meet', $allowed, true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';
			$this->formHead( 'meet', $id, $rev );
			echo '<label>' . esc_html( 'Ссылка Google Meet ' ) . '<input type="url" class="regular-text" name="meet_url" placeholder="https://meet.google.com/abc-defg-hij" value="' . esc_attr( (string) $b['meet_url'] ) . '"></label> ';
			submit_button( 'Сохранить и отправить посетителю', 'secondary', '', false );
			echo '</form>';
		}

		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0">';

		foreach ( array( 'complete' => 'Выполнено' ) as $op => $label ) {
			if ( in_array( $op, $allowed, true ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				$this->formHead( $op, $id, $rev );
				submit_button( $label, 'secondary', '', false );
				echo '</form>';
			}
		}

		echo '</div>';

		if ( in_array( 'answer', $allowed, true ) ) {
			$this->answerForm( $b, $now );
		}

		if ( in_array( 'cancel', $allowed, true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0;padding:8px 12px;border:1px solid #d63638;background:#fff">';
			$this->formHead( 'cancel', $id, $rev );
			echo '<p><strong>' . esc_html( 'Отменить запись' ) . '</strong><br>' . esc_html( 'Деньги автоматически не возвращаются — возврат делается в EDD отдельно.' ) . '</p>';
			echo '<label><input type="checkbox" name="confirm" value="1"> ' . esc_html( 'Подтверждаю отмену записи' ) . '</label> ';
			submit_button( 'Отменить запись', 'delete', '', false );
			echo '</form>';
		}
	}

	/** @param array<string,mixed> $b */
	private function rescheduleForm( array $b, string $rev, int $now ): void {
		$opts = $this->rescheduleOptions( $b, $now );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';

		if ( is_string( $opts ) ) {
			echo '<p>' . esc_html( 'Перенос: ' . $opts ) . '</p></form>';

			return;
		}

		$this->formHead( 'reschedule', (int) $b['id'], $rev );
		$written = 'written' === (string) $b['kind'];
		echo '<label>' . esc_html( $written ? 'Новая дата ' : 'Новое время ' ) . '<select name="' . ( $written ? 'date' : 'slot' ) . '">';

		foreach ( $opts as $o ) {
			echo '<option value="' . esc_attr( $o['value'] ) . '">' . esc_html( $o['label'] ) . '</option>';
		}

		echo '</select></label> ';
		submit_button( 'Перенести', 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Свободные слоты (или даты) на горизонт расписания.
	 *
	 * @param array<string,mixed> $b
	 * @return list<array{value:string,label:string}>|string Список либо причина, почему его нет.
	 */
	private function rescheduleOptions( array $b, int $now ): array|string {
		$cfg = $this->gate->scheduleConfig();

		if ( ! $cfg->isValid() ) {
			return 'расписание настроено неверно (см. «Настройки консультаций»).';
		}

		$gen = new SlotGenerator( $cfg );
		$out = array();

		if ( 'written' === (string) $b['kind'] ) {
			$limit = max( 0, (int) ( $this->gate->config()['written_per_day'] ?? 2 ) );

			foreach ( $gen->writtenDates( $now ) as $date ) {
				$deadline = $gen->writtenDeadline( $date );

				if ( null !== $deadline && $this->slots->seatsUsed( $date, $now ) < $limit ) {
					$out[] = array( 'value' => $date, 'label' => Display::date( $date ) . ' — ответ до ' . ConsultView::stamp( $deadline, $cfg->tz ) );
				}
			}
		} else {
			$cands = $gen->candidates( $now );
			$busy  = empty( $cands ) ? array() : $this->slots->busyUnits( $cands[0]['start'] - 3600, $cands[ count( $cands ) - 1 ]['block_end'] + 3600, $now );

			foreach ( $cands as $c ) {
				if ( SlotGenerator::isFree( $c, $busy ) && count( $out ) < 120 ) {
					$out[] = array( 'value' => (string) $c['start'], 'label' => ConsultView::stamp( $c['start'], $cfg->tz ) );
				}
			}
		}

		return empty( $out ) ? 'свободных вариантов в горизонте расписания нет.' : $out;
	}

	/** @param array<string,mixed> $b */
	private function answerForm( array $b, int $now ): void {
		$id   = (int) $b['id'];
		$sent = $this->messages->countOut( $id );
		$err  = ConsultView::writtenCheck( $b, $sent, $now );
		$w    = (array) ( ( (array) $b['terms'] )['written'] ?? array() );

		echo '<h4>' . esc_html( 'Письменный ответ' ) . '</h4>';
		echo '<p class="description">' . esc_html( 'Отправлено ответов: ' . $sent . '. Уточнений использовано: ' . (int) $b['followups_used'] . ' из ' . (int) ( $w['followups'] ?? 0 ) . '. Срок ответа: ' . ConsultView::whenLabel( $b ) . '.' ) . '</p>';

		if ( null !== $err ) {
			echo '<p>' . esc_html( $err ) . '</p>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->formHead( 'answer', $id );
		echo '<input type="hidden" name="answers" value="' . esc_attr( (string) $sent ) . '">';
		echo '<p><label>' . esc_html( 'Тема' ) . '<br><input type="text" class="large-text" name="subject" maxlength="200" value="' . esc_attr( 'Письменный ответ на ваш вопрос' ) . '"></label></p>';
		echo '<p><label>' . esc_html( 'Текст (простой текст, HTML удаляется)' ) . '<br><textarea name="body" rows="10" class="large-text" maxlength="20000"></textarea></label></p>';
		submit_button( 'Отправить ответ', 'primary', '', false );
		echo '</form>';
	}

	/**
	 * Неудавшиеся письма по записи с кнопкой «Отправить повторно».
	 *
	 * @param array<string,mixed> $b
	 */
	private function failedMailBlock( array $b ): void {
		$id = (int) $b['id'];

		foreach ( $this->notifier->forBooking( $id ) as $n ) {
			if ( 'failed' !== (string) $n['status'] ) {
				continue;
			}

			echo '<div class="notice notice-error inline"><p>' . esc_html( 'Письмо не отправлено (' . ( self::NOTIFICATION_LABELS[ $n['type'] ] ?? (string) $n['type'] ) . '): ' . (string) $n['last_error'] ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->formHead( 'retry', $id );
			echo '<input type="hidden" name="notification" value="' . esc_attr( (string) (int) $n['id'] ) . '">';
			submit_button( 'Отправить повторно', 'secondary', '', false );
			echo '</form></div>';
		}
	}

	/** @param array<string,mixed> $b */
	private function messagesBlock( array $b ): void {
		$rows = $this->messages->forBooking( (int) $b['id'] );

		if ( empty( $rows ) ) {
			return;
		}

		echo '<h3>' . esc_html( 'Письменные ответы' ) . '</h3>';

		foreach ( $rows as $m ) {
			echo '<div style="border:1px solid #c3c4c7;background:#fff;padding:8px 12px;margin:0 0 8px;max-width:720px"><p><strong>' . esc_html( (string) $m['subject'] ) . '</strong> <small>' . esc_html( Display::localTime( (string) $m['created_at'] ) . ', письмо: ' . (string) $m['mail_status'] ) . '</small></p>';
			echo '<div style="white-space:pre-wrap">' . esc_html( (string) $m['body'] ) . '</div></div>';
		}
	}

	// -----------------------------------------------------------------
	// Мелочи вывода.

	private function formHead( string $op, int $id, string $rev = '' ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="booking" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::ACTION . '_' . $op . '_' . $id ) ) . '">';

		if ( '' !== $rev ) {
			echo '<input type="hidden" name="rev" value="' . esc_attr( $rev ) . '">';
		}
	}

	private function kv( string $label, string $value, bool $multiline = false ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td' . ( $multiline ? ' style="white-space:pre-wrap"' : '' ) . '>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
	}

	private function tr( string $label, string $value ): void {
		echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
	}
}
