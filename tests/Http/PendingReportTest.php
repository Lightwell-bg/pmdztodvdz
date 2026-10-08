<?php
/**
 * Тесты правила показа состояния «ожидаем оплату».
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Http;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Http\RestController;
use PHPUnit\Framework\TestCase;

final class PendingReportTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function at( int $secondsAgo ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW - $secondsAgo );
	}

	public function test_recent_pending_order_is_reported(): void {
		$this->assertTrue( RestController::isPendingReportable( AccessPolicy::PENDING, 'pending', $this->at( 60 ), self::NOW ) );
		$this->assertTrue( RestController::isPendingReportable( AccessPolicy::PENDING, 'pending', $this->at( 1800 ), self::NOW ) );
	}

	public function test_old_pending_order_is_not_reported(): void {
		$this->assertFalse( RestController::isPendingReportable( AccessPolicy::PENDING, 'pending', $this->at( 1801 ), self::NOW ) );
		$this->assertFalse( RestController::isPendingReportable( AccessPolicy::PENDING, 'pending', '', self::NOW ) );
	}

	public function test_processing_is_always_reported(): void {
		$this->assertTrue( RestController::isPendingReportable( AccessPolicy::PENDING, 'processing', $this->at( 86400 * 3 ), self::NOW ) );
	}

	public function test_other_states_are_always_reported(): void {
		foreach ( array( AccessPolicy::PAID, AccessPolicy::DISPUTED, AccessPolicy::REFUNDED, AccessPolicy::REVOKED, AccessPolicy::FAILED ) as $state ) {
			$this->assertTrue( RestController::isPendingReportable( $state, 'complete', $this->at( 86400 * 30 ), self::NOW ) );
		}
	}
}
