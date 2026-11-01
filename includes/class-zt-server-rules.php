<?php
/**
 * Kara listeyi sunucu düzeyi engelleme kurallarına çevirir (Apache .htaccess / Nginx)
 * ve isteğe bağlı olarak .htaccess dosyasını WP işaretçileriyle yönetir.
 *
 * Sunucu düzeyi engelleme, WP yüklenmeden önce (önbellekli sayfalar dahil) devreye
 * girdiği için uygulama içi 403'ten daha güçlüdür.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Server_Rules {

	const MARKER = 'Ziyaretci Takibi Block';

	/**
	 * Kara liste satırını CIDR/IP'ye çevirir. '' geçersizse.
	 */
	private static function to_cidr( $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			return '';
		}
		// IPv6: joker desteklemeyiz, olduğu gibi (tek adres).
		if ( false !== strpos( $entry, ':' ) ) {
			$entry = rtrim( $entry, '*' );
			return filter_var( $entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? $entry : '';
		}
		// Joker yoksa tek IPv4.
		if ( false === strpos( $entry, '*' ) ) {
			return filter_var( $entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $entry : '';
		}
		// "85.208.*" / "45.146.56.*" / "10.*" → CIDR.
		$stripped = preg_replace( '/\.?\*+$/', '', $entry );
		$parts    = array_values( array_filter( explode( '.', $stripped ), function ( $p ) {
			return '' !== $p;
		} ) );
		$n = count( $parts );
		if ( $n < 1 || $n > 3 ) {
			return '';
		}
		foreach ( $parts as $p ) {
			if ( ! ctype_digit( $p ) || (int) $p > 255 ) {
				return '';
			}
		}
		$bits = $n * 8;
		while ( count( $parts ) < 4 ) {
			$parts[] = '0';
		}
		return implode( '.', $parts ) . '/' . $bits;
	}

	/**
	 * Kara listeden benzersiz CIDR/IP dizisi.
	 */
	public static function entries() {
		$list = (string) ZT_Settings::get( 'blocklist' );
		$out  = array();
		foreach ( preg_split( '/[\r\n,]+/', $list ) as $line ) {
			$c = self::to_cidr( $line );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * .htaccess'e yazılacak Apache yönerge satırları (işaretçi hariç).
	 */
	private static function apache_lines( $entries ) {
		if ( empty( $entries ) ) {
			return array( '# (Kara liste boş)' );
		}
		$lines   = array();
		$lines[] = '<IfModule mod_authz_core.c>';
		$lines[] = '  <RequireAll>';
		$lines[] = '    Require all granted';
		foreach ( $entries as $e ) {
			$lines[] = '    Require not ip ' . $e;
		}
		$lines[] = '  </RequireAll>';
		$lines[] = '</IfModule>';
		$lines[] = '<IfModule !mod_authz_core.c>';
		$lines[] = '  Order allow,deny';
		$lines[] = '  Allow from all';
		foreach ( $entries as $e ) {
			$lines[] = '  Deny from ' . $e;
		}
		$lines[] = '</IfModule>';
		return $lines;
	}

	private static function nginx_lines( $entries ) {
		if ( empty( $entries ) ) {
			return array( '# (Kara liste boş)' );
		}
		$lines = array( '# server { } bloğu içine ekleyin:' );
		foreach ( $entries as $e ) {
			$lines[] = 'deny ' . $e . ';';
		}
		return $lines;
	}

	/**
	 * Panelde gösterilecek kurallar + .htaccess durumu.
	 */
	public static function generate() {
		$entries = self::entries();
		return array(
			'count'    => count( $entries ),
			'apache'   => implode( "\n", self::apache_lines( $entries ) ),
			'nginx'    => implode( "\n", self::nginx_lines( $entries ) ),
			'htaccess' => self::htaccess_status(),
		);
	}

	private static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	public static function htaccess_status() {
		$path     = self::htaccess_path();
		$exists   = file_exists( $path );
		$writable = $exists ? is_writable( $path ) : wp_is_writable( dirname( $path ) );
		$managed  = false;
		if ( $exists ) {
			$content = file_get_contents( $path );
			$managed = ( false !== strpos( (string) $content, '# BEGIN ' . self::MARKER ) );
		}
		return array(
			'path'     => $path,
			'exists'   => $exists,
			'writable' => (bool) $writable,
			'managed'  => $managed,
		);
	}

	/**
	 * Apache kurallarını .htaccess'e (işaretçili blok) yazar.
	 */
	public static function htaccess_apply() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		// Yönetici kendi IP'sini kilitlemesin: .htaccess engeli wp-admin dahil tüm siteyi kapsar.
		if ( self::would_block_current() ) {
			return false;
		}
		$path = self::htaccess_path();
		$st   = self::htaccess_status();
		if ( ! $st['writable'] ) {
			return false;
		}
		return insert_with_markers( $path, self::MARKER, self::apache_lines( self::entries() ) );
	}

	/**
	 * İşaretçili bloğu (BEGIN/END satırları dahil) .htaccess'ten tamamen kaldırır.
	 *
	 * insert_with_markers() boş liste verilince işaretçileri ve açıklama satırlarını bırakır;
	 * bu durumda blok hâlâ "yönetiliyor" görünür ve ilk kayıtta kurallar yeniden yazılırdı.
	 * Bu işlev yalnızca ABSPATH ve WP işlevlerine dayanır (uninstall.php de kullanır).
	 */
	public static function htaccess_remove() {
		$path = self::htaccess_path();
		if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
			return false;
		}
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return false;
		}
		$begin   = preg_quote( '# BEGIN ' . self::MARKER, '/' );
		$end     = preg_quote( '# END ' . self::MARKER, '/' );
		$cleaned = preg_replace( '/^[ \t]*' . $begin . '.*?^[ \t]*' . $end . '[ \t]*(?:\R|\z)/ms', '', $content, 1 );
		if ( null === $cleaned ) {
			return false;
		}
		if ( $cleaned === $content ) {
			return true; // Kaldırılacak blok yok.
		}
		return false !== file_put_contents( $path, $cleaned, LOCK_EX );
	}

	/**
	 * Kara liste, şu anki isteği yapan IP'yi (yönetici) kapsıyor mu?
	 */
	public static function would_block_current() {
		$ip = ZT_Tracker::get_ip();
		return '' !== $ip && ZT_Tracker::ip_in_list( $ip, ZT_Settings::get( 'blocklist' ) );
	}

	/**
	 * .htaccess halihazırda bizim tarafımızdan yönetiliyorsa kuralları tazeler.
	 * Kara liste değiştiğinde (engelle/kaldır, ayar kaydı) çağrılır.
	 */
	public static function sync_if_managed() {
		$st = self::htaccess_status();
		if ( $st['managed'] && $st['writable'] ) {
			self::htaccess_apply();
		}
	}
}
