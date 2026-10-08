<?php
/**
 * Напоминания о синхронных консультациях.
 *
 * Подключается к действию `bgdvzh_consult_tick` (BookingCron, каждые 5 минут):
 * подтверждённые записи, у которых начало в окне (сейчас, сейчас + reminder_hours]
 * и напоминание ещё не ставилось, получают письмо customer_reminder
 * (seq = ics_sequence, то есть после переноса уйдёт новое напоминание).
 * Повторная отправка неудавшихся писем — это Notifier::retryPending() в том же
 * проходе cron, здесь она не дублируется. Если начало уже наступило или
 * прошло, напоминание не ставится.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Storage\BookingRepository;

final class Reminders {

	/** @param array<string,mixed> $settings Настройки консультаций (reminder_hours). */
	public function __construct( private BookingRepository $bookings, private Notifier $notifier, private array $settings ) {
	}

	/** Подключить к проходу cron. */
	public function register(): void {
		add_action( BookingCron::HOOK_TICK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * @param mixed $now Unix-время прохода.
	 * @return int Сколько напоминаний поставлено в очередь.
	 */
	public function run( $now ): int {
		$now   = (int) $now;
		$hours = max( 1, (int) ( $this->settings['reminder_hours'] ?? 24 ) );
		$n     = 0;

		foreach ( $this->bookings->dueReminders( $now, $now + $hours * 3600, 100 ) as $b ) {
			// Страховка поверх запроса: начавшиеся и прошедшие встречи не напоминаем.
			if ( (int) $b['start_utc'] <= $now || 'confirmed' !== (string) $b['status'] ) {
				continue;
			}

			$this->notifier->queue( (int) $b['id'], 'customer_reminder', (int) $b['ics_sequence'] );
			$this->bookings->update( (int) $b['id'], array( 'reminder_sent_at' => gmdate( 'Y-m-d H:i:s', $now ) ) );
			++$n;
		}

		return $n;
	}
}
