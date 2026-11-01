<?php
/**
 * Haftalık özet e-postası: son 7 günün istatistiklerini HTML olarak yöneticiye gönderir.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_Report {

	/**
	 * Haftalık raporu gönderir. Aynı gün ikinci kez çağrılırsa tekrar göndermez.
	 *
	 * @param bool $force Guard'ı yok say (elle test için).
	 * @return bool Gönderim denendiyse true.
	 */
	public static function send_weekly( $force = false ) {
		if ( ! $force ) {
			$last = get_option( 'zt_last_report', '' );
			$today = gmdate( 'Y-m-d', ZT_Time::now_ts() );
			if ( $last === $today ) {
				return false;
			}
			update_option( 'zt_last_report', $today );
		}

		$to = ZT_Settings::get( 'report_email' );
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return false;
		}

		$data    = ZT_DB::get_dashboard( '7d' );
		$subject = sprintf( '📊 %s — Haftalık Ziyaretçi Özeti', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = self::build_html( $data );

		$headers     = array( 'Content-Type: text/html; charset=UTF-8' );
		$attachments = array();
		$tmp_path    = '';

		if ( ZT_Settings::get( 'attach_pdf' ) ) {
			$bytes = self::build_pdf( '7d' );
			if ( $bytes ) {
				$name     = 'ziyaretci-rapor-' . gmdate( 'Y-m-d' ) . '.pdf';
				$tmp_path = trailingslashit( get_temp_dir() ) . $name;
				if ( false !== @file_put_contents( $tmp_path, $bytes ) ) {
					$attachments[] = $tmp_path;
				} else {
					$tmp_path = '';
				}
			}
		}

		$sent = (bool) wp_mail( $to, $subject, $body, $headers, $attachments );

		if ( '' !== $tmp_path && file_exists( $tmp_path ) ) {
			@unlink( $tmp_path );
		}

		return $sent;
	}

	/* ─────────────────────────────────────────────
	 * PDF RAPORU (ZT_PDF ile)
	 * ───────────────────────────────────────────── */

	/**
	 * @param string $period today | 7d | 30d
	 * @return string PDF bayt dizesi.
	 */
	public static function build_pdf( $period ) {
		$labels = array( 'today' => 'Bugün', '7d' => 'Son 7 Gün', '30d' => 'Son 30 Gün' );
		if ( ! isset( $labels[ $period ] ) ) {
			$period = '7d';
		}

		$d     = ZT_DB::get_dashboard( $period );
		$split = ZT_DB::visitor_split( $period );
		$t     = $d['totals'];
		$brand = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$gray   = array( 0.40, 0.45, 0.50 );
		$accent = array( 0.39, 0.40, 0.95 );
		$dark   = array( 0.20, 0.25, 0.33 );
		$fmt    = function ( $n ) {
			return number_format_i18n( (int) $n );
		};

		$pdf = new ZT_PDF();

		// Başlık.
		$pdf->text( 40, 54, 18, $brand, true );
		$pdf->text( 40, 70, 10.5, 'Ziyaretçi Raporu', false, $gray );
		$pdf->text_right( 555, 50, 10, 'Dönem: ' . $labels[ $period ], false );
		$pdf->text_right( 555, 65, 9.5, substr( $d['range']['from'], 0, 10 ) . ' - ' . substr( $d['range']['to'], 0, 10 ), false, $gray );
		$pdf->text_right( 555, 79, 9, 'Oluşturuldu: ' . wp_date( 'd.m.Y H:i', ZT_Time::now_ts() ), false, $gray );
		$pdf->line( 40, 90, 555, 90, $accent, 1.2 );

		// İstatistik kutuları.
		$stats = array(
			array( 'Tekil Ziyaretçi', $fmt( $t['visitors'] ) ),
			array( 'Sayfa Görüntüleme', $fmt( $t['views'] ) ),
			array( 'Yeni / Dönen', $fmt( $split['new'] ) . ' / ' . $fmt( $split['returning'] ) ),
			array( 'Bot Trafiği', $fmt( $t['bots'] ) ),
		);
		$bw = 121.25;
		$y0 = 104;
		$bh = 52;
		foreach ( $stats as $i => $s ) {
			$x = 40 + $i * ( $bw + 10 );
			$pdf->rect( $x, $y0, $bw, $bh, array( 0.97, 0.98, 0.99 ) );
			$pdf->text( $x + 12, $y0 + 27, 16, $s[1], true );
			$pdf->text( $x + 12, $y0 + 43, 8.5, $s[0], false, $gray );
		}

		// Trend.
		$pdf->rect( 40, 177, 3, 12, $accent );
		$pdf->text( 48, 186, 11, 'Trafik Eğilimi', true, $dark );
		self::pdf_bars( $pdf, 40, 555, 200, 76, $d['series'] );

		// Tablolar.
		$rows_c = array();
		foreach ( array_slice( (array) $d['countries'], 0, 5 ) as $c ) {
			$rows_c[] = array( $c['name'], $fmt( $c['visitors'] ) );
		}
		$rows_p = array();
		foreach ( array_slice( (array) $d['top_pages'], 0, 5 ) as $p ) {
			$ti       = '' !== $p['title'] ? $p['title'] : $p['url'];
			$rows_p[] = array( mb_substr( $ti, 0, 32 ), $fmt( $p['views'] ) );
		}
		$rows_r = array();
		foreach ( array_slice( (array) $d['referrers'], 0, 5 ) as $r ) {
			$rows_r[] = array( $r['k'], $fmt( $r['n'] ) );
		}
		$dev_map = array( 'desktop' => 'Masaüstü', 'mobile' => 'Mobil', 'tablet' => 'Tablet' );
		$rows_d  = array();
		foreach ( (array) $d['devices'] as $dv ) {
			$rows_d[] = array( isset( $dev_map[ $dv['k'] ] ) ? $dev_map[ $dv['k'] ] : $dv['k'], $fmt( $dv['n'] ) );
		}
		foreach ( array_slice( (array) $d['browsers'], 0, 3 ) as $b ) {
			$rows_d[] = array( $b['k'], $fmt( $b['n'] ) );
		}
		$rows_d = array_slice( $rows_d, 0, 5 );

		self::pdf_table( $pdf, 40, 288, 305, 'Ülkeler', $rows_c, $accent, $dark );
		self::pdf_table( $pdf, 307, 555, 305, 'En Çok Görüntülenen Sayfalar', $rows_p, $accent, $dark );
		self::pdf_table( $pdf, 40, 288, 425, 'Geliş Kaynakları', $rows_r, $accent, $dark );
		self::pdf_table( $pdf, 307, 555, 425, 'Cihazlar / Tarayıcılar', $rows_d, $accent, $dark );

		// Alt bilgi.
		$pdf->line( 40, 800, 555, 800, array( 0.93, 0.95, 0.97 ), 0.5 );
		$pdf->text( 40, 812, 8.5, 'Ziyaretçi Takibi eklentisi - ' . home_url(), false, $gray );

		return $pdf->render();
	}

	private static function pdf_table( $pdf, $x, $x_right, $y, $title, $rows, $accent, $dark ) {
		$pdf->rect( $x, $y - 8, 3, 11, $accent );
		$pdf->text( $x + 8, $y, 10.5, $title, true, $dark );
		$y += 17;
		if ( empty( $rows ) ) {
			$pdf->text( $x, $y, 9, 'Veri yok', false, array( 0.6, 0.65, 0.7 ) );
			return;
		}
		foreach ( $rows as $r ) {
			$pdf->text( $x, $y, 9, $r[0] );
			$pdf->text_right( $x_right, $y, 9, $r[1], true );
			$pdf->line( $x, $y + 5, $x_right, $y + 5, array( 0.93, 0.95, 0.97 ), 0.4 );
			$y += 15;
		}
	}

	private static function pdf_bars( $pdf, $x, $x_right, $y, $h, $series ) {
		$series = (array) $series;
		$n      = count( $series );
		if ( 0 === $n ) {
			return;
		}
		$vals = array();
		foreach ( $series as $s ) {
			$vals[] = (int) $s['views'];
		}
		$max  = max( 1, max( $vals ) );
		$w    = $x_right - $x;
		$slot = $w / $n;
		$bw   = min( 24, $slot * 0.6 );
		$step = max( 1, (int) ceil( $n / 10 ) );
		foreach ( $series as $i => $s ) {
			$v  = (int) $s['views'];
			$bh = ( $v / $max ) * $h;
			$bx = $x + $slot * $i + ( $slot - $bw ) / 2;
			$by = $y + ( $h - $bh );
			$pdf->rect( $bx, $by, $bw, max( 0.5, $bh ), array( 0.39, 0.40, 0.95 ) );
			if ( 0 === $i % $step ) {
				$pdf->text( $x + $slot * $i + $slot / 2 - 11, $y + $h + 12, 7.5, $s['label'], false, array( 0.40, 0.45, 0.50 ) );
			}
		}
	}

	private static function build_html( $data ) {
		$t     = $data['totals'];
		$brand = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$panel = admin_url( 'admin.php?page=ziyaretci-takibi' );

		$delta = function ( $cur, $prev ) {
			if ( $prev <= 0 ) {
				return $cur > 0 ? '<span style="color:#10b981">▲ yeni</span>' : '<span style="color:#94a3b8">—</span>';
			}
			$pct = round( ( $cur - $prev ) / $prev * 100 );
			if ( $pct > 0 ) {
				return '<span style="color:#10b981">▲ %' . $pct . '</span>';
			}
			if ( $pct < 0 ) {
				return '<span style="color:#ef4444">▼ %' . abs( $pct ) . '</span>';
			}
			return '<span style="color:#94a3b8">%0</span>';
		};

		$fmt = function ( $n ) {
			return number_format_i18n( (int) $n );
		};

		$stat_cell = function ( $label, $value, $sub ) {
			return '<td style="padding:16px;background:#f8fafc;border-radius:10px;text-align:center;width:33%">' .
				'<div style="font-size:28px;font-weight:800;color:#0f172a;line-height:1">' . $value . '</div>' .
				'<div style="font-size:12px;color:#64748b;margin-top:6px">' . esc_html( $label ) . '</div>' .
				'<div style="font-size:12px;margin-top:4px">' . $sub . '</div></td>';
		};

		// Ülke listesi.
		$countries_html = '';
		foreach ( array_slice( $data['countries'], 0, 5 ) as $c ) {
			$countries_html .= '<tr><td style="padding:6px 0;color:#334155">' . esc_html( $c['name'] ) . '</td>' .
				'<td style="padding:6px 0;text-align:right;font-weight:700;color:#0f172a">' . $fmt( $c['visitors'] ) . ' ziyaretçi</td></tr>';
		}
		if ( '' === $countries_html ) {
			$countries_html = '<tr><td style="padding:6px 0;color:#94a3b8">Veri yok</td></tr>';
		}

		// En çok görüntülenen sayfalar.
		$pages_html = '';
		foreach ( array_slice( $data['top_pages'], 0, 5 ) as $p ) {
			$title = '' !== $p['title'] ? $p['title'] : $p['url'];
			$pages_html .= '<tr><td style="padding:6px 0;color:#334155">' . esc_html( mb_substr( $title, 0, 60 ) ) . '</td>' .
				'<td style="padding:6px 0;text-align:right;font-weight:700;color:#0f172a">' . $fmt( $p['views'] ) . '</td></tr>';
		}
		if ( '' === $pages_html ) {
			$pages_html = '<tr><td style="padding:6px 0;color:#94a3b8">Veri yok</td></tr>';
		}

		ob_start();
		?>
<div style="background:#eef2f7;padding:24px 0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06)">
	<tr><td style="background:linear-gradient(135deg,#22d3ee,#818cf8);padding:28px 28px">
		<div style="color:#ffffff;font-size:13px;opacity:0.9;letter-spacing:0.5px">HAFTALIK ZİYARETÇİ ÖZETİ</div>
		<div style="color:#ffffff;font-size:22px;font-weight:800;margin-top:4px"><?php echo esc_html( $brand ); ?></div>
		<div style="color:#ffffff;font-size:13px;opacity:0.9;margin-top:4px">Son 7 gün</div>
	</td></tr>
	<tr><td style="padding:24px 28px">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="8"><tr>
			<?php
			echo $stat_cell( 'Tekil Ziyaretçi', $fmt( $t['visitors'] ), $delta( $t['visitors'], $t['visitors_prev'] ) ); // phpcs:ignore
			echo $stat_cell( 'Görüntüleme', $fmt( $t['views'] ), $delta( $t['views'], $t['views_prev'] ) ); // phpcs:ignore
			echo $stat_cell( 'Bot Trafiği', $fmt( $t['bots'] ), '<span style="color:#94a3b8">bu hafta</span>' ); // phpcs:ignore
			?>
		</tr></table>

		<div style="margin-top:24px;font-size:14px;font-weight:700;color:#0f172a">🌍 Ülkeler</div>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;font-size:13px;border-top:1px solid #e2e8f0">
			<?php echo $countries_html; // phpcs:ignore ?>
		</table>

		<div style="margin-top:20px;font-size:14px;font-weight:700;color:#0f172a">📄 En Çok Görüntülenen Sayfalar</div>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;font-size:13px;border-top:1px solid #e2e8f0">
			<?php echo $pages_html; // phpcs:ignore ?>
		</table>

		<div style="text-align:center;margin-top:28px">
			<a href="<?php echo esc_url( $panel ); ?>" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:600">Tüm Panele Git →</a>
		</div>
	</td></tr>
	<tr><td style="padding:16px 28px;background:#f8fafc;color:#94a3b8;font-size:11px;text-align:center">
		Bu e-posta Ziyaretçi Takibi eklentisi tarafından gönderildi. Raporu kapatmak için eklenti ayarlarını ziyaret edin.
	</td></tr>
</table>
</td></tr></table>
</div>
		<?php
		return ob_get_clean();
	}
}
