<?php
/**
 * Resolves category names to term IDs, creating them if they don't exist.
 *
 * Priority order for parent category:
 *   1. parent_category field in the webhook payload (name-based, auto-created)
 *   2. Parent category ID configured in plugin settings
 *   3. No parent (WordPress Uncategorized)
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_Category_Handler {

	/**
	 * @param string $child_name        Category name from the webhook payload.
	 * @param int    $settings_parent_id Parent category ID from plugin settings.
	 * @param string $parent_name        Parent category name from webhook payload (optional).
	 * @return int|\WP_Error             Term ID on success.
	 */
	public function resolve( string $child_name, int $settings_parent_id, string $parent_name = '' ): int|WP_Error {

		// ── Resolve parent ────────────────────────────────────────────────────
		$parent_id = 0;

		if ( $parent_name !== '' ) {
			// Parent name supplied in payload — find or create it.
			$parent_id = $this->find_or_create( $parent_name, 0 );
			if ( is_wp_error( $parent_id ) ) {
				return $parent_id;
			}
		} elseif ( $settings_parent_id > 0 ) {
			// Use plugin settings parent — but create it if it no longer exists.
			$parent = get_term( $settings_parent_id, 'category' );
			if ( ! is_wp_error( $parent ) && ! empty( $parent ) ) {
				$parent_id = $settings_parent_id;
			}
			// If it doesn't exist we just proceed without a parent rather than erroring.
		}

		// ── No child name — assign to parent (or Uncategorized) ──────────────
		if ( $child_name === '' ) {
			return $parent_id > 0 ? $parent_id : 1;
		}

		// ── Find or create child ──────────────────────────────────────────────
		return $this->find_or_create( $child_name, $parent_id );
	}

	// -------------------------------------------------------------------------

	private function find_or_create( string $name, int $parent_id ): int|WP_Error {
		// Check if it already exists.
		$existing = $this->find( $name, $parent_id );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		// Create it.
		$args = array( 'slug' => sanitize_title( $name ) );
		if ( $parent_id > 0 ) {
			$args['parent'] = $parent_id;
		}

		$result = wp_insert_term( $name, 'category', $args );

		if ( is_wp_error( $result ) ) {
			// Handle race condition — term created between check and insert.
			if ( 'term_exists' === $result->get_error_code() ) {
				$data = $result->get_error_data();
				return (int) ( is_array( $data ) ? $data['term_id'] : $data );
			}
			return new \WP_Error(
				'rs_category_create_failed',
				'Failed to create category "' . esc_html( $name ) . '": ' . $result->get_error_message()
			);
		}

		return (int) $result['term_id'];
	}

	private function find( string $name, int $parent_id ): ?\WP_Term {
		$args = array( 'taxonomy' => 'category', 'name' => $name, 'hide_empty' => false, 'number' => 1 );
		if ( $parent_id > 0 ) {
			$args['parent'] = $parent_id;
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}
		return $terms[0];
	}
}
