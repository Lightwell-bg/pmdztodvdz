<?php
/**
 * Очистка данных анкеты: обнуляет ответы, результат, предпросмотр и
 * инструкцию снимка, удаляет документ (PDF) и отзывает grants. Строки
 * purchases и заказ EDD остаются (бухгалтерия не затрагивается).
 * Используется cron'ом (срок хранения) и админкой («Удалить данные анкеты»).
 *
 * Порядок защищает от гонки с генерацией PDF: сначала атомарно ставится
 * purged_at (после этого DocumentService не начнёт и не завершит
 * генерацию), затем обнуляются данные, затем удаляются документы и файлы.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

use Bginfo\PmzhDvzh\Pdf\DocumentService;

final class SnapshotPurger {

	/** Кэш-состояния покупки, при которых снимок нельзя удалять автоматически. */
	private const PROTECTED_STATES = array( 'paid', 'pending', 'disputed', 'refunded', 'revoked' );

	/** Живые состояния, при которых снимок без оплаты можно удалять автоматически. */
	private const PURGEABLE_LIVE = array( 'failed', 'missing' );

	public function __construct(
		private SnapshotRepository $snapshots,
		private PurchaseRepository $purchases,
		private GrantRepository $grants,
		private DocumentRepository $documents,
		private DocumentService $documentService,
		private EventRepository $events
	) {
	}

	/**
	 * Можно ли удалить снимок автоматически по сроку хранения.
	 *
	 * Нельзя, если у любой покупки есть paid_at или кэш-состояние из
	 * PROTECTED_STATES, либо если живое состояние покупки не failed/missing
	 * (в том числе unavailable: EDD недоступен — состояние неизвестно).
	 * Снимок без покупок удалять можно.
	 *
	 * @param list<array<string,mixed>> $purchases  Строки purchases снимка.
	 * @param list<string>              $liveStates Живое состояние каждой покупки (в том же порядке).
	 */
	public static function canAutoPurge( array $purchases, array $liveStates ): bool {
		foreach ( array_values( $purchases ) as $i => $p ) {
			if ( ! empty( $p['paid_at'] ) || in_array( (string) ( $p['state'] ?? '' ), self::PROTECTED_STATES, true ) ) {
				return false;
			}

			if ( ! in_array( $liveStates[ $i ] ?? '', self::PURGEABLE_LIVE, true ) ) {
				return false;
			}
		}

		return true;
	}

	public function purge( int $snapshotId, string $reason = '' ): void {
		// 1. Атомарная пометка; 2. обнуление данных (повторный вызов безопасен).
		$this->snapshots->markPurged( $snapshotId );
		$this->snapshots->wipeData( $snapshotId );

		// 3. Документы, файлы, grants.
		foreach ( $this->purchases->bySnapshot( $snapshotId ) as $purchase ) {
			$file = $this->documents->deleteForPurchase( (int) $purchase['id'] );

			if ( null !== $file ) {
				$this->documentService->deleteFile( $file );
			}

			$this->grants->revokeForPurchase( (int) $purchase['id'] );
			$this->events->log( 'snapshot_purged', (int) $purchase['id'], (int) $purchase['order_id'], $reason );
		}

		$this->grants->revokeForSnapshot( $snapshotId );
	}
}
