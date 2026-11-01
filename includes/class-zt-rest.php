<?php
/**
 * Beacon modu için REST ucu. Tarayıcı, sayfa yüklendiğinde /wp-json/zt/v1/hit
 * adresine küçük bir istek gönderir; IP ve User-Agent ziyaretçinin isteğinden alınır.
 * Bu sayede sayfa önbellekten sunulsa bile ziyaret kaydedilir.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route( 'zt/v1', '/hit', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_hit' ),
			'permission_callback' => '__return_true', // Herkese açık veri toplama ucu.
		) );
		register_rest_route( 'zt/v1', '/heat', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_heat' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle_heat( $request ) {
		$noop = new WP_REST_Response( null, 204 );

		if ( ! ZT_Settings::get( 'heatmap_enabled' ) ) {
			return $noop;
		}
		if ( ! self::same_origin( $request ) ) {
			return $noop;
		}

		$settings = ZT_Settings::all();
		$ip       = ZT_Tracker::get_ip();
		if ( ZT_Tracker::ip_in_list( $ip, $settings['excluded_ips'] ) ) {
			return $noop;
		}
		if ( ! empty( $settings['block_enabled'] ) && ZT_Tracker::ip_in_list( $ip, $settings['blocklist'] ) ) {
			return $noop;
		}
		if ( is_user_logged_in() ) {
			if ( ! $settings['track_logged_in'] ) {
				return $noop;
			}
			$user = wp_get_current_user();
			if ( array_intersect( (array) $settings['excluded_roles'], (array) $user->roles ) ) {
				return $noop;
			}
		}

		$url = mb_substr( sanitize_text_field( (string) $request->get_param( 'url' ) ), 0, 190 );
		if ( '' === $url || '/' !== $url[0] ) {
			$url = '/' . ltrim( $url, '/' );
		}

		global $wpdb;
		$t   = $wpdb->prefix . 'zt_heat';
		$now = ZT_Time::mysql();

		// Tıklamalar — tek çok satırlı INSERT.
		$clicks = $request->get_param( 'clicks' );
		if ( is_array( $clicks ) ) {
			$clicks = array_slice( $clicks, 0, 200 );
			$ph     = array();
			$flat   = array();
			foreach ( $clicks as $c ) {
				if ( ! is_array( $c ) || count( $c ) < 2 ) {
					continue;
				}
				$ph[]   = '(%s,%s,%s,%d,%d)';
				$flat[] = $now;
				$flat[] = $url;
				$flat[] = 'click';
				$flat[] = max( 0, min( 1000, (int) $c[0] ) );
				$flat[] = max( 0, min( 1000, (int) $c[1] ) );
			}
			if ( ! empty( $ph ) ) {
				$sql = "INSERT INTO {$t} (created_at,url,type,x,y) VALUES " . implode( ',', $ph );
				$wpdb->query( $wpdb->prepare( $sql, $flat ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}

		// Kaydırma derinliği.
		$scroll = $request->get_param( 'scroll' );
		if ( null !== $scroll && '' !== $scroll ) {
			$wpdb->insert(
				$t,
				array( 'created_at' => $now, 'url' => $url, 'type' => 'scroll', 'x' => 0, 'y' => max( 0, min( 1000, (int) $scroll ) ) ),
				array( '%s', '%s', '%s', '%d', '%d' )
			);
		}

		return $noop;
	}

	public static function handle_hit( $request ) {
		$noop = new WP_REST_Response( null, 204 );

		// Yalnızca beacon modunda kayıt al.
		if ( 'beacon' !== ZT_Settings::get( 'track_mode' ) ) {
			return $noop;
		}

		// Aynı köken kontrolü: isteğin bizim siteden geldiğini doğrula.
		if ( ! self::same_origin( $request ) ) {
			return $noop;
		}

		$url      = esc_url_raw( (string) $request->get_param( 'url' ) );
		$referrer = esc_url_raw( (string) $request->get_param( 'referrer' ) );
		$title    = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$post_id  = absint( $request->get_param( 'post_id' ) );

		// Bildirilen sayfa bizim siteye mi ait? Değilse yoksay.
		$home_host = ZT_Tracker::domain_of( home_url() );
		if ( '' === $url || ZT_Tracker::domain_of( $url ) !== $home_host ) {
			return $noop;
		}

		$page_type = ZT_Tracker::normalize_page_type( $request->get_param( 'page_type' ) );
		if ( '' === $page_type ) {
			$page_type = 'page'; // Emniyet: türü çözülemeyen içerik isteğini yine de say.
		}

		// UTM parametrelerini bildirilen URL'in sorgu dizesinden çıkar.
		$utm       = array( 'source' => '', 'medium' => '', 'campaign' => '' );
		$query_str = wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query_str ) {
			parse_str( $query_str, $query_arr );
			$utm = ZT_Tracker::utm_from_array( $query_arr );
		}

		ZT_Tracker::record( array(
			'source'    => 'beacon',
			'page_type' => $page_type,
			'url'       => $url,
			'title'     => $title,
			'post_id'   => $post_id,
			'referrer'  => $referrer,
			'utm'       => $utm,
		) );

		return $noop;
	}

	/**
	 * İsteğin Origin/Referer başlığı siteyle aynı ana bilgisayarı gösteriyor mu?
	 */
	private static function same_origin( $request ) {
		$home_host = ZT_Tracker::domain_of( home_url() );
		if ( '' === $home_host ) {
			return true; // Ana bilgisayar çözülemiyorsa engelleme.
		}

		$origin = $request->get_header( 'origin' );
		if ( $origin ) {
			return ZT_Tracker::domain_of( $origin ) === $home_host;
		}

		$referer = $request->get_header( 'referer' );
		if ( $referer ) {
			return ZT_Tracker::domain_of( $referer ) === $home_host;
		}

		// Başlık yoksa (bazı gizlilik ayarları) izin ver; yine de URL host kontrolü var.
		return true;
	}
}
