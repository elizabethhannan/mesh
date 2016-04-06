<?php
/**
 * Handle everything related to Admin Pointers
 *
 * @package    Mesh
 * @subpackage Admin_Pointers
 */

/**
 * Class Mesh Pointers
 */
class Mesh_Admin_Pointers {

	/**
	 * Mesh_Admin_Pointers constructor.
	 */
	function __construct() {
		add_action( 'admin_enqueue_scripts',     array( $this, 'admin_enqueue_scripts' ) );

		// @todo this filter needs to support multiple post types
		add_filter( 'mesh_admin_pointers-page', array( $this, 'register_pointers' ) );

		// Output our pointer data.
		add_filter( 'mcs_data',               array( $this, 'add_pointers' ), 1000, 1 );
	}

	/**
	 * Add our pointer styles
	 */
	function admin_enqueue_scripts() {
		// Add pointers style to queue.
		wp_enqueue_style( 'wp-pointer' );
	}

	/**
	 * Add pointer data to mcs_data->strings
	 *
	 * @param array $strings Array of our l10n strings
	 *
	 * @return array
	 */
	function add_pointers( $localized_data = array() ) {

	    // Don't run on WP < 3.3.
	    if ( get_bloginfo( 'version' ) < '3.3' ) {
		    return $localized_data;
	    }

	    $screen = get_current_screen();
	    $screen_id = $screen->id;

	    // Get pointers for this screen.
	    $pointers = apply_filters( 'mesh_admin_pointers-' . $screen_id, array() );

	    if ( ! $pointers || ! is_array( $pointers ) ) {
		    return $localized_data;
	    }

	    // Get dismissed pointers.
	    $dismissed      = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );
	    $valid_pointers = array();

	    // Check pointers and remove dismissed ones.
	    foreach ( $pointers as $pointer_id => $pointer ) {

	        // Sanity check.
	        if ( in_array( $pointer_id, $dismissed, true ) || empty( $pointer ) || empty( $pointer_id ) || empty( $pointer['target'] ) || empty( $pointer['options'] ) ) {
		        continue;
	        }

	        $pointer['pointer_id'] = $pointer_id;

	        // Add the pointer to $valid_pointers array.
	        $valid_pointers['pointers'][] = $pointer;
	    }

	    // No valid pointers? Stop here.
	    if ( empty( $valid_pointers ) ) {
		    return $localized_data;
	    }

		$localized_data['wp_pointers'] = $valid_pointers;

		return $localized_data;
	}

	/**
	 * Register our WP Pointers for display
	 * @param $p
	 *
	 * @return mixed
	 */
	function register_pointers( $p ) {
	    $p['all_section_options'] = array(
	        'target' => '.mcs-more-section-options',
	        'options' => array(
	            'content' => sprintf( '<h3> %s </h3> <p> %s </p>',
	                __( 'Section Options' ,'linchpin-mcs' ),
	                __( 'View all section options by click the "More Options" toggle.','linchpin-mcs' )
	            ),
	            'position' => array( 'edge' => 'bottom', 'align' => 'left' ),
	        ),
	    );

	    $p['offset'] = array(
	        'target' => '.mcs-column-offset:first',
	        'options' => array(
	            'content' => sprintf( '<h3> %s </h3> <p> %s </p>',
	                __( 'What is an offset?' ,'linchpin-mcs' ),
	                __( 'If using Foundation, an offset will indent your column by the amount of columns selected in the dropdown menu.', 'linchpin-mcs' )
	            ),
	            'position' => array( 'edge' => 'bottom', 'align' => 'left' ),
	        ),
	    );

	    $p['column_slider'] = array(
		    'target' => '.mcs-editor-blocks .the-mover:first',
		    'options' => array(
			    'content' => sprintf( '<h3> %s </h3> <p> %s </p>',
				    __( 'Rearrange Columns', 'linchpin-mcs' ),
				    __( 'Use this handle to click and drag this column, giving you the ability to swap columns on the fly.', 'linchpin-mcs' )
			    ),
			    'position' => array(
				    'edge' => 'bottom',
				    'align' => 'left',
			    ),
		    ),
	    );

	    return $p;
	}
}