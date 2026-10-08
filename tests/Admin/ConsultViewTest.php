<?php
/**
 * Тесты чистых помощников админки консультаций.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Admin;

use Bginfo\PmzhDvzh\Admin\ConsultView;
use PHPUnit\Framework\TestCase;

final class ConsultViewTest extends TestCase {

	/** @return array<string,mixed> */
	private function booking( array $over = array() ): array {
		return array_merge(
			array(
				'id'               => 3,
				'format'           => 'telegram',
				'mode'             => 'call',
				'kind'             => 'sync',
				'status'           => 'confirmed',
				'start_utc'        => gmmktime( 7, 0, 0, 6, 11, 2026 ),
				'deadline_utc'     => null,
				'written_date'     => null,
				'meet_url'         => '',
				'ics_sequence'     => 0,
				'reschedule_count' => 0,
				'followups_used'   => 0,
				'credit_conflict'  => 0,
				'terms'            => array( 'consultant_tz' => 'Europe/Sofia', 'written' => array( 'followups' => 1, 'followup_days' => 7 ) ),
			),
			$over
		);
	}

	public function test_meet_url_validation_is_strict(): void {
		$this->assertTrue( ConsultView::validMeetUrl( 'https://meet.google.com/abc-defg-hij' ) );

		foreach ( array(
			'http://meet.google.com/abc-defg-hij',
			'https://meet.google.com/abc-defg-hij/',
			'https://meet.google.com/abc-defg-hij?x=1',
			'https://meet.google.com/abc-defg-hij#a',
			"https://meet.google.com/abc-defg-hij\n",
			'https://meet.google.com/ABC-defg-hij',
			'https://meet.google.com/ab-defg-hij',
			'https://evil.example/https://meet.google.com/abc-defg-hij',
			'https://meet.google.com.evil.example/abc-defg-hij',
			' https://meet.google.com/abc-defg-hij',
			'',
		) as $bad ) {
			$this->assertFalse( ConsultView::validMeetUrl( $bad ), $bad );
		}
	}

	public function test_when_label_in_consultant_zone(): void {
		$this->assertSame( '11.06.2026 10:00 EEST', ConsultView::whenLabel( $this->booking() ) );
		$this->assertSame( '—', ConsultView::whenLabel( $this->booking( array( 'start_utc' => null ) ) ) );

		$written = $this->booking( array( 'kind' => 'written', 'deadline_utc' => gmmktime( 20, 59, 59, 6, 13, 2026 ) ) );
		$this->assertSame( 'до 13.06.2026 23:59 EEST', ConsultView::whenLabel( $written ) );
	}

	public function test_format_labels(): void {
		$this->assertSame( 'Telegram (звонок)', ConsultView::formatLabel( $this->booking() ) );
		$this->assertSame( 'WhatsApp (переписка)', ConsultView::formatLabel( $this->booking( array( 'format' => 'whatsapp', 'mode' => 'text' ) ) ) );
		$this->assertSame( 'Google Meet', ConsultView::formatLabel( $this->booking( array( 'format' => 'meet' ) ) ) );
	}

	public function test_allowed_actions_by_status(): void {
		$this->assertEqualsCanonicalizing( array( 'reschedule', 'cancel', 'complete' ), ConsultView::allowedActions( $this->booking() ) );
		$this->assertEqualsCanonicalizing( array( 'reschedule', 'cancel', 'complete', 'meet' ), ConsultView::allowedActions( $this->booking( array( 'format' => 'meet' ) ) ) );
		$this->assertEqualsCanonicalizing( array( 'reschedule', 'cancel' ), ConsultView::allowedActions( $this->booking( array( 'status' => 'needs_reschedule' ) ) ) );
		$this->assertSame( array( 'cancel' ), ConsultView::allowedActions( $this->booking( array( 'status' => 'held' ) ) ) );
		$this->assertSame( array(), ConsultView::allowedActions( $this->booking( array( 'status' => 'cancelled' ) ) ) );
		$this->assertSame( array(), ConsultView::allowedActions( $this->booking( array( 'status' => 'refunded' ) ) ) );
		$this->assertSame( array(), ConsultView::allowedActions( $this->booking( array( 'status' => 'disputed' ) ) ) );
		$this->assertContains( 'answer', ConsultView::allowedActions( $this->booking( array( 'kind' => 'written', 'format' => 'email' ) ) ) );
		$this->assertContains( 'answer', ConsultView::allowedActions( $this->booking( array( 'kind' => 'written', 'format' => 'email', 'status' => 'completed' ) ) ) );
		$this->assertNotContains( 'meet', ConsultView::allowedActions( $this->booking( array( 'format' => 'meet', 'status' => 'completed' ) ) ) );
		$this->assertContains( 'credit_resolved', ConsultView::allowedActions( $this->booking( array( 'status' => 'cancelled', 'credit_conflict' => 1 ) ) ) );
	}

	public function test_flags(): void {
		$this->assertSame( array(), ConsultView::flags( $this->booking() ) );
		$this->assertSame( array( 'конфликт времени' ), ConsultView::flags( $this->booking( array( 'status' => 'needs_reschedule' ) ) ) );
		$this->assertSame( array( 'конфликт зачёта', 'ошибка письма' ), ConsultView::flags( $this->booking( array( 'credit_conflict' => 1 ) ), true ) );
	}

	public function test_revision_changes_with_state(): void {
		$base = ConsultView::revision( $this->booking() );

		$this->assertSame( $base, ConsultView::revision( $this->booking() ) );
		$this->assertNotSame( $base, ConsultView::revision( $this->booking( array( 'status' => 'cancelled' ) ) ) );
		$this->assertNotSame( $base, ConsultView::revision( $this->booking( array( 'ics_sequence' => 1 ) ) ) );
		$this->assertNotSame( $base, ConsultView::revision( $this->booking( array( 'meet_url' => 'https://meet.google.com/abc-defg-hij' ) ) ) );
	}

	public function test_written_check_first_answer_then_followups_within_window(): void {
		$deadline = gmmktime( 20, 59, 59, 6, 13, 2026 );
		$b        = $this->booking( array( 'kind' => 'written', 'format' => 'email', 'deadline_utc' => $deadline ) );

		$this->assertNull( ConsultView::writtenCheck( $b, 0, $deadline + 99 * 86400 ), 'первый ответ — всегда' );
		$this->assertNull( ConsultView::writtenCheck( $b, 1, $deadline + 86400 ), 'уточнение в срок' );

		$late = ConsultView::writtenCheck( $b, 1, $deadline + 8 * 86400 );
		$this->assertStringContainsString( 'Срок уточнений истёк', (string) $late );

		$used = ConsultView::writtenCheck( $this->booking( array( 'kind' => 'written', 'deadline_utc' => $deadline, 'followups_used' => 1 ) ), 2, $deadline + 86400 );
		$this->assertStringContainsString( 'Лимит уточнений исчерпан', (string) $used );
	}

	public function test_written_check_rejects_wrong_kind_or_status(): void {
		$this->assertNotNull( ConsultView::writtenCheck( $this->booking(), 0, 1 ) );
		$this->assertNotNull( ConsultView::writtenCheck( $this->booking( array( 'kind' => 'written', 'status' => 'held' ) ), 0, 1 ) );
		$this->assertNotNull( ConsultView::writtenCheck( $this->booking( array( 'kind' => 'written', 'status' => 'cancelled' ) ), 0, 1 ) );
	}

	public function test_append_note(): void {
		$now = gmmktime( 6, 5, 0, 6, 10, 2026 );

		$this->assertSame( '2026-06-10 06:05 UTC — Отмена', ConsultView::appendNote( '', "Отмена\n", $now ) );
		$this->assertSame( "старое\n2026-06-10 06:05 UTC — Новая строка", ConsultView::appendNote( "старое\n", "Новая\n  строка", $now ) );
	}
}
