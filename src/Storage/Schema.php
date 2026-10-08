<?php
/**
 * Таблицы плагина. Создаются/обновляются через dbDelta при активации
 * и при смене DB_VERSION (проверка на admin_init / plugins_loaded).
 *
 * Принципы:
 *  - токены и секреты в БД только в виде sha256 (кроме csrf_secret,
 *    который нужен в открытом виде для HMAC и никогда не уходит в браузер);
 *  - снимок (snapshots) неизменяем после создания: меняются только
 *    locked_at и purged_at;
 *  - purchases.order_item_id UNIQUE — одна оплаченная позиция открывает
 *    ровно один снимок; (order_id, snapshot_id) UNIQUE — одна привязка
 *    снимка к заказу;
 *  - бухгалтерские записи EDD здесь не хранятся и не удаляются.
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Storage;

final class Schema {

	public const DB_VERSION = '3';
	public const OPTION     = 'bgdvzh_db_version';

	/** @return array<string,string> короткое имя => полное имя таблицы */
	public static function tables(): array {
		global $wpdb;

		$p = $wpdb->prefix . 'bgdvzh_';

		return array(
			'sessions'  => $p . 'sessions',
			'drafts'    => $p . 'drafts',
			'snapshots' => $p . 'snapshots',
			'purchases' => $p . 'purchases',
			'grants'    => $p . 'grants',
			'documents' => $p . 'documents',
			'templates' => $p . 'templates',
			'events'    => $p . 'events',
			// Консультации (DB_VERSION 3).
			'units'         => $p . 'units',
			'seats'         => $p . 'seats',
			'bookings'      => $p . 'bookings',
			'credits'       => $p . 'credits',
			'notifications' => $p . 'notifications',
			'messages'      => $p . 'messages',
		);
	}

	public static function table( string $name ): string {
		return self::tables()[ $name ];
	}

	/** @return list<string> */
	public static function ddl(): array {
		global $wpdb;

		$c = $wpdb->get_charset_collate();
		$t = self::tables();

		return array(
			// Гостевая (или пользовательская) сессия анкеты.
			"CREATE TABLE {$t['sessions']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				csrf_secret char(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				last_seen_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY user_id (user_id),
				KEY expires_at (expires_at)
			) $c;",
			// Изменяемый черновик: один на сессию.
			"CREATE TABLE {$t['drafts']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL,
				data longtext NOT NULL,
				step tinyint(3) unsigned NOT NULL DEFAULT 0,
				revision int(10) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY session_id (session_id),
				KEY updated_at (updated_at)
			) $c;",
			// Неизменяемый снимок: ответы + результат + собранная инструкция.
			"CREATE TABLE {$t['snapshots']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				session_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL,
				answers longtext NULL,
				result longtext NULL,
				preview longtext NULL,
				instruction longtext NULL,
				summary varchar(255) NOT NULL DEFAULT '',
				rules_version varchar(32) NOT NULL,
				templates_version varchar(32) NOT NULL,
				content_hash char(64) NOT NULL,
				created_at datetime NOT NULL,
				locked_at datetime NULL,
				purged_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY session_id (session_id),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) $c;",
			// Привязка снимка к позиции заказа EDD. Кэш состояния — только
			// для админки и запуска генерации; право доступа всегда
			// проверяется по живому состоянию EDD (AccessPolicy).
			"CREATE TABLE {$t['purchases']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				snapshot_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				order_item_id bigint(20) unsigned NOT NULL,
				download_id bigint(20) unsigned NOT NULL,
				price_id bigint(20) unsigned NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				session_id bigint(20) unsigned NOT NULL DEFAULT 0,
				state varchar(20) NOT NULL DEFAULT 'pending',
				state_reason varchar(64) NOT NULL DEFAULT '',
				item_total decimal(18,9) NOT NULL DEFAULT 0,
				refunded_total decimal(18,9) NOT NULL DEFAULT 0,
				receipt_link_sent tinyint(1) NOT NULL DEFAULT 0,
				kind varchar(16) NOT NULL DEFAULT 'instruction',
				booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				paid_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_item_id (order_item_id),
				UNIQUE KEY order_snapshot (order_id,snapshot_id),
				KEY snapshot_id (snapshot_id),
				KEY booking_id (booking_id),
				KEY order_id (order_id),
				KEY user_id (user_id),
				KEY state (state)
			) $c;",
			// Секретные ссылки и grant-cookie, ограниченные одной покупкой.
			"CREATE TABLE {$t['grants']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				purchase_id bigint(20) unsigned NOT NULL,
				snapshot_id bigint(20) unsigned NOT NULL,
				order_item_id bigint(20) unsigned NOT NULL,
				kind varchar(10) NOT NULL,
				token_hash char(64) NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				revoked_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY purchase_id (purchase_id),
				KEY expires_at (expires_at)
			) $c;",
			// Документ (HTML + PDF) и состояние задачи генерации.
			"CREATE TABLE {$t['documents']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				purchase_id bigint(20) unsigned NOT NULL,
				state varchar(16) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(255) NOT NULL DEFAULT '',
				locked_until datetime NULL,
				lock_token char(32) NOT NULL DEFAULT '',
				html longtext NULL,
				pdf longblob NULL,
				pdf_file varchar(255) NOT NULL DEFAULT '',
				pdf_sha256 char(64) NOT NULL DEFAULT '',
				generated_at datetime NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY purchase_id (purchase_id),
				KEY state (state)
			) $c;",
			// Версии шаблонов текстов (не автозагружаемые опции).
			"CREATE TABLE {$t['templates']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				version varchar(32) NOT NULL,
				data longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY version (version)
			) $c;",
			// Журнал событий оплаты и доступа (без персональных ответов и токенов).
			"CREATE TABLE {$t['events']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				purchase_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(48) NOT NULL,
				detail varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY purchase_id (purchase_id),
				KEY order_id (order_id)
			) $c;",
			// Занятые 5-минутные единицы времени консультаций.
			"CREATE TABLE {$t['units']} (
				unit_utc bigint(20) NOT NULL,
				booking_id bigint(20) unsigned NOT NULL,
				expires_at bigint(20) NULL,
				PRIMARY KEY  (unit_utc),
				KEY booking_id (booking_id),
				KEY expires_at (expires_at)
			) $c;",
			// Места письменных разборов по датам.
			"CREATE TABLE {$t['seats']} (
				local_date date NOT NULL,
				seat_no smallint(5) unsigned NOT NULL,
				booking_id bigint(20) unsigned NOT NULL,
				expires_at bigint(20) NULL,
				PRIMARY KEY  (local_date,seat_no),
				KEY booking_id (booking_id)
			) $c;",
			// Записи на консультацию.
			"CREATE TABLE {$t['bookings']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id char(32) NOT NULL,
				snapshot_id bigint(20) unsigned NOT NULL,
				session_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				format varchar(16) NOT NULL,
				mode varchar(16) NOT NULL DEFAULT '',
				kind varchar(8) NOT NULL,
				status varchar(20) NOT NULL,
				status_reason varchar(32) NOT NULL DEFAULT '',
				pre_dispute_status varchar(20) NOT NULL DEFAULT '',
				lock_token char(32) NOT NULL DEFAULT '',
				lock_until bigint(20) NULL,
				start_utc bigint(20) NULL,
				end_utc bigint(20) NULL,
				block_end_utc bigint(20) NULL,
				written_date date NULL,
				deadline_utc bigint(20) NULL,
				hold_until bigint(20) NOT NULL DEFAULT 0,
				contact varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				question text NULL,
				visitor_tz varchar(64) NOT NULL DEFAULT '',
				terms longtext NULL,
				meet_url varchar(255) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
				purchase_id bigint(20) unsigned NOT NULL DEFAULT 0,
				credit_purchase_id bigint(20) unsigned NOT NULL DEFAULT 0,
				credit_amount decimal(18,9) NOT NULL DEFAULT 0,
				credit_conflict tinyint(1) NOT NULL DEFAULT 0,
				ics_uid varchar(64) NOT NULL DEFAULT '',
				ics_sequence int(10) unsigned NOT NULL DEFAULT 0,
				reschedule_count int(10) unsigned NOT NULL DEFAULT 0,
				followups_used int(10) unsigned NOT NULL DEFAULT 0,
				reminder_sent_at datetime NULL,
				paid_at datetime NULL,
				admin_notes longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY status (status),
				KEY start_utc (start_utc),
				KEY snapshot_id (snapshot_id),
				KEY session_id (session_id),
				KEY order_id (order_id)
			) $c;",
			// Зачёт инструкции в консультацию: одна строка на покупку инструкции.
			"CREATE TABLE {$t['credits']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				instruction_purchase_id bigint(20) unsigned NOT NULL,
				booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(18,9) NOT NULL DEFAULT 0,
				currency varchar(8) NOT NULL DEFAULT '',
				tax_mode varchar(8) NOT NULL DEFAULT 'incl',
				state varchar(10) NOT NULL,
				hold_until bigint(20) NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY instruction_purchase_id (instruction_purchase_id),
				KEY booking_id (booking_id),
				KEY state (state)
			) $c;",
			// Журнал писем: UNIQUE (booking_id, type, seq) исключает повторную отправку.
			"CREATE TABLE {$t['notifications']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				booking_id bigint(20) unsigned NOT NULL,
				type varchar(40) NOT NULL,
				seq int(10) unsigned NOT NULL DEFAULT 0,
				recipient varchar(190) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT 'queued',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				sent_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY once (booking_id,type,seq),
				KEY status (status)
			) $c;",
			// История письменных ответов и заметок.
			"CREATE TABLE {$t['messages']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				booking_id bigint(20) unsigned NOT NULL,
				direction varchar(8) NOT NULL,
				subject varchar(255) NOT NULL DEFAULT '',
				body longtext NULL,
				author_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				mail_status varchar(10) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY booking_id (booking_id)
			) $c;",
		);
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Дубли (order_id, snapshot_id) нужно убрать до добавления UNIQUE KEY order_snapshot.
		self::dedupePurchases();

		foreach ( self::ddl() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/**
	 * Удаляет дубли покупок с одинаковой парой (order_id, snapshot_id),
	 * оставляя строку с наименьшим id; связанные grants и документы дублей
	 * удаляются, событие пишется в журнал. Если таблицы ещё нет — ничего не делает.
	 *
	 * @return int Сколько строк удалено.
	 */
	public static function dedupePurchases(): int {
		global $wpdb;

		$p = self::table( 'purchases' );

		$wpdb->suppress_errors( true );
		$groups = $wpdb->get_results( "SELECT order_id, snapshot_id, MIN(id) AS keep_id FROM {$p} GROUP BY order_id, snapshot_id HAVING COUNT(*) > 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( false );

		if ( ! is_array( $groups ) || empty( $groups ) ) {
			return 0;
		}

		$removed = 0;
		$events  = new EventRepository();

		foreach ( $groups as $g ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p} WHERE order_id = %d AND snapshot_id = %d AND id <> %d", (int) $g['order_id'], (int) $g['snapshot_id'], (int) $g['keep_id'] ) ); // phpcs:ignore WordPress.DB

			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				$id = (int) $id;
				$wpdb->delete( self::table( 'grants' ), array( 'purchase_id' => $id ), array( '%d' ) );
				$wpdb->delete( self::table( 'documents' ), array( 'purchase_id' => $id ), array( '%d' ) );
				$wpdb->delete( $p, array( 'id' => $id ), array( '%d' ) );
				$events->log( 'purchase_deduplicated', $id, (int) $g['order_id'], 'kept ' . (int) $g['keep_id'] );
				++$removed;
			}
		}

		return $removed;
	}

	/** Схема актуальна (таблицы консультаций созданы). */
	public static function ready(): bool {
		return function_exists( 'get_option' ) && (string) get_option( self::OPTION, '' ) === self::DB_VERSION;
	}

	public static function maybeUpgrade(): void {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}
}
