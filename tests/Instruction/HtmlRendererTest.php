<?php
/**
 * Тесты HtmlRenderer: экранирование, ссылки, таблицы, документ для PDF.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Instruction;

use Bginfo\PmzhDvzh\Instruction\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class HtmlRendererTest extends TestCase {

	/** @return array<string,mixed> */
	private static function doc(): array {
		return array(
			'title'             => 'Инструкция <script>alert(1)</script>',
			'status_title'      => 'Нужна проверка',
			'calc_date'         => '2026-10-08',
			'filing_date'       => '2026-10-20',
			'rules_version'     => 'r1',
			'templates_version' => 't1',
			'sections'          => array(
				array(
					'id'     => 'summary" onload="x',
					'title'  => '1. <img src=x onerror=alert(1)>',
					'blocks' => array(
						array( 'type' => 'p', 'text' => "Привет <script>alert('x')</script>\nвторая строка" ),
						array( 'type' => 'note', 'text' => 'Важно & нужно' ),
						array( 'type' => 'list', 'ordered' => true, 'items' => array( 'один <b>', 'два' ) ),
						array( 'type' => 'list', 'ordered' => false, 'items' => array( 'пункт' ) ),
						array( 'type' => 'table', 'head' => array( 'Колонка' ), 'rows' => array( array( '<i>ячейка</i>' ), array( 5 ) ) ),
						array(
							'type'  => 'links',
							'items' => array(
								array( 'title' => 'Закон', 'url' => 'https://lex.bg/?a=1&b=2', 'note' => 'проверено' ),
								array( 'title' => 'Плохая', 'url' => 'javascript:alert(1)', 'note' => '' ),
							),
						),
						array( 'type' => 'unknown', 'text' => 'не выводится' ),
					),
				),
			),
		);
	}

	public function test_text_is_escaped(): void {
		$html = ( new HtmlRenderer() )->render( self::doc() );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&amp; нужно', $html );
		$this->assertStringContainsString( '<br>', $html, 'перевод строки' );
		$this->assertStringNotContainsString( 'не выводится', $html );
	}

	public function test_section_id_is_sanitized(): void {
		$html = ( new HtmlRenderer() )->render( self::doc() );

		$this->assertStringNotContainsString( 'onload', str_replace( 'summaryonloadx', '', $html ) );
		$this->assertMatchesRegularExpression( '/id="bgdvzh-sec-[a-z0-9_-]+"/i', $html );
	}

	public function test_links_rendered_with_safe_attributes_and_bad_scheme_dropped(): void {
		$html = ( new HtmlRenderer() )->render( self::doc() );

		$this->assertStringContainsString( 'href="https://lex.bg/?a=1&amp;b=2"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( 'Плохая', $html, 'название остаётся текстом' );
	}

	public function test_structure_and_classes(): void {
		$html = ( new HtmlRenderer() )->render( self::doc() );

		$this->assertStringContainsString( '<h3 class="bgdvzh-h">', $html );
		$this->assertStringContainsString( '<div class="bgdvzh-note">', $html );
		$this->assertStringContainsString( '<ol class="bgdvzh-list">', $html );
		$this->assertStringContainsString( '<ul class="bgdvzh-list">', $html );
		$this->assertStringContainsString( '<table class="bgdvzh-table"><thead><tr><th>Колонка</th></tr></thead>', $html );
		$this->assertStringContainsString( '<td>5</td>', $html );
		$this->assertStringContainsString( 'class="bgdvzh-instruction"', $html );
	}

	public function test_document_is_standalone_with_print_css(): void {
		$html = ( new HtmlRenderer() )->document( self::doc() );

		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '<meta charset="utf-8">', $html );
		$this->assertStringContainsString( 'DejaVu Sans', $html );
		$this->assertStringContainsString( 'font-size: 10pt', $html );
		$this->assertStringContainsString( 'border-collapse: collapse', $html );
		$this->assertStringContainsString( 'page-break-inside: avoid', $html );
		$this->assertStringContainsString( 'overflow-wrap: anywhere', $html );
		$this->assertStringContainsString( '08.10.2026', $html );
		$this->assertStringContainsString( 'Версия правил: r1', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_empty_document_does_not_fail(): void {
		$this->assertStringContainsString( 'bgdvzh-instruction', ( new HtmlRenderer() )->render( array() ) );
		$this->assertStringContainsString( '</html>', ( new HtmlRenderer() )->document( array() ) );
	}
}
