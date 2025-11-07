<?php
/**
 * Plugin Name: WP Auto Bump
 * Plugin URI: https://mikemoisio.ai/wp-auto-bump-plugin/
 * Description: WP Auto Bump automatically updates the publish date of older posts to make them appear freshly updated. Perfect for evergreen blogs, news sites, or content marketers who want to keep their homepage dynamic.
 * Version: 1.0.0
 * Author: Mike Moisio
 * Author URI: https://mikemoisio.ai
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-auto-bump
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Load plugin textdomain for translations.
 */
function wpab_load_textdomain() {
	load_plugin_textdomain( 'wp-auto-bump', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wpab_load_textdomain' );

/**
 * Return default options for WP Auto Bump.
 *
 * @return array
 */
function wpab_default_options() {
	return array(
		'categories'       => array(), // empty => all categories
		'frequency'        => 7,       // days
		'variation_days'   => 0,
		'variation_hours'  => 6,
	);
}

/**
 * Get plugin options merged with defaults.
 *
 * @return array
 */
function wpab_get_options() {
	$defaults = wpab_default_options();
	$opts     = get_option( 'wp_auto_bump_options', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	return array_merge( $defaults, $opts );
}

/**
 * Sanitize and validate options.
 *
 * Enforces:
 * - frequency: 1..365 (days)
 * - variation_days: >= 0
 * - variation_hours: 0..23
 * - (variation_days*24 + variation_hours) < frequency*24 - 1
 *
 * @param array $input Raw input from Settings API.
 * @return array Sanitized options.
 */
function wpab_sanitize_options( $input ) {
	$defaults = wpab_default_options();
	$clean    = array();

	// Categories: array of integers; empty means all categories.
	$clean['categories'] = array();
	if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
		foreach ( $input['categories'] as $term_id ) {
			$term_id = absint( $term_id );
			if ( $term_id > 0 ) {
				$clean['categories'][] = $term_id;
			}
		}
		$clean['categories'] = array_values( array_unique( $clean['categories'] ) );
	}

	// Frequency: days (1..365)
	$freq = isset( $input['frequency'] ) ? intval( $input['frequency'] ) : $defaults['frequency'];
	$freq = max( 1, min( 365, $freq ) );
	$clean['frequency'] = $freq;

	// Variation: days >= 0, hours 0..23
	$var_days  = isset( $input['variation_days'] ) ? intval( $input['variation_days'] ) : $defaults['variation_days'];
	$var_hours = isset( $input['variation_hours'] ) ? intval( $input['variation_hours'] ) : $defaults['variation_hours'];
	$var_days  = max( 0, $var_days );
	$var_hours = max( 0, min( 23, $var_hours ) );

	$total_var_hours = ( $var_days * 24 ) + $var_hours;
	$max_allowed     = ( $freq * 24 ) - 1; // must be strictly less than this

	if ( $total_var_hours >= $max_allowed ) {
		// Cap to one hour less than the strict limit to satisfy: total < (freq*24 - 1)
		$total_var_hours = max( 0, $max_allowed - 1 );
		/* translators: %s: maximum hours of allowed variation */
		add_settings_error( 'wp_auto_bump_options', 'variation_too_high', sprintf( __( 'Variation reduced to %s hours to satisfy limits.', 'wp-auto-bump' ), number_format_i18n( $total_var_hours ) ) );
	}

	$clean['variation_days']  = (int) floor( $total_var_hours / 24 );
	$clean['variation_hours'] = (int) ( $total_var_hours % 24 );

	return $clean;
}

/**
 * Add admin settings page.
 */
function wpab_admin_menu() {
	add_options_page(
		__( 'WP Auto Bump', 'wp-auto-bump' ),
		__( 'WP Auto Bump', 'wp-auto-bump' ),
		'manage_options',
		'wp-auto-bump',
		'wpab_render_settings_page'
	);
}
add_action( 'admin_menu', 'wpab_admin_menu' );

/**
 * Register settings, sections, and fields.
 */
function wpab_register_settings() {
	register_setting(
		'wpab_settings',
		'wp_auto_bump_options',
		array(
			'sanitize_callback' => 'wpab_sanitize_options',
			'default'           => wpab_default_options(),
		)
	);

	add_settings_section(
		'wpab_main',
		__( 'Bump Settings', 'wp-auto-bump' ),
		'wpab_section_desc',
		'wp-auto-bump'
	);

	add_settings_field(
		'wpab_categories',
		__( 'Categories', 'wp-auto-bump' ),
		'wpab_field_categories',
		'wp-auto-bump',
		'wpab_main'
	);

	add_settings_field(
		'wpab_frequency',
		__( 'Bump Frequency', 'wp-auto-bump' ),
		'wpab_field_frequency',
		'wp-auto-bump',
		'wpab_main'
	);

	add_settings_field(
		'wpab_variation',
		__( 'Bump Variation', 'wp-auto-bump' ),
		'wpab_field_variation',
		'wp-auto-bump',
		'wpab_main'
	);
}
add_action( 'admin_init', 'wpab_register_settings' );

/**
 * Section description callback.
 */
function wpab_section_desc() {
	echo '<p>' . esc_html__( 'Choose which posts to consider and how often to bump them. The plugin will randomly bump one older post at each scheduled time.', 'wp-auto-bump' ) . '</p>';
}

/**
 * Categories field callback.
 */
function wpab_field_categories() {
	$opts       = wpab_get_options();
	$selected   = isset( $opts['categories'] ) && is_array( $opts['categories'] ) ? array_map( 'intval', $opts['categories'] ) : array();
	$terms      = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
	$name_attr  = 'wp_auto_bump_options[categories][]';

	echo '<select multiple style="min-width:320px;max-width:100%;height:12rem;" name="' . esc_attr( $name_attr ) . '" id="wpab_categories">';
	if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$sel = in_array( (int) $term->term_id, $selected, true ) ? ' selected' : '';
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . $sel . '>' . esc_html( $term->name ) . '</option>';
		}
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Leave empty (no selection) to include all categories.', 'wp-auto-bump' ) . '</p>';
}

/**
 * Frequency field callback.
 */
function wpab_field_frequency() {
	$opts = wpab_get_options();
	$val  = isset( $opts['frequency'] ) ? intval( $opts['frequency'] ) : 7;
	echo '<label for="wpab_frequency">' . esc_html__( 'Bump Frequency', 'wp-auto-bump' ) . '</label> ';
	echo '<input type="number" min="1" max="365" step="1" id="wpab_frequency" name="wp_auto_bump_options[frequency]" value="' . esc_attr( (string) $val ) . '" style="width:90px;" /> ';
	echo '<span>' . esc_html__( 'days', 'wp-auto-bump' ) . '</span>';
}

/**
 * Variation field callback.
 */
function wpab_field_variation() {
	$opts      = wpab_get_options();
	$days_val  = isset( $opts['variation_days'] ) ? intval( $opts['variation_days'] ) : 0;
	$hours_val = isset( $opts['variation_hours'] ) ? intval( $opts['variation_hours'] ) : 6;
	echo '<label for="wpab_variation_days">' . esc_html__( 'Variation Days', 'wp-auto-bump' ) . '</label> ';
	echo '<input type="number" min="0" step="1" id="wpab_variation_days" name="wp_auto_bump_options[variation_days]" value="' . esc_attr( (string) $days_val ) . '" style="width:90px;" /> ';
	echo '&nbsp;&nbsp;';
	echo '<label for="wpab_variation_hours">' . esc_html__( 'Variation Hours', 'wp-auto-bump' ) . '</label> ';
	echo '<input type="number" min="0" max="23" step="1" id="wpab_variation_hours" name="wp_auto_bump_options[variation_hours]" value="' . esc_attr( (string) $hours_val ) . '" style="width:90px;" /> ';
	echo '<p class="description">' . esc_html__( 'Randomly applied in both directions. The total variation (in hours) must be less than: Frequency × 24 − 1.', 'wp-auto-bump' ) . '</p>';
}

/**
 * Render settings page.
 */
function wpab_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$next_ts  = (int) get_option( 'wp_auto_bump_next_time' );
	$now      = time();
	$next_str = $next_ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts ) : __( 'Immediately (due)', 'wp-auto-bump' );
	if ( $next_ts && $next_ts <= $now ) {
		$next_str = __( 'Immediately (due)', 'wp-auto-bump' );
	}

	// Optional notice after "Bump Now" action.
	if ( isset( $_GET['wpab_bump'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = sanitize_text_field( wp_unslash( $_GET['wpab_bump'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'done' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'A post was bumped successfully.', 'wp-auto-bump' ) . '</p></div>';
		} elseif ( 'none' === $state ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No eligible posts found to bump.', 'wp-auto-bump' ) . '</p></div>';
		}
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'WP Auto Bump', 'wp-auto-bump' ); ?></h1>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'wpab_settings' );
			do_settings_sections( 'wp-auto-bump' );
			submit_button();
			?>
		</form>
		<hr />
		<p><strong><?php echo esc_html__( 'Next bump scheduled:', 'wp-auto-bump' ); ?></strong> <?php echo esc_html( $next_str ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
			<?php wp_nonce_field( 'wpab_bump_now_action' ); ?>
			<input type="hidden" name="action" value="wpab_bump_now" />
			<?php submit_button( __( 'Bump Now', 'wp-auto-bump' ), 'secondary', 'submit', false ); ?>
			<p class="description" style="margin-top:.5rem;"><?php echo esc_html__( 'Immediately bumps one random older post and recalculates the next schedule.', 'wp-auto-bump' ); ?></p>
		</form>
	</div>
	<?php
}

/**
 * Calculate the next bump timestamp from a given starting point.
 *
 * @param int   $from_ts Starting UNIX timestamp (typically now).
 * @param array $opts    Options array.
 * @return int Next run timestamp (UTC).
 */
function wpab_calculate_next_time( $from_ts, $opts ) {
	$freq_days = isset( $opts['frequency'] ) ? max( 1, (int) $opts['frequency'] ) : 1;
	$var_days  = isset( $opts['variation_days'] ) ? max( 0, (int) $opts['variation_days'] ) : 0;
	$var_hours = isset( $opts['variation_hours'] ) ? max( 0, min( 23, (int) $opts['variation_hours'] ) ) : 0;

	$total_var_hours = ( $var_days * 24 ) + $var_hours;
	$max_allowed     = ( $freq_days * 24 ) - 1; // must be strictly less than this
	if ( $total_var_hours >= $max_allowed ) {
		$total_var_hours = max( 0, $max_allowed - 1 );
	}

	$rand_offset_hours = $total_var_hours > 0 ? random_int( -$total_var_hours, $total_var_hours ) : 0;
	$offset            = ( $freq_days * DAY_IN_SECONDS ) + ( $rand_offset_hours * HOUR_IN_SECONDS );
	$ts                = $from_ts + $offset;

	// Defensive: ensure we don't schedule in the immediate past/now due to rounding.
	if ( $ts <= ( $from_ts + HOUR_IN_SECONDS ) ) {
		$ts = $from_ts + HOUR_IN_SECONDS;
	}

	return $ts;
}

/**
 * Schedule the next single cron event and persist the next time.
 *
 * @param bool $recompute If true, compute from now; otherwise use saved option or compute if missing.
 */
function wpab_schedule_next_event( $recompute = true ) {
	$opts = wpab_get_options();
	$now  = time();

	if ( $recompute ) {
		$next = wpab_calculate_next_time( $now, $opts );
		update_option( 'wp_auto_bump_next_time', (int) $next );
	} else {
		$next = (int) get_option( 'wp_auto_bump_next_time' );
		if ( ! $next ) {
			$next = wpab_calculate_next_time( $now, $opts );
			update_option( 'wp_auto_bump_next_time', (int) $next );
		}
	}

	// Clear any previously scheduled events for this hook to avoid duplicates.
	wp_clear_scheduled_hook( 'wp_auto_bump_run' );
	wp_schedule_single_event( (int) $next, 'wp_auto_bump_run' );
}

/**
 * On plugin activation: ensure defaults exist and schedule the first event.
 */
function wpab_activate() {
	if ( false === get_option( 'wp_auto_bump_options', false ) ) {
		add_option( 'wp_auto_bump_options', wpab_default_options() );
	}
	// Treat missing/zero as due now for first run handling.
	update_option( 'wp_auto_bump_next_time', 0 );
	wpab_schedule_next_event( true );
}
register_activation_hook( __FILE__, 'wpab_activate' );

/**
 * On plugin deactivation: clear scheduled cron job.
 */
function wpab_deactivate() {
	wp_clear_scheduled_hook( 'wp_auto_bump_run' );
}
register_deactivation_hook( __FILE__, 'wpab_deactivate' );

/**
 * Reschedule cron when settings are updated.
 *
 * @param mixed $old Old value.
 * @param mixed $new New value.
 * @param string $option Option name.
 */
function wpab_options_updated( $old, $new, $option ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parameterNameFound
	if ( 'wp_auto_bump_options' === $option ) {
		wpab_schedule_next_event( true );
	}
}
add_action( 'update_option_wp_auto_bump_options', 'wpab_options_updated', 10, 3 );

/**
 * Cron handler: if due (or missing), bump a post and schedule next run.
 */
function wpab_cron_handler() {
	$now       = time();
	$next_time = (int) get_option( 'wp_auto_bump_next_time' );

	if ( $next_time && $next_time > $now ) {
		// Not time yet; make sure event exists at the expected time.
		wp_clear_scheduled_hook( 'wp_auto_bump_run' );
		wp_schedule_single_event( $next_time, 'wp_auto_bump_run' );
		return;
	}

	// Perform a bump immediately (first run or overdue).
	wpab_bump_one_post();

	// Compute and schedule the next run.
	wpab_schedule_next_event( true );
}
add_action( 'wp_auto_bump_run', 'wpab_cron_handler' );

/**
 * Execute the bump: pick one random post from the oldest ~10% of eligible posts and update its dates.
 *
 * @return int|false Post ID on success, false on failure or nothing to bump.
 */
function wpab_bump_one_post() {
	$opts = wpab_get_options();

	$args_base = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'orderby'             => 'date',
		'order'               => 'ASC',
		'ignore_sticky_posts' => true,
	);

	// First query: get total posts count with found_posts.
	$args_count = $args_base;
	$args_count['fields']         = 'ids';
	$args_count['posts_per_page'] = 1;
	$args_count['no_found_rows']  = false; // we need found_posts

	if ( ! empty( $opts['categories'] ) ) {
		$args_count['category__in'] = array_map( 'intval', $opts['categories'] );
	}

	$q_count = new WP_Query( $args_count );
	$total   = (int) $q_count->found_posts;

	if ( $total <= 0 ) {
		return false;
	}

	$sample = (int) max( 1, min( 100, ceil( $total * 0.10 ) ) );

	$args_pick = $args_base;
	$args_pick['fields']         = 'ids';
	$args_pick['posts_per_page'] = $sample;
	$args_pick['no_found_rows']  = true;
	if ( ! empty( $opts['categories'] ) ) {
		$args_pick['category__in'] = array_map( 'intval', $opts['categories'] );
	}

	$q_pick = new WP_Query( $args_pick );
	$ids    = is_array( $q_pick->posts ) ? $q_pick->posts : array();

	if ( empty( $ids ) ) {
		return false;
	}

	$random_id = (int) $ids[ array_rand( $ids ) ];

	$local_now = current_time( 'mysql', false );
	$gmt_now   = current_time( 'mysql', true );

	$postarr = array(
		'ID'                 => $random_id,
		'post_date'          => $local_now,
		'post_date_gmt'      => $gmt_now,
		'post_modified'      => $local_now,
		'post_modified_gmt'  => $gmt_now,
	);

	// Update the post timestamps.
	wp_update_post( $postarr );

	return $random_id;
}

/**
 * Handle the admin action for the "Bump Now" button.
 *
 * This sets the next time to now, performs the bump immediately, and reschedules the next run.
 */
function wpab_handle_bump_now() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-auto-bump' ) );
	}

	check_admin_referer( 'wpab_bump_now_action' );

	// Set next time to now for traceability, then bump and reschedule.
	update_option( 'wp_auto_bump_next_time', time() );

	$result = wpab_bump_one_post();
	wpab_schedule_next_event( true );

	$redirect = add_query_arg(
		array(
			'page'       => 'wp-auto-bump',
			'wpab_bump'  => $result ? 'done' : 'none',
		),
		admin_url( 'options-general.php' )
	);

	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_wpab_bump_now', 'wpab_handle_bump_now' );
