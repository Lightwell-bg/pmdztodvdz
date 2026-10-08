<?php
/**
 * Тесты пятиминутной сетки занятости.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Schedule;

use Bginfo\PmzhDvzh\Schedule\Grid;
use PHPUnit\Framework\TestCase;

final class GridTest extends TestCase {

	public function test_units_cover_touched_cells(): void {
		$this->assertSame( array( 0 ), Grid::units( 0, 300 ) );
		$this->assertSame( array( 0, 300, 600 ), Grid::units( 100, 700 ) );
		$this->assertSame( array(), Grid::units( 300, 300 ) );
		$this->assertSame( array(), Grid::units( 600, 300 ) );
	}

	public function test_floor_and_ceil(): void {
		$this->assertSame( 600, Grid::floor( 899 ) );
		$this->assertSame( 900, Grid::ceil( 899 ) );
		$this->assertSame( 900, Grid::ceil( 900 ) );
		$this->assertSame( 900, Grid::floor( 900 ) );
	}

	public function test_block_with_break_has_expected_unit_count(): void {
		// 45 минут встречи + 15 минут перерыва = 12 единиц.
		$start = 1_780_000_200; // кратно 300.
		$this->assertSame( 0, $start % 300 );
		$this->assertCount( 12, Grid::units( $start, $start + 3600 ) );
	}

	public function test_intersecting_intervals_always_share_a_unit(): void {
		// Даже не кратные 5 минутам границы дают общую единицу.
		$a = Grid::units( 1000, 1000 + 2700 + 900 );
		$b = Grid::units( 1000 + 3599, 1000 + 3599 + 3600 );

		$this->assertNotEmpty( array_intersect( $a, $b ) );
	}

	public function test_is_aligned(): void {
		$this->assertTrue( Grid::isAligned( 0 ) );
		$this->assertTrue( Grid::isAligned( 45 ) );
		$this->assertFalse( Grid::isAligned( 47 ) );
		$this->assertFalse( Grid::isAligned( -5 ) );
	}
}
