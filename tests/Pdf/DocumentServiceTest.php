<?php
/**
 * Тесты проверки приватного каталога PDF и (при возможности) рендера PDF.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Pdf;

use Bginfo\PmzhDvzh\Instruction\HtmlRenderer;
use Bginfo\PmzhDvzh\Pdf\DocumentService;
use Bginfo\PmzhDvzh\Pdf\PdfRenderer;
use PHPUnit\Framework\TestCase;

final class DocumentServiceTest extends TestCase {

	private string $root = '';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/bgdvzh-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/wp/uploads', 0777, true );
		mkdir( $this->root . '/private', 0777, true );
		mkdir( $this->root . '/wp/private-inside', 0777, true );
	}

	protected function tearDown(): void {
		foreach ( array( $this->root . '/wp/private-inside', $this->root . '/wp/uploads', $this->root . '/wp', $this->root . '/private' ) as $dir ) {
			if ( is_dir( $dir ) ) {
				@rmdir( $dir );
			}
		}

		foreach ( glob( $this->root . '/cache/*' ) ?: array() as $f ) {
			@unlink( $f );
		}

		@rmdir( $this->root . '/cache' );
		@rmdir( $this->root );
	}

	public function test_private_dir_outside_abspath_and_uploads_is_accepted(): void {
		$real = realpath( $this->root . '/private' );

		$this->assertSame(
			$real,
			DocumentService::resolvePrivateDir( $this->root . '/private', $this->root . '/wp', $this->root . '/wp/uploads' )
		);
	}

	public function test_private_dir_inside_abspath_or_uploads_is_rejected(): void {
		$this->assertNull( DocumentService::resolvePrivateDir( $this->root . '/wp/private-inside', $this->root . '/wp', $this->root . '/wp/uploads' ) );
		$this->assertNull( DocumentService::resolvePrivateDir( $this->root . '/wp/uploads', $this->root . '/wp', $this->root . '/wp/uploads' ) );
		$this->assertNull( DocumentService::resolvePrivateDir( $this->root . '/wp', $this->root . '/wp', $this->root . '/wp/uploads' ) );
	}

	public function test_missing_or_empty_private_dir_is_rejected(): void {
		$this->assertNull( DocumentService::resolvePrivateDir( '', $this->root . '/wp', $this->root . '/wp/uploads' ) );
		$this->assertNull( DocumentService::resolvePrivateDir( $this->root . '/nope', $this->root . '/wp', $this->root . '/wp/uploads' ) );
	}

	public function test_private_dir_without_constant_is_null(): void {
		if ( defined( 'BGINFO_PMZH_DVZH_PRIVATE_DIR' ) ) {
			$this->markTestSkipped( 'Константа определена в окружении.' );
		}

		$this->assertNull( DocumentService::privateDir() );
	}

	public function test_pdf_is_rendered_with_cyrillic_text(): void {
		if ( PHP_INT_SIZE < 8 ) {
			$this->markTestSkipped( '32-битный PHP: php-font-lib выдаёт предупреждения при встраивании шрифтов.' );
		}

		$dir = dirname( __DIR__, 2 );

		if ( ! is_readable( $dir . '/vendor-prefixed/autoload.php' ) ) {
			$this->markTestSkipped( 'vendor-prefixed не собран.' );
		}

		if ( ! defined( 'BGINFO_PMZH_DVZH_DIR' ) ) {
			define( 'BGINFO_PMZH_DVZH_DIR', $dir . '/' );
		}

		$doc = array(
			'title'    => 'Проверка кириллицы',
			'sections' => array( array( 'id' => 's', 'title' => 'Раздел', 'blocks' => array( array( 'type' => 'p', 'text' => 'Текст ДВЖ' ) ) ) ),
		);
		$pdf = ( new PdfRenderer() )->render( ( new HtmlRenderer() )->document( $doc ) );

		$this->assertStringStartsWith( '%PDF', $pdf );
	}
}
