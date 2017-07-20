<?php
/**
 * Activity Triggers, used for triggering achievement earning
 *
 * @package     GamiPress\Triggers
 * @since       1.0.0
 */
// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

/**
 * GamiPress activity triggers
 *
 * @since  1.0.0
 * @return array Array of all activity triggers
 */
function gamipress_get_activity_triggers() {
	GamiPress()->activity_triggers = apply_filters( 'gamipress_activity_triggers',
		array(
			// WordPress
			__( 'WordPress', 'gamipress' ) => array(
				'wp_login'             				=> __( 'Log in to website', 'gamipress' ),
				'gamipress_new_comment'  			=> __( 'Comment on a post', 'gamipress' ),
				'gamipress_specific_new_comment' 	=> __( 'Comment on a specific post', 'gamipress' ),
				'gamipress_publish_post'     		=> __( 'Publish a new post', 'gamipress' ),
				'gamipress_publish_page'     		=> __( 'Publish a new page', 'gamipress' ),
			),
			// Site Interactions
			__( 'Site Interactions', 'gamipress' ) => array(
				'gamipress_site_visit'  			=> __( 'Daily visit the website', 'gamipress' ),
				'gamipress_specific_post_visit'  	=> __( 'Daily visit a specific post', 'gamipress' ),
			),
			// GamiPress
			__( 'GamiPress', 'gamipress' ) => array(
				'specific-achievement' 				=> __( 'Specific Achievement of Type', 'gamipress' ),
				'any-achievement'      				=> __( 'Any Achievement of Type', 'gamipress' ),
				'all-achievements'     				=> __( 'All Achievements of Type', 'gamipress' ),
			),
		)
	);

	return GamiPress()->activity_triggers;
}

/**
 * GamiPress specific activity triggers
 *
 * @since  1.0.0
 * @return array Array of all specific activity triggers
 */
function gamipress_get_specific_activity_triggers() {
	return apply_filters( 'gamipress_specific_activity_triggers', array(
		'gamipress_specific_new_comment' 	=> array( 'post', 'page' ),
		'gamipress_specific_post_visit'  	=> array( 'post', 'page' ),
	) );
}

/**
 * Helper function for returning an activity trigger label
 *
 * @since  1.0.0
 * @param string $activity_trigger
 * @return string
 */
function gamipress_get_activity_trigger_label( $activity_trigger ) {
	$activity_triggers = gamipress_get_activity_triggers();

	foreach( $activity_triggers as $group => $group_triggers ) {
		if( isset( $group_triggers[$activity_trigger] ) ) {
			return $group_triggers[$activity_trigger];
		}
	}

	return '';
}

/**
 * Helper function for returning a specific activity trigger label
 *
 * @since  1.0.0
 * @param string $activity_trigger
 * @return string
 */
function gamipress_get_specific_activity_trigger_label( $activity_trigger ) {
	$specific_activity_trigger_labels = apply_filters( 'gamipress_specific_activity_trigger_label', array(
		'gamipress_specific_new_comment' 	=> 'Comment on %s',
		'gamipress_specific_post_visit'  	=> 'Visit %s',
	) );

	if( isset( $specific_activity_trigger_labels[$activity_trigger] ) ) {
		return $specific_activity_trigger_labels[$activity_trigger];
	}

	return '';
}

/**
 * Load up our activity triggers so we can add actions to them
 *
 * @since 1.0.0
 * @return void
 */
function gamipress_load_activity_triggers() {

	// Grab our activity triggers
	$activity_triggers = gamipress_get_activity_triggers();

	// Loop through each achievement type and add triggers for unlocking them
	foreach ( gamipress_get_achievement_types_slugs() as $achievement_type ) {

		// Grab the post type object, and bail if it's not actually an object
		$post_type_object = get_post_type_object( $achievement_type );
		if ( ! is_object( $post_type_object ) )
			continue;

		// Add trigger for unlocking ANY and ALL posts for each achievement type
		$activity_triggers[__( 'GamiPress', 'gamipress' )]['gamipress_unlock_' . $achievement_type] = sprintf( __( 'Unlocked a %s', 'gamipress' ), $post_type_object->labels->singular_name );
		$activity_triggers[__( 'GamiPress', 'gamipress' )]['gamipress_unlock_all_' . $achievement_type] = sprintf( __( 'Unlocked all %s', 'gamipress' ), $post_type_object->labels->name );

	}

	// Loop through each trigger and add our trigger event to the hook
	foreach ( $activity_triggers as $group => $group_triggers ) {
		foreach( $group_triggers as $trigger => $label ) {
			add_action( $trigger, 'gamipress_trigger_event', 10, 20 );
		}
	}

}
add_action( 'init', 'gamipress_load_activity_triggers' );

/**
 * Handle each of our activity triggers
 *
 * @since 1.0.0
 * @return mixed
 */
function gamipress_trigger_event() {

	// Setup all our globals
	global $blog_id, $wpdb;

	$site_id = $blog_id;

	$args = func_get_args();

	// Grab our current trigger
	$this_trigger = current_filter();

	// Grab the user ID
	$user_id = gamipress_trigger_get_user_id( $this_trigger, $args );
	$user_data = get_user_by( 'id', $user_id );

	// Sanity check, if we don't have a user object, bail here
	if ( ! is_object( $user_data ) )
		return $args[ 0 ];

	// If the user doesn't satisfy the trigger requirements, bail here
	if ( ! apply_filters( 'gamipress_user_deserves_trigger', true, $user_id, $this_trigger, $site_id, $args ) )
		return $args[ 0 ];

	// Update hook count for this user
	$new_count = gamipress_update_user_trigger_count( $user_id, $this_trigger, $site_id, $args );

	// Mark the count in the log entry
	gamipress_insert_log( $user_id, 'private', array(
		'type' => 'event_trigger',
		'pattern' => '{user} triggered {trigger_type} (x{count})',
		'count' => $new_count,
		'trigger_type' => $this_trigger,
	) );

	// Now determine if any badges are earned based on this trigger event
	$triggered_achievements = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id
		FROM   $wpdb->postmeta
		WHERE  meta_key = '_gamipress_trigger_type'
		       AND meta_value = %s",
		$this_trigger
	) );

	foreach ( $triggered_achievements as $achievement ) {
		gamipress_maybe_award_achievement_to_user( $achievement->post_id, $user_id, $this_trigger, $site_id, $args );
	}

	return $args[ 0 ];

}

/**
 * Get user for a given trigger action.
 *
 * @since  1.0.0
 *
 * @param  string  $trigger Trigger name.
 * @param  array   $args    Passed trigger args.
 * @return integer          User ID.
 */
function gamipress_trigger_get_user_id( $trigger = '', $args = array() ) {

	switch ( $trigger ) {
		case 'wp_login':
			$user_data = get_user_by( 'login', $args[ 0 ] );
			$user_id = $user_data->ID;
			break;
		case 'gamipress_site_visit':
		case 'gamipress_unlock_' == substr( $trigger, 0, 15 ):
			$user_id = $args[0];
			break;
		case 'gamipress_publish_post':
		case 'gamipress_publish_page':
		case 'gamipress_new_comment':
		case 'gamipress_specific_new_comment':
		case 'gamipress_specific_post_visit':
			$user_id = $args[1];
			break;
		default :
			$user_id = get_current_user_id();
			break;
	}

	return apply_filters( 'gamipress_trigger_get_user_id', $user_id, $trigger, $args );
}

/**
 * Wrapper function for returning a user's array of sprung triggers
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  integer $site_id The desired Site ID to check
 * @return array            An array of the triggers a user has triggered
 */
function gamipress_get_user_triggers( $user_id = 0, $site_id = 0 ) {

	// Grab all of the user's triggers
	$user_triggers = ( $array_exists = get_user_meta( $user_id, '_gamipress_triggered_triggers', true ) ) ? $array_exists : array( $site_id => array() );

	// Use current site ID if site ID is not set, AND not explicitly set to false
	if ( ! $site_id && false !== $site_id ) {
		$site_id = get_current_blog_id();
	}

	// Return only the triggers that are relevant to the provided $site_id
	if ( $site_id && isset( $user_triggers[ $site_id ] ) ) {
		return $user_triggers[ $site_id ];

	// Otherwise, return the full array of all triggers across all sites
	} else {
		return $user_triggers;
	}
}

/**
 * Get the count for the number of times a user has triggered a particular trigger
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  string  $trigger The given trigger we're checking
 * @param  integer $site_id The desired Site ID to check
 * @param  array $args      The triggered args
 * @return integer          The total number of times a user has triggered the trigger
 */
function gamipress_get_user_trigger_count( $user_id, $trigger, $site_id = 0, $args = array() ) {

	// Set to current site id
	if ( ! $site_id )
		$site_id = get_current_blog_id();

	// Grab the user's logged triggers
	$user_triggers = gamipress_get_user_triggers( $user_id, $site_id );

	$trigger = apply_filters( 'gamipress_get_user_trigger_name', $trigger, $user_id, $site_id, $args );

	// If we have any triggers, return the current count for the given trigger
	if ( ! empty( $user_triggers ) && isset( $user_triggers[$trigger] ) )
		return absint( $user_triggers[$trigger] );

	// Otherwise, they've never hit the trigger
	else
		return 0;

}

/**
 * Get the count for the number of times is logged a user has triggered a particular trigger
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  string  $trigger The given trigger we're checking
 * @param  integer $since 	The since timestamp where retrieve the logs
 * @param  integer $site_id The desired Site ID to check
 * @param  array $args      The triggered args
 * @return integer          The total number of times a user has triggered the trigger
 */
function gamipress_get_user_trigger_count_from_logs( $user_id, $trigger, $since = 0, $site_id = 0, $args = array() ) {
	global $wpdb;

	// Set to current site id
	if ( ! $site_id )
		$site_id = get_current_blog_id();

	$post_date = '';

	if( $since !== 0 ) {
		$now = date( 'Y-m-d' );
		$since = date( 'Y-m-d', $since );

		$post_date = "AND p.post_date BETWEEN '$since' AND '$now'";

		if( $since === $now ) {
			$post_date = "AND p.post_date >= '$now'";
		}
	}

	$user_triggers = $wpdb->get_var( $wpdb->prepare(
		"
		SELECT COUNT(*)
		FROM   $wpdb->posts AS p
		LEFT JOIN $wpdb->postmeta AS pm1
		ON ( p.ID = pm1.post_id )
		LEFT JOIN $wpdb->postmeta AS pm2
		ON ( p.ID = pm2.post_id )
		WHERE p.post_type = %s
			AND p.post_author = %s
			{$post_date}
			AND (
				( pm1.meta_key = %s AND pm1.meta_value = %s )
				AND ( pm2.meta_key = %s AND pm2.meta_value = %s )
			)
		",
		'gamipress-log',
		$user_id,
		'_gamipress_type', 'event_trigger',
		'_gamipress_trigger_type', $trigger
	) );

	// If we have any triggers, return the current count for the given trigger
	return absint( $user_triggers );
}

/**
 * Update the user's trigger count for a given trigger by 1
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  string  $trigger The trigger we're updating
 * @param  integer $site_id The desired Site ID to update
 * @param  array $args        The triggered args
 * @return integer          The updated trigger count
 */
function gamipress_update_user_trigger_count( $user_id, $trigger, $site_id = 0, $args = array() ) {

	// Set to current site id
	if ( ! $site_id )
		$site_id = get_current_blog_id();

	// Grab the current count and increase it by 1
	$trigger_count = absint( gamipress_get_user_trigger_count( $user_id, $trigger, $site_id, $args ) );
	$trigger_count += (int) apply_filters( 'gamipress_update_user_trigger_count', 1, $user_id, $trigger, $site_id, $args );

	// Update the triggers arary with the new count
	$user_triggers = gamipress_get_user_triggers( $user_id, false );
	$user_triggers[$site_id][$trigger] = $trigger_count;
	update_user_meta( $user_id, '_gamipress_triggered_triggers', $user_triggers );

	// Send back our trigger count for other purposes
	return $trigger_count;

}

/**
 * Reset a user's trigger count for a given trigger to 0 or reset ALL triggers
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  string  $trigger The trigger we're updating (or "all" to dump all triggers)
 * @param  integer $site_id The desired Site ID to update (or "all" to dump across all sites)
 * @return integer          The updated trigger count
 */
function gamipress_reset_user_trigger_count( $user_id, $trigger, $site_id = 0 ) {

	// Set to current site id
	if ( ! $site_id )
		$site_id = get_current_blog_id();

	// Grab the user's current triggers
	$user_triggers = gamipress_get_user_triggers( $user_id, false );

	// If we're deleteing all triggers...
	if ( 'all' == $trigger ) {
		// For all sites
		if ( 'all' == $site_id )
			$user_triggers = array();
		// For a specific site
		else
			$user_triggers[$site_id] = array();
	// Otherwise, reset the specific trigger back to zero
	} else {
		$user_triggers[$site_id][$trigger] = 0;
	}

	// Finally, update our user meta
	update_user_meta( $user_id, '_gamipress_triggered_triggers', $user_triggers );

}