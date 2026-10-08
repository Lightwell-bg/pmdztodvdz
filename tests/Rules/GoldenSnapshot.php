<?php
/**
 * Строит «золотой» снимок результатов Evaluator по всем сценариям AnswersFactory.
 * Используется генератором bin/dev/make-golden.php и RulesGoldenTest.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Rules;

use Bginfo\PmzhDvzh\Rules\Evaluator;

final class GoldenSnapshot {

	/** @return array<string,array<string,mixed>> */
	public static function build(): array {
		$out = array();

		foreach ( AnswersFactory::scenarioAnswers() as $name => $answers ) {
			$r = ( new Evaluator() )->evaluate( $answers, AnswersFactory::config() );

			$findings = array();
			foreach ( $r['findings'] as $f ) {
				$findings[] = array(
					'code'     => $f['code'] ?? null,
					'severity' => $f['severity'] ?? null,
					'area'     => $f['area'] ?? null,
					'params'   => $f['params'] ?? null,
					'sources'  => $f['sources'] ?? null,
				);
			}

			$timeline = $r['timeline'];
			unset( $timeline['log'] );

			$out[ $name ] = array(
				'status'        => $r['status'],
				'rules_version' => $r['rules_version'],
				'calc_date'     => $r['calc_date'],
				'filing_date'   => $r['filing_date'],
				'window_start'  => $r['window_start'],
				'findings'      => $findings,
				'absences'      => array_diff_key( $r['absences'], array( 'log' => true ) ),
				'timeline'      => $timeline,
				'log'           => $r['log'],
			);
		}

		return $out;
	}
}
