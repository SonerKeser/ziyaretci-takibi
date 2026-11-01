<?php
/**
 * Gerçek zamanlı uyarı motoru: kurallar tetiklendiğinde uyarı kaydı oluşturur
 * (panel bildirim çanında görünür) ve isteğe bağlı e-posta gönderir.
 *
 * Tetikleyiciler:
 *  - Zamanlanmış (5 dk cron): trafik artışı, 404 fırtınası, bot artışı (son 1 saat).
 *  - Anlık (ziyaret anında): izlenen IP ziyareti.
 * Aynı uyarının tekrar tekrar üretilmesini "cooldown" penceresi engeller.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Alerts {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zt_alerts';
	}

	private static function cooldown( $type ) {
		return HOUR_IN_SECONDS; // Tüm tipler için 1 saat.
	}

	/**
	 * Cooldown penceresinde değilse uyarıyı kaydeder. watchip için cooldown IP'ye göredir.
	 *
	 * @return bool Kayıt oluşturulduysa true.
	 */
	public static function fire( $type, $severity, $title, $message, $ip = '' ) {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', ZT_Time::now_ts() - self::cooldown( $type ) );

		if ( 'watchip' === $type ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t} WHERE type = %s AND ip = %s AND created_at >= %s LIMIT 1",
				$type, $ip, $since
			) );
		} else {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t} WHERE type = %s AND created_at >= %s LIMIT 1",
				$type, $since
			) );
		}
		if ( $exists ) {
			return false;
		}

		$wpdb->insert(
			$t,
			array(
				'created_at' => ZT_Time::mysql(),
				'type'       => $type,
				'severity'   => $severity,
				'title'      => mb_substr( (string) $title, 0, 150 ),
				'message'    => mb_substr( (string) $message, 0, 255 ),
				'ip'         => mb_substr( (string) $ip, 0, 45 ),
				'seen'       => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ZT_Settings::get( 'alert_email' ) ) {
			self::email( $title, $message );
		}

		return true;
	}

	private static function email( $title, $message ) {
		$to = ZT_Settings::get( 'report_email' );
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}
		$brand   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = '⚠️ ' . $brand . ' — ' . wp_strip_all_tags( $title );
		$body    = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;color:#0f172a">' .
			'<p style="font-size:16px;font-weight:700;margin:0 0 8px">⚠️ ' . esc_html( $title ) . '</p>' .
			'<p style="margin:0 0 16px;color:#334155">' . esc_html( $message ) . '</p>' .
			'<a href="' . esc_url( admin_url( 'admin.php?page=ziyaretci-takibi' ) ) . '" style="display:inline-block;background:#0f172a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px">Panele Git →</a>' .
			'</div>';
		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Zamanlanmış değerlendirme (cron). Son 1 saatteki eşikleri kontrol eder.
	 */
	public static function evaluate_scheduled() {
		if ( ! ZT_Settings::get( 'alerts_enabled' ) ) {
			return;
		}
		global $wpdb;
		$v         = ZT_DB::visits_table();
		$now_ts    = ZT_Time::now_ts();
		$hour_from = gmdate( 'Y-m-d H:i:s', $now_ts - HOUR_IN_SECONDS );

		$spike = (int) ZT_Settings::get( 'alert_spike' );
		if ( $spike > 0 ) {
			$vis = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT ip) FROM {$v} WHERE is_bot = 0 AND visited_at >= %s",
				$hour_from
			) );
			if ( $vis >= $spike ) {
				self::fire( 'spike', 'warning', 'Trafik artışı', 'Son 1 saatte ' . $vis . ' tekil ziyaretçi (eşik: ' . $spike . ').' );
			}
		}

		$t404 = (int) ZT_Settings::get( 'alert_404' );
		if ( $t404 > 0 ) {
			$c = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$v} WHERE page_type = '404' AND visited_at >= %s",
				$hour_from
			) );
			if ( $c >= $t404 ) {
				self::fire( 'http404', 'warning', '404 hatası artışı', 'Son 1 saatte ' . $c . ' adet 404 (eşik: ' . $t404 . '). Tarama veya bozuk bağlantı olabilir.' );
			}
		}

		$tbot = (int) ZT_Settings::get( 'alert_bot' );
		if ( $tbot > 0 ) {
			$c = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$v} WHERE is_bot = 1 AND visited_at >= %s",
				$hour_from
			) );
			if ( $c >= $tbot ) {
				self::fire( 'botsurge', 'info', 'Bot trafiği artışı', 'Son 1 saatte ' . $c . ' bot isteği (eşik: ' . $tbot . ').' );
			}
		}
	}

	/**
	 * İzlenen IP ziyaret ettiğinde tetiklenir (tracker'dan çağrılır).
	 */
	public static function check_ip( $ip, $ctx ) {
		if ( '' === (string) $ip || ! ZT_Settings::get( 'alerts_enabled' ) ) {
			return;
		}
		$watch = ZT_Settings::get( 'alert_watch_ips' );
		if ( '' === trim( (string) $watch ) ) {
			return;
		}
		if ( ZT_Tracker::ip_in_list( $ip, $watch ) ) {
			$page = ( isset( $ctx['title'] ) && '' !== $ctx['title'] ) ? $ctx['title'] : ( isset( $ctx['url'] ) ? $ctx['url'] : '' );
			self::fire( 'watchip', 'info', 'İzlenen IP ziyareti', $ip . ' adresi siteyi ziyaret etti — ' . mb_substr( (string) $page, 0, 120 ), $ip );
		}
	}

	public static function recent( $limit = 30 ) {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, created_at, type, severity, title, message, ip, seen FROM {$t} ORDER BY id DESC LIMIT %d",
			max( 1, min( 100, (int) $limit ) )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function unseen_count() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE seen = 0" );
	}

	public static function mark_seen() {
		global $wpdb;
		$t = self::table();
		$wpdb->query( "UPDATE {$t} SET seen = 1 WHERE seen = 0" );
	}

	public static function clear_all() {
		global $wpdb;
		$t = self::table();
		$wpdb->query( "TRUNCATE TABLE {$t}" );
	}
}
