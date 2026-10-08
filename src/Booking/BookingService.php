<?php
/**
 * Машина состояний записи на консультацию.
 *
 * Статусы:
 *   held             — временный резерв (единицы/место с expires_at), ждём оплату;
 *   expired          — резерв закончился без оплаты (заказа нет или он не прошёл);
 *   confirmed        — оплачено, время/место закреплено;
 *   needs_reschedule — оплачено, но закрепить время не удалось (поздняя оплата,
 *                      место занято) — посетитель/админ выбирает новое время;
 *   disputed         — спор по оплате: место держим, зачёт заблокирован;
 *   refunded         — полный возврат позиции консультации: место освобождено;
 *   cancelled        — встреча отменена (без автоматического возврата денег);
 *   completed        — консультация проведена.
 *
 * Флаг credit_conflict = 1 — оплата пришла, но зачёт к этому моменту уже
 * использован другой бронью: оплата и доступ сохраняются, админ решает вручную.
 *
 * Все переходы — через BookingRepository::transition() (условный UPDATE по
 * текущему статусу), поэтому повторные события и клики идемпотентны.
 *
 * Необходимое от зависимостей (реализуются в репозитории/сервисах):
 *  BookingRepository: create(array): ?array, get(int): ?array,
 *    transition(int $id, list<string> $from, string $to, array $fields = []): bool,
 *    update(int $id, array $fields): void, activeHold(int $sessionId, int $snapshotId, int $now): ?array
 *  Notifier: queue(int $bookingId, string $type, int $seq = 0): void
 *    (уникальный журнал (booking_id, type, seq) — повтор не создаёт второе письмо).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Schedule\Grid;
use Bginfo\PmzhDvzh\Schedule\ScheduleConfig;
use Bginfo\PmzhDvzh\Schedule\SlotGenerator;
use Bginfo\PmzhDvzh\Storage\CreditRepository;
use Bginfo\PmzhDvzh\Storage\SlotRepository;

final class BookingService {

	public const SYNC_FORMATS    = array( 'telegram', 'whatsapp', 'phone', 'meet' );
	public const WRITTEN_FORMATS = array( 'email' );
	public const FORMATS         = array( 'telegram', 'whatsapp', 'email', 'phone', 'meet' );

	/** Статусы с активной занятостью времени/места. */
	public const ACTIVE = array( 'held', 'confirmed', 'disputed' );

	/**
	 * @param object $bookings BookingRepository
	 * @param object $notifier Notifier
	 * @param array<string,mixed> $settings Настройки консультаций (Support\Settings::consultation()).
	 */
	public function __construct(
		private object $bookings,
		private SlotRepository $slots,
		private CreditRepository $credits,
		private object $notifier,
		private ScheduleConfig $schedule,
		private array $settings
	) {
	}

	// -----------------------------------------------------------------
	// Создание резерва.

	/**
	 * Создать временный резерв.
	 *
	 * $req: format, mode ('text'|'call' для telegram/whatsapp), start_utc
	 * (синхронные) или date ('Y-m-d', email), contact, email, question,
	 * visitor_tz, snapshot_id, session_id, user_id,
	 * price: {base, item, currency, tax_mode, price_id} (уже рассчитано
	 * сервером по EDD), credit: null | {instruction_purchase_id, amount}.
	 *
	 * @param array<string,mixed> $req
	 * @return array{ok:bool,error?:string,booking?:array<string,mixed>,alternatives?:list<string>}
	 */
	public function hold( array $req, int $now ): array {
		$format = (string) ( $req['format'] ?? '' );

		if ( ! in_array( $format, self::FORMATS, true ) || empty( $this->settings['formats'][ $format ]['enabled'] ) ) {
			return array( 'ok' => false, 'error' => 'format_unavailable' );
		}

		if ( ! $this->schedule->isValid() ) {
			return array( 'ok' => false, 'error' => 'schedule_invalid' );
		}

		$gen      = new SlotGenerator( $this->schedule );
		$holdMin  = max( 5, (int) ( $this->settings['hold_minutes'] ?? 15 ) );
		$holdTill = $now + $holdMin * 60;
		$written  = in_array( $format, self::WRITTEN_FORMATS, true );

		// Повторный клик / новая попытка в той же сессии: старый резерв этой
		// анкеты освобождаем (одна активная бронь на сессию и анкету).
		$prev = $this->bookings->activeHold( (int) $req['session_id'], (int) $req['snapshot_id'], $now );

		if ( null !== $prev ) {
			// По прежней брони уже идёт оплата (живой заказ) — не заменяем,
			// иначе её зачёт и время могли бы уйти второй брони.
			if ( ! empty( $req['prev_order_alive'] ) ) {
				return array( 'ok' => false, 'error' => 'payment_in_progress', 'booking' => $prev );
			}

			// Под блокировкой брони: параллельная оплата могла уже подтвердить её.
			$released = $this->withLock(
				(int) $prev['id'],
				$now,
				function ( array $fresh ) use ( $now ): bool {
					if ( 'held' !== $fresh['status'] ) {
						return false;
					}

					$this->expire( $fresh, $now, 'replaced' );

					return true;
				},
				false
			);

			if ( ! $released ) {
				return array( 'ok' => false, 'error' => 'payment_in_progress', 'booking' => $prev );
			}
		}

		if ( $written ) {
			$date = (string) ( $req['date'] ?? '' );

			if ( ! in_array( $date, $gen->writtenDates( $now ), true ) ) {
				return array( 'ok' => false, 'error' => 'date_unavailable' );
			}

			$deadline = $gen->writtenDeadline( $date );

			if ( null === $deadline ) {
				return array( 'ok' => false, 'error' => 'schedule_invalid' );
			}

			$slot = null;
		} else {
			$slot = $gen->match( (int) ( $req['start_utc'] ?? 0 ), $now );

			if ( null === $slot ) {
				return array( 'ok' => false, 'error' => 'time_unavailable' );
			}

			$date     = $slot['date'];
			$deadline = null;
		}

		$terms   = $this->terms( $format, (string) ( $req['mode'] ?? '' ), $slot, $written ? $date : null, $deadline, (string) ( $req['visitor_tz'] ?? '' ), (array) $req['price'], $req['credit'] ?? null );
		$booking = $this->bookings->create(
			array(
				'snapshot_id'   => (int) $req['snapshot_id'],
				'session_id'    => (int) $req['session_id'],
				'user_id'       => (int) $req['user_id'],
				'format'        => $format,
				'mode'          => $terms['mode'],
				'kind'          => $written ? 'written' : 'sync',
				'status'        => 'held',
				'start_utc'     => $slot['start'] ?? null,
				'end_utc'       => $slot['end'] ?? null,
				'block_end_utc' => $slot['block_end'] ?? null,
				'written_date'  => $written ? $date : null,
				'deadline_utc'  => $deadline,
				'hold_until'    => $holdTill,
				'contact'       => (string) ( $req['contact'] ?? '' ),
				'email'         => (string) ( $req['email'] ?? '' ),
				'question'      => (string) ( $req['question'] ?? '' ),
				'visitor_tz'    => (string) ( $req['visitor_tz'] ?? '' ),
				'terms'         => $terms,
				'credit_purchase_id' => isset( $req['credit']['instruction_purchase_id'] ) ? (int) $req['credit']['instruction_purchase_id'] : 0,
				'credit_amount' => isset( $req['credit']['amount'] ) ? (string) $req['credit']['amount'] : '0',
			)
		);

		if ( null === $booking ) {
			return array( 'ok' => false, 'error' => 'storage' );
		}

		$id = (int) $booking['id'];

		$claimed = $written
			? null !== $this->slots->claimSeat( $id, $date, $this->writtenLimit(), $holdTill, $now )
			: $this->slots->claimUnits( $id, Grid::units( $slot['start'], $slot['block_end'] ), $holdTill, $now );

		if ( ! $claimed ) {
			$this->bookings->transition( $id, array( 'held' ), 'expired', array( 'status_reason' => 'taken' ) );

			return array( 'ok' => false, 'error' => $written ? 'date_full' : 'time_taken' );
		}

		if ( ! empty( $req['credit'] ) ) {
			$ok = $this->credits->reserve(
				(int) $req['credit']['instruction_purchase_id'],
				$id,
				(string) $req['credit']['amount'],
				(string) $req['price']['currency'],
				(string) $req['price']['tax_mode'],
				$holdTill,
				gmdate( 'Y-m-d H:i:s', $now )
			);

			if ( ! $ok ) {
				// Зачёт занят другой бронью — без зачёта не продаём молча:
				// снимаем резерв, посетитель увидит актуальную цену заново.
				$this->expire( $booking, $now, 'credit_busy' );

				return array( 'ok' => false, 'error' => 'credit_busy' );
			}
		}

		return array( 'ok' => true, 'booking' => $this->bookings->get( $id ) );
	}

	// -----------------------------------------------------------------
	// Оплата и события EDD (вызываются из OrderEvents по живому состоянию).

	/**
	 * Позиция консультации живо оплачена. Идемпотентно.
	 *
	 * @param array<string,mixed> $booking
	 * @return string итоговый статус
	 */
	public function onPaid( array $booking, int $now ): string {
		return $this->withLock(
			(int) $booking['id'],
			$now,
			fn( array $fresh ): string => $this->paidLocked( $fresh, $now ),
			(string) $booking['status']
		);
	}

	/**
	 * @param array<string,mixed> $booking Свежая строка под блокировкой.
	 */
	private function paidLocked( array $booking, int $now ): string {
		$id     = (int) $booking['id'];
		$status = (string) $booking['status'];
		$stamp  = gmdate( 'Y-m-d H:i:s', $now );

		if ( 'cancelled' === $status ) {
			// Заказ оплачен уже после отмены: время не возвращаем, но оплату не теряем.
			if ( empty( $booking['paid_at'] ) ) {
				$fields = array( 'paid_at' => $stamp );

				if ( (int) $booking['credit_purchase_id'] > 0 && ! $this->credits->markUsed( (int) $booking['credit_purchase_id'], $id, $stamp ) ) {
					$fields['credit_conflict'] = 1;
				}

				$this->bookings->update( $id, $fields );
				$this->notifier->queue( $id, 'admin_paid_after_cancel' );
			}

			return $status;
		}

		if ( in_array( $status, array( 'confirmed', 'completed', 'needs_reschedule' ), true ) ) {
			return $status;
		}

		if ( 'disputed' === $status ) {
			// Спор решён в пользу продавца: возвращаем статус, из которого ушли в спор.
			$this->credits->unlock( $id, $stamp );

			$pre = (string) ( $booking['pre_dispute_status'] ?? '' );

			if ( '' === $pre || 'confirmed' === $pre ) {
				$this->bookings->transition( $id, array( 'disputed' ), 'confirmed', array( 'pre_dispute_status' => '' ) );
			} elseif ( 'needs_reschedule' === $pre ) {
				$this->bookings->transition( $id, array( 'disputed' ), 'needs_reschedule', array( 'pre_dispute_status' => '' ) );
			} else {
				// held / expired: единицы могли истечь, подтверждаем как обычную оплату.
				$this->confirmPaid( $booking, array( 'disputed' ), $now, array( 'pre_dispute_status' => '' ) );
			}

			$fresh = $this->bookings->get( $id );

			return null === $fresh ? 'confirmed' : (string) $fresh['status'];
		}

		if ( ! in_array( $status, array( 'held', 'expired' ), true ) ) {
			// refunded → снова оплачено (крайне редко): только админ.
			return $status;
		}

		$this->confirmPaid( $booking, array( 'held', 'expired' ), $now );

		$fresh = $this->bookings->get( $id );

		return null === $fresh ? (string) $status : (string) $fresh['status'];
	}

	/**
	 * Закрепить время/место, использовать зачёт и перевести запись в confirmed
	 * (или needs_reschedule, если закрепить не удалось).
	 *
	 * @param array<string,mixed> $booking
	 * @param list<string>        $from
	 * @param array<string,mixed> $extra
	 */
	private function confirmPaid( array $booking, array $from, int $now, array $extra = array() ): void {
		$id    = (int) $booking['id'];
		$fixed = 'written' === $booking['kind']
			? $this->slots->confirmSeat( $id, (string) $booking['written_date'], $this->writtenLimit(), $now )
			: $this->slots->confirmUnits( $id, Grid::units( (int) $booking['start_utc'], (int) $booking['block_end_utc'] ), $now );

		$fields = array( 'paid_at' => gmdate( 'Y-m-d H:i:s', $now ) ) + $extra;
		$conflict = (int) $booking['credit_purchase_id'] > 0 && ! $this->credits->markUsed( (int) $booking['credit_purchase_id'], $id, gmdate( 'Y-m-d H:i:s', $now ) );

		if ( $conflict ) {
			$fields['credit_conflict'] = 1;
		}

		$to = $fixed ? 'confirmed' : 'needs_reschedule';

		if ( ! $this->bookings->transition( $id, $from, $to, $fields ) ) {
			return;
		}

		if ( $conflict ) {
			$this->notifier->queue( $id, 'admin_credit_conflict' );
		}

		$this->notifier->queue( $id, 'customer_confirmation', 0 );
		$this->notifier->queue( $id, 'admin_new_paid' );

		if ( ! $fixed ) {
			$this->notifier->queue( $id, 'admin_time_conflict' );
		}
	}

	/**
	 * Полный возврат позиции консультации.
	 *
	 * @param array<string,mixed> $booking
	 */
	public function onFullRefund( array $booking, bool $instructionStillValid, int $now ): void {
		$this->withLock(
			(int) $booking['id'],
			$now,
			function ( array $b ) use ( $instructionStillValid, $now ): bool {
				$id   = (int) $b['id'];
				$from = (string) $b['status'];

				if ( $this->bookings->transition( $id, array( 'held', 'expired', 'confirmed', 'needs_reschedule', 'disputed', 'cancelled', 'completed' ), 'refunded' ) ) {
					$this->releaseTime( $b );
					$this->notifier->queue( $id, 'admin_refunded' );

					if ( 'sync' === $b['kind'] && in_array( $from, array( 'confirmed', 'disputed' ), true ) ) {
						$this->notifier->queue( $id, 'customer_cancelled', (int) $b['ics_sequence'] + 1 );
					}
				}

				$this->credits->afterRefund( $id, $instructionStillValid, gmdate( 'Y-m-d H:i:s', $now ) );

				return true;
			},
			false
		);
	}

	/** @param array<string,mixed> $booking */
	public function onDispute( array $booking, int $now ): void {
		$this->withLock(
			(int) $booking['id'],
			$now,
			function ( array $b ) use ( $now ): bool {
				$id = (int) $b['id'];

				// Статус до спора запоминаем, чтобы после выигранного спора вернуть его.
				if ( $this->bookings->transition( $id, array( 'confirmed', 'needs_reschedule', 'held', 'expired' ), 'disputed', array( 'pre_dispute_status' => (string) $b['status'] ) ) ) {
					$this->notifier->queue( $id, 'admin_disputed' );
				}

				$this->credits->lock( $id, gmdate( 'Y-m-d H:i:s', $now ) );

				return true;
			},
			false
		);
	}

	/**
	 * Резерв истёк и по брони нет живого заказа в pending/processing/complete
	 * ($orderAlive — решение вызывающего по живому EDD). Освобождает время
	 * и зачёт. Если заказ ещё жив — резерв времени всё равно истекает по
	 * таймеру (единицы освободит следующий претендент), но зачёт держим.
	 *
	 * @param array<string,mixed> $booking
	 */
	public function expireIfStale( array $booking, bool $orderAlive, int $now ): void {
		if ( $orderAlive ) {
			return; // Оплата идёт: статус held сохраняется, зачёт закреплён.
		}

		$this->withLock(
			(int) $booking['id'],
			$now,
			function ( array $b ) use ( $now ): bool {
				if ( 'held' === $b['status'] && (int) $b['hold_until'] < $now ) {
					$this->expire( $b, $now, 'timeout' );
				}

				return true;
			},
			false
		);
	}

	/**
	 * Освободить зачёт брони, чей резерв истёк, если по ней нет живого
	 * заказа (вызывается перед резервированием зачёта другой бронью).
	 *
	 * @param array<string,mixed> $booking
	 */
	public function releaseCreditIfStale( array $booking, bool $orderAlive, int $now ): void {
		if ( $orderAlive || (int) $booking['hold_until'] >= $now ) {
			return;
		}

		$this->withLock(
			(int) $booking['id'],
			$now,
			function ( array $b ) use ( $now ): bool {
				// Живой заказ (в том числе у отменённой записи: его ещё могут оплатить)
				// отсёк вызывающий через $orderAlive; здесь не трогаем уже оплаченные статусы.
				if ( (int) $b['hold_until'] < $now && ! in_array( $b['status'], array( 'confirmed', 'needs_reschedule', 'disputed', 'completed' ), true ) ) {
					$this->credits->releaseReserved( (int) $b['id'], gmdate( 'Y-m-d H:i:s', $now ) );
				}

				return true;
			},
			false
		);
	}

	// -----------------------------------------------------------------
	// Перенос, отмена, завершение.

	/**
	 * Перенос. by = 'guest' проверяет условия из terms записи: не позднее
	 * N часов до начала и не более M раз. Старое время освобождается
	 * только после успешного занятия нового.
	 *
	 * @param array<string,mixed> $booking
	 * @param array{start_utc?:int,date?:string} $to
	 * @return array{ok:bool,error?:string}
	 */
	public function reschedule( array $booking, array $to, string $by, int $now ): array {
		return $this->withLock(
			(int) $booking['id'],
			$now,
			fn( array $fresh ): array => $this->rescheduleLocked( $fresh, $to, $by, $now ),
			array( 'ok' => false, 'error' => 'busy' )
		);
	}

	/**
	 * @param array<string,mixed> $booking Свежая строка под блокировкой.
	 * @param array{start_utc?:int,date?:string} $to
	 * @return array{ok:bool,error?:string}
	 */
	private function rescheduleLocked( array $booking, array $to, string $by, int $now ): array {
		$id    = (int) $booking['id'];
		$terms = (array) $booking['terms'];

		if ( ! in_array( $booking['status'], array( 'confirmed', 'needs_reschedule' ), true ) ) {
			return array( 'ok' => false, 'error' => 'not_reschedulable' );
		}

		if ( 'guest' === $by ) {
			$max   = (int) ( $terms['reschedule']['max'] ?? 1 );
			$hours = (int) ( $terms['reschedule']['hours_before'] ?? 24 );
			$ref   = 'written' === $booking['kind'] ? (int) $booking['deadline_utc'] : (int) $booking['start_utc'];

			if ( (int) $booking['reschedule_count'] >= $max ) {
				return array( 'ok' => false, 'error' => 'reschedule_limit' );
			}

			if ( 'confirmed' === $booking['status'] && $now > $ref - $hours * 3600 ) {
				return array( 'ok' => false, 'error' => 'reschedule_too_late' );
			}
		}

		$gen = new SlotGenerator( $this->schedule );

		if ( 'written' === $booking['kind'] ) {
			$date = (string) ( $to['date'] ?? '' );

			if ( ! in_array( $date, $gen->writtenDates( $now ), true ) ) {
				return array( 'ok' => false, 'error' => 'date_unavailable' );
			}

			$deadline = $gen->writtenDeadline( $date );

			if ( null === $deadline || ! $this->slots->transferSeat( $id, $date, $this->writtenLimit(), $now ) ) {
				return array( 'ok' => false, 'error' => 'date_full' );
			}

			$terms['written']['date']         = $date;
			$terms['written']['deadline_utc'] = $deadline;
			$fields                           = array( 'written_date' => $date, 'deadline_utc' => $deadline );
		} else {
			$slot = $gen->match( (int) ( $to['start_utc'] ?? 0 ), $now );

			if ( null === $slot ) {
				return array( 'ok' => false, 'error' => 'time_unavailable' );
			}

			if ( ! $this->slots->transferUnits( $id, Grid::units( $slot['start'], $slot['block_end'] ), null, $now ) ) {
				return array( 'ok' => false, 'error' => 'time_taken' );
			}

			$terms['start_utc'] = $slot['start'];
			$terms['end_utc']   = $slot['end'];
			$fields             = array( 'start_utc' => $slot['start'], 'end_utc' => $slot['end'], 'block_end_utc' => $slot['block_end'] );
		}

		$seq    = (int) $booking['ics_sequence'] + 1;
		$fields = $fields + array(
			'terms'            => $terms,
			'ics_sequence'     => $seq,
			'reschedule_count' => (int) $booking['reschedule_count'] + ( 'guest' === $by ? 1 : 0 ),
			'reminder_sent_at' => null,
		);

		if ( ! $this->bookings->transition( $id, array( 'confirmed', 'needs_reschedule' ), 'confirmed', $fields ) ) {
			return array( 'ok' => false, 'error' => 'not_reschedulable' );
		}

		$this->notifier->queue( $id, 'customer_updated', $seq );
		$this->notifier->queue( $id, 'admin_rescheduled', $seq );

		return array( 'ok' => true );
	}

	/**
	 * Отмена встречи администратором. Деньги НЕ возвращаются автоматически,
	 * зачёт остаётся использованным.
	 *
	 * @param array<string,mixed> $booking
	 */
	public function cancel( array $booking, ?int $now = null ): bool {
		return $this->withLock(
			(int) $booking['id'],
			$now ?? time(),
			function ( array $b ): bool {
				$id = (int) $b['id'];

				if ( ! $this->bookings->transition( $id, array( 'held', 'confirmed', 'needs_reschedule' ), 'cancelled' ) ) {
					return false;
				}

				$this->releaseTime( $b );
				$this->notifier->queue( $id, 'customer_cancelled', (int) $b['ics_sequence'] + 1 );
				$this->bookings->update( $id, array( 'ics_sequence' => (int) $b['ics_sequence'] + 1 ) );

				return true;
			},
			false
		);
	}

	/** @param array<string,mixed> $booking */
	public function complete( array $booking, ?int $now = null ): bool {
		return $this->withLock(
			(int) $booking['id'],
			$now ?? time(),
			fn( array $b ): bool => $this->bookings->transition( (int) $b['id'], array( 'confirmed' ), 'completed' ),
			false
		);
	}

	// -----------------------------------------------------------------
	// Блокировка записи.

	/**
	 * Выполнить $fn над СВЕЖЕЙ строкой записи под блокировкой (до ~3 с ожидания).
	 * Не удалось взять блокировку — вернуть $busy: вызывающие (sync при следующем
	 * запросе, cron) повторят.
	 *
	 * @template T
	 * @param callable(array<string,mixed>):T $fn
	 * @param T                               $busy
	 * @return T
	 */
	private function withLock( int $id, int $now, callable $fn, mixed $busy ): mixed {
		$token    = null;
		$deadline = microtime( true ) + 3.0;

		do {
			$token = $this->bookings->acquireLock( $id, $now );

			if ( null !== $token ) {
				break;
			}

			usleep( random_int( 30000, 80000 ) );
		} while ( microtime( true ) < $deadline );

		if ( null === $token ) {
			return $busy;
		}

		try {
			$fresh = $this->bookings->get( $id );

			return null === $fresh ? $busy : $fn( $fresh );
		} finally {
			$this->bookings->releaseLock( $id, $token );
		}
	}

	// -----------------------------------------------------------------

	/** @param array<string,mixed> $booking */
	private function expire( array $booking, int $now, string $reason ): void {
		$id = (int) $booking['id'];

		if ( $this->bookings->transition( $id, array( 'held' ), 'expired', array( 'status_reason' => $reason ) ) ) {
			$this->releaseTime( $booking );
		}

		$this->credits->releaseReserved( $id, gmdate( 'Y-m-d H:i:s', $now ) );
	}

	/** @param array<string,mixed> $booking */
	private function releaseTime( array $booking ): void {
		if ( 'written' === $booking['kind'] ) {
			$this->slots->releaseSeat( (int) $booking['id'] );
		} else {
			$this->slots->releaseUnits( (int) $booking['id'] );
		}
	}

	private function writtenLimit(): int {
		return max( 0, (int) ( $this->settings['written_per_day'] ?? 2 ) );
	}

	/**
	 * Неизменяемые условия услуги, фиксируются в записи при резерве.
	 *
	 * @param array{start:int,end:int,block_end:int,date:string,local:string}|null $slot
	 * @param array<string,mixed> $price
	 * @param array<string,mixed>|null $credit
	 * @return array<string,mixed>
	 */
	public function terms( string $format, string $mode, ?array $slot, ?string $date, ?int $deadline, string $visitorTz, array $price, ?array $credit ): array {
		$f       = (array) ( $this->settings['formats'][ $format ] ?? array() );
		$written = in_array( $format, self::WRITTEN_FORMATS, true );

		if ( in_array( $format, array( 'telegram', 'whatsapp' ), true ) ) {
			$mode = in_array( $mode, array( 'text', 'call' ), true ) ? $mode : 'text';
		} else {
			$mode = $written ? 'written' : 'call';
		}

		return array(
			'format'       => $format,
			'mode'         => $mode,
			'initiator'    => (string) ( $f['initiator_text'] ?? '' ),
			'consultant_tz' => $this->schedule->tz->getName(),
			'visitor_tz'   => $visitorTz,
			'duration_min' => $written ? null : $this->schedule->duration,
			'break_min'    => $written ? null : $this->schedule->break,
			'start_utc'    => $slot['start'] ?? null,
			'end_utc'      => $slot['end'] ?? null,
			'written'      => $written ? array(
				'date'            => $date,
				'deadline_utc'    => $deadline,
				'business_days'   => $this->schedule->writtenBusinessDays,
				'scope'           => (string) ( $this->settings['written_scope'] ?? '' ),
				'followups'       => max( 0, (int) ( $this->settings['written_followups'] ?? 1 ) ),
				'followup_days'   => max( 1, (int) ( $this->settings['written_followup_days'] ?? 7 ) ),
			) : null,
			'price'        => array(
				'base'     => (string) ( $price['base'] ?? '' ),
				'credit'   => null === $credit ? '0' : (string) $credit['amount'],
				'item'     => (string) ( $price['item'] ?? '' ),
				'currency' => (string) ( $price['currency'] ?? '' ),
				'tax_mode' => (string) ( $price['tax_mode'] ?? '' ),
				'price_id' => $price['price_id'] ?? null,
			),
			'reschedule'   => array(
				'hours_before' => max( 0, (int) ( $this->settings['reschedule_hours'] ?? 24 ) ),
				'max'          => max( 0, (int) ( $this->settings['reschedule_max'] ?? 1 ) ),
			),
			'includes_instruction' => true,
		);
	}
}
