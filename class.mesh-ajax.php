<?php
/**
 * Class to handle all ajax related class within the admin
 *
 * @since      1.0.0
 * @package    Mesh
 * @subpackage AJAX
 */

/**
 * Mesh_AJAX class.
 */
class Mesh_AJAX {

	/**
	 * __construct function.
	 *
	 * @access public
	 */
	function __construct() {
		add_action( 'wp_ajax_mesh_list_templates',        array( $this, 'mesh_list_templates' ) );
		add_action( 'wp_ajax_mesh_choose_template',       array( $this, 'mesh_choose_template' ) );

		add_action( 'wp_ajax_mesh_add_section',           array( $this, 'mesh_add_section' ) );
		add_action( 'wp_ajax_mesh_save_section',          array( $this, 'mesh_save_section' ) );
		add_action( 'wp_ajax_mesh_remove_section',        array( $this, 'mesh_remove_section' ) );

		add_action( 'wp_ajax_mesh_choose_layout',         array( $this, 'mesh_choose_layout' ) );
		add_action( 'wp_ajax_mesh_update_order',          array( $this, 'mesh_update_order' ) );
		add_action( 'wp_ajax_mesh_update_featured_image', array( $this, 'mesh_update_featured_image' ) );
		add_action( 'wp_ajax_mesh_dismiss_notification',  array( $this, 'mesh_dismiss_notification' ) );

		include_once LINCHPIN_MESH___PLUGIN_DIR . '/class.mesh-templates-duplicate.php';
	}

	/**
	 * Return the markup for a new section.
	 *
	 * @access public
	 * @return void
	 */
	function mesh_add_section() {
		check_ajax_referer( 'mesh_add_section_nonce', 'mesh_add_section_nonce' );

		$post_id = (int) $_POST['mesh_post_id']; // WPCS: sanitization ok
		$menu_order = (int) $_POST['mesh_section_count']; // WPCS: sanitization ok

		if ( empty( $post_id ) ) {
			wp_die( -1 );
		}

		$args = array(
			'post_type'   => 'mesh_section',
			'post_title'  => __( 'No Section Title', 'mesh' ),
			'post_status' => 'draft',
			'post_parent' => $post_id,
			'menu_order'  => $menu_order,
		);

		if ( $new_section = wp_insert_post( $args ) ) {
			$section = get_post( $new_section );

			// Make sure the new section has one block (default number needed).
			mesh_maybe_create_section_blocks( $section, 1 );

			mesh_add_section_admin_markup( $section );
			wp_die();
		} else {
			wp_die( -1 );
		}

		wp_die();
	}

	/**
	 * Save a block via AJAX
	 */
	function mesh_save_section() {
		check_ajax_referer( 'mesh_save_section_nonce', 'mesh_save_section_nonce' );

		$section_id = (int) $_POST['mesh_section_id'];

		if ( ! $section = get_post( $section_id ) ) {
			wp_die( -1 );
		}

		parse_str( $_POST['mesh_section_data'], $passed_args );

		// Only need certain arguments to be passed on.
		$new_data = array(
			'action' => $passed_args['action'],
			'mesh_action' => 'mesh_save_section',
			'mesh_content_sections_nonce' => $passed_args['mesh_content_sections_nonce'],
			'mesh-sections' => array(
				$section->ID => $passed_args['mesh-sections'][ $section->ID ],
			),
		);

		$_POST = array_merge( $_POST, $new_data );

		$section_args = array(
			'ID' => $section->ID,
			'post_title' => $_POST['mesh-sections'][ $section->ID ]['post_title'],
		);

		wp_update_post( $section_args );
	}

	/**
	 * Select a section. Return the template using AJAX
	 *
	 * @since 0.2.0
	 */
	function mesh_choose_layout() {
		check_ajax_referer( 'mesh_choose_layout_nonce', 'mesh_choose_layout_nonce' );

		if ( ! $selected_template = sanitize_text_field( $_POST['mesh_section_layout'] ) ) {
			$selected_template = 'mesh-columns-1.php';
		}

		$section_id = (int) $_POST['mesh_section_id'];

		if ( empty( $section_id ) || ! current_user_can( 'edit_post', $section_id ) ) {
			wp_die();
		}

		$section = get_post( $section_id );

		if ( empty( $section ) ) {
			wp_die();
		}

		update_post_meta( $section_id, '_mesh_template', $selected_template );

		$block_template = mesh_locate_template_files();

		$templates = apply_filters( 'mesh_section_data', $block_template );

		// Make sure that a section has enough blocks to fill the template.
		$blocks = mesh_maybe_create_section_blocks( $section, $templates[ $selected_template ]['blocks'] );

		// Reset our widths on layout change.
		foreach ( $blocks as $block ) {
			delete_post_meta( $block->ID, '_mesh_column_width' );
		}
		ob_start();
		include( LINCHPIN_MESH___PLUGIN_DIR . '/admin/section-blocks.php' );
		include( LINCHPIN_MESH___PLUGIN_DIR . '/admin/section-template-warnings.php' );
		$output = ob_get_contents();

		ob_end_clean();

		// Clean whitespace before output to prevent jQuery ajax warnings.
		echo trim( $output ); // WPCS: XSS ok, sanitization ok.

		wp_die();
	}

	/**
	 * Remove the selected section from
	 *
	 * @since 1.0
	 */
	function mesh_remove_section() {
		check_ajax_referer( 'mesh_remove_section_nonce', 'mesh_remove_section_nonce' );

		$post_id    = (int) $_POST['mesh_post_id']; // WPCS: XSS ok, sanitization ok.
		$section_id = (int) $_POST['mesh_section_id']; // WPCS: XSS ok, sanitization ok.

		if ( empty( $post_id ) || empty( $section_id ) ) {
			wp_die( -1 );
		}

		if ( ! $section = get_post( $section_id ) ) {
			wp_die( -1 );
		}

		if ( $post_id !== $section->post_parent ) {
			wp_die( -1 );
		}

		if ( wp_trash_post( $section_id ) ) {
			// Trash the section's blocks.
			foreach ( mesh_get_section_blocks( $section_id ) as $block ) {
				if ( $section_id === $block->post_parent ) {
					wp_trash_post( $block->ID );
				}
			}

			wp_die( 1 );
		} else {
			wp_die( -1 );
		}
	}

	/**
	 * Save the order of sections after drag and drop reordering
	 *
	 * @since 1.0
	 */
	function mesh_update_order() {
		check_ajax_referer( 'mesh_reorder_section_nonce', 'mesh_reorder_section_nonce' );

		$post_id     = (int) $_POST['mesh_post_id']; // WPCS: XSS ok, sanitization ok
		$section_ids = array_values( array_map( 'intval', $_POST['mesh_section_ids'] ) ); // WPCS: XSS ok, sanitization ok

		if ( empty( $post_id ) || empty( $section_ids ) ) {
			wp_die( -1 );
		}

		foreach ( $section_ids as $key => $section_id ) {
			$section = get_post( $section_id );

			if ( empty( $section ) ) {
				continue;
			}

			if ( $section->post_parent !== $post_id ) {
				continue;
			}

			$post_args = array(
				'ID' => $section_id,
				'menu_order' => $key,
			);

			wp_update_post( $post_args );
		}

		wp_die();
	}

	/**
	 * Update the sections featured image.
	 *
	 * @since 1.0
	 * @access public
	 * @return void
	 */
	function mesh_update_featured_image() {
		check_ajax_referer( 'mesh_featured_image_nonce', 'mesh_featured_image_nonce' );

		$post_id  = (int) $_POST['mesh_section_id']; // WPCS: input var okay.
		$image_id = (int) $_POST['mesh_image_id']; // WPCS: input var okay.

		if ( 'mesh_section' !== get_post_type( $post_id ) ) {
			wp_die( -1 );
		}

		if ( empty( $image_id ) ) {
			delete_post_meta( $post_id, '_thumbnail_id' );

			die( 1 );
		}

		if ( 'attachment' !== get_post_type( $image_id ) ) {
			wp_die( -1 );
		}

		update_post_meta( $post_id, '_thumbnail_id', $image_id );

		wp_die( 1 );
	}

	/**
	 * Add the ability to store when notifications are dismissed
	 *
	 * @since 1.0
	 */
	function mesh_dismiss_notification() {
		check_ajax_referer( 'mesh_dismiss_notification_nonce', 'mesh_dismiss_notification_nonce' );

		$user_id = get_current_user_id();

		$notification_type = sanitize_title( wp_unslash( $_POST['mesh_notification_type'] ) );  // WPCS: input var okay.

		$notifications = maybe_unserialize( get_user_option( 'linchpin_mesh_notifications', $user_id ) );

		if ( empty( $notifications ) ) {
			$notifications = array();
		}

		$notifications[ $notification_type ] = '1';

		if ( current_user_can( 'edit_posts' ) ) {
			update_user_meta( $user_id, 'linchpin_mesh_notifications', $notifications );
			wp_die( 1 );
		}
	}

	/**
	 * Select a template from the mesh_template post type
	 * This template will be used to create all the mesh_sections
	 * on your selected post.
	 *
	 * @since 1.1
	 */
	function mesh_list_templates() {

		check_ajax_referer( 'mesh_choose_template_nonce', 'mesh_choose_template_nonce' );

		if ( ! current_user_can( 'edit_post', (int) $_POST['mesh_post_id'] ) ) {
			wp_die();
		}

		$mesh_templates = new WP_Query( array(
			'post_type'      => 'mesh_template',
			'posts_per_page' => apply_filters( 'mesh_templates_per_page', 50 ),
			'no_found_rows'  => true,
			'post_status'    => 'publish',
		) );

		$mesh_template_selectable = true;
		$default_template = false;
		if ( $mesh_templates->have_posts() ) {
			while ( $mesh_templates->have_posts() ) {
				global $post;

				$mesh_templates->the_post();

				$mesh_template_title = get_the_title( $post->ID );
				$mesh_template_id = $post->ID;

				$layout = get_post_meta( $post->ID, '_mesh_template_layout', true );

				// If our layout doesn't have any published sections we should skip it's display.
				if ( empty( $layout ) ) {
					continue;
				}

				include LINCHPIN_MESH___PLUGIN_DIR . 'admin/template-layout-preview.php';
			}

			$mesh_template_title = __( 'Blank Template', 'mesh' );
			$mesh_template_id    = 'blank';
			$layout              = array();
			$layout['row-blank']['blocks'][] = array(
				'columns' => 12,
				'offset' => 0,
			);
			$default_template = true;
			include LINCHPIN_MESH___PLUGIN_DIR . 'admin/template-layout-preview.php';
		} else {
			esc_html_e( 'No Templates Found. Did you build one yet?', 'mesh' );
		} ?>
		<p>
			<a href="#" class="button primary mesh-template-start dashicons-before dashicons-plus"><?php esc_html_e( 'Select Template', 'mesh' ); ?></a>
			<a href="#" class="button primary mesh-template-skip dashicons-before dashicons-plus"><?php esc_html_e( 'Nevermind Start from Scratch', 'mesh' ); ?></a>
		</p>
		<?php

		include LINCHPIN_MESH___PLUGIN_DIR . 'admin/template-layout-usage.php';

		wp_die();
	}

	/**
	 * Choose a template and build out all mesh_section posts
	 * based on the selected template.
	 *
	 * @todo There should probably be a busy spinner of sorts while
	 *       the build out is being done behind the scenes.
	 *
	 * @since 1.1
	 */
	function mesh_choose_template() {
		check_ajax_referer( 'mesh_choose_template_nonce', 'mesh_choose_template_nonce' );

		$post_id            = ( isset( $_POST['mesh_post_id'] ) && '' !== $_POST['mesh_post_id'] ) ? (int) $_POST['mesh_post_id'] : 0;
		$mesh_template_id   = ( isset( $_POST['mesh_template_id'] ) ) ? (int) $_POST['mesh_template_id'] : 0;
		$mesh_template_type = ( isset( $_POST['mesh_template_type'] ) ) ? sanitize_title( $_POST['mesh_template_type'] ) : '';

		if ( ! current_user_can( 'edit_post', $mesh_template_id ) || empty( $post_id ) || empty( $mesh_template_id ) ) {
			wp_die( -1 );
		}

		if ( $mesh_template = get_post( $mesh_template_id ) ) {
			// Apply template type to our taxonomy that tracks template usage.
			wp_set_object_terms( $post_id, $mesh_template->post_name, 'mesh_template_usage', false );

			$terms = get_terms( array(
				'taxonomy' => 'mesh_template_types',
				'hide_empty' => false,
				'fields' => array( 'slug' ),
			) );

			if ( in_array( $mesh_template_type ) ) {
				wp_set_object_terms( $post_id, $mesh_template_type, 'mesh_template_types', false );
			}

			$mesh_templates_duplicate = new Mesh_Templates_Duplicate();

			$duplicate_sections = $mesh_templates_duplicate->duplicate_sections( $mesh_template_id, $post_id );

			if ( ! empty( $duplicate_sections ) ) {
				echo wp_kses( $duplicate_sections, array(
					'div' => array(
						'class' => array(),
						'id' => array(),
						'data-type' => array(),
						'style' => array(),
					),
					'a' => array(
						'href' => array(),
						'title' => array(),
						'class' => array(),
					),
					'input' => array(
						'type' => array(),
						'name' => array(),
						'id' => array(),
						'class' => array(),
						'value' => array(),
					),
					'label' => array(
						'for' => array(),
						'class' => array(),
					),
					'select' => array(
						'name' => array(),
						'id' => array(),
						'class' => array(),
						'value' => array(),
					),
					'option' => array(
						'value' => array(),
						'selected' => array(),
					),
					'span' => array(
						'class' => array(),
						'style' => array(),
					),
					'ul' => array(
						'class' => array(),
					),
					'li' => array(
						'class' => array(),
					),
					'p' => array(),
					'br' => array(),
				) );
			} else {
				echo 'did not duplicate sections';
			}
			exit;
		}

		exit;
	}
}

$mesh_ajax = new Mesh_AJAX();
