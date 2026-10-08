<?php
/**
 * Тесты форматтера ответов и подписей админки.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Admin;

use Bginfo\PmzhDvzh\Admin\AnswersFormatter;
use Bginfo\PmzhDvzh\Admin\Display;
use Bginfo\PmzhDvzh\Tests\Rules\AnswersFactory;
use PHPUnit\Framework\TestCase;

final class AnswersFormatterTest extends TestCase {

	/** @return array<string,string> */
	private function flat( array $answers ): array {
		$out = array();

		foreach ( AnswersFormatter::sections( $answers ) as $section ) {
			foreach ( $section['rows'] as $row ) {
				$out[ $row[0] ] = $row[1];
			}
		}

		return $out;
	}

	public function test_base_answers_are_readable(): void {
		$rows = $this->flat( AnswersFactory::base() );

		$this->assertSame( 'Украина', $rows['Гражданство'] );
		$this->assertSame( '15.03.2019', $rows['Дата решения о ПМЖ'] );
		$this->assertSame( 'Да', $rows['Решение о ПМЖ сохранилось'] );
		$this->assertSame( '—', $rows['Поездки'] );
		$this->assertSame( '3 000,00 EUR', $rows['Средний доход в месяц'] );
		$this->assertSame( 'не указана (расчёт на дату отправки)', $rows['Планируемая дата подачи'] );
	}

	public function test_seven_sections(): void {
		$this->assertCount( 7, AnswersFormatter::sections( AnswersFactory::base() ) );
	}

	public function test_trips_and_statuses_are_listed_per_line(): void {
		$answers = AnswersFactory::with(
			array(
				'trips_any' => 'yes',
				'trips'     => array(
					AnswersFactory::trip( '2025-01-10', '2025-02-01' ),
					AnswersFactory::trip( '2025-06-01', '2025-06-10', 'approx' ),
				),
				'statuses'  => array( AnswersFactory::status( 'study_24v', '2015-09-01', 'ended', '2018-06-30' ) ),
			)
		);

		$rows = $this->flat( $answers );

		$this->assertSame( 2, substr_count( $rows['Поездки'], "\n" ) + 1 );
		$this->assertStringContainsString( '10.01.2025 — 01.02.2025, Турция', $rows['Поездки'] );
		$this->assertStringContainsString( '01.09.2015', $rows['Статусы до ПМЖ'] );
		$this->assertStringContainsString( '30.06.2018', $rows['Статусы до ПМЖ'] );
	}

	public function test_missing_keys_do_not_break(): void {
		$rows = $this->flat( array() );

		$this->assertSame( '—', $rows['Гражданство'] );
		$this->assertSame( 'не указан', $rows['Возраст'] );
	}

	public function test_values_are_not_escaped_here(): void {
		$answers                                  = AnswersFactory::base();
		$answers['applicant']['citizenship'] = '<b>x</b>';

		$this->assertSame( '<b>x</b>', $this->flat( $answers )['Гражданство'] );
	}

	public function test_display_helpers(): void {
		$this->assertSame( '15.03.2019', Display::date( '2019-03-15' ) );
		$this->assertSame( '89abcdef', Display::shortNumber( '0123456789abcdef0123456789abcdef' ) );
		$this->assertSame( 'Оплачено', Display::paymentLabel( 'paid' ) );
		$this->assertSame( '—', Display::localTime( null ) );
	}
}
