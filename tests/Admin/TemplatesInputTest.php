<?php
/**
 * Тесты разбора формы «Шаблоны».
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Admin;

use Bginfo\PmzhDvzh\Admin\TemplatesInput;
use Bginfo\PmzhDvzh\Instruction\DefaultTemplates;
use PHPUnit\Framework\TestCase;

final class TemplatesInputTest extends TestCase {

	public function test_empty_post_keeps_defaults(): void {
		$d = DefaultTemplates::get();

		$this->assertSame( $d, TemplatesInput::fromPost( array(), $d ) );
	}

	public function test_known_fields_are_updated_and_tags_stripped(): void {
		$d   = DefaultTemplates::get();
		$out = TemplatesInput::fromPost(
			array(
				'status_titles' => array( 'ok' => 'Новый <b>заголовок</b>' ),
				'disclaimer'    => "Строка 1\r\nСтрока 2",
				'findings'      => array( 'RES_OK' => array( 'short' => '<script>alert(1)</script>Текст' ) ),
			),
			$d
		);

		$this->assertSame( 'Новый заголовок', $out['status_titles']['ok'] );
		$this->assertSame( "Строка 1\nСтрока 2", $out['disclaimer'] );
		$this->assertStringNotContainsString( '<', $out['findings']['RES_OK']['short'] );
		$this->assertStringContainsString( 'Текст', $out['findings']['RES_OK']['short'] );
		$this->assertSame( $d['status_titles']['check'], $out['status_titles']['check'] );
	}

	public function test_unknown_keys_are_ignored(): void {
		$d   = DefaultTemplates::get();
		$out = TemplatesInput::fromPost(
			array(
				'evil'          => 'x',
				'status_titles' => array( 'hacked' => 'x' ),
				'findings'      => array( 'NO_SUCH_CODE' => array( 'short' => 'x' ) ),
			),
			$d
		);

		$this->assertSame( $d, $out );
	}

	public function test_offer_items_one_per_line(): void {
		$out = TemplatesInput::fromPost( array( 'offer_items' => "Один\n\n  Два  \nТри" ), DefaultTemplates::get() );

		$this->assertSame( array( 'Один', 'Два', 'Три' ), $out['offer']['items'] );
	}

	public function test_offer_items_limited(): void {
		$text = implode( "\n", array_map( static fn( int $i ): string => 'п' . $i, range( 1, 50 ) ) );
		$out  = TemplatesInput::fromPost( array( 'offer_items' => $text ), DefaultTemplates::get() );

		$this->assertCount( TemplatesInput::MAX_ITEMS, $out['offer']['items'] );
	}

	public function test_length_is_limited(): void {
		$out = TemplatesInput::fromPost( array( 'disclaimer' => str_repeat( 'я', 10000 ) ), DefaultTemplates::get() );

		$this->assertSame( TemplatesInput::MAX_LEN, mb_strlen( $out['disclaimer'] ) );
	}

	public function test_every_default_code_has_an_area(): void {
		foreach ( array_keys( DefaultTemplates::get()['findings'] ) as $code ) {
			$this->assertNotSame( 'Прочее', TemplatesInput::area( (string) $code ), $code );
		}
	}
}
