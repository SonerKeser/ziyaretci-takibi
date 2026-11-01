<?php
/**
 * Eklenti ayarları: varsayılanlar, okuma, doğrulayarak kaydetme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Settings {

	const OPTION = 'zt_settings';

	public static function defaults() {
		return array(
			'retention_days'  => 90,     // 0 = süresiz sakla
			'max_rows'        => 300000, // emniyet tavanı, 0 = sınırsız
			'track_mode'      => 'php',   // 'php' | 'beacon' (önbellek uyumlu)
			'track_bots'      => 1,
			'track_logged_in' => 1,
			'excluded_roles'  => array( 'administrator', 'editor' ),
			'excluded_ips'    => '',     // satır başına bir IP, sonda * ile önek desteklenir
			'blocklist'       => '',     // engellenen IP'ler (satır başına bir, * öneki)
			'block_enabled'   => 1,      // kara listeyi fiilen uygula (403)
			'anonymize_ip'    => 0,
			'geo_enabled'     => 1,
			'flag_datacenter' => 1,      // veri merkezi/proxy IP'lerini bot say
			'weekly_report'   => 0,
			'report_email'    => '',     // boşsa yönetici e-postası kullanılır
			'report_day'      => 1,      // 0=Pazar ... 6=Cumartesi
			'attach_pdf'      => 1,      // haftalık rapora PDF eki
			'alerts_enabled'  => 0,
			'alert_email'     => 0,      // yeni uyarıda e-posta gönder
			'alert_spike'     => 0,      // saatlik ziyaretçi eşiği, 0=kapalı
			'alert_404'       => 0,      // saatlik 404 eşiği, 0=kapalı
			'alert_bot'       => 0,      // saatlik bot eşiği, 0=kapalı
			'alert_watch_ips' => '',     // izlenen IP'ler (satır başına bir, * öneki)
			'heatmap_enabled' => 0,      // tıklama/kaydırma ısı haritası kaydı
			'heatmap_sample'  => 100,    // örnekleme yüzdesi (1-100)
			'uninstall_purge' => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Ham girdiyi doğrulayıp kaydeder, kaydedilen ayarları döndürür.
	 */
	public static function save( $input ) {
		$defaults = self::defaults();
		$clean    = array();

		$clean['retention_days']  = max( 0, absint( isset( $input['retention_days'] ) ? $input['retention_days'] : $defaults['retention_days'] ) );
		$clean['max_rows']        = max( 0, absint( isset( $input['max_rows'] ) ? $input['max_rows'] : $defaults['max_rows'] ) );
		$clean['track_mode']      = ( isset( $input['track_mode'] ) && 'beacon' === $input['track_mode'] ) ? 'beacon' : 'php';
		$clean['track_bots']      = empty( $input['track_bots'] ) ? 0 : 1;
		$clean['track_logged_in'] = empty( $input['track_logged_in'] ) ? 0 : 1;
		$clean['anonymize_ip']    = empty( $input['anonymize_ip'] ) ? 0 : 1;
		$clean['geo_enabled']     = empty( $input['geo_enabled'] ) ? 0 : 1;
		$clean['flag_datacenter'] = empty( $input['flag_datacenter'] ) ? 0 : 1;
		$clean['block_enabled']   = empty( $input['block_enabled'] ) ? 0 : 1;
		$clean['weekly_report']   = empty( $input['weekly_report'] ) ? 0 : 1;
		$clean['attach_pdf']      = empty( $input['attach_pdf'] ) ? 0 : 1;
		$clean['uninstall_purge'] = empty( $input['uninstall_purge'] ) ? 0 : 1;

		$email                  = isset( $input['report_email'] ) ? sanitize_email( (string) $input['report_email'] ) : '';
		$clean['report_email']  = is_email( $email ) ? $email : '';
		$clean['report_day']    = min( 6, max( 0, absint( isset( $input['report_day'] ) ? $input['report_day'] : $defaults['report_day'] ) ) );

		$clean['alerts_enabled'] = empty( $input['alerts_enabled'] ) ? 0 : 1;
		$clean['alert_email']    = empty( $input['alert_email'] ) ? 0 : 1;
		$clean['alert_spike']    = max( 0, absint( isset( $input['alert_spike'] ) ? $input['alert_spike'] : 0 ) );
		$clean['alert_404']      = max( 0, absint( isset( $input['alert_404'] ) ? $input['alert_404'] : 0 ) );
		$clean['alert_bot']      = max( 0, absint( isset( $input['alert_bot'] ) ? $input['alert_bot'] : 0 ) );
		$clean['alert_watch_ips'] = self::sanitize_ip_list( isset( $input['alert_watch_ips'] ) ? $input['alert_watch_ips'] : '' );

		$clean['heatmap_enabled'] = empty( $input['heatmap_enabled'] ) ? 0 : 1;
		$clean['heatmap_sample']  = min( 100, max( 1, absint( isset( $input['heatmap_sample'] ) ? $input['heatmap_sample'] : 100 ) ) );

		$roles = array();
		if ( isset( $input['excluded_roles'] ) && is_array( $input['excluded_roles'] ) ) {
			$valid_roles = array_keys( wp_roles()->roles );
			foreach ( $input['excluded_roles'] as $role ) {
				$role = sanitize_key( $role );
				if ( in_array( $role, $valid_roles, true ) ) {
					$roles[] = $role;
				}
			}
		}
		$clean['excluded_roles'] = $roles;

		$clean['excluded_ips'] = self::sanitize_ip_list( isset( $input['excluded_ips'] ) ? $input['excluded_ips'] : '' );
		$clean['blocklist']    = self::sanitize_ip_list( isset( $input['blocklist'] ) ? $input['blocklist'] : '' );

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Satır/virgülle ayrılmış IP listesini temizler (yalnızca IP karakterleri ve * joker).
	 */
	public static function sanitize_ip_list( $raw ) {
		$lines = preg_split( '/[\r\n,]+/', (string) $raw );
		$ips   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^[0-9a-fA-F\.\:\*]{1,45}$/', $line ) ) {
				$ips[] = $line;
			}
		}
		return implode( "\n", array_unique( $ips ) );
	}
}
