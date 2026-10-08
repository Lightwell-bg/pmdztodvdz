<?php
/**
 * Чистое решение, какой «платёжный» режим показать при просмотре результата.
 *
 * 'admin' (бесплатный доступ администратора) возможен только владельцу снимка
 * по сессии/пользователю; посетитель, открывший результат лишь по grant,
 * его не получает. Если есть действующий платный доступ, он главнее.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Access;

final class ResultAccess {

	public const ADMIN = 'admin';

	/**
	 * @param bool   $owner        Владелец снимка по сессии/пользователю.
	 * @param bool   $grantOnly    Доступ только по grant-cookie.
	 * @param bool   $admin        AdminAccess::can().
	 * @param string $paymentState Лучшее состояние оплаты ('paid', 'none', ...).
	 * @return string 'paid' | 'admin' | исходное состояние оплаты
	 */
	public static function decide( bool $owner, bool $grantOnly, bool $admin, string $paymentState ): string {
		if ( AccessPolicy::PAID === $paymentState ) {
			return AccessPolicy::PAID;
		}

		if ( $admin && $owner && ! $grantOnly ) {
			return self::ADMIN;
		}

		return $paymentState;
	}
}
