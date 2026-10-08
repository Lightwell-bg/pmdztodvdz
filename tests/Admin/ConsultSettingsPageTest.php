<?php
/**
 * Тесты разбора поля «Выходные даты» упрощённой формы настроек консультаций.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Admin;

use Bginfo\PmzhDvzh\Admin\ConsultSettingsPage;
use Bginfo\PmzhDvzh\Support\ConsultSettings;
use PHPUnit\Framework\TestCase;

final class ConsultSettingsPageTest extends TestCase {

	public function test_dates_only_strips_intervals_and_duplicates(): void {
		$this->assertSame( "2026-12-24\n2026-12-31", ConsultSettingsPage::datesOnly( "2026-12-24 выходной\n\n2026-12-31 10:00-12:00\n2026-12-24" ) );
		$this->assertSame( '', ConsultSettingsPage::datesOnly( '' ) );
	}

	public function test_bad_line_is_kept_so_schedule_validation_reports_it(): void {
		$text = ConsultSettingsPage::datesOnly( "завтра\n2026-12-24" );
		$ex   = ConsultSettings::exceptionsFromText( $text );

		$this->assertSame( 'завтра', $ex[0]['date'] );
		$this->assertSame( array(), $ex[1]['intervals'], 'дата без интервалов — выходной' );
		$this->assertNotEmpty( ConsultSettings::errors( ConsultSettings::sanitize( array( 'exceptions' => $text ) ) ) );
	}
}
