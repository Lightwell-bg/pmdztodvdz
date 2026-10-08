<?php
/**
 * Готовность продажи консультаций и цены консультации из EDD.
 *
 * reason() возвращает причину недоступности (null — всё готово). Цена
 * позиции берётся только из EDD (вариативные цены), в браузер от клиента
 * приходит лишь формат консультации.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

use Bginfo\PmzhDvzh\Schedule\ScheduleConfig;
use Bginfo\PmzhDvzh\Storage\Schema;
use Bginfo\PmzhDvzh\Support\ConsultSettings;
use Bginfo\PmzhDvzh\Support\Settings;

final class ConsultGate {

	public function __construct( private Settings $settings ) {
	}

	/**
	 * Настройки консультаций с дефолтами. Без собственного кэша: админка
	 * сохраняет настройки и сразу читает их в том же запросе.
	 *
	 * @return array<string,mixed>
	 */
	public function config(): array {
		return $this->settings->consultation();
	}

	public function scheduleConfig(): ScheduleConfig {
		return new ScheduleConfig( $this->config() );
	}

	public function downloadId(): int {
		return max( 0, (int) ( $this->config()['download_id'] ?? 0 ) );
	}

	public function isVariable(): bool {
		$id = $this->downloadId();

		return $id > 0 && function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $id );
	}

	/** @return list<string> Включённые форматы в порядке настроек. */
	public function enabledFormats(): array {
		$out = array();

		foreach ( ConsultSettings::FORMATS as $f ) {
			if ( ! empty( $this->config()['formats'][ $f ]['enabled'] ) ) {
				$out[] = $f;
			}
		}

		return $out;
	}

	/**
	 * Причина, по которой консультация выключена; null — работает.
	 */
	public function reason(): ?string {
		$c = $this->config();

		if ( ! Schema::ready() ) {
			return 'База данных плагина ещё не обновлена. Откройте любую страницу админки, чтобы обновить её.';
		}

		if ( empty( $c['enabled'] ) ) {
			return 'Консультации выключены в настройках.';
		}

		if ( ! EddGate::eddAvailable() ) {
			return 'Для продажи консультаций нужен Easy Digital Downloads ' . EddGate::MIN_VERSION . ' или новее.';
		}

		$id = $this->downloadId();

		if ( $id <= 0 ) {
			return 'Не выбран товар EDD для консультации.';
		}

		if ( $id === (int) $this->settings->get( 'download_id', 0 ) ) {
			return 'Для консультации нужен отдельный товар EDD, не тот же, что для инструкции.';
		}

		$post = function_exists( 'get_post' ) ? get_post( $id ) : null;

		if ( ! $post instanceof \WP_Post || 'download' !== $post->post_type ) {
			return 'Выбранный товар консультации не найден. Выберите товар заново.';
		}

		if ( ! EddGate::purchasable( $id ) ) {
			return 'Товар консультации не опубликован или недоступен для покупки (черновик не годится).';
		}

		if ( $this->isVariable() ) {
			$prices = $this->variablePrices();
			$pid    = $c['price_id'] ?? null;

			if ( null === $pid || ! array_key_exists( (int) $pid, $prices ) ) {
				return 'У товара консультации несколько вариантов цены, но базовый вариант не выбран или не существует.';
			}
		}

		$errors = ConsultSettings::errors( $c );

		if ( ! empty( $errors ) ) {
			return 'Расписание или форматы настроены неверно: ' . $errors[0];
		}

		return null;
	}

	public function ok(): bool {
		return null === $this->reason();
	}

	/**
	 * price_id товара для формата: всегда базовый (одна цена для всех форматов).
	 * null — товар с одной ценой.
	 */
	public function priceIdFor( string $format ): ?int {
		if ( ! $this->isVariable() ) {
			return null;
		}

		// Одна цена для всех форматов: format_price_ids игнорируется.
		$c    = $this->config();
		$base = $c['price_id'] ?? null;

		return null === $base ? null : (int) $base;
	}

	/**
	 * Цена консультации формата из EDD (строка с двумя знаками) либо null.
	 * Для режима «цены включают налог» это цена с налогом, иначе без.
	 */
	public function priceFor( string $format ): ?string {
		$id = $this->downloadId();

		if ( $id <= 0 ) {
			return null;
		}

		if ( $this->isVariable() ) {
			$pid    = $this->priceIdFor( $format );
			$prices = $this->variablePrices();
			$amount = null !== $pid && isset( $prices[ $pid ]['amount'] ) ? $prices[ $pid ]['amount'] : null;
		} else {
			$amount = function_exists( 'edd_get_download_price' ) ? edd_get_download_price( $id ) : null;
		}

		if ( null === $amount || ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return null;
		}

		return number_format( (float) $amount, 2, '.', '' );
	}

	public function currency(): string {
		return function_exists( 'edd_get_currency' ) ? (string) edd_get_currency() : '';
	}

	/** 'incl' — цены включают налог, 'excl' — нет. */
	public function taxMode(): string {
		return function_exists( 'edd_prices_include_tax' ) && edd_prices_include_tax() ? 'incl' : 'excl';
	}

	/** Нужна пометка «плюс налог, если применяется». */
	public function needsTaxNote(): bool {
		return function_exists( 'edd_use_taxes' ) && edd_use_taxes() && 'excl' === $this->taxMode();
	}

	/** Сумма в формате EDD (с валютой), безопасная для вывода. */
	public function display( string $amount ): string {
		if ( ! function_exists( 'edd_currency_filter' ) || ! function_exists( 'edd_format_amount' ) ) {
			return $amount;
		}

		return wp_kses( (string) edd_currency_filter( edd_format_amount( (float) $amount ) ), array() );
	}

	/** @return array<int|string,array<string,mixed>> */
	private function variablePrices(): array {
		$prices = function_exists( 'edd_get_variable_prices' ) ? edd_get_variable_prices( $this->downloadId() ) : array();

		return is_array( $prices ) ? $prices : array();
	}
}
