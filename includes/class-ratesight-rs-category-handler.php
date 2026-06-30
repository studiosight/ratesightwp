<?php
/**
 * Resolves rs_category taxonomy terms, creating them if they don't exist.
 * Mirrors Ratesight_Category_Handler but operates on the rs_category taxonomy
 * used exclusively by ratesight_page CPT.
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_RS_Category_Handler {

	private const TAXONOMY = 'rs_category';

	/**
	 * @param string $child_name        Category name from the webhook payload.
	 * @param int    $settings_parent_id Parent term ID from plugin settings.
	 * @param string $parent_name        Parent term name from webhook payload (optional).
	 * @return int|\WP_Error             Term ID on success.
	 */
	public function resolve( string $child_name, int $settings_parent_id, string $parent_name = '' ): int|\WP_Error {

		// ── Resolve parent ────────────────────────────────────────────────────
		$parent_id = 0;

		if ( $parent_name !== '' ) {
			$parent_id = $this->find_or_create( $parent_name, 0 );
			if ( is_wp_error( $parent_id ) ) {
				return $parent_id;
			}
		} elseif ( $settings_parent_id > 0 ) {
			$parent = get_term( $settings_parent_id, self::TAXONOMY );
			if ( ! is_wp_error( $parent ) && ! empty( $parent ) ) {
				$parent_id = $settings_parent_id;
			}
		}

		// ── No child name — use parent (or no term) ───────────────────────────
		if ( $child_name === '' ) {
			return $parent_id > 0 ? $parent_id : 0;
		}

		// ── Find or create child ──────────────────────────────────────────────
		return $this->find_or_create( $child_name, $parent_id );
	}

	private function find_or_create( string $name, int $parent_id ): int|\WP_Error {
		$existing = $this->find( $name, $parent_id );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$args = array( 'slug' => sanitize_title( $name ) );
		if ( $parent_id > 0 ) {
			$args['parent'] = $parent_id;
		}

		$result = wp_insert_term( $name, self::TAXONOMY, $args );

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				$data = $result->get_error_data();
				return (int) ( is_array( $data ) ? $data['term_id'] : $data );
			}
			return $result;
		}

		return (int) $result['term_id'];
	}

	private function find( string $name, int $parent_id ): ?\WP_Term {
		$args = array(
			'taxonomy'   => self::TAXONOMY,
			'name'       => $name,
			'hide_empty' => false,
			'number'     => 1,
		);
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
