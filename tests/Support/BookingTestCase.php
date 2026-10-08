<?php
/**
 * Основа тестов записей: настоящие репозитории на SQLite (PdoWpdb) и
 * BookingService с реальным расписанием. Без pdo_sqlite тесты пропускаются.
 *
 * Расписание: каждый день 10:00-13:00 (Europe/Sofia), встреча 45 минут +
 * перерыв 15, шаг 15, уведомление 24 ч. «Сейчас» — среда 10.06.2026 09:00
 * по Софии (06:00 UTC); слоты берутся на четверг 11.06.2026 (летнее время, UTC+3).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Support;

use Bginfo\PmzhDvzh\Booking\BookingService;
use Bginfo\PmzhDvzh\Booking\Notifier;
use Bginfo\PmzhDvzh\Schedule\ScheduleConfig;
use Bginfo\PmzhDvzh\Storage\BookingRepository;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\SlotRepository;
use Bginfo\PmzhDvzh\Support\ConsultSettings;
use PHPUnit\Framework\TestCase;

abstract class BookingTestCase extends TestCase {

	protected PdoWpdb $db;
	protected BookingRepository $bookings;
	protected SlotRepository $slots;
	protected CreditRepository $credits;
	protected Notifier $notifier;
	protected BookingService $service;
	/** @var array<string,mixed> */
	protected array $cfg;
	protected int $now;

	protected function setUp(): void {
		if ( ! PdoWpdb::available() ) {
			$this->markTestSkipped( 'Нужно расширение pdo_sqlite (php -d extension=pdo_sqlite).' );
		}

		$this->now = gmmktime( 6, 0, 0, 6, 10, 2026 );
		$this->db  = new PdoWpdb();
		SqliteSchema::create( $this->db );
		$GLOBALS['wpdb'] = $this->db;

		$this->bookings = new BookingRepository( $this->db );
		$this->slots    = new SlotRepository( $this->db );
		$this->credits  = new CreditRepository( $this->db );
		$this->notifier = new Notifier( $this->bookings, static fn(): string => 'admin@example.com', $this->db );
		$this->cfg      = ConsultSettings::merge(
			array(
				'weekly'           => array_fill( 1, 7, array( '10:00-13:00' ) ),
				'min_notice_hours' => 24,
				'horizon_days'     => 7,
				'written_per_day'  => 2,
			)
		);
		$this->service  = new BookingService( $this->bookings, $this->slots, $this->credits, $this->notifier, new ScheduleConfig( $this->cfg ), $this->cfg );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/** Начало слота четверга 11.06.2026 в местное H:M (UTC+3). */
	protected function start( int $hour, int $minute = 0 ): int {
		return gmmktime( $hour - 3, $minute, 0, 6, 11, 2026 );
	}

	/**
	 * @param array<string,mixed> $over
	 * @return array<string,mixed>
	 */
	protected function req( array $over = array() ): array {
		return array_merge(
			array(
				'format'      => 'telegram',
				'mode'        => 'text',
				'start_utc'   => $this->start( 10 ),
				'contact'     => '@tester',
				'email'       => 'guest@example.com',
				'question'    => 'Вопрос',
				'visitor_tz'  => 'Europe/Sofia',
				'snapshot_id' => 1,
				'session_id'  => 10,
				'user_id'     => 0,
				'price'       => array( 'base' => '49.00', 'item' => '49.00', 'currency' => 'EUR', 'tax_mode' => 'incl', 'price_id' => 1 ),
				'credit'      => null,
			),
			$over
		);
	}

	/** @return array{ok:bool,error?:string,booking?:array<string,mixed>} */
	protected function hold( array $over = array(), ?int $now = null ): array {
		return $this->service->hold( $this->req( $over ), $now ?? $this->now );
	}

	/** @return array<string,mixed> */
	protected function holdOk( array $over = array(), ?int $now = null ): array {
		$r = $this->hold( $over, $now );
		$this->assertTrue( $r['ok'], 'hold: ' . ( $r['error'] ?? '' ) );

		return $r['booking'];
	}

	/** @return array<string,mixed> */
	protected function fresh( int $id ): array {
		$b = $this->bookings->get( $id );
		$this->assertNotNull( $b );

		return $b;
	}

	/** Оплаченная (confirmed) запись. */
	protected function paid( array $over = array() ): array {
		$b = $this->holdOk( $over );
		$this->service->onPaid( $b, $this->now + 60 );

		return $this->fresh( (int) $b['id'] );
	}

	protected function notes( int $bookingId, string $type ): int {
		return $this->notifier->count( $bookingId, $type );
	}

	protected function unitCount( int $bookingId, bool $confirmedOnly = false ): int {
		return $this->slots->countUnits( $bookingId, $confirmedOnly );
	}

	/** @return array<string,mixed>|null */
	protected function creditRow( int $instructionPurchaseId ): ?array {
		return $this->credits->byInstruction( $instructionPurchaseId );
	}
}
