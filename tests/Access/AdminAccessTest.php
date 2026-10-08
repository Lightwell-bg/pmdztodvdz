<?php
/**
 * Бесплатный доступ администратора: право, фильтр и решение по результату.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Access;

use Bginfo\PmzhDvzh\Access\AdminAccess;
use Bginfo\PmzhDvzh\Access\ResultAccess;
use PHPUnit\Framework\TestCase;

final class AdminAccessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bgdvzh_test_user']    = 0;
		$GLOBALS['bgdvzh_test_caps']    = array();
		$GLOBALS['bgdvzh_test_filters'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['bgdvzh_test_user'], $GLOBALS['bgdvzh_test_caps'], $GLOBALS['bgdvzh_test_filters'] );
	}

	public function test_guest_is_denied_even_if_capability_stub_says_yes(): void {
		$GLOBALS['bgdvzh_test_user'] = 0;
		$GLOBALS['bgdvzh_test_caps'] = array( 'manage_options' );

		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertFalse( AdminAccess::can() );
	}

	public function test_admin_with_default_capability(): void {
		$GLOBALS['bgdvzh_test_user'] = 5;
		$GLOBALS['bgdvzh_test_caps'] = array( 'manage_options' );

		$this->assertTrue( AdminAccess::can() );
	}

	public function test_user_without_capability_is_denied(): void {
		$GLOBALS['bgdvzh_test_user'] = 7;
		$GLOBALS['bgdvzh_test_caps'] = array( 'read' );

		$this->assertFalse( AdminAccess::can() );
	}

	public function test_filter_changes_capability(): void {
		$GLOBALS['bgdvzh_test_user']    = 7;
		$GLOBALS['bgdvzh_test_caps']    = array( 'edit_pages' );
		$GLOBALS['bgdvzh_test_filters'] = array( AdminAccess::FILTER => 'edit_pages' );

		$this->assertTrue( AdminAccess::can() );

		$GLOBALS['bgdvzh_test_caps'] = array( 'manage_options' );
		$this->assertFalse( AdminAccess::can(), 'manage_options больше не нужна' );
	}

	public function test_invalid_filter_value_falls_back_to_default(): void {
		$GLOBALS['bgdvzh_test_user']    = 7;
		$GLOBALS['bgdvzh_test_caps']    = array( 'manage_options' );
		$GLOBALS['bgdvzh_test_filters'] = array( AdminAccess::FILTER => '' );

		$this->assertTrue( AdminAccess::can() );
	}

	public function test_result_decision_matrix(): void {
		$this->assertSame( 'admin', ResultAccess::decide( true, false, true, 'none' ) );
		$this->assertSame( 'admin', ResultAccess::decide( true, false, true, 'refunded' ), 'нет действующей оплаты — доступ администратора' );
		$this->assertSame( 'paid', ResultAccess::decide( true, false, true, 'paid' ), 'оплата главнее' );
		$this->assertSame( 'none', ResultAccess::decide( true, false, false, 'none' ), 'не администратор' );
		$this->assertSame( 'none', ResultAccess::decide( false, true, true, 'none' ), 'grant-only никогда не получает admin' );
		$this->assertSame( 'paid', ResultAccess::decide( false, true, true, 'paid' ) );
		$this->assertSame( 'none', ResultAccess::decide( false, false, true, 'none' ), 'не владелец' );
	}
}
