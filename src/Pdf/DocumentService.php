<?php
/**
 * Генерация и выдача PDF (ARCHITECTURE §8).
 *
 * Страница инструкции строится из снимка на каждый запрос и от состояния
 * PDF не зависит. PDF строится из того же HTML. Захват задачи атомарен:
 * параллельные запросы и cron не генерируют документ дважды. Ошибка
 * генерации переводит документ в failed (без персональных данных в
 * тексте ошибки) и не влияет на показ инструкции на странице.
 *
 * Хранение: по умолчанию в БД (LONGBLOB). Если определена константа
 * BGINFO_PMZH_DVZH_PRIVATE_DIR и каталог лежит вне ABSPATH и uploads,
 * PDF пишется в файл `<sha256>.pdf`; при любой ошибке — в БД.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Pdf;

use Bginfo\PmzhDvzh\Instruction\HtmlRenderer;
use Bginfo\PmzhDvzh\Storage\DocumentRepository;
use Bginfo\PmzhDvzh\Storage\EventRepository;
use Bginfo\PmzhDvzh\Storage\SnapshotRepository;
use Bginfo\PmzhDvzh\Support\Json;

final class DocumentService {

	public function __construct(
		private DocumentRepository $documents,
		private SnapshotRepository $snapshots,
		private EventRepository $events,
		private HtmlRenderer $html,
		private PdfRenderer $pdf
	) {
	}

	/**
	 * Состояние документа для интерфейса.
	 *
	 * @return 'pending'|'running'|'done'|'failed'
	 */
	public function state( int $purchaseId ): string {
		$row = $this->documents->findByPurchase( $purchaseId );

		if ( null === $row ) {
			return 'pending';
		}

		$state = (string) $row['state'];

		return in_array( $state, array( 'pending', 'running', 'done', 'failed' ), true ) ? $state : 'pending';
	}

	/**
	 * Возвращает готовый PDF или запускает генерацию. Вызывающий обязан
	 * уже проверить доступ (AccessPolicy + владелец).
	 *
	 * @param array<string,mixed> $purchase Строка purchases.
	 * @return array{state:string,pdf:?string,sha256:string}
	 */
	public function ensure( array $purchase ): array {
		$pid = (int) $purchase['id'];

		// Для очищенного снимка PDF не генерируем и строку документа не создаём.
		$snapshot = $this->snapshots->findById( (int) $purchase['snapshot_id'] );

		if ( null === $snapshot || ! empty( $snapshot['purged_at'] ) ) {
			return array( 'state' => 'failed', 'pdf' => null, 'sha256' => '' );
		}

		$this->documents->ensureRow( $pid );

		for ( $round = 0; $round < 2; $round++ ) {
			$row = $this->documents->findByPurchase( $pid );

			if ( null !== $row && 'done' === $row['state'] ) {
				$body = $this->readPdf( $row );

				if ( null !== $body ) {
					return array( 'state' => 'done', 'pdf' => $body, 'sha256' => (string) $row['pdf_sha256'] );
				}

				// Файл потерян: сбрасываем и генерируем заново.
				$this->documents->resetForRegenerate( $pid );
			}

			$token = bin2hex( random_bytes( 16 ) );

			if ( $this->documents->claim( $pid, $token ) ) {
				return $this->generate( $purchase, $token );
			}

			$row = $this->documents->findByPurchase( $pid );

			if ( null !== $row && 'done' === $row['state'] && 0 === $round ) {
				continue;
			}

			break;
		}

		$row   = $this->documents->findByPurchase( $pid );
		$state = null === $row ? 'pending' : (string) $row['state'];

		if ( 'done' === $state ) {
			$body = null === $row ? null : $this->readPdf( $row );

			return array( 'state' => null === $body ? 'failed' : 'done', 'pdf' => $body, 'sha256' => (string) ( $row['pdf_sha256'] ?? '' ) );
		}

		return array( 'state' => 'running' === $state ? 'running' : ( 'failed' === $state ? 'failed' : 'pending' ), 'pdf' => null, 'sha256' => '' );
	}

	/**
	 * PDF «на лету» из инструкции снимка: без хранения и без строк документов
	 * (бесплатный доступ администратора). Вызывающий проверяет права, что снимок
	 * не очищен и что content_hash совпадает.
	 *
	 * @param array<string,mixed> $snapshot Строка snapshots.
	 * @throws \RuntimeException Если инструкции нет.
	 */
	public function renderForSnapshot( array $snapshot ): string {
		$instruction = Json::decode( (string) ( $snapshot['instruction'] ?? '' ) );

		if ( empty( $instruction ) ) {
			throw new \RuntimeException( 'Snapshot has no instruction' );
		}

		return $this->pdf->render( $this->html->document( $instruction ) );
	}

	/**
	 * @param array<string,mixed> $purchase
	 * @return array{state:string,pdf:?string,sha256:string}
	 */
	private function generate( array $purchase, string $token ): array {
		$pid  = (int) $purchase['id'];
		$file = null;

		try {
			$snapshot = $this->snapshots->findById( (int) $purchase['snapshot_id'] );

			if ( null === $snapshot || ! empty( $snapshot['purged_at'] ) ) {
				throw new \RuntimeException( 'Snapshot is unavailable' );
			}

			if ( ! SnapshotRepository::hashMatches( $snapshot ) ) {
				$this->events->log( 'content_hash_mismatch', $pid, (int) $purchase['order_id'], 'pdf' );
				throw new \RuntimeException( 'Snapshot integrity check failed' );
			}

			$instruction = Json::decode( (string) $snapshot['instruction'] );
			$html        = $this->html->document( $instruction );
			$bytes       = $this->pdf->render( $html );
			$sha         = hash( 'sha256', $bytes );
			$file        = $this->storeInFile( $bytes, $sha );

			$ok = $this->documents->finish( $pid, $token, null === $file ? $bytes : null, $file ?? '', $sha, $html );

			if ( ! $ok ) {
				// Блокировку перехватил другой процесс (или строку удалила очистка):
				// записанный нами файл не нужен, результат определит другой процесс.
				$this->deleteFileIfUnused( $file );

				return array( 'state' => 'running', 'pdf' => null, 'sha256' => '' );
			}

			// Гонка с очисткой: снимок могли очистить, пока шла генерация.
			$fresh = $this->snapshots->findById( (int) $purchase['snapshot_id'] );

			if ( null === $fresh || ! empty( $fresh['purged_at'] ) ) {
				$this->documents->deleteForPurchase( $pid );
				$this->deleteFileIfUnused( $file );
				$this->events->log( 'document_dropped_purged', $pid, (int) $purchase['order_id'], '' );

				return array( 'state' => 'failed', 'pdf' => null, 'sha256' => '' );
			}

			$this->events->log( 'document_done', $pid, (int) $purchase['order_id'], '' );

			return array( 'state' => 'done', 'pdf' => $bytes, 'sha256' => $sha );
		} catch ( \Throwable $e ) {
			$this->deleteFileIfUnused( $file );
			$this->documents->fail( $pid, $token, get_class( $e ) . ': ' . mb_substr( $e->getMessage(), 0, 150 ) );
			$this->events->log( 'document_failed', $pid, (int) $purchase['order_id'], get_class( $e ) );

			return array( 'state' => 'failed', 'pdf' => null, 'sha256' => '' );
		}
	}

	/**
	 * Читает PDF из БД либо из приватного файла.
	 *
	 * @param array<string,mixed> $row
	 */
	public function readPdf( array $row ): ?string {
		$file = (string) ( $row['pdf_file'] ?? '' );

		if ( '' === $file ) {
			return $this->documents->pdfBody( (int) $row['purchase_id'] );
		}

		$dir = self::privateDir();

		if ( null === $dir || 1 !== preg_match( '/^[0-9a-f]{64}\.pdf$/', $file ) ) {
			return null;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $file;
		$real = realpath( $path );

		if ( false === $real || 0 !== strpos( $real, $dir . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		$body = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_string( $body ) && '' !== $body ? $body : null;
	}

	/** Пишет PDF в приватный каталог; null — использовать БД. */
	private function storeInFile( string $bytes, string $sha ): ?string {
		$dir = self::privateDir();

		if ( null === $dir ) {
			return null;
		}

		$name = $sha . '.pdf';
		$tmp  = $dir . DIRECTORY_SEPARATOR . $name . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';

		if ( false === @file_put_contents( $tmp, $bytes, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return null;
		}

		if ( ! @rename( $tmp, $dir . DIRECTORY_SEPARATOR . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return null;
		}

		return $name;
	}

	/** Удаляет только что записанный файл, если на него не ссылается ни одна строка документа. */
	private function deleteFileIfUnused( ?string $file ): void {
		if ( null !== $file && '' !== $file && ! $this->documents->fileInUse( $file ) ) {
			$this->deleteFile( $file );
		}
	}

	/** Удаляет файл PDF (при очистке данных анкеты). */
	public function deleteFile( string $file ): void {
		$dir = self::privateDir();

		if ( null === $dir || 1 !== preg_match( '/^[0-9a-f]{64}\.pdf$/', $file ) ) {
			return;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $file;

		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	/** Приватный каталог из константы или null, если он не прошёл проверку. */
	public static function privateDir(): ?string {
		if ( ! defined( 'BGINFO_PMZH_DVZH_PRIVATE_DIR' ) ) {
			return null;
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$base    = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		return self::resolvePrivateDir( (string) BGINFO_PMZH_DVZH_PRIVATE_DIR, defined( 'ABSPATH' ) ? (string) ABSPATH : '', $base );
	}

	/**
	 * Каталог допустим, если он реальный, записываемый и лежит вне
	 * корня WordPress (ABSPATH) и вне uploads.
	 */
	public static function resolvePrivateDir( string $dir, string $abspath, string $uploadsBase ): ?string {
		$real = '' === $dir ? false : realpath( $dir );

		if ( false === $real || ! is_dir( $real ) || ! is_writable( $real ) ) {
			return null;
		}

		foreach ( array( $abspath, $uploadsBase ) as $forbidden ) {
			if ( '' === $forbidden ) {
				continue;
			}

			$f = realpath( $forbidden );

			if ( false !== $f && self::isInside( $real, $f ) ) {
				return null;
			}
		}

		return $real;
	}

	private static function isInside( string $path, string $root ): bool {
		$p = rtrim( str_replace( '\\', '/', $path ), '/' ) . '/';
		$r = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';

		return 0 === strncasecmp( $p, $r, strlen( $r ) );
	}
}
