<?php
/**
 * Zamanlanmış görevler: günlük bakım (saklama süresi, tavan, geo süpürme)
 * ve 5 dakikada bir konum kuyruğu işleme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Cron {

	const DAILY_HOOK = 'zt_daily_maintenance';
	const GEO_HOOK   = 'zt_geo_tick';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'daily_maintenance' ) );
		add_action( self::GEO_HOOK, array( __CLASS__, 'geo_tick' ) );

		// Etkinleştirme sonrası bir şekilde silinmişse yeniden kur.
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) || ! wp_next_scheduled( self::GEO_HOOK ) ) {
			self::schedule();
		}
	}

	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['zt_five_minutes'] ) ) {
			$schedules['zt_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Her 5 dakikada bir (Ziyaretçi Takibi)',
			);
		}
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			// Gece 03:30'da (site saatiyle) çalışsın.
			$first = strtotime( 'tomorrow 03:30', time() );
			wp_schedule_event( $first, 'daily', self::DAILY_HOOK );
		}
		if ( ! wp_next_scheduled( self::GEO_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'zt_five_minutes', self::GEO_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_clear_scheduled_hook( self::GEO_HOOK );
	}

	public static function daily_maintenance() {
		$settings = ZT_Settings::all();
		ZT_DB::cleanup( (int) $settings['retention_days'], (int) $settings['max_rows'] );
		ZT_Geo::requeue_failed( 24 );

		if ( ! empty( $settings['flag_datacenter'] ) ) {
			ZT_DB::sweep_datacenters();
		}

		// Haftalık rapor: seçilen gün geldiyse gönder (send_weekly kendi guard'ıyla mükerrerliği önler).
		if ( ! empty( $settings['weekly_report'] ) ) {
			$site_local_ts = ZT_Time::now_ts() + (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			$today_dow     = (int) gmdate( 'w', $site_local_ts );
			if ( $today_dow === (int) $settings['report_day'] ) {
				ZT_Report::send_weekly();
			}
		}
	}

	public static function geo_tick() {
		ZT_Time::maybe_sync();
		ZT_Geo::process_pending( 100 );
		ZT_Alerts::evaluate_scheduled();
	}
}
