<?php
/**
 * Plugin Name: Lightweight Cookieless Analytics
 * Plugin URI:  https://github.com/[USER]/lightweight-cookieless-analytics
 * Description: Ultra-light, cookieless, real-time analytics for WordPress. No personal data stored.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lightweight-cookieless-analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'LCA_VERSION', '1.0.0' );
define( 'LCA_TABLE', $wpdb->prefix . 'lca_events' );

/**
 * Activation hook: create the custom table.
 */
register_activation_hook( __FILE__, 'lca_activate' );
function lca_activate() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$wpdb->prefix}lca_events (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_time DATETIME NOT NULL,
		url VARCHAR(255) NOT NULL,
		referrer VARCHAR(255) DEFAULT '',
		title VARCHAR(255) DEFAULT '',
		screen VARCHAR(20) DEFAULT '',
		ip_hash CHAR(32) NOT NULL,
		PRIMARY KEY  (id),
		KEY event_time (event_time),
		KEY url (url(191))
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Uninstall hook: remove the table and any plugin options.
 */
register_uninstall_hook( __FILE__, 'lca_uninstall' );
function lca_uninstall() {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lca_events" );
	delete_option( 'lca_exclude_admin' );
}

/**
 * Enqueue the tracking script inline.
 */
add_action( 'wp_enqueue_scripts', 'lca_enqueue_script' );
function lca_enqueue_script() {
	// Exclude logged-in administrators if option enabled.
	if ( current_user_can( 'manage_options' ) && get_option( 'lca_exclude_admin', 1 ) ) {
		return;
	}
	wp_register_script( 'lca-tracker', '', array(), LCA_VERSION, true );
	wp_enqueue_script( 'lca-tracker' );
	wp_add_inline_script( 'lca-tracker', '
		(function() {
			var endpoint = ' . json_encode( esc_url_raw( rest_url( 'lca/v1/track' ) ) ) . ';
			var data = {
				url: location.href,
				ref: document.referrer || "",
				title: document.title || "",
				screen: screen.width + "x" + screen.height
			};
			var blob = new Blob([JSON.stringify(data)], {type: "application/json"});
			navigator.sendBeacon(endpoint, blob);
		})();
	' );
}

/**
 * REST API endpoint for tracking.
 */
add_action( 'rest_api_init', 'lca_register_rest_routes' );
function lca_register_rest_routes() {
	register_rest_route( 'lca/v1', '/track', array(
		'methods'             => 'POST',
		'callback'            => 'lca_handle_track',
		'permission_callback' => '__return_true',
	) );
}

function lca_handle_track( WP_REST_Request $request ) {
	global $wpdb;

	$data = $request->get_json_params();

	$url      = isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
	$referrer = isset( $data['ref'] ) ? esc_url_raw( $data['ref'] ) : '';
	$title    = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
	$screen   = isset( $data['screen'] ) ? sanitize_text_field( $data['screen'] ) : '';

	// Anonymise IP: hash with a site-specific salt.
	$ip_hash = md5( $_SERVER['REMOTE_ADDR'] . wp_salt( 'auth' ) );

	$wpdb->insert(
		$wpdb->prefix . 'lca_events',
		array(
			'event_time' => current_time( 'mysql' ),
			'url'        => $url,
			'referrer'   => $referrer,
			'title'      => $title,
			'screen'     => $screen,
			'ip_hash'    => $ip_hash,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return new WP_REST_Response( null, 204 );
}

/**
 * AJAX endpoint for real-time stats.
 */
add_action( 'wp_ajax_lca_realtime_stats', 'lca_ajax_realtime_stats' );
function lca_ajax_realtime_stats() {
	check_ajax_referer( 'lca_realtime_nonce', 'nonce' );

	global $wpdb;
	$table = $wpdb->prefix . 'lca_events';

	// Stats for the last 5 minutes.
	$since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 300 );

	$total_views = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE event_time >= %s",
		$since
	) );

	$unique_visitors = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT ip_hash) FROM $table WHERE event_time >= %s",
		$since
	) );

	$top_pages = $wpdb->get_results( $wpdb->prepare(
		"SELECT url, COUNT(*) as views FROM $table WHERE event_time >= %s GROUP BY url ORDER BY views DESC LIMIT 10",
		$since
	) );

	wp_send_json_success( array(
		'total_views'     => (int) $total_views,
		'unique_visitors' => (int) $unique_visitors,
		'top_pages'       => $top_pages,
		'time'            => current_time( 'mysql' ),
	) );
}

/**
 * Admin menu and dashboard page.
 */
add_action( 'admin_menu', 'lca_admin_menu' );
function lca_admin_menu() {
	add_menu_page(
		'Lightweight Analytics',
		'Analytics',
		'manage_options',
		'lca-dashboard',
		'lca_render_dashboard',
		'dashicons-chart-area',
		26
	);
}

function lca_render_dashboard() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$nonce = wp_create_nonce( 'lca_realtime_nonce' );
	?>
	<div class="wrap">
		<h1>Lightweight Analytics – Real-time</h1>
		<p>Data from the last 5 minutes. Auto-refreshes every 10 seconds.</p>
		<div id="lca-stats">
			<p>Total views: <span id="lca-total">0</span></p>
			<p>Unique visitors: <span id="lca-unique">0</span></p>
			<h3>Top pages</h3>
			<ul id="lca-top-pages"></ul>
		</div>
	</div>
	<script>
	(function() {
		const nonce = '<?php echo esc_js( $nonce ); ?>';
		const ajaxurl = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
		async function fetchStats() {
			try {
				const res = await fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'lca_realtime_stats',
						nonce: nonce
					})
				});
				const data = await res.json();
				if (data.success) {
					document.getElementById('lca-total').textContent = data.data.total_views;
					document.getElementById('lca-unique').textContent = data.data.unique_visitors;
					const list = document.getElementById('lca-top-pages');
					list.innerHTML = '';
					data.data.top_pages.forEach(page => {
						const li = document.createElement('li');
						li.textContent = `${page.url} (${page.views} views)`;
						list.appendChild(li);
					});
				}
			} catch (e) {
				console.error('LCA fetch error:', e);
			}
		}
		fetchStats();
		setInterval(fetchStats, 10000);
	})();
	</script>
	<?php
}

/**
 * Option to exclude administrators from tracking.
 */
add_action( 'admin_init', 'lca_register_settings' );
function lca_register_settings() {
	register_setting( 'general', 'lca_exclude_admin' );
}
