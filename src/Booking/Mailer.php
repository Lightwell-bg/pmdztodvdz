<?php
/**
 * Отправитель писем по записям на консультацию (plain text, wp_mail).
 *
 * Подключается к Notifier как отправитель: callable( array $notification, array $booking ): true|string.
 * Текст письма посетителю строится только из строки записи и её замороженных
 * условий (terms): позднейшие правки настроек уже оформленных записей не
 * меняют. Заметки администратора в письма не попадают.
 *
 * Безопасность заголовков: тема и значения заголовков очищаются от CR/LF и
 * управляющих символов, получатель обязан быть корректным одиночным email.
 *
 * Результат: true — отправлено; строка — ошибка (Notifier пометит failed и
 * повторит); строка с префиксом `skip:` — письмо больше не актуально
 * (Notifier пометит skipped).
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Booking;

use Bginfo\PmzhDvzh\Http\ConsultController;
use Bginfo\PmzhDvzh\Storage\MessageRepository;
use Bginfo\PmzhDvzh\Support\Clock;

final class Mailer {

	/** Типы писем посетителю, к которым прикладывается .ics (синхронные форматы). */
	private const ICS_TYPES = array( 'customer_confirmation', 'customer_confirmation_resend', 'customer_updated', 'customer_cancelled' );

	/** Допустимые статусы записи на момент отправки письма посетителю. */
	private const ALLOWED_STATUSES = array(
		'customer_confirmation'        => array( 'confirmed', 'needs_reschedule' ),
		'customer_confirmation_resend' => array( 'confirmed', 'needs_reschedule' ),
		'customer_updated'             => array( 'confirmed' ),
		'customer_cancelled'           => array( 'cancelled', 'refunded' ),
		'customer_meet_link'           => array( 'confirmed' ),
		'customer_reminder'            => array( 'confirmed' ),
		'customer_written_answer'      => array( 'confirmed', 'completed' ),
	);

	private const SUBJECTS = array(
		'customer_confirmation'        => 'Запись на консультацию подтверждена',
		'customer_confirmation_resend' => 'Запись на консультацию: подтверждение',
		'customer_updated'             => 'Консультация: данные записи обновлены',
		'customer_cancelled'           => 'Консультация отменена',
		'customer_meet_link'           => 'Ссылка на Google Meet для консультации',
		'customer_reminder'            => 'Напоминание о консультации',
		'customer_written_answer'      => 'Письменный ответ на ваш вопрос',
		'admin_new_paid'               => '[ПМЖ → ДВЖ] Новая оплаченная консультация',
		'admin_time_conflict'          => '[ПМЖ → ДВЖ] Оплата получена, время занято: нужен перенос',
		'admin_credit_conflict'        => '[ПМЖ → ДВЖ] Конфликт зачёта инструкции',
		'admin_refunded'               => '[ПМЖ → ДВЖ] Возврат по консультации',
		'admin_disputed'               => '[ПМЖ → ДВЖ] Спор по оплате консультации',
		'admin_rescheduled'            => '[ПМЖ → ДВЖ] Консультация перенесена',
		'admin_paid_after_cancel'      => '[ПМЖ → ДВЖ] Оплата отменённой консультации',
		'admin_mail_failed'            => '[ПМЖ → ДВЖ] Не удалось отправить письмо по консультации',
	);

	/** @var callable(array<string,mixed>):array{instruction:string,manage:string} */
	private $links;

	/** @var callable(int):string */
	private $adminUrl;

	/** @var callable|null fn(string $to, string $subject, string $body, list<string> $headers, list<string> $attachments): true|string */
	private $transport;

	/** @var callable|null fn():string Адрес администратора для строки «напишите на …». */
	private $adminEmail;

	/**
	 * @param callable(array<string,mixed>):array{instruction:string,manage:string} $links    Ссылки #dvzh-k и #dvzh-b (пустая строка — нет).
	 * @param callable(int):string                                                  $adminUrl Адрес карточки записи в админке.
	 */
	public function __construct( callable $links, callable $adminUrl, private ?MessageRepository $messages = null, ?callable $transport = null, ?callable $adminEmail = null ) {
		$this->links      = $links;
		$this->adminUrl   = $adminUrl;
		$this->transport  = $transport;
		$this->adminEmail = $adminEmail;
	}

	/**
	 * Отправитель для Notifier.
	 *
	 * @param array<string,mixed> $notification
	 * @param array<string,mixed> $booking
	 * @return true|string
	 */
	public function __invoke( array $notification, array $booking ) {
		$mail = $this->compose( $notification, $booking );

		if ( is_string( $mail ) ) {
			return $mail;
		}

		$to = (string) $mail['to'];

		if ( ! self::validRecipient( $to ) ) {
			return 'invalid recipient';
		}

		$files = array();
		$tmp   = array();

		if ( null !== $mail['ics'] && '' !== $mail['ics'] ) {
			$path = self::writeTemp( (string) $mail['ics'] );

			if ( null !== $path ) {
				$files[] = $path;
				$tmp[]   = $path;
			}
		}

		try {
			$result = $this->deliver( $to, (string) $mail['subject'], (string) $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ), $files );
		} finally {
			foreach ( $tmp as $path ) {
				if ( is_file( $path ) ) {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- временный файл.
				}
			}
		}

		if ( 'customer_written_answer' === $notification['type'] && null !== $this->messages && ! empty( $mail['message_id'] ) ) {
			$this->messages->setMailStatus( (int) $mail['message_id'], true === $result ? 'sent' : 'failed' );
		}

		return $result;
	}

	/**
	 * Собрать письмо без отправки.
	 *
	 * @param array<string,mixed> $n Строка notifications.
	 * @param array<string,mixed> $b Строка bookings.
	 * @return array{to:string,subject:string,body:string,ics:?string,message_id?:int}|string Письмо либо строка ошибки/skip.
	 */
	public function compose( array $n, array $b ) {
		$type = (string) ( $n['type'] ?? '' );

		if ( ! isset( self::SUBJECTS[ $type ] ) ) {
			return 'unknown type';
		}

		$to = trim( (string) ( $n['recipient'] ?? '' ) );

		if ( 0 === strpos( $type, 'admin_' ) ) {
			return array(
				'to'      => $to,
				'subject' => self::headerValue( self::SUBJECTS[ $type ] ),
				'body'    => $this->adminBody( $type, $n, $b ),
				'ics'     => null,
			);
		}

		// Письмо посетителю: адрес берётся из записи, а не из журнала.
		$to = trim( (string) ( $b['email'] ?? $to ) );

		if ( 'customer_reminder' === $type && ( 'confirmed' !== (string) $b['status'] || (int) $b['start_utc'] <= Clock::timestamp() ) ) {
			return 'skip: reminder is no longer relevant';
		}

		// Письмо уходит только пока статус записи ему соответствует (задержка очереди,
		// возврат или отмена между постановкой и отправкой не должны давать устаревшее письмо).
		if ( ! in_array( (string) $b['status'], self::ALLOWED_STATUSES[ $type ] ?? array(), true ) ) {
			return 'skip: booking status does not match the notification';
		}

		$subject = self::SUBJECTS[ $type ];
		$extra   = array();

		// Оплата пришла, но время занято: тема не должна обещать подтверждённую запись.
		if ( in_array( $type, array( 'customer_confirmation', 'customer_confirmation_resend' ), true ) && 'needs_reschedule' === (string) $b['status'] ) {
			$subject = 'Оплата консультации получена: согласуем новое время';
		}

		if ( 'customer_meet_link' === $type && '' === (string) $b['meet_url'] ) {
			return 'skip: meet link is not set';
		}

		if ( 'customer_written_answer' === $type ) {
			$msg = null === $this->messages ? null : $this->messages->nthOut( (int) $b['id'], (int) $n['seq'] );

			if ( null === $msg ) {
				return 'skip: message not found';
			}

			$extra['message']    = $msg;
			$extra['message_id'] = (int) $msg['id'];

			if ( '' !== trim( (string) $msg['subject'] ) ) {
				$subject = (string) $msg['subject'];
			}
		}

		$ics = null;

		if ( in_array( $type, self::ICS_TYPES, true ) && 'sync' === (string) $b['kind'] && 'needs_reschedule' !== (string) $b['status'] ) {
			$forIcs = $b;

			if ( 'customer_cancelled' === $type ) {
				// SEQUENCE отмены не меньше номера письма: иначе календарь проигнорирует отмену.
				$forIcs['ics_sequence'] = max( (int) $b['ics_sequence'], (int) $n['seq'] );

				if ( ! in_array( (string) $b['status'], array( 'cancelled', 'refunded' ), true ) ) {
					$forIcs['status'] = 'cancelled';
				}
			}

			$built = IcsBuilder::build( $forIcs );
			$ics   = '' === $built ? null : $built;
		}

		$mail = array(
			'to'      => $to,
			'subject' => self::headerValue( $subject ),
			'body'    => $this->customerBody( $type, $n, $b, $extra ),
			'ics'     => $ics,
		);

		if ( isset( $extra['message_id'] ) ) {
			$mail['message_id'] = $extra['message_id'];
		}

		return $mail;
	}

	// -----------------------------------------------------------------
	// Тексты.

	/**
	 * @param array<string,mixed> $n
	 * @param array<string,mixed> $b
	 * @param array<string,mixed> $extra
	 */
	private function customerBody( string $type, array $n, array $b, array $extra ): string {
		$terms    = (array) $b['terms'];
		$status   = (string) $b['status'];
		$written  = 'written' === (string) $b['kind'];
		$needMove = 'needs_reschedule' === $status;
		$closed   = 'customer_cancelled' === $type || in_array( $status, array( 'cancelled', 'refunded' ), true );
		$lines    = array( 'Здравствуйте!', '' );

		$lines[] = $this->lead( $type, $needMove, $written );
		$lines[] = '';

		if ( 'customer_written_answer' === $type ) {
			$lines[] = self::clean( (string) ( $extra['message']['body'] ?? '' ), 20000 );
			$lines[] = '';
			$lines[] = '—';
			$lines[] = $this->followupLine( $terms );

			return $this->finish( $lines, $b, $type, $closed );
		}

		$lines[] = 'Способ: ' . $this->formatLabel( $b );

		if ( '' !== (string) ( $terms['initiator'] ?? '' ) ) {
			$lines[] = self::clean( (string) $terms['initiator'], 300 );
		}

		if ( 'customer_meet_link' === $type || ( 'meet' === (string) $b['format'] && ! $closed ) ) {
			$url = (string) $b['meet_url'];
			$lines[] = '' !== $url ? 'Ссылка на встречу (Google Meet): ' . $url : 'Ссылка на Google Meet будет отправлена отдельно.';
		}

		if ( ! $needMove ) {
			array_push( $lines, ...$this->timeLines( $b, $terms, $written ) );
		}

		if ( $written ) {
			$w = (array) ( $terms['written'] ?? array() );

			if ( '' !== (string) ( $w['scope'] ?? '' ) ) {
				$lines[] = 'Что входит: ' . self::clean( (string) $w['scope'], 300 ) . '; ' . $this->followupLine( $terms, false );
			}
		} elseif ( ! empty( $terms['duration_min'] ) ) {
			$lines[] = 'Длительность: ' . (int) $terms['duration_min'] . ' мин.';
		}

		if ( '' !== (string) $b['contact'] ) {
			$lines[] = 'Ваш контакт: ' . self::clean( (string) $b['contact'], 190 );
		}

		if ( '' !== trim( (string) $b['question'] ) ) {
			$lines[] = 'Ваш вопрос: ' . self::clean( (string) $b['question'], 2000 );
		}

		return $this->finish( $lines, $b, $type, $closed, $terms );
	}

	/**
	 * Хвост письма: ссылки и условия переноса.
	 *
	 * @param list<string>        $lines
	 * @param array<string,mixed> $b
	 * @param array<string,mixed> $terms
	 */
	private function finish( array $lines, array $b, string $type, bool $closed, array $terms = array() ): string {
		if ( ! $closed ) {
			$links = (array) call_user_func( $this->links, $b );

			if ( ! empty( $links['instruction'] ) ) {
				$lines[] = '';
				$lines[] = 'Ваша персональная инструкция (страница и PDF): ' . self::clean( (string) $links['instruction'], 500 );
			}

			if ( ! empty( $links['manage'] ) ) {
				$lines[] = ( 'written' === (string) ( $b['kind'] ?? '' ) ? 'Ваша запись (статус): ' : 'Ваша запись (статус, файл календаря): ' ) . self::clean( (string) $links['manage'], 500 );
			}

			if ( 'customer_written_answer' !== $type && ! empty( $terms ) ) {
				$lines[] = '';
				$lines[] = $this->rescheduleLine( $terms, 'written' === (string) $b['kind'] );
			}
		}

		$lines[] = '';
		$lines[] = 'Если нужно что-то уточнить, просто ответьте на это письмо.';

		return implode( "\n", $lines ) . "\n";
	}

	private function lead( string $type, bool $needMove, bool $written ): string {
		if ( $needMove && in_array( $type, array( 'customer_confirmation', 'customer_confirmation_resend' ), true ) ) {
			return 'Оплата получена, но выбранное время закрепить не удалось. Мы свяжемся с вами для выбора нового времени — оплата сохраняется. Можно также ответить на это письмо.';
		}

		return match ( $type ) {
			'customer_confirmation', 'customer_confirmation_resend' => 'Оплата получена, запись на консультацию подтверждена.',
			'customer_updated'        => $written ? 'Данные записи обновлены, срок письменного ответа указан ниже.' : 'Данные записи обновлены, новое время указано ниже. Файл календаря во вложении обновит событие.',
			'customer_cancelled'      => 'Запись на консультацию отменена. Если у вас остались вопросы по оплате, ответьте на это письмо.',
			'customer_meet_link'      => 'Консультант прислал ссылку на встречу.',
			'customer_reminder'       => 'Напоминаем о предстоящей консультации.',
			'customer_written_answer' => 'Письменный ответ консультанта по вашей анкете:',
			default                   => '',
		};
	}

	/**
	 * Время в поясе посетителя и в Болгарии либо срок письменного ответа.
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,mixed> $terms
	 * @return list<string>
	 */
	private function timeLines( array $b, array $terms, bool $written ): array {
		$consultant = self::zone( (string) ( $terms['consultant_tz'] ?? '' ), 'Europe/Sofia' );
		$visitor    = self::zone( (string) ( $b['visitor_tz'] ?? '' ), $consultant->getName() );
		$out        = array();

		if ( $written ) {
			$w        = (array) ( $terms['written'] ?? array() );
			$deadline = (int) ( $b['deadline_utc'] ?? $w['deadline_utc'] ?? 0 );

			if ( ! empty( $w['date'] ) ) {
				$out[] = 'Выбранная дата: ' . self::clean( (string) $w['date'], 10 );
			}

			if ( $deadline > 0 ) {
				$out[] = 'Ответ придёт не позднее: ' . self::stamp( $deadline, $consultant ) . ' (' . $consultant->getName() . ')';

				if ( $visitor->getName() !== $consultant->getName() ) {
					$out[] = 'Это ' . self::stamp( $deadline, $visitor ) . ' по вашему времени (' . $visitor->getName() . ')';
				}
			}

			return $out;
		}

		$start = (int) ( $b['start_utc'] ?? 0 );

		if ( $start <= 0 ) {
			return $out;
		}

		if ( $visitor->getName() === $consultant->getName() ) {
			$out[] = 'Время: ' . self::stamp( $start, $consultant ) . ' (' . $consultant->getName() . ', Болгария)';

			return $out;
		}

		$out[] = 'Ваше время: ' . self::stamp( $start, $visitor ) . ' (' . $visitor->getName() . ')';
		$out[] = 'Время в Болгарии: ' . self::stamp( $start, $consultant ) . ' (' . $consultant->getName() . ')';

		return $out;
	}

	/** @param array<string,mixed> $terms */
	private function rescheduleLine( array $terms, bool $written ): string {
		$mail = null === $this->adminEmail ? '' : (string) call_user_func( $this->adminEmail );
		$mail = self::validRecipient( $mail ) ? $mail : '';

		return 'Чтобы перенести или отменить запись, ответьте на это письмо' . ( '' !== $mail ? ' или напишите на ' . $mail : '' ) . '.';
	}

	/** @param array<string,mixed> $terms */
	private function followupLine( array $terms, bool $withLead = true ): string {
		$w     = (array) ( $terms['written'] ?? array() );
		$n     = max( 0, (int) ( $w['followups'] ?? 0 ) );
		$days  = max( 1, (int) ( $w['followup_days'] ?? 7 ) );
		$daysW = self::plural( $days, 'день', 'дня', 'дней' );

		if ( 0 === $n ) {
			return $withLead ? 'Дополнительные уточнения условиями не предусмотрены.' : 'уточнения не предусмотрены';
		}

		$what = 1 === $n ? 'одно уточнение' : $n . ' ' . self::plural( $n, 'уточнение', 'уточнения', 'уточнений' );

		return ( $withLead ? 'Включено: ' : '' ) . $what . ' в течение ' . $days . ' ' . $daysW . ' после срока ответа';
	}

	/**
	 * @param array<string,mixed> $n
	 * @param array<string,mixed> $b
	 */
	private function adminBody( string $type, array $n, array $b ): string {
		$terms   = (array) $b['terms'];
		$tz      = self::zone( (string) ( $terms['consultant_tz'] ?? '' ), 'Europe/Sofia' );
		$written = 'written' === (string) $b['kind'];
		$when    = '—';

		if ( $written && (int) $b['deadline_utc'] > 0 ) {
			$when = 'срок ответа до ' . self::stamp( (int) $b['deadline_utc'], $tz ) . ' (' . $tz->getName() . ')';
		} elseif ( (int) $b['start_utc'] > 0 ) {
			$when = self::stamp( (int) $b['start_utc'], $tz ) . ' (' . $tz->getName() . ')';
		}

		$lines = array(
			'Запись № ' . (int) $b['id'] . ': ' . $this->formatLabel( $b ) . ', статус «' . self::clean( (string) $b['status'], 20 ) . '».',
			'Время: ' . $when . '.',
		);

		if ( 'admin_mail_failed' === $type ) {
			$lines[] = 'Не удалось отправить письмо (уведомление № ' . (int) $n['seq'] . ') после нескольких попыток. Откройте карточку записи и нажмите «Повторить» либо проверьте почту сайта.';
		} elseif ( 'admin_time_conflict' === $type ) {
			$lines[] = 'Посетитель оплатил, но время уже занято. Выберите ему новое время в карточке записи.';
		} elseif ( 'admin_credit_conflict' === $type ) {
			$lines[] = 'Зачёт стоимости инструкции использован повторно либо исходная инструкция возвращена. Оплата консультации и доступ сохранены, решите вопрос вручную.';
		} elseif ( 'admin_paid_after_cancel' === $type ) {
			$lines[] = 'Заказ по отменённой консультации оказался оплачен. Время не восстановлено: решите вручную, вернуть деньги или согласовать новое время.';
		} elseif ( 'admin_refunded' === $type ) {
			$lines[] = 'По позиции консультации оформлен полный возврат, место освобождено.';
		} elseif ( 'admin_disputed' === $type ) {
			$lines[] = 'По оплате консультации открыт спор. Место удержано, зачёт заблокирован.';
		}

		$lines[] = '';
		$lines[] = 'Карточка записи: ' . (string) call_user_func( $this->adminUrl, (int) $b['id'] );

		return implode( "\n", $lines ) . "\n";
	}

	/** @param array<string,mixed> $b */
	private function formatLabel( array $b ): string {
		$format = (string) $b['format'];
		$label  = ConsultController::LABELS[ $format ] ?? $format;
		$mode   = (string) $b['mode'];

		if ( in_array( $format, array( 'telegram', 'whatsapp' ), true ) ) {
			$label .= 'call' === $mode ? ', звонок' : ', переписка';
		}

		return $label;
	}

	// -----------------------------------------------------------------
	// Утилиты.

	/** Значение заголовка/темы без CR/LF и управляющих символов. */
	public static function headerValue( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );

		return mb_substr( trim( $value ), 0, 200 );
	}

	/** Один корректный адрес без переводов строк и списков. */
	public static function validRecipient( string $to ): bool {
		if ( '' === $to || strlen( $to ) > 190 || 1 === preg_match( '/[\x00-\x1F\x7F,;<>\s]/', $to ) ) {
			return false;
		}

		return function_exists( 'is_email' ) ? false !== is_email( $to ) : false !== filter_var( $to, FILTER_VALIDATE_EMAIL );
	}

	/** Текст письма: без управляющих символов (кроме перевода строки), длина ограничена. */
	public static function clean( string $text, int $max ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		return mb_substr( $text, 0, $max );
	}

	/** Дата и время в поясе с аббревиатурой: 11.06.2026 10:00 EEST. */
	public static function stamp( int $ts, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'd.m.Y H:i T' );
	}

	private static function zone( string $name, string $fallback ): \DateTimeZone {
		try {
			return new \DateTimeZone( '' === $name ? $fallback : $name );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( $fallback );
		}
	}

	public static function plural( int $n, string $one, string $few, string $many ): string {
		$n10  = $n % 10;
		$n100 = $n % 100;

		if ( 1 === $n10 && 11 !== $n100 ) {
			return $one;
		}

		return ( $n10 >= 2 && $n10 <= 4 && ( $n100 < 12 || $n100 > 14 ) ) ? $few : $many;
	}

	/** Временный файл .ics; null, если создать не удалось. */
	private static function writeTemp( string $ics ): ?string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			return null;
		}

		$tmp = wp_tempnam( 'consultation.ics' );

		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return null;
		}

		$path = (string) preg_replace( '/\.tmp$/', '', $tmp ) . '.ics';

		if ( $path !== $tmp && @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$tmp = $path;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- временный файл для вложения.
		if ( false === file_put_contents( $tmp, $ics ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

			return null;
		}

		return $tmp;
	}

	/**
	 * @param list<string> $headers
	 * @param list<string> $files
	 * @return true|string
	 */
	private function deliver( string $to, string $subject, string $body, array $headers, array $files ) {
		if ( null !== $this->transport ) {
			return call_user_func( $this->transport, $to, $subject, $body, $headers, $files );
		}

		if ( ! function_exists( 'wp_mail' ) ) {
			return 'wp_mail is not available';
		}

		$error   = '';
		$capture = static function ( $err ) use ( &$error ): void {
			if ( is_object( $err ) && method_exists( $err, 'get_error_message' ) ) {
				$error = (string) $err->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $capture );

		try {
			$ok = wp_mail( $to, $subject, $body, $headers, $files );
		} finally {
			remove_action( 'wp_mail_failed', $capture );
		}

		return $ok ? true : ( '' !== $error ? 'wp_mail: ' . mb_substr( $error, 0, 200 ) : 'wp_mail returned false' );
	}
}
