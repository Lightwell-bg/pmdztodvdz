<?php
/**
 * Тесты писем: содержимое только из записи и её условий, защита заголовков,
 * вложение .ics, ошибки отправки и отсутствие цикла admin_mail_failed.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Booking\Mailer;
use Bginfo\PmzhDvzh\Booking\Notifier;
use Bginfo\PmzhDvzh\Storage\MessageRepository;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class MailerTest extends BookingTestCase {

	/** @var list<array<string,mixed>> */
	private array $sent = array();

	/** @var true|string */
	private $transportResult = true;

	protected function tearDown(): void {
		Clock::freeze( null );
		parent::tearDown();
	}

	private function mailer(): Mailer {
		$this->sent = array();

		return new Mailer(
			static fn( array $b ): array => array(
				'instruction' => 'https://example.com/anketa/#dvzh-k=INSTRTOKEN',
				'manage'      => 'https://example.com/anketa/#dvzh-b=MANAGETOKEN',
			),
			static fn( int $id ): string => 'https://example.com/wp-admin/admin.php?page=bgdvzh-consultations&action=view&id=' . $id,
			new MessageRepository( $this->db ),
			function ( string $to, string $subject, string $body, array $headers, array $files ): bool|string {
				$entry = compact( 'to', 'subject', 'body', 'headers', 'files' );

				foreach ( $files as $f ) {
					$entry['file_exists']   = is_file( $f );
					$entry['file_name']     = basename( $f );
					$entry['file_contents'] = (string) file_get_contents( $f );
				}

				$this->sent[] = $entry;

				return $this->transportResult;
			}
		);
	}

	/** @return array<string,mixed> */
	private function notification( string $type, int $bookingId, int $seq = 0, string $recipient = 'guest@example.com' ): array {
		return array( 'id' => 1, 'booking_id' => $bookingId, 'type' => $type, 'seq' => $seq, 'recipient' => $recipient, 'status' => 'queued', 'attempts' => 0 );
	}

	public function test_confirmation_shows_both_time_zones_with_abbreviations_and_links(): void {
		$b    = $this->paid( array( 'visitor_tz' => 'America/New_York' ) );
		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $b );

		$this->assertIsArray( $mail );
		$this->assertSame( 'guest@example.com', $mail['to'] );
		$this->assertStringContainsString( '11.06.2026 03:00 EDT', $mail['body'] );
		$this->assertStringContainsString( '11.06.2026 10:00 EEST', $mail['body'] );
		$this->assertStringContainsString( 'Europe/Sofia', $mail['body'] );
		$this->assertStringContainsString( 'Длительность: 45 мин.', $mail['body'] );
		$this->assertStringContainsString( 'Ваш контакт: @tester', $mail['body'] );
		$this->assertStringContainsString( 'Ваш вопрос: Вопрос', $mail['body'] );
		$this->assertStringContainsString( 'https://example.com/anketa/#dvzh-k=INSTRTOKEN', $mail['body'] );
		$this->assertStringContainsString( 'https://example.com/anketa/#dvzh-b=MANAGETOKEN', $mail['body'] );
		$this->assertStringContainsString( 'Чтобы перенести или отменить запись, ответьте на это письмо', $mail['body'] );
		$this->assertStringNotContainsString( 'не позднее чем за', $mail['body'], 'условий самостоятельного переноса больше нет' );
		$this->assertStringContainsString( 'Консультант напишет вам в Telegram', $mail['body'] );
		$this->assertStringNotContainsString( '<', $mail['body'], 'письмо plain text' );
	}

	public function test_winter_time_uses_eet_abbreviation(): void {
		$b = $this->paid();
		$b['start_utc'] = gmmktime( 8, 0, 0, 1, 15, 2027 );
		$b['end_utc']   = gmmktime( 8, 45, 0, 1, 15, 2027 );

		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $b );

		$this->assertStringContainsString( '15.01.2027 10:00 EET', $mail['body'] );
	}

	public function test_content_comes_from_frozen_terms_not_from_current_settings(): void {
		$b = $this->paid();
		$t = $b['terms'];
		$t['initiator']                = 'Текст, зафиксированный при записи';
		$this->bookings->update( (int) $b['id'], array( 'terms' => $t ) );

		// Настройки после записи меняются, письмо их не видит.
		$this->cfg['formats']['telegram']['initiator_text'] = 'НОВЫЙ ТЕКСТ ИЗ НАСТРОЕК';
		$this->cfg['reschedule_hours']                      = 1;

		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $this->fresh( (int) $b['id'] ) );

		$this->assertStringContainsString( 'Текст, зафиксированный при записи', $mail['body'] );
		$this->assertStringNotContainsString( 'НОВЫЙ ТЕКСТ', $mail['body'] );
	}

	public function test_admin_notes_never_reach_any_mail(): void {
		$b = $this->paid();
		$this->bookings->update( (int) $b['id'], array( 'admin_notes' => 'СЕКРЕТНАЯ ЗАМЕТКА АДМИНА' ) );
		$b = $this->fresh( (int) $b['id'] );

		$mailer = $this->mailer();

		foreach ( array_merge( Notifier::CUSTOMER_TYPES, Notifier::ADMIN_TYPES ) as $type ) {
			$n    = $this->notification( $type, (int) $b['id'], 1, 'admin@example.com' );
			$mail = $mailer->compose( $n, $b );

			if ( is_array( $mail ) ) {
				$this->assertStringNotContainsString( 'СЕКРЕТНАЯ ЗАМЕТКА', $mail['body'] . $mail['subject'], $type );
			}
		}
	}

	public function test_meet_without_url_says_link_will_be_sent_separately(): void {
		$b    = $this->paid( array( 'format' => 'meet', 'mode' => 'call' ) );
		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $b );

		$this->assertStringContainsString( 'Ссылка на Google Meet будет отправлена отдельно', $mail['body'] );
		$this->assertStringNotContainsString( 'meet.google.com', $mail['body'] );
	}

	public function test_meet_link_mail_contains_url(): void {
		$b = $this->paid( array( 'format' => 'meet', 'mode' => 'call' ) );
		$this->bookings->update( (int) $b['id'], array( 'meet_url' => 'https://meet.google.com/abc-defg-hij' ) );

		$mail = $this->mailer()->compose( $this->notification( 'customer_meet_link', (int) $b['id'], 1 ), $this->fresh( (int) $b['id'] ) );

		$this->assertStringContainsString( 'https://meet.google.com/abc-defg-hij', $mail['body'] );
		$this->assertNull( $mail['ics'], 'к письму со ссылкой календарь не прикладывается' );
	}

	public function test_meet_link_mail_without_url_is_skipped(): void {
		$b = $this->paid( array( 'format' => 'meet', 'mode' => 'call' ) );

		$this->assertStringStartsWith( 'skip:', $this->mailer()->compose( $this->notification( 'customer_meet_link', (int) $b['id'], 1 ), $b ) );
	}

	public function test_written_booking_shows_deadline_and_followup_wording(): void {
		$b    = $this->paid( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com', 'visitor_tz' => 'America/New_York' ) );
		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $b );

		$this->assertStringContainsString( 'Выбранная дата: 2026-06-11', $mail['body'] );
		$this->assertStringContainsString( 'Ответ придёт не позднее: 13.06.2026 23:59 EEST (Europe/Sofia)', $mail['body'] );
		$this->assertStringContainsString( 'по вашему времени (America/New_York)', $mail['body'] );
		$this->assertStringContainsString( 'одно уточнение в течение 7 дней', $mail['body'] );
		$this->assertStringContainsString( 'Письменный ответ по анкете', $mail['body'] );
		$this->assertNull( $mail['ics'], 'у письменного формата нет файла календаря' );
	}

	public function test_confirmation_attaches_ics_and_removes_temp_file(): void {
		$b      = $this->paid();
		$mailer = $this->mailer();

		$this->assertTrue( $mailer( $this->notification( 'customer_confirmation', (int) $b['id'] ), $b ) );
		$this->assertCount( 1, $this->sent );

		$sent = $this->sent[0];
		$this->assertCount( 1, $sent['files'] );
		$this->assertTrue( $sent['file_exists'] );
		$this->assertStringEndsWith( '.ics', $sent['file_name'] );
		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $sent['file_contents'] );
		$this->assertFileDoesNotExist( $sent['files'][0], 'временный файл удалён после отправки' );
	}

	public function test_reminder_has_no_ics_attachment(): void {
		Clock::freeze( $this->now );
		$b = $this->paid();

		$this->assertTrue( ( $this->mailer() )( $this->notification( 'customer_reminder', (int) $b['id'], 0 ), $b ) );
		$this->assertSame( array(), $this->sent[0]['files'] );
	}

	public function test_cancellation_ics_has_cancel_method_and_bumped_sequence(): void {
		$b = $this->paid();
		$this->assertTrue( $this->service->cancel( $b ) );
		$cancelled = $this->fresh( (int) $b['id'] );

		$mail = $this->mailer()->compose( $this->notification( 'customer_cancelled', (int) $b['id'], 1 ), $cancelled );

		$this->assertStringContainsString( 'METHOD:CANCEL', $mail['ics'] );
		$this->assertStringContainsString( 'SEQUENCE:1', $mail['ics'] );
		$this->assertStringNotContainsString( 'dvzh-k=', $mail['body'], 'в письме об отмене нет ссылок доступа' );
	}

	public function test_needs_reschedule_confirmation_asks_for_new_time_without_ics(): void {
		$b = $this->holdOk();
		$this->service->onPaid( $b, $this->now + 10 );
		$this->bookings->transition( (int) $b['id'], array( 'confirmed' ), 'needs_reschedule' );
		$row = $this->fresh( (int) $b['id'] );

		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $row );

		$this->assertStringContainsString( 'выбранное время закрепить не удалось', $mail['body'] );
		$this->assertStringNotContainsString( 'Ваше время:', $mail['body'] );
		$this->assertNull( $mail['ics'] );
		$this->assertStringNotContainsString( 'подтверждена', $mail['subject'], 'тема не обещает подтверждённую запись' );
		$this->assertStringContainsString( 'новое время', $mail['subject'] );
	}

	public function test_manage_link_label_has_space_and_no_calendar_for_written(): void {
		$sync = $this->paid();
		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $sync['id'] ), $sync );
		$this->assertMatchesRegularExpression( '/файл календаря\): \S/u', $mail['body'] );

		$w    = $this->paid( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );
		$mail = $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $w['id'] ), $w );
		$this->assertStringNotContainsString( 'файл календаря', $mail['body'] );
	}

	public function test_reminder_after_start_is_skipped(): void {
		$b = $this->paid();
		Clock::freeze( (int) $b['start_utc'] + 60 );

		$this->assertStringStartsWith( 'skip:', $this->mailer()->compose( $this->notification( 'customer_reminder', (int) $b['id'], 0 ), $b ) );

		Clock::freeze( (int) $b['start_utc'] - 3600 );
		$this->assertIsArray( $this->mailer()->compose( $this->notification( 'customer_reminder', (int) $b['id'], 0 ), $b ) );
	}

	public function test_skipped_reminder_is_marked_skipped_and_not_retried(): void {
		$b = $this->paid();
		Clock::freeze( (int) $b['start_utc'] + 60 );
		$this->notifier->setSender( $this->mailer() );

		$this->notifier->queue( (int) $b['id'], 'customer_reminder', 0 );
		$row = $this->notifier->forBooking( (int) $b['id'] );
		$row = array_values( array_filter( $row, static fn( array $r ): bool => 'customer_reminder' === $r['type'] ) )[0];

		$this->assertSame( 'skipped', $row['status'] );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( 0, $this->notes( (int) $b['id'], 'admin_mail_failed' ) );
	}

	public function test_header_injection_in_recipient_is_rejected(): void {
		$b = $this->paid();
		$this->bookings->update( (int) $b['id'], array( 'email' => "victim@example.com\r\nBcc: spy@example.com" ) );
		$row = $this->fresh( (int) $b['id'] );

		$result = ( $this->mailer() )( $this->notification( 'customer_confirmation', (int) $b['id'] ), $row );

		$this->assertSame( 'invalid recipient', $result );
		$this->assertSame( array(), $this->sent, 'wp_mail не вызывался' );
		$this->assertFalse( Mailer::validRecipient( "a@example.com\nBcc: b@example.com" ) );
		$this->assertFalse( Mailer::validRecipient( 'a@example.com, b@example.com' ) );
		$this->assertFalse( Mailer::validRecipient( 'Имя <a@example.com>' ) );
		$this->assertFalse( Mailer::validRecipient( '' ) );
		$this->assertTrue( Mailer::validRecipient( 'guest@example.com' ) );
	}

	public function test_header_injection_in_subject_is_neutralised(): void {
		$b = $this->paid( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );
		$messages = new MessageRepository( $this->db );
		$messages->addOut( (int) $b['id'], "Тема\r\nBcc: spy@example.com", "Текст ответа\nвторая строка", 1 );

		$mail = $this->mailer()->compose( $this->notification( 'customer_written_answer', (int) $b['id'], 1 ), $this->fresh( (int) $b['id'] ) );

		$this->assertIsArray( $mail );
		$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $mail['subject'] );
		$this->assertStringStartsWith( 'Тема', $mail['subject'] );
		$this->assertStringContainsString( "Текст ответа\nвторая строка", $mail['body'] );
		$this->assertSame( "a b", Mailer::headerValue( "a\r\nb" ) );
	}

	public function test_written_answer_without_message_is_skipped_and_status_is_recorded(): void {
		$b = $this->paid( array( 'format' => 'email', 'start_utc' => 0, 'date' => '2026-06-11', 'contact' => 'guest@example.com' ) );

		$this->assertStringStartsWith( 'skip:', $this->mailer()->compose( $this->notification( 'customer_written_answer', (int) $b['id'], 1 ), $b ) );

		$messages = new MessageRepository( $this->db );
		$mid      = $messages->addOut( (int) $b['id'], 'Тема', 'Текст', 1 );

		$this->assertTrue( ( $this->mailer() )( $this->notification( 'customer_written_answer', (int) $b['id'], 1 ), $b ) );
		$this->assertSame( 'sent', $messages->forBooking( (int) $b['id'] )[0]['mail_status'] );
		$this->assertSame( $mid, (int) $messages->forBooking( (int) $b['id'] )[0]['id'] );
	}

	public function test_admin_mail_uses_notification_recipient_and_admin_link(): void {
		$b    = $this->paid();
		$mail = $this->mailer()->compose( $this->notification( 'admin_new_paid', (int) $b['id'], 0, 'admin@example.com' ), $b );

		$this->assertSame( 'admin@example.com', $mail['to'] );
		$this->assertStringContainsString( 'action=view&id=' . $b['id'], $mail['body'] );
		$this->assertStringNotContainsString( '@tester', $mail['body'], 'контакт посетителя админу в письмо не копируется' );
		$this->assertNull( $mail['ics'] );
	}

	public function test_send_failure_marks_failed_and_admin_is_notified_once_without_loop(): void {
		$this->transportResult = 'wp_mail returned false';
		$this->notifier->setSender( $this->mailer() );

		$b  = $this->paid();
		$id = $this->notifier->queue( (int) $b['id'], 'customer_confirmation_resend', 1 );
		$this->assertGreaterThan( 0, $id );

		for ( $i = 0; $i < 8; $i++ ) {
			$this->notifier->dispatch( $id );
		}

		$rows = array_values( array_filter( $this->notifier->forBooking( (int) $b['id'] ), static fn( array $r ): bool => 'customer_confirmation_resend' === $r['type'] ) );
		$this->assertSame( 'failed', $rows[0]['status'] );
		$this->assertSame( 'wp_mail returned false', $rows[0]['last_error'] );
		$this->assertSame( 5, (int) $rows[0]['attempts'] );

		$this->assertSame( 1, $this->notes( (int) $b['id'], 'admin_mail_failed' ) );

		// Письмо о сбое само падает, но нового письма о сбое не порождает.
		$failed = array_values( array_filter( $this->notifier->forBooking( (int) $b['id'] ), static fn( array $r ): bool => 'admin_mail_failed' === $r['type'] ) );

		for ( $i = 0; $i < 8; $i++ ) {
			$this->notifier->dispatch( (int) $failed[0]['id'] );
		}

		$this->assertSame( 1, $this->notes( (int) $b['id'], 'admin_mail_failed' ), 'цикла нет' );
	}

	public function test_customer_mail_is_skipped_when_booking_status_no_longer_matches(): void {
		$b = $this->paid();

		$this->assertIsArray( $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $b ) );

		$cancelled = array_merge( $b, array( 'status' => 'cancelled' ) );
		$this->assertStringStartsWith( 'skip:', $this->mailer()->compose( $this->notification( 'customer_confirmation', (int) $b['id'] ), $cancelled ) );
		$this->assertStringStartsWith( 'skip:', $this->mailer()->compose( $this->notification( 'customer_updated', (int) $b['id'], 1 ), $cancelled ) );
		$this->assertIsArray( $this->mailer()->compose( $this->notification( 'customer_cancelled', (int) $b['id'], 1 ), $cancelled ) );
		$this->assertStringStartsWith( 'skip:', $this->mailer()->compose( $this->notification( 'customer_cancelled', (int) $b['id'], 1 ), $b ) );
	}

	public function test_plural_helper(): void {
		$this->assertSame( 'день', Mailer::plural( 1, 'день', 'дня', 'дней' ) );
		$this->assertSame( 'дня', Mailer::plural( 3, 'день', 'дня', 'дней' ) );
		$this->assertSame( 'дней', Mailer::plural( 7, 'день', 'дня', 'дней' ) );
		$this->assertSame( 'дней', Mailer::plural( 11, 'день', 'дня', 'дней' ) );
		$this->assertSame( 'день', Mailer::plural( 21, 'день', 'дня', 'дней' ) );
	}
}
