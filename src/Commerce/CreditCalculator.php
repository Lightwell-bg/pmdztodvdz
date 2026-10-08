<?php
/**
 * Расчёт зачёта инструкции в консультацию (чистая функция, без WP и EDD).
 *
 * Деньги считаются в целых центах, чтобы не копить ошибку float.
 *
 *  - 'incl' (цены включают налог): item.total − Σ|refund.total|;
 *  - 'excl' (цены без налога): (item.subtotal − item.discount) − Σ(|refund.subtotal| − |refund.discount|).
 *
 * Результат не меньше 0, округление до 2 знаков.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Commerce;

final class CreditCalculator {

	/** Минимальная цена позиции после зачёта (EDD не принимает ноль и минус). */
	public const MIN_ITEM = 0.01;

	/**
	 * @param array{subtotal?:float|int|string,discount?:float|int|string,total?:float|int|string} $item
	 * @param list<array{subtotal?:float|int|string,discount?:float|int|string,total?:float|int|string}> $refunds Суммы возвратов (знак не важен).
	 * @param string $taxMode 'incl'|'excl'
	 */
	public static function amount( array $item, array $refunds, string $taxMode ): float {
		if ( 'incl' === $taxMode ) {
			$cents = self::cents( $item['total'] ?? 0 );

			foreach ( $refunds as $r ) {
				$cents -= abs( self::cents( $r['total'] ?? 0 ) );
			}
		} else {
			$cents = self::cents( $item['subtotal'] ?? 0 ) - abs( self::cents( $item['discount'] ?? 0 ) );

			foreach ( $refunds as $r ) {
				$cents -= abs( self::cents( $r['subtotal'] ?? 0 ) ) - abs( self::cents( $r['discount'] ?? 0 ) );
			}
		}

		return max( 0, $cents ) / 100;
	}

	/** Зачёт применим: 0 < зачёт < цены консультации. */
	public static function isApplicable( float $credit, float $consultPrice ): bool {
		return self::cents( $credit ) > 0 && self::cents( $credit ) < self::cents( $consultPrice );
	}

	/** Цена позиции консультации с зачётом: не меньше 0,01. */
	public static function itemPrice( float $consultPrice, float $credit ): float {
		return max( (int) round( self::MIN_ITEM * 100 ), self::cents( $consultPrice ) - self::cents( $credit ) ) / 100;
	}

	/** Строка с двумя знаками для хранения и сравнения. */
	public static function format( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/** @param float|int|string $v */
	private static function cents( $v ): int {
		// Сначала гасим двоичный шум float (999.4999999 → 999.5), затем округляем половину вверх.
		return (int) round( round( (float) $v * 100, 6 ) );
	}
}
