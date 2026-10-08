<?php
/**
 * Версионируемые шаблоны текстов. Текущий набор — последняя строка в
 * таблице templates, а если таблица пуста — DefaultTemplates (версия
 * `default-1`). Сохранение всегда создаёт новую версию `Ymd-His`;
 * купленные снимки хранят уже собранный текст, поэтому старые
 * инструкции не меняются.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Instruction;

use Bginfo\PmzhDvzh\Storage\TemplateRepository;
use Bginfo\PmzhDvzh\Support\Clock;

final class TemplateStore {

	public const DEFAULT_VERSION = 'default-1';

	public function __construct( private TemplateRepository $repo ) {
	}

	/**
	 * Текущий набор.
	 *
	 * @return array{version:string,data:array<string,mixed>}
	 */
	public function current(): array {
		$row = $this->repo->latest();

		if ( null === $row ) {
			return array( 'version' => self::DEFAULT_VERSION, 'data' => DefaultTemplates::get() );
		}

		return array(
			'version' => $row['version'],
			'data'    => self::merge( DefaultTemplates::get(), $row['data'] ),
		);
	}

	/**
	 * Тексты карточек выбора (ключ `offers` текущего набора) для публичных
	 * ответов: только строки известных ключей.
	 *
	 * @return array<string,string>
	 */
	public function offers(): array {
		$defaults = (array) ( DefaultTemplates::get()['offers'] ?? array() );
		$stored   = (array) ( $this->current()['data']['offers'] ?? array() );
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$value        = $stored[ $key ] ?? $default;
			$out[ $key ]  = is_string( $value ) && '' !== $value ? $value : (string) $default;
		}

		return $out;
	}

	/**
	 * Набор конкретной версии (null, если такой нет).
	 *
	 * @return array{version:string,data:array<string,mixed>}|null
	 */
	public function get( string $version ): ?array {
		if ( self::DEFAULT_VERSION === $version ) {
			return array( 'version' => self::DEFAULT_VERSION, 'data' => DefaultTemplates::get() );
		}

		$row = $this->repo->byVersion( $version );

		if ( null === $row ) {
			return null;
		}

		return array( 'version' => $row['version'], 'data' => self::merge( DefaultTemplates::get(), $row['data'] ) );
	}

	/**
	 * Сохраняет новую версию.
	 *
	 * @param array<string,mixed> $data Полный набор шаблонов (очистка — на стороне вызывающего).
	 * @return string|null Версия или null, если сохранить не удалось.
	 */
	public function save( array $data, int $userId ): ?string {
		$base = gmdate( 'Ymd-His', Clock::timestamp() );

		for ( $i = 0; $i < 20; $i++ ) {
			$version = 0 === $i ? $base : $base . '-' . ( $i + 1 );

			if ( $this->repo->exists( $version ) ) {
				continue;
			}

			if ( $this->repo->insert( $version, $data, $userId ) ) {
				return $version;
			}
		}

		return null;
	}

	/**
	 * Накладывает сохранённые значения на значения по умолчанию. Списки
	 * (последовательные массивы) заменяются целиком, ассоциативные
	 * массивы сливаются по ключам: так удалённый администратором пункт
	 * списка не «воскресает» из значений по умолчанию, а новый ключ
	 * шаблона по умолчанию не пропадает.
	 *
	 * @param array<string,mixed> $defaults
	 * @param array<string,mixed> $stored
	 * @return array<string,mixed>
	 */
	public static function merge( array $defaults, array $stored ): array {
		$out = $defaults;

		foreach ( $stored as $key => $value ) {
			if (
				is_array( $value )
				&& isset( $out[ $key ] )
				&& is_array( $out[ $key ] )
				&& ! self::isList( $value )
				&& ! self::isList( $out[ $key ] )
			) {
				$out[ $key ] = self::merge( $out[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/** @param array<mixed> $a */
	private static function isList( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}
}
