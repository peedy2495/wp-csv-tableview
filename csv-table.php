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
		// optional sort defaults (accepts 0-based or 1-based in shortcode; '0' forces 0-based)
		'sort_col'      => '',
		'sort_order'    => '',
		'cols'          => '',
		'popup_cols'    => '',
	), $atts, 'csv_table' );

	if ( empty( $atts['src'] ) ) {
		return '<p><em>CSV Table: missing src attribute.</em></p>';
	}

	$src = esc_url_raw( $atts['src'] );
	$header = intval( $atts['header'] ) === 1;
	// We'll allow admin options to provide defaults when shortcode omits attributes.
	$opts = get_option( 'sct_settings', array() );

	// Determine default sort: shortcode attribute has priority; settings provide optional defaults
	$default_sort_col = null;
	$default_sort_order = 'asc';
	$shortcode_default = false;
	if ( $atts['sort_col'] !== '' ) {
		// Allow shortcode to specify 1-based column numbers like `cols`/`popup_cols`.
		// If the raw value contains a literal '0', treat as 0-based; otherwise treat as 1-based.
		$raw = trim( $atts['sort_col'] );
		$parts = preg_split('/\s*,\s*/', $raw );
		$has_zero = in_array( '0', $parts, true );
		// find first numeric part
		$found = null;
		foreach ( $parts as $p ) {
			if ( $p === '' ) {
				continue;
			}
			if ( preg_match('/^-?\d+$/', $p ) ) {
				$found = $p;
				break;
			}
		}
		if ( $found !== null ) {
			$sc = intval( $found );
			if ( ! $has_zero ) {
				$sc = max( 0, $sc - 1 );
			}
			if ( $sc >= 0 ) {
				$default_sort_col = $sc;
				$shortcode_default = true;
			}
		}
	}
	if ( ! $shortcode_default && isset( $opts['default_sort_col'] ) && $opts['default_sort_col'] !== '' ) {
		$sc = intval( $opts['default_sort_col'] );
		if ( $sc >= 0 ) {
			$default_sort_col = $sc;
		}
	}
	if ( $atts['sort_order'] !== '' && in_array( strtolower( $atts['sort_order'] ), array( 'asc', 'desc' ), true ) ) {
		$default_sort_order = strtolower( $atts['sort_order'] );
	} elseif ( isset( $opts['default_sort_order'] ) && in_array( strtolower( $opts['default_sort_order'] ), array( 'asc', 'desc' ), true ) ) {
		$default_sort_order = strtolower( $opts['default_sort_order'] );
	}

	// delimiter: shortcode attr > option > default
	if ( $atts['delimiter'] !== '' ) {
		$delimiter = substr( $atts['delimiter'], 0, 1 );
	} elseif ( isset( $opts['delimiter'] ) && $opts['delimiter'] !== '' ) {
		$delimiter = substr( $opts['delimiter'], 0, 1 );
	} else {
		$delimiter = ',';
	}
	// validate delimiter is a single character (defense-in-depth)
	if ( strlen( $delimiter ) !== 1 ) {
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

	// restrict_host: always enforced; shortcode attr is ignored (security measure)
	$restrict_host = isset( $opts['restrict_host'] ) ? ! empty( $opts['restrict_host'] ) : true;

	// header: shortcode attr > option > default(true)
	if ( $atts['header'] !== '' ) {
		$header = intval( $atts['header'] ) === 1;
	} elseif ( isset( $opts['header'] ) ) {
		$header = intval( $opts['header'] ) === 1;
	} else {
		$header = true;
	}

	// Use transient to cache fetches briefly
	$transient_key = 'sct_csv_' . md5( $src . '|' . $delimiter . '|' . $max_rows . '|' . $max_mb . '|' . AUTH_SALT );
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

	// Parse optional `cols` attribute to allow selecting and ordering displayed columns.
	// `cols` is a comma-separated list of integers. If the list contains a literal '0',
	// values are treated as 0-based indices; otherwise values are treated as 1-based
	// (so '1' refers to the first column). Duplicate or out-of-range indices are ignored.
	$display_cols = array();
	if ( isset( $atts['cols'] ) && trim( $atts['cols'] ) !== '' ) {
		$parts = preg_split('/\s*,\s*/', trim( $atts['cols'] ) );
		$has_zero = in_array( '0', $parts, true );
		foreach ( $parts as $p ) {
			if ( $p === '' ) {
				continue;
			}
			// allow numeric strings and negative handling via intval
			if ( preg_match('/^-?\d+$/', $p ) ) {
				$ival = intval( $p );
				if ( ! $has_zero ) {
					// treat as 1-based
					$ival = max( 0, $ival - 1 );
				}
				if ( $ival >= 0 && ! in_array( $ival, $display_cols, true ) ) {
					$display_cols[] = $ival;
				}
			}
		}
	}
	// If no explicit cols provided, default to all columns based on widest row
	if ( empty( $display_cols ) ) {
		$max_cols = 0;
		foreach ( $rows as $r ) {
			$cnt = is_array( $r ) ? count( $r ) : 0;
			if ( $cnt > $max_cols ) {
				$max_cols = $cnt;
			}
		}
		if ( $max_cols > 0 ) {
			$display_cols = range( 0, $max_cols - 1 );
		} else {
			$display_cols = array();
		}
	}

	// Parse optional `popup_cols` attribute to control which columns appear in the hover tooltip
	// `popup_cols` is a comma-separated list of integers. If the list contains a literal '0',
	// values are treated as 0-based indices; otherwise values are treated as 1-based.
	$popup_cols = array();
	if ( isset( $atts['popup_cols'] ) && trim( $atts['popup_cols'] ) !== '' ) {
		$parts = preg_split('/\s*,\s*/', trim( $atts['popup_cols'] ) );
		$has_zero = in_array( '0', $parts, true );
		foreach ( $parts as $p ) {
			if ( $p === '' ) {
				continue;
			}
			if ( preg_match('/^-?\d+$/', $p ) ) {
				$ival = intval( $p );
				if ( ! $has_zero ) {
					$ival = max( 0, $ival - 1 );
				}
				if ( $ival >= 0 && ! in_array( $ival, $popup_cols, true ) ) {
					$popup_cols[] = $ival;
				}
			}
		}
	}

	// Parse sorting parameters: consider plugin settings and shortcode defaults
	$sort_col = null;
	$sort_order = 'asc';
	$enable_sorting = isset( $opts['enable_sorting'] ) && $opts['enable_sorting'];
	$effective_enable_sorting = $enable_sorting;
	// Querystring overrides defaults
	if ( isset( $_GET['col'] ) ) {
		$sc = intval( $_GET['col'] );
		if ( $sc >= 0 ) {
			$sort_col = $sc;
		}
	}
	if ( isset( $_GET['order'] ) ) {
		$sanitized_order = strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) );
		if ( in_array( $sanitized_order, array( 'asc', 'desc' ), true ) ) {
			$sort_order = $sanitized_order;
		}
	}
	// If no query params present, apply defaults:
	// - shortcode `sort_col` always acts as a default for this shortcode instance
	// - settings `default_sort_col` apply only when `enable_sorting` is on
	if ( $sort_col === null ) {
		if ( $shortcode_default && $default_sort_col !== null ) {
			$sort_col = $default_sort_col;
			$sort_order = $default_sort_order;
		} elseif ( $enable_sorting && $default_sort_col !== null ) {
			$sort_col = $default_sort_col;
			$sort_order = $default_sort_order;
		}
	}

	// Build table HTML
	$html = '<div class="sct-csv-table-wrap" style="overflow:auto;">';
	$html .= '<table class="sct-csv-table' . esc_attr( $table_class ) . '" cellspacing="0" cellpadding="4" border="0">';

	$start_index = 0;
	if ( $header ) {
		$head_row = $rows[0];
		$html .= '<thead><tr>';
		foreach ( $display_cols as $col ) {
			$indicator = '';
			$cell = isset( $head_row[ $col ] ) ? $head_row[ $col ] : '';
			$th_content = esc_html( $cell );
			if ( $effective_enable_sorting ) {
				if ( $sort_col === $col ) {
					$next_order = $sort_order === 'asc' ? 'desc' : 'asc';
					$indicator = $sort_order === 'asc' ? ' ▲' : ' ▼';
				} else {
					$next_order = 'asc';
				}
				$link = esc_url( add_query_arg( array( 'col' => $col, 'order' => $next_order ) ) );
				$th_content = '<a href="' . $link . '">' . $th_content . '</a>';
			}
			$html .= '<th>' . $th_content . esc_html( $indicator ) . '</th>';
		}

		$html .= '</tr></thead>';
		$start_index = 1;
	}

	// Extract body rows for possible sorting
	$body_rows = array_slice( $rows, $start_index );
	// If a valid sort column was provided, sort the body rows
	if ( $sort_col !== null ) {
		usort( $body_rows, function( $a, $b ) use ( $sort_col, $sort_order ) {
			$va = isset( $a[ $sort_col ] ) ? $a[ $sort_col ] : '';
			$vb = isset( $b[ $sort_col ] ) ? $b[ $sort_col ] : '';
			$va_trim = trim( $va );
			$vb_trim = trim( $vb );
			// numeric comparison when both values are numeric
			if ( is_numeric( $va_trim ) && is_numeric( $vb_trim ) ) {
				$cmp = $va_trim - $vb_trim;
			} else {
				$cmp = strcmp( $va_trim, $vb_trim );
			}
			if ( $cmp === 0 ) {
				return 0;
			}
			if ( $sort_order === 'asc' ) {
				return ( $cmp < 0 ) ? -1 : 1;
			}
			return ( $cmp < 0 ) ? 1 : -1;
		} );
	}

	$html .= '<tbody>';
	foreach ( $body_rows as $row ) {
		// prepare popup data if popup_cols is defined; otherwise no tooltip data is attached
		$cells_json = '';
		$headers_json = '';
		if ( ! empty( $popup_cols ) ) {
			$popup_cells = array();
			foreach ( $popup_cols as $pcol ) {
				$popup_cells[] = isset( $row[ $pcol ] ) ? $row[ $pcol ] : '';
			}
			// build headers array matching the popup_cols order so labels align with values
			$popup_headers = array();
			if ( isset( $head_row ) ) {
				foreach ( $popup_cols as $pcol ) {
					$popup_headers[] = isset( $head_row[ $pcol ] ) ? $head_row[ $pcol ] : '';
				}
			}
			$cells_json = wp_json_encode( $popup_cells );
			$headers_json = wp_json_encode( $popup_headers );
		}
		$data_attrs = '';
		if ( $cells_json !== '' ) {
			$data_attrs = ' data-cells="' . esc_attr( $cells_json ) . '" data-headers="' . esc_attr( $headers_json ) . '"';
		}
		$html .= '<tr' . $data_attrs . '>';
		foreach ( $display_cols as $col ) {
			$cell = isset( $row[ $col ] ) ? $row[ $col ] : '';
			$html .= '<td>' . esc_html( $cell ) . '</td>';
		}
		$html .= '</tr>';
	}
	$html .= '</tbody>';

	// Tooltip container + inline script (hover near cursor showing "Spaltenname: Wert")
	$html .= '</table>';
	$html .= '<div id="sct-tooltip" style="display:none;position:absolute;z-index:10000;max-width:320px;background:#fff;border:1px solid #ccc;padding:8px;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;line-height:1.4;"></div>';
	if ( count( $rows ) >= $max_rows ) {
		$html .= '<p style="font-size:0.9em;color:#666;margin-top:6px;">Displayed first ' . intval( $max_rows ) . ' rows.</p>';
	}
	$html .= '</div>';

	$html .= <<<'JS'
<script>(function(){function escapeHtml(s){var div=document.createElement('div');div.textContent=String(s);return div.innerHTML.replace(/&/g,"&amp;").replace(/"/g,"&quot;");}
var tip=document.getElementById('sct-tooltip');if(!tip){tip=document.createElement('div');tip.id='sct-tooltip';document.body.appendChild(tip);}function showTip(html){tip.innerHTML=html;tip.style.display='block';}
function hideTip(){tip.style.display='none';}
document.addEventListener('mouseover', function(e){var node=e.target;while(node&&node.nodeName&&node.nodeName.toLowerCase()!=='tr'){node=node.parentNode;}if(!node) return;var cellsAttr=node.getAttribute&&node.getAttribute('data-cells');if(!cellsAttr) return;try{var cells=JSON.parse(cellsAttr);}catch(err){cells=[];}var headersAttr=node.getAttribute('data-headers')||'[]';try{var headers=JSON.parse(headersAttr);}catch(err){headers=[];}var parts=[];for(var i=0;i<cells.length;i++){var label=(headers[i]!==undefined&&headers[i]!==null&&String(headers[i]).trim()!=='')?String(headers[i]):('Spalte '+(i+1));parts.push('<div style="padding:4px 0;border-bottom:1px solid #eee;"><strong>'+escapeHtml(label)+':</strong> '+escapeHtml(String(cells[i]))+'</div>');}showTip(parts.join(''));}, false);
document.addEventListener('mousemove', function(e){if(tip.style.display==='none') return;var x=e.clientX+12;var y=e.clientY+12;var docW=document.documentElement.clientWidth;var docH=document.documentElement.clientHeight;var rect=tip.getBoundingClientRect();if(x+rect.width>docW) x=docW-rect.width-12; if(y+rect.height>docH) y=docH-rect.height-12;tip.style.left=(x+window.pageXOffset)+'px';tip.style.top=(y+window.pageYOffset)+'px';}, false);
document.addEventListener('mouseout', function(e){var node=e.target;while(node&&node.nodeName&&node.nodeName.toLowerCase()!=='tr'){node=node.parentNode;}if(!node){hideTip();return;}var related=e.relatedTarget; if(related){var p=related; while(p&&p.nodeName&&p.nodeName.toLowerCase()!=='tr'){p=p.parentNode;} if(p===node) return;} if(node.getAttribute&&node.getAttribute('data-cells')){hideTip();} }, false);
})();</script>
JS;

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
		'sct_enable_sorting',
		'Enable default sorting',
		'sct_render_field_enable_sorting',
		'sct-settings',
		'sct_main_section'
	);

	add_settings_field(
		'sct_default_sort_col',
		'Default sort column',
		'sct_render_field_default_sort_col',
		'sct-settings',
		'sct_main_section'
	);

	add_settings_field(
		'sct_default_sort_order',
		'Default sort order',
		'sct_render_field_default_sort_order',
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

	// Sorting settings
	$out['enable_sorting'] = ! empty( $input['enable_sorting'] ) ? 1 : 0;
	if ( isset( $input['default_sort_col'] ) && $input['default_sort_col'] !== '' ) {
		$out['default_sort_col'] = max( 0, intval( $input['default_sort_col'] ) );
	} else {
		$out['default_sort_col'] = '';
	}
	if ( isset( $input['default_sort_order'] ) && in_array( strtolower( $input['default_sort_order'] ), array( 'asc', 'desc' ), true ) ) {
		$out['default_sort_order'] = strtolower( $input['default_sort_order'] );
	} else {
		$out['default_sort_order'] = 'asc';
	}
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

function sct_render_field_enable_sorting() {
	$opts = get_option( 'sct_settings', array() );
	$checked = isset( $opts['enable_sorting'] ) ? ( ! empty( $opts['enable_sorting'] ) ? 'checked' : '' ) : '';
	$checked_attr = $checked ? 'checked' : '';
	echo '<label><input id="sct_enable_sorting" type="checkbox" name="sct_settings[enable_sorting]" value="1" ' . $checked_attr . ' /> Enable default sorting by column</label>';
	echo '<p class="description">When enabled, the plugin will apply default sorting from the settings or shortcode attributes if no query parameters are present.</p>';
	// Add small inline script to toggle dependent fields in the settings UI
	echo "<script>(function(){var cb=document.getElementById('sct_enable_sorting');if(!cb) return;function t(){var els=document.querySelectorAll('.sct-sort-dependent');els.forEach(function(e){e.disabled=!cb.checked;e.style.opacity=cb.checked?'':'0.6';});}cb.addEventListener('change',t);document.addEventListener('DOMContentLoaded',t);t();})();</script>";
}

function sct_render_field_default_sort_col() {
	$opts = get_option( 'sct_settings', array() );
	$val = isset( $opts['default_sort_col'] ) ? esc_attr( $opts['default_sort_col'] ) : '';
	$disabled = empty( $opts['enable_sorting'] ) ? 'disabled' : '';
	echo '<div style="margin-left:24px">';
	echo '<input class="sct-sort-dependent" type="number" min="0" name="sct_settings[default_sort_col]" value="' . $val . '" ' . $disabled . ' />';
	echo '<p class="description">Zero-based column index to sort by by default (leave empty for none).</p>';
	echo '</div>';
}

function sct_render_field_default_sort_order() {
	$opts = get_option( 'sct_settings', array() );
	$val = isset( $opts['default_sort_order'] ) ? $opts['default_sort_order'] : 'asc';
	$disabled = empty( $opts['enable_sorting'] ) ? 'disabled' : '';
	echo '<div style="margin-left:24px">';
	echo '<select class="sct-sort-dependent" name="sct_settings[default_sort_order]" ' . $disabled . '>';
	echo '<option value="asc"' . selected( $val, 'asc', false ) . '>Ascending</option>';
	echo '<option value="desc"' . selected( $val, 'desc', false ) . '>Descending</option>';
	echo '</select>';
	echo '<p class="description">Default sort direction when a default sort column is set.</p>';
	echo '</div>';
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
