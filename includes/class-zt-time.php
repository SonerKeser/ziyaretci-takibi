<?php
/**
 * Bazı sanallaştırılmış sunucularda PHP'nin sistem saati (barındırma sağlayıcısının
 * henüz çözmediği bir hipervizör sorunu yüzünden) kayabiliyor. Bu sınıf, sunucunun
 * kendi saatine güvenmek yerine periyodik olarak harici, güvenilir kaynaklardan
 * (Cloudflare / HTTPS yanıt üstbilgisi) gerçek zamanı öğrenir ve yerel saatle
 * arasındaki farkı (offset) saklar. Eklentinin tüm zaman damgaları bu düzeltilmiş
 * saate göre üretilir.
 *
 * Senkronizasyon yalnızca cron tetiklemesinde (arka planda) çalışır; ziyaretçi
 * isteklerinde asla ağ çağrısı yapılmaz — gecikme eklenmez.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Time {

	const OPT_OFFSET = 'zt_time_offset';    // saniye: (gerçek zaman) - (PHP time())
	const OPT_SYNCED = 'zt_time_synced_at'; // son başarılı senkronun PHP time() değeri
	const OPT_SOURCE = 'zt_time_source';    // hangi kaynaktan öğrenildi
	const MIN_INTERVAL = 900;               // 15 dakika — throttle

	/**
	 * Son senkronizasyon üzerinden yeterli süre geçtiyse dış kaynaktan zamanı günceller.
	 * Cron tick'inden çağrılır.
	 *
	 * @return bool Senkron denendiyse ve başarılıysa true.
	 */
	public static function maybe_sync() {
		$last = (int) get_option( self::OPT_SYNCED, 0 );
		if ( ( time() - $last ) < self::MIN_INTERVAL ) {
			return false;
		}
		return self::sync();
	}

	/**
	 * Kaynakları sırayla dener, ilk başarılıda offset'i hesaplayıp kaydeder.
	 */
	public static function sync() {
		$sources = array(
			array( __CLASS__, 'fetch_cloudflare_trace' ),
			array( __CLASS__, 'fetch_wp_api_date' ),
			array( __CLASS__, 'fetch_generic_date' ),
		);

		foreach ( $sources as $source ) {
			$before = time();
			$result = call_user_func( $source );
			if ( null === $result ) {
				continue;
			}
			$offset = $result['ts'] - $before;
			// Bariz hatalı yanıtları (ör. sunucu hatası sayfası) reddet.
			if ( abs( $offset ) >= YEAR_IN_SECONDS ) {
				continue;
			}
			update_option( self::OPT_OFFSET, $offset, false );
			update_option( self::OPT_SYNCED, $before, false );
			update_option( self::OPT_SOURCE, $result['source'], false );
			return true;
		}

		return false;
	}

	/**
	 * Cloudflare'ın herkese açık "trace" ucu; gövdede "ts=<unix.epoch>" satırı döner.
	 * En hassas kaynak (ondalıklı saniye), ekstra ayrıştırma gerektirmez.
	 */
	private static function fetch_cloudflare_trace() {
		$res = wp_remote_get( 'https://www.cloudflare.com/cdn-cgi/trace', array( 'timeout' => 3 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $res );
		if ( preg_match( '/^ts=([0-9]+(?:\.[0-9]+)?)/m', $body, $m ) ) {
			return array( 'ts' => (int) round( (float) $m[1] ), 'source' => 'cloudflare' );
		}
		return null;
	}

	private static function fetch_wp_api_date() {
		return self::fetch_date_header( 'https://api.wordpress.org/core/version-check/1.7/', 'wordpress.org' );
	}

	private static function fetch_generic_date() {
		return self::fetch_date_header( 'https://www.google.com/generate_204', 'google' );
	}

	/**
	 * Herhangi bir HTTPS yanıtının standart "Date" üstbilgisinden zamanı okur.
	 */
	private static function fetch_date_header( $url, $label ) {
		$res = wp_remote_head( $url, array( 'timeout' => 3 ) );
		if ( is_wp_error( $res ) ) {
			$res = wp_remote_get( $url, array( 'timeout' => 3 ) ); // bazı uçlar HEAD'e izin vermez
		}
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$date = wp_remote_retrieve_header( $res, 'date' );
		if ( ! $date ) {
			return null;
		}
		$ts = strtotime( $date );
		return $ts ? array( 'ts' => $ts, 'source' => $label ) : null;
	}

	/**
	 * Düzeltilmiş "şu an" — Unix zaman damgası (GMT).
	 */
	public static function now_ts() {
		return time() + (int) get_option( self::OPT_OFFSET, 0 );
	}

	/**
	 * current_time('mysql') ile aynı biçim, ama düzeltilmiş saatle: site saat
	 * dilimine göre yerel tarih/saat metni ("Y-m-d H:i:s").
	 */
	public static function mysql() {
		$offset_sec = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		return gmdate( 'Y-m-d H:i:s', self::now_ts() + $offset_sec );
	}

	/**
	 * Senkronizasyon durumu (Ayarlar ekranında gösterilir).
	 */
	public static function status() {
		return array(
			'offset'    => (int) get_option( self::OPT_OFFSET, 0 ),
			'synced_at' => (int) get_option( self::OPT_SYNCED, 0 ),
			'source'    => (string) get_option( self::OPT_SOURCE, '' ),
		);
	}
}
