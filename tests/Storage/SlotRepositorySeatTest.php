<?php
/**
 * Тесты мест письменных разборов: подтверждение места и «существующее место».
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Storage;

use Bginfo\PmzhDvzh\Tests\Support\BookingTestCase;

final class SlotRepositorySeatTest extends BookingTestCase {

	public function test_confirm_seat_fails_when_expired_seat_was_given_away(): void {
		$date = '2026-06-11';

		$this->assertSame( 1, $this->slots->claimSeat( 101, $date, 1, $this->now + 900, $this->now ) );

		// Резерв 101 истёк, место заняла бронь 102.
		$later = $this->now + 1000;
		$this->assertSame( 1, $this->slots->claimSeat( 102, $date, 1, null, $later ) );

		$this->assertFalse( $this->slots->confirmSeat( 101, $date, 1, $later ) );
		$this->assertSame( 1, $this->slots->seatsUsed( $date, $later ) );
	}

	public function test_claim_seat_ignores_expired_own_row_and_confirm_succeeds_when_free(): void {
		$date = '2026-06-11';

		$this->slots->claimSeat( 101, $date, 1, $this->now + 900, $this->now );

		$later = $this->now + 1000;
		$this->assertSame( 1, $this->slots->claimSeat( 101, $date, 1, null, $later ), 'просроченная строка пересоздаётся подтверждённой' );
		$this->assertTrue( $this->slots->confirmSeat( 101, $date, 1, $later ) );
		$this->assertSame( 1, $this->slots->seatsUsed( $date, $later + 100000 ), 'подтверждённое место не истекает' );
	}
}
