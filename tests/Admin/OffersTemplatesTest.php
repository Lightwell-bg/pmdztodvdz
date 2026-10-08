<?php
/**
 * Тесты шаблона `offers`: тексты по умолчанию, правка через форму, версионирование (merge).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Admin;

use Bginfo\PmzhDvzh\Admin\TemplatesInput;
use Bginfo\PmzhDvzh\Instruction\DefaultTemplates;
use Bginfo\PmzhDvzh\Instruction\TemplateStore;
use PHPUnit\Framework\TestCase;

final class OffersTemplatesTest extends TestCase {

	public function test_default_texts_are_exact(): void {
		$o = DefaultTemplates::get()['offers'];

		$this->assertSame( 'Персональная инструкция', $o['instruction_title'] );
		$this->assertSame( 'Порядок действий, список документов и вопросы для уточнения по вашей анкете. Доступ на странице и скачивание PDF', $o['instruction_text'] );
		$this->assertSame( 'Получить мою инструкцию', $o['instruction_button'] );
		$this->assertSame( 'Индивидуальная консультация', $o['consult_title'] );
		$this->assertSame( 'Предварительно изучим вашу анкету, разберём вопросы и определим следующие действия. Персональная инструкция и PDF включены', $o['consult_text'] );
		$this->assertSame( 'Выбрать способ и время', $o['consult_button'] );
		$this->assertSame( 'Нужен индивидуальный разбор? Стоимость уже купленной инструкции зачтём при оплате консультации', $o['upsell_after_instruction'] );
		$this->assertSame( 'Обсудим причины и план подготовки. Консультация не гарантирует готовность к подаче или положительное решение', $o['consult_obstacle_note'] );
		$this->assertSame( 'Рекомендуем при вашей ситуации', $o['recommended_label'] );
		$this->assertCount( 9, $o );
	}

	public function test_form_updates_known_keys_and_strips_markup(): void {
		$d   = DefaultTemplates::get();
		$out = TemplatesInput::fromPost(
			array(
				'offers' => array(
					'consult_title' => 'Новая <b>консультация</b>',
					'consult_text'  => '<script>alert(1)</script>Текст',
					'unknown_key'   => 'взлом',
				),
			),
			$d
		);

		$this->assertSame( 'Новая консультация', $out['offers']['consult_title'] );
		$this->assertStringNotContainsString( '<', $out['offers']['consult_text'] );
		$this->assertArrayNotHasKey( 'unknown_key', $out['offers'] );
		$this->assertSame( $d['offers']['consult_button'], $out['offers']['consult_button'] );
	}

	public function test_empty_field_keeps_previous_text(): void {
		$d   = DefaultTemplates::get();
		$out = TemplatesInput::fromPost( array( 'offers' => array( 'consult_button' => '   ' ) ), $d );

		$this->assertSame( $d['offers']['consult_button'], $out['offers']['consult_button'] );
	}

	public function test_stored_set_without_offers_still_gets_defaults(): void {
		$stored = DefaultTemplates::get();
		unset( $stored['offers'] );

		$merged = TemplateStore::merge( DefaultTemplates::get(), $stored );

		$this->assertSame( DefaultTemplates::get()['offers'], $merged['offers'] );
	}

	public function test_stored_partial_offers_override_only_given_keys(): void {
		$merged = TemplateStore::merge( DefaultTemplates::get(), array( 'offers' => array( 'consult_title' => 'Другое' ) ) );

		$this->assertSame( 'Другое', $merged['offers']['consult_title'] );
		$this->assertSame( 'Выбрать способ и время', $merged['offers']['consult_button'] );
	}
}
