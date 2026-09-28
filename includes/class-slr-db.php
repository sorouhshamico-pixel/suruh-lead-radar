<?php
defined( 'ABSPATH' ) || exit;

class SLR_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'slr_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$visitors = self::table( 'visitors' );
		$events   = self::table( 'events' );
		$leads    = self::table( 'leads' );

		dbDelta( "CREATE TABLE $visitors (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uid varchar(64) NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			phone varchar(32) NOT NULL DEFAULT '',
			first_source varchar(100) NOT NULL DEFAULT '',
			first_medium varchar(100) NOT NULL DEFAULT '',
			first_campaign varchar(191) NOT NULL DEFAULT '',
			first_landing varchar(500) NOT NULL DEFAULT '',
			device varchar(20) NOT NULL DEFAULT '',
			contacts int(10) unsigned NOT NULL DEFAULT 0,
			leads int(10) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uid (uid),
			KEY phone (phone),
			KEY last_seen (last_seen)
		) $charset;" );

		dbDelta( "CREATE TABLE $events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL,
			placement varchar(40) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			target varchar(191) NOT NULL DEFAULT '',
			page_url varchar(500) NOT NULL DEFAULT '',
			page_path varchar(255) NOT NULL DEFAULT '',
			page_title varchar(255) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(191) NOT NULL DEFAULT '',
			click_id_type varchar(20) NOT NULL DEFAULT '',
			click_id varchar(255) NOT NULL DEFAULT '',
			referrer varchar(500) NOT NULL DEFAULT '',
			device varchar(20) NOT NULL DEFAULT '',
			ip_hash char(40) NOT NULL DEFAULT '',
			ua_hash char(40) NOT NULL DEFAULT '',
			is_repeat tinyint(1) NOT NULL DEFAULT 0,
			possible_repeat tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY visitor_id (visitor_id),
			KEY type_created (type,created_at),
			KEY created_at (created_at),
			KEY page_path (page_path(191)),
			KEY fingerprint (ip_hash,ua_hash)
		) $charset;" );

		dbDelta( "CREATE TABLE $leads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_id varchar(100) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			phone_normalized varchar(32) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			service varchar(191) NOT NULL DEFAULT '',
			message text NOT NULL,
			page_url varchar(500) NOT NULL DEFAULT '',
			page_title varchar(255) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(191) NOT NULL DEFAULT '',
			click_id_type varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'new',
			notes text NOT NULL,
			is_repeat tinyint(1) NOT NULL DEFAULT 0,
			repeat_of bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY visitor_id (visitor_id),
			KEY phone_normalized (phone_normalized),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;" );

		update_option( 'slr_db_version', SLR_DB_VERSION, false );

		if ( false === get_option( 'slr_settings' ) ) {
			add_option( 'slr_settings', SLR_Helpers::default_settings(), '', false );
		}
		if ( ! get_option( 'slr_salt' ) ) {
			add_option( 'slr_salt', wp_generate_password( 32, true, true ), '', false );
		}
		if ( ! wp_next_scheduled( 'slr_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'slr_daily_cleanup' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'slr_db_version' ) !== SLR_DB_VERSION ) {
			self::install();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'slr_daily_cleanup' );
	}

	/** Deletes click events older than the retention period. Leads are never auto-deleted. */
	public static function cleanup() {
		global $wpdb;
		$days = (int) SLR_Helpers::setting( 'retention_days' );
		if ( $days < 30 ) {
			return;
		}
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'events' ) . " WHERE created_at < %s AND type <> 'form'", $cutoff ) );
	}
}
