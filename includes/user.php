<?php
/**
 * User-related Functions
 *
 * @package     GamiPress\User_Functions
 * @since       1.0.0
 */
// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

/**
 * Get a user's gamipress achievements
 *
 * @since  1.0.0
 *
 * @param  array $args An array of all our relevant arguments
 *
 * @return array       An array of all the achievement objects that matched our parameters, or empty if none
 */
function gamipress_get_user_achievements( $args = array() ) {

	// If not properly upgrade to required version fallback to compatibility function
	if( ! is_gamipress_upgraded_to( '1.2.8' ) ) {
		return gamipress_get_user_achievements_old( $args );
	}

	// Setup our default args
	$defaults = array(
		'user_id'          => 0,     // The given user's ID
		'site_id'          => get_current_blog_id(), // The given site's ID
		'achievement_id'   => false, // A specific achievement's post ID
		'achievement_type' => false, // A specific achievement type
		'since'            => 0,     // A specific timestamp to use in place of $limit_in_days
		'limit'            => -1,    // Limit of achievements to return
	);

	$args = wp_parse_args( $args, $defaults );

	// Use current user's ID if none specified
	if ( ! $args['user_id'] )
		$args['user_id'] = get_current_user_id();

	// Setup CT object
	ct_setup_table( 'gamipress_user_earnings' );

	// Setup query args
	$query_args = array(
		'user_id' => $args['user_id'],
		'nopaging' => true,
		'items_per_page' => $args['limit']
	);

	if( $args['achievement_id'] !== false ) {
		$query_args['post_id'] = $args['achievement_id'];
	}

	if( $args['achievement_type'] !== false ) {
		$query_args['post_type'] = $args['achievement_type'];
	}

	if( $args['since'] !== 0 ) {
		$query_args['since'] = $args['since'];
	}

	$ct_query = new CT_Query( $query_args );

	$achievements = $ct_query->get_results();

	foreach ( $achievements as $key => $achievement ) {

		// Update object for backward compatibility for usages previously to 1.2.7
		$achievement->ID = $achievement->post_id;
		$achievement->date_earned = strtotime( $achievement->date );

		$achievements[$key] = $achievement;

		if( isset( $args['display'] ) && $args['display'] ) {
			// Unset hidden achievements on display context
			$hidden = gamipress_get_hidden_achievement_by_id( $achievement->post_id );

			if( ! empty( $hidden ) ) {
				unset( $achievements[$key] );
			}
		}

	}

	return $achievements;

}

/**
 * Updates the user's earned achievements
 *
 * We can either replace the achievement's array, or append new achievements to it.
 *
 * @since  1.0.0
 *
 * @param  array        $args An array containing all our relevant arguments
 *
 * @return integer|bool       The updated umeta ID on success, false on failure
 */
function gamipress_update_user_achievements( $args = array() ) {

	// If not properly upgrade to required version fallback to compatibility function
	if( ! is_gamipress_upgraded_to( '1.2.8' ) ) {
		return gamipress_update_user_achievements_old( $args );
	}

	// Setup our default args
	$defaults = array(
		'user_id'          => 0,     // The given user's ID
		'site_id'          => get_current_blog_id(), // The given site's ID
		//'all_achievements' => false, // An array of ALL achievements earned by the user // TODO: Not supported since 1.2.8
		'new_achievements' => false, // An array of NEW achievements earned by the user
	);
	$args = wp_parse_args( $args, $defaults );

	// Use current user's ID if none specified
	if ( ! $args['user_id'] )
		$args['user_id'] = get_current_user_id();

	// Setup CT object
	$ct_table = ct_setup_table( 'gamipress_user_earnings' );

	// Lets to append the new achievements array
	if ( is_array( $args['new_achievements'] ) && ! empty( $args['new_achievements'] ) ) {

		foreach( $args['new_achievements'] as $new_achievement ) {
			$ct_table->db->insert( array(
				'user_id' => absint( $args['user_id'] ),
				'post_id' => $new_achievement->ID,
				'post_type' => $new_achievement->post_type,
				'points' => absint( $new_achievement->points ),
				'points_type' => $new_achievement->points_type,
				'date' => date( 'Y-m-d H:i:s', $new_achievement->date_earned )
			) );
		}

	}

	return true;

}

/**
 * Display achievements for a user on their profile screen
 *
 * @since  1.0.0
 * @param  object $user The current user's $user object
 * @return void
 */
function gamipress_user_profile_data( $user = null ) {
	// Verify user meets minimum role to view earned achievements
	if ( current_user_can( gamipress_get_manager_capability() ) ) : ?>

		<hr>

		<h2><i class="dashicons dashicons-gamipress"></i> <?php _e( 'GamiPress', 'gamipress' ); ?></h2>

		<?php // Output markup to user rank
		gamipress_profile_user_rank( $user );

        // Output markup to list user points
		gamipress_profile_user_points( $user );

        // Output markup to list user achievements
		gamipress_profile_user_achievements( $user );

		// Output markup for awarding achievement for user
		gamipress_profile_award_achievement( $user ); ?>

		<hr>

	<?php endif;

}
add_action( 'show_user_profile', 'gamipress_user_profile_data' );
add_action( 'edit_user_profile', 'gamipress_user_profile_data' );


/**
 * Save extra user meta fields to the Edit Profile screen
 *
 * @since  1.0.0
 * @param  int  $user_id      User ID being saved
 * @return mixed			  false if current user can not edit users, void if can
 */
function gamipress_save_user_profile_fields( $user_id = 0 ) {

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return false;
	}

	// Update user's rank, but only if edited
	if ( isset( $_POST['user_rank'] ) && absint( $_POST['user_rank'] ) !== gamipress_get_user_rank_id( $user_id ) ) {
		gamipress_update_user_rank( $user_id, absint( $_POST['user_rank'] ), get_current_user_id() );
	}

	$rank_types = gamipress_get_rank_types();

	foreach( $rank_types as $rank_type => $data ) {
		// Update each user's rank type, but only if edited
		if ( isset( $_POST['user_' . $rank_type . '_rank'] ) && absint( $_POST['user_' . $rank_type . '_rank'] ) !== gamipress_get_user_rank_id( $user_id, $rank_type ) ) {
			gamipress_update_user_rank( $user_id, absint( $_POST['user_' . $rank_type . '_rank'] ), get_current_user_id() );
		}
	}

	// Update our user's points total, but only if edited
	if ( isset( $_POST['user_points'] ) && $_POST['user_points'] !== gamipress_get_user_points( $user_id ) ) {
		gamipress_update_user_points( $user_id, absint( $_POST['user_points'] ), get_current_user_id() );
	}

    $points_types = gamipress_get_points_types();

    foreach( $points_types as $points_type => $data ) {
        // Update each user's points type total, but only if edited
        if ( isset( $_POST['user_' . $points_type . '_points'] ) && $_POST['user_' . $points_type . '_points'] !== gamipress_get_user_points( $user_id, $points_type ) ) {
            gamipress_update_user_points( $user_id, absint( $_POST['user_' . $points_type . '_points'] ), get_current_user_id(), null, $points_type );
        }
    }

}
add_action( 'personal_options_update', 'gamipress_save_user_profile_fields' );
add_action( 'edit_user_profile_update', 'gamipress_save_user_profile_fields' );

/**
 * Generate markup to show user rank
 *
 * @since  1.0.0
 *
 * @param  object $user         The current user's $user object
 *
 * @return string               concatenated markup
 */
function gamipress_profile_user_rank( $user = null ) {

	$rank_types = gamipress_get_rank_types(); ?>

	<h2><?php _e( 'Ranks', 'gamipress' ); ?></h2>

	<table class="form-table">

		<?php if( empty( $rank_types ) ) : ?>
			<tr>
				<th><label for="user_rank"><?php _e( 'User Ranks', 'gamipress' ); ?></label></th>
				<td>
					<span class="description">
						<?php echo sprintf( __( 'No rank types configured, visit %s to configure some rank types.', 'gamipress' ), '<a href="' . admin_url( 'edit.php?post_type=rank-type' ) . '">' . __( 'this page', 'gamipress' ) . '</a>' ); ?>
					</span>
				</td>
			</tr>
		<?php else : ?>

			<?php foreach( $rank_types as $rank_type => $data ) :

				// Get all published ranks of this type
				$ranks = gamipress_get_ranks( array( 'post_type' => $rank_type ) );

				$user_rank_id = gamipress_get_user_rank_id( $user->ID, $rank_type ); ?>

				<tr>
					<th><label for="user_<?php echo $rank_type; ?>_rank"><?php echo sprintf( __( 'User %s', 'gamipress' ), $data['singular_name'] ); ?></label></th>
					<td>

						<?php if( empty( $ranks ) ) : ?>

							<span class="description">
								<?php echo sprintf( __( 'No %1$s configured, visit %2$s to configure some %1$s.', 'gamipress' ),
									strtolower( $data['plural_name'] ),
									'<a href="' . admin_url( 'edit.php?post_type=' . $rank_type ) . '">' . __( 'this page', 'gamipress' ) . '</a>'
								); ?>
							</span>

						<?php else : ?>

							<select name="user_<?php echo $rank_type; ?>_rank" id="user_<?php echo $rank_type; ?>_rank" style="min-width: 15em;">
								<?php foreach( $ranks as $rank ) : ?>
									<option value="<?php echo $rank->ID; ?>" <?php selected( $user_rank_id, $rank->ID ); ?>><?php echo $rank->post_title; ?></option>
								<?php endforeach; ?>
							</select>
							<span class="description"><?php echo sprintf( __( "The user's %s rank. %s listed are ordered by priority.", 'gamipress' ), strtolower( $data['singular_name'] ), $data['plural_name'] ); ?></span>

						<?php endif; ?>

					</td>
				</tr>

			<?php endforeach; ?>

		<?php endif; ?>

	</table>
	<?php
}

/**
 * Generate markup to list user earned points
 *
 * @since  1.0.0
 *
 * @param  object $user         The current user's $user object
 *
 * @return string               concatenated markup
 */
function gamipress_profile_user_points( $user = null ) {

    $points_types = gamipress_get_points_types(); ?>

    <h2><?php _e( 'Points Balance', 'gamipress' ); ?></h2>

    <table class="form-table">

		<?php if( empty( $points_types ) ) : ?>

			<tr>
				<th><label for="user_points"><?php _e( 'User Points', 'gamipress' ); ?></label></th>
				<td>
					<span class="description">
						<?php echo sprintf( __( 'No points types configured, visit %s to configure some points types.', 'gamipress' ), '<a href="' . admin_url( 'edit.php?post_type=points-type' ) . '">' . __( 'this page', 'gamipress' ) . '</a>' ); ?>
					</span>
				</td>
			</tr>

		<?php else : ?>

			<tr>
				<th><label for="user_points"><?php _e( 'Earned Default Points', 'gamipress' ); ?></label></th>
				<td>
					<input type="text" name="user_points" id="user_points" value="<?php echo gamipress_get_user_points( $user->ID ); ?>" class="regular-text" /><br />
					<span class="description"><?php _e( "The user's points total. Entering a new total will automatically log the change and difference between totals.", 'gamipress' ); ?></span>
				</td>
			</tr>

			<?php foreach( $points_types as $points_type => $data ) : ?>

				<tr>
					<th><label for="user_<?php echo $points_type; ?>_points"><?php echo sprintf( __( 'Earned %s', 'gamipress' ), $data['plural_name'] ); ?></label></th>
					<td>
						<input type="text" name="user_<?php echo $points_type; ?>_points" id="user_<?php echo $points_type; ?>_points" value="<?php echo gamipress_get_user_points( $user->ID, $points_type ); ?>" class="regular-text" /><br />
						<span class="description"><?php echo sprintf( __( "The user's %s total. Entering a new total will automatically log the change and difference between totals.", 'gamipress' ), strtolower( $data['plural_name'] ) ); ?></span>
					</td>
				</tr>

			<?php endforeach; ?>

		<?php endif; ?>

    </table>
	<?php
}

/**
 * Generate markup to list user earned achievements
 *
 * @since  1.0.0
 *
 * @param  object $user         The current user's $user object
 *
 * @return string               concatenated markup
 */
function gamipress_profile_user_achievements( $user = null ) {
	?>

    <h2><?php _e( 'Earned Achievements', 'gamipress' ); ?></h2>

	<?php ct_render_ajax_list_table( 'gamipress_user_earnings',
		array(
			'user_id' => absint( $user->ID )
		),
		array(
			'views' => false,
			'search_box' => false
		)
	);
}

/**
 * Generate markup for awarding an achievement to a user
 *
 * @since  1.0.0
 *
 * @param  object $user         The current user's $user object
 *
 * @return string               concatenated markup
 */
function gamipress_profile_award_achievement( $user = null ) {

	$achievements = gamipress_get_user_achievements( array( 'user_id' => absint( $user->ID ) ) );

    $achievement_ids = array_map( function( $achievement ) {
        return $achievement->ID;
    }, $achievements );

	// Grab our achievement types
	$achievement_types = gamipress_get_achievement_types();
	$requirement_types = gamipress_get_requirement_types();

	$achievement_types = array_merge( $achievement_types, $requirement_types )
	?>

	<h2><?php _e( 'Award an Achievement', 'gamipress' ); ?></h2>

	<table class="form-table">

		<tr>
			<th><label for="thechoices"><?php _e( 'Select an Achievement Type to Award:', 'gamipress' ); ?></label></th>
			<td>
				<select id="thechoices">
				<option>Choose an achievement type</option>
				<?php foreach ( $achievement_types as $achievement_slug => $achievement_type ) :
					echo '<option value="'. $achievement_slug .'">' . ucwords( $achievement_type['singular_name'] ) .'</option>';
				endforeach; ?>
				</select>
			</td>
		</tr>

	</table>

	<div id="boxes">
		<?php foreach ( $achievement_types as $achievement_slug => $achievement_type ) : ?>
			<table id="<?php echo esc_attr( $achievement_slug ); ?>" class="wp-list-table widefat fixed striped gamipress-table">

				<thead>
					<tr>
						<th width="60px"><?php _e( 'Image', 'gamipress' ); ?></th>
						<th><?php echo ucwords( $achievement_type['singular_name'] ); ?></th>
						<th><?php _e( 'Actions', 'gamipress' ); ?></th>
					</tr>
				</thead>

				<tbody>
				<?php
				// Load achievement type entries
				$the_query = new WP_Query( array(
					'post_type'      => $achievement_slug,
					'posts_per_page' => '999',
					'post_status'    => 'publish'
				) );

				if ( $the_query->have_posts() ) : ?>

					<?php while ( $the_query->have_posts() ) : $the_query->the_post();

						// if not parent object, skip
						if( $achievement_slug === 'step' && ! $parent_achievement = gamipress_get_parent_of_achievement( get_the_ID() ) ) {
							continue;
						} else if( $achievement_slug === 'points-award' && ! $points_type = gamipress_get_points_award_points_type( get_the_ID() ) ) {
							continue;
						}

						// Setup our award URL
						$award_url = add_query_arg( array(
							'action'         => 'award',
							'achievement_id' => absint( get_the_ID() ),
							'user_id'        => absint( $user->ID )
						) );
						?>
						<tr>
							<td><?php the_post_thumbnail( array( 50, 50 ) ); ?></td>
							<td>
								<?php if( $achievement_slug === 'step' || $achievement_slug === 'points-award' ) : ?>
									<strong><?php echo get_the_title( get_the_ID() ); ?></strong>
									<?php // Output parent achievement
									if( $achievement_slug === 'step' && $parent_achievement ) : ?>
										<?php echo ( isset( $achievement_types[$parent_achievement->post_type] ) ? '<br> ' . $achievement_types[$parent_achievement->post_type]['singular_name'] . ': ' : '' ); ?>
										<?php echo '<a href="' . get_edit_post_link( $parent_achievement->ID ) . '">' . get_the_title( $parent_achievement->ID ) . '</a>'; ?>
									<?php elseif( $points_type ) : ?>
										<br>
										<?php echo '<a href="' . get_edit_post_link( $points_type->ID ) . '">' . get_the_title( $points_type->ID ) . '</a>'; ?>
									<?php endif; ?>
								<?php else : ?>
									<strong><?php echo '<a href="' . get_edit_post_link( get_the_ID() ) . '">' . get_the_title( get_the_ID() ) . '</a>'; ?></strong>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( $award_url, 'gamipress_award_achievement' ) ); ?>"><?php printf( __( 'Award %s', 'gamipress' ), ucwords( $achievement_type['singular_name'] ) ); ?></a>
								<?php if ( in_array( get_the_ID(), (array) $achievement_ids ) ) :
									// Setup our revoke URL
									$revoke_url = add_query_arg( array(
										'action'         => 'revoke',
										'user_id'        => absint( $user->ID ),
										'achievement_id' => absint( get_the_ID() ),
									) );
									?>
									| <span class="delete"><a class="error" href="<?php echo esc_url( wp_nonce_url( $revoke_url, 'gamipress_revoke_achievement' ) ); ?>"><?php _e( 'Revoke Award', 'gamipress' ); ?></a></span>
								<?php endif; ?>

							</td>
						</tr>
					<?php endwhile; ?>

				<?php else : ?>
					<tr>
						<td colspan="3"><?php printf( __( 'No %s found.', 'gamipress' ), $achievement_type['plural_name'] ); ?></td>
					</tr>
				<?php endif; wp_reset_postdata(); ?>

				</tbody>

			</table><!-- #<?php echo esc_attr( $achievement_slug ); ?> -->
		<?php endforeach; ?>
	</div><!-- #boxes -->

	<script type="text/javascript">
		(function($){
			<?php foreach ( $achievement_types as $achievement_slug => $achievement_type ) { ?>
				$('#<?php echo $achievement_slug; ?>').hide();
			<?php } ?>
			$("#thechoices").change(function(){
				if ( 'all' == this.value )
					$("#boxes").children().show();
				else
					$("#" + this.value).show().siblings().hide();
			}).change();
		})(jQuery);
	</script>
	<?php
}

/**
 * Process the adding/revoking of achievements on the user profile page
 *
 * @since  1.0.0
 */
function gamipress_process_user_data() {

	// verify user meets minimum role to view earned achievements
	if ( current_user_can( gamipress_get_manager_capability() ) ) {

		// Process awarding achievement to user
		if ( isset( $_GET['action'] ) && 'award' == $_GET['action'] &&  isset( $_GET['user_id'] ) && isset( $_GET['achievement_id'] ) ) {

			// Verify our nonce
			check_admin_referer( 'gamipress_award_achievement' );

			// Award the achievement
			gamipress_award_achievement_to_user( absint( $_GET['achievement_id'] ), absint( $_GET['user_id'] ), get_current_user_id() );

			// Redirect back to the user editor
			wp_redirect( add_query_arg( 'user_id', absint( $_GET['user_id'] ), admin_url( 'user-edit.php' ) ) );
			exit();
		}

		// Process revoking achievement from a user
		if ( isset( $_GET['action'] ) && 'revoke' == $_GET['action'] && isset( $_GET['user_id'] ) && isset( $_GET['achievement_id'] ) ) {

			// Verify our nonce
			check_admin_referer( 'gamipress_revoke_achievement' );

			$earning_id = isset( $_GET['user_earning_id'] ) ? absint( $_GET['user_earning_id'] ) : 0 ;

			// Revoke the achievement
			gamipress_revoke_achievement_from_user( absint( $_GET['achievement_id'] ), absint( $_GET['user_id'] ), $earning_id );

			// Redirect back to the user editor
			wp_redirect( add_query_arg( 'user_id', absint( $_GET['user_id'] ), admin_url( 'user-edit.php' ) ) );
			exit();

		}

	}

}
add_action( 'init', 'gamipress_process_user_data' );

/**
 * Returns array of achievement types a user has earned across a multisite network
 *
 * @since  1.0.0
 * @param  integer $user_id  The user's ID
 * @return array             An array of post types
 */
function gamipress_get_network_achievement_types_for_user( $user_id ) {
	global $blog_id;

	// Store a copy of the original ID for later
	$cached_id = $blog_id;

	// Assume we have no achievement types
	$all_achievement_types = array();

	// Loop through all active sites
	$sites = gamipress_get_network_site_ids();
	foreach( $sites as $site_blog_id ) {

		// If we're polling a different blog, switch to it
		if ( $blog_id != $site_blog_id ) {
			switch_to_blog( $site_blog_id );
		}

		// Merge earned achievements to our achievement type array
		$achievement_types = gamipress_get_user_earned_achievement_types( $user_id );

		if ( is_array( $achievement_types ) ) {
			$all_achievement_types = array_merge( $achievement_types, $all_achievement_types );
		}
	}

	if ( is_multisite() ) {
		// Restore the original blog so the sky doesn't fall
		switch_to_blog( $cached_id );
	}

	// Pare down achievement type list so we return no duplicates
	$achievement_types = array_unique( $all_achievement_types );

	// Return all found achievements
	return $achievement_types;
}
