<?php
/**
 * Registers the ratesight_page custom post type.
 *
 * Pages created via the webhook use this CPT instead of WordPress's native
 * 'page' type. This means if the plugin is removed, all webhook-created pages
 * return 404 automatically — no manual cleanup needed. Regular 'post' type
 * posts are completely unaffected.
 *
 * The CPT is intentionally hidden from the front-end admin menu to keep the
 * client WP admin clean. Admins can still access them via Ratesight → Activity
 * Log post links or directly at /wp-admin/edit.php?post_type=ratesight_page.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Post_Type {

	public function register() {
		register_post_type( 'ratesight_page', array(
			'labels' => array(
				'name'               => __( 'RS Pages',          'ratesight' ),
				'singular_name'      => __( 'RS Page',           'ratesight' ),
				'add_new'            => __( 'Add New',           'ratesight' ),
				'add_new_item'       => __( 'Add New RS Page',   'ratesight' ),
				'edit_item'          => __( 'Edit RS Page',      'ratesight' ),
				'view_item'          => __( 'View RS Page',      'ratesight' ),
				'all_items'          => __( 'All RS Pages',      'ratesight' ),
				'search_items'       => __( 'Search RS Pages',   'ratesight' ),
				'not_found'          => __( 'No RS pages found', 'ratesight' ),
				'not_found_in_trash' => __( 'No RS pages in trash', 'ratesight' ),
			),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => false,  // Hidden from main menu — access via RS admin
			'show_in_nav_menus'   => true,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
			'hierarchical'        => true,   // Supports parent/child like pages
			'has_archive'         => false,
			'rewrite'             => array(
				'slug'       => '',          // Empty slug = pages appear at root like native pages
				'with_front' => false,
			),
			'capability_type'     => 'page', // Uses same caps as pages so existing roles work
			'map_meta_cap'        => true,
		) );
	}

	/**
	 * Flush rewrite rules after registration if needed.
	 * Called on plugin activation.
	 */
	public static function flush_rewrite_rules() {
		( new self() )->register();
		flush_rewrite_rules();
	}
}
