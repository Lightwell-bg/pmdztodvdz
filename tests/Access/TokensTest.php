<?php
/**
 * Тесты Tokens: CSRF, проверка происхождения запроса, подпись ссылки корзины.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Access;

use Bginfo\PmzhDvzh\Access\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokensTest extends TestCase {

	private const HOME = 'https://example.com';

	public function test_random_tokens_have_expected_shape(): void {
		$this->assertSame( 64, strlen( Tokens::random() ) );
		$this->assertTrue( Tokens::isWellFormed( Tokens::random() ) );
		$this->assertSame( 32, strlen( Tokens::publicId() ) );
		$this->assertNotSame( Tokens::random(), Tokens::random() );
		$this->assertFalse( Tokens::isWellFormed( str_repeat( 'g', 64 ) ) );
	}

	public function test_csrf_valid_only_for_same_secret_action_and_scope(): void {
		$secret = str_repeat( 'a', 64 );
		$token  = Tokens::csrf( $secret, 'checkout', 'abc' );

		$this->assertTrue( Tokens::csrfValid( $secret, 'checkout', 'abc', $token ) );
		$this->assertFalse( Tokens::csrfValid( $secret, 'checkout', 'abd', $token ), 'другая область' );
		$this->assertFalse( Tokens::csrfValid( $secret, 'retry', 'abc', $token ), 'другое действие' );
		$this->assertFalse( Tokens::csrfValid( str_repeat( 'b', 64 ), 'checkout', 'abc', $token ), 'другая сессия' );
		$this->assertFalse( Tokens::csrfValid( $secret, 'checkout', 'abc', '' ), 'пустой токен' );
		$this->assertFalse( Tokens::csrfValid( '', 'checkout', 'abc', $token ), 'пустой секрет' );
		$this->assertFalse( Tokens::csrfValid( $secret, 'checkout', 'abc', 'zz' ), 'мусор' );
	}

	/** @return array<string,array{0:?string,1:?string,2:bool}> */
	public static function originProvider(): array {
		return array(
			'свой Origin'             => array( 'https://example.com', null, true ),
			'свой Referer'            => array( null, 'https://example.com/page/?a=1', true ),
			'чужой Origin'            => array( 'https://evil.example', null, false ),
			'чужой Origin, свой Ref'  => array( 'https://evil.example', 'https://example.com/', false ),
			'другая схема'            => array( 'http://example.com', null, false ),
			'другой порт'             => array( 'https://example.com:8443', null, false ),
			'поддомен'                => array( 'https://example.com.evil.example', null, false ),
			'оба отсутствуют'         => array( null, null, false ),
			'пустые'                  => array( '', '', false ),
			'Origin null'             => array( 'null', null, false ),
			'Origin null, свой Ref'   => array( 'null', 'https://example.com/', true ),
			'мусор'                   => array( 'not a url', null, false ),
		);
	}

	#[DataProvider( 'originProvider' )]
	public function test_same_origin( ?string $origin, ?string $referer, bool $expected ): void {
		$this->assertSame( $expected, Tokens::sameOrigin( $origin, $referer, self::HOME ) );
	}

	public function test_cart_ref_roundtrip_and_tamper(): void {
		$pub = Tokens::publicId();
		$ref = Tokens::cartRef( $pub, 7, 'key' );

		$this->assertTrue( Tokens::cartRefValid( $ref, 7, 'key' ) );
		$this->assertFalse( Tokens::cartRefValid( $ref, 8, 'key' ), 'чужая сессия снимка' );
		$this->assertFalse( Tokens::cartRefValid( $ref, 7, 'other' ), 'другой ключ' );

		$other = Tokens::publicId();
		$forged = $other . '.' . explode( '.', $ref )[1];
		$this->assertFalse( Tokens::cartRefValid( $forged, 7, 'key' ), 'подмена public_id' );

		$mac    = explode( '.', $ref )[1];
		$broken = $pub . '.' . strrev( $mac );
		$this->assertFalse( Tokens::cartRefValid( $broken, 7, 'key' ), 'испорченная подпись' );

		$this->assertNull( Tokens::parseCartRef( 'garbage' ) );
		$this->assertFalse( Tokens::cartRefValid( '', 7, 'key' ) );
		$this->assertSame( array( $pub, $mac ), Tokens::parseCartRef( $ref ) );
	}
}
