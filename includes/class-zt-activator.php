<?php
/**
 * Aktivasyon / deaktivasyon: veritabanı tabloları, varsayılan ayarlar, cron kurulumu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Activator {

	public static function activate() {
		self::create_tables();
		add_option( 'zt_settings', ZT_Settings::defaults() );
		add_option( 'zt_db_version', ZT_VERSION );
		ZT_Cron::schedule();
		ZT_Time::sync(); // ilk gerçek zaman ölçümü — sonraki her kayıt bunu kullanır.
	}

	public static function deactivate() {
		ZT_Cron::unschedule();
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$visits          = $wpdb->prefix . 'zt_visits';
		$geo             = $wpdb->prefix . 'zt_geo';
		$alerts          = $wpdb->prefix . 'zt_alerts';
		$heat            = $wpdb->prefix . 'zt_heat';

		dbDelta( "CREATE TABLE {$visits} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visited_at DATETIME NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(500) NOT NULL DEFAULT '',
			page_title VARCHAR(255) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			page_type VARCHAR(20) NOT NULL DEFAULT '',
			referrer VARCHAR(500) NOT NULL DEFAULT '',
			referrer_domain VARCHAR(190) NOT NULL DEFAULT '',
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			device VARCHAR(20) NOT NULL DEFAULT '',
			browser VARCHAR(50) NOT NULL DEFAULT '',
			browser_version VARCHAR(20) NOT NULL DEFAULT '',
			os VARCHAR(50) NOT NULL DEFAULT '',
			is_bot TINYINT(1) NOT NULL DEFAULT 0,
			bot_name VARCHAR(100) NOT NULL DEFAULT '',
			utm_source VARCHAR(100) NOT NULL DEFAULT '',
			utm_medium VARCHAR(100) NOT NULL DEFAULT '',
			utm_campaign VARCHAR(150) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY visited_at (visited_at),
			KEY ip (ip),
			KEY is_bot (is_bot),
			KEY referrer_domain (referrer_domain),
			KEY device (device),
			KEY utm_campaign (utm_campaign)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$geo} (
			ip VARCHAR(45) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			country_code VARCHAR(2) NOT NULL DEFAULT '',
			country VARCHAR(100) NOT NULL DEFAULT '',
			region VARCHAR(100) NOT NULL DEFAULT '',
			city VARCHAR(100) NOT NULL DEFAULT '',
			isp VARCHAR(150) NOT NULL DEFAULT '',
			org VARCHAR(150) NOT NULL DEFAULT '',
			timezone VARCHAR(64) NOT NULL DEFAULT '',
			lat DOUBLE NOT NULL DEFAULT 0,
			lon DOUBLE NOT NULL DEFAULT 0,
			is_proxy TINYINT(1) NOT NULL DEFAULT 0,
			is_hosting TINYINT(1) NOT NULL DEFAULT 0,
			looked_up_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (ip),
			KEY status (status),
			KEY country_code (country_code)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$alerts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT '',
			severity VARCHAR(10) NOT NULL DEFAULT 'info',
			title VARCHAR(150) NOT NULL DEFAULT '',
			message VARCHAR(255) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			seen TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY seen (seen),
			KEY type (type)
		) {$charset_collate};" );

		dbDelta( "CREATE TABLE {$heat} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			url VARCHAR(190) NOT NULL DEFAULT '',
			type VARCHAR(10) NOT NULL DEFAULT 'click',
			x SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			y SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY url (url),
			KEY created_at (created_at)
		) {$charset_collate};" );
	}
}
