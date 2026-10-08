<?php
/**
 * Тесты настроек консультаций: дефолты, очистка, разбор текстовых полей, ошибки расписания.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Support;

use Bginfo\PmzhDvzh\Support\ConsultSettings;
use Bginfo\PmzhDvzh\Support\Settings;
use PHPUnit\Framework\TestCase;

final class ConsultSettingsTest extends TestCase {

	public function test_defaults_are_valid_but_disabled(): void {
		$d = ConsultSettings::defaults();

		$this->assertFalse( $d['enabled'] );
		$this->assertSame( 'Europe/Sofia', $d['tz'] );
		$this->assertSame( array( '10:00-13:00', '14:00-18:00' ), $d['weekly'][1] );
		$this->assertArrayNotHasKey( 6, $d['weekly'] );
		$this->assertSame( array(), ConsultSettings::errors( $d ), 'дефолтное расписание корректно' );
		$this->assertSame( array( 'telegram', 'whatsapp', 'phone', 'meet', 'email' ), array_keys( $d['formats'] ) );
	}

	public function test_settings_defaults_contain_consult_subtree(): void {
		$this->assertSame( ConsultSettings::defaults(), Settings::defaults()['consult'] );
	}

	public function test_merge_fills_missing_keys(): void {
		$m = ConsultSettings::merge( array( 'duration_min' => 30, 'formats' => array( 'phone' => array( 'enabled' => false ) ) ) );

		$this->assertSame( 30, $m['duration_min'] );
		$this->assertSame( 15, $m['break_min'] );
		$this->assertFalse( $m['formats']['phone']['enabled'] );
		$this->assertSame( 'Консультант позвонит вам в назначенное время', $m['formats']['phone']['initiator_text'] );
		$this->assertTrue( $m['formats']['email']['enabled'] );
	}

	public function test_sanitize_clamps_numbers_and_keeps_unspecified(): void {
		$s = ConsultSettings::sanitize( array( 'hold_minutes' => 9999, 'horizon_days' => -3, 'reminder_hours' => 'abc' ), array( 'reminder_hours' => 12 ) );

		$this->assertSame( 120, $s['hold_minutes'] );
		$this->assertSame( 1, $s['horizon_days'] );
		$this->assertSame( 12, $s['reminder_hours'], 'нечисловое значение не меняет прежнее' );
		$this->assertSame( 45, $s['duration_min'] );
	}

	public function test_sanitize_does_not_silently_round_minutes(): void {
		$s = ConsultSettings::sanitize( array( 'duration_min' => 47 ) );

		$this->assertSame( 47, $s['duration_min'] );
		$errors = ConsultSettings::errors( $s );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'кратен 5', $errors[0] );
	}

	public function test_booleans_change_only_when_key_is_present(): void {
		$base = ConsultSettings::sanitize( array( 'enabled' => '1', 'analytics_events' => 'on' ) );
		$this->assertTrue( $base['enabled'] );
		$this->assertTrue( $base['analytics_events'] );

		$kept = ConsultSettings::sanitize( array( 'duration_min' => 30 ), $base );
		$this->assertTrue( $kept['enabled'] );

		$off = ConsultSettings::sanitize( array( 'enabled' => '0' ), $base );
		$this->assertFalse( $off['enabled'] );
	}

	public function test_format_price_ids_and_download(): void {
		$s = ConsultSettings::sanitize( array( 'download_id' => '42', 'price_id' => '2', 'format_price_ids' => array( 'meet' => '3', 'phone' => '', 'bogus' => '9' ) ) );

		$this->assertSame( 42, $s['download_id'] );
		$this->assertSame( 2, $s['price_id'] );
		$this->assertSame( 3, $s['format_price_ids']['meet'] );
		$this->assertNull( $s['format_price_ids']['phone'] );
		$this->assertArrayNotHasKey( 'bogus', $s['format_price_ids'] );
	}

	public function test_timezone_must_be_valid(): void {
		$this->assertSame( 'Europe/Sofia', ConsultSettings::sanitize( array( 'tz' => 'Mars/Base' ) )['tz'] );
		$this->assertSame( 'Europe/Berlin', ConsultSettings::sanitize( array( 'tz' => 'Europe/Berlin' ) )['tz'] );
	}

	public function test_admin_email_is_validated(): void {
		$this->assertSame( 'a@example.com', ConsultSettings::sanitize( array( 'admin_email' => ' a@example.com ' ) )['admin_email'] );
		$this->assertSame( '', ConsultSettings::sanitize( array( 'admin_email' => 'nope' ) )['admin_email'] );
	}

	public function test_weekly_from_text(): void {
		$w = ConsultSettings::weeklyFromText( "1: 10:00-13:00, 14:00-18:00\nвт 09:00-12:00\n6:\n9: 10:00-11:00\nмусор" );

		$this->assertSame( array( '10:00-13:00', '14:00-18:00' ), $w[1] );
		$this->assertSame( array( '09:00-12:00' ), $w[2] );
		$this->assertSame( array(), $w[6] );
		$this->assertArrayNotHasKey( 9, $w );
	}

	public function test_weekly_round_trip_and_errors_for_bad_lines(): void {
		$w = ConsultSettings::weeklyFromText( "1: 10:00-13:00\n2: 10:02-12:00" );

		$this->assertSame( "1: 10:00-13:00\n2: 10:02-12:00", ConsultSettings::weeklyToText( $w ) );

		$errors = ConsultSettings::errors( ConsultSettings::sanitize( array( 'weekly' => "1: 10:00-13:00\n2: 10:02-12:00" ) ) );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'кратны 5', $errors[0] );
	}

	public function test_exceptions_from_text(): void {
		$e = ConsultSettings::exceptionsFromText( "2026-12-24\n2026-12-31: 10:00-12:00, 14:00-16:00\n2027-01-02 выходной\n\nкакая-то строка" );

		$this->assertSame( array( 'date' => '2026-12-24', 'intervals' => array() ), $e[0] );
		$this->assertSame( array( '10:00-12:00', '14:00-16:00' ), $e[1]['intervals'] );
		$this->assertSame( array(), $e[2]['intervals'] );
		$this->assertSame( 'какая-то строка', $e[3]['date'] );
		$this->assertNotEmpty( ConsultSettings::errors( ConsultSettings::sanitize( array( 'exceptions' => 'какая-то строка' ) ) ), 'мусорная дата — ошибка расписания' );
		$this->assertSame( "2026-12-24\n2026-12-31: 10:00-12:00, 14:00-16:00\n2027-01-02\nкакая-то строка", ConsultSettings::exceptionsToText( $e ) );
	}

	public function test_vacations_from_text(): void {
		$v = ConsultSettings::vacationsFromText( "2026-08-01 - 2026-08-15\n2026-09-01\n2026-10-01..2026-10-03\nлето" );

		$this->assertSame( array( 'from' => '2026-08-01', 'to' => '2026-08-15' ), $v[0] );
		$this->assertSame( array( 'from' => '2026-09-01', 'to' => '2026-09-01' ), $v[1] );
		$this->assertSame( array( 'from' => '2026-10-01', 'to' => '2026-10-03' ), $v[2] );
		$this->assertSame( '', $v[3]['to'] );
		$this->assertNotEmpty( ConsultSettings::errors( ConsultSettings::sanitize( array( 'vacations' => 'лето' ) ) ) );
		$this->assertSame( "2026-08-01 - 2026-08-15\n2026-09-01", ConsultSettings::vacationsToText( array_slice( $v, 0, 2 ) ) );
	}

	public function test_settings_sanitize_handles_consult_group(): void {
		$out = Settings::sanitize( array( 'consult' => array( 'duration_min' => 50, 'enabled' => '1' ) ), array() );

		$this->assertSame( 50, $out['consult']['duration_min'] );
		$this->assertTrue( $out['consult']['enabled'] );
		$this->assertSame( 15, $out['consult']['break_min'] );

		// Прочие ключи Settings не затрагиваются.
		$this->assertSame( 30, $out['retention_days_drafts'] );

		// Настройки без группы consult сохраняют прежнюю.
		$again = Settings::sanitize( array( 'link_ttl_days' => 10 ), $out );
		$this->assertSame( 50, $again['consult']['duration_min'] );
	}

	public function test_errors_when_no_format_is_enabled(): void {
		$s = ConsultSettings::sanitize(
			array(
				'formats' => array(
					'telegram' => array( 'enabled' => '0' ),
					'whatsapp' => array( 'enabled' => '0' ),
					'phone'    => array( 'enabled' => '0' ),
					'meet'     => array( 'enabled' => '0' ),
					'email'    => array( 'enabled' => '0' ),
				),
			)
		);

		$this->assertContains( 'Не включён ни один формат консультации.', ConsultSettings::errors( $s ) );
	}
}
