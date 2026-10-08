<?php
/**
 * Предпросмотр не содержит платной инструкции, а результат пригоден для
 * хранения в JSON и проверки content_hash.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Instruction;

use Bginfo\PmzhDvzh\Instruction\Composer;
use Bginfo\PmzhDvzh\Instruction\DefaultTemplates;
use Bginfo\PmzhDvzh\Instruction\HtmlRenderer;
use Bginfo\PmzhDvzh\Instruction\TemplateStore;
use Bginfo\PmzhDvzh\Questionnaire\Validator;
use Bginfo\PmzhDvzh\Rules\Evaluator;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Json;
use Bginfo\PmzhDvzh\Tests\Rules\AnswersFactory;
use PHPUnit\Framework\TestCase;

final class PreviewIsolationTest extends TestCase {

	/** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:array<string,mixed>} */
	private function pipeline(): array {
		$norm     = Validator::normalize( AnswersFactory::base(), AnswersFactory::TODAY );
		$this->assertSame( array(), $norm['errors'] );

		$result   = ( new Evaluator() )->evaluate( $norm['answers'], AnswersFactory::config() );
		$composer = new Composer( DefaultTemplates::get(), array( 'fee_text' => '40 EUR', 'fee_verified_at' => '2026-10-08' ) );

		return array(
			$norm['answers'],
			$result,
			$composer->preview( $result ),
			$composer->instruction( $norm['answers'], $result, 'default-1' ),
		);
	}

	public function test_preview_has_no_instruction_content(): void {
		list( $answers, , $preview, $instruction ) = $this->pipeline();

		$json = Json::encode( $preview );

		$this->assertArrayNotHasKey( 'sections', $preview );
		$this->assertArrayNotHasKey( 'instruction', $preview );

		foreach ( $instruction['sections'] as $section ) {
			$this->assertStringNotContainsString( $section['title'], $json, 'заголовок раздела платной инструкции в предпросмотре' );
		}

		$this->assertStringNotContainsString( '4. Персональный перечень документов', $json );
		$this->assertStringNotContainsString( $answers['applicant']['citizenship'], $json, 'ответы не должны попадать в предпросмотр' );
		$this->assertStringNotContainsString( 'Проверяемый пятилетний период', $json );
	}

	public function test_pipeline_result_is_json_safe_and_hash_verifies(): void {
		list( $answers, $result, $preview, $instruction ) = $this->pipeline();

		$a = Json::encode( $answers );
		$r = Json::encode( $result );
		$p = Json::encode( $preview );
		$i = Json::encode( $instruction );

		$this->assertStringNotContainsString( 'timezone_type', $r, 'в результате не должно быть объектов DateTime' );
		$this->assertStringNotContainsString( 'timezone_type', $i );

		$hash = SnapshotRepository::contentHash( $a, $r, $p, $i );
		$this->assertSame( 64, strlen( $hash ) );

		$row = array(
			'answers'      => $a,
			'result'       => $r,
			'preview'      => $p,
			'instruction'  => $i,
			'content_hash' => $hash,
			'purged_at'    => null,
		);
		$this->assertTrue( SnapshotRepository::hashMatches( $row ) );

		$tampered                = $row;
		$tampered['instruction'] = str_replace( 'Персональная', 'Подделанная', $i );
		$this->assertFalse( SnapshotRepository::hashMatches( $tampered ), 'подмена инструкции' );

		$purged              = $row;
		$purged['purged_at'] = '2026-10-08 10:00:00';
		$purged['answers']   = null;
		$this->assertFalse( SnapshotRepository::hashMatches( $purged ), 'очищенный снимок' );
	}

	public function test_instruction_renders_to_escaped_html_and_document(): void {
		list( , , , $instruction ) = $this->pipeline();

		$renderer = new HtmlRenderer();
		$html     = $renderer->render( $instruction );
		$doc      = $renderer->document( $instruction );

		$this->assertStringContainsString( 'Персональный перечень документов', $html );
		$this->assertStringContainsString( 'bgdvzh-sec-summary', $html );
		$this->assertStringContainsString( 'DejaVu Sans', $doc );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_template_merge_replaces_lists_and_merges_maps(): void {
		$defaults = array(
			'a'    => array( 'x' => '1', 'y' => '2' ),
			'list' => array( 'one', 'two', 'three' ),
			'z'    => 'keep',
		);
		$stored   = array(
			'a'    => array( 'x' => 'changed' ),
			'list' => array( 'only' ),
		);

		$merged = TemplateStore::merge( $defaults, $stored );

		$this->assertSame( array( 'x' => 'changed', 'y' => '2' ), $merged['a'] );
		$this->assertSame( array( 'only' ), $merged['list'] );
		$this->assertSame( 'keep', $merged['z'] );
	}
}
