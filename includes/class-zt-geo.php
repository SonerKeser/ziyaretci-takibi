<?php
/**
 * IP konum tespiti: IP'ler kuyruklanır, ip-api.com toplu uç noktasıyla (100 IP / istek)
 * arka planda çözülür ve kalıcı olarak önbelleklenir. Her IP yalnızca 1 kez sorgulanır.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Geo {

	const BATCH_URL  = 'http://ip-api.com/batch';
	const FIELDS     = 'status,message,country,countryCode,region,regionName,city,isp,org,timezone,lat,lon,proxy,hosting,query';
	const LOCK_KEY   = 'zt_geo_lock';
	const LOCK_TTL   = 20; // saniye — ücretsiz plandaki dakikalık istek limitine saygı için.

	/**
	 * IP'yi kuyruğa ekler (zaten kayıtlıysa dokunmaz).
	 */
	public static function queue( $ip ) {
		if ( '' === (string) $ip ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'zt_geo';

		if ( self::is_private( $ip ) ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$table} (ip, status, country, city, looked_up_at) VALUES (%s, 'private', 'Yerel Ağ', '', %s)",
				$ip,
				ZT_Time::mysql()
			) );
			return;
		}

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (ip, status) VALUES (%s, 'pending')",
			$ip
		) );
	}

	/**
	 * Bekleyen IP'leri toplu olarak çözer. Cron'dan ve panel açılışından çağrılır.
	 *
	 * @return int Çözülen IP sayısı.
	 */
	public static function process_pending( $limit = 100 ) {
		if ( ! ZT_Settings::get( 'geo_enabled' ) ) {
			return 0;
		}

		// Eşzamanlı çalışmayı ve API limitini aşmayı önle.
		if ( get_transient( self::LOCK_KEY ) ) {
			return 0;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		global $wpdb;
		$table = $wpdb->prefix . 'zt_geo';

		$ips = $wpdb->get_col( $wpdb->prepare(
			"SELECT ip FROM {$table} WHERE status = 'pending' ORDER BY ip LIMIT %d",
			min( 100, max( 1, (int) $limit ) )
		) );

		if ( empty( $ips ) ) {
			return 0;
		}

		$response = wp_remote_post(
			self::BATCH_URL . '?fields=' . rawurlencode( self::FIELDS ),
			array(
				'timeout' => 12,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array_values( $ips ) ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$now  = ZT_Time::mysql();
		$done = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['query'] ) ) {
				continue;
			}
			$ip = (string) $row['query'];

			if ( isset( $row['status'] ) && 'success' === $row['status'] ) {
				$wpdb->update(
					$table,
					array(
						'status'       => 'ok',
						'country_code' => strtoupper( substr( (string) ( isset( $row['countryCode'] ) ? $row['countryCode'] : '' ), 0, 2 ) ),
						'country'      => mb_substr( (string) ( isset( $row['country'] ) ? $row['country'] : '' ), 0, 100 ),
						'region'       => mb_substr( (string) ( isset( $row['regionName'] ) ? $row['regionName'] : '' ), 0, 100 ),
						'city'         => mb_substr( (string) ( isset( $row['city'] ) ? $row['city'] : '' ), 0, 100 ),
						'isp'          => mb_substr( (string) ( isset( $row['isp'] ) ? $row['isp'] : '' ), 0, 150 ),
						'org'          => mb_substr( (string) ( isset( $row['org'] ) ? $row['org'] : '' ), 0, 150 ),
						'timezone'     => mb_substr( (string) ( isset( $row['timezone'] ) ? $row['timezone'] : '' ), 0, 64 ),
						'lat'          => (float) ( isset( $row['lat'] ) ? $row['lat'] : 0 ),
						'lon'          => (float) ( isset( $row['lon'] ) ? $row['lon'] : 0 ),
						'is_proxy'     => empty( $row['proxy'] ) ? 0 : 1,
						'is_hosting'   => empty( $row['hosting'] ) ? 0 : 1,
						'looked_up_at' => $now,
					),
					array( 'ip' => $ip )
				);

				// Yeni öğrenildiyse: veri merkezi/proxy IP'sinin geçmiş ziyaretlerini bot say.
				if ( ( ! empty( $row['proxy'] ) || ! empty( $row['hosting'] ) ) && ZT_Settings::get( 'flag_datacenter' ) ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE " . ZT_DB::visits_table() . " SET is_bot = 1, bot_name = %s WHERE ip = %s AND is_bot = 0",
						'Veri Merkezi',
						$ip
					) );
				}

				$done++;
			} else {
				$wpdb->update(
					$table,
					array(
						'status'       => 'fail',
						'looked_up_at' => $now,
					),
					array( 'ip' => $ip )
				);
			}
		}

		return $done;
	}

	/**
	 * IP, geo önbelleğinde veri merkezi/proxy olarak biliniyor mu?
	 */
	public static function is_known_datacenter( $ip ) {
		if ( '' === (string) $ip ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'zt_geo';
		$r     = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, is_proxy, is_hosting FROM {$table} WHERE ip = %s",
			$ip
		), ARRAY_A );
		return $r && 'ok' === $r['status'] && ( 1 === (int) $r['is_proxy'] || 1 === (int) $r['is_hosting'] );
	}

	/**
	 * Bekleyen kayıt sayısı.
	 */
	public static function pending_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'zt_geo';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}

	/**
	 * Başarısız sorguları yeniden kuyruğa alır (günlük bakımda çağrılır).
	 */
	public static function requeue_failed( $older_than_hours = 24 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'zt_geo';
		$cutoff = gmdate( 'Y-m-d H:i:s', ZT_Time::local_ts() - $older_than_hours * HOUR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'pending' WHERE status = 'fail' AND (looked_up_at IS NULL OR looked_up_at < %s)",
			$cutoff
		) );
	}

	public static function is_private( $ip ) {
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
