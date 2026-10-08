<?php
/**
 * Фоновые задачи (`bgdvzh_cron`, раз в час) и отложенная генерация PDF
 * (`bgdvzh_generate`): обработка документов, сверка покупок в ожидании
 * оплаты, удаление данных по сроку хранения (ARCHITECTURE §8, §11).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Cron;

use Bginfo\PmzhDvzh\Access\AccessPolicy;
use Bginfo\PmzhDvzh\Commerce\EddGate;
use Bginfo\PmzhDvzh\Commerce\OrderEvents;
use Bginfo\PmzhDvzh\Pdf\DocumentService;
use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\DraftRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\GrantRepository;
use Bginfo\PmzhDvzh\Storage\PurchaseRepository;
use Bginfo\PmzhDvzh\Storage\SessionRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotPurger;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Clock;
use Bginfo\PmzhDvzh\Support\Settings;

final class CronHandler {

	public const HOOK = 'bgdvzh_cron';

	public function __construct(
		private Settings $settings,
		private DocumentRepository $documentRepo,
		private DocumentService $documents,
		private PurchaseRepository $purchases,
		private SnapshotRepository $snapshots,
		private DraftRepository $drafts,
		private SessionRepository $sessionRepo,
		private GrantRepository $grantRepo,
		private EventRepository $events,
		private AccessPolicy $policy,
		private OrderEvents $orderEvents,
		private SnapshotPurger $purger
	) {
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( OrderEvents::GENERATE_HOOK, array( $this, 'generate' ), 10, 1 );
	}

	/** Ежечасный проход. */
	public function run(): void {
		$this->documentRepo->failExhausted();
		$this->processDocuments( 10 );

		if ( EddGate::eddAvailable() ) {
			$this->reconcilePending();
		}

		$this->retention();
	}

	/**
	 * Отложенная генерация одного документа.
	 *
	 * @param mixed $purchaseId
	 */
	public function generate( $purchaseId ): void {
		$this->generateFor( (int) $purchaseId );
	}

	private function processDocuments( int $limit ): void {
		foreach ( $this->documentRepo->pendingPurchaseIds( $limit ) as $pid ) {
			$this->generateFor( $pid );
		}
	}

	/** Генерирует PDF, только если доступ подтверждается живым состоянием EDD. */
	private function generateFor( int $purchaseId ): void {
		$purchase = $this->purchases->byId( $purchaseId );

		if ( null === $purchase || ! $this->policy->paymentState( $purchase )['access'] ) {
			return;
		}

		$snapshot = $this->snapshots->findById( (int) $purchase['snapshot_id'] );

		if ( null === $snapshot || ! empty( $snapshot['purged_at'] ) ) {
			return;
		}

		$this->documents->ensure( $purchase );
	}

	/** Пересчёт кэша покупок, ожидавших оплаты (пропущенные хуки, webhook'и). */
	private function reconcilePending(): void {
		foreach ( $this->purchases->listByState( 'pending', 100 ) as $purchase ) {
			$this->orderEvents->syncPurchase( $purchase, 'cron' );
		}
	}

	private function retention(): void {
		$draftDays  = max( 1, (int) $this->settings->get( 'retention_days_drafts', 30 ) );
		$unpaidDays = max( 1, (int) $this->settings->get( 'retention_days_unpaid', 90 ) );

		$this->drafts->purgeOlderThan( Clock::fromNow( -$draftDays * DAY_IN_SECONDS ) );

		// Если EDD недоступен, состояние заказов неизвестно: снимки не трогаем.
		if ( $this->policy->available() ) {
			foreach ( $this->snapshots->findUnpaidOlderThan( Clock::fromNow( -$unpaidDays * DAY_IN_SECONDS ), 200 ) as $id ) {
				$rows = $this->purchases->bySnapshot( $id );
				$live = array();

				foreach ( $rows as $row ) {
					$live[] = $this->policy->paymentState( $row )['state'];
				}

				if ( SnapshotPurger::canAutoPurge( $rows, $live ) ) {
					$this->purger->purge( $id, 'retention' );
				}
			}
		}

		$this->sessionRepo->purgeExpired();
		$this->grantRepo->purgeExpired();
		$this->events->purgeOlderThan( Clock::fromNow( -365 * DAY_IN_SECONDS ) );
	}
}
