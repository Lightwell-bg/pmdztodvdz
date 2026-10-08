<?php
/**
 * Сценарии BookingService на настоящем SQL репозиториев (SQLite):
 * занятость времени и мест, истечение резерва, поздняя оплата, зачёт,
 * спор, возврат, отмена, перенос.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class BookingServiceTest extends BookingTestCase {

	private const CREDIT = array( 'instruction_purchase_id' => 5, 'amount' => '9.99' );

	// -----------------------------------------------------------------
	// Занятость времени и мест.

	public function test_same_slot_twice_second_fails(): void {
		$a = $this->holdOk();
		$b = $this->hold( array( 'session_id' => 11 ) );

		$this->assertFalse( $b['ok'] );
		$this->assertSame( 'time_taken', $b['error'] );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'] ), '45 минут встречи + 15 перерыва = 12 единиц' );

		$rows = $this->bookings->listForAdmin( array( 'status' => 'held' ), 1 )['items'];
		$this->assertCount( 1, $rows, 'проигравшая запись не остаётся в held' );
	}

	public function test_overlap_including_break(): void {
		$this->holdOk(); // 10:00-10:45, блок до 11:00.

		$overlap = $this->hold( array( 'session_id' => 11, 'start_utc' => $this->start( 10, 45 ) ) );
		$this->assertFalse( $overlap['ok'] );
		$this->assertSame( 'time_taken', $overlap['error'], '10:45 попадает в перерыв предыдущей встречи' );

		$next = $this->hold( array( 'session_id' => 12, 'start_utc' => $this->start( 11 ) ) );
		$this->assertTrue( $next['ok'], '11:00 — сразу после перерыва' );
	}

	public function test_slot_outside_schedule_is_rejected(): void {
		$r = $this->hold( array( 'start_utc' => $this->start( 10, 7 ) ) );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'time_unavailable', $r['error'] );
	}

	public function test_last_email_seat(): void {
		$written = array( 'format' => 'email', 'mode' => 'written', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' );

		$this->assertTrue( $this->hold( $written + array( 'session_id' => 11 ) )['ok'] );
		$this->assertTrue( $this->hold( $written + array( 'session_id' => 12 ) )['ok'] );

		$third = $this->hold( $written + array( 'session_id' => 13 ) );
		$this->assertFalse( $third['ok'] );
		$this->assertSame( 'date_full', $third['error'] );

		$this->assertSame( 2, $this->slots->seatsUsed( '2026-06-11', $this->now ) );
	}

	public function test_written_hold_stores_deadline(): void {
		$b = $this->holdOk( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );

		$this->assertSame( 'written', $b['kind'] );
		$this->assertSame( '2026-06-11', $b['written_date'] );
		// Четверг 11.06 + 2 рабочих дня (все дни рабочие) = суббота 13.06, 23:59:59 по Софии (UTC+3).
		$this->assertSame( gmmktime( 20, 59, 59, 6, 13, 2026 ), $b['deadline_utc'] );
		$this->assertNull( $b['start_utc'] );
		$this->assertSame( 'written', $b['terms']['mode'] );
	}

	public function test_written_date_must_be_offered(): void {
		$r = $this->hold( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-10', 'contact' => 'guest@example.com' ) );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'date_unavailable', $r['error'], 'сегодня раньше срока уведомления' );
	}

	public function test_disabled_format_is_rejected(): void {
		$cfg = $this->cfg;
		$cfg['formats']['phone']['enabled'] = false;
		$svc = new \Bginfo\PmzhDvzh\Booking\BookingService( $this->bookings, $this->slots, $this->credits, $this->notifier, new \Bginfo\PmzhDvzh\Schedule\ScheduleConfig( $cfg ), $cfg );

		$r = $svc->hold( $this->req( array( 'format' => 'phone', 'contact' => '+359881234567' ) ), $this->now );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'format_unavailable', $r['error'] );
	}

	public function test_terms_are_frozen_in_booking(): void {
		$b = $this->holdOk( array( 'format' => 'whatsapp', 'mode' => 'call', 'contact' => '+359881234567' ) );

		$this->assertSame( 'call', $b['mode'] );
		$this->assertSame( 45, $b['terms']['duration_min'] );
		$this->assertSame( 'Europe/Sofia', $b['terms']['consultant_tz'] );
		$this->assertSame( 24, $b['terms']['reschedule']['hours_before'] );
		$this->assertSame( 1, $b['terms']['reschedule']['max'] );
		$this->assertTrue( $b['terms']['includes_instruction'] );
		$this->assertSame( '49.00', $b['terms']['price']['base'] );
		$this->assertSame( $this->now + 900, $b['hold_until'] );
	}

	public function test_replacing_active_hold_expires_previous(): void {
		$a = $this->holdOk();
		$b = $this->holdOk( array( 'start_utc' => $this->start( 12 ) ) );

		$this->assertSame( 'expired', $this->fresh( (int) $a['id'] )['status'] );
		$this->assertSame( 'replaced', $this->fresh( (int) $a['id'] )['status_reason'] );
		$this->assertSame( 0, $this->unitCount( (int) $a['id'] ), 'время прежней записи освобождено' );
		$this->assertSame( 12, $this->unitCount( (int) $b['id'] ) );
	}

	public function test_replacing_is_refused_while_previous_payment_is_in_progress(): void {
		$a = $this->holdOk();
		$r = $this->hold( array( 'start_utc' => $this->start( 12 ), 'prev_order_alive' => true ) );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'payment_in_progress', $r['error'] );
		$this->assertSame( 'held', $this->fresh( (int) $a['id'] )['status'] );
	}

	// -----------------------------------------------------------------
	// Оплата, поздняя оплата, идемпотентность.

	public function test_payment_confirms_and_queues_notifications_once(): void {
		$a = $this->holdOk();
		$id = (int) $a['id'];

		$this->assertSame( 'confirmed', $this->service->onPaid( $a, $this->now + 60 ) );
		$this->assertSame( 12, $this->unitCount( $id, true ), 'единицы подтверждены (без срока)' );
		$this->assertNotNull( $this->fresh( $id )['paid_at'] );

		// Повторные события оплаты (хуки EDD, GET /consult/booking, cron) ничего не дублируют.
		$this->service->onPaid( $this->fresh( $id ), $this->now + 70 );
		$this->service->onPaid( $a, $this->now + 80 );
		$this->service->onPaid( $this->fresh( $id ), $this->now + 90 );

		$this->assertSame( 'confirmed', $this->fresh( $id )['status'] );
		$this->assertSame( 1, $this->notes( $id, 'customer_confirmation' ) );
		$this->assertSame( 1, $this->notes( $id, 'admin_new_paid' ) );
		$this->assertSame( 0, $this->notes( $id, 'admin_time_conflict' ) );
	}

	public function test_stale_payload_cannot_double_confirm(): void {
		$a = $this->holdOk();

		// Две «параллельные» обработки с устаревшей строкой: условный переход срабатывает один раз.
		$this->service->onPaid( $a, $this->now + 60 );
		$this->service->onPaid( $a, $this->now + 61 );

		$this->assertSame( 1, $this->notes( (int) $a['id'], 'customer_confirmation' ) );
	}

	public function test_late_payment_after_other_claimed_slot_needs_reschedule(): void {
		$a     = $this->holdOk();
		$later = $this->now + 1000; // резерв (15 минут) истёк.

		$b = $this->holdOk( array( 'session_id' => 11 ), $later );

		$this->assertSame( 12, $this->unitCount( (int) $b['id'] ), 'другой посетитель занял истёкшее время' );
		$this->assertSame( 0, $this->unitCount( (int) $a['id'] ) );

		$this->assertSame( 'needs_reschedule', $this->service->onPaid( $a, $later + 5 ) );

		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( 'needs_reschedule', $row['status'] );
		$this->assertNotNull( $row['paid_at'], 'оплата не теряется' );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_time_conflict' ) );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'customer_confirmation' ) );
		$this->assertSame( 12, $this->unitCount( (int) $b['id'] ), 'время второго посетителя не тронуто' );
		$this->assertSame( 0, $this->unitCount( (int) $a['id'] ) );
	}

	public function test_late_payment_with_free_slot_confirms(): void {
		$a = $this->holdOk();

		$this->assertSame( 'confirmed', $this->service->onPaid( $a, $this->now + 1000 ) );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'], true ) );
	}

	public function test_late_payment_after_purge_reclaims_free_slot(): void {
		$a = $this->holdOk();

		$this->slots->purgeAllExpired( $this->now + 1000 );
		$this->assertSame( 0, $this->unitCount( (int) $a['id'] ) );

		$this->assertSame( 'confirmed', $this->service->onPaid( $a, $this->now + 1001 ) );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'], true ) );
	}

	public function test_late_payment_for_expired_booking(): void {
		$a     = $this->holdOk();
		$later = $this->now + 1000;

		$this->service->expireIfStale( $a, false, $later );
		$this->assertSame( 'expired', $this->fresh( (int) $a['id'] )['status'] );

		$this->assertSame( 'confirmed', $this->service->onPaid( $this->fresh( (int) $a['id'] ), $later + 1 ), 'оплата пришла уже после истечения — время свободно' );
	}

	public function test_expire_if_stale_respects_live_order(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );

		// Срок не вышел — ничего не происходит.
		$this->service->expireIfStale( $a, false, $this->now + 100 );
		$this->assertSame( 'held', $this->fresh( (int) $a['id'] )['status'] );

		// Срок вышел, но заказ жив: статус и зачёт сохраняются.
		$this->service->expireIfStale( $a, true, $this->now + 1000 );
		$this->assertSame( 'held', $this->fresh( (int) $a['id'] )['status'] );
		$this->assertSame( 'reserved', $this->creditRow( 5 )['state'] );

		// Заказа нет — резерв и зачёт освобождаются.
		$this->service->expireIfStale( $a, false, $this->now + 1000 );
		$this->assertSame( 'expired', $this->fresh( (int) $a['id'] )['status'] );
		$this->assertSame( 'timeout', $this->fresh( (int) $a['id'] )['status_reason'] );
		$this->assertSame( 'released', $this->creditRow( 5 )['state'] );
		$this->assertSame( 0, $this->unitCount( (int) $a['id'] ) );
	}

	// -----------------------------------------------------------------
	// Зачёт.

	public function test_credit_reserved_then_used_on_payment(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );

		$row = $this->creditRow( 5 );
		$this->assertSame( 'reserved', $row['state'] );
		$this->assertSame( (int) $a['id'], (int) $row['booking_id'] );
		$this->assertSame( 5, (int) $a['credit_purchase_id'] );
		$this->assertSame( '9.99', $a['terms']['price']['credit'] );

		$this->service->onPaid( $a, $this->now + 60 );

		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );
		$this->assertSame( 0, (int) $this->fresh( (int) $a['id'] )['credit_conflict'] );
	}

	public function test_second_booking_cannot_reserve_used_credit(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$this->service->onPaid( $a, $this->now + 60 );

		$b = $this->hold( array( 'session_id' => 11, 'start_utc' => $this->start( 12 ), 'credit' => self::CREDIT ) );

		$this->assertFalse( $b['ok'] );
		$this->assertSame( 'credit_busy', $b['error'] );
		$this->assertSame( (int) $a['id'], (int) $this->creditRow( 5 )['booking_id'], 'зачёт остался у первой записи' );

		// Время неудачной записи не удерживается.
		$this->assertTrue( $this->hold( array( 'session_id' => 12, 'start_utc' => $this->start( 12 ) ) )['ok'] );
	}

	public function test_second_booking_cannot_reserve_while_first_is_reserved(): void {
		$this->holdOk( array( 'credit' => self::CREDIT ) );

		$b = $this->hold( array( 'session_id' => 11, 'start_utc' => $this->start( 12 ), 'credit' => self::CREDIT ) );

		$this->assertFalse( $b['ok'] );
		$this->assertSame( 'credit_busy', $b['error'] );
	}

	public function test_released_credit_late_payment_of_first_after_second_used_is_conflict(): void {
		$a     = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$later = $this->now + 1000;

		$this->service->expireIfStale( $a, false, $later );
		$this->assertSame( 'released', $this->creditRow( 5 )['state'] );

		$b = $this->holdOk( array( 'session_id' => 11, 'start_utc' => $this->start( 11 ), 'credit' => self::CREDIT ), $later );
		$this->service->onPaid( $b, $later + 10 );
		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );
		$this->assertSame( (int) $b['id'], (int) $this->creditRow( 5 )['booking_id'] );

		// Первая запись оплачена с зачётом позже: деньги и время сохраняются, конфликт фиксируется.
		$status = $this->service->onPaid( $this->fresh( (int) $a['id'] ), $later + 20 );

		$this->assertSame( 'confirmed', $status );
		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( 1, (int) $row['credit_conflict'] );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_credit_conflict' ) );
		$this->assertSame( (int) $b['id'], (int) $this->creditRow( 5 )['booking_id'], 'зачёт не «перетянут» у второй записи' );
	}

	public function test_late_payment_uses_released_credit_when_nobody_took_it(): void {
		$a     = $this->holdOk( array( 'credit' => self::CREDIT ) );
		$later = $this->now + 1000;

		$this->service->expireIfStale( $a, false, $later );
		$this->assertSame( 'confirmed', $this->service->onPaid( $this->fresh( (int) $a['id'] ), $later + 1 ) );

		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );
		$this->assertSame( 0, (int) $this->fresh( (int) $a['id'] )['credit_conflict'] );
	}

	public function test_release_credit_if_stale(): void {
		$a = $this->holdOk( array( 'credit' => self::CREDIT ) );

		$this->service->releaseCreditIfStale( $a, false, $this->now + 100 );
		$this->assertSame( 'reserved', $this->creditRow( 5 )['state'], 'срок не вышел' );

		$this->service->releaseCreditIfStale( $a, true, $this->now + 1000 );
		$this->assertSame( 'reserved', $this->creditRow( 5 )['state'], 'заказ жив' );

		$this->service->releaseCreditIfStale( $a, false, $this->now + 1000 );
		$this->assertSame( 'released', $this->creditRow( 5 )['state'] );
	}

	public function test_dispute_locks_credit_and_resolution_unlocks(): void {
		$a = $this->paid( array( 'credit' => self::CREDIT ) );

		$this->service->onDispute( $a, $this->now + 100 );
		$this->assertSame( 'disputed', $this->fresh( (int) $a['id'] )['status'] );
		$this->assertSame( 'locked', $this->creditRow( 5 )['state'] );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_disputed' ) );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'], true ), 'место при споре держим' );

		// Повтор события спора ничего не дублирует.
		$this->service->onDispute( $this->fresh( (int) $a['id'] ), $this->now + 101 );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_disputed' ) );

		// Спор решён в пользу продавца: снова оплачено.
		$this->assertSame( 'confirmed', $this->service->onPaid( $this->fresh( (int) $a['id'] ), $this->now + 200 ) );
		$this->assertSame( 'used', $this->creditRow( 5 )['state'] );
	}

	public function test_full_refund_releases_credit_only_if_instruction_valid(): void {
		$a = $this->paid( array( 'credit' => self::CREDIT ) );
		$this->service->onFullRefund( $a, true, $this->now + 100 );

		$this->assertSame( 'refunded', $this->fresh( (int) $a['id'] )['status'] );
		$this->assertSame( 'released', $this->creditRow( 5 )['state'] );
		$this->assertSame( 0, $this->unitCount( (int) $a['id'] ), 'время освобождено' );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_refunded' ) );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'customer_cancelled' ) );

		// Повтор безопасен.
		$this->service->onFullRefund( $a, true, $this->now + 101 );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_refunded' ) );

		$b = $this->paid( array( 'session_id' => 11, 'start_utc' => $this->start( 12 ), 'credit' => array( 'instruction_purchase_id' => 6, 'amount' => '9.99' ) ) );
		$this->service->onFullRefund( $b, false, $this->now + 100 );

		$this->assertSame( 'void', $this->creditRow( 6 )['state'], 'инструкция возвращена — зачёт недоступен' );
	}

	public function test_refund_of_unpaid_written_booking_sends_no_customer_mail(): void {
		$a = $this->holdOk( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );
		$this->service->onFullRefund( $a, true, $this->now + 5 );

		$this->assertSame( 'refunded', $this->fresh( (int) $a['id'] )['status'] );
		$this->assertSame( 0, $this->slots->seatsUsed( '2026-06-11', $this->now + 5 ) );
		$this->assertSame( 0, $this->notes( (int) $a['id'], 'customer_cancelled' ) );
	}

	public function test_cancel_keeps_credit_used_and_frees_time(): void {
		$a = $this->paid( array( 'credit' => self::CREDIT ) );

		$this->assertTrue( $this->service->cancel( $a ) );

		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( 'cancelled', $row['status'] );
		$this->assertSame( 1, (int) $row['ics_sequence'] );
		$this->assertSame( 'used', $this->creditRow( 5 )['state'], 'отмена не возвращает зачёт' );
		$this->assertSame( 0, $this->unitCount( (int) $a['id'] ) );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'customer_cancelled' ) );
		$this->assertFalse( $this->service->cancel( $a ), 'повторная отмена — ничего' );
	}

	public function test_complete_only_from_confirmed(): void {
		$held = $this->holdOk();
		$this->assertFalse( $this->service->complete( $held ) );

		$paid = $this->paid( array( 'session_id' => 11, 'start_utc' => $this->start( 12 ) ) );
		$this->assertTrue( $this->service->complete( $paid ) );
		$this->assertSame( 'completed', $this->fresh( (int) $paid['id'] )['status'] );
	}

	// -----------------------------------------------------------------
	// Перенос.

	public function test_reschedule_is_atomic_when_new_time_is_taken(): void {
		$a = $this->paid();
		$b = $this->paid( array( 'session_id' => 11, 'start_utc' => $this->start( 11 ) ) );

		$r = $this->service->reschedule( $a, array( 'start_utc' => $this->start( 11 ) ), 'guest', $this->now + 100 );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'time_taken', $r['error'] );

		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( $this->start( 10 ), $row['start_utc'], 'старое время сохранено' );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'], true ) );
		$this->assertSame( 0, (int) $row['reschedule_count'] );
		$this->assertSame( 12, $this->unitCount( (int) $b['id'], true ) );
		$this->assertSame( 0, $this->notes( (int) $a['id'], 'customer_updated' ) );
	}

	public function test_reschedule_moves_time_and_counts(): void {
		$a = $this->paid();
		$r = $this->service->reschedule( $a, array( 'start_utc' => $this->start( 12 ) ), 'guest', $this->now + 100 );

		$this->assertTrue( $r['ok'] );

		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( $this->start( 12 ), $row['start_utc'] );
		$this->assertSame( 1, (int) $row['ics_sequence'] );
		$this->assertSame( 1, (int) $row['reschedule_count'] );
		$this->assertSame( $this->start( 12 ), $row['terms']['start_utc'] );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'], true ) );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'customer_updated' ) );
		$this->assertSame( 1, $this->notes( (int) $a['id'], 'admin_rescheduled' ) );

		// Старое время свободно: на него можно записаться.
		$this->assertTrue( $this->hold( array( 'session_id' => 11 ) )['ok'] );

		// Лимит переносов для посетителя — 1; администратор не ограничен.
		$again = $this->service->reschedule( $row, array( 'start_utc' => $this->start( 11 ) ), 'guest', $this->now + 200 );
		$this->assertSame( 'reschedule_limit', $again['error'] );
		$this->assertTrue( $this->service->reschedule( $row, array( 'start_utc' => $this->start( 12, 15 ) ), 'admin', $this->now + 200 )['ok'] );
		$this->assertSame( 1, (int) $this->fresh( (int) $a['id'] )['reschedule_count'], 'перенос администратором счётчик не увеличивает' );
	}

	public function test_reschedule_overlapping_own_old_block_is_allowed(): void {
		$a = $this->paid();
		$r = $this->service->reschedule( $a, array( 'start_utc' => $this->start( 10, 30 ) ), 'guest', $this->now + 100 );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'], true ) );
	}

	public function test_guest_cannot_reschedule_within_notice_window(): void {
		$a = $this->paid();
		// За 23 часа до встречи.
		$now = $this->start( 10 ) - 23 * 3600;
		$r   = $this->service->reschedule( $a, array( 'start_utc' => $this->start( 12 ) ), 'guest', $now );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'reschedule_too_late', $r['error'] );
		$this->assertSame( $this->start( 10 ), $this->fresh( (int) $a['id'] )['start_utc'] );
	}

	public function test_admin_can_reschedule_within_notice_window(): void {
		$a   = $this->paid();
		$now = $this->start( 10 ) - 23 * 3600;
		// Для администратора условия из terms не проверяются; слот должен быть допустим по расписанию.
		$r = $this->service->reschedule( $a, array( 'start_utc' => $this->start( 12 ) ), 'admin', $now );

		$this->assertTrue( $r['ok'] );
	}

	public function test_needs_reschedule_booking_can_be_moved_by_guest(): void {
		$a = $this->holdOk();
		$this->holdOk( array( 'session_id' => 11 ), $this->now + 1000 );
		$this->assertSame( 'needs_reschedule', $this->service->onPaid( $a, $this->now + 1010 ) );

		$r = $this->service->reschedule( $this->fresh( (int) $a['id'] ), array( 'start_utc' => $this->start( 12 ) ), 'guest', $this->now + 1020 );

		$this->assertTrue( $r['ok'] );
		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( 12, $this->unitCount( (int) $a['id'], true ) );
	}

	public function test_reschedule_rejected_for_wrong_status(): void {
		$a = $this->holdOk();

		$this->assertSame( 'not_reschedulable', $this->service->reschedule( $a, array( 'start_utc' => $this->start( 12 ) ), 'guest', $this->now )['error'] );
	}

	public function test_written_reschedule_moves_seat(): void {
		$a = $this->paid( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );

		$r = $this->service->reschedule( $a, array( 'date' => '2026-06-12' ), 'guest', $this->now + 100 );

		$this->assertTrue( $r['ok'] );
		$row = $this->fresh( (int) $a['id'] );
		$this->assertSame( '2026-06-12', $row['written_date'] );
		$this->assertSame( 0, $this->slots->seatsUsed( '2026-06-11', $this->now + 100 ) );
		$this->assertSame( 1, $this->slots->seatsUsed( '2026-06-12', $this->now + 100 ) );
		$this->assertSame( '2026-06-12', $row['terms']['written']['date'] );
	}

	public function test_written_reschedule_to_full_date_keeps_old_seat(): void {
		$a = $this->paid( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );
		$this->paid( array( 'session_id' => 11, 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-12', 'contact' => 'guest@example.com' ) );
		$this->paid( array( 'session_id' => 12, 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-12', 'contact' => 'guest@example.com' ) );

		$r = $this->service->reschedule( $a, array( 'date' => '2026-06-12' ), 'guest', $this->now + 100 );

		$this->assertSame( 'date_full', $r['error'] );
		$this->assertSame( '2026-06-11', $this->fresh( (int) $a['id'] )['written_date'] );
		$this->assertSame( 1, $this->slots->seatsUsed( '2026-06-11', $this->now + 100 ) );
	}
}
