<?php
/**
 * Таблицы консультаций для SQLite (упрощённые типы, те же ключи и UNIQUE,
 * что в Storage\Schema). Имена — с префиксом wp_bgdvzh_, как у Schema::table().
 *
 * @package Bginfo\PmzhDvzh
 */

declare(strict_types=1);

namespace Bginfo\PmzhDvzh\Tests\Support;

final class SqliteSchema {

	public static function create( PdoWpdb $db ): void {
		$p = $db->prefix . 'bgdvzh_';

		$db->exec_ddl( "CREATE TABLE {$p}units (unit_utc INTEGER NOT NULL PRIMARY KEY, booking_id INTEGER NOT NULL, expires_at INTEGER NULL)" );
		$db->exec_ddl( "CREATE TABLE {$p}seats (local_date TEXT NOT NULL, seat_no INTEGER NOT NULL, booking_id INTEGER NOT NULL, expires_at INTEGER NULL, PRIMARY KEY (local_date, seat_no))" );
		$db->exec_ddl(
			"CREATE TABLE {$p}bookings (
				id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, snapshot_id INTEGER NOT NULL, session_id INTEGER NOT NULL DEFAULT 0,
				user_id INTEGER NOT NULL DEFAULT 0, format TEXT NOT NULL, mode TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL, status TEXT NOT NULL,
				status_reason TEXT NOT NULL DEFAULT '', pre_dispute_status TEXT NOT NULL DEFAULT '', lock_token TEXT NOT NULL DEFAULT '', lock_until INTEGER NULL, start_utc INTEGER NULL, end_utc INTEGER NULL, block_end_utc INTEGER NULL, written_date TEXT NULL,
				deadline_utc INTEGER NULL, hold_until INTEGER NOT NULL DEFAULT 0, contact TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
				question TEXT NULL, visitor_tz TEXT NOT NULL DEFAULT '', terms TEXT NULL, meet_url TEXT NOT NULL DEFAULT '', order_id INTEGER NOT NULL DEFAULT 0,
				order_item_id INTEGER NOT NULL DEFAULT 0, purchase_id INTEGER NOT NULL DEFAULT 0, credit_purchase_id INTEGER NOT NULL DEFAULT 0,
				credit_amount NUMERIC NOT NULL DEFAULT 0, credit_conflict INTEGER NOT NULL DEFAULT 0, ics_uid TEXT NOT NULL DEFAULT '',
				ics_sequence INTEGER NOT NULL DEFAULT 0, reschedule_count INTEGER NOT NULL DEFAULT 0, followups_used INTEGER NOT NULL DEFAULT 0,
				reminder_sent_at TEXT NULL, paid_at TEXT NULL, admin_notes TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
			)"
		);
		$db->exec_ddl(
			"CREATE TABLE {$p}credits (
				id INTEGER PRIMARY KEY AUTOINCREMENT, instruction_purchase_id INTEGER NOT NULL UNIQUE, booking_id INTEGER NOT NULL DEFAULT 0,
				amount NUMERIC NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT '', tax_mode TEXT NOT NULL DEFAULT 'incl', state TEXT NOT NULL,
				hold_until INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL
			)"
		);
		$db->exec_ddl(
			"CREATE TABLE {$p}notifications (
				id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER NOT NULL, type TEXT NOT NULL, seq INTEGER NOT NULL DEFAULT 0,
				recipient TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0,
				last_error TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, sent_at TEXT NULL, UNIQUE (booking_id, type, seq)
			)"
		);
		$db->exec_ddl(
			"CREATE TABLE {$p}messages (
				id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER NOT NULL, direction TEXT NOT NULL, subject TEXT NOT NULL DEFAULT '',
				body TEXT NULL, author_id INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, mail_status TEXT NOT NULL DEFAULT ''
			)"
		);
		$db->exec_ddl(
			"CREATE TABLE {$p}snapshots (
				id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, session_id INTEGER NOT NULL, user_id INTEGER NOT NULL DEFAULT 0,
				status TEXT NOT NULL DEFAULT 'ok', summary TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT '2026-06-10 06:00:00',
				locked_at TEXT NULL, purged_at TEXT NULL
			)"
		);
		$db->exec_ddl(
			"CREATE TABLE {$p}purchases (
				id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_id INTEGER NOT NULL, order_id INTEGER NOT NULL, order_item_id INTEGER NOT NULL UNIQUE,
				download_id INTEGER NOT NULL, price_id INTEGER NULL, user_id INTEGER NOT NULL DEFAULT 0, session_id INTEGER NOT NULL DEFAULT 0,
				state TEXT NOT NULL DEFAULT 'pending', state_reason TEXT NOT NULL DEFAULT '', item_total NUMERIC NOT NULL DEFAULT 0,
				refunded_total NUMERIC NOT NULL DEFAULT 0, receipt_link_sent INTEGER NOT NULL DEFAULT 0, kind TEXT NOT NULL DEFAULT 'instruction',
				booking_id INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, paid_at TEXT NULL,
				UNIQUE (order_id, snapshot_id)
			)"
		);
	}
}
