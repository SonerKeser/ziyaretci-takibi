<?php
/**
 * Ziyaret kayıt motoru. İki kayıt yolu tek bir record() mantığını paylaşır:
 *  - PHP modu: template_redirect üzerinde sunucu tarafı (varsayılan).
 *  - Beacon modu: tarayıcıdan REST ucuna (önbellek eklentileriyle uyumlu).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Tracker {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'enforce_block' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_beacon' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_heatmap' ) );
	}

	public static function maybe_enqueue_heatmap() {
		if ( ! ZT_Settings::get( 'heatmap_enabled' ) ) {
			return;
		}
		if ( is_preview() || is_customize_preview() ) {
			return;
		}
		if ( is_user_logged_in() ) {
			$settings = ZT_Settings::all();
			if ( ! $settings['track_logged_in'] ) {
				return;
			}
			$user = wp_get_current_user();
			if ( array_intersect( (array) $settings['excluded_roles'], (array) $user->roles ) ) {
				return;
			}
		}

		wp_enqueue_script( 'zt-heatmap', ZT_PLUGIN_URL . 'admin/js/heatmap.js', array(), ZT_VERSION, true );
		wp_localize_script( 'zt-heatmap', 'ZT_HEAT', array(
			'url'    => esc_url_raw( rest_url( 'zt/v1/heat' ) ),
			'sample' => (int) ZT_Settings::get( 'heatmap_sample' ),
		) );
	}

	/* ─────────────────────────────────────────────
	 * ERİŞİM ENGELLEME (kara liste)
	 * ───────────────────────────────────────────── */

	/**
	 * Kara listedeki IP'lere ön yüzde 403 döner. (Admin/AJAX/REST hariç — template_redirect
	 * yalnızca ön yüz sayfa isteklerinde çalışır.) Önbellekten sunulan sayfalar için
	 * sunucu/güvenlik duvarı düzeyinde engelleme gerekir; bu, WP'ye ulaşan istekleri kapsar.
	 */
	public static function enforce_block() {
		if ( ! ZT_Settings::get( 'block_enabled' ) ) {
			return;
		}
		$list = ZT_Settings::get( 'blocklist' );
		if ( '' === trim( (string) $list ) ) {
			return;
		}
		$ip = self::get_ip();
		if ( '' !== $ip && self::ip_in_list( $ip, $list ) ) {
			status_header( 403 );
			nocache_headers();
			wp_die(
				esc_html__( 'Erişiminiz engellenmiştir.', 'ziyaretci-takibi' ),
				esc_html__( 'Erişim Engellendi', 'ziyaretci-takibi' ),
				array( 'response' => 403 )
			);
		}
	}

	public static function is_blocked( $ip ) {
		if ( '' === (string) $ip || ! ZT_Settings::get( 'block_enabled' ) ) {
			return false;
		}
		return self::ip_in_list( $ip, ZT_Settings::get( 'blocklist' ) );
	}

	/* ─────────────────────────────────────────────
	 * PHP MODU (template_redirect)
	 * ───────────────────────────────────────────── */

	public static function capture() {
		if ( 'beacon' === ZT_Settings::get( 'track_mode' ) ) {
			return; // Bu istekte kayıt beacon ile yapılacak.
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return;
		}
		if ( is_feed() || is_trackback() || is_robots() || is_preview() || is_customize_preview() ) {
			return;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return;
		}

		$page_type = self::page_type();
		if ( '' === $page_type ) {
			return;
		}

		$utm = self::utm_from_array( isset( $_GET ) ? wp_unslash( $_GET ) : array() ); // phpcs:ignore WordPress.Security.NonceVerification

		self::record( array(
			'source'    => 'php',
			'page_type' => $page_type,
			'url'       => self::current_url(),
			'title'     => wp_get_document_title(),
			'post_id'   => (int) get_queried_object_id(),
			'referrer'  => isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '',
			'utm'       => $utm,
		) );
	}

	/* ─────────────────────────────────────────────
	 * ORTAK KAYIT MANTIĞI
	 * ───────────────────────────────────────────── */

	/**
	 * @param array $ctx source,page_type,url,title,post_id,referrer,utm
	 * @return bool Kayıt atıldıysa true.
	 */
	public static function record( $ctx ) {
		$settings = ZT_Settings::all();

		// Giriş yapmış kullanıcılar: ayara göre tamamen ya da role göre atla.
		$user_id = 0;
		if ( is_user_logged_in() ) {
			if ( ! $settings['track_logged_in'] ) {
				return false;
			}
			$user = wp_get_current_user();
			if ( array_intersect( (array) $settings['excluded_roles'], (array) $user->roles ) ) {
				return false;
			}
			$user_id = (int) $user->ID;
		}

		$page_type = isset( $ctx['page_type'] ) ? $ctx['page_type'] : '';
		if ( '' === $page_type ) {
			return false;
		}

		$ip = self::get_ip();
		if ( self::ip_in_list( $ip, $settings['excluded_ips'] ) ) {
			return false;
		}
		// Kara listedeki IP'ler beacon modunda 403'e takılmaz; burada da kaydetme.
		if ( ! empty( $settings['block_enabled'] ) && self::ip_in_list( $ip, $settings['blocklist'] ) ) {
			return false;
		}

		$ua_raw = isset( $_SERVER['HTTP_USER_AGENT'] )
			? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
			: '';

		$parsed = ZT_UA_Parser::parse( $ua_raw );

		// Bilinen veri merkezi/proxy IP'sini bot say (geo önbellekte çözülmüşse).
		if ( ! $parsed['is_bot'] && ! empty( $settings['flag_datacenter'] ) && ! empty( $settings['geo_enabled'] ) && ZT_Geo::is_known_datacenter( $ip ) ) {
			$parsed['is_bot']   = 1;
			$parsed['bot_name'] = 'Veri Merkezi';
		}

		if ( $parsed['is_bot'] && ! $settings['track_bots'] ) {
			return false;
		}

		if ( $settings['anonymize_ip'] && '' !== $ip ) {
			$ip = self::anonymize_ip( $ip );
		}

		$referrer        = isset( $ctx['referrer'] ) ? esc_url_raw( mb_substr( (string) $ctx['referrer'], 0, 500 ) ) : '';
		$referrer_domain = self::domain_of( $referrer );

		// Kendi site içi geçişleri "kaynak" olarak sayma.
		if ( '' !== $referrer_domain && self::domain_of( home_url() ) === $referrer_domain ) {
			$referrer_domain = '';
			$referrer        = '';
		}

		$utm = isset( $ctx['utm'] ) && is_array( $ctx['utm'] ) ? $ctx['utm'] : array( 'source' => '', 'medium' => '', 'campaign' => '' );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'zt_visits',
			array(
				'visited_at'      => ZT_Time::mysql(),
				'ip'              => $ip,
				'user_id'         => $user_id,
				'url'             => mb_substr( (string) ( isset( $ctx['url'] ) ? $ctx['url'] : '' ), 0, 500 ),
				'page_title'      => mb_substr( (string) ( isset( $ctx['title'] ) ? $ctx['title'] : '' ), 0, 255 ),
				'post_id'         => (int) ( isset( $ctx['post_id'] ) ? $ctx['post_id'] : 0 ),
				'page_type'       => $page_type,
				'referrer'        => $referrer,
				'referrer_domain' => mb_substr( $referrer_domain, 0, 190 ),
				'user_agent'      => $ua_raw,
				'device'          => $parsed['device'],
				'browser'         => mb_substr( $parsed['browser'], 0, 50 ),
				'browser_version' => mb_substr( $parsed['browser_version'], 0, 20 ),
				'os'              => mb_substr( $parsed['os'], 0, 50 ),
				'is_bot'          => (int) $parsed['is_bot'],
				'bot_name'        => mb_substr( $parsed['bot_name'], 0, 100 ),
				'utm_source'      => mb_substr( $utm['source'], 0, 100 ),
				'utm_medium'      => mb_substr( $utm['medium'], 0, 100 ),
				'utm_campaign'    => mb_substr( $utm['campaign'], 0, 150 ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $settings['geo_enabled'] && '' !== $ip ) {
			ZT_Geo::queue( $ip );
		}

		// İzlenen IP uyarısı (yalnızca alerts açıksa ve liste doluysa iş yapar).
		if ( ! empty( $settings['alerts_enabled'] ) ) {
			ZT_Alerts::check_ip( $ip, $ctx );
		}

		return true;
	}

	/* ─────────────────────────────────────────────
	 * BEACON (frontend script)
	 * ───────────────────────────────────────────── */

	public static function maybe_enqueue_beacon() {
		if ( 'beacon' !== ZT_Settings::get( 'track_mode' ) ) {
			return;
		}
		if ( is_preview() || is_customize_preview() ) {
			return;
		}

		// Hariç tutulan kullanıcıya gereksiz beacon gönderme.
		if ( is_user_logged_in() ) {
			$settings = ZT_Settings::all();
			if ( ! $settings['track_logged_in'] ) {
				return;
			}
			$user = wp_get_current_user();
			if ( array_intersect( (array) $settings['excluded_roles'], (array) $user->roles ) ) {
				return;
			}
		}

		wp_enqueue_script( 'zt-beacon', ZT_PLUGIN_URL . 'admin/js/beacon.js', array(), ZT_VERSION, true );
		wp_localize_script( 'zt-beacon', 'ZT_BEACON', array(
			'url' => esc_url_raw( rest_url( 'zt/v1/hit' ) ),
		) );
	}

	/* ─────────────────────────────────────────────
	 * YARDIMCILAR
	 * ───────────────────────────────────────────── */

	/**
	 * '' dönerse istek kaydedilmez.
	 */
	public static function page_type() {
		if ( is_front_page() ) {
			return 'front';
		}
		if ( is_home() ) {
			return 'home';
		}
		if ( is_page() ) {
			return 'page';
		}
		if ( is_singular() ) {
			return 'single';
		}
		if ( is_category() || is_tag() || is_tax() || is_author() || is_date() || is_post_type_archive() ) {
			return 'archive';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_404() ) {
			return '404';
		}
		return '';
	}

	/**
	 * Beacon'dan gelen ya da PHP tarafındaki page_type değerini beyaz listeye kısıtlar.
	 */
	public static function normalize_page_type( $type ) {
		$allowed = array( 'front', 'home', 'page', 'single', 'archive', 'search', '404' );
		$type    = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		return in_array( $type, $allowed, true ) ? $type : '';
	}

	/**
	 * utm_source / utm_medium / utm_campaign değerlerini bir diziden çıkarır.
	 */
	public static function utm_from_array( $arr ) {
		$get = function ( $key ) use ( $arr ) {
			return isset( $arr[ $key ] ) ? sanitize_text_field( (string) $arr[ $key ] ) : '';
		};
		return array(
			'source'   => $get( 'utm_source' ),
			'medium'   => $get( 'utm_medium' ),
			'campaign' => $get( 'utm_campaign' ),
		);
	}

	public static function get_ip() {
		// Vekil/CDN başlıkları istemci tarafından taklit edilebilir; yalnızca site bir
		// ters vekil arkasındaysa güvenilir. Ayarlardan kapatılırsa sadece REMOTE_ADDR okunur.
		$keys = array( 'REMOTE_ADDR' );
		if ( ZT_Settings::get( 'trust_proxy' ) ) {
			$keys = array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_REAL_IP',
				'REMOTE_ADDR',
			);
		}
		$keys = (array) apply_filters( 'zt_client_ip_headers', $keys );
		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$raw   = wp_unslash( $_SERVER[ $key ] );
				$parts = explode( ',', $raw );
				$ip    = trim( $parts[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}

	/**
	 * IP, satır/virgülle ayrılmış listeyle eşleşiyor mu? Sonda * öneki destekler.
	 */
	public static function ip_in_list( $ip, $list ) {
		if ( '' === (string) $ip || '' === trim( (string) $list ) ) {
			return false;
		}
		$lines = preg_split( '/[\r\n,]+/', (string) $list );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '*' === substr( $line, -1 ) ) {
				$prefix = rtrim( $line, '*' );
				if ( '' !== $prefix && 0 === strpos( $ip, $prefix ) ) {
					return true;
				}
			} elseif ( $ip === $line ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * KVKK dostu anonimleştirme: IPv4 son oktet, IPv6 son 5 hextet sıfırlanır.
	 */
	public static function anonymize_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $ip );
			if ( false !== $packed ) {
				// İlk 48 bit korunur, kalanı sıfırlanır.
				$masked = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
				$result = @inet_ntop( $masked );
				if ( false !== $result ) {
					return $result;
				}
			}
		}
		return $ip;
	}

	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	public static function domain_of( $url ) {
		if ( '' === (string) $url ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		return strtolower( preg_replace( '/^www\./i', '', $host ) );
	}
}
