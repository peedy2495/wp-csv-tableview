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
		'delimiter'     => '',
		'class'         => '',
		'max_rows'      => '',
		'max_mb'        => '', // maximum CSV size in megabytes (0 = unlimited)
		'restrict_host' => '1',  // '1' to allow only same-host CSVs
		'cache_minutes' => '',
	), $atts, 'csv_table' );

	if ( empty( $atts['src'] ) ) {
		return '<p><em>CSV Table: missing src attribute.</em></p>';
	}

	$src = esc_url_raw( $atts['src'] );
	$header = intval( $atts['header'] ) === 1;
	// We'll allow admin options to provide defaults when shortcode omits attributes.
	$opts = get_option( 'sct_settings', array() );

	// delimiter: shortcode attr > option > default
	if ( $atts['delimiter'] !== '' ) {
		$delimiter = substr( $atts['delimiter'], 0, 1 ); // single char
		if ( $delimiter === '' ) {
			$delimiter = ',';
		}
	} elseif ( isset( $opts['delimiter'] ) && $opts['delimiter'] !== '' ) {
		$delimiter = substr( $opts['delimiter'], 0, 1 );
	} else {
		$delimiter = ',';
	}
	// sanitize multiple classes correctly
	$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $atts['class'] ) ) ) );
	$table_class = $classes ? ' ' . implode( ' ', $classes ) : '';
	// max_rows: shortcode attr > option > default(500)
	if ( $atts['max_rows'] !== '' ) {
		$max_rows = intval( $atts['max_rows'] ) > 0 ? intval( $atts['max_rows'] ) : 500;
	} elseif ( isset( $opts['max_rows'] ) ) {
		$max_rows = intval( $opts['max_rows'] ) > 0 ? intval( $opts['max_rows'] ) : 500;
	} else {
		$max_rows = 500;
	}

	// max_mb: shortcode attr > option > default(2)
	if ( $atts['max_mb'] !== '' ) {
		$max_mb = intval( $atts['max_mb'] );
	} elseif ( isset( $opts['max_mb'] ) ) {
		$max_mb = intval( $opts['max_mb'] );
	} else {
		$max_mb = 2;
	}

	// cache_minutes: shortcode attr takes precedence; else option; else 5
	if ( $atts['cache_minutes'] !== '' ) {
		$cache_minutes = intval( $atts['cache_minutes'] ) >= 0 ? intval( $atts['cache_minutes'] ) : 5;
	} elseif ( isset( $opts['cache_minutes'] ) ) {
		$cache_minutes = intval( $opts['cache_minutes'] ) >= 0 ? intval( $opts['cache_minutes'] ) : 5;
	} else {
		$cache_minutes = 5;
	}

	// restrict_host: shortcode attr overrides option; default true when not configured
	if ( $atts['restrict_host'] !== '' ) {
		$restrict_host = intval( $atts['restrict_host'] ) === 1;
	} else {
		$restrict_host = isset( $opts['restrict_host'] ) ? ! empty( $opts['restrict_host'] ) : true;
	}

	// header: shortcode attr > option > default(true)
	if ( $atts['header'] !== '' ) {
		$header = intval( $atts['header'] ) === 1;
	} elseif ( isset( $opts['header'] ) ) {
		$header = intval( $opts['header'] ) === 1;
	} else {
		$header = true;
	}

	// Use transient to cache fetches briefly
	$transient_key = 'sct_csv_' . md5( $src . '|' . $delimiter . '|' . $max_rows . '|' . $max_mb );
	$cached = get_transient( $transient_key );

	if ( false === $cached ) {
		// Validate URL scheme and optionally host before fetching
		$scheme = wp_parse_url( $src, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '<p><em>CSV Table: invalid URL scheme.</em></p>';
		}
		if ( $restrict_host ) {
			$req_host = wp_parse_url( $src, PHP_URL_HOST );
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $req_host && $home_host && $req_host !== $home_host ) {
				return '<p><em>CSV Table: external hosts not allowed.</em></p>';
			}
		}

		// Fetch with conservative HTTP options
		$response = wp_remote_get( $src, array( 'timeout' => 15, 'redirection' => 3, 'sslverify' => true ) );
		if ( is_wp_error( $response ) ) {
			return '<p><em>CSV Table: failed to fetch CSV - ' . esc_html( $response->get_error_message() ) . '</em></p>';
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return '<p><em>CSV Table: failed to fetch CSV - HTTP ' . esc_html( $code ) . '</em></p>';
		}
		$body = wp_remote_retrieve_body( $response );
		// Enforce maximum size check (Content-Length header and actual body length)
		if ( $max_mb > 0 ) {
			$cl = wp_remote_retrieve_header( $response, 'content-length' );
			$max_bytes = $max_mb * 1024 * 1024;
			if ( $cl && intval( $cl ) > 0 && intval( $cl ) > $max_bytes ) {
				return '<p><em>CSV Table: CSV too large.</em></p>';
			}
			if ( strlen( $body ) > $max_bytes ) {
				return '<p><em>CSV Table: CSV exceeds allowed size.</em></p>';
			}
		}
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
 * Register admin settings and add settings page for plugin defaults.
 * English-only comments as requested.
 */
function sct_register_settings() {
	register_setting( 'sct_settings_group', 'sct_settings', 'sct_sanitize_settings' );

	add_settings_section(
		'sct_main_section',
		'CSV Table Settings',
		function() { echo '<p>Configure defaults used when shortcode attributes are not provided.</p>'; },
		'sct-settings'
	);

	add_settings_field(
		'sct_cache_minutes',
		'Cache minutes',
		'sct_render_field_cache_minutes',
		'sct-settings',
		'sct_main_section'
	);

	add_settings_field(
		'sct_restrict_host',
		'Restrict host',
		'sct_render_field_restrict_host',
		'sct-settings',
		'sct_main_section'
	);

	add_settings_field(
		'sct_max_rows',
		'Default max rows',
		'sct_render_field_max_rows',
		'sct-settings',
		'sct_main_section'
	);

	add_settings_field(
		'sct_max_mb',
		'Default max MB',
		'sct_render_field_max_mb',
		'sct-settings',
		'sct_main_section'
	);

	add_settings_field(
		'sct_delimiter',
		'Default delimiter',
		'sct_render_field_delimiter',
		'sct-settings',
		'sct_main_section'
	);

	add_settings_field(
		'sct_header',
		'Default has header',
		'sct_render_field_header',
		'sct-settings',
		'sct_main_section'
	);
}
add_action( 'admin_init', 'sct_register_settings' );

function sct_sanitize_settings( $input ) {
	$out = array();
	if ( isset( $input['cache_minutes'] ) ) {
		$out['cache_minutes'] = max( 0, intval( $input['cache_minutes'] ) );
	}
	$out['restrict_host'] = ! empty( $input['restrict_host'] ) ? 1 : 0;
	if ( isset( $input['max_rows'] ) ) {
		$out['max_rows'] = max( 0, intval( $input['max_rows'] ) );
	}
	if ( isset( $input['max_mb'] ) ) {
		$out['max_mb'] = max( 0, intval( $input['max_mb'] ) );
	}
	if ( isset( $input['delimiter'] ) ) {
		$out['delimiter'] = substr( trim( $input['delimiter'] ), 0, 1 );
	}
	$out['header'] = ! empty( $input['header'] ) ? 1 : 0;
	return $out;
}

function sct_render_field_cache_minutes() {
	$opts = get_option( 'sct_settings', array() );
	$val = isset( $opts['cache_minutes'] ) ? intval( $opts['cache_minutes'] ) : 5;
	echo '<input type="number" min="0" name="sct_settings[cache_minutes]" value="' . esc_attr( $val ) . '" />';
	echo '<p class="description">Set default caching minutes for remote CSV fetches (0 = no cache).</p>';
}

function sct_render_field_restrict_host() {
	$opts = get_option( 'sct_settings', array() );
	// Default to checked when option not yet configured
	$checked = isset( $opts['restrict_host'] ) ? ( ! empty( $opts['restrict_host'] ) ? 'checked' : '' ) : 'checked';
	echo '<label><input type="checkbox" name="sct_settings[restrict_host]" value="1" ' . $checked . ' /> Only allow same-host CSVs by default</label>';
}

function sct_render_field_max_rows() {
	$opts = get_option( 'sct_settings', array() );
	$val = isset( $opts['max_rows'] ) ? intval( $opts['max_rows'] ) : 500;
	echo '<input type="number" min="0" name="sct_settings[max_rows]" value="' . esc_attr( $val ) . '" />';
	echo '<p class="description">Default maximum number of rows to display when shortcode omits <code>max_rows</code>.</p>';
}

function sct_render_field_max_mb() {
	$opts = get_option( 'sct_settings', array() );
	$val = isset( $opts['max_mb'] ) ? intval( $opts['max_mb'] ) : 2;
	echo '<input type="number" min="0" name="sct_settings[max_mb]" value="' . esc_attr( $val ) . '" />';
	echo '<p class="description">Default maximum CSV size in megabytes when shortcode omits <code>max_mb</code>. Set 0 for unlimited.</p>';
}

function sct_render_field_delimiter() {
	$opts = get_option( 'sct_settings', array() );
	$val = isset( $opts['delimiter'] ) ? esc_attr( $opts['delimiter'] ) : ',';
	echo '<input type="text" maxlength="1" name="sct_settings[delimiter]" value="' . $val . '" />';
	echo '<p class="description">Default single-character delimiter when shortcode omits <code>delimiter</code>.</p>';
}

function sct_render_field_header() {
	$opts = get_option( 'sct_settings', array() );
	// Default to checked when option not yet configured
	$checked = isset( $opts['header'] ) ? ( ! empty( $opts['header'] ) ? 'checked' : '' ) : 'checked';
	echo '<label><input type="checkbox" name="sct_settings[header]" value="1" ' . $checked . ' /> Treat first row as header by default</label>';
	echo '<p class="description">This setting is a default only; shortcode attribute <code>header</code> overrides this.</p>';
}

function sct_add_admin_menu() {
	add_options_page( 'CSV Table', 'CSV Table', 'manage_options', 'sct-settings', 'sct_settings_page' );
}
add_action( 'admin_menu', 'sct_add_admin_menu' );

function sct_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>CSV Table Settings</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'sct_settings_group' ); ?>
			<?php do_settings_sections( 'sct-settings' ); ?>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

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

/**
 * Add a Settings link on the Plugins page for convenience.
 */
function sct_plugin_action_links( $links ) {
	$settings_url = admin_url( 'options-general.php?page=sct-settings' );
	$settings_link = '<a href="' . esc_url( $settings_url ) . '">Settings</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sct_plugin_action_links' );

/**
 * Debug helper: reset plugin options and transients on activation.
 * Use only for debugging; this runs once when plugin is activated.
 */
function sct_debug_reset_on_activation() {
	// delete stored settings
	delete_option( 'sct_settings' );

	// remove transients created by this plugin (pattern: sct_csv_...)
	global $wpdb;
	$like1 = $wpdb->esc_like( '_transient_sct_csv_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like1 ) );
	$like2 = $wpdb->esc_like( '_transient_timeout_sct_csv_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2 ) );

	// Also clear object cache to remove any cached transient values in some setups
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}
register_activation_hook( __FILE__, 'sct_debug_reset_on_activation' );