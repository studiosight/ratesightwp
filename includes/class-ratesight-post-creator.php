<?php
/**
 * Assembles and inserts a WordPress post/page from processed webhook data.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Post_Creator {

	/**
	 * @param array{
	 *   title: string,
	 *   slug: string,
	 *   summary: string,
	 *   article: string,
	 *   post_type: string,
	 *   post_parent: int,
	 *   category_id: int,
	 * } $args
	 * @return int|\WP_Error  Post ID on success.
	 */
	public function create( array $args ): int|WP_Error {
		$args = wp_parse_args( $args, array(
			'title'       => '',
			'slug'        => '',
			'summary'     => '',
			'article'     => '',
			'post_type'   => 'post',
			'post_parent' => 0,
			'category_id' => 0,
		) );

		if ( empty( $args['title'] ) ) {
			return new \WP_Error( 'rs_no_title', 'Cannot create a post without a title.' );
		}

		$post_type = in_array( $args['post_type'], array( 'post', 'page', 'ratesight_page' ), true )
			? $args['post_type'] : 'post';

		// Ensure the CPT is registered before inserting — some hosts with object
		// caching or unusual plugin load orders can reach this point before init.
		if ( $post_type === 'ratesight_page' && ! post_type_exists( 'ratesight_page' ) ) {
			Ratesight_CPT::do_register();
		}

		// Always create as draft. Ratesight_Publisher flips to the intended
		// final status after attaching the featured image.
		$post_author = (int) Ratesight_Options::get( 'post_author' );
		if ( ! get_user_by( 'id', $post_author ) ) {
			$post_author = 1;
		}

		$final_status = Ratesight_Options::get( 'post_status' );
		if ( ! in_array( $final_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$final_status = 'publish';
		}

		$slug = $this->unique_slug(
			! empty( $args['slug'] ) ? $args['slug'] : sanitize_title( $args['title'] ),
			$final_status,
			$post_type
		);

		$post_data = array(
			'post_title'   => $args['title'],
			'post_name'    => $slug,
			'post_excerpt' => $args['summary'],
			'post_content' => $args['article'],
			'post_status'  => 'draft',   // always draft — publisher promotes it
			'post_author'  => $post_author,
			'post_type'    => $post_type,
		);

		// Parent page (pages only).
		if ( in_array( $post_type, array( 'page', 'ratesight_page' ), true ) && ! empty( $args['post_parent'] ) ) {
			$post_data['post_parent'] = (int) $args['post_parent'];
		}

		// Category (posts only).
		if ( $post_type === 'post' && ! empty( $args['category_id'] ) ) {
			$post_data['post_category'] = array( (int) $args['category_id'] );
		}

		// wp_slash() prevents wp_insert_post's internal wp_unslash() from
		// stripping backslashes that are legitimately in the content.
		$post_id = wp_insert_post( wp_slash( $post_data ), true );

		if ( ! is_wp_error( $post_id ) ) {
			// Mark as Ratesight-created regardless of final post type.
			update_post_meta( (int) $post_id, '_rs_created', 1 );
		}

		return is_wp_error( $post_id ) ? $post_id : (int) $post_id;
	}

	private function unique_slug( string $slug, string $post_status, string $post_type ) {
		return wp_unique_post_slug( $slug, 0, $post_status, $post_type, 0 );
	}
}
