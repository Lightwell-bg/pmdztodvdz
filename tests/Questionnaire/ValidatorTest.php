<?php
/**
 * Тесты Questionnaire\Validator (docs/ANSWERS_FORMAT.md).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Questionnaire;

use Bginfo\PmzhDvzh\Questionnaire\Validator;
use Bginfo\PmzhDvzh\Tests\Rules\AnswersFactory;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

	private const TODAY = AnswersFactory::TODAY;

	/** @return array{answers:array<string,mixed>,errors:array<string,string>,warnings:array<string,string>} */
	private function norm( array $raw ): array {
		return Validator::normalize( $raw, self::TODAY );
	}

	/** @return array<string,mixed> */
	private static function base(): array {
		return AnswersFactory::base();
	}

	public function test_valid_base_has_no_errors_and_keeps_data(): void {
		$res = $this->norm( self::base() );

		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( 'Украина', $res['answers']['applicant']['citizenship'] );
		$this->assertSame( '2019-03-15', $res['answers']['pmzh']['decision_date'] );
		$this->assertSame( 3000.0, $res['answers']['funds']['income_amount'] );
	}

	public function test_empty_input_gives_defaults_and_required_errors(): void {
		$res = $this->norm( array() );

		$this->assertArrayHasKey( 'applicant.citizenship', $res['errors'] );
		$this->assertSame( 'unknown', $res['answers']['applicant']['eu_status'] );
		$this->assertSame( 'self', $res['answers']['applicant']['family'] );
		$this->assertNull( $res['answers']['applicant']['age'] );
		$this->assertSame( 'unknown', $res['answers']['pmzh']['date_precision'] );
		$this->assertSame( '', $res['answers']['pmzh']['decision_date'] );
		$this->assertSame( array(), $res['answers']['trips'] );
		$this->assertSame( 'unknown', $res['answers']['health']['nzok'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		$raw             = self::base();
		$raw['evil']     = 'x';
		$raw['pmzh']['extra'] = 'y';
		$res             = $this->norm( $raw );

		$this->assertArrayNotHasKey( 'evil', $res['answers'] );
		$this->assertArrayNotHasKey( 'extra', $res['answers']['pmzh'] );
	}

	public function test_invalid_dates_are_errors(): void {
		$raw                         = self::base();
		$raw['pmzh']['decision_date'] = '2023-02-29';
		$res                         = $this->norm( $raw );

		$this->assertArrayHasKey( 'pmzh.decision_date', $res['errors'] );
		$this->assertSame( '', $res['answers']['pmzh']['decision_date'] );

		$raw                          = self::base();
		$raw['documents']['passport_until'] = '31.12.2030';
		$this->assertArrayHasKey( 'documents.passport_until', $this->norm( $raw )['errors'] );

		$raw                          = self::base();
		$raw['documents']['passport_until'] = '1949-12-31';
		$this->assertArrayHasKey( 'documents.passport_until', $this->norm( $raw )['errors'], 'раньше 1950' );

		$raw                          = self::base();
		$raw['documents']['passport_until'] = '2030-02-29';
		$this->assertArrayHasKey( 'documents.passport_until', $this->norm( $raw )['errors'], '2030 не високосный' );

		$raw                          = self::base();
		$raw['filing']['planned_date'] = '2029-10-09';
		$this->assertArrayHasKey( 'filing.planned_date', $this->norm( $raw )['errors'], 'позже сегодня + 3 года' );

		$raw                          = self::base();
		$raw['filing']['planned_date'] = '2029-10-08';
		$this->assertArrayNotHasKey( 'filing.planned_date', $this->norm( $raw )['errors'], 'ровно сегодня + 3 года допустимо' );

		// Сроки действия документов могут быть дальше: паспорт на 10 лет.
		$raw                                = self::base();
		$raw['documents']['passport_until'] = '2036-05-01';
		$this->assertArrayNotHasKey( 'documents.passport_until', $this->norm( $raw )['errors'] );

		$raw['documents']['passport_until'] = '2041-10-09';
		$this->assertArrayHasKey( 'documents.passport_until', $this->norm( $raw )['errors'], 'позже сегодня + 15 лет' );
	}

	public function test_unknown_precision_clears_dates(): void {
		$raw                          = self::base();
		$raw['pmzh']['date_precision'] = 'unknown';
		$raw['statuses']              = array(
			array( 'kind' => 'vnzh', 'basis' => 'работа', 'precision' => 'unknown', 'from' => '2015-01-01', 'end' => 'ended', 'to' => '2018-01-01' ),
		);
		$raw['trips_any']             = 'yes';
		$raw['trips']                 = array(
			array( 'out' => '2025-01-01', 'back' => '2025-01-10', 'countries' => 'DE', 'precision' => 'unknown' ),
		);
		$res = $this->norm( $raw );

		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( '', $res['answers']['pmzh']['decision_date'] );
		$this->assertSame( '', $res['answers']['statuses'][0]['from'] );
		$this->assertSame( '', $res['answers']['statuses'][0]['to'] );
		$this->assertSame( '', $res['answers']['trips'][0]['out'] );
		$this->assertSame( '', $res['answers']['trips'][0]['back'] );
		$this->assertSame( 'DE', $res['answers']['trips'][0]['countries'] );
	}

	public function test_approx_month_gets_first_day(): void {
		$raw                          = self::base();
		$raw['pmzh']['date_precision'] = 'approx';
		$raw['pmzh']['decision_date']  = '2019-03';
		$res                          = $this->norm( $raw );

		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( '2019-03-01', $res['answers']['pmzh']['decision_date'] );

		$raw['pmzh']['decision_date'] = '';
		$this->assertArrayHasKey( 'pmzh.decision_date', $this->norm( $raw )['errors'], 'при approx нужна хотя бы примерная дата' );

		$raw['pmzh']['date_precision'] = 'exact';
		$raw['pmzh']['decision_date']  = '2019-03';
		$this->assertArrayHasKey( 'pmzh.decision_date', $this->norm( $raw )['errors'], 'при exact месяц без дня не принимается' );
	}

	public function test_conditional_fields_are_cleared(): void {
		$raw                        = self::base();
		$raw['trips_any']           = 'no';
		$raw['trips']               = array( array( 'out' => '2025-01-01', 'back' => '2025-01-10', 'countries' => 'DE', 'precision' => 'exact' ) );
		$raw['trips_complete']      = 'complete';
		$raw['history']['gap_without_permit'] = 'no';
		$raw['history']['gaps']     = array( array( 'from' => '2015-01-01', 'to' => '2015-02-01' ) );
		$raw['health']['nzok']      = 'yes';
		$raw['health']['other_cover'] = 'policy';
		$raw['health']['cover_until'] = '2027-01-01';
		$raw['statuses']            = array( array( 'kind' => 'vnzh', 'basis' => '', 'precision' => 'exact', 'from' => '2015-01-01', 'end' => 'ongoing', 'to' => '2018-01-01' ) );
		$res                        = $this->norm( $raw );
		$a                          = $res['answers'];

		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( array(), $a['trips'] );
		$this->assertSame( 'unknown', $a['trips_complete'] );
		$this->assertSame( array(), $a['history']['gaps'] );
		$this->assertSame( 'unknown', $a['health']['other_cover'] );
		$this->assertSame( '', $a['health']['cover_until'] );
		$this->assertSame( '', $a['statuses'][0]['to'], 'to очищается, если статус не закончился' );

		$raw['health']['nzok']          = 'no';
		$raw['health']['contributions'] = 'ok';
		$res                            = $this->norm( $raw );

		$this->assertSame( 'unknown', $res['answers']['health']['contributions'] );
		$this->assertSame( 'policy', $res['answers']['health']['other_cover'] );
		$this->assertSame( '2027-01-01', $res['answers']['health']['cover_until'] );

		$raw['health']['other_cover'] = 'none';
		$this->assertSame( '', $this->norm( $raw )['answers']['health']['cover_until'] );
	}

	public function test_status_cross_checks_and_required_dates(): void {
		$raw             = self::base();
		$raw['statuses'] = array(
			array( 'kind' => 'vnzh', 'basis' => '', 'precision' => 'exact', 'from' => '2015-06-01', 'end' => 'ended', 'to' => '2015-01-01' ),
			array( 'kind' => 'vnzh', 'basis' => '', 'precision' => 'exact', 'from' => '2016-01-01', 'end' => 'ended', 'to' => '' ),
			array( 'kind' => 'bad', 'basis' => '', 'precision' => 'exact', 'from' => '', 'end' => 'ongoing', 'to' => '' ),
		);
		$res = $this->norm( $raw );

		$this->assertArrayHasKey( 'statuses.0.to', $res['errors'] );
		$this->assertArrayHasKey( 'statuses.1.to', $res['errors'] );
		$this->assertArrayHasKey( 'statuses.2.kind', $res['errors'] );
		$this->assertArrayHasKey( 'statuses.2.from', $res['errors'] );
	}

	public function test_trip_cross_checks(): void {
		$raw              = self::base();
		$raw['trips_any'] = 'yes';
		$raw['trips']     = array(
			array( 'out' => '2025-03-10', 'back' => '2025-03-01', 'countries' => '', 'precision' => 'exact' ),
			array( 'out' => '2026-10-01', 'back' => '2026-10-20', 'countries' => '', 'precision' => 'exact' ),
			array( 'out' => '2026-10-09', 'back' => '2026-10-09', 'countries' => '', 'precision' => 'exact' ),
		);
		$res = $this->norm( $raw );

		$this->assertArrayHasKey( 'trips.0.back', $res['errors'], 'back < out' );
		$this->assertArrayHasKey( 'trips.1.back', $res['errors'], 'возвращение в будущем' );
		$this->assertArrayHasKey( 'trips.2.out', $res['errors'], 'выезд в будущем' );
		$this->assertStringContainsString( 'совершённые', $res['errors']['trips.1.back'] );
	}

	public function test_overlapping_trips_are_error_but_back_to_back_is_not(): void {
		$raw              = self::base();
		$raw['trips_any'] = 'yes';
		$raw['trips']     = array(
			array( 'out' => '2025-03-01', 'back' => '2025-03-10', 'countries' => '', 'precision' => 'exact' ),
			array( 'out' => '2025-03-10', 'back' => '2025-03-15', 'countries' => '', 'precision' => 'exact' ),
		);
		$this->assertSame( array(), $this->norm( $raw )['errors'], 'выезд в день возвращения — не пересечение' );

		$raw['trips'][1]['out'] = '2025-03-09';
		$res                    = $this->norm( $raw );
		$this->assertArrayHasKey( 'trips.1.out', $res['errors'] );
		$this->assertStringContainsString( '№1', $res['errors']['trips.1.out'] );

		// Порядок строк в списке не важен.
		$raw['trips'] = array_reverse( $raw['trips'] );
		$this->assertNotSame( array(), $this->norm( $raw )['errors'] );
	}

	public function test_gaps_validation(): void {
		$raw                                  = self::base();
		$raw['history']['gap_without_permit'] = 'yes';
		$raw['history']['gaps']               = array(
			array( 'from' => '2016-05-01', 'to' => '2016-04-01' ),
			array( 'from' => '', 'to' => '2017-01-01' ),
		);
		$res = $this->norm( $raw );

		$this->assertArrayHasKey( 'history.gaps.0.to', $res['errors'] );
		$this->assertArrayHasKey( 'history.gaps.1.from', $res['errors'] );
		$this->assertCount( 2, $res['answers']['history']['gaps'] );
	}

	public function test_planned_date_in_past_is_error_today_is_ok(): void {
		$raw                     = self::base();
		$raw['filing']           = array( 'planned_date' => '2026-10-07' );
		$res                     = $this->norm( $raw );
		$this->assertArrayHasKey( 'filing.planned_date', $res['errors'] );

		$raw['filing']['planned_date'] = '2026-10-08';
		$this->assertSame( array(), $this->norm( $raw )['errors'] );

		$raw['filing']['planned_date'] = '';
		$this->assertSame( '', $this->norm( $raw )['answers']['filing']['planned_date'] );
	}

	public function test_list_limits(): void {
		$raw = self::base();

		$status = array( 'kind' => 'vnzh', 'basis' => '', 'precision' => 'unknown', 'from' => '', 'end' => 'unknown', 'to' => '' );
		$raw['statuses'] = array_fill( 0, 21, $status );
		$res             = $this->norm( $raw );
		$this->assertArrayHasKey( 'statuses', $res['errors'] );
		$this->assertCount( 20, $res['answers']['statuses'] );

		$raw             = self::base();
		$raw['trips_any'] = 'yes';
		$raw['trips']    = array_fill( 0, 151, array( 'out' => '', 'back' => '', 'countries' => '', 'precision' => 'unknown' ) );
		$res             = $this->norm( $raw );
		$this->assertArrayHasKey( 'trips', $res['errors'] );
		$this->assertCount( 150, $res['answers']['trips'] );

		$raw                                  = self::base();
		$raw['history']['gap_without_permit'] = 'yes';
		$raw['history']['gaps']               = array_fill( 0, 11, array( 'from' => '2016-01-01', 'to' => '2016-02-01' ) );
		$res                                  = $this->norm( $raw );
		$this->assertArrayHasKey( 'history.gaps', $res['errors'] );
		$this->assertCount( 10, $res['answers']['history']['gaps'] );
	}

	public function test_text_is_trimmed_truncated_and_stripped_of_control_chars(): void {
		$raw                                = self::base();
		$raw['applicant']['citizenship']    = "  Укра\x00и\x07на\n";
		$raw['applicant']['other_citizenships'] = str_repeat( 'я', 250 );
		$res                                = $this->norm( $raw );

		$this->assertSame( 'Украина', $res['answers']['applicant']['citizenship'] );
		$this->assertSame( 200, mb_strlen( $res['answers']['applicant']['other_citizenships'] ) );
	}

	public function test_html_in_text_is_kept_as_text_for_output_escaping(): void {
		$raw                             = self::base();
		$raw['applicant']['citizenship'] = '<b>Украина</b>';
		$res                             = $this->norm( $raw );

		$this->assertSame( '<b>Украина</b>', $res['answers']['applicant']['citizenship'] );
	}

	public function test_numbers(): void {
		$raw                          = self::base();
		$raw['applicant']['age']      = 121;
		$raw['funds']['income_amount'] = '-5';
		$raw['funds']['dependents']   = 21;
		$res                          = $this->norm( $raw );

		$this->assertArrayHasKey( 'applicant.age', $res['errors'] );
		$this->assertArrayHasKey( 'funds.income_amount', $res['errors'] );
		$this->assertArrayHasKey( 'funds.dependents', $res['errors'] );

		$raw                           = self::base();
		$raw['applicant']['age']       = '';
		$raw['funds']['income_amount'] = '1 200,50';
		$raw['funds']['dependents']    = '2';
		$res                           = $this->norm( $raw );

		$this->assertSame( array(), $res['errors'] );
		$this->assertNull( $res['answers']['applicant']['age'] );
		$this->assertSame( 1200.5, $res['answers']['funds']['income_amount'] );
		$this->assertSame( 2, $res['answers']['funds']['dependents'] );

		$raw['funds']['income_amount'] = 1000001;
		$this->assertArrayHasKey( 'funds.income_amount', $this->norm( $raw )['errors'] );
	}

	public function test_enums_and_lists(): void {
		$raw                            = self::base();
		$raw['applicant']['eu_status']  = 'martian';
		$raw['funds']['income_sources'] = array( 'salary', 'salary', 'lottery' );
		$raw['housing']['proofs']       = 'notarial_act';
		$res                            = $this->norm( $raw );

		$this->assertArrayHasKey( 'applicant.eu_status', $res['errors'] );
		$this->assertArrayHasKey( 'funds.income_sources', $res['errors'] );
		$this->assertSame( array( 'salary' ), $res['answers']['funds']['income_sources'] );
		$this->assertSame( array(), $res['answers']['housing']['proofs'] );
	}

	public function test_warnings(): void {
		$raw              = self::base();
		$raw['trips_any'] = 'yes';
		$raw['trips']     = array();
		$res              = $this->norm( $raw );

		$this->assertSame( array(), $res['errors'] );
		$this->assertArrayHasKey( 'trips', $res['warnings'] );

		$raw['trips'] = array(
			array( 'out' => '2018-01-01', 'back' => '2018-01-10', 'countries' => '', 'precision' => 'exact' ),
			array( 'out' => '2025-01-01', 'back' => '2025-01-10', 'countries' => '', 'precision' => 'exact' ),
		);
		$res = $this->norm( $raw );

		$this->assertSame( array(), $res['errors'] );
		$this->assertArrayHasKey( 'trips.0', $res['warnings'] );
		$this->assertArrayNotHasKey( 'trips.1', $res['warnings'] );
	}

	public function test_draft_is_lenient(): void {
		$raw                          = self::base();
		$raw['applicant']['citizenship'] = '';
		$raw['pmzh']['decision_date']  = '2023-02-29';
		$raw['trips_any']             = 'yes';
		$raw['trips']                 = array( array( 'out' => '2030-01-02', 'back' => '2029-01-01', 'countries' => 'x', 'precision' => 'exact' ) );
		$res                          = Validator::normalizeDraft( $raw, self::TODAY );

		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( array(), $res['warnings'] );
		$this->assertSame( '', $res['answers']['pmzh']['decision_date'] );
		$this->assertCount( 1, $res['answers']['trips'] );
		$this->assertSame( 'x', $res['answers']['trips'][0]['countries'] );
	}

	public function test_non_array_sections_do_not_break(): void {
		$res = $this->norm( array( 'applicant' => 'x', 'statuses' => 'y', 'trips' => 5, 'funds' => null ) );

		$this->assertArrayHasKey( 'applicant.citizenship', $res['errors'] );
		$this->assertSame( array(), $res['answers']['statuses'] );
	}

	public function test_normalized_answers_are_idempotent(): void {
		$first  = $this->norm( self::base() );
		$second = $this->norm( $first['answers'] );

		$this->assertSame( array(), $second['errors'] );
		$this->assertSame( $first['answers'], $second['answers'] );
	}
}
