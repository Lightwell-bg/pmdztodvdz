<?php
/**
 * Тесты напоминаний: окно reminder_hours, один раз, не для прошедших встреч.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Booking\Reminders;
use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class RemindersTest extends BookingTestCase {

	private function reminders(): Reminders {
		return new Reminders( $this->bookings, $this->notifier, $this->cfg );
	}

	public function test_reminder_is_queued_only_inside_the_window(): void {
		$b     = $this->paid();
		$start = (int) $b['start_utc'];

		$this->assertSame( 0, $this->reminders()->run( $start - 25 * 3600 ), 'ещё рано: до встречи больше 24 ч' );
		$this->assertSame( 0, $this->notes( (int) $b['id'], 'customer_reminder' ) );

		$this->assertSame( 1, $this->reminders()->run( $start - 23 * 3600 ) );
		$this->assertSame( 1, $this->notes( (int) $b['id'], 'customer_reminder' ) );
		$this->assertNotNull( $this->fresh( (int) $b['id'] )['reminder_sent_at'] );
	}

	public function test_reminder_is_sent_only_once(): void {
		$b = $this->paid();

		$this->assertSame( 1, $this->reminders()->run( (int) $b['start_utc'] - 3600 ) );
		$this->assertSame( 0, $this->reminders()->run( (int) $b['start_utc'] - 1800 ) );
		$this->assertSame( 0, $this->reminders()->run( (int) $b['start_utc'] - 60 ) );
		$this->assertSame( 1, $this->notes( (int) $b['id'], 'customer_reminder' ) );
	}

	public function test_no_reminder_when_start_is_not_in_the_future(): void {
		$b = $this->paid();

		$this->assertSame( 0, $this->reminders()->run( (int) $b['start_utc'] ), 'ровно в начало — уже нельзя' );
		$this->assertSame( 0, $this->reminders()->run( (int) $b['start_utc'] + 600 ) );
		$this->assertSame( 0, $this->notes( (int) $b['id'], 'customer_reminder' ) );
		$this->assertNull( $this->fresh( (int) $b['id'] )['reminder_sent_at'] );
	}

	public function test_only_confirmed_sync_bookings_get_reminders(): void {
		$held     = $this->holdOk();
		$written  = $this->paid( array( 'session_id' => 11, 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );
		$cancelled = $this->paid( array( 'session_id' => 12, 'start_utc' => $this->start( 12 ) ) );
		$this->service->cancel( $cancelled );

		$this->assertSame( 0, $this->reminders()->run( $this->start( 10 ) - 3600 ) );
		$this->assertSame( 0, $this->notes( (int) $held['id'], 'customer_reminder' ) );
		$this->assertSame( 0, $this->notes( (int) $written['id'], 'customer_reminder' ) );
		$this->assertSame( 0, $this->notes( (int) $cancelled['id'], 'customer_reminder' ) );
	}

	public function test_reschedule_allows_a_new_reminder_with_new_sequence(): void {
		$b = $this->paid();
		$this->assertSame( 1, $this->reminders()->run( (int) $b['start_utc'] - 3600 ) );

		$this->assertTrue( $this->service->reschedule( $this->fresh( (int) $b['id'] ), array( 'start_utc' => $this->start( 12 ) ), 'admin', $this->now + 100 )['ok'] );
		$this->assertNull( $this->fresh( (int) $b['id'] )['reminder_sent_at'] );

		$this->assertSame( 1, $this->reminders()->run( $this->start( 12 ) - 3600 ) );
		$this->assertSame( 2, $this->notes( (int) $b['id'], 'customer_reminder' ), 'seq = ics_sequence: первое и второе напоминания разные' );
	}

	public function test_reminder_hours_setting_is_used(): void {
		$b   = $this->paid();
		$cfg = array_merge( $this->cfg, array( 'reminder_hours' => 2 ) );

		$this->assertSame( 0, ( new Reminders( $this->bookings, $this->notifier, $cfg ) )->run( (int) $b['start_utc'] - 3 * 3600 ) );
		$this->assertSame( 1, ( new Reminders( $this->bookings, $this->notifier, $cfg ) )->run( (int) $b['start_utc'] - 3600 ) );
	}
}
