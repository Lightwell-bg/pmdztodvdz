<?php
/**
 * Тесты журнала писем: уникальность (booking_id, type, seq), отправитель, повторы.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class NotifierTest extends BookingTestCase {

	public function test_queue_is_idempotent_per_booking_type_seq(): void {
		$b = $this->holdOk();
		$id = (int) $b['id'];

		$first = $this->notifier->queue( $id, 'customer_confirmation', 0 );
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $this->notifier->queue( $id, 'customer_confirmation', 0 ), 'дубль не создаёт строку' );
		$this->assertGreaterThan( 0, $this->notifier->queue( $id, 'customer_confirmation', 1 ), 'другой seq — другое письмо' );
		$this->assertSame( 2, $this->notes( $id, 'customer_confirmation' ) );
	}

	public function test_recipients(): void {
		$b = $this->holdOk();
		$this->notifier->queue( (int) $b['id'], 'customer_confirmation' );
		$this->notifier->queue( (int) $b['id'], 'admin_new_paid' );

		$rows = array();

		foreach ( $this->notifier->forBooking( (int) $b['id'] ) as $r ) {
			$rows[ $r['type'] ] = $r['recipient'];
		}

		$this->assertSame( 'guest@example.com', $rows['customer_confirmation'] );
		$this->assertSame( 'admin@example.com', $rows['admin_new_paid'] );
	}

	public function test_unknown_type_is_ignored(): void {
		$b = $this->holdOk();

		$this->assertSame( 0, $this->notifier->queue( (int) $b['id'], 'spam' ) );
		$this->assertSame( array(), $this->notifier->forBooking( (int) $b['id'] ) );
	}

	public function test_without_sender_letters_stay_queued_until_sender_is_set(): void {
		$b = $this->holdOk();
		$this->notifier->queue( (int) $b['id'], 'customer_confirmation' );

		$this->assertSame( 'queued', $this->notifier->forBooking( (int) $b['id'] )[0]['status'] );
		$this->assertFalse( $this->notifier->hasSender() );
		$this->assertSame( 0, $this->notifier->retryPending() );

		$sent = array();
		$this->notifier->setSender(
			static function ( array $n, array $booking ) use ( &$sent ): bool {
				$sent[] = $n['type'] . ':' . $booking['public_id'];

				return true;
			}
		);

		$this->assertSame( 1, $this->notifier->retryPending() );
		$this->assertSame( 'sent', $this->notifier->forBooking( (int) $b['id'] )[0]['status'] );
		$this->assertCount( 1, $sent );

		// Отправленное повторно не уходит.
		$this->assertSame( 0, $this->notifier->retryPending() );
	}

	public function test_sender_is_called_right_after_queue_and_only_once(): void {
		$calls = 0;
		$this->notifier->setSender(
			static function () use ( &$calls ): bool {
				++$calls;

				return true;
			}
		);

		$b = $this->holdOk();
		$this->notifier->queue( (int) $b['id'], 'customer_confirmation' );
		$this->notifier->queue( (int) $b['id'], 'customer_confirmation' );

		$this->assertSame( 1, $calls );
	}

	public function test_booking_service_events_do_not_duplicate_mail(): void {
		$calls = 0;
		$this->notifier->setSender(
			static function () use ( &$calls ): bool {
				++$calls;

				return true;
			}
		);

		$b = $this->holdOk();
		$this->service->onPaid( $b, $this->now + 10 );
		$this->service->onPaid( $b, $this->now + 11 );
		$this->service->onPaid( $this->fresh( (int) $b['id'] ), $this->now + 12 );

		$this->assertSame( 2, $calls, 'одно письмо посетителю и одно администратору' );
	}

	public function test_failures_are_counted_and_admin_is_notified_after_last_attempt(): void {
		$calls = 0;
		$this->notifier->setSender(
			static function () use ( &$calls ): string {
				++$calls;

				return 'smtp down';
			}
		);

		$b  = $this->holdOk();
		$id = $this->notifier->queue( (int) $b['id'], 'customer_confirmation' );

		$row = $this->notifier->forBooking( (int) $b['id'] )[0];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'smtp down', $row['last_error'] );
		$this->assertSame( 1, (int) $row['attempts'] );

		for ( $i = 0; $i < 6; $i++ ) {
			$this->notifier->dispatch( $id );
		}

		$row = $this->notifier->forBooking( (int) $b['id'] )[0];
		$this->assertSame( 5, (int) $row['attempts'], 'не больше пяти попыток' );
		$this->assertSame( 1, $this->notes( (int) $b['id'], 'admin_mail_failed' ), 'администратор уведомлён один раз' );
		$callsBefore = $calls;
		$this->notifier->dispatch( $id );
		$this->assertSame( $callsBefore, $calls, 'после пяти попыток отправитель больше не вызывается' );
	}

	public function test_manual_retry_resets_failed(): void {
		$ok = false;
		$this->notifier->setSender( static function () use ( &$ok ) {
			return $ok ? true : 'down';
		} );

		$b  = $this->holdOk();
		$id = $this->notifier->queue( (int) $b['id'], 'customer_confirmation' );
		$this->assertSame( 'failed', $this->notifier->forBooking( (int) $b['id'] )[0]['status'] );

		$ok = true;
		$this->notifier->retry( $id );

		$this->assertSame( 'sent', $this->notifier->forBooking( (int) $b['id'] )[0]['status'] );
	}

	public function test_sender_exception_is_a_failure_not_a_crash(): void {
		$this->notifier->setSender( static function () {
			throw new \RuntimeException( 'boom' );
		} );

		$b = $this->holdOk();
		$this->notifier->queue( (int) $b['id'], 'customer_confirmation' );

		$row = $this->notifier->forBooking( (int) $b['id'] )[0];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'RuntimeException', $row['last_error'] );
	}
}
