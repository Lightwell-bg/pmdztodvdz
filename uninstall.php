<?php
/**
 * Деинсталляция: таблицы и опции удаляются, только если в настройках
 * включено «Удалять данные при удалении плагина» (по умолчанию нет).
 * Заказы EDD не затрагиваются.
 *
 * @package Bginfo\PmzhDvzh
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bgdvzh_settings = get_option( 'bgdvzh_settings', array() );

if ( ! is_array( $bgdvzh_settings ) || empty( $bgdvzh_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

/*
 * Файлы PDF из приватного каталога удаляются до удаления таблиц (имена
 * берутся из documents.pdf_file). Каталог проверяется так же, как в
 * DocumentService::resolvePrivateDir(): реальный путь вне ABSPATH и uploads;
 * удаляются только файлы с именем <sha256>.pdf.
 */
if ( defined( 'BGINFO_PMZH_DVZH_PRIVATE_DIR' ) && '' !== (string) BGINFO_PMZH_DVZH_PRIVATE_DIR ) {
	$bgdvzh_dir = realpath( (string) BGINFO_PMZH_DVZH_PRIVATE_DIR );

	if ( false !== $bgdvzh_dir && is_dir( $bgdvzh_dir ) ) {
		$bgdvzh_uploads = wp_upload_dir( null, false );
		$bgdvzh_inside  = false;

		foreach ( array( ABSPATH, is_array( $bgdvzh_uploads ) && ! empty( $bgdvzh_uploads['basedir'] ) ? (string) $bgdvzh_uploads['basedir'] : '' ) as $bgdvzh_forbidden ) {
			$bgdvzh_root = '' === $bgdvzh_forbidden ? false : realpath( $bgdvzh_forbidden );

			if ( false !== $bgdvzh_root ) {
				$bgdvzh_p = rtrim( str_replace( '\\', '/', $bgdvzh_dir ), '/' ) . '/';
				$bgdvzh_r = rtrim( str_replace( '\\', '/', $bgdvzh_root ), '/' ) . '/';

				if ( 0 === strncasecmp( $bgdvzh_p, $bgdvzh_r, strlen( $bgdvzh_r ) ) ) {
					$bgdvzh_inside = true;
				}
			}
		}

		if ( ! $bgdvzh_inside ) {
			$bgdvzh_docs = $wpdb->get_col( 'SELECT DISTINCT pdf_file FROM `' . esc_sql( $wpdb->prefix . 'bgdvzh_documents' ) . "` WHERE pdf_file <> ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL

			foreach ( is_array( $bgdvzh_docs ) ? $bgdvzh_docs : array() as $bgdvzh_file ) {
				if ( 1 !== preg_match( '/^[0-9a-f]{64}\.pdf$/', (string) $bgdvzh_file ) ) {
					continue;
				}

				$bgdvzh_path = realpath( $bgdvzh_dir . DIRECTORY_SEPARATOR . $bgdvzh_file );

				if ( false !== $bgdvzh_path && 0 === strpos( $bgdvzh_path, $bgdvzh_dir . DIRECTORY_SEPARATOR ) && is_file( $bgdvzh_path ) ) {
					@unlink( $bgdvzh_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				}
			}
		}
	}
}

foreach ( array( 'sessions', 'drafts', 'snapshots', 'purchases', 'grants', 'documents', 'templates', 'events' ) as $bgdvzh_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'bgdvzh_' . $bgdvzh_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
}

delete_option( 'bgdvzh_settings' );
delete_option( 'bgdvzh_db_version' );

wp_clear_scheduled_hook( 'bgdvzh_cron' );
