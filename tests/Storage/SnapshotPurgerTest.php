<?php
/**
 * Решение об автоматической очистке снимка по сроку хранения.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Storage;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Storage\SnapshotPurger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnapshotPurgerTest extends TestCase {

	/** @return array<string,mixed> */
	private static function row( string $state, ?string $paidAt = null ): array {
		return array( 'id' => 1, 'state' => $state, 'paid_at' => $paidAt );
	}

	public function test_snapshot_without_purchases_can_be_purged(): void {
		$this->assertTrue( SnapshotPurger::canAutoPurge( array(), array() ) );
	}

	public function test_failed_and_missing_purchases_can_be_purged(): void {
		$this->assertTrue( SnapshotPurger::canAutoPurge( array( self::row( 'failed' ) ), array( AccessPolicy::FAILED ) ) );
		$this->assertTrue( SnapshotPurger::canAutoPurge( array( self::row( 'missing' ) ), array( AccessPolicy::MISSING ) ) );
	}

	public function test_purchase_that_was_ever_paid_is_never_purged(): void {
		$this->assertFalse( SnapshotPurger::canAutoPurge( array( self::row( 'failed', '2026-01-01 00:00:00' ) ), array( AccessPolicy::FAILED ) ) );
	}

	/** @return array<string,array{0:string}> */
	public static function protectedStates(): array {
		return array(
			'paid'     => array( 'paid' ),
			'pending'  => array( 'pending' ),
			'disputed' => array( 'disputed' ),
			'refunded' => array( 'refunded' ),
			'revoked'  => array( 'revoked' ),
		);
	}

	#[DataProvider( 'protectedStates' )]
	public function test_protected_cached_states_block_purge(string $state): void {
		$this->assertFalse( SnapshotPurger::canAutoPurge( array( self::row( $state ) ), array( AccessPolicy::FAILED ) ) );
	}

	public function test_unavailable_or_other_live_state_blocks_purge(): void {
		foreach ( array( AccessPolicy::UNAVAILABLE, AccessPolicy::PAID, AccessPolicy::PENDING, AccessPolicy::MISMATCH, AccessPolicy::REFUNDED, '' ) as $live ) {
			$this->assertFalse( SnapshotPurger::canAutoPurge( array( self::row( 'failed' ) ), array( $live ) ), $live );
		}
	}

	public function test_every_purchase_must_be_purgeable(): void {
		$rows = array( self::row( 'failed' ), self::row( 'failed' ) );

		$this->assertTrue( SnapshotPurger::canAutoPurge( $rows, array( AccessPolicy::FAILED, AccessPolicy::MISSING ) ) );
		$this->assertFalse( SnapshotPurger::canAutoPurge( $rows, array( AccessPolicy::FAILED, AccessPolicy::PAID ) ) );
		$this->assertFalse( SnapshotPurger::canAutoPurge( $rows, array( AccessPolicy::FAILED ) ), 'нет живого состояния второй покупки' );
	}
}
