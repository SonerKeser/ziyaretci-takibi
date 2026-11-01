<?php
/**
 * Eklenti kaldırılırken çalışır. Ayarlarda "kaldırınca verileri sil"
 * işaretliyse tabloları ve seçenekleri temizler.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$zt_settings = get_option( 'zt_settings', array() );

if ( ! empty( $zt_settings['uninstall_purge'] ) ) {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}zt_visits" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}zt_geo" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}zt_alerts" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}zt_heat" );

	delete_option( 'zt_settings' );
	delete_option( 'zt_db_version' );
	delete_option( 'zt_last_report' );
	delete_option( 'zt_funnel' );
	delete_option( 'zt_funnels' );
	delete_option( 'zt_time_offset' );
	delete_option( 'zt_time_synced_at' );
	delete_option( 'zt_time_source' );
	delete_transient( 'zt_geo_lock' );
}

wp_clear_scheduled_hook( 'zt_daily_maintenance' );
wp_clear_scheduled_hook( 'zt_geo_tick' );

// Sunucu düzeyi engelleme bloğu eklenti silindikten sonra yönetilemez; .htaccess'ten kaldır.
if ( ! class_exists( 'ZT_Server_Rules' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-zt-server-rules.php';
}
ZT_Server_Rules::htaccess_remove();
