<?php
/**
 * Обёртка PHPUnit над bin/concurrency-check.php (реальный InnoDB, несколько соединений и процессов).
 *
 * Выполняется только если задана переменная окружения BGDVZH_TEST_MYSQL_DSN
 * (необязательно: BGDVZH_TEST_MYSQL_USER, BGDVZH_TEST_MYSQL_PASS); иначе
 * пропускается, и обычный набор тестов зелёный без MySQL.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Concurrency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InnoDbConcurrencyTest extends TestCase {

	/** @return array<string,array{0:string}> */
	public static function scenarios(): array {
		return array(
			'a: один слот, 8 процессов'           => array( 'a' ),
			'b: перекрытие с перерывом'           => array( 'b' ),
			'c: последнее письменное место'       => array( 'c' ),
			'd: один зачёт'                       => array( 'd' ),
			'e: поздняя оплата против занявшего'  => array( 'e' ),
			'f: параллельный перенос'             => array( 'f' ),
			'g: deadlock и повторы'               => array( 'g' ),
		);
	}

	#[DataProvider( 'scenarios' )]
	public function test_scenario( string $key ): void {
		$dsn = getenv( 'BGDVZH_TEST_MYSQL_DSN' );

		if ( false === $dsn || '' === $dsn ) {
			$this->markTestSkipped( 'BGDVZH_TEST_MYSQL_DSN не задан: проверка на InnoDB пропущена.' );
		}

		if ( ! extension_loaded( 'pdo_mysql' ) ) {
			$this->markTestSkipped( 'Нет расширения pdo_mysql.' );
		}

		$user   = getenv( 'BGDVZH_TEST_MYSQL_USER' );
		$pass   = getenv( 'BGDVZH_TEST_MYSQL_PASS' );
		$script = dirname( __DIR__, 2 ) . '/bin/concurrency-check.php';
		// Свой префикс на сценарий: таблицы разных запусков не пересекаются.
		$prefix = 'bgdvzh_cc_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) . '_';

		$cmd = array(
			PHP_BINARY,
			$script,
			'--dsn=' . $dsn,
			'--user=' . ( false === $user ? 'root' : $user ),
			'--pass=' . ( false === $pass ? '' : $pass ),
			'--prefix=' . $prefix,
			'--only=' . $key,
		);

		$pr = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $pr, 'Не удалось запустить concurrency-check.php' );

		$out  = (string) stream_get_contents( $pipes[1] );
		$err  = (string) stream_get_contents( $pipes[2] );
		$code = proc_close( $pr );

		$this->assertSame( 0, $code, "concurrency-check.php завершился с кодом $code\n$out\n$err" );
		$this->assertStringContainsString( 'PASS ' . $key . ' ', $out, $out );
		$this->assertStringNotContainsString( 'FAIL', $out, $out );
	}
}
