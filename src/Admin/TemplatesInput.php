<?php
/**
 * Разбор и очистка формы «Шаблоны»: только простой текст, только известные
 * ключи (из DefaultTemplates). Чистая функция: без WP и без вывода.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

final class TemplatesInput {

	public const MAX_LEN   = 4000;
	public const MAX_ITEMS = 20;

	/** Поля шаблона для каждого кода замечания. */
	public const FINDING_FIELDS = array( 'short', 'detail', 'action', 'question' );

	/**
	 * @param array<string,mixed> $post     Данные формы (уже без слэшей).
	 * @param array<string,mixed> $defaults Набор по умолчанию: определяет допустимые ключи.
	 * @return array<string,mixed> Полный набор шаблонов.
	 */
	public static function fromPost( array $post, array $defaults ): array {
		$out = $defaults;

		foreach ( array( 'status_titles', 'status_intro' ) as $group ) {
			foreach ( array_keys( (array) ( $defaults[ $group ] ?? array() ) ) as $key ) {
				if ( isset( $post[ $group ][ $key ] ) ) {
					$out[ $group ][ $key ] = self::clean( $post[ $group ][ $key ] );
				}
			}
		}

		foreach ( array_keys( (array) ( $defaults['offer'] ?? array() ) ) as $key ) {
			if ( 'items' === $key ) {
				continue;
			}

			if ( isset( $post['offer'][ $key ] ) ) {
				$out['offer'][ $key ] = self::clean( $post['offer'][ $key ] );
			}
		}

		// Тексты карточек выбора: только известные ключи, простой текст.
		foreach ( array_keys( (array) ( $defaults['offers'] ?? array() ) ) as $key ) {
			// Пустое поле оставляет прежний текст: карточка не должна остаться без текста.
			if ( isset( $post['offers'][ $key ] ) && '' !== self::clean( $post['offers'][ $key ] ) ) {
				$out['offers'][ $key ] = self::clean( $post['offers'][ $key ] );
			}
		}

		if ( isset( $post['offer_items'] ) ) {
			$out['offer']['items'] = self::lines( $post['offer_items'] );
		}

		foreach ( array( 'disclaimer', 'method_note' ) as $key ) {
			if ( isset( $post[ $key ] ) ) {
				$out[ $key ] = self::clean( $post[ $key ] );
			}
		}

		foreach ( (array) ( $defaults['findings'] ?? array() ) as $code => $_fields ) {
			foreach ( self::FINDING_FIELDS as $field ) {
				if ( isset( $post['findings'][ $code ][ $field ] ) ) {
					$out['findings'][ $code ][ $field ] = self::clean( $post['findings'][ $code ][ $field ] );
				}
			}
		}

		return $out;
	}

	/**
	 * Группа замечания для заголовка в форме: по префиксу кода.
	 */
	public static function area( string $code ): string {
		$prefix = strtok( $code, '_' );

		return match ( $prefix ) {
			'FILING', 'APP', 'PMZH' => 'Заявитель и ПМЖ',
			'RES'                   => 'История проживания',
			'TRIP'                  => 'Поездки и отсутствия',
			'SPEC'                  => 'Особые основания',
			'HOUSING'               => 'Жильё',
			'HEALTH'                => 'Медицинское страхование',
			'FUNDS'                 => 'Средства',
			'DOC'                   => 'Документы и обстоятельства',
			default                 => 'Прочее',
		};
	}

	/** @param mixed $v */
	public static function clean( $v ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $s ) : trim( strip_tags( $s ) );

		return mb_substr( $s, 0, self::MAX_LEN );
	}

	/**
	 * Многострочный текст → список непустых строк.
	 *
	 * @param mixed $v
	 * @return list<string>
	 */
	public static function lines( $v ): array {
		$out = array();

		foreach ( explode( "\n", self::clean( $v ) ) as $line ) {
			$line = trim( $line );

			if ( '' !== $line && count( $out ) < self::MAX_ITEMS ) {
				$out[] = $line;
			}
		}

		return $out;
	}
}
