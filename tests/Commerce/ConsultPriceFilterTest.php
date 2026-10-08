<?php
/**
 * Тесты фильтров цены и названия позиции консультации (зачёт из БД, не из корзины).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Commerce;

use Bginfo\PmzhDvzh\Access\Tokens;
use Bginfo\PmzhDvzh\Commerce\ConsultGate;
use Bginfo\PmzhDvzh\Commerce\ConsultPriceFilter;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Settings;
use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class ConsultPriceFilterTest extends BookingTestCase {

	private const DOWNLOAD = 77;
	private ConsultPriceFilter $filter;
	/** @var array<string,mixed> */
	private array $snapshot;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['bgdvzh_test_options'] = array( Settings::OPTION => array( 'consult' => array( 'download_id' => self::DOWNLOAD ) ) );

		$this->db->insert( 'wp_bgdvzh_snapshots', array( 'public_id' => str_repeat( 'a', 32 ), 'session_id' => 10 ) );
		$snaps          = new SnapshotRepository();
		$this->snapshot = (array) $snaps->findByPublicId( str_repeat( 'a', 32 ) );
		$this->filter   = new ConsultPriceFilter( new ConsultGate( new Settings() ), $this->bookings, $this->credits, $snaps );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['bgdvzh_test_options'] );
		parent::tearDown();
	}

	/** @return array<string,mixed> */
	private function options( array $booking, ?string $snapshotPublic = null, int $session = 10 ): array {
		return array(
			'price_id'                           => 2,
			'bgdvzh_ref'                         => Tokens::cartRef( $snapshotPublic ?? (string) $this->snapshot['public_id'], $session, wp_salt( 'auth' ) ),
			ConsultPriceFilter::OPTION_BOOKING   => (string) $booking['public_id'],
		);
	}

	private function creditBooking(): array {
		return $this->holdOk( array( 'credit' => array( 'instruction_purchase_id' => 5, 'amount' => '9.99' ) ) );
	}

	public function test_price_is_reduced_by_reserved_credit_from_db(): void {
		$b = $this->creditBooking();

		$this->assertSame( '39.01', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b ) ) );
		$this->assertSame( '39.01', $this->filter->price( 49.0, self::DOWNLOAD, $this->options( $b ) ), 'число тоже' );
	}

	public function test_client_options_cannot_change_credit(): void {
		$b      = $this->creditBooking();
		$opts   = $this->options( $b ) + array( 'bgdvzh_credit' => '40.00', 'credit' => '40.00' );

		$this->assertSame( '39.01', $this->filter->price( '49.00', self::DOWNLOAD, $opts ) );
	}

	public function test_other_products_and_missing_context_are_untouched(): void {
		$b = $this->creditBooking();

		$this->assertSame( '49.00', $this->filter->price( '49.00', 999, $this->options( $b ) ), 'другой товар' );
		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, array( 'price_id' => 2 ) ), 'нет ссылки на запись' );
		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, array() ) );
		$this->assertFalse( $this->filter->price( false, self::DOWNLOAD, $this->options( $b ) ), 'цена не определена — не трогаем' );
		$this->assertSame( '0', $this->filter->price( '0', self::DOWNLOAD, $this->options( $b ) ) );
	}

	public function test_forged_or_foreign_ref_is_ignored(): void {
		$b = $this->creditBooking();

		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b, null, 11 ) ), 'подпись не сходится' );
		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b, str_repeat( 'b', 32 ) ) ), 'снимок не существует' );

		$this->db->insert( 'wp_bgdvzh_snapshots', array( 'public_id' => str_repeat( 'c', 32 ), 'session_id' => 10 ) );
		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b, str_repeat( 'c', 32 ) ) ), 'запись другого снимка' );
	}

	public function test_no_credit_unless_reserved_for_this_booking(): void {
		$b = $this->creditBooking();

		$this->service->onPaid( $b, $this->now + 10 );
		$this->filter->reset();
		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b ) ), 'запись уже не held' );
	}

	public function test_released_credit_is_not_applied(): void {
		$b = $this->creditBooking();
		$this->credits->releaseReserved( (int) $b['id'], '2026-06-10 07:00:00' );
		$this->filter->reset();

		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b ) ) );
	}

	public function test_booking_without_credit_is_untouched(): void {
		$b = $this->holdOk();

		$this->assertSame( '49.00', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b ) ) );
	}

	public function test_price_never_drops_below_one_cent(): void {
		$b = $this->holdOk( array( 'credit' => array( 'instruction_purchase_id' => 5, 'amount' => '60.00' ) ) );

		$this->assertSame( '0.01', $this->filter->price( '49.00', self::DOWNLOAD, $this->options( $b ) ) );
	}

	public function test_cart_item_name_mentions_credit(): void {
		$b = $this->creditBooking();

		$name = $this->filter->name( 'Консультация', self::DOWNLOAD, array( 'id' => self::DOWNLOAD, 'options' => $this->options( $b ) ) );
		$this->assertSame( 'Консультация (с зачётом инструкции 9.99)', $name );

		$this->assertSame( 'Консультация', $this->filter->name( 'Консультация', self::DOWNLOAD, array( 'options' => array() ) ) );
		$this->assertSame( 'Другое', $this->filter->name( 'Другое', 999, array( 'options' => $this->options( $b ) ) ) );
	}
}
