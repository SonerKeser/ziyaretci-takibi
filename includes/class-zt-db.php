<?php
/**
 * Sorgu motoru: filtreli ziyaret listeleri, panel istatistikleri,
 * ziyaretçi (IP) toplulaştırmaları ve profil/oturum analizi.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_DB {

	const SESSION_GAP = 1800; // 30 dk — oturum ayırma eşiği (saniye).

	public static function visits_table() {
		global $wpdb;
		return $wpdb->prefix . 'zt_visits';
	}

	public static function geo_table() {
		global $wpdb;
		return $wpdb->prefix . 'zt_geo';
	}

	/* ─────────────────────────────────────────────
	 * FİLTRE → WHERE İNŞASI
	 * ───────────────────────────────────────────── */

	/**
	 * @param array $f Filtreler (from, to, ip, country, device, browser, os, bots, search, referrer, page_type).
	 * @return array [where_sql, params]
	 */
	private static function build_where( $f ) {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['from'] ) ) {
			$where[]  = 'v.visited_at >= %s';
			$params[] = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['to'] ) ) {
			$where[]  = 'v.visited_at <= %s';
			$params[] = $f['to'] . ' 23:59:59';
		}
		if ( ! empty( $f['ip'] ) ) {
			global $wpdb;
			$where[]  = 'v.ip LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $f['ip'] ) . '%';
		}
		if ( ! empty( $f['country'] ) ) {
			$where[]  = 'g.country_code = %s';
			$params[] = strtoupper( substr( $f['country'], 0, 2 ) );
		}
		if ( ! empty( $f['device'] ) ) {
			$where[]  = 'v.device = %s';
			$params[] = $f['device'];
		}
		if ( ! empty( $f['browser'] ) ) {
			$where[]  = 'v.browser = %s';
			$params[] = $f['browser'];
		}
		if ( ! empty( $f['os'] ) ) {
			$where[]  = 'v.os = %s';
			$params[] = $f['os'];
		}
		if ( ! empty( $f['page_type'] ) ) {
			$where[]  = 'v.page_type = %s';
			$params[] = $f['page_type'];
		}
		if ( ! empty( $f['referrer'] ) ) {
			global $wpdb;
			$where[]  = 'v.referrer_domain LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $f['referrer'] ) . '%';
		}
		if ( ! empty( $f['campaign'] ) ) {
			$where[]  = 'v.utm_campaign = %s';
			$params[] = $f['campaign'];
		}
		if ( ! empty( $f['search'] ) ) {
			global $wpdb;
			$like     = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			$where[]  = '(v.url LIKE %s OR v.page_title LIKE %s OR v.utm_campaign LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$bots = isset( $f['bots'] ) ? $f['bots'] : 'include';
		if ( 'exclude' === $bots ) {
			$where[] = 'v.is_bot = 0';
		} elseif ( 'only' === $bots ) {
			$where[] = 'v.is_bot = 1';
		}

		return array( implode( ' AND ', $where ), $params );
	}

	private static function run( $sql, $params = array() ) {
		global $wpdb;
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		return $sql;
	}

	/* ─────────────────────────────────────────────
	 * ZİYARET LİSTESİ
	 * ───────────────────────────────────────────── */

	public static function get_visits( $filters, $page = 1, $per_page = 50, $order = 'DESC' ) {
		global $wpdb;

		$v = self::visits_table();
		$g = self::geo_table();

		list( $where, $params ) = self::build_where( $filters );

		$order    = ( 'ASC' === strtoupper( (string) $order ) ) ? 'ASC' : 'DESC';
		$per_page = min( 200, max( 10, (int) $per_page ) );
		$page     = max( 1, (int) $page );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( self::run(
			"SELECT COUNT(*) FROM {$v} v LEFT JOIN {$g} g ON g.ip = v.ip WHERE {$where}",
			$params
		) );

		$rows = $wpdb->get_results( self::run(
			"SELECT v.id, v.visited_at, v.ip, v.user_id, v.url, v.page_title, v.page_type,
			        v.referrer, v.referrer_domain, v.user_agent, v.device, v.browser,
			        v.browser_version, v.os, v.is_bot, v.bot_name,
			        v.utm_source, v.utm_medium, v.utm_campaign,
			        g.country_code, g.country, g.city, g.isp, g.is_proxy, g.is_hosting, g.status AS geo_status
			 FROM {$v} v
			 LEFT JOIN {$g} g ON g.ip = v.ip
			 WHERE {$where}
			 ORDER BY v.visited_at {$order}, v.id {$order}
			 LIMIT {$per_page} OFFSET {$offset}",
			$params
		), ARRAY_A );

		return array(
			'rows'     => is_array( $rows ) ? $rows : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Filtre açılır listeleri için mevcut değerler.
	 */
	public static function get_filter_options() {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		$countries = $wpdb->get_results(
			"SELECT g.country_code AS code, g.country AS name, COUNT(DISTINCT g.ip) AS n
			 FROM {$g} g WHERE g.status = 'ok' AND g.country_code != ''
			 GROUP BY g.country_code, g.country ORDER BY n DESC LIMIT 100",
			ARRAY_A
		);
		$browsers = $wpdb->get_col( "SELECT DISTINCT browser FROM {$v} WHERE browser != '' AND is_bot = 0 ORDER BY browser LIMIT 50" );
		$oses     = $wpdb->get_col( "SELECT DISTINCT os FROM {$v} WHERE os != '' AND is_bot = 0 ORDER BY os LIMIT 50" );

		return array(
			'countries' => is_array( $countries ) ? $countries : array(),
			'browsers'  => is_array( $browsers ) ? $browsers : array(),
			'oses'      => is_array( $oses ) ? $oses : array(),
		);
	}

	/* ─────────────────────────────────────────────
	 * PANEL (DASHBOARD)
	 * ───────────────────────────────────────────── */

	/**
	 * @param string $period 'today' | '7d' | '30d'
	 */
	public static function get_dashboard( $period ) {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		$now_ts = ZT_Time::local_ts();
		$today  = gmdate( 'Y-m-d', $now_ts );

		if ( 'today' === $period ) {
			$from      = $today . ' 00:00:00';
			$to        = $today . ' 23:59:59';
			$prev_from = gmdate( 'Y-m-d', $now_ts - DAY_IN_SECONDS ) . ' 00:00:00';
			$prev_to   = gmdate( 'Y-m-d', $now_ts - DAY_IN_SECONDS ) . ' 23:59:59';
			$days      = 1;
		} elseif ( '30d' === $period ) {
			$days      = 30;
			$from      = gmdate( 'Y-m-d', $now_ts - 29 * DAY_IN_SECONDS ) . ' 00:00:00';
			$to        = $today . ' 23:59:59';
			$prev_from = gmdate( 'Y-m-d', $now_ts - 59 * DAY_IN_SECONDS ) . ' 00:00:00';
			$prev_to   = gmdate( 'Y-m-d', $now_ts - 30 * DAY_IN_SECONDS ) . ' 23:59:59';
		} else {
			$period    = '7d';
			$days      = 7;
			$from      = gmdate( 'Y-m-d', $now_ts - 6 * DAY_IN_SECONDS ) . ' 00:00:00';
			$to        = $today . ' 23:59:59';
			$prev_from = gmdate( 'Y-m-d', $now_ts - 13 * DAY_IN_SECONDS ) . ' 00:00:00';
			$prev_to   = gmdate( 'Y-m-d', $now_ts - 7 * DAY_IN_SECONDS ) . ' 23:59:59';
		}

		// Toplamlar (botlar hariç) + önceki dönem karşılaştırması.
		$totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors
			 FROM {$v} WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s",
			$from, $to
		), ARRAY_A );

		$prev = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors
			 FROM {$v} WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s",
			$prev_from, $prev_to
		), ARRAY_A );

		$bots = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$v} WHERE is_bot = 1 AND visited_at BETWEEN %s AND %s",
			$from, $to
		) );

		$active_now = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ip) FROM {$v} WHERE is_bot = 0 AND visited_at >= %s",
			gmdate( 'Y-m-d H:i:s', $now_ts - 5 * MINUTE_IN_SECONDS )
		) );

		// Zaman serisi.
		$series = array();
		if ( 'today' === $period ) {
			$raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT HOUR(visited_at) AS b, COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors
				 FROM {$v} WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s
				 GROUP BY HOUR(visited_at)",
				$from, $to
			), ARRAY_A );
			$map = array();
			foreach ( (array) $raw as $r ) {
				$map[ (int) $r['b'] ] = $r;
			}
			$current_hour = (int) gmdate( 'G', $now_ts );
			for ( $h = 0; $h <= 23; $h++ ) {
				$series[] = array(
					'label'    => sprintf( '%02d:00', $h ),
					'views'    => isset( $map[ $h ] ) ? (int) $map[ $h ]['views'] : 0,
					'visitors' => isset( $map[ $h ] ) ? (int) $map[ $h ]['visitors'] : 0,
					'future'   => $h > $current_hour ? 1 : 0,
				);
			}
		} else {
			$raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(visited_at) AS b, COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors
				 FROM {$v} WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s
				 GROUP BY DATE(visited_at)",
				$from, $to
			), ARRAY_A );
			$map = array();
			foreach ( (array) $raw as $r ) {
				$map[ $r['b'] ] = $r;
			}
			for ( $i = $days - 1; $i >= 0; $i-- ) {
				$d        = gmdate( 'Y-m-d', $now_ts - $i * DAY_IN_SECONDS );
				$series[] = array(
					'label'    => date_i18n( 'j M', strtotime( $d . ' 12:00:00' ) ),
					'views'    => isset( $map[ $d ] ) ? (int) $map[ $d ]['views'] : 0,
					'visitors' => isset( $map[ $d ] ) ? (int) $map[ $d ]['visitors'] : 0,
					'future'   => 0,
				);
			}
		}

		// Dağılımlar.
		$devices = $wpdb->get_results( $wpdb->prepare(
			"SELECT device AS k, COUNT(*) AS n FROM {$v}
			 WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s GROUP BY device ORDER BY n DESC",
			$from, $to
		), ARRAY_A );

		$browsers = $wpdb->get_results( $wpdb->prepare(
			"SELECT browser AS k, COUNT(*) AS n FROM {$v}
			 WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s GROUP BY browser ORDER BY n DESC LIMIT 6",
			$from, $to
		), ARRAY_A );

		$oses = $wpdb->get_results( $wpdb->prepare(
			"SELECT os AS k, COUNT(*) AS n FROM {$v}
			 WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s GROUP BY os ORDER BY n DESC LIMIT 6",
			$from, $to
		), ARRAY_A );

		$countries = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.country_code AS code, g.country AS name,
			        COUNT(*) AS views, COUNT(DISTINCT v.ip) AS visitors
			 FROM {$v} v INNER JOIN {$g} g ON g.ip = v.ip
			 WHERE v.is_bot = 0 AND g.country_code != '' AND v.visited_at BETWEEN %s AND %s
			 GROUP BY g.country_code, g.country ORDER BY visitors DESC, views DESC LIMIT 8",
			$from, $to
		), ARRAY_A );

		// Harita için tüm ülkeler (sınırsız).
		$map = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.country_code AS code, g.country AS name,
			        COUNT(*) AS views, COUNT(DISTINCT v.ip) AS visitors
			 FROM {$v} v INNER JOIN {$g} g ON g.ip = v.ip
			 WHERE v.is_bot = 0 AND g.country_code != '' AND v.visited_at BETWEEN %s AND %s
			 GROUP BY g.country_code, g.country",
			$from, $to
		), ARRAY_A );

		$top_pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, MAX(page_title) AS title, COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors
			 FROM {$v} WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s
			 GROUP BY url ORDER BY views DESC LIMIT 10",
			$from, $to
		), ARRAY_A );

		$referrers = $wpdb->get_results( $wpdb->prepare(
			"SELECT referrer_domain AS k, COUNT(*) AS n, COUNT(DISTINCT ip) AS visitors
			 FROM {$v} WHERE is_bot = 0 AND referrer_domain != '' AND visited_at BETWEEN %s AND %s
			 GROUP BY referrer_domain ORDER BY n DESC LIMIT 10",
			$from, $to
		), ARRAY_A );

		$top_bots = $wpdb->get_results( $wpdb->prepare(
			"SELECT bot_name AS k, COUNT(*) AS n FROM {$v}
			 WHERE is_bot = 1 AND visited_at BETWEEN %s AND %s GROUP BY bot_name ORDER BY n DESC LIMIT 6",
			$from, $to
		), ARRAY_A );

		$campaigns = $wpdb->get_results( $wpdb->prepare(
			"SELECT utm_campaign AS campaign, MAX(utm_source) AS source, MAX(utm_medium) AS medium,
			        COUNT(*) AS n, COUNT(DISTINCT ip) AS visitors
			 FROM {$v}
			 WHERE is_bot = 0 AND utm_campaign != '' AND visited_at BETWEEN %s AND %s
			 GROUP BY utm_campaign ORDER BY n DESC LIMIT 8",
			$from, $to
		), ARRAY_A );

		$recent = $wpdb->get_results(
			"SELECT v.id, v.visited_at, v.ip, v.url, v.page_title, v.device, v.browser, v.os,
			        v.is_bot, v.bot_name, v.referrer_domain,
			        g.country_code, g.country, g.city
			 FROM {$v} v LEFT JOIN {$g} g ON g.ip = v.ip
			 ORDER BY v.id DESC LIMIT 12",
			ARRAY_A
		);

		return array(
			'period'  => $period,
			'range'   => array( 'from' => $from, 'to' => $to ),
			'totals'  => array(
				'views'         => (int) ( isset( $totals['views'] ) ? $totals['views'] : 0 ),
				'visitors'      => (int) ( isset( $totals['visitors'] ) ? $totals['visitors'] : 0 ),
				'views_prev'    => (int) ( isset( $prev['views'] ) ? $prev['views'] : 0 ),
				'visitors_prev' => (int) ( isset( $prev['visitors'] ) ? $prev['visitors'] : 0 ),
				'bots'          => $bots,
				'active_now'    => $active_now,
			),
			'series'    => $series,
			'devices'   => (array) $devices,
			'browsers'  => (array) $browsers,
			'oses'      => (array) $oses,
			'countries' => (array) $countries,
			'map'       => (array) $map,
			'top_pages' => (array) $top_pages,
			'referrers' => (array) $referrers,
			'top_bots'  => (array) $top_bots,
			'campaigns' => (array) $campaigns,
			'recent'    => (array) $recent,
		);
	}

	/* ─────────────────────────────────────────────
	 * CANLI İZLEME
	 * ───────────────────────────────────────────── */

	/**
	 * Gerçek zamanlı panel verisi: aktif ziyaretçiler, akış, aktif sayfalar, dakikalık sparkline.
	 *
	 * @param int $window_sec "aktif" kabul edilen pencere (saniye).
	 */
	public static function get_live( $window_sec = 300 ) {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		$now_ts     = ZT_Time::local_ts();
		$active_from = gmdate( 'Y-m-d H:i:s', $now_ts - max( 60, (int) $window_sec ) );
		$today_from  = gmdate( 'Y-m-d', $now_ts ) . ' 00:00:00';
		$hour_from   = gmdate( 'Y-m-d H:i:s', $now_ts - HOUR_IN_SECONDS );
		$spark_from  = gmdate( 'Y-m-d H:i:s', $now_ts - 30 * MINUTE_IN_SECONDS );

		$active_visitors = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ip) FROM {$v} WHERE is_bot = 0 AND visited_at >= %s",
			$active_from
		) );

		$views_5m = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$v} WHERE is_bot = 0 AND visited_at >= %s",
			$active_from
		) );

		$views_hour = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$v} WHERE is_bot = 0 AND visited_at >= %s",
			$hour_from
		) );

		$views_today = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$v} WHERE is_bot = 0 AND visited_at >= %s",
			$today_from
		) );

		// Şu an aktif sayfalar.
		$active_pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, MAX(page_title) AS title, COUNT(DISTINCT ip) AS visitors, COUNT(*) AS views
			 FROM {$v} WHERE is_bot = 0 AND visited_at >= %s
			 GROUP BY url ORDER BY visitors DESC, views DESC LIMIT 8",
			$active_from
		), ARRAY_A );

		// Akış: son 25 ziyaret (botlar dahil, panelde işaretli).
		$feed = $wpdb->get_results(
			"SELECT v.id, v.visited_at, v.ip, v.url, v.page_title, v.page_type, v.referrer_domain,
			        v.device, v.browser, v.os, v.is_bot, v.bot_name,
			        g.country_code, g.country, g.city, g.lat, g.lon
			 FROM {$v} v LEFT JOIN {$g} g ON g.ip = v.ip
			 ORDER BY v.id DESC LIMIT 25",
			ARRAY_A
		);

		// Dakikalık sparkline (son 30 dk).
		$raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(visited_at, '%%Y-%%m-%%d %%H:%%i') AS b, COUNT(*) AS n
			 FROM {$v} WHERE is_bot = 0 AND visited_at >= %s GROUP BY b",
			$spark_from
		), ARRAY_A );
		$map = array();
		foreach ( (array) $raw as $r ) {
			$map[ $r['b'] ] = (int) $r['n'];
		}
		$spark = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$key     = gmdate( 'Y-m-d H:i', $now_ts - $i * MINUTE_IN_SECONDS );
			$spark[] = isset( $map[ $key ] ) ? $map[ $key ] : 0;
		}

		return array(
			'server_time'     => ZT_Time::mysql(),
			'window_sec'      => (int) $window_sec,
			'active_visitors' => $active_visitors,
			'views_5m'        => $views_5m,
			'views_hour'      => $views_hour,
			'views_today'     => $views_today,
			'active_pages'    => (array) $active_pages,
			'feed'            => (array) $feed,
			'spark'           => $spark,
		);
	}

	/* ─────────────────────────────────────────────
	 * KARŞILAŞTIRMALI RAPOR (dönem vs önceki dönem)
	 * ───────────────────────────────────────────── */

	/**
	 * @param string $period '7d' | '30d' | '90d'
	 */
	public static function get_comparison( $period ) {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		$days   = ( '30d' === $period ) ? 30 : ( ( '90d' === $period ) ? 90 : 7 );
		$period = in_array( $period, array( '7d', '30d', '90d' ), true ) ? $period : '7d';

		$now_ts   = ZT_Time::local_ts();
		$cur_from = gmdate( 'Y-m-d', $now_ts - ( $days - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';
		$cur_to   = gmdate( 'Y-m-d', $now_ts ) . ' 23:59:59';
		$prev_from = gmdate( 'Y-m-d', $now_ts - ( 2 * $days - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';
		$prev_to   = gmdate( 'Y-m-d', $now_ts - $days * DAY_IN_SECONDS ) . ' 23:59:59';

		// Toplamlar (mevcut + önceki).
		$tot = function ( $from, $to ) use ( $wpdb, $v ) {
			$r = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors,
				        SUM(CASE WHEN is_bot=1 THEN 1 ELSE 0 END) AS bots
				 FROM {$v} WHERE visited_at BETWEEN %s AND %s",
				$from, $to
			), ARRAY_A );
			return array(
				'views'    => (int) ( $r['views'] ?? 0 ) - (int) ( $r['bots'] ?? 0 ),
				'visitors' => (int) ( $r['visitors'] ?? 0 ),
				'bots'     => (int) ( $r['bots'] ?? 0 ),
			);
		};
		$cur  = $tot( $cur_from, $cur_to );
		$prev = $tot( $prev_from, $prev_to );

		// Günlük seriler (2N günlük tek sorgu, indeksle hizala).
		$raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(visited_at) AS b, COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors
			 FROM {$v} WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s GROUP BY DATE(visited_at)",
			$prev_from, $cur_to
		), ARRAY_A );
		$map = array();
		foreach ( (array) $raw as $r ) {
			$map[ $r['b'] ] = $r;
		}

		$labels = array();
		$cur_views = array(); $cur_visitors = array();
		$prev_views = array(); $prev_visitors = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$cur_d  = gmdate( 'Y-m-d', $now_ts - ( $days - 1 - $i ) * DAY_IN_SECONDS );
			$prev_d = gmdate( 'Y-m-d', $now_ts - ( 2 * $days - 1 - $i ) * DAY_IN_SECONDS );
			$labels[]        = date_i18n( 'j M', strtotime( $cur_d . ' 12:00:00' ) );
			$cur_views[]     = isset( $map[ $cur_d ] ) ? (int) $map[ $cur_d ]['views'] : 0;
			$cur_visitors[]  = isset( $map[ $cur_d ] ) ? (int) $map[ $cur_d ]['visitors'] : 0;
			$prev_views[]    = isset( $map[ $prev_d ] ) ? (int) $map[ $prev_d ]['views'] : 0;
			$prev_visitors[] = isset( $map[ $prev_d ] ) ? (int) $map[ $prev_d ]['visitors'] : 0;
		}

		// Kırılım karşılaştırması: ülke / sayfa / cihaz.
		$breakdown = function ( $select, $group ) use ( $wpdb, $v, $g, $cur_from, $cur_to, $prev_from, $prev_to ) {
			$join = ( false !== strpos( $select, 'g.' ) ) ? "INNER JOIN {$g} g ON g.ip = v.ip" : '';
			$q = function ( $from, $to ) use ( $wpdb, $v, $select, $group, $join ) {
				return $wpdb->get_results( $wpdb->prepare(
					"SELECT {$select}, COUNT(DISTINCT v.ip) AS visitors
					 FROM {$v} v {$join}
					 WHERE v.is_bot = 0 AND v.visited_at BETWEEN %s AND %s
					 GROUP BY {$group}",
					$from, $to
				), ARRAY_A );
			};
			$cur_rows  = $q( $cur_from, $cur_to );
			$prev_rows = $q( $prev_from, $prev_to );
			$prev_map = array();
			foreach ( (array) $prev_rows as $r ) {
				$prev_map[ $r['key'] ] = (int) $r['visitors'];
			}
			$out = array();
			foreach ( (array) $cur_rows as $r ) {
				$key = $r['key'];
				$p   = isset( $prev_map[ $key ] ) ? $prev_map[ $key ] : 0;
				$out[] = array(
					'key'   => $key,
					'label' => isset( $r['label'] ) ? $r['label'] : $key,
					'cur'   => (int) $r['visitors'],
					'prev'  => $p,
					'delta' => (int) $r['visitors'] - $p,
				);
			}
			usort( $out, function ( $a, $b ) {
				return $b['cur'] - $a['cur'];
			} );
			return array_slice( $out, 0, 8 );
		};

		$countries = $breakdown( "g.country_code AS `key`, g.country AS label", 'g.country_code, g.country' );
		$pages     = $breakdown( "v.url AS `key`, MAX(v.page_title) AS label", 'v.url' );
		$devices   = $breakdown( "v.device AS `key`, v.device AS label", 'v.device' );

		$split = self::new_returning( $cur_from, $cur_to, $prev_from, $prev_to, $now_ts, $days );

		return array(
			'period'   => $period,
			'days'     => $days,
			'range'    => array(
				'cur_from'  => substr( $cur_from, 0, 10 ),
				'cur_to'    => substr( $cur_to, 0, 10 ),
				'prev_from' => substr( $prev_from, 0, 10 ),
				'prev_to'   => substr( $prev_to, 0, 10 ),
			),
			'totals'   => array(
				'views'         => $cur['views'],
				'views_prev'    => $prev['views'],
				'visitors'      => $cur['visitors'],
				'visitors_prev' => $prev['visitors'],
				'bots'          => $cur['bots'],
				'bots_prev'     => $prev['bots'],
			),
			'labels'   => $labels,
			'current'  => array( 'views' => $cur_views, 'visitors' => $cur_visitors ),
			'previous' => array( 'views' => $prev_views, 'visitors' => $prev_visitors ),
			'countries' => $countries,
			'pages'     => $pages,
			'devices'   => $devices,
			'split'     => $split,
		);
	}

	/**
	 * Yeni vs dönen ziyaretçi. Bir IP, ilk-görülme (first_ever) tarihi dönem içindeyse
	 * "yeni", dönemden önceyse "dönen" sayılır. Günlük seri + toplamlar + önceki dönem.
	 */
	private static function new_returning( $cur_from, $cur_to, $prev_from, $prev_to, $now_ts, $days ) {
		global $wpdb;
		$v = self::visits_table();

		// Günlük yeni ziyaretçi (ilk-görülme günü mevcut dönemde olanlar).
		$new_daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(fe) AS d, COUNT(*) AS n FROM (
				SELECT ip, MIN(visited_at) AS fe FROM {$v} WHERE is_bot = 0 GROUP BY ip
			) t WHERE t.fe BETWEEN %s AND %s GROUP BY DATE(fe)",
			$cur_from, $cur_to
		), ARRAY_A );
		$new_map = array();
		foreach ( (array) $new_daily as $r ) {
			$new_map[ $r['d'] ] = (int) $r['n'];
		}

		// Günlük toplam tekil ziyaretçi.
		$tot_daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(visited_at) AS d, COUNT(DISTINCT ip) AS n FROM {$v}
			 WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s GROUP BY DATE(visited_at)",
			$cur_from, $cur_to
		), ARRAY_A );
		$tot_map = array();
		foreach ( (array) $tot_daily as $r ) {
			$tot_map[ $r['d'] ] = (int) $r['n'];
		}

		$new_series = array();
		$ret_series = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d  = gmdate( 'Y-m-d', $now_ts - ( $days - 1 - $i ) * DAY_IN_SECONDS );
			$nw = isset( $new_map[ $d ] ) ? $new_map[ $d ] : 0;
			$tt = isset( $tot_map[ $d ] ) ? $tot_map[ $d ] : 0;
			$new_series[] = $nw;
			$ret_series[] = max( 0, $tt - $nw );
		}

		$cur  = self::nr_totals( $cur_from, $cur_to );
		$prev = self::nr_totals( $prev_from, $prev_to );

		return array(
			'new'       => $new_series,
			'returning' => $ret_series,
			'totals'    => array(
				'new'            => $cur['new'],
				'returning'      => $cur['returning'],
				'total'          => $cur['total'],
				'new_prev'       => $prev['new'],
				'returning_prev' => $prev['returning'],
				'rate'           => $cur['total'] > 0 ? round( $cur['returning'] / $cur['total'] * 100 ) : 0,
			),
		);
	}

	/**
	 * Dönem için yeni/dönen özeti (PDF/rapor için). $period: today | 7d | 30d.
	 */
	public static function visitor_split( $period ) {
		$now_ts = ZT_Time::local_ts();
		$today  = gmdate( 'Y-m-d', $now_ts );
		if ( 'today' === $period ) {
			$from = $today . ' 00:00:00';
		} elseif ( '30d' === $period ) {
			$from = gmdate( 'Y-m-d', $now_ts - 29 * DAY_IN_SECONDS ) . ' 00:00:00';
		} else {
			$from = gmdate( 'Y-m-d', $now_ts - 6 * DAY_IN_SECONDS ) . ' 00:00:00';
		}
		$to = $today . ' 23:59:59';
		$r  = self::nr_totals( $from, $to );
		$r['rate'] = $r['total'] > 0 ? round( $r['returning'] / $r['total'] * 100 ) : 0;
		return $r;
	}

	private static function nr_totals( $from, $to ) {
		global $wpdb;
		$v = self::visits_table();
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ip) FROM {$v} WHERE is_bot = 0 AND visited_at BETWEEN %s AND %s",
			$from, $to
		) );
		$new = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT ip, MIN(visited_at) AS fe FROM {$v} WHERE is_bot = 0 GROUP BY ip
			) t WHERE t.fe BETWEEN %s AND %s",
			$from, $to
		) );
		return array(
			'total'     => $total,
			'new'       => $new,
			'returning' => max( 0, $total - $new ),
		);
	}

	/* ─────────────────────────────────────────────
	 * KOHORT (RETENTION) ANALİZİ
	 * ───────────────────────────────────────────── */

	const COHORT_EPOCH = '2020-01-06'; // Bir Pazartesi — hafta indeksi referansı.

	/**
	 * Haftalık edinim kohortları × sonraki haftalarda geri dönüş (retention) yüzdesi.
	 *
	 * @param int $weeks Kaç haftalık kohort (satır).
	 */
	public static function get_cohorts( $weeks = 8 ) {
		global $wpdb;
		$v     = self::visits_table();
		$weeks = min( 16, max( 2, (int) $weeks ) );
		$epoch = self::COHORT_EPOCH;

		$now_ts   = ZT_Time::local_ts();
		$today    = gmdate( 'Y-m-d', $now_ts );
		$cur_week = (int) $wpdb->get_var( $wpdb->prepare( "SELECT FLOOR(DATEDIFF(%s, %s)/7)", $today, $epoch ) );
		$start_wk = $cur_week - ( $weeks - 1 );
		$start_dt = gmdate( 'Y-m-d', strtotime( $epoch ) + $start_wk * 7 * DAY_IN_SECONDS ) . ' 00:00:00';

		// Kohort ataması: her IP'nin ilk-görülme haftası (yalnızca pencere içindekiler).
		$cohort_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ip, FLOOR(DATEDIFF(MIN(visited_at), %s)/7) AS cwk
			 FROM {$v} WHERE is_bot = 0 GROUP BY ip HAVING cwk >= %d",
			$epoch, $start_wk
		), ARRAY_A );

		$cohort_of = array();
		$size      = array();
		foreach ( (array) $cohort_rows as $r ) {
			$cw               = (int) $r['cwk'];
			$cohort_of[ $r['ip'] ] = $cw;
			$size[ $cw ]      = isset( $size[ $cw ] ) ? $size[ $cw ] + 1 : 1;
		}

		$retained = array();
		if ( ! empty( $cohort_of ) ) {
			$ips = array_keys( $cohort_of );
			if ( count( $ips ) > 50000 ) {
				$ips = array_slice( $ips, 0, 50000 );
			}
			$in  = "'" . implode( "','", array_map( 'esc_sql', $ips ) ) . "'";
			$act = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT ip, FLOOR(DATEDIFF(visited_at, %s)/7) AS wk
				 FROM {$v} WHERE is_bot = 0 AND visited_at >= %s AND ip IN ({$in})",
				$epoch, $start_dt
			), ARRAY_A );
			foreach ( (array) $act as $a ) {
				$ip = $a['ip'];
				if ( ! isset( $cohort_of[ $ip ] ) ) {
					continue;
				}
				$offset = (int) $a['wk'] - $cohort_of[ $ip ];
				if ( $offset < 0 || $offset >= $weeks ) {
					continue;
				}
				$cw = $cohort_of[ $ip ];
				if ( ! isset( $retained[ $cw ] ) ) {
					$retained[ $cw ] = array();
				}
				$retained[ $cw ][ $offset ] = isset( $retained[ $cw ][ $offset ] ) ? $retained[ $cw ][ $offset ] + 1 : 1;
			}
		}

		$cohorts = array();
		for ( $cw = $start_wk; $cw <= $cur_week; $cw++ ) {
			$sz    = isset( $size[ $cw ] ) ? $size[ $cw ] : 0;
			$max_o = $cur_week - $cw;
			$cells = array();
			for ( $o = 0; $o < $weeks; $o++ ) {
				if ( $o > $max_o ) {
					$cells[] = null; // gelecek hafta
				} elseif ( $sz > 0 ) {
					$ret     = isset( $retained[ $cw ][ $o ] ) ? $retained[ $cw ][ $o ] : 0;
					$cells[] = (int) round( $ret / $sz * 100 );
				} else {
					$cells[] = 0;
				}
			}
			// Hafta başı UTC gece yarısıdır; etiket de UTC'de biçimlenmeli (site saat dilimi günü kaydırmasın).
			$label     = wp_date( 'j M', strtotime( $epoch . ' 00:00:00 UTC' ) + $cw * 7 * DAY_IN_SECONDS, new DateTimeZone( 'UTC' ) );
			$cohorts[] = array( 'label' => $label, 'size' => $sz, 'cells' => $cells );
		}

		return array( 'weeks' => $weeks, 'cohorts' => $cohorts );
	}

	/* ─────────────────────────────────────────────
	 * SEGMENT KARŞILAŞTIRMA (A/B)
	 * ───────────────────────────────────────────── */

	private static function segment_where( $type, $value ) {
		global $wpdb;
		switch ( $type ) {
			case 'url':
				return array( 'v.url LIKE %s', '%' . $wpdb->esc_like( $value ) . '%', false );
			case 'campaign':
				return array( 'v.utm_campaign = %s', $value, false );
			case 'referrer':
				return array( 'v.referrer_domain LIKE %s', '%' . $wpdb->esc_like( $value ) . '%', false );
			case 'device':
				return array( 'v.device = %s', $value, false );
			case 'country':
				return array( 'g.country_code = %s', strtoupper( substr( $value, 0, 2 ) ), true );
			default:
				return null;
		}
	}

	private static function segment_metrics( $type, $value, $from, $to ) {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		$seg = self::segment_where( $type, $value );
		if ( ! $seg ) {
			return array( 'views' => 0, 'visitors' => 0, 'ppv' => 0 );
		}
		$join = $seg[2] ? "INNER JOIN {$g} g ON g.ip = v.ip" : '';
		$row  = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS views, COUNT(DISTINCT v.ip) AS visitors
			 FROM {$v} v {$join}
			 WHERE v.is_bot = 0 AND {$seg[0]} AND v.visited_at BETWEEN %s AND %s",
			$seg[1], $from, $to
		), ARRAY_A );

		$views    = (int) ( $row['views'] ?? 0 );
		$visitors = (int) ( $row['visitors'] ?? 0 );
		return array(
			'views'    => $views,
			'visitors' => $visitors,
			'ppv'      => $visitors > 0 ? round( $views / $visitors, 2 ) : 0,
		);
	}

	/**
	 * İki segmenti yan yana karşılaştırır.
	 *
	 * @param array  $a      ['type'=>..,'value'=>..,'label'=>..]
	 * @param array  $b      Aynı yapı.
	 * @param string $period 7d|30d|90d
	 */
	public static function get_segments( $a, $b, $period ) {
		$now_ts = ZT_Time::local_ts();
		$days   = ( '30d' === $period ) ? 30 : ( ( '90d' === $period ) ? 90 : 7 );
		$period = in_array( $period, array( '7d', '30d', '90d' ), true ) ? $period : '7d';
		$from   = gmdate( 'Y-m-d', $now_ts - ( $days - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';
		$to     = gmdate( 'Y-m-d', $now_ts ) . ' 23:59:59';

		return array(
			'period' => $period,
			'a'      => self::segment_metrics( $a['type'], $a['value'], $from, $to ),
			'b'      => self::segment_metrics( $b['type'], $b['value'], $from, $to ),
		);
	}

	/* ─────────────────────────────────────────────
	 * HUNİ / SEGMENT ANALİZİ
	 * ───────────────────────────────────────────── */

	/**
	 * Sıralı dönüşüm hunisi. Her adım bir URL parçasıyla eşleşir; bir ziyaretçi (IP)
	 * adım i'ye, adım i-1'i geçtiği andan SONRA eşleşen bir ziyareti varsa ulaşmış sayılır.
	 *
	 * @param array  $steps  [ ['label'=>..,'match'=>..], ... ] (en çok 8)
	 * @param string $window '7d' | '30d' | '90d' | 'all'
	 */
	public static function get_funnel( $steps, $window ) {
		global $wpdb;
		$v = self::visits_table();

		// Adımları temizle.
		$clean = array();
		foreach ( (array) $steps as $s ) {
			$match = isset( $s['match'] ) ? trim( (string) $s['match'] ) : '';
			$label = isset( $s['label'] ) ? trim( (string) $s['label'] ) : '';
			if ( '' === $match ) {
				continue;
			}
			$clean[] = array(
				'label' => '' !== $label ? mb_substr( $label, 0, 60 ) : $match,
				'match' => mb_substr( $match, 0, 190 ),
			);
			if ( count( $clean ) >= 8 ) {
				break;
			}
		}

		$now_ts = ZT_Time::local_ts();
		if ( '7d' === $window ) {
			$from = gmdate( 'Y-m-d', $now_ts - 6 * DAY_IN_SECONDS ) . ' 00:00:00';
		} elseif ( '90d' === $window ) {
			$from = gmdate( 'Y-m-d', $now_ts - 89 * DAY_IN_SECONDS ) . ' 00:00:00';
		} elseif ( 'all' === $window ) {
			$from = '2000-01-01 00:00:00';
		} else {
			$window = '30d';
			$from   = gmdate( 'Y-m-d', $now_ts - 29 * DAY_IN_SECONDS ) . ' 00:00:00';
		}
		$to = gmdate( 'Y-m-d', $now_ts ) . ' 23:59:59';

		if ( empty( $clean ) ) {
			return array( 'window' => $window, 'steps' => array(), 'from' => substr( $from, 0, 10 ), 'to' => substr( $to, 0, 10 ) );
		}

		$cap    = 20000; // güvenlik: taşınan IP kümesi tavanı.
		$result = array();
		$reach  = array(); // ip => o adıma ulaşma zamanı
		$truncated = false;

		foreach ( $clean as $i => $step ) {
			$like = '%' . $wpdb->esc_like( $step['match'] ) . '%';

			if ( 0 === $i ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ip, MIN(visited_at) AS t FROM {$v}
					 WHERE is_bot = 0 AND url LIKE %s AND visited_at BETWEEN %s AND %s
					 GROUP BY ip LIMIT %d",
					$like, $from, $to, $cap + 1
				), ARRAY_A );
				$reach = array();
				foreach ( (array) $rows as $r ) {
					$reach[ $r['ip'] ] = $r['t'];
				}
			} else {
				if ( empty( $reach ) ) {
					$reach = array();
				} else {
					$ips = array_keys( $reach );
					if ( count( $ips ) > $cap ) {
						$ips       = array_slice( $ips, 0, $cap );
						$truncated = true;
					}
					$in   = "'" . implode( "','", array_map( 'esc_sql', $ips ) ) . "'";
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT ip, visited_at FROM {$v}
						 WHERE is_bot = 0 AND url LIKE %s AND visited_at BETWEEN %s AND %s AND ip IN ({$in})
						 ORDER BY ip, visited_at",
						$like, $from, $to
					), ARRAY_A );
					$next = array();
					foreach ( (array) $rows as $r ) {
						$ip = $r['ip'];
						if ( isset( $next[ $ip ] ) ) {
							continue; // bu IP için ilk uygun ziyaret zaten bulundu
						}
						if ( isset( $reach[ $ip ] ) && $r['visited_at'] > $reach[ $ip ] ) {
							$next[ $ip ] = $r['visited_at'];
						}
					}
					$reach = $next;
				}
			}

			$result[] = array(
				'label' => $step['label'],
				'match' => $step['match'],
				'count' => count( $reach ),
			);
		}

		$first = $result[0]['count'];
		foreach ( $result as $i => &$row ) {
			$prev              = $i > 0 ? $result[ $i - 1 ]['count'] : $row['count'];
			$row['pct_first']  = $first > 0 ? round( $row['count'] / $first * 100 ) : 0;
			$row['pct_prev']   = $prev > 0 ? round( $row['count'] / $prev * 100 ) : 100;
			$row['dropoff']    = max( 0, $prev - $row['count'] );
		}
		unset( $row );

		return array(
			'window'    => $window,
			'from'      => substr( $from, 0, 10 ),
			'to'        => substr( $to, 0, 10 ),
			'entered'   => $first,
			'truncated' => $truncated,
			'steps'     => $result,
		);
	}

	/* ─────────────────────────────────────────────
	 * ISI HARİTASI
	 * ───────────────────────────────────────────── */

	public static function get_heat_urls() {
		global $wpdb;
		$h    = $wpdb->prefix . 'zt_heat';
		$rows = $wpdb->get_results(
			"SELECT url,
			        SUM(CASE WHEN type='click' THEN 1 ELSE 0 END) AS clicks,
			        SUM(CASE WHEN type='scroll' THEN 1 ELSE 0 END) AS sessions
			 FROM {$h} GROUP BY url ORDER BY clicks DESC LIMIT 100",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_heat( $url ) {
		global $wpdb;
		$h = $wpdb->prefix . 'zt_heat';

		$clicks = $wpdb->get_results( $wpdb->prepare(
			"SELECT x, y FROM {$h} WHERE url = %s AND type = 'click' ORDER BY id DESC LIMIT 3000",
			$url
		), ARRAY_A );
		$pts = array();
		foreach ( (array) $clicks as $c ) {
			$pts[] = array( (int) $c['x'], (int) $c['y'] );
		}

		$click_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$h} WHERE url = %s AND type = 'click'",
			$url
		) );

		$scrolls = $wpdb->get_col( $wpdb->prepare(
			"SELECT y FROM {$h} WHERE url = %s AND type = 'scroll'",
			$url
		) );
		$total = count( $scrolls );
		$reach = array();
		for ( $k = 1; $k <= 10; $k++ ) {
			$th  = $k * 100;
			$cnt = 0;
			foreach ( $scrolls as $d ) {
				if ( (int) $d >= $th ) {
					$cnt++;
				}
			}
			$reach[] = $total > 0 ? round( $cnt / $total * 100 ) : 0;
		}

		return array(
			'url'         => $url,
			'clicks'      => $pts,
			'click_count' => $click_count,
			'sessions'    => $total,
			'reach'       => $reach,
		);
	}

	/* ─────────────────────────────────────────────
	 * ZİYARETÇİLER (IP BAZLI TOPLULAŞTIRMA)
	 * ───────────────────────────────────────────── */

	public static function get_visitors( $args ) {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$bots     = isset( $args['bots'] ) ? $args['bots'] : 'exclude';
		$sort     = isset( $args['sort'] ) ? $args['sort'] : 'last_seen';
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$per_page = min( 100, max( 10, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 25 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( "v.ip != ''" );
		$params = array();

		if ( 'exclude' === $bots ) {
			$where[] = 'v.is_bot = 0';
		} elseif ( 'only' === $bots ) {
			$where[] = 'v.is_bot = 1';
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(v.ip LIKE %s OR g.city LIKE %s OR g.country LIKE %s OR g.isp LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['country'] ) ) {
			$where[]  = 'g.country_code = %s';
			$params[] = strtoupper( substr( $args['country'], 0, 2 ) );
		}

		$where_sql = implode( ' AND ', $where );

		$sort_map = array(
			'last_seen'  => 'last_seen DESC',
			'first_seen' => 'first_seen DESC',
			'visits'     => 'visits DESC',
			'pages'      => 'pages DESC',
		);
		$order_by = isset( $sort_map[ $sort ] ) ? $sort_map[ $sort ] : $sort_map['last_seen'];

		$total = (int) $wpdb->get_var( self::run(
			"SELECT COUNT(DISTINCT v.ip) FROM {$v} v LEFT JOIN {$g} g ON g.ip = v.ip WHERE {$where_sql}",
			$params
		) );

		$rows = $wpdb->get_results( self::run(
			"SELECT v.ip,
			        COUNT(*) AS visits,
			        COUNT(DISTINCT v.url) AS pages,
			        MIN(v.visited_at) AS first_seen,
			        MAX(v.visited_at) AS last_seen,
			        MAX(v.is_bot) AS has_bot,
			        g.country_code, g.country, g.city, g.isp, g.is_proxy, g.is_hosting
			 FROM {$v} v
			 LEFT JOIN {$g} g ON g.ip = v.ip
			 WHERE {$where_sql}
			 GROUP BY v.ip, g.country_code, g.country, g.city, g.isp, g.is_proxy, g.is_hosting
			 ORDER BY {$order_by}
			 LIMIT {$per_page} OFFSET {$offset}",
			$params
		), ARRAY_A );

		$rows = is_array( $rows ) ? $rows : array();

		// Bu sayfadaki IP'lerin son ziyaret detayını (cihaz/tarayıcı/sayfa) tek sorguda çek.
		if ( ! empty( $rows ) ) {
			$ips          = wp_list_pluck( $rows, 'ip' );
			$placeholders = implode( ',', array_fill( 0, count( $ips ), '%s' ) );
			$last_rows    = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.ip, t.device, t.browser, t.os, t.page_title, t.url
				 FROM {$v} t
				 INNER JOIN ( SELECT ip, MAX(id) AS max_id FROM {$v} WHERE ip IN ({$placeholders}) GROUP BY ip ) m
				 ON m.max_id = t.id",
				$ips
			), ARRAY_A );
			$last_map = array();
			foreach ( (array) $last_rows as $lr ) {
				$last_map[ $lr['ip'] ] = $lr;
			}
			foreach ( $rows as &$row ) {
				$lr                  = isset( $last_map[ $row['ip'] ] ) ? $last_map[ $row['ip'] ] : array();
				$row['device']       = isset( $lr['device'] ) ? $lr['device'] : '';
				$row['browser']      = isset( $lr['browser'] ) ? $lr['browser'] : '';
				$row['os']           = isset( $lr['os'] ) ? $lr['os'] : '';
				$row['last_page']    = isset( $lr['page_title'] ) ? $lr['page_title'] : '';
				$row['last_url']     = isset( $lr['url'] ) ? $lr['url'] : '';
			}
			unset( $row );
		}

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/* ─────────────────────────────────────────────
	 * ZİYARETÇİ PROFİLİ (TEK IP)
	 * ───────────────────────────────────────────── */

	public static function get_visitor_profile( $ip ) {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		$geo = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$g} WHERE ip = %s",
			$ip
		), ARRAY_A );

		$stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS visits,
			        COUNT(DISTINCT url) AS pages,
			        COUNT(DISTINCT DATE(visited_at)) AS days,
			        MIN(visited_at) AS first_seen,
			        MAX(visited_at) AS last_seen,
			        MAX(is_bot) AS has_bot
			 FROM {$v} WHERE ip = %s",
			$ip
		), ARRAY_A );

		if ( empty( $stats ) || 0 === (int) $stats['visits'] ) {
			return null;
		}

		$devices = $wpdb->get_results( $wpdb->prepare(
			"SELECT device AS k, COUNT(*) AS n FROM {$v} WHERE ip = %s GROUP BY device ORDER BY n DESC",
			$ip
		), ARRAY_A );

		$browsers = $wpdb->get_results( $wpdb->prepare(
			"SELECT CONCAT(browser, CASE WHEN browser_version != '' THEN CONCAT(' ', browser_version) ELSE '' END) AS k,
			        COUNT(*) AS n
			 FROM {$v} WHERE ip = %s GROUP BY k ORDER BY n DESC LIMIT 5",
			$ip
		), ARRAY_A );

		$top_pages = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, MAX(page_title) AS title, COUNT(*) AS views
			 FROM {$v} WHERE ip = %s GROUP BY url ORDER BY views DESC LIMIT 8",
			$ip
		), ARRAY_A );

		$referrers = $wpdb->get_results( $wpdb->prepare(
			"SELECT referrer_domain AS k, COUNT(*) AS n
			 FROM {$v} WHERE ip = %s AND referrer_domain != '' GROUP BY referrer_domain ORDER BY n DESC LIMIT 5",
			$ip
		), ARRAY_A );

		// Oturum analizi için son 500 ziyaret (eskiden yeniye).
		$visits = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, visited_at, url, page_title, page_type, referrer_domain,
			        device, browser, browser_version, os, is_bot, bot_name, user_agent
			 FROM ( SELECT * FROM {$v} WHERE ip = %s ORDER BY visited_at DESC, id DESC LIMIT 500 ) sub
			 ORDER BY visited_at ASC, id ASC",
			$ip
		), ARRAY_A );

		$sessions = self::group_sessions( (array) $visits );

		return array(
			'ip'        => $ip,
			'geo'       => $geo ? $geo : null,
			'stats'     => $stats,
			'devices'   => (array) $devices,
			'browsers'  => (array) $browsers,
			'top_pages' => (array) $top_pages,
			'referrers' => (array) $referrers,
			'sessions'  => $sessions,
		);
	}

	/**
	 * Ziyaretleri 30 dk kuralına göre oturumlara böler (yeniden eskiye sıralı döner).
	 */
	private static function group_sessions( $visits ) {
		$sessions = array();
		$current  = null;
		$prev_ts  = 0;

		foreach ( $visits as $visit ) {
			$ts = strtotime( $visit['visited_at'] );
			if ( null === $current || ( $ts - $prev_ts ) > self::SESSION_GAP ) {
				if ( null !== $current ) {
					$sessions[] = $current;
				}
				$current = array(
					'start'  => $visit['visited_at'],
					'end'    => $visit['visited_at'],
					'entry_referrer' => $visit['referrer_domain'],
					'device' => $visit['device'],
					'browser' => trim( $visit['browser'] . ' ' . $visit['browser_version'] ),
					'os'     => $visit['os'],
					'pages'  => array(),
				);
			}
			$current['end']     = $visit['visited_at'];
			$current['pages'][] = array(
				'id'         => (int) $visit['id'],
				'time'       => $visit['visited_at'],
				'url'        => $visit['url'],
				'title'      => $visit['page_title'],
				'page_type'  => $visit['page_type'],
				'is_bot'     => (int) $visit['is_bot'],
				'bot_name'   => $visit['bot_name'],
			);
			$prev_ts = $ts;
		}
		if ( null !== $current ) {
			$sessions[] = $current;
		}

		foreach ( $sessions as &$s ) {
			$s['duration']   = max( 0, strtotime( $s['end'] ) - strtotime( $s['start'] ) );
			$s['page_count'] = count( $s['pages'] );
		}
		unset( $s );

		return array_reverse( $sessions );
	}

	/* ─────────────────────────────────────────────
	 * BAKIM / TEMİZLİK
	 * ───────────────────────────────────────────── */

	public static function count_all() {
		global $wpdb;
		$v = self::visits_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$v}" );
	}

	public static function table_size_mb() {
		global $wpdb;
		$size = $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND( SUM(data_length + index_length) / 1048576, 2 )
			 FROM information_schema.TABLES
			 WHERE table_schema = %s AND table_name IN (%s, %s)",
			DB_NAME,
			self::visits_table(),
			self::geo_table()
		) );
		return $size ? (float) $size : 0.0;
	}

	/**
	 * Veri merkezi/proxy IP'lerine ait "insan" ziyaretlerini toplu olarak bot işaretler.
	 * Ayar açıldığında ve günlük bakımda çağrılır (idempotent).
	 *
	 * @return int Etkilenen satır sayısı.
	 */
	public static function sweep_datacenters() {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$v} v INNER JOIN {$g} g ON g.ip = v.ip
			 SET v.is_bot = 1, v.bot_name = %s
			 WHERE v.is_bot = 0 AND g.status = 'ok' AND ( g.is_hosting = 1 OR g.is_proxy = 1 )",
			'Veri Merkezi'
		) );
	}

	public static function delete_visit( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::visits_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function delete_ip( $ip ) {
		global $wpdb;
		$deleted = $wpdb->delete( self::visits_table(), array( 'ip' => $ip ), array( '%s' ) );
		$wpdb->delete( self::geo_table(), array( 'ip' => $ip ), array( '%s' ) );
		return (int) $deleted;
	}

	public static function delete_all() {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();
		$wpdb->query( "TRUNCATE TABLE {$v}" );
		$wpdb->query( "TRUNCATE TABLE {$g}" );
	}

	/**
	 * Saklama süresi ve satır tavanını uygular; artık geo kayıtlarını süpürür.
	 */
	public static function cleanup( $retention_days, $max_rows ) {
		global $wpdb;
		$v = self::visits_table();
		$g = self::geo_table();

		if ( $retention_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', ZT_Time::local_ts() - $retention_days * DAY_IN_SECONDS );
			// Büyük tablolarda kilitlenmemek için parça parça sil.
			do {
				$deleted = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$v} WHERE visited_at < %s LIMIT 10000",
					$cutoff
				) );
			} while ( $deleted > 0 );
		}

		if ( $max_rows > 0 ) {
			$count = self::count_all();
			if ( $count > $max_rows ) {
				$excess = $count - $max_rows;
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$v} ORDER BY id ASC LIMIT %d", $excess ) );
			}
		}

		// Ziyareti kalmamış IP'lerin geo kayıtlarını temizle.
		$wpdb->query( "DELETE g FROM {$g} g LEFT JOIN {$v} v ON v.ip = g.ip WHERE v.ip IS NULL" );

		// Isı haritası kayıtları (saklama süresi).
		$heat = $wpdb->prefix . 'zt_heat';
		if ( $retention_days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', ZT_Time::local_ts() - $retention_days * DAY_IN_SECONDS );
			do {
				$deleted = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$heat} WHERE created_at < %s LIMIT 10000",
					$cutoff
				) );
			} while ( $deleted > 0 );
		}

		// Herkese açık REST ucundan şişmeyi önlemek için ısı haritası satır tavanı (süresiz saklamada da geçerli).
		$heat_max = (int) apply_filters( 'zt_heat_max_rows', 500000 );
		if ( $heat_max > 0 ) {
			$heat_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$heat}" );
			$excess     = $heat_count - $heat_max;
			while ( $excess > 0 ) {
				$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$heat} ORDER BY id ASC LIMIT %d", min( 10000, $excess ) ) );
				if ( $deleted < 1 ) {
					break;
				}
				$excess -= $deleted;
			}
		}
	}
}
