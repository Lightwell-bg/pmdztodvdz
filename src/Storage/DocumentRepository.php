<?php
/**
 * Документы (PDF) и состояние задач генерации.
 *
 * Состояния: pending → running → done | failed. Захват задачи атомарен
 * (см. claim()): два параллельных запроса или cron не сгенерируют PDF дважды.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Support\Clock;

final class DocumentRepository {

	public const MAX_ATTEMPTS = 5;
	public const LOCK_SECONDS = 120;

	/** Создаёт строку документа в состоянии pending, если её ещё нет. */
	public function ensureRow( int $purchaseId ): void {
		global $wpdb;

		$t = Schema::table( 'documents' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t} (purchase_id, state, attempts, updated_at) VALUES (%d, 'pending', 0, %s)",
				$purchaseId,
				Clock::now()
			)
		);
	}

	/** @return array<string,mixed>|null Без тела PDF (оно читается отдельно). */
	public function findByPurchase( int $purchaseId ): ?array {
		global $wpdb;

		$t   = Schema::table( 'documents' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, purchase_id, state, attempts, last_error, locked_until, lock_token, pdf_file, pdf_sha256, generated_at, updated_at, (pdf IS NOT NULL) AS has_pdf FROM {$t} WHERE purchase_id = %d",
				$purchaseId
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** @return string|null Тело PDF из БД. */
	public function pdfBody( int $purchaseId ): ?string {
		global $wpdb;

		$t   = Schema::table( 'documents' );
		$pdf = $wpdb->get_var( $wpdb->prepare( "SELECT pdf FROM {$t} WHERE purchase_id = %d", $purchaseId ) );

		return is_string( $pdf ) && '' !== $pdf ? $pdf : null;
	}

	/**
	 * Атомарный захват задачи (ARCHITECTURE §8).
	 *
	 * @return bool true, если именно этот вызов получил задачу.
	 */
	public function claim( int $purchaseId, string $lockToken ): bool {
		global $wpdb;

		$t   = Schema::table( 'documents' );
		$now = Clock::now();

		// Зависшая блокировка с исчерпанными попытками → failed (иначе строка
		// навсегда остаётся «running», и «Повторить» не срабатывает).
		$this->failExhausted( $purchaseId );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET state = 'running', lock_token = %s, locked_until = %s, attempts = attempts + 1, updated_at = %s WHERE purchase_id = %d AND (state IN ('pending','failed') OR (state = 'running' AND locked_until < %s)) AND attempts < %d",
				$lockToken,
				Clock::fromNow( self::LOCK_SECONDS ),
				$now,
				$purchaseId,
				$now,
				self::MAX_ATTEMPTS
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Переводит в failed документы, у которых блокировка истекла, а попытки
	 * исчерпаны. Без аргумента — все такие строки (cron).
	 *
	 * @return int Сколько строк переведено.
	 */
	public function failExhausted( ?int $purchaseId = null ): int {
		global $wpdb;

		$t     = Schema::table( 'documents' );
		$where = null === $purchaseId ? '' : $wpdb->prepare( ' AND purchase_id = %d', $purchaseId );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET state = 'failed', last_error = %s, locked_until = NULL, lock_token = '', updated_at = %s WHERE state = 'running' AND locked_until < %s AND attempts >= %d{$where}",
				'Lock expired, attempts exhausted',
				Clock::now(),
				Clock::now(),
				self::MAX_ATTEMPTS
			)
		);
	}

	/** Ссылается ли какая-либо строка документа на этот файл PDF. */
	public function fileInUse( string $file ): bool {
		global $wpdb;

		$t = Schema::table( 'documents' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE pdf_file = %s", $file ) ) > 0;
	}

	/**
	 * Завершение генерации (только держателем блокировки).
	 *
	 * @param string|null $pdf  Тело PDF для хранения в БД; null — PDF лежит в файле.
	 * @param string      $file Имя файла в приватном каталоге либо ''.
	 */
	public function finish( int $purchaseId, string $lockToken, ?string $pdf, string $file, string $sha256, string $html ): bool {
		global $wpdb;

		$t   = Schema::table( 'documents' );
		$now = Clock::now();

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET state = 'done', pdf = UNHEX(%s), pdf_file = %s, pdf_sha256 = %s, html = %s, last_error = '', generated_at = %s, locked_until = NULL, lock_token = '', updated_at = %s WHERE purchase_id = %d AND lock_token = %s",
				bin2hex( (string) $pdf ),
				$file,
				$sha256,
				$html,
				$now,
				$now,
				$purchaseId,
				$lockToken
			)
		);

		if ( 1 === (int) $affected && null === $pdf ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET pdf = NULL WHERE purchase_id = %d", $purchaseId ) );
		}

		return 1 === (int) $affected;
	}

	/** Ошибка генерации. Текст без персональных данных, до 255 символов. */
	public function fail( int $purchaseId, string $lockToken, string $error ): void {
		global $wpdb;

		$t = Schema::table( 'documents' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET state = 'failed', last_error = %s, locked_until = NULL, lock_token = '', updated_at = %s WHERE purchase_id = %d AND lock_token = %s",
				mb_substr( $error, 0, 255 ),
				Clock::now(),
				$purchaseId,
				$lockToken
			)
		);
	}

	/**
	 * Кнопка «Повторить»: failed → pending, attempts = 0, не чаще раза в cooldown секунд.
	 *
	 * @return bool true, если состояние сброшено.
	 */
	public function resetFailed( int $purchaseId, int $cooldownSeconds = 60 ): bool {
		global $wpdb;

		$t = Schema::table( 'documents' );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET state = 'pending', attempts = 0, updated_at = %s WHERE purchase_id = %d AND state = 'failed' AND updated_at <= %s",
				Clock::now(),
				$purchaseId,
				Clock::fromNow( -$cooldownSeconds )
			)
		);

		return 1 === (int) $affected;
	}

	/** Админ: сгенерировать PDF заново из того же снимка. */
	public function resetForRegenerate( int $purchaseId ): void {
		global $wpdb;

		$t = Schema::table( 'documents' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET state = 'pending', attempts = 0, pdf = NULL, pdf_file = '', pdf_sha256 = '', generated_at = NULL, locked_until = NULL, lock_token = '', updated_at = %s WHERE purchase_id = %d",
				Clock::now(),
				$purchaseId
			)
		);
	}

	/**
	 * Документы, ожидающие генерации (для cron).
	 *
	 * @return list<int> purchase_id
	 */
	public function pendingPurchaseIds( int $limit = 20 ): array {
		global $wpdb;

		$t   = Schema::table( 'documents' );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT purchase_id FROM {$t} WHERE (state IN ('pending','failed') OR (state = 'running' AND locked_until < %s)) AND attempts < %d ORDER BY id ASC LIMIT %d",
				Clock::now(),
				self::MAX_ATTEMPTS,
				$limit
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/** Удаляет документ покупки (при очистке данных анкеты). */
	public function deleteForPurchase( int $purchaseId ): ?string {
		global $wpdb;

		$t    = Schema::table( 'documents' );
		$file = $wpdb->get_var( $wpdb->prepare( "SELECT pdf_file FROM {$t} WHERE purchase_id = %d", $purchaseId ) );
		$wpdb->delete( $t, array( 'purchase_id' => $purchaseId ), array( '%d' ) );

		return is_string( $file ) && '' !== $file ? $file : null;
	}
}
