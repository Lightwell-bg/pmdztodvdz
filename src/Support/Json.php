<?php
/**
 * Единое кодирование JSON для хранилища и хеша снимка (без WordPress).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Support;

final class Json {

	private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

	/**
	 * @param mixed $value
	 * @throws \RuntimeException Если значение не кодируется.
	 */
	public static function encode( $value ): string {
		$json = json_encode( $value, self::FLAGS );

		if ( false === $json ) {
			throw new \RuntimeException( 'JSON encode failed' );
		}

		return $json;
	}

	/**
	 * @return array<string|int,mixed> Пустой массив при пустом/битом JSON.
	 */
	public static function decode( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}
}
