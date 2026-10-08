<?php
/**
 * Настройки плагина: одна опция `bgdvzh_settings` (autoload = no).
 *
 * Значения по умолчанию и очистка живут здесь, чтобы страница настроек,
 * REST и Composer читали одно и то же. Деньги и секреты здесь не хранятся.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Support;

final class Settings {

	public const OPTION = 'bgdvzh_settings';

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'download_id'           => 0,
			'price_id'              => null,
			'page_id'               => 0,
			'privacy_url'           => '',
			'retention_days_drafts' => 30,
			'retention_days_unpaid' => 90,
			'link_ttl_days'         => 30,
			'fee_text'              => '110 лв. (56,24 €) — такса по данным ИИСДА за предоставление статута',
			'fee_verified_at'       => '2026-10-08',
			'income_min_monthly'    => null,
			'income_currency'       => 'EUR',
			'income_verified_at'    => '',
			'income_guide_text'     => '',
			'delete_on_uninstall'   => false,
			'consult'               => ConsultSettings::defaults(),
		);
	}

	/**
	 * Настройки консультаций с дефолтами (ключ `consult`, см. ConsultSettings).
	 *
	 * @return array<string,mixed>
	 */
	public function consultation(): array {
		return ConsultSettings::merge( $this->all()['consult'] ?? array() );
	}

	/**
	 * Ошибки конфигурации консультаций для админки и ConsultGate.
	 *
	 * @return list<string>
	 */
	public function consultationErrors(): array {
		return ConsultSettings::errors( $this->consultation() );
	}

	/**
	 * Все настройки (сохранённые поверх значений по умолчанию).
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$this->cache = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
		}

		return $this->cache;
	}

	/**
	 * @param mixed $default Значение, если ключ неизвестен.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/** Адрес страницы с анкетой (пусто, если не выбрана или не опубликована). */
	public function pageUrl(): string {
		$id = (int) $this->get( 'page_id', 0 );

		if ( $id <= 0 || ! function_exists( 'get_permalink' ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}

		$url = get_permalink( $id );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Очищает и сохраняет настройки. Ключи, которых нет во входе, остаются
	 * прежними (кроме чекбокса delete_on_uninstall — отсутствие = false,
	 * поэтому его передают всегда).
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed> Сохранённые настройки.
	 */
	public function update( array $input ): array {
		$clean = self::sanitize( $input, $this->all() );

		update_option( self::OPTION, $clean, false );
		$this->cache = $clean;

		return $clean;
	}

	/**
	 * Очистка входных данных. Неизвестные ключи отбрасываются; ключи,
	 * не пришедшие во входе, берутся из $current (или значений по умолчанию).
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $current
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input, array $current = array() ): array {
		$base = array_merge( self::defaults(), array_intersect_key( $current, self::defaults() ) );
		$out  = $base;

		if ( array_key_exists( 'download_id', $input ) ) {
			$out['download_id'] = max( 0, (int) $input['download_id'] );
		}

		if ( array_key_exists( 'price_id', $input ) ) {
			$v                = $input['price_id'];
			$out['price_id']  = ( null === $v || '' === $v || ! is_numeric( $v ) ) ? null : max( 0, (int) $v );
		}

		if ( array_key_exists( 'page_id', $input ) ) {
			$out['page_id'] = max( 0, (int) $input['page_id'] );
		}

		if ( array_key_exists( 'privacy_url', $input ) ) {
			$out['privacy_url'] = self::url( $input['privacy_url'] );
		}

		if ( array_key_exists( 'retention_days_drafts', $input ) ) {
			$out['retention_days_drafts'] = self::clampInt( $input['retention_days_drafts'], 1, 365, 30 );
		}

		if ( array_key_exists( 'retention_days_unpaid', $input ) ) {
			$out['retention_days_unpaid'] = self::clampInt( $input['retention_days_unpaid'], 1, 730, 90 );
		}

		if ( array_key_exists( 'link_ttl_days', $input ) ) {
			$out['link_ttl_days'] = self::clampInt( $input['link_ttl_days'], 1, 365, 30 );
		}

		if ( array_key_exists( 'fee_text', $input ) ) {
			$out['fee_text'] = self::line( $input['fee_text'], 300 );
		}

		if ( array_key_exists( 'fee_verified_at', $input ) ) {
			$out['fee_verified_at'] = self::date( $input['fee_verified_at'] );
		}

		if ( array_key_exists( 'income_min_monthly', $input ) ) {
			$v = $input['income_min_monthly'];

			if ( is_string( $v ) ) {
				$v = str_replace( array( ' ', ',' ), array( '', '.' ), $v );
			}

			$out['income_min_monthly'] = ( null === $v || '' === $v || ! is_numeric( $v ) || (float) $v < 0 || (float) $v > 1000000 )
				? null
				: round( (float) $v, 2 );
		}

		if ( array_key_exists( 'income_currency', $input ) ) {
			$cur                     = strtoupper( (string) $input['income_currency'] );
			$out['income_currency']  = in_array( $cur, array( 'EUR', 'BGN' ), true ) ? $cur : 'EUR';
		}

		if ( array_key_exists( 'income_verified_at', $input ) ) {
			$out['income_verified_at'] = self::date( $input['income_verified_at'] );
		}

		if ( array_key_exists( 'income_guide_text', $input ) ) {
			$out['income_guide_text'] = self::text( $input['income_guide_text'], 1000 );
		}

		if ( array_key_exists( 'delete_on_uninstall', $input ) ) {
			$v                          = $input['delete_on_uninstall'];
			$out['delete_on_uninstall'] = true === $v || '1' === $v || 1 === $v || 'on' === $v || 'yes' === $v;
		}

		if ( array_key_exists( 'consult', $input ) && is_array( $input['consult'] ) ) {
			$out['consult'] = ConsultSettings::sanitize( $input['consult'], ConsultSettings::merge( $base['consult'] ?? array() ) );
		}

		return $out;
	}

	/** @param mixed $v */
	private static function clampInt( $v, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $v ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $v ) );
	}

	/** @param mixed $v */
	private static function line( $v, int $max ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $s ) : trim( strip_tags( $s ) );

		return mb_substr( $s, 0, $max );
	}

	/** @param mixed $v */
	private static function text( $v, int $max ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $s ) : trim( strip_tags( $s ) );

		return mb_substr( $s, 0, $max );
	}

	/** @param mixed $v */
	private static function url( $v ): string {
		$s = is_scalar( $v ) ? trim( (string) $v ) : '';

		if ( '' === $s ) {
			return '';
		}

		return function_exists( 'esc_url_raw' ) ? esc_url_raw( $s ) : $s;
	}

	/** @param mixed $v */
	private static function date( $v ): string {
		$s = is_scalar( $v ) ? trim( (string) $v ) : '';

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		return $s;
	}
}
