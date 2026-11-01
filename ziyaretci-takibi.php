<?php
/**
 * Plugin Name:       Ziyaretçi Takibi
 * Plugin URI:        https://szksoft.com
 * Description:       Gelişmiş ziyaretçi takibi: konum + dünya haritası, canlı izleme, UTM kampanya, karşılaştırmalı raporlar, yeni-vs-dönen, huni analizi, gerçek zamanlı uyarılar, IP engelleme (uygulama + sunucu düzeyi) ve modern bir istatistik paneli.
 * Version:           1.9.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            SZK Soft
 * License:           GPL-2.0-or-later
 * Text Domain:       ziyaretci-takibi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZT_VERSION', '1.9.3' );
define( 'ZT_PLUGIN_FILE', __FILE__ );
define( 'ZT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ZT_PLUGIN_DIR . 'includes/class-zt-settings.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-time.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-activator.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-ua-parser.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-geo.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-tracker.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-rest.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-db.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-alerts.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-server-rules.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-pdf.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-report.php';
require_once ZT_PLUGIN_DIR . 'includes/class-zt-cron.php';

register_activation_hook( __FILE__, array( 'ZT_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZT_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', 'zt_boot' );

function zt_boot() {
	ZT_Cron::init();
	ZT_Tracker::init();
	ZT_REST::init();

	if ( is_admin() ) {
		require_once ZT_PLUGIN_DIR . 'includes/class-zt-admin.php';
		ZT_Admin::init();

		// Eklenti dosyaları güncellendiyse tablo şemasını tazele.
		if ( get_option( 'zt_db_version' ) !== ZT_VERSION ) {
			ZT_Activator::create_tables();
			update_option( 'zt_db_version', ZT_VERSION );
		}
	}
}
