<?php
/**
 * Kendi kendine yeten minimal PDF yazıcı. Harici kütüphane (FPDF/dompdf) veya
 * PHP eklentisi gerektirmez. Çekirdek Helvetica fontlarıyla metin, dolu dikdörtgen
 * ve çizgi çizer; tek sayfa A4 çıktı üretir.
 *
 * Kodlama: WinAnsi (Windows-1252). ç/ö/ü korunur; ş/ğ/ı ASCII'ye çevrilir
 * (çekirdek fontlar bu Türkçe karakterleri içermez, font gömme yapılmaz).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZT_PDF {

	private $content = '';
	private $w;
	private $h;

	public function __construct() {
		$this->w = 595.28; // A4 genişlik (pt)
		$this->h = 841.89; // A4 yükseklik (pt)
	}

	public function width() {
		return $this->w;
	}

	public function height() {
		return $this->h;
	}

	/**
	 * Metni WinAnsi'ye hazırlar ve PDF dizesi için kaçışlar.
	 */
	private function prep( $s ) {
		$s = (string) $s;
		$s = str_replace(
			array( 'ş', 'Ş', 'ğ', 'Ğ', 'ı', 'İ' ),
			array( 's', 'S', 'g', 'G', 'i', 'I' ),
			$s
		);
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$s = mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		} else {
			$s = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s );
		}
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', '', '' ), (string) $s );
	}

	/**
	 * Yaklaşık Helvetica metin genişliği (sağa hizalama için).
	 */
	public function text_width( $str, $size ) {
		return strlen( (string) $str ) * $size * 0.5;
	}

	public function text( $x, $y, $size, $str, $bold = false, $color = array( 0.06, 0.09, 0.16 ) ) {
		$f  = $bold ? 'F2' : 'F1';
		$py = $this->h - $y;
		$this->content .= sprintf(
			"BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
			$f, $size, $color[0], $color[1], $color[2], $x, $py, $this->prep( $str )
		);
		return $this;
	}

	public function text_right( $x_right, $y, $size, $str, $bold = false, $color = array( 0.06, 0.09, 0.16 ) ) {
		$x = $x_right - $this->text_width( $str, $size );
		return $this->text( $x, $y, $size, $str, $bold, $color );
	}

	public function rect( $x, $y, $w, $h, $color ) {
		$py = $this->h - $y - $h;
		$this->content .= sprintf(
			"%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
			$color[0], $color[1], $color[2], $x, $py, $w, $h
		);
		return $this;
	}

	public function line( $x1, $y1, $x2, $y2, $color, $width = 0.5 ) {
		$this->content .= sprintf(
			"%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
			$color[0], $color[1], $color[2], $width, $x1, $this->h - $y1, $x2, $this->h - $y2
		);
		return $this;
	}

	/**
	 * Geçerli PDF bayt dizesini üretir.
	 */
	public function render() {
		$objects    = array();
		$objects[1] = '<</Type/Catalog/Pages 2 0 R>>';
		$objects[2] = '<</Type/Pages/Kids[3 0 R]/Count 1>>';
		$objects[3] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 ' . round( $this->w, 2 ) . ' ' . round( $this->h, 2 ) . ']'
			. '/Resources<</Font<</F1 5 0 R/F2 6 0 R>>>>/Contents 4 0 R>>';
		$objects[4] = '<</Length ' . strlen( $this->content ) . ">>\nstream\n" . $this->content . "endstream";
		$objects[5] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>';
		$objects[6] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica-Bold/Encoding/WinAnsiEncoding>>';

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$offsets[ $i ] = strlen( $pdf );
			$pdf          .= $i . " 0 obj\n" . $objects[ $i ] . "\nendobj\n";
		}

		$xref_pos = strlen( $pdf );
		$pdf     .= "xref\n0 7\n0000000000 65535 f \n";
		for ( $i = 1; $i <= 6; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<</Size 7/Root 1 0 R>>\nstartxref\n" . $xref_pos . "\n%%EOF";

		return $pdf;
	}
}
