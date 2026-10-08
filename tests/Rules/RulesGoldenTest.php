<?php
/**
 * Золотой тест правил: результат Evaluator по каждому сценарию AnswersFactory
 * должен совпадать с tests/Rules/fixtures/golden-results.json.
 *
 * Перегенерировать эталон (php bin/dev/make-golden.php) ТОЛЬКО при намеренном
 * изменении правил — и тогда поднять RulesMeta::VERSION.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Rules;

use PHPUnit\Framework\TestCase;

final class RulesGoldenTest extends TestCase {

	public function test_results_match_golden_fixture(): void {
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/golden-results.json' ), true, 512, JSON_THROW_ON_ERROR );
		// Прогон через JSON — те же типы, что в эталоне.
		$actual = json_decode( json_encode( GoldenSnapshot::build(), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( array_keys( $expected ), array_keys( $actual ), 'Список сценариев разошёлся с эталоном' );

		foreach ( $expected as $name => $want ) {
			$this->assertSame( $want, $actual[ $name ], "Сценарий '{$name}' разошёлся с эталоном" );
		}
	}
}
