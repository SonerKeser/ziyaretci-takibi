<?php
/**
 * Yönetim paneli: menüler, sayfa iskeletleri, AJAX uçları, CSV dışa aktarma,
 * WordPress ana paneli özet widget'ı.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Admin {

	const CAP   = 'manage_options';
	const NONCE = 'zt_nonce';

	/** @var array Kayıtlı sayfa hook'ları (asset yüklemesi için). */
	private static $hooks = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );

		$ajax_actions = array(
			'zt_dashboard'     => 'ajax_dashboard',
			'zt_live'          => 'ajax_live',
			'zt_report'        => 'ajax_report',
			'zt_visits'        => 'ajax_visits',
			'zt_options'       => 'ajax_options',
			'zt_visitors'      => 'ajax_visitors',
			'zt_profile'       => 'ajax_profile',
			'zt_geo_now'       => 'ajax_geo_now',
			'zt_time_sync'     => 'ajax_time_sync',
			'zt_save_settings' => 'ajax_save_settings',
			'zt_test_report'   => 'ajax_test_report',
			'zt_reset_data'    => 'ajax_reset_data',
			'zt_delete_visit'  => 'ajax_delete_visit',
			'zt_delete_ip'     => 'ajax_delete_ip',
			'zt_block_ip'      => 'ajax_block_ip',
			'zt_unblock_ip'    => 'ajax_unblock_ip',
			'zt_alerts'        => 'ajax_alerts',
			'zt_alerts_seen'   => 'ajax_alerts_seen',
			'zt_alerts_clear'  => 'ajax_alerts_clear',
			'zt_block_rules'   => 'ajax_block_rules',
			'zt_htaccess'      => 'ajax_htaccess',
			'zt_funnel_run'    => 'ajax_funnel_run',
			'zt_funnels_get'   => 'ajax_funnels_get',
			'zt_funnel_save'   => 'ajax_funnel_save',
			'zt_funnel_delete' => 'ajax_funnel_delete',
			'zt_heat_urls'     => 'ajax_heat_urls',
			'zt_heat'          => 'ajax_heat',
			'zt_cohorts'       => 'ajax_cohorts',
			'zt_segments'      => 'ajax_segments',
		);
		foreach ( $ajax_actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}

		add_action( 'admin_post_zt_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_zt_pdf', array( __CLASS__, 'handle_pdf' ) );
	}

	/* ─────────────────────────────────────────────
	 * MENÜ + SAYFALAR
	 * ───────────────────────────────────────────── */

	public static function register_menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 2a8 8 0 1 0 8 8h-1.6A6.4 6.4 0 1 1 10 3.6V2z"/><path fill="black" d="M10 5a5 5 0 1 0 5 5h-1.6A3.4 3.4 0 1 1 10 6.6V5z"/><circle fill="black" cx="10" cy="10" r="1.7"/><path fill="black" d="M10.6 9.4 15.3 4.7l.9.9-4.7 4.7z"/></svg>'
		);

		self::$hooks[] = add_menu_page(
			'Ziyaretçi Takibi',
			'Ziyaretçi Takibi',
			self::CAP,
			'ziyaretci-takibi',
			array( __CLASS__, 'render_dashboard' ),
			$icon,
			58
		);

		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Genel Bakış', 'Genel Bakış', self::CAP, 'ziyaretci-takibi', array( __CLASS__, 'render_dashboard' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Canlı', 'Canlı', self::CAP, 'zt-live', array( __CLASS__, 'render_live' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Ziyaretler', 'Ziyaretler', self::CAP, 'zt-visits', array( __CLASS__, 'render_visits' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Raporlar', 'Raporlar', self::CAP, 'zt-reports', array( __CLASS__, 'render_reports' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Huniler', 'Huniler', self::CAP, 'zt-funnel', array( __CLASS__, 'render_funnel' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Isı Haritası', 'Isı Haritası', self::CAP, 'zt-heatmap', array( __CLASS__, 'render_heatmap' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Kohort & A/B', 'Kohort & A/B', self::CAP, 'zt-cohort', array( __CLASS__, 'render_cohort' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Ziyaretçiler', 'Ziyaretçiler', self::CAP, 'zt-visitors', array( __CLASS__, 'render_visitors' ) );
		self::$hooks[] = add_submenu_page( 'ziyaretci-takibi', 'Ayarlar', 'Ayarlar', self::CAP, 'zt-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function render_dashboard() {
		self::render_shell( 'dashboard' );
	}

	public static function render_live() {
		self::render_shell( 'live' );
	}

	public static function render_visits() {
		self::render_shell( 'visits' );
	}

	public static function render_visitors() {
		self::render_shell( 'visitors' );
	}

	public static function render_reports() {
		self::render_shell( 'reports' );
	}

	public static function render_funnel() {
		self::render_shell( 'funnel' );
	}

	public static function render_heatmap() {
		self::render_shell( 'heatmap' );
	}

	public static function render_cohort() {
		self::render_shell( 'cohort' );
	}

	public static function render_settings() {
		self::render_shell( 'settings' );
	}

	private static function render_shell( $page ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// WP çekirdeği admin bildirimlerini ilk h1'in altına taşır; onlara demirleme noktası ver.
		echo '<div class="wrap zt-notice-dock"><h1 class="screen-reader-text">Ziyaretçi Takibi</h1></div>';
		echo '<div class="zt-shell"><div id="zt-app" data-page="' . esc_attr( $page ) . '">';
		echo '<div class="zt-boot-loading"><div class="zt-boot-spinner"></div><p>Ziyaretçi Takibi yükleniyor…</p></div>';
		echo '</div></div>';
		echo '<noscript><div class="notice notice-error"><p>Ziyaretçi Takibi panelinin çalışması için JavaScript gereklidir.</p></div></noscript>';
	}

	/* ─────────────────────────────────────────────
	 * ASSET'LER
	 * ───────────────────────────────────────────── */

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}

		$page = 'dashboard';
		if ( isset( $_GET['page'] ) ) {
			$slug = sanitize_key( wp_unslash( $_GET['page'] ) );
			$map  = array(
				'ziyaretci-takibi' => 'dashboard',
				'zt-live'          => 'live',
				'zt-reports'       => 'reports',
				'zt-funnel'        => 'funnel',
				'zt-heatmap'       => 'heatmap',
				'zt-cohort'        => 'cohort',
				'zt-visits'        => 'visits',
				'zt-visitors'      => 'visitors',
				'zt-settings'      => 'settings',
			);
			if ( isset( $map[ $slug ] ) ) {
				$page = $map[ $slug ];
			}
		}

		wp_enqueue_style( 'zt-admin', ZT_PLUGIN_URL . 'admin/css/admin.css', array(), ZT_VERSION );
		wp_enqueue_script( 'zt-charts', ZT_PLUGIN_URL . 'admin/js/charts.js', array(), ZT_VERSION, true );

		$admin_deps = array( 'zt-charts' );

		// Dünya haritası yalnızca haritayı gösteren ekranlarda yüklenir.
		if ( in_array( $page, array( 'dashboard', 'reports', 'live' ), true ) ) {
			wp_enqueue_script( 'zt-worldmap', ZT_PLUGIN_URL . 'admin/js/worldmap.js', array(), ZT_VERSION, true );
			$admin_deps[] = 'zt-worldmap';
		}

		wp_enqueue_script( 'zt-admin', ZT_PLUGIN_URL . 'admin/js/admin.js', $admin_deps, ZT_VERSION, true );

		$boot = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'page'      => $page,
			'version'   => ZT_VERSION,
			'exportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=zt_export' ), 'zt_export' ),
			'pdfUrl'    => wp_nonce_url( admin_url( 'admin-post.php?action=zt_pdf' ), 'zt_pdf' ),
			'pages'     => array(
				'dashboard' => admin_url( 'admin.php?page=ziyaretci-takibi' ),
				'live'      => admin_url( 'admin.php?page=zt-live' ),
				'reports'   => admin_url( 'admin.php?page=zt-reports' ),
				'funnel'    => admin_url( 'admin.php?page=zt-funnel' ),
				'heatmap'   => admin_url( 'admin.php?page=zt-heatmap' ),
				'cohort'    => admin_url( 'admin.php?page=zt-cohort' ),
				'visits'    => admin_url( 'admin.php?page=zt-visits' ),
				'visitors'  => admin_url( 'admin.php?page=zt-visitors' ),
				'settings'  => admin_url( 'admin.php?page=zt-settings' ),
			),
			'geoEnabled'    => (int) ZT_Settings::get( 'geo_enabled' ),
			'alertsEnabled' => (int) ZT_Settings::get( 'alerts_enabled' ),
			// Kayıtlar site saat diliminde saklanır; panel bunu gerçek zaman anına çevirir.
			'gmtOffset'     => (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ),
			'tzLabel'       => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC',
		);

		if ( 'settings' === $page ) {
			$boot['settings']   = ZT_Settings::all();
			$boot['roles']      = wp_roles()->get_names();
			$boot['adminEmail'] = get_option( 'admin_email' );
			$boot['dbInfo']     = array(
				'rows'    => ZT_DB::count_all(),
				'sizeMb'  => ZT_DB::table_size_mb(),
				'pending' => ZT_Geo::pending_count(),
			);
			$boot['timeStatus'] = ZT_Time::status();
		}

		if ( 'funnel' === $page ) {
			$boot['funnels'] = self::get_funnels();
		}

		// Ziyaretler sayfasına derin bağlantı ile gelinirse (ör. profilden/haritadan) ön filtreler.
		if ( isset( $_GET['ip'] ) ) {
			$boot['presetIp'] = sanitize_text_field( wp_unslash( $_GET['ip'] ) );
		}
		if ( isset( $_GET['country'] ) ) {
			$boot['presetCountry'] = strtoupper( substr( sanitize_text_field( wp_unslash( $_GET['country'] ) ), 0, 2 ) );
		}

		wp_localize_script( 'zt-admin', 'ZT_BOOT', $boot );
	}

	/* ─────────────────────────────────────────────
	 * AJAX YARDIMCILARI
	 * ───────────────────────────────────────────── */

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Yetkiniz yok.' ), 403 );
		}
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	private static function collect_filters() {
		return array(
			'from'      => self::post( 'from' ),
			'to'        => self::post( 'to' ),
			'ip'        => self::post( 'ip' ),
			'country'   => self::post( 'country' ),
			'device'    => self::post( 'device' ),
			'browser'   => self::post( 'browser' ),
			'os'        => self::post( 'os' ),
			'bots'      => self::post( 'bots', 'include' ),
			'search'    => self::post( 'search' ),
			'referrer'  => self::post( 'referrer' ),
			'campaign'  => self::post( 'campaign' ),
			'page_type' => self::post( 'page_type' ),
		);
	}

	/* ─────────────────────────────────────────────
	 * AJAX UÇLARI
	 * ───────────────────────────────────────────── */

	public static function ajax_dashboard() {
		self::guard();

		// Panel açıkken bekleyen konumları fırsatçı işle (kilit sınıf içinde).
		if ( ZT_Settings::get( 'geo_enabled' ) && ZT_Geo::pending_count() > 0 ) {
			ZT_Geo::process_pending( 100 );
		}

		$period = self::post( 'period', '7d' );
		$data   = ZT_DB::get_dashboard( $period );

		$data['geo_pending'] = ZT_Geo::pending_count();

		wp_send_json_success( $data );
	}

	public static function ajax_live() {
		self::guard();
		wp_send_json_success( ZT_DB::get_live( 300 ) );
	}

	public static function ajax_report() {
		self::guard();
		wp_send_json_success( ZT_DB::get_comparison( self::post( 'period', '7d' ) ) );
	}

	private static function read_funnel_steps() {
		$raw   = isset( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : '[]';
		$steps = json_decode( (string) $raw, true );
		$clean = array();
		if ( is_array( $steps ) ) {
			foreach ( $steps as $s ) {
				$m = isset( $s['match'] ) ? sanitize_text_field( (string) $s['match'] ) : '';
				$l = isset( $s['label'] ) ? sanitize_text_field( (string) $s['label'] ) : '';
				if ( '' !== $m ) {
					$clean[] = array( 'label' => mb_substr( $l, 0, 60 ), 'match' => mb_substr( $m, 0, 190 ) );
				}
				if ( count( $clean ) >= 8 ) {
					break;
				}
			}
		}
		return $clean;
	}

	public static function ajax_funnel_run() {
		self::guard();
		wp_send_json_success( ZT_DB::get_funnel( self::read_funnel_steps(), self::post( 'window', '30d' ) ) );
	}

	/**
	 * Kayıtlı huniler (eski tekil zt_funnel'dan göç eder).
	 */
	private static function get_funnels() {
		$funnels = get_option( 'zt_funnels', null );
		if ( ! is_array( $funnels ) ) {
			$funnels = array();
			$old     = get_option( 'zt_funnel', null );
			if ( is_array( $old ) && ! empty( $old['steps'] ) ) {
				$funnels[] = array(
					'id'     => uniqid( 'f' ),
					'name'   => 'Huni 1',
					'steps'  => $old['steps'],
					'window' => isset( $old['window'] ) ? $old['window'] : '30d',
				);
			}
			update_option( 'zt_funnels', $funnels );
		}
		return array_values( $funnels );
	}

	public static function ajax_funnels_get() {
		self::guard();
		wp_send_json_success( array( 'funnels' => self::get_funnels() ) );
	}

	public static function ajax_funnel_save() {
		self::guard();
		$funnels = self::get_funnels();

		$id     = self::post( 'id' );
		$name   = self::post( 'name' );
		$name   = '' !== $name ? mb_substr( $name, 0, 60 ) : 'Adsız huni';
		$window = self::post( 'window', '30d' );
		if ( ! in_array( $window, array( '7d', '30d', '90d', 'all' ), true ) ) {
			$window = '30d';
		}
		$steps = self::read_funnel_steps();

		$found = false;
		if ( '' !== $id ) {
			foreach ( $funnels as &$f ) {
				if ( isset( $f['id'] ) && $f['id'] === $id ) {
					$f['name']   = $name;
					$f['steps']  = $steps;
					$f['window'] = $window;
					$found       = true;
					break;
				}
			}
			unset( $f );
		}
		if ( ! $found ) {
			$id        = uniqid( 'f' );
			$funnels[] = array( 'id' => $id, 'name' => $name, 'steps' => $steps, 'window' => $window );
		}
		if ( count( $funnels ) > 20 ) {
			$funnels = array_slice( $funnels, -20 );
		}
		update_option( 'zt_funnels', array_values( $funnels ) );

		wp_send_json_success( array( 'id' => $id, 'funnels' => self::get_funnels(), 'message' => 'Huni kaydedildi.' ) );
	}

	public static function ajax_funnel_delete() {
		self::guard();
		$id      = self::post( 'id' );
		$funnels = array_values( array_filter( self::get_funnels(), function ( $f ) use ( $id ) {
			return ! isset( $f['id'] ) || $f['id'] !== $id;
		} ) );
		update_option( 'zt_funnels', $funnels );
		wp_send_json_success( array( 'funnels' => $funnels, 'message' => 'Huni silindi.' ) );
	}

	public static function ajax_heat_urls() {
		self::guard();
		wp_send_json_success( array( 'urls' => ZT_DB::get_heat_urls() ) );
	}

	public static function ajax_heat() {
		self::guard();
		$url = self::post( 'url' );
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => 'URL belirtilmedi.' ) );
		}
		wp_send_json_success( ZT_DB::get_heat( $url ) );
	}

	public static function ajax_cohorts() {
		self::guard();
		wp_send_json_success( ZT_DB::get_cohorts( (int) self::post( 'weeks', 8 ) ) );
	}

	public static function ajax_segments() {
		self::guard();
		$a = array( 'type' => self::post( 'a_type', 'url' ), 'value' => self::post( 'a_value' ) );
		$b = array( 'type' => self::post( 'b_type', 'url' ), 'value' => self::post( 'b_value' ) );
		wp_send_json_success( ZT_DB::get_segments( $a, $b, self::post( 'period', '7d' ) ) );
	}

	public static function ajax_alerts() {
		self::guard();
		wp_send_json_success( array(
			'items'   => ZT_Alerts::recent( 30 ),
			'unseen'  => ZT_Alerts::unseen_count(),
			'enabled' => (int) ZT_Settings::get( 'alerts_enabled' ),
		) );
	}

	public static function ajax_alerts_seen() {
		self::guard();
		ZT_Alerts::mark_seen();
		wp_send_json_success( array( 'unseen' => 0 ) );
	}

	public static function ajax_alerts_clear() {
		self::guard();
		ZT_Alerts::clear_all();
		wp_send_json_success( array( 'message' => 'Uyarılar temizlendi.' ) );
	}

	public static function ajax_visits() {
		self::guard();

		$result = ZT_DB::get_visits(
			self::collect_filters(),
			(int) self::post( 'paged', 1 ),
			(int) self::post( 'per_page', 50 ),
			self::post( 'order', 'DESC' )
		);

		wp_send_json_success( $result );
	}

	public static function ajax_options() {
		self::guard();
		wp_send_json_success( ZT_DB::get_filter_options() );
	}

	public static function ajax_visitors() {
		self::guard();

		$result = ZT_DB::get_visitors( array(
			'search'   => self::post( 'search' ),
			'bots'     => self::post( 'bots', 'exclude' ),
			'sort'     => self::post( 'sort', 'last_seen' ),
			'country'  => self::post( 'country' ),
			'page'     => (int) self::post( 'paged', 1 ),
			'per_page' => (int) self::post( 'per_page', 25 ),
		) );

		wp_send_json_success( $result );
	}

	public static function ajax_profile() {
		self::guard();

		$ip = self::post( 'ip' );
		if ( '' === $ip ) {
			wp_send_json_error( array( 'message' => 'IP belirtilmedi.' ) );
		}

		$profile = ZT_DB::get_visitor_profile( $ip );
		if ( null === $profile ) {
			wp_send_json_error( array( 'message' => 'Bu IP için kayıt bulunamadı.' ) );
		}

		$profile['blocked'] = ZT_Tracker::ip_in_list( $ip, ZT_Settings::get( 'blocklist' ) );

		wp_send_json_success( $profile );
	}

	public static function ajax_block_ip() {
		self::guard();
		$ip = self::post( 'ip' );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			wp_send_json_error( array( 'message' => 'Geçersiz IP adresi.' ) );
		}
		self::update_blocklist( $ip, true );
		wp_send_json_success( array( 'message' => $ip . ' engellendi.', 'blocked' => true ) );
	}

	public static function ajax_unblock_ip() {
		self::guard();
		$ip = self::post( 'ip' );
		if ( '' === $ip ) {
			wp_send_json_error( array( 'message' => 'IP belirtilmedi.' ) );
		}
		self::update_blocklist( $ip, false );
		wp_send_json_success( array( 'message' => $ip . ' engeli kaldırıldı.', 'blocked' => false ) );
	}

	private static function update_blocklist( $ip, $add ) {
		$settings = ZT_Settings::all();
		$list     = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $settings['blocklist'] ) ) );
		$list     = array_values( array_diff( $list, array( $ip ) ) ); // önce çıkar
		if ( $add ) {
			$list[] = $ip;
		}
		$settings['blocklist'] = implode( "\n", array_unique( $list ) );
		ZT_Settings::save( $settings );
		ZT_Server_Rules::sync_if_managed();
	}

	public static function ajax_block_rules() {
		self::guard();
		wp_send_json_success( ZT_Server_Rules::generate() );
	}

	public static function ajax_htaccess() {
		self::guard();
		$action = self::post( 'op' );
		if ( 'apply' === $action ) {
			$ok = ZT_Server_Rules::htaccess_apply();
			if ( $ok ) {
				wp_send_json_success( array( 'message' => 'Kurallar .htaccess dosyasına yazıldı.', 'status' => ZT_Server_Rules::htaccess_status() ) );
			}
			wp_send_json_error( array( 'message' => '.htaccess yazılamadı. Dosya izinlerini kontrol edin.' ) );
		} elseif ( 'remove' === $action ) {
			$ok = ZT_Server_Rules::htaccess_remove();
			if ( $ok ) {
				wp_send_json_success( array( 'message' => 'Kurallar .htaccess dosyasından kaldırıldı.', 'status' => ZT_Server_Rules::htaccess_status() ) );
			}
			wp_send_json_error( array( 'message' => '.htaccess güncellenemedi.' ) );
		}
		wp_send_json_error( array( 'message' => 'Geçersiz işlem.' ) );
	}

	public static function ajax_geo_now() {
		self::guard();
		$done = ZT_Geo::process_pending( 100 );
		wp_send_json_success( array(
			'done'    => $done,
			'pending' => ZT_Geo::pending_count(),
		) );
	}

	public static function ajax_time_sync() {
		self::guard();
		$ok = ZT_Time::sync();
		$st = ZT_Time::status();
		wp_send_json_success( array(
			'synced'  => $ok,
			'status'  => $st,
			'message' => $ok
				? 'Zaman senkronize edildi (kaynak: ' . $st['source'] . ').'
				: 'Hiçbir kaynağa ulaşılamadı. Sunucunun giden HTTPS bağlantısını kontrol edin.',
		) );
	}

	public static function ajax_save_settings() {
		self::guard();

		$raw = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';
		$input = json_decode( $raw, true );
		if ( ! is_array( $input ) ) {
			wp_send_json_error( array( 'message' => 'Geçersiz ayar verisi.' ) );
		}

		$saved = ZT_Settings::save( $input );

		// Kara liste .htaccess'te yönetiliyorsa kuralları tazele.
		ZT_Server_Rules::sync_if_managed();

		// Veri merkezi bayrağı açıksa mevcut ziyaretleri hemen temizle.
		$swept = 0;
		if ( ! empty( $saved['flag_datacenter'] ) ) {
			$swept = ZT_DB::sweep_datacenters();
		}

		wp_send_json_success( array(
			'settings' => $saved,
			'swept'    => $swept,
			'message'  => 'Ayarlar kaydedildi.' . ( $swept > 0 ? ' ' . $swept . ' veri merkezi ziyareti bot olarak işaretlendi.' : '' ),
		) );
	}

	public static function ajax_test_report() {
		self::guard();
		$sent = ZT_Report::send_weekly( true );
		if ( $sent ) {
			$to = ZT_Settings::get( 'report_email' );
			if ( ! is_email( $to ) ) {
				$to = get_option( 'admin_email' );
			}
			wp_send_json_success( array( 'message' => 'Test raporu ' . $to . ' adresine gönderildi.' ) );
		}
		wp_send_json_error( array( 'message' => 'E-posta gönderilemedi. Sitenizin e-posta ayarlarını kontrol edin.' ) );
	}

	public static function ajax_reset_data() {
		self::guard();

		if ( 'SIFIRLA' !== self::post( 'confirm' ) ) {
			wp_send_json_error( array( 'message' => 'Onay metni doğrulanamadı.' ) );
		}

		ZT_DB::delete_all();
		wp_send_json_success( array( 'message' => 'Tüm ziyaret verileri silindi.' ) );
	}

	public static function ajax_delete_visit() {
		self::guard();

		$id = (int) self::post( 'id', 0 );
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Geçersiz kayıt.' ) );
		}
		ZT_DB::delete_visit( $id );
		wp_send_json_success( array( 'message' => 'Kayıt silindi.' ) );
	}

	public static function ajax_delete_ip() {
		self::guard();

		$ip = self::post( 'ip' );
		if ( '' === $ip ) {
			wp_send_json_error( array( 'message' => 'IP belirtilmedi.' ) );
		}
		$deleted = ZT_DB::delete_ip( $ip );
		wp_send_json_success( array( 'message' => $deleted . ' kayıt silindi.' ) );
	}

	/* ─────────────────────────────────────────────
	 * CSV DIŞA AKTARMA
	 * ───────────────────────────────────────────── */

	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Yetkiniz yok.' );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'zt_export' ) ) {
			wp_die( 'Güvenlik doğrulaması başarısız.' );
		}

		$get = function ( $key, $default = '' ) {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
		};

		$filters = array(
			'from'      => $get( 'from' ),
			'to'        => $get( 'to' ),
			'ip'        => $get( 'ip' ),
			'country'   => $get( 'country' ),
			'device'    => $get( 'device' ),
			'browser'   => $get( 'browser' ),
			'os'        => $get( 'os' ),
			'bots'      => $get( 'bots', 'include' ),
			'search'    => $get( 'search' ),
			'referrer'  => $get( 'referrer' ),
			'campaign'  => $get( 'campaign' ),
			'page_type' => $get( 'page_type' ),
		);

		$filename = 'ziyaretci-takibi-' . wp_date( 'Y-m-d-Hi', ZT_Time::now_ts() ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// Türkçe Excel uyumu: UTF-8 BOM + noktalı virgül ayracı.
		fwrite( $out, "\xEF\xBB\xBF" );

		$header = array(
			'Tarih', 'IP', 'Ülke', 'Şehir', 'ISS', 'Sayfa Başlığı', 'URL', 'Sayfa Türü',
			'Kaynak', 'Cihaz', 'Tarayıcı', 'Sürüm', 'İşletim Sistemi', 'Bot', 'Bot Adı', 'Kullanıcı ID',
			'Kampanya', 'UTM Kaynak', 'UTM Medya',
		);
		fputcsv( $out, $header, ';' );

		$page = 1;
		do {
			$result = ZT_DB::get_visits( $filters, $page, 200, 'DESC' );
			foreach ( $result['rows'] as $row ) {
				fputcsv( $out, array(
					$row['visited_at'],
					$row['ip'],
					$row['country'],
					$row['city'],
					$row['isp'],
					$row['page_title'],
					$row['url'],
					$row['page_type'],
					$row['referrer_domain'],
					$row['device'],
					$row['browser'],
					$row['browser_version'],
					$row['os'],
					$row['is_bot'] ? 'Evet' : 'Hayır',
					$row['bot_name'],
					$row['user_id'],
					isset( $row['utm_campaign'] ) ? $row['utm_campaign'] : '',
					isset( $row['utm_source'] ) ? $row['utm_source'] : '',
					isset( $row['utm_medium'] ) ? $row['utm_medium'] : '',
				), ';' );
			}
			$page++;
			$has_more = count( $result['rows'] ) === 200 && $page <= 1000; // en fazla 200.000 satır
		} while ( $has_more );

		fclose( $out );
		exit;
	}

	/* ─────────────────────────────────────────────
	 * PDF / YAZDIRILABİLİR RAPOR
	 * ───────────────────────────────────────────── */

	public static function handle_pdf() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Yetkiniz yok.' );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'zt_pdf' ) ) {
			wp_die( 'Güvenlik doğrulaması başarısız.' );
		}

		$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '7d';
		if ( ! in_array( $period, array( 'today', '7d', '30d' ), true ) ) {
			$period = '7d';
		}
		$labels = array( 'today' => 'Bugün', '7d' => 'Son 7 Gün', '30d' => 'Son 30 Gün' );

		$d     = ZT_DB::get_dashboard( $period );
		$split = ZT_DB::visitor_split( $period );
		$t     = $d['totals'];
		$brand = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$fmt = function ( $n ) {
			return number_format_i18n( (int) $n );
		};

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!doctype html><html lang="tr"><head><meta charset="utf-8">';
		echo '<title>Ziyaretçi Raporu — ' . esc_html( $brand ) . '</title>';
		echo '<style>' . self::pdf_css() . '</style></head><body>';

		echo '<div class="zt-pdf-toolbar"><button onclick="window.print()">🖨️ Yazdır / PDF Kaydet</button><span>Tarayıcının yazdırma penceresinde "Hedef: PDF olarak kaydet" seçin.</span></div>';

		echo '<div class="zt-pdf">';

		// Başlık.
		echo '<div class="zt-pdf-head"><div><div class="brand">' . esc_html( $brand ) . '</div>';
		echo '<div class="title">Ziyaretçi Raporu</div></div>';
		echo '<div class="meta"><div><b>Dönem:</b> ' . esc_html( $labels[ $period ] ) . '</div>';
		echo '<div>' . esc_html( substr( $d['range']['from'], 0, 10 ) ) . ' – ' . esc_html( substr( $d['range']['to'], 0, 10 ) ) . '</div>';
		echo '<div class="muted">Oluşturuldu: ' . esc_html( wp_date( 'd.m.Y H:i', ZT_Time::now_ts() ) ) . '</div></div></div>';

		// İstatistik kartları.
		$stats = array(
			array( 'Tekil Ziyaretçi', $fmt( $t['visitors'] ) ),
			array( 'Sayfa Görüntüleme', $fmt( $t['views'] ) ),
			array( 'Yeni / Dönen', $fmt( $split['new'] ) . ' / ' . $fmt( $split['returning'] ) ),
			array( 'Bot Trafiği', $fmt( $t['bots'] ) ),
		);
		echo '<div class="zt-pdf-stats">';
		foreach ( $stats as $s ) {
			echo '<div class="stat"><div class="num">' . esc_html( $s[1] ) . '</div><div class="lbl">' . esc_html( $s[0] ) . '</div></div>';
		}
		echo '</div>';

		// Trend grafiği (satır içi SVG).
		echo '<div class="zt-pdf-section"><h3>Trafik Eğilimi</h3>' . self::pdf_bars( $d['series'] ) . '</div>';

		// İki sütunlu tablolar.
		echo '<div class="zt-pdf-cols">';
		echo self::pdf_table( 'Ülkeler', $d['countries'], function ( $r ) {
			return array( self::pdf_flag( $r['code'] ) . ' ' . $r['name'], number_format_i18n( (int) $r['visitors'] ) );
		} );
		echo self::pdf_table( 'En Çok Görüntülenen Sayfalar', $d['top_pages'], function ( $r ) {
			$title = '' !== $r['title'] ? $r['title'] : $r['url'];
			return array( mb_substr( $title, 0, 48 ), number_format_i18n( (int) $r['views'] ) );
		} );
		echo '</div>';

		echo '<div class="zt-pdf-cols">';
		echo self::pdf_table( 'Geliş Kaynakları', $d['referrers'], function ( $r ) {
			return array( $r['k'], number_format_i18n( (int) $r['n'] ) );
		} );
		echo self::pdf_table( 'Cihazlar / Tarayıcılar', array_merge(
			array_map( function ( $r ) {
				$m = array( 'desktop' => 'Masaüstü', 'mobile' => 'Mobil', 'tablet' => 'Tablet' );
				return array( 'k' => isset( $m[ $r['k'] ] ) ? $m[ $r['k'] ] : $r['k'], 'n' => $r['n'] );
			}, $d['devices'] ),
			array_slice( (array) $d['browsers'], 0, 4 )
		), function ( $r ) {
			return array( $r['k'], number_format_i18n( (int) $r['n'] ) );
		} );
		echo '</div>';

		echo '<div class="zt-pdf-foot">Ziyaretçi Takibi eklentisi · ' . esc_html( home_url() ) . '</div>';
		echo '</div>';

		echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},350);});</script>';
		echo '</body></html>';
		exit;
	}

	private static function pdf_flag( $cc ) {
		$cc = strtoupper( (string) $cc );
		if ( 2 !== strlen( $cc ) || ! ctype_alpha( $cc ) ) {
			return '🌐';
		}
		$a = 0x1F1E6 + ( ord( $cc[0] ) - 65 );
		$b = 0x1F1E6 + ( ord( $cc[1] ) - 65 );
		return mb_convert_encoding( '&#' . $a . ';&#' . $b . ';', 'UTF-8', 'HTML-ENTITIES' );
	}

	private static function pdf_table( $title, $rows, $row_fn ) {
		$html = '<div class="zt-pdf-section"><h3>' . esc_html( $title ) . '</h3>';
		if ( empty( $rows ) ) {
			return $html . '<p class="muted">Veri yok</p></div>';
		}
		$html .= '<table>';
		foreach ( $rows as $r ) {
			$cells = call_user_func( $row_fn, $r );
			$html .= '<tr><td>' . esc_html( $cells[0] ) . '</td><td class="num">' . esc_html( $cells[1] ) . '</td></tr>';
		}
		return $html . '</table></div>';
	}

	private static function pdf_bars( $series ) {
		$series = (array) $series;
		$n      = count( $series );
		if ( 0 === $n ) {
			return '<p class="muted">Veri yok</p>';
		}
		$vals = array();
		foreach ( $series as $s ) {
			$vals[] = (int) $s['views'];
		}
		$max   = max( 1, max( $vals ) );
		$w     = 760;
		$h     = 170;
		$padB  = 24;
		$plotH = $h - $padB - 6;
		$slot  = $w / $n;
		$bw    = min( 26, $slot * 0.6 );

		$svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" preserveAspectRatio="none" style="display:block">';
		$step = max( 1, (int) ceil( $n / 10 ) );
		foreach ( $series as $i => $s ) {
			$val = (int) $s['views'];
			$bh  = $max > 0 ? ( $val / $max ) * $plotH : 0;
			$x   = $slot * $i + ( $slot - $bw ) / 2;
			$y   = 6 + $plotH - $bh;
			$svg .= '<rect x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( $bh, 1 ) . '" rx="2" fill="#6366f1"></rect>';
			if ( 0 === $i % $step ) {
				$svg .= '<text x="' . round( $slot * $i + $slot / 2, 1 ) . '" y="' . ( $h - 8 ) . '" text-anchor="middle" font-size="9" fill="#64748b">' . esc_html( $s['label'] ) . '</text>';
			}
		}
		return $svg . '</svg>';
	}

	private static function pdf_css() {
		return 'body{margin:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#0f172a}'
			. '.zt-pdf-toolbar{background:#0f172a;color:#fff;padding:12px 20px;display:flex;align-items:center;gap:14px;font-size:13px}'
			. '.zt-pdf-toolbar button{background:#6366f1;color:#fff;border:0;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer}'
			. '.zt-pdf-toolbar span{color:#94a3b8}'
			. '.zt-pdf{max-width:820px;margin:20px auto;background:#fff;padding:32px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08)}'
			. '.zt-pdf-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #6366f1;padding-bottom:16px;margin-bottom:20px}'
			. '.zt-pdf-head .brand{font-size:20px;font-weight:800}.zt-pdf-head .title{font-size:13px;color:#64748b;margin-top:2px}'
			. '.zt-pdf-head .meta{text-align:right;font-size:12px;line-height:1.7}.zt-pdf-head .meta .muted{color:#94a3b8}'
			. '.zt-pdf-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:22px}'
			. '.zt-pdf-stats .stat{background:#f8fafc;border-radius:9px;padding:14px;text-align:center}'
			. '.zt-pdf-stats .num{font-size:22px;font-weight:800}.zt-pdf-stats .lbl{font-size:11px;color:#64748b;margin-top:3px}'
			. '.zt-pdf-section{margin-bottom:20px}.zt-pdf-section h3{font-size:13px;margin:0 0 10px;color:#334155;border-left:3px solid #6366f1;padding-left:8px}'
			. '.zt-pdf-cols{display:grid;grid-template-columns:1fr 1fr;gap:24px}'
			. 'table{width:100%;border-collapse:collapse;font-size:12px}'
			. 'td{padding:6px 4px;border-bottom:1px solid #eef2f7}td.num{text-align:right;font-weight:700;white-space:nowrap}'
			. '.muted{color:#94a3b8;font-size:12px}'
			. '.zt-pdf-foot{margin-top:24px;padding-top:14px;border-top:1px solid #eef2f7;text-align:center;color:#94a3b8;font-size:11px}'
			. '@media print{body{background:#fff}.zt-pdf-toolbar{display:none}.zt-pdf{box-shadow:none;margin:0;max-width:100%;padding:0}@page{margin:1.4cm}}';
	}

	/* ─────────────────────────────────────────────
	 * WP ANA PANEL WIDGET'I
	 * ───────────────────────────────────────────── */

	public static function register_widget() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_add_dashboard_widget( 'zt_summary', 'Ziyaretçi Takibi', array( __CLASS__, 'render_widget' ) );
	}

	public static function render_widget() {
		$data   = ZT_DB::get_dashboard( 'today' );
		$totals = $data['totals'];

		$top_page = '';
		if ( ! empty( $data['top_pages'] ) ) {
			$first    = $data['top_pages'][0];
			$top_page = '' !== $first['title'] ? $first['title'] : $first['url'];
		}

		echo '<div style="display:flex;gap:12px;text-align:center;margin:4px 0 12px;">';
		$boxes = array(
			array( number_format_i18n( $totals['visitors'] ), 'Bugünkü Ziyaretçi' ),
			array( number_format_i18n( $totals['views'] ), 'Görüntüleme' ),
			array( number_format_i18n( $totals['active_now'] ), 'Şu An Aktif' ),
		);
		foreach ( $boxes as $box ) {
			echo '<div style="flex:1;background:#f0f0f1;border-radius:8px;padding:12px 6px;">';
			echo '<div style="font-size:22px;font-weight:700;color:#1d2327;line-height:1.2;">' . esc_html( $box[0] ) . '</div>';
			echo '<div style="font-size:11px;color:#646970;margin-top:2px;">' . esc_html( $box[1] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		if ( '' !== $top_page ) {
			echo '<p style="margin:0 0 10px;color:#646970;font-size:12px;">Bugünün en çok görüntülenen sayfası: <strong>' . esc_html( mb_substr( $top_page, 0, 60 ) ) . '</strong></p>';
		}

		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=ziyaretci-takibi' ) ) . '">Panele Git</a>';
	}
}
