<?php
/**
 * Generatore PDF minimale, senza dipendenze esterne.
 *
 * Produce un PDF/1.4 a pagina singola (A4) con i font core Helvetica /
 * Helvetica-Bold (encoding WinAnsi, accenti italiani supportati).
 * La struttura a byte e' stata validata con pypdf.
 *
 * @package WAYT_Recesso_Online
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PDF writer.
 */
final class WAYT_Recesso_PDF {

	/**
	 * Converte UTF-8 in CP1252 (WinAnsi) per i font core.
	 *
	 * @param string $s Testo UTF-8.
	 * @return string
	 */
	private static function cp1252( string $s ): string {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
			if ( false !== $out && '' !== $out ) {
				return $out;
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s );
			if ( false !== $out ) {
				return $out;
			}
		}
		// Ultima spiaggia: rimuovi i non-ASCII.
		return preg_replace( '/[^\x00-\x7F]/', '?', $s );
	}

	/**
	 * Escape dei caratteri speciali nelle stringhe PDF.
	 *
	 * @param string $b Testo gia' in CP1252.
	 * @return string
	 */
	private static function esc( string $b ): string {
		$b = str_replace( '\\', '\\\\', $b );
		$b = str_replace( '(', '\\(', $b );
		$b = str_replace( ')', '\\)', $b );
		// Rimuovi caratteri di controllo che romperebbero lo stream.
		$b = str_replace( array( "\r", "\n", "\t" ), array( '', '', ' ' ), $b );
		return $b;
	}

	/**
	 * Word-wrap su un numero massimo di caratteri (font monospaziato stimato).
	 *
	 * @param string $text      Testo.
	 * @param int    $max_chars Caratteri per riga.
	 * @return array<int,string>
	 */
	private static function wrap( string $text, int $max_chars ): array {
		$out  = array();
		$text = str_replace( "\r\n", "\n", $text );
		foreach ( explode( "\n", $text ) as $para ) {
			if ( '' === $para ) {
				$out[] = '';
				continue;
			}
			$words = explode( ' ', $para );
			$line  = '';
			foreach ( $words as $w ) {
				$t = trim( $line . ' ' . $w );
				if ( strlen( $t ) <= $max_chars ) {
					$line = $t;
				} else {
					if ( '' !== $line ) {
						$out[] = $line;
					}
					// Spezza parole troppo lunghe.
					while ( strlen( $w ) > $max_chars ) {
						$out[] = substr( $w, 0, $max_chars );
						$w     = substr( $w, $max_chars );
					}
					$line = $w;
				}
			}
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Genera il PDF.
	 *
	 * @param array $args {
	 *     @type string                     $title      Titolo.
	 *     @type array<int,array{0:string,1:string}> $rows  Coppie etichetta/valore.
	 *     @type string                     $decl_title Titolo della dichiarazione.
	 *     @type string                     $decl_text  Corpo della dichiarazione.
	 *     @type string                     $footer     Riga a pie' di pagina.
	 * }
	 * @return string Byte del PDF.
	 */
	public static function generate( array $args ): string {
		$title      = (string) ( $args['title'] ?? '' );
		$rows       = isset( $args['rows'] ) && is_array( $args['rows'] ) ? $args['rows'] : array();
		$decl_title = (string) ( $args['decl_title'] ?? '' );
		$decl_text  = (string) ( $args['decl_text'] ?? '' );
		$footer     = (string) ( $args['footer'] ?? '' );

		$page_w = 595;
		$page_h = 842;
		$margin = 56;
		$y      = $page_h - $margin;
		$parts  = array();

		$parts[] = 'BT';
		// Titolo (Helvetica-Bold 16).
		$parts[] = '/F2 16 Tf';
		$parts[] = '0 0 0 rg';
		$parts[] = sprintf( '1 0 0 1 %d %d Tm', $margin, $y );
		$parts[] = '(' . self::esc( self::cp1252( $title ) ) . ') Tj';
		$y      -= 28;

		// Righe etichetta/valore.
		foreach ( $rows as $row ) {
			$label = isset( $row[0] ) ? (string) $row[0] : '';
			$value = isset( $row[1] ) ? (string) $row[1] : '';

			$parts[] = '/F2 11 Tf';
			$parts[] = sprintf( '1 0 0 1 %d %d Tm', $margin, $y );
			$parts[] = '(' . self::esc( self::cp1252( $label ) ) . ') Tj';

			$parts[]    = '/F1 11 Tf';
			$value_wrap = self::wrap( $value, 60 );
			if ( empty( $value_wrap ) ) {
				$value_wrap = array( '' );
			}
			foreach ( $value_wrap as $i => $ln ) {
				$yy      = $y - ( $i * 14 );
				$parts[] = sprintf( '1 0 0 1 %d %d Tm', $margin + 140, $yy );
				$parts[] = '(' . self::esc( self::cp1252( $ln ) ) . ') Tj';
			}
			$y -= ( 14 * max( 1, count( $value_wrap ) ) ) + 6;
		}

		$y -= 10;

		// Titolo dichiarazione.
		if ( '' !== $decl_title ) {
			$parts[] = '/F2 12 Tf';
			$parts[] = sprintf( '1 0 0 1 %d %d Tm', $margin, $y );
			$parts[] = '(' . self::esc( self::cp1252( $decl_title ) ) . ') Tj';
			$y      -= 18;
		}

		// Corpo dichiarazione (Helvetica 10).
		$parts[] = '/F1 10 Tf';
		foreach ( self::wrap( $decl_text, 92 ) as $ln ) {
			if ( $y < $margin + 40 ) {
				$parts[] = '(...) Tj';
				break;
			}
			$parts[] = sprintf( '1 0 0 1 %d %d Tm', $margin, $y );
			$parts[] = '(' . self::esc( self::cp1252( $ln ) ) . ') Tj';
			$y      -= 13;
		}

		// Footer.
		$parts[] = '/F1 8 Tf';
		$parts[] = '0.4 0.4 0.4 rg';
		$parts[] = sprintf( '1 0 0 1 %d %d Tm', $margin, $margin - 20 );
		$parts[] = '(' . self::esc( self::cp1252( $footer ) ) . ') Tj';
		$parts[] = 'ET';

		$stream = implode( "\n", $parts );

		$objs   = array();
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$objs[] = sprintf(
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
			$page_w,
			$page_h
		);
		$objs[] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$out     = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
		$offsets = array();
		$i       = 1;
		foreach ( $objs as $o ) {
			$offsets[] = strlen( $out );
			$out      .= $i . " 0 obj\n" . $o . "\nendobj\n";
			$i++;
		}
		$xref_pos = strlen( $out );
		$n        = count( $objs ) + 1;

		$out .= "xref\n";
		$out .= '0 ' . $n . "\n";
		$out .= "0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$out .= sprintf( "%010d 00000 n \n", $off );
		}
		$out .= "trailer\n";
		$out .= sprintf( "<< /Size %d /Root 1 0 R >>\n", $n );
		$out .= "startxref\n" . $xref_pos . "\n%%EOF";

		return $out;
	}
}
