<?php
/**
 * Подписи и форматирование для экранов админки (чистые функции, без WP).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Admin;

final class Display {

	/** Результат предварительной оценки. */
	public static function resultLabel( string $status ): string {
		return match ( $status ) {
			'ok'       => 'Условия подтверждаются',
			'check'    => 'Нужна проверка',
			'obstacle' => 'Есть препятствие',
			default    => '—',
		};
	}

	/** Состояние оплаты (кэш purchases.state или живое состояние AccessPolicy). */
	public static function paymentLabel( string $state ): string {
		return match ( $state ) {
			'paid'     => 'Оплачено',
			'pending'  => 'Ожидает оплаты',
			'failed'   => 'Не оплачено',
			'refunded' => 'Возврат',
			'revoked'  => 'Доступ отозван',
			'disputed' => 'Спор по платежу',
			'mismatch' => 'Позиция не совпадает',
			'missing'  => 'Заказ не найден',
			''         => 'Нет покупки',
			default    => $state,
		};
	}

	/** Состояние документа. */
	public static function documentLabel( string $state ): string {
		return match ( $state ) {
			'pending' => 'Ожидает генерации',
			'running' => 'Генерируется',
			'done'    => 'Готов',
			'failed'  => 'Ошибка',
			''        => '—',
			default   => $state,
		};
	}

	/** Важность замечания в результате. */
	public static function severityLabel( string $severity ): string {
		return match ( $severity ) {
			'obstacle' => 'Препятствие',
			'check'    => 'Нужно проверить',
			'ok'       => 'Подтверждается',
			default    => 'Примечание',
		};
	}

	/** Последние 8 символов публичного идентификатора: короткий номер анкеты. */
	public static function shortNumber( string $publicId ): string {
		return substr( $publicId, -8 );
	}

	/** Дата Y-m-d в виде d.m.Y; всё остальное возвращается как есть. */
	public static function date( string $ymd ): string {
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return $m[3] . '.' . $m[2] . '.' . $m[1];
		}

		return $ymd;
	}

	/**
	 * Дата-время из БД (UTC) в часовом поясе сайта; без WP возвращается как есть.
	 */
	public static function localTime( ?string $utc ): string {
		if ( null === $utc || '' === $utc || '0000-00-00 00:00:00' === $utc ) {
			return '—';
		}

		if ( function_exists( 'get_date_from_gmt' ) ) {
			return (string) get_date_from_gmt( $utc, 'd.m.Y H:i' );
		}

		return $utc;
	}

	/** Ссылка на экран заказа EDD. */
	public static function orderUrl( int $orderId ): string {
		return admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $orderId );
	}
}
