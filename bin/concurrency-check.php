<?php
/**
 * Проверка конкурентности атомарного SQL бронирования и зачётов на реальном InnoDB.
 *
 * Берёт настоящие SlotRepository и CreditRepository, создаёт в служебном
 * префиксе копии таблиц units / seats / credits (колонки и ключи как в
 * Storage\Schema), гоняет сценарии на нескольких независимых соединениях
 * и настоящих параллельных процессах PHP и проверяет инварианты с третьего
 * соединения. В конце удаляет ТОЛЬКО свои таблицы (префикс обязан содержать «cc»).
 *
 * Запуск (нужен php с pdo_mysql):
 *   php bin/concurrency-check.php --dsn="mysql:host=127.0.0.1;port=3407;dbname=bgdvzh_test;charset=utf8mb4" --user=root --pass=root
 *   необязательно: --prefix=bgdvzh_cc_  --only=a,e  --iterations=200  --workers=8
 * Значения по умолчанию берутся из BGDVZH_TEST_MYSQL_DSN / _USER / _PASS.
 * Код выхода 0 — все сценарии PASS, 1 — есть FAIL, 2 — ошибка запуска.
 *
 * Тестовую базу выбирайте отдельную (staging): скрипт создаёт и удаляет таблицы.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\SlotRepository;

require_once dirname( __DIR__ ) . '/src/Storage/SlotRepository.php';
require_once dirname( __DIR__ ) . '/src/Storage/CreditRepository.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

const CC_NOW  = 1800000000; // Кратно 300 с.
const CC_DAY  = 86400;
const CC_STEP = 300;

/**
 * Совместимая с wpdb обёртка над PDO (MySQL, autocommit как в WordPress).
 */
final class CcDb {

	public string $last_error = '';
	public int $insert_id     = 0;
	public int $rows_affected = 0;
	public string $prefix     = 'wp_';
	public \PDO $pdo;

	/** Принудительные сбои: первые $failTimes запросов, подходящих под $failPattern, не выполняются. */
	public int $failTimes        = 0;
	public string $failPattern   = '/^INSERT/i';
	public string $failMessage   = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';
	public int $failedQueryCount = 0;

	private bool $suppress = false;

	public function __construct( string $dsn, string $user, string $pass ) {
		$this->pdo = new \PDO(
			$dsn,
			$user,
			$pass,
			array(
				\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			)
		);
		$this->pdo->exec( 'SET SESSION innodb_lock_wait_timeout = 20' );
	}

	public function suppress_errors( $suppress = true ): bool {
		$prev           = $this->suppress;
		$this->suppress = (bool) $suppress;

		return $prev;
	}

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return (string) preg_replace_callback(
			'/%[%dsf]/',
			function ( array $m ) use ( &$i, $args ): string {
				if ( '%%' === $m[0] ) {
					return '%';
				}

				$v = $args[ $i++ ];

				if ( '%d' === $m[0] ) {
					return (string) (int) $v;
				}

				if ( '%f' === $m[0] ) {
					return (string) (float) $v;
				}

				return $this->pdo->quote( null === $v ? '' : (string) $v );
			},
			$query
		);
	}

	/** @return int|false */
	public function query( string $sql ) {
		$this->last_error = '';

		if ( $this->failTimes > 0 && 1 === preg_match( $this->failPattern, ltrim( $sql ) ) ) {
			--$this->failTimes;
			++$this->failedQueryCount;
			$this->last_error = $this->failMessage;

			return false;
		}

		try {
			$n                   = $this->pdo->exec( $sql );
			$this->rows_affected = (int) $n;
			$this->insert_id     = (int) $this->pdo->lastInsertId();

			return (int) $n;
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	public function get_var( string $sql ) {
		$row = $this->rows( $sql, \PDO::FETCH_NUM );

		return array() === $row ? null : $row[0][0];
	}

	/** @return list<mixed> */
	public function get_col( string $sql ): array {
		return $this->rows( $sql, \PDO::FETCH_COLUMN );
	}

	public function get_row( string $sql, $output = 'ARRAY_A' ) {
		$rows = $this->rows( $sql, \PDO::FETCH_ASSOC );

		return array() === $rows ? null : $rows[0];
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $sql, $output = 'ARRAY_A' ): array {
		return $this->rows( $sql, \PDO::FETCH_ASSOC );
	}

	/** @return list<mixed> */
	private function rows( string $sql, int $mode ): array {
		$this->last_error = '';

		try {
			$st  = $this->pdo->query( $sql );
			$out = $st->fetchAll( $mode );
			$st->closeCursor();

			return $out;
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();

			return array();
		}
	}
}

// ---------------------------------------------------------------------
// Вспомогательное.

/** @return list<int> Единицы по 5 минут для интервала [from, to) в минутах от начала суток $base. */
function cc_units( int $base, int $fromMin, int $toMin ): array {
	$out = array();

	for ( $t = $base + $fromMin * 60; $t < $base + $toMin * 60; $t += CC_STEP ) {
		$out[] = $t;
	}

	return $out;
}

function cc_wait_until( float $at ): void {
	while ( true ) {
		$d = $at - microtime( true );

		if ( $d <= 0 ) {
			return;
		}

		if ( $d > 0.003 ) {
			usleep( (int) ( ( $d - 0.002 ) * 1000000 ) );
		}
	}
}

/** @return list<string> */
function cc_ddl( string $p ): array {
	$o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

	return array(
		"CREATE TABLE {$p}units (
			unit_utc bigint(20) NOT NULL,
			booking_id bigint(20) unsigned NOT NULL,
			expires_at bigint(20) NULL,
			PRIMARY KEY (unit_utc),
			KEY booking_id (booking_id),
			KEY expires_at (expires_at)
		) $o",
		"CREATE TABLE {$p}seats (
			local_date date NOT NULL,
			seat_no smallint(5) unsigned NOT NULL,
			booking_id bigint(20) unsigned NOT NULL,
			expires_at bigint(20) NULL,
			PRIMARY KEY (local_date,seat_no),
			KEY booking_id (booking_id)
		) $o",
		"CREATE TABLE {$p}credits (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			instruction_purchase_id bigint(20) unsigned NOT NULL,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(18,9) NOT NULL DEFAULT 0,
			currency varchar(8) NOT NULL DEFAULT '',
			tax_mode varchar(8) NOT NULL DEFAULT 'incl',
			state varchar(10) NOT NULL,
			hold_until bigint(20) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY instruction_purchase_id (instruction_purchase_id),
			KEY booking_id (booking_id),
			KEY state (state)
		) $o",
	);
}

function cc_drop( CcDb $db, string $p ): void {
	foreach ( array( 'units', 'seats', 'credits' ) as $t ) {
		$db->pdo->exec( "DROP TABLE IF EXISTS {$p}{$t}" );
	}
}

// ---------------------------------------------------------------------
// Рабочий процесс (--worker=N): читает команды JSON из STDIN, отвечает строкой JSON.

/** @param array<string,string|false|list<string|false>> $o */
function cc_worker( array $o ): void {
	$p     = (string) $o['prefix'];
	$db    = new CcDb( (string) $o['dsn'], (string) $o['user'], (string) $o['pass'] );
	$slots = new SlotRepository( $db, $p . 'units', $p . 'seats' );
	$cred  = new CreditRepository( $db, $p . 'credits' );

	fwrite( STDOUT, json_encode( array( 'ready' => true ) ) . "\n" );
	fflush( STDOUT );

	while ( false !== ( $line = fgets( STDIN ) ) ) {
		$c = json_decode( trim( $line ), true );

		if ( ! is_array( $c ) ) {
			continue;
		}

		if ( 'quit' === $c['op'] ) {
			break;
		}

		cc_wait_until( (float) ( $c['at'] ?? 0 ) );

		try {
			switch ( $c['op'] ) {
				case 'claim':
					$r = $slots->claimUnits( (int) $c['b'], $c['units'], $c['exp'], (int) $c['now'] );
					break;
				case 'confirm':
					$r = $slots->confirmUnits( (int) $c['b'], $c['units'], (int) $c['now'] );
					break;
				case 'transfer':
					$r = $slots->transferUnits( (int) $c['b'], $c['units'], $c['exp'], (int) $c['now'] );
					break;
				case 'seat':
					$r = $slots->claimSeat( (int) $c['b'], (string) $c['date'], (int) $c['limit'], $c['exp'], (int) $c['now'] );
					break;
				case 'reserve':
					$r = $cred->reserve( (int) $c['ip'], (int) $c['b'], '10.000000000', 'EUR', 'incl', (int) $c['hold'], '2030-01-01 00:00:00' );
					break;
				case 'markUsed':
					$r = $cred->markUsed( (int) $c['ip'], (int) $c['b'], '2030-01-01 00:00:00' );
					break;
				case 'releaseReserved':
					$cred->releaseReserved( (int) $c['b'], '2030-01-01 00:00:00' );
					$r = true;
					break;
				case 'set_timeout':
					$db->pdo->exec( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $c['sec'] );
					$r = true;
					break;
				case 'tx_begin':
					$db->pdo->beginTransaction();
					$r = true;
					break;
				case 'tx_lock':
					try {
						$db->pdo->exec( $db->prepare( "UPDATE {$p}units SET expires_at = 1 WHERE unit_utc = %d", (int) $c['unit'] ) );
						$r = array( 'ok' => true );
					} catch ( \PDOException $e ) {
						$r = array(
							'ok'        => false,
							'code'      => (int) ( $e->errorInfo[1] ?? 0 ),
							'msg'       => $e->getMessage(),
							'retryable' => SlotRepository::isRetryable( $e->getMessage() ),
						);

						if ( $db->pdo->inTransaction() ) {
							$db->pdo->rollBack();
						}
					}
					break;
				case 'tx_end':
					if ( $db->pdo->inTransaction() ) {
						$db->pdo->commit();
					}
					$r = true;
					break;
				default:
					$r = array( 'unknown_op' => $c['op'] );
			}

			$out = array( 'r' => $r );
		} catch ( \Throwable $e ) {
			$out = array( 'exc' => get_class( $e ) . ': ' . $e->getMessage() );
		}

		fwrite( STDOUT, json_encode( $out ) . "\n" );
		fflush( STDOUT );
	}
}

/**
 * Пул постоянных рабочих процессов (у каждого своё соединение с БД).
 */
final class CcPool {

	/** @var array<int,resource> */
	private array $procs = array();
	/** @var array<int,array<int,resource>> */
	private array $pipes = array();

	/** @param array<string,string> $o */
	public function __construct( array $o, int $n ) {
		for ( $i = 0; $i < $n; $i++ ) {
			$cmd = array(
				PHP_BINARY,
				__FILE__,
				'--worker=' . $i,
				'--dsn=' . $o['dsn'],
				'--user=' . $o['user'],
				'--pass=' . $o['pass'],
				'--prefix=' . $o['prefix'],
			);
			$err = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bgdvzh-cc-worker-' . $i . '.log';
			$pr  = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'file', $err, 'w' ) ), $pipes );

			if ( ! is_resource( $pr ) ) {
				throw new \RuntimeException( 'Не удалось запустить рабочий процесс ' . $i );
			}

			$this->procs[ $i ] = $pr;
			$this->pipes[ $i ] = $pipes;

			$ready = $this->recv( $i );

			if ( ! isset( $ready['ready'] ) ) {
				throw new \RuntimeException( 'Рабочий процесс ' . $i . ' не стартовал, см. ' . $err );
			}
		}
	}

	public function size(): int {
		return count( $this->procs );
	}

	/** @param array<string,mixed> $cmd */
	public function send( int $i, array $cmd ): void {
		fwrite( $this->pipes[ $i ][0], json_encode( $cmd ) . "\n" );
		fflush( $this->pipes[ $i ][0] );
	}

	/** @return array<string,mixed>|null */
	public function recv( int $i ): ?array {
		$line = fgets( $this->pipes[ $i ][1] );

		if ( false === $line ) {
			return null;
		}

		$d = json_decode( trim( $line ), true );

		return is_array( $d ) ? $d : null;
	}

	/**
	 * Выполнить команды одновременно: все ждут общей метки времени.
	 *
	 * @param array<int,array<string,mixed>> $cmds   worker => команда
	 * @param array<int,int>                 $jitter worker => задержка в мкс (необязательно)
	 * @return array<int,mixed> worker => результат (r) либо строка ошибки
	 */
	public function run( array $cmds, array $jitter = array(), float $lead = 0.25 ): array {
		$at = microtime( true ) + $lead;

		foreach ( $cmds as $i => $cmd ) {
			$cmd['at'] = $at + ( $jitter[ $i ] ?? 0 ) / 1000000;
			$this->send( $i, $cmd );
		}

		$out = array();

		foreach ( array_keys( $cmds ) as $i ) {
			$r         = $this->recv( $i );
			$out[ $i ] = null === $r ? 'NO_REPLY' : ( $r['exc'] ?? ( $r['r'] ?? null ) );
		}

		return $out;
	}

	public function close(): void {
		foreach ( $this->procs as $i => $pr ) {
			try {
				$this->send( $i, array( 'op' => 'quit' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			proc_close( $pr );
		}

		$this->procs = array();
	}
}

// ---------------------------------------------------------------------
// Сценарии. Каждый возвращает array{violations: list<string>, info: string}.

final class CcScenarios {

	public function __construct(
		private CcDb $v,
		private SlotRepository $slotsA,
		private SlotRepository $slotsB,
		private CreditRepository $creditA,
		private CcPool $pool,
		private string $p,
		private int $iterations
	) {}

	/** @return list<array{0:string,1:string,2:callable}> */
	public function all(): array {
		return array(
			array( 'a', 'один слот, 8 процессов', fn() => $this->a() ),
			array( 'b', 'перекрытие с перерывом', fn() => $this->b() ),
			array( 'c', 'последнее письменное место, 8 процессов', fn() => $this->c() ),
			array( 'd', 'один зачёт, 8 процессов', fn() => $this->d() ),
			array( 'e', 'запоздавшая оплата против занявшего время', fn() => $this->e() ),
			array( 'f', 'параллельный перенос в один слот', fn() => $this->f() ),
			array( 'g', 'deadlock и повторы', fn() => $this->g() ),
		);
	}

	/** @param list<int> $units */
	private function inList( array $units ): string {
		return implode( ',', array_map( 'intval', $units ) );
	}

	/** @return array<int,int> booking_id => число единиц среди $units */
	private function owners( array $units ): array {
		$rows = $this->v->get_results( "SELECT booking_id, COUNT(*) AS c FROM {$this->p}units WHERE unit_utc IN (" . $this->inList( $units ) . ') GROUP BY booking_id' );
		$out  = array();

		foreach ( $rows as $r ) {
			$out[ (int) $r['booking_id'] ] = (int) $r['c'];
		}

		return $out;
	}

	private function seed( int $bookingId, array $units, ?int $exp ): void {
		foreach ( $units as $u ) {
			$this->v->query( $this->v->prepare( "INSERT INTO {$this->p}units (unit_utc, booking_id, expires_at) VALUES (%d, %d, " . ( null === $exp ? 'NULL' : '%d' ) . ')', ...( null === $exp ? array( $u, $bookingId ) : array( $u, $bookingId, $exp ) ) ) );
		}
	}

	private function a(): array {
		$bad    = array();
		$rounds = 6;
		$n      = $this->pool->size();

		for ( $r = 0; $r < $rounds; $r++ ) {
			$base  = CC_NOW + ( 10 + $r ) * CC_DAY;
			$units = cc_units( $base, 600, 660 );

			if ( 1 === $r % 2 ) {
				// Чужой истёкший резерв на части единиц: претенденты параллельно его вычищают.
				$this->seed( 99, array_slice( $units, 2, 5 ), CC_NOW - 5 );
			}

			$cmds = array();

			for ( $i = 0; $i < $n; $i++ ) {
				$cmds[ $i ] = array( 'op' => 'claim', 'b' => $i + 1, 'units' => $units, 'exp' => CC_NOW + 900, 'now' => CC_NOW );
			}

			$res  = $this->pool->run( $cmds );
			$wins = array_keys( array_filter( $res, static fn( $x ) => true === $x ) );
			$own  = $this->owners( $units );
			$tag  = "раунд $r: ";

			if ( 1 !== count( $wins ) ) {
				$bad[] = $tag . 'победителей ' . count( $wins ) . ' вместо 1; результаты=' . json_encode( $res );
			}

			if ( 1 !== count( $own ) || (int) reset( $own ) !== count( $units ) ) {
				$bad[] = $tag . 'владельцы единиц ' . json_encode( $own ) . ' (нужен один владелец на все ' . count( $units ) . ')';
			} elseif ( 1 === count( $wins ) && array_key_first( $own ) !== $wins[0] + 1 ) {
				$bad[] = $tag . 'владелец ' . array_key_first( $own ) . ' не совпадает с победителем ' . ( $wins[0] + 1 );
			}

			$total = (int) $this->v->get_var( "SELECT COUNT(*) FROM {$this->p}units WHERE unit_utc >= " . ( $base ) . ' AND unit_utc < ' . ( $base + CC_DAY ) );

			if ( $total !== count( $units ) ) {
				$bad[] = $tag . "в таблице $total строк за день вместо " . count( $units ) . ' (остались хвосты проигравших или чужого резерва)';
			}
		}

		return array( $bad, "$rounds раундов x $n процессов" );
	}

	private function b(): array {
		$bad   = array();
		$base  = CC_NOW + 30 * CC_DAY;
		$first = cc_units( $base, 600, 660 ); // 10:00-10:45 + 15 мин перерыва.
		$sec   = cc_units( $base, 645, 705 ); // 10:45-11:30 + 15: пересекается на 10:45-11:00.
		$third = cc_units( $base, 660, 720 ); // 11:00-11:45 + 15: встык.

		if ( ! $this->slotsA->claimUnits( 1, $first, CC_NOW + 900, CC_NOW ) ) {
			$bad[] = 'первая бронь не заняла свободное время';
		}

		if ( $this->slotsB->claimUnits( 2, $sec, CC_NOW + 900, CC_NOW ) ) {
			$bad[] = 'вторая бронь [10:45..] заняла время, пересекающееся с перерывом первой';
		}

		$own = $this->owners( $sec );

		if ( isset( $own[2] ) ) {
			$bad[] = 'у проигравшей брони остались частичные единицы: ' . $own[2];
		}

		if ( ( $own[1] ?? 0 ) !== 3 ) {
			$bad[] = 'у первой брони в зоне пересечения ' . ( $own[1] ?? 0 ) . ' единиц вместо 3';
		}

		if ( ! $this->slotsB->claimUnits( 3, $third, CC_NOW + 900, CC_NOW ) ) {
			$bad[] = 'бронь встык [11:00..] не заняла свободное время';
		}

		$all = (int) $this->v->get_var( "SELECT COUNT(*) FROM {$this->p}units WHERE unit_utc >= $base AND unit_utc < " . ( $base + CC_DAY ) );

		if ( 24 !== $all ) {
			$bad[] = "всего единиц за день $all вместо 24";
		}

		// Детерминированное перемежение на двух соединениях: A держит открытую транзакцию, B ждёт и получает таймаут.
		$base2 = CC_NOW + 31 * CC_DAY;
		$u2    = cc_units( $base2, 600, 660 );
		$this->slotsA->claimUnits( 5, $u2, CC_NOW + 900, CC_NOW );
		$this->slotsA->confirmUnits( 5, $u2, CC_NOW );

		if ( $this->slotsB->claimUnits( 6, $u2, CC_NOW + 900, CC_NOW ) ) {
			$bad[] = 'подтверждённое время занято вторым соединением';
		}

		return array( $bad, 'встык проходит, пересечение отклонено без хвостов' );
	}

	private function c(): array {
		$bad    = array();
		$rounds = 6;
		$n      = $this->pool->size();
		$limit  = 2;

		for ( $r = 0; $r < $rounds; $r++ ) {
			$date = sprintf( '2030-02-%02d', $r + 1 );

			if ( 1 === $r % 2 ) {
				// Истёкшее место: его вычищают претенденты.
				$this->v->query( "INSERT INTO {$this->p}seats (local_date, seat_no, booking_id, expires_at) VALUES ('$date', 1, 99, " . ( CC_NOW - 5 ) . ')' );
			}

			$cmds = array();

			for ( $i = 0; $i < $n; $i++ ) {
				$cmds[ $i ] = array( 'op' => 'seat', 'b' => $i + 1, 'date' => $date, 'limit' => $limit, 'exp' => CC_NOW + 900, 'now' => CC_NOW );
			}

			$res  = $this->pool->run( $cmds );
			$got  = array_values( array_filter( $res, static fn( $x ) => is_int( $x ) ) );
			$rows = $this->v->get_results( "SELECT seat_no, booking_id FROM {$this->p}seats WHERE local_date = '$date' ORDER BY seat_no" );
			$tag  = "раунд $r: ";

			if ( count( $got ) !== $limit ) {
				$bad[] = $tag . 'мест получено ' . count( $got ) . " вместо $limit; результаты=" . json_encode( $res );
			}

			if ( count( $rows ) !== $limit ) {
				$bad[] = $tag . 'строк мест ' . count( $rows ) . " вместо $limit";
			}

			$nos = array_map( static fn( $x ) => (int) $x['seat_no'], $rows );

			if ( count( array_unique( $nos ) ) !== count( $nos ) || count( array_unique( $got ) ) !== count( $got ) ) {
				$bad[] = $tag . 'повтор номера места: ' . json_encode( $res );
			}

			foreach ( $res as $i => $seat ) {
				if ( is_int( $seat ) ) {
					$owner = $this->v->get_var( "SELECT booking_id FROM {$this->p}seats WHERE local_date = '$date' AND seat_no = $seat" );

					if ( (int) $owner !== $i + 1 ) {
						$bad[] = $tag . "процесс $i получил место $seat, а владелец " . json_encode( $owner );
					}
				}
			}
		}

		return array( $bad, "$rounds раундов x $n процессов, лимит $limit" );
	}

	private function d(): array {
		$bad    = array();
		$rounds = 6;
		$n      = $this->pool->size();

		for ( $r = 0; $r < $rounds; $r++ ) {
			$ip   = 1000 + $r;
			$cmds = array();

			for ( $i = 0; $i < $n; $i++ ) {
				$cmds[ $i ] = array( 'op' => 'reserve', 'ip' => $ip, 'b' => $i + 1, 'hold' => CC_NOW + 900 );
			}

			$res  = $this->pool->run( $cmds );
			$wins = array_keys( array_filter( $res, static fn( $x ) => true === $x ) );
			$tag  = "раунд $r: ";

			if ( 1 !== count( $wins ) ) {
				$bad[] = $tag . 'резервов ' . count( $wins ) . ' вместо 1; ' . json_encode( $res );
				continue;
			}

			$holder = $wins[0] + 1;
			$row    = $this->creditA->byInstruction( $ip );

			if ( null === $row || 'reserved' !== $row['state'] || (int) $row['booking_id'] !== $holder ) {
				$bad[] = $tag . 'строка зачёта не совпадает с победителем: ' . json_encode( $row );
			}

			$other = 0 === $wins[0] ? 1 : 0;

			if ( true === $this->pool->run( array( $other => array( 'op' => 'markUsed', 'ip' => $ip, 'b' => $other + 1 ) ) )[ $other ] ) {
				$bad[] = $tag . 'markUsed не владельцем вернул true';
			}

			$row = $this->creditA->byInstruction( $ip );

			if ( null === $row || 'reserved' !== $row['state'] || (int) $row['booking_id'] !== $holder ) {
				$bad[] = $tag . 'markUsed не владельцем изменил строку: ' . json_encode( $row );
			}

			// Освобождение и повторная гонка за освободившийся зачёт.
			$this->pool->run( array( $wins[0] => array( 'op' => 'releaseReserved', 'b' => $holder ) ) );
			$cmds = array();

			for ( $i = 0; $i < $n; $i++ ) {
				$cmds[ $i ] = array( 'op' => 'reserve', 'ip' => $ip, 'b' => $i + 100, 'hold' => CC_NOW + 900 );
			}

			$res2 = $this->pool->run( $cmds );
			$w2   = array_keys( array_filter( $res2, static fn( $x ) => true === $x ) );

			if ( 1 !== count( $w2 ) ) {
				$bad[] = $tag . 'после release резервов ' . count( $w2 ) . ' вместо 1; ' . json_encode( $res2 );
				continue;
			}

			$h2  = $w2[0] + 100;
			$row = $this->creditA->byInstruction( $ip );

			if ( null === $row || (int) $row['booking_id'] !== $h2 ) {
				$bad[] = $tag . 'после release владелец не совпал с победителем: ' . json_encode( $row );
			}

			$loser = 0 === $w2[0] ? 1 : 0;

			if ( true === $this->pool->run( array( $loser => array( 'op' => 'markUsed', 'ip' => $ip, 'b' => $loser + 100 ) ) )[ $loser ] ) {
				$bad[] = $tag . 'markUsed проигравшей брони вернул true';
			}

			if ( true !== $this->pool->run( array( $w2[0] => array( 'op' => 'markUsed', 'ip' => $ip, 'b' => $h2 ) ) )[ $w2[0] ] ) {
				$bad[] = $tag . 'markUsed держателя вернул не true';
			}
		}

		return array( $bad, "$rounds раундов x $n процессов (+ гонка после release)" );
	}

	private function e(): array {
		$bad   = array();
		$n     = $this->iterations;
		$base  = CC_NOW + 40 * CC_DAY;
		$units = cc_units( $base, 600, 660 );
		$full  = count( $units );
		$aWon  = 0;
		$bWon  = 0;
		$in    = $this->inList( $units );

		for ( $it = 0; $it < $n; $it++ ) {
			$this->v->query( "DELETE FROM {$this->p}units WHERE unit_utc IN ($in)" );
			// A держит резерв, срок которого уже прошёл (по времени NOW), но строки ещё не вычищены.
			$this->slotsA->claimUnits( 1, $units, CC_NOW - 10, CC_NOW - 100 );

			$cmds   = array(
				0 => array( 'op' => 'confirm', 'b' => 1, 'units' => $units, 'now' => CC_NOW ),
				1 => array( 'op' => 'claim', 'b' => 2, 'units' => $units, 'exp' => CC_NOW + 900, 'now' => CC_NOW ),
			);
			$jitter = array(
				0 => random_int( 0, 1 ) ? 0 : random_int( 0, 3000 ),
				1 => random_int( 0, 1 ) ? 0 : random_int( 0, 3000 ),
			);
			$res    = $this->pool->run( $cmds, $jitter, 0.05 );
			$rows   = $this->v->get_results( "SELECT booking_id, COUNT(*) AS c, SUM(expires_at IS NULL) AS conf FROM {$this->p}units WHERE unit_utc IN ($in) GROUP BY booking_id" );
			$own    = array();
			$conf   = array();

			foreach ( $rows as $row ) {
				$own[ (int) $row['booking_id'] ]  = (int) $row['c'];
				$conf[ (int) $row['booking_id'] ] = (int) $row['conf'];
			}

			$a   = $own[1] ?? 0;
			$b   = $own[2] ?? 0;
			$tag = "итерация $it (jitter " . json_encode( $jitter ) . ', результаты ' . json_encode( $res ) . '): ';

			if ( ( $conf[1] ?? 0 ) === $full && $b > 0 ) {
				$bad[] = $tag . 'A подтверждена целиком И B держит те же единицы (' . json_encode( $own ) . ')';
			}

			if ( $a + $b !== $full ) {
				$bad[] = $tag . "строк у A=$a, у B=$b, всего должно быть $full";
			}

			if ( true === $res[0] && ( $a !== $full || ( $conf[1] ?? 0 ) !== $full ) ) {
				$bad[] = $tag . 'confirm вернул true, но у A нет всех подтверждённых единиц';
			}

			if ( true !== $res[0] && $a > 0 ) {
				$bad[] = $tag . 'confirm вернул не true, но у A остались единицы: ' . $a;
			}

			if ( true === $res[1] && $b !== $full ) {
				$bad[] = $tag . 'claim B вернул true без полного набора единиц';
			}

			if ( true !== $res[1] && $b > 0 ) {
				$bad[] = $tag . 'claim B вернул не true, но у B остались единицы: ' . $b;
			}

			if ( ( true === $res[0] ) === ( true === $res[1] ) ) {
				$bad[] = $tag . 'ни один или оба считают время своим (ожидался ровно один)';
			}

			true === $res[0] ? ++$aWon : ++$bWon;

			if ( count( $bad ) >= 5 ) {
				$bad[] = 'остановлено после 5 нарушений';
				break;
			}
		}

		return array( $bad, "$n итераций, победила A $aWon раз, B $bWon раз" );
	}

	private function f(): array {
		$bad    = array();
		$rounds = 24;

		for ( $r = 0; $r < $rounds; $r++ ) {
			$base   = CC_NOW + ( 60 + $r ) * CC_DAY;
			$overlap = 1 === $r % 2;
			$s1     = cc_units( $base, 600, 660 );
			$s2     = cc_units( $base, 900, 960 );
			$target = $overlap ? cc_units( $base, 630, 690 ) : cc_units( $base, 1200, 1260 );

			$this->seed( 1, $s1, null );
			$this->seed( 2, $s2, null );

			$res  = $this->pool->run(
				array(
					0 => array( 'op' => 'transfer', 'b' => 1, 'units' => $target, 'exp' => null, 'now' => CC_NOW ),
					1 => array( 'op' => 'transfer', 'b' => 2, 'units' => $target, 'exp' => null, 'now' => CC_NOW ),
				)
			);
			$tag  = "раунд $r" . ( $overlap ? ' (цель пересекает прежний слот брони 1)' : '' ) . ': ';
			$wins = array_keys( array_filter( $res, static fn( $x ) => true === $x ) );

			if ( 1 !== count( $wins ) ) {
				$bad[] = $tag . 'успешных переносов ' . count( $wins ) . ' вместо 1; ' . json_encode( $res );
				continue;
			}

			$win   = $wins[0] + 1;
			$lose  = 3 - $win;
			$orig  = array( 1 => $s1, 2 => $s2 );
			$day   = 'unit_utc >= ' . $base . ' AND unit_utc < ' . ( $base + CC_DAY );
			$mine  = array_map( 'intval', $this->v->get_col( "SELECT unit_utc FROM {$this->p}units WHERE booking_id = $win AND $day ORDER BY unit_utc" ) );
			$left  = array_map( 'intval', $this->v->get_col( "SELECT unit_utc FROM {$this->p}units WHERE booking_id = $lose AND $day ORDER BY unit_utc" ) );

			if ( $mine !== $target ) {
				$bad[] = $tag . "у победителя $win не ровно целевые единицы: " . count( $mine );
			}

			if ( $left !== $orig[ $lose ] ) {
				$bad[] = $tag . "проигравший $lose потерял или изменил свои единицы: осталось " . count( $left ) . ' из ' . count( $orig[ $lose ] );
			}

			if ( ! $overlap && count( $mine ) + count( $left ) !== 24 ) {
				$bad[] = $tag . 'суммарно единиц ' . ( count( $mine ) + count( $left ) );
			}

			if ( count( array_intersect( $mine, $left ) ) > 0 ) {
				$bad[] = $tag . 'единицы у двух броней одновременно';
			}
		}

		return array( $bad, "$rounds раундов x 2 процесса (чётные: свободная цель, нечётные: цель пересекает старый слот)" );
	}

	private function g(): array {
		$bad = array();
		$u1  = CC_NOW + 90 * CC_DAY;
		$u2  = $u1 + CC_STEP;

		$this->seed( 1, array( $u1, $u2 ), null );

		// 1. Настоящий deadlock: два процесса берут строки в противоположном порядке.
		$this->pool->run( array( 0 => array( 'op' => 'tx_begin' ), 1 => array( 'op' => 'tx_begin' ) ), array(), 0.0 );
		$this->pool->run( array( 0 => array( 'op' => 'tx_lock', 'unit' => $u1 ), 1 => array( 'op' => 'tx_lock', 'unit' => $u2 ) ), array(), 0.0 );
		$this->pool->send( 0, array( 'op' => 'tx_lock', 'unit' => $u2, 'at' => 0 ) );
		usleep( 400000 );
		$this->pool->send( 1, array( 'op' => 'tx_lock', 'unit' => $u1, 'at' => 0 ) );
		$r0 = $this->pool->recv( 0 );
		$r1 = $this->pool->recv( 1 );
		$this->pool->run( array( 0 => array( 'op' => 'tx_end' ), 1 => array( 'op' => 'tx_end' ) ), array(), 0.0 );

		$errs = array();

		foreach ( array( $r0, $r1 ) as $r ) {
			if ( isset( $r['r']['ok'] ) && false === $r['r']['ok'] ) {
				$errs[] = $r['r'];
			}
		}

		if ( 1 !== count( $errs ) ) {
			$bad[] = 'deadlock: ошибок ' . count( $errs ) . ' вместо 1 (' . json_encode( array( $r0, $r1 ) ) . ')';
		} else {
			if ( 1213 !== $errs[0]['code'] ) {
				$bad[] = 'deadlock: код ошибки PDO ' . $errs[0]['code'] . ' вместо 1213 (' . $errs[0]['msg'] . ')';
			}

			if ( true !== $errs[0]['retryable'] ) {
				$bad[] = 'SlotRepository::isRetryable не узнал сообщение deadlock: ' . $errs[0]['msg'];
			}
		}

		// 2. Lock wait timeout (1205) распознаётся как повторяемая ошибка.
		$this->pool->run( array( 0 => array( 'op' => 'tx_begin' ) ), array(), 0.0 );
		$this->pool->run( array( 0 => array( 'op' => 'tx_lock', 'unit' => $u1 ) ), array(), 0.0 );
		$this->pool->run( array( 1 => array( 'op' => 'set_timeout', 'sec' => 1 ) ), array(), 0.0 );
		$this->pool->run( array( 1 => array( 'op' => 'tx_begin' ) ), array(), 0.0 );
		$rt = $this->pool->run( array( 1 => array( 'op' => 'tx_lock', 'unit' => $u1 ) ), array(), 0.0 )[1];
		$this->pool->run( array( 0 => array( 'op' => 'tx_end' ), 1 => array( 'op' => 'tx_end' ) ), array(), 0.0 );
		$this->pool->run( array( 1 => array( 'op' => 'set_timeout', 'sec' => 20 ) ), array(), 0.0 );

		if ( ! is_array( $rt ) || true === ( $rt['ok'] ?? true ) ) {
			$bad[] = 'lock wait: ожидалась ошибка таймаута, получено ' . json_encode( $rt );
		} elseif ( 1205 !== $rt['code'] || true !== $rt['retryable'] ) {
			$bad[] = 'lock wait: код ' . $rt['code'] . ', retryable=' . json_encode( $rt['retryable'] ) . ' (' . $rt['msg'] . ')';
		}

		// 3. Путь повтора в репозитории: первый INSERT падает с deadlock, второй проходит.
		$base  = CC_NOW + 95 * CC_DAY;
		$units = cc_units( $base, 600, 660 );
		$shim  = new CcDb( $this->dsn(), $this->user(), $this->pass() );
		$repo  = new SlotRepository( $shim, $this->p . 'units', $this->p . 'seats' );
		$shim->failTimes = 1;

		if ( ! $repo->claimUnits( 7, $units, CC_NOW + 900, CC_NOW ) ) {
			$bad[] = 'повтор: claimUnits не повторил INSERT после deadlock';
		}

		if ( 1 !== $shim->failedQueryCount ) {
			$bad[] = 'повтор: ожидался 1 принудительный сбой, было ' . $shim->failedQueryCount;
		}

		if ( ( $this->owners( $units )[7] ?? 0 ) !== count( $units ) ) {
			$bad[] = 'повтор: после успешного повтора единицы не все';
		}

		// 4. Постоянный deadlock: после MAX_RETRIES+1 попыток false и ни одной строки.
		$base2           = CC_NOW + 96 * CC_DAY;
		$units2          = cc_units( $base2, 600, 660 );
		$shim->failTimes = 50;

		if ( $repo->claimUnits( 8, $units2, CC_NOW + 900, CC_NOW ) ) {
			$bad[] = 'повтор: при постоянном deadlock claimUnits вернул true';
		}

		if ( ( $this->owners( $units2 )[8] ?? 0 ) !== 0 ) {
			$bad[] = 'повтор: при постоянном deadlock остались строки';
		}

		$tries = 50 - $shim->failTimes;

		if ( SlotRepository::MAX_RETRIES + 1 !== $tries ) {
			$bad[] = "повтор: попыток $tries вместо " . ( SlotRepository::MAX_RETRIES + 1 );
		}

		// 5. Сообщения реальных ошибок distinct: дубликат ключа не считается повторяемым.
		$dup = new CcDb( $this->dsn(), $this->user(), $this->pass() );
		$dup->query( "INSERT INTO {$this->p}units (unit_utc, booking_id, expires_at) VALUES ($u1, 5, NULL)" );

		if ( SlotRepository::isRetryable( $dup->last_error ) || ! SlotRepository::isDuplicate( $dup->last_error ) ) {
			$bad[] = 'дубликат ключа распознан неверно: ' . $dup->last_error;
		}

		return array( $bad, 'реальный deadlock 1213, lock wait 1205, повтор через шим' );
	}

	/** @var array<string,string> */
	public array $conn = array();

	private function dsn(): string {
		return $this->conn['dsn'];
	}

	private function user(): string {
		return $this->conn['user'];
	}

	private function pass(): string {
		return $this->conn['pass'];
	}
}

// ---------------------------------------------------------------------
// Точка входа.

/** @param array<string,mixed> $opt */
function cc_opt( array $opt, string $key, string $env, string $default = '' ): string {
	if ( isset( $opt[ $key ] ) && is_string( $opt[ $key ] ) ) {
		return $opt[ $key ];
	}

	$e = getenv( $env );

	return false !== $e && '' !== $e ? $e : $default;
}

function cc_main(): int {
	$opt = getopt( '', array( 'dsn:', 'user:', 'pass:', 'prefix:', 'worker:', 'only:', 'iterations:', 'workers:' ) );
	$o   = array(
		'dsn'    => cc_opt( $opt, 'dsn', 'BGDVZH_TEST_MYSQL_DSN' ),
		'user'   => cc_opt( $opt, 'user', 'BGDVZH_TEST_MYSQL_USER', 'root' ),
		'pass'   => cc_opt( $opt, 'pass', 'BGDVZH_TEST_MYSQL_PASS' ),
		'prefix' => cc_opt( $opt, 'prefix', 'BGDVZH_TEST_MYSQL_PREFIX', 'bgdvzh_cc_' ),
	);

	if ( '' === $o['dsn'] ) {
		fwrite( STDERR, "Нужен --dsn=mysql:host=...;dbname=...;charset=utf8mb4 --user=... --pass=...\n" );

		return 2;
	}

	if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,40}$/', $o['prefix'] ) || false === stripos( $o['prefix'], 'cc' ) ) {
		fwrite( STDERR, "Префикс должен состоять из [A-Za-z0-9_] и содержать «cc» (защита от удаления рабочих таблиц).\n" );

		return 2;
	}

	if ( isset( $opt['worker'] ) ) {
		cc_worker( $o );

		return 0;
	}

	$iterations = max( 1, (int) cc_opt( $opt, 'iterations', 'BGDVZH_TEST_ITERATIONS', '200' ) );
	$workers    = max( 2, (int) cc_opt( $opt, 'workers', 'BGDVZH_TEST_WORKERS', '8' ) );
	$only       = array_filter( explode( ',', strtolower( cc_opt( $opt, 'only', 'BGDVZH_TEST_ONLY' ) ) ) );

	try {
		$a = new CcDb( $o['dsn'], $o['user'], $o['pass'] );
		$b = new CcDb( $o['dsn'], $o['user'], $o['pass'] );
		$v = new CcDb( $o['dsn'], $o['user'], $o['pass'] );
	} catch ( \PDOException $e ) {
		fwrite( STDERR, 'Нет соединения с БД: ' . $e->getMessage() . "\n" );

		return 2;
	}

	$p    = $o['prefix'];
	$pool = null;
	$fail = 0;

	try {
		cc_drop( $v, $p );

		foreach ( cc_ddl( $p ) as $sql ) {
			$v->pdo->exec( $sql );
		}

		$engine = (string) $v->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}units'" );

		if ( 'InnoDB' !== $engine ) {
			fwrite( STDERR, "Движок таблиц $engine, нужен InnoDB\n" );

			return 2;
		}

		$pool = new CcPool( $o, $workers );
		$sc   = new CcScenarios(
			$v,
			new SlotRepository( $a, $p . 'units', $p . 'seats' ),
			new SlotRepository( $b, $p . 'units', $p . 'seats' ),
			new CreditRepository( $a, $p . 'credits' ),
			$pool,
			$p,
			$iterations
		);
		$sc->conn = $o;

		foreach ( $sc->all() as list( $key, $title, $fn ) ) {
			if ( ! empty( $only ) && ! in_array( $key, $only, true ) ) {
				continue;
			}

			$t0 = microtime( true );

			try {
				list( $bad, $info ) = $fn();
			} catch ( \Throwable $e ) {
				$bad  = array( 'исключение: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getLine() );
				$info = '';
			}

			$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			if ( empty( $bad ) ) {
				echo "PASS $key $title ($info, {$ms} мс)\n";
			} else {
				++$fail;
				echo "FAIL $key $title ($info, {$ms} мс)\n";

				foreach ( $bad as $line ) {
					echo "  - $line\n";
				}
			}
		}
	} finally {
		if ( null !== $pool ) {
			$pool->close();
		}

		cc_drop( $v, $p );
	}

	echo 0 === $fail ? "ИТОГО: все сценарии PASS\n" : "ИТОГО: FAIL сценариев: $fail\n";

	return 0 === $fail ? 0 : 1;
}

exit( cc_main() );
