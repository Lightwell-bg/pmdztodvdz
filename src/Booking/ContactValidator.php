<?php
/**
 * Проверка и нормализация контактов и полей записи на консультацию
 * (чистые функции, без WordPress).
 *
 * Контакт по формату:
 *  - telegram: @username (5–32 символа [A-Za-z0-9_]) либо телефон в E.164;
 *  - whatsapp, phone: телефон в E.164 (+ и 8–15 цифр);
 *  - meet, email: адрес email.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

final class ContactValidator {

	public const QUESTION_MAX = 2000;

	/**
	 * @return array{ok:bool,contact:string,error:string}
	 */
	public static function contact( string $format, string $contact, string $email ): array {
		$contact = trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', $contact ) ?? '' );

		switch ( $format ) {
			case 'telegram':
				if ( 1 === preg_match( '/^@?([A-Za-z0-9_]{5,32})$/', $contact, $m ) ) {
					return array( 'ok' => true, 'contact' => '@' . $m[1], 'error' => '' );
				}

				$phone = self::phone( $contact );

				return null === $phone
					? array( 'ok' => false, 'contact' => '', 'error' => 'Укажите Telegram в виде @username (5–32 символа: латиница, цифры, подчёркивание) или телефон в формате +359…' )
					: array( 'ok' => true, 'contact' => $phone, 'error' => '' );

			case 'whatsapp':
			case 'phone':
				$phone = self::phone( $contact );

				return null === $phone
					? array( 'ok' => false, 'contact' => '', 'error' => 'Укажите телефон в международном формате: + и от 8 до 15 цифр.' )
					: array( 'ok' => true, 'contact' => $phone, 'error' => '' );

			case 'meet':
			case 'email':
				$value = '' === $contact ? trim( $email ) : $contact;

				return self::validEmail( $value )
					? array( 'ok' => true, 'contact' => $value, 'error' => '' )
					: array( 'ok' => false, 'contact' => '', 'error' => 'Укажите корректный адрес email.' );
		}

		return array( 'ok' => false, 'contact' => '', 'error' => 'Неизвестный способ связи.' );
	}

	/** Телефон в E.164 или null. */
	public static function phone( string $raw ): ?string {
		$clean = preg_replace( '/[\s\-().]/', '', $raw ) ?? '';

		return 1 === preg_match( '/^\+[0-9]{8,15}$/', $clean ) ? $clean : null;
	}

	public static function validEmail( string $email ): bool {
		return strlen( $email ) <= 190 && false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/** Вопрос: без управляющих символов (кроме переводов строки), не длиннее лимита. */
	public static function question( string $q ): array {
		$q = str_replace( "\r\n", "\n", $q );
		$q = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $q ) ?? '' );

		if ( mb_strlen( $q ) > self::QUESTION_MAX ) {
			return array( 'ok' => false, 'question' => '', 'error' => 'Вопрос слишком длинный: не более ' . self::QUESTION_MAX . ' символов.' );
		}

		return array( 'ok' => true, 'question' => $q, 'error' => '' );
	}

	public static function visitorTz( string $tz ): bool {
		return '' !== $tz && in_array( $tz, \DateTimeZone::listIdentifiers(), true );
	}
}
