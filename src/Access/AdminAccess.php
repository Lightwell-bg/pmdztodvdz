<?php
/**
 * Бесплатный доступ администраторов сайта к инструкции.
 *
 * Право определяется возможностью (по умолчанию manage_options), которую
 * можно изменить фильтром `bgdvzh_free_access_capability`. Гость (user_id = 0)
 * доступа не получает ни при каких условиях.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Access;

final class AdminAccess {

	public const FILTER     = 'bgdvzh_free_access_capability';
	public const DEFAULT_CAP = 'manage_options';

	/** Есть ли у текущего пользователя бесплатный доступ ко всем инструкциям. */
	public static function can(): bool {
		if ( (int) get_current_user_id() <= 0 ) {
			return false;
		}

		$cap = apply_filters( self::FILTER, self::DEFAULT_CAP );

		if ( ! is_string( $cap ) || '' === $cap ) {
			$cap = self::DEFAULT_CAP;
		}

		return (bool) current_user_can( $cap );
	}
}
