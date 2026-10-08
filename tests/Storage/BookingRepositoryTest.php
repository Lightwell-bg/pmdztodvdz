<?php
/**
 * Тесты BookingRepository: создание, условные переходы, белый список колонок, выборки.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Storage;

use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class BookingRepositoryTest extends BookingTestCase {

	public function test_create_generates_identifiers_and_decodes_row(): void {
		$b = $this->holdOk();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $b['public_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}@\S+$/', $b['ics_uid'] );
		$this->assertIsInt( $b['id'] );
		$this->assertIsInt( $b['start_utc'] );
		$this->assertIsArray( $b['terms'] );
		$this->assertSame( 'telegram', $b['terms']['format'] );
		$this->assertSame( 'held', $b['status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $b['created_at'] );
		$this->assertNull( $b['written_date'] );
		$this->assertNull( $b['admin_notes'] );
	}

	public function test_lock_is_exclusive_expires_and_releases_only_by_owner(): void {
		$b  = $this->holdOk();
		$id = (int) $b['id'];

		$token = $this->bookings->acquireLock( $id, $this->now, 30 );
		$this->assertNotNull( $token );
		$this->assertNull( $this->bookings->acquireLock( $id, $this->now + 10, 30 ), 'пока блокировка действует' );

		$this->bookings->releaseLock( $id, 'wrong' );
		$this->assertNull( $this->bookings->acquireLock( $id, $this->now + 10, 30 ), 'чужой токен не снимает' );

		$taken = $this->bookings->acquireLock( $id, $this->now + 31, 30 );
		$this->assertNotNull( $taken, 'просроченную блокировку можно перехватить' );

		$this->bookings->releaseLock( $id, $token );
		$this->assertNull( $this->bookings->acquireLock( $id, $this->now + 32, 30 ), 'старый токен не снимает чужую блокировку' );

		$this->bookings->releaseLock( $id, $taken );
		$this->assertNotNull( $this->bookings->acquireLock( $id, $this->now + 32, 30 ) );
	}

	public function test_service_returns_busy_result_when_booking_is_locked(): void {
		$b = $this->holdOk();
		$this->bookings->acquireLock( (int) $b['id'], $this->now, 3600 );

		$this->assertSame( 'held', $this->service->onPaid( $b, $this->now + 1 ) );
		$this->assertSame( 'held', $this->fresh( (int) $b['id'] )['status'] );
	}

	public function test_get_and_by_public_id(): void {
		$b = $this->holdOk();

		$this->assertSame( $b['id'], $this->bookings->byPublicId( $b['public_id'] )['id'] );
		$this->assertNull( $this->bookings->byPublicId( str_repeat( 'f', 32 ) ) );
		$this->assertNull( $this->bookings->byPublicId( "x' OR '1'='1" ) );
		$this->assertNull( $this->bookings->get( 9999 ) );
	}

	public function test_transition_is_conditional_on_current_status(): void {
		$b  = $this->holdOk();
		$id = (int) $b['id'];

		$this->assertFalse( $this->bookings->transition( $id, array( 'confirmed' ), 'completed' ), 'не из того статуса' );
		$this->assertSame( 'held', $this->fresh( $id )['status'] );

		$this->assertTrue( $this->bookings->transition( $id, array( 'held', 'expired' ), 'confirmed', array( 'paid_at' => '2026-06-10 06:01:00', 'credit_conflict' => 1 ) ) );
		$row = $this->fresh( $id );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( '2026-06-10 06:01:00', $row['paid_at'] );
		$this->assertSame( 1, $row['credit_conflict'] );

		// Тот же переход второй раз ничего не делает: именно этот вызов должен был сменить статус.
		$this->assertFalse( $this->bookings->transition( $id, array( 'held', 'expired' ), 'confirmed' ) );
		$this->assertFalse( $this->bookings->transition( $id, array(), 'confirmed' ) );
	}

	public function test_transition_updates_terms_as_json(): void {
		$b  = $this->holdOk();
		$id = (int) $b['id'];
		$t  = $b['terms'];
		$t['start_utc'] = 123;

		$this->assertTrue( $this->bookings->transition( $id, array( 'held' ), 'held', array( 'terms' => $t, 'reminder_sent_at' => null ) ) );
		$this->assertSame( 123, $this->fresh( $id )['terms']['start_utc'] );
	}

	public function test_update_uses_whitelist_and_ignores_immutable_columns(): void {
		$b  = $this->holdOk();
		$id = (int) $b['id'];

		$this->bookings->update( $id, array( 'meet_url' => 'https://meet.google.com/abc-defg-hij', 'snapshot_id' => 999, 'kind' => 'written', 'format' => 'email', 'id' => 55, 'evil = 1; --' => 'x' ) );

		$row = $this->fresh( $id );
		$this->assertSame( 'https://meet.google.com/abc-defg-hij', $row['meet_url'] );
		$this->assertSame( 1, $row['snapshot_id'] );
		$this->assertSame( 'sync', $row['kind'] );
		$this->assertSame( 'telegram', $row['format'] );
		$this->assertSame( $id, $row['id'] );
	}

	public function test_text_values_are_escaped(): void {
		$b = $this->holdOk( array( 'question' => "О'Брайен \"; DROP TABLE x; --" ) );

		$this->assertSame( "О'Брайен \"; DROP TABLE x; --", $this->fresh( (int) $b['id'] )['question'] );
	}

	public function test_active_hold(): void {
		$b = $this->holdOk();

		$this->assertSame( $b['id'], $this->bookings->activeHold( 10, 1, $this->now )['id'] );
		$this->assertNull( $this->bookings->activeHold( 10, 1, $this->now + 901 ), 'резерв истёк' );
		$this->assertNull( $this->bookings->activeHold( 11, 1, $this->now ), 'другая сессия' );
		$this->assertNull( $this->bookings->activeHold( 10, 2, $this->now ), 'другая анкета' );
	}

	public function test_stale_and_awaiting_lists(): void {
		$a = $this->holdOk();
		$b = $this->holdOk( array( 'session_id' => 11, 'start_utc' => $this->start( 12 ) ) );
		$this->bookings->update( (int) $b['id'], array( 'order_id' => 77 ) );

		$stale = $this->bookings->staleHeld( $this->now + 1000 );
		$this->assertCount( 2, $stale );
		$this->assertCount( 0, $this->bookings->staleHeld( $this->now + 100 ) );

		$await = $this->bookings->awaitingPayment();
		$this->assertCount( 1, $await );
		$this->assertSame( $b['id'], $await[0]['id'] );
		$this->assertSame( array( (int) $b['id'] ), array_map( 'intval', array_column( $this->bookings->byOrder( 77 ), 'id' ) ) );
		$this->assertSame( array( (int) $a['id'], (int) $b['id'] ), array_map( 'intval', array_column( array_reverse( $this->bookings->bySnapshot( 1 ) ), 'id' ) ) );
	}

	public function test_due_reminders_window(): void {
		$a = $this->paid( array( 'start_utc' => $this->start( 10 ) ) );

		$from = $this->start( 10 ) - 24 * 3600;
		$to   = $this->start( 10 );

		$this->assertCount( 1, $this->bookings->dueReminders( $from, $to ) );
		$this->assertCount( 0, $this->bookings->dueReminders( $this->start( 10 ), $this->start( 10 ) + 100 ), 'встреча уже началась' );

		$this->bookings->update( (int) $a['id'], array( 'reminder_sent_at' => '2026-06-10 07:00:00' ) );
		$this->assertCount( 0, $this->bookings->dueReminders( $from, $to ), 'напоминание уже отправлено' );
	}

	public function test_written_bookings_get_no_reminders(): void {
		$this->paid( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );

		$this->assertCount( 0, $this->bookings->dueReminders( 0, PHP_INT_MAX ) );
	}

	public function test_list_for_admin_filters(): void {
		$a = $this->paid();
		$this->paid( array( 'session_id' => 11, 'format' => 'phone', 'contact' => '+359881234567', 'start_utc' => $this->start( 12 ) ) );
		$this->bookings->update( (int) $a['id'], array( 'credit_conflict' => 1 ) );

		$all = $this->bookings->listForAdmin( array(), 1 );
		$this->assertSame( 2, $all['total'] );

		$this->assertSame( 1, $this->bookings->listForAdmin( array( 'format' => 'phone' ), 1 )['total'] );
		$this->assertSame( 2, $this->bookings->listForAdmin( array( 'status' => array( 'confirmed', 'held' ) ), 1 )['total'] );
		$this->assertSame( 0, $this->bookings->listForAdmin( array( 'status' => 'cancelled' ), 1 )['total'] );
		$this->assertSame( 1, $this->bookings->listForAdmin( array( 'conflicts' => true ), 1 )['total'] );
		$this->assertSame( 1, $this->bookings->listForAdmin( array( 'from_utc' => $this->start( 11 ) ), 1 )['total'] );
		$this->assertCount( 1, $this->bookings->listForAdmin( array(), 2, 1 )['items'] );
	}

	public function test_for_month_calendar(): void {
		$this->paid();
		$this->paid( array( 'session_id' => 11, 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-12', 'contact' => 'guest@example.com' ) );
		$this->holdOk( array( 'session_id' => 12, 'start_utc' => $this->start( 12 ) ) );
		$expired = $this->holdOk( array( 'session_id' => 13, 'start_utc' => $this->start( 11 ) ) );
		$this->bookings->transition( (int) $expired['id'], array( 'held' ), 'expired' );

		$june = $this->bookings->forMonth( 2026, 6, 'Europe/Sofia' );
		$this->assertCount( 3, $june, 'истёкшие резервы в календаре не показываются' );
		$this->assertCount( 0, $this->bookings->forMonth( 2026, 7, 'Europe/Sofia' ) );
	}
}
