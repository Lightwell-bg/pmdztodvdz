<?php
/**
 * Тесты проверки контактов и полей записи.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Booking;

use Bginfo\PmzhDvzh\Booking\ContactValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactValidatorTest extends TestCase {

	/** @return array<string,array{0:string,1:string,2:string,3:?string}> формат, ввод, email, ожидаемый контакт (null — ошибка) */
	public static function contacts(): array {
		return array(
			'telegram username'        => array( 'telegram', '@abcde', 'a@example.com', '@abcde' ),
			'telegram без @'           => array( 'telegram', 'abc_def1', 'a@example.com', '@abc_def1' ),
			'telegram слишком короткий' => array( 'telegram', '@abcd', 'a@example.com', null ),
			'telegram слишком длинный' => array( 'telegram', '@' . str_repeat( 'a', 33 ), 'a@example.com', null ),
			'telegram дефис'           => array( 'telegram', '@abc-def', 'a@example.com', null ),
			'telegram телефон'         => array( 'telegram', '+359 88 123 4567', 'a@example.com', '+359881234567' ),
			'whatsapp e164'            => array( 'whatsapp', '+359881234567', 'a@example.com', '+359881234567' ),
			'whatsapp с пробелами'     => array( 'whatsapp', '+7 (916) 123-45-67', 'a@example.com', '+79161234567' ),
			'whatsapp без плюса'       => array( 'whatsapp', '359881234567', 'a@example.com', null ),
			'whatsapp слишком коротко' => array( 'whatsapp', '+1234567', 'a@example.com', null ),
			'phone 15 цифр'            => array( 'phone', '+123456789012345', 'a@example.com', '+123456789012345' ),
			'phone 16 цифр'            => array( 'phone', '+1234567890123456', 'a@example.com', null ),
			'phone буквы'              => array( 'phone', '+3598812abcd', 'a@example.com', null ),
			'meet из email'            => array( 'meet', '', 'a@example.com', 'a@example.com' ),
			'meet свой email'          => array( 'meet', 'b@example.com', 'a@example.com', 'b@example.com' ),
			'meet не email'            => array( 'meet', 'not-email', 'a@example.com', null ),
			'email'                    => array( 'email', '', 'a@example.com', 'a@example.com' ),
			'email пусто'              => array( 'email', '', '', null ),
			'неизвестный формат'       => array( 'fax', '+359881234567', 'a@example.com', null ),
		);
	}

	#[DataProvider( 'contacts' )]
	public function test_contact( string $format, string $input, string $email, ?string $expected ): void {
		$r = ContactValidator::contact( $format, $input, $email );

		if ( null === $expected ) {
			$this->assertFalse( $r['ok'] );
			$this->assertNotSame( '', $r['error'] );
		} else {
			$this->assertTrue( $r['ok'], $r['error'] );
			$this->assertSame( $expected, $r['contact'] );
		}
	}

	public function test_control_characters_are_stripped_from_contact(): void {
		$r = ContactValidator::contact( 'telegram', "@abcde\r\nBcc: x@evil.test", 'a@example.com' );

		$this->assertFalse( $r['ok'] );
	}

	public function test_question_limit_and_cleanup(): void {
		$this->assertTrue( ContactValidator::question( str_repeat( 'я', 2000 ) )['ok'] );
		$this->assertFalse( ContactValidator::question( str_repeat( 'я', 2001 ) )['ok'] );

		$r = ContactValidator::question( "  Привет\r\nмир\x00\x07  " );
		$this->assertSame( "Привет\nмир", $r['question'] );
	}

	public function test_visitor_timezone_must_be_a_real_identifier(): void {
		$this->assertTrue( ContactValidator::visitorTz( 'Europe/Sofia' ) );
		$this->assertTrue( ContactValidator::visitorTz( 'America/New_York' ) );
		$this->assertFalse( ContactValidator::visitorTz( '' ) );
		$this->assertFalse( ContactValidator::visitorTz( 'Mars/Base' ) );
		$this->assertFalse( ContactValidator::visitorTz( "Europe/Sofia\n" ) );
	}
}
