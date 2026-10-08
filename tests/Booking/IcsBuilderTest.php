<?php
/**
 * Тесты .ics: поля, отмена, SEQUENCE, экранирование и складывание строк.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Booking\IcsBuilder;
use PHPUnit\Framework\TestCase;

final class IcsBuilderTest extends TestCase {

	/** @return array<string,mixed> */
	private function booking( array $over = array() ): array {
		return array_merge(
			array(
				'id'           => 7,
				'format'       => 'telegram',
				'mode'         => 'text',
				'kind'         => 'sync',
				'status'       => 'confirmed',
				'start_utc'    => gmmktime( 7, 0, 0, 6, 11, 2026 ),
				'end_utc'      => gmmktime( 7, 45, 0, 6, 11, 2026 ),
				'ics_uid'      => 'abc123@example.com',
				'ics_sequence' => 2,
				'updated_at'   => '2026-06-10 06:00:00',
				'contact'      => '@secret_user',
				'email'        => 'private@example.com',
				'question'     => 'Очень личный вопрос',
				'admin_notes'  => 'внутренняя заметка',
			),
			$over
		);
	}

	public function test_publish_event_fields(): void {
		$ics = IcsBuilder::build( $this->booking() );

		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "\r\nMETHOD:PUBLISH\r\n", $ics );
		$this->assertStringContainsString( "\r\nUID:abc123@example.com\r\n", $ics );
		$this->assertStringContainsString( "\r\nSEQUENCE:2\r\n", $ics );
		$this->assertStringContainsString( "\r\nDTSTART:20260611T070000Z\r\n", $ics );
		$this->assertStringContainsString( "\r\nDTEND:20260611T074500Z\r\n", $ics );
		$this->assertStringContainsString( "\r\nDTSTAMP:20260610T060000Z\r\n", $ics );
		$this->assertStringContainsString( "\r\nSTATUS:CONFIRMED\r\n", $ics );
		$this->assertStringNotContainsString( "\n", str_replace( "\r\n", '', $ics ), 'только CRLF' );
	}

	public function test_summary_has_only_the_format_label_and_no_personal_data(): void {
		$ics = IcsBuilder::build( $this->booking() );
		$flat = str_replace( "\r\n ", '', $ics );

		$this->assertStringContainsString( 'SUMMARY:Консультация ПМЖ → ДВЖ (Telegram)', $flat );

		foreach ( array( '@secret_user', 'private@example.com', 'личный вопрос', 'внутренняя заметка' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $flat );
		}
	}

	public function test_cancelled_booking_publishes_cancel_method(): void {
		$ics = IcsBuilder::build( $this->booking( array( 'status' => 'cancelled', 'ics_sequence' => 3 ) ) );

		$this->assertStringContainsString( "\r\nMETHOD:CANCEL\r\n", $ics );
		$this->assertStringContainsString( "\r\nSTATUS:CANCELLED\r\n", $ics );
		$this->assertStringContainsString( "\r\nSEQUENCE:3\r\n", $ics );
		$this->assertStringContainsString( "\r\nUID:abc123@example.com\r\n", $ics );
	}

	public function test_refunded_booking_is_also_cancel(): void {
		$this->assertStringContainsString( 'METHOD:CANCEL', IcsBuilder::build( $this->booking( array( 'status' => 'refunded' ) ) ) );
	}

	public function test_sequence_follows_booking(): void {
		$this->assertStringContainsString( "\r\nSEQUENCE:0\r\n", IcsBuilder::build( $this->booking( array( 'ics_sequence' => 0 ) ) ) );
		$this->assertStringContainsString( "\r\nSEQUENCE:5\r\n", IcsBuilder::build( $this->booking( array( 'ics_sequence' => 5 ) ) ) );
	}

	public function test_written_booking_without_time_gives_empty_string(): void {
		$this->assertSame( '', IcsBuilder::build( $this->booking( array( 'kind' => 'written', 'start_utc' => null, 'end_utc' => null ) ) ) );
	}

	public function test_escape_special_characters(): void {
		$this->assertSame( 'a\\;b\\,c\\\\d\\nе', IcsBuilder::escape( "a;b,c\\d\nе" ) );
		$this->assertSame( 'x\\ny\\nz', IcsBuilder::escape( "x\r\ny\rz" ) );
	}

	public function test_fold_keeps_lines_within_75_octets_and_does_not_split_multibyte(): void {
		$line   = 'SUMMARY:' . str_repeat( 'Кириллица → текст, ', 12 );
		$folded = IcsBuilder::fold( $line );

		$this->assertStringEndsWith( "\r\n", $folded );

		foreach ( explode( "\r\n", rtrim( $folded, "\r\n" ) ) as $i => $physical ) {
			$this->assertLessThanOrEqual( 75, strlen( $physical ), 'строка ' . $i . ' длиннее 75 октетов' );
			$this->assertTrue( mb_check_encoding( $physical, 'UTF-8' ), 'разорван многобайтовый символ' );

			if ( $i > 0 ) {
				$this->assertStringStartsWith( ' ', $physical );
			}
		}

		$this->assertSame( $line, str_replace( "\r\n ", '', rtrim( $folded, "\r\n" ) ) );
	}

	public function test_short_line_is_not_folded(): void {
		$this->assertSame( "UID:x\r\n", IcsBuilder::fold( 'UID:x' ) );
	}

	public function test_built_calendar_lines_are_all_short(): void {
		$ics = IcsBuilder::build( $this->booking( array( 'ics_uid' => str_repeat( 'u', 120 ) . '@example.com' ) ) );

		foreach ( explode( "\r\n", rtrim( $ics, "\r\n" ) ) as $physical ) {
			$this->assertLessThanOrEqual( 75, strlen( $physical ) );
		}

		$this->assertStringContainsString( str_repeat( 'u', 120 ) . '@example.com', str_replace( "\r\n ", '', $ics ) );
	}
}
