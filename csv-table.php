<?php
/**
 * Plugin Name: Simple CSV Table
 * Description: Shortcode [csv_table src="CSV_URL" header="1" delimiter="," class=""] - renders a sanitized HTML table from a CSV.
 * Version: 0.2
 * Author: Peter Mark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a CSV as an HTML table.
 *
 * Shortcode attributes:
 * - src (required): URL to the CSV file (http(s) or site-relative).
 * - header: 1 or 0 (default 1) - treat first row as header.
 * - delimiter: single character CSV delimiter (default ",").
 * - class: extra CSS classes for the table element.
 * - max_rows: maximum number of rows to render (default 500).
 * - cache_minutes: integer minutes to cache fetch results (default 5).
 */
function sct_csv_table_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'src'           => '',
		'header'        => '1',
		'delimiter'     => ',',
		'class'         => '',
		'max_rows'      => 500,
		'cache_minutes' => 5,
	), $atts, 'csv_table' );

	if ( empty( $atts['src'] ) ) {
		return '<p><em>CSV Table: missing src attribute.</em></p>';
	}

	$src = esc_url_raw( $atts['src'] );
	$header = intval( $atts['header'] ) === 1;
	$delimiter = substr( $atts['delimiter'], 0, 1 ); // single char
	if ( $delimiter === '' ) {
		$delimiter = ',';
	}
	$table_class = sanitize_html_class( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
	$max_rows = intval( $atts['max_rows'] ) > 0 ? intval( $atts['max_rows'] ) : 500;
	$cache_minutes = intval( $atts['cache_minutes'] ) >= 0 ? intval( $atts['cache_minutes'] ) : 5;

	// Use transient to cache fetches briefly
	$transient_key = 'sct_csv_' . md5( $src . '|' . $delimiter . '|' . $max_rows );
	$cached = get_transient( $transient_key );

	if ( false === $cached ) {
		$response = wp_remote_get( $src, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return '<p><em>CSV Table: failed to fetch CSV - ' . esc_html( $response->get_error_message() ) . '</em></p>';
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return '<p><em>CSV Table: failed to fetch CSV - HTTP ' . esc_html( $code ) . '</em></p>';
		}
		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return '<p><em>CSV Table: empty CSV.</em></p>';
		}

		// normalize line endings and remove UTF-8 BOM if present
		$body = str_replace( array( "\r\n", "\r" ), "\n", $body );
		$body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

		// Parse CSV robustly using fgetcsv on a memory stream (handles quoted newlines)
		$rows = array();
		$fp = fopen( 'php://temp', 'r+' );
		if ( ! $fp ) {
			return '<p><em>CSV Table: unable to parse CSV.</em></p>';
		}
		fwrite( $fp, $body );
		rewind( $fp );

		$line_count = 0;
		while ( ( $row = fgetcsv( $fp, 0, $delimiter ) ) !== false ) {
			// skip completely empty rows
			$all_empty = true;
			foreach ( $row as $cell ) {
				if ( trim( $cell ) !== '' ) {
					$all_empty = false;
					break;
				}
			}
			if ( $all_empty ) {
				continue;
			}
			$rows[] = $row;
			$line_count++;
			if ( $line_count >= $max_rows ) {
				break;
			}
		}
		fclose( $fp );

		$cached = $rows;
		if ( $cache_minutes > 0 ) {
			set_transient( $transient_key, $cached, $cache_minutes * MINUTE_IN_SECONDS );
		}
	}

	$rows = $cached;
	if ( empty( $rows ) ) {
		return '<p><em>CSV Table: no rows to display.</em></p>';
	}

	// Build table HTML
	$html = '<div class="sct-csv-table-wrap" style="overflow:auto;">';
	$html .= '<table class="sct-csv-table' . esc_attr( $table_class ) . '" cellspacing="0" cellpadding="4" border="0">';

	$start_index = 0;
	if ( $header ) {
		$head_row = $rows[0];
		$html .= '<thead><tr>';
		foreach ( $head_row as $cell ) {
			$html .= '<th>' . esc_html( $cell ) . '</th>';
		}
		$html .= '</tr></thead>';
		$start_index = 1;
	}

	$html .= '<tbody>';
	for ( $i = $start_index; $i < count( $rows ); $i++ ) {
		$row = $rows[ $i ];
		$html .= '<tr>';
		foreach ( $row as $cell ) {
			$html .= '<td>' . esc_html( $cell ) . '</td>';
		}
		$html .= '</tr>';
	}
	$html .= '</tbody>';

	$html .= '</table>';
	if ( count( $rows ) >= $max_rows ) {
		$html .= '<p style="font-size:0.9em;color:#666;margin-top:6px;">Displayed first ' . intval( $max_rows ) . ' rows.</p>';
	}
	$html .= '</div>';

	return $html;
}
add_shortcode( 'csv_table', 'sct_csv_table_shortcode' );

/**
 * Optional: Basic stylesheet when plugin is active (small, non-intrusive).
 */
function sct_enqueue_styles() {
	$css = "
		.sct-csv-table { border-collapse:collapse; width:100%; }
		.sct-csv-table th { background:#f7f7f7; text-align:left; border-bottom:1px solid #ddd; padding:6px 8px; }
		.sct-csv-table td { border-bottom:1px solid #eee; padding:6px 8px; vertical-align:top; }
		.sct-csv-table-wrap { margin:8px 0; }
	";
	wp_register_style( 'sct-inline', false );
	wp_enqueue_style( 'sct-inline' );
	wp_add_inline_style( 'sct-inline', $css );
}
add_action( 'wp_enqueue_scripts', 'sct_enqueue_styles' );