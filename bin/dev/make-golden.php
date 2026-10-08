<?php
/**
 * Dev-only: генератор эталона tests/Rules/fixtures/golden-results.json.
 * Не входит в ZIP (bin/ исключён). Запуск: php bin/dev/make-golden.php
 *
 * Перегенерировать ТОЛЬКО при намеренном изменении правил (и поднять RulesMeta::VERSION).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';

use Bginfo\PmzhDvzh\Tests\Rules\GoldenSnapshot;

$out = GoldenSnapshot::build();
$dir = dirname( __DIR__, 2 ) . '/tests/Rules/fixtures';

if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}

$json = json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
file_put_contents( $dir . '/golden-results.json', $json . "\n" );

echo count( $out ) . ' scenarios, ' . strlen( $json ) . " bytes\n";
