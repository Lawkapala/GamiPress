<?php
/**
 * GamiPress Points Types Shortcode
 *
 * @package     GamiPress\Shortcodes\Shortcode\GamiPress_Points_Types
 * @since       1.0.0
 */
// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

/**
 * Register [gamipress_points_types] shortcode.
 *
 * @since 1.0.0
 */
function gamipress_register_points_types_shortcode() {

    // Setup a custom array of points types
    $points_types = array(
        'all' => __( 'All', 'gamipress' ) ,
    );

    foreach ( gamipress_get_points_types() as $slug => $data ) {
        $points_types[$slug] = $data['plural_name'];
    }

    gamipress_register_shortcode( 'gamipress_points_types', array(
        'name'            => __( 'Points Types', 'gamipress' ),
        'description'     => __( 'Output a list of points types with their points awards.', 'gamipress' ),
        'output_callback' => 'gamipress_points_types_shortcode',
        'fields'      => array(
            'type' => array(
                'name'        => __( 'Points Type(s)', 'gamipress' ),
                'description' => __( 'Single, or comma-separated list of, points type(s) to display.', 'gamipress' ),
                'type'        => 'select_multiple',
                'options'      => $points_types,
                'default'     => 'all',
            ),
            'wpms' => array(
                'name'        => __( 'Include Multisite Points Types', 'gamipress' ),
                'description' => __( 'Show points types from all network sites.', 'gamipress' ),
                'type' 	=> 'checkbox',
                'classes' => 'gamipress-switch',
            ),
        ),
    ) );
}
add_action( 'init', 'gamipress_register_points_types_shortcode' );

/**
 * Achievement List Shortcode.
 *
 * @since  1.0.0
 *
 * @param  array $atts Shortcode attributes.
 * @return string 	   HTML markup.
 */
function gamipress_points_types_shortcode( $atts = array () ) {
    global $gamipress_template_args, $blog_id;

    // Initialize GamiPress template args global
    $gamipress_template_args = array();

    $atts = shortcode_atts( array(
        // Points atts
        'type'        => 'all',
        'wpms'        => 'no',
    ), $atts, 'gamipress_points' );

    $atts['wpms'] = gamipress_shortcode_att_to_bool( $atts['wpms'] );

    wp_enqueue_style( 'gamipress' );

    // Single type check to use dynamic template
    $is_single_type = false;
    $types = explode( ',', $atts['type'] );

    if ( ( 'all' !== $atts['type'] && '' !== $atts['type'] ) && count( $types ) === 1 ) {
        $is_single_type = true;
    } else if( 'all' === $atts['type'] ) {
        $types = gamipress_get_points_types_slugs();
    }

    // If we're polling all sites, grab an array of site IDs
    if( $atts['wpms'] )
        $sites = gamipress_get_network_site_ids();
    // Otherwise, use only the current site
    else
        $sites = array( $blog_id );

    // GamiPress template args global
    $gamipress_template_args = $atts;

    // Get the points count of all registered network sites
    $gamipress_template_args['points-types'] = array();

    // Loop through each site (default is current site only)
    foreach( $sites as $site_blog_id ) {

        // If we're not polling the current site, switch to the site we're polling
        if ( $blog_id != $site_blog_id ) {
            switch_to_blog( $site_blog_id );
        }

        foreach( $types as $points_type ) {
            if( ! isset( $gamipress_template_args['points-types'][$points_type] ) ) {
                $gamipress_template_args['points-types'][$points_type] = array();
            }

            $points_awards = gamipress_get_points_type_points_awards( $points_type );

            if( $points_awards ) {
                $gamipress_template_args['points-types'][$points_type] += $points_awards;
            }
        }



        if ( $blog_id != $site_blog_id ) {
            // Come back to current blog
            restore_current_blog();
        }

    }

    ob_start();
    if( $is_single_type ) {
        gamipress_get_template_part( 'points-types', $atts['type'] );
    } else {
        gamipress_get_template_part( 'points-types' );
    }
    $output = ob_get_clean();

    return $output;

}