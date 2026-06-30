<?php
/**
 * Google Business Profile API client.
 *
 * Uses the current Business Profile APIs (split from the deprecated
 * mybusiness.googleapis.com/v4 endpoint in 2022):
 *
 *   Account Management:  mybusinessaccountmanagement.googleapis.com/v1
 *   Business Info:       mybusinessbusinessinformation.googleapis.com/v1
 *   Local Posts:         mybusiness.googleapis.com/v4  (still active for posts)
 *
 * @package    Ratesight
 * @subpackage Ratesight/includes
 */

defined( 'ABSPATH' ) || die;

class Ratesight_GBP_Client {

	private const ACCOUNTS_BASE  = 'https://mybusinessaccountmanagement.googleapis.com/v1/';
	private const INFO_BASE      = 'https://mybusinessbusinessinformation.googleapis.com/v1/';
	private const POSTS_BASE     = 'https://mybusiness.googleapis.com/v4/';

	// -------------------------------------------------------------------------
	// Setup helpers
	// -------------------------------------------------------------------------

	/**
	 * List all locations across all GBP accounts for the connected user.
	 * Returns a flat array of [ 'id' => resource_name, 'label' => "Account / Location" ]
	 *
	 * @return array|\WP_Error
	 */
	public static function list_all_locations(): array|WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// 1. Get all accounts (paginated).
		$accounts   = array();
		$acct_token = '';
		do {
			$acct_url = self::ACCOUNTS_BASE . 'accounts?pageSize=20';
			if ( $acct_token !== '' ) {
				$acct_url .= '&pageToken=' . rawurlencode( $acct_token );
			}
			$response = self::get( $acct_url, $token );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$accounts   = array_merge( $accounts, $response['accounts'] ?? array() );
			$acct_token = $response['nextPageToken'] ?? '';
		} while ( $acct_token !== '' );

		if ( empty( $accounts ) ) {
			return new \WP_Error( 'rs_gbp_no_accounts', 'No GBP accounts found for this Google account.' );
		}

		$locations    = array();
		$locs_errors  = array();

		foreach ( $accounts as $account ) {
			$account_name  = $account['name']        ?? '';
			$account_label = $account['accountName'] ?? $account_name;

			// Fetch all location pages for this account.
			$page_token = '';
			do {
				$locs_url = self::INFO_BASE . $account_name . '/locations?readMask=name%2Ctitle%2CstorefrontAddress&pageSize=100';
				if ( $page_token !== '' ) {
					$locs_url .= '&pageToken=' . rawurlencode( $page_token );
				}

				$locs_response = self::get( $locs_url, $token );

				if ( is_wp_error( $locs_response ) ) {
					$locs_errors[] = $account_name . ': ' . $locs_response->get_error_message();
					break;
				}

				foreach ( $locs_response['locations'] ?? array() as $loc ) {
					$loc_name    = $loc['name']  ?? '';
					$loc_title   = $loc['title'] ?? $loc_name;
					$full_path   = $account_name . '/' . $loc_name;
					$loc_id_short = basename( $loc_name );

					// Build address string if available.
					$addr  = $loc['storefrontAddress'] ?? array();
					$parts = array_filter( array(
						$addr['addressLines'][0] ?? '',
						$addr['locality']         ?? '',
						$addr['administrativeArea'] ?? '',
					) );
					$address_str = implode( ', ', $parts );

					$locations[] = array(
						'id'       => $full_path,
						'label'    => $loc_title,
						'sublabel' => $address_str ?: ( 'ID: ' . $loc_id_short ),
					);
				}

				$page_token = $locs_response['nextPageToken'] ?? '';

			} while ( $page_token !== '' );
		}

		if ( empty( $locations ) ) {
			$account_count = count( $accounts );
			$account_names = implode( ', ', array_column( $accounts, 'name' ) );
			$error_detail  = ! empty( $locs_errors ) ? ' API errors: ' . implode( '; ', $locs_errors ) : '';
			return new \WP_Error( 'rs_gbp_no_locations', "No locations found across {$account_count} account(s): {$account_names}.{$error_detail}" );
		}

		return $locations;
	}

	/**
	 * Create a "What's New" local post on a GBP location.
	 *
	 * @param string $location_resource  e.g. locations/1234567890
	 * @param string $summary            Post body text.
	 * @param string $post_url           CTA link URL.
	 * @param string $image_url          Optional featured image URL.
	 * @return array|\WP_Error
	 */
	public static function create_post( string $location_resource, string $summary, string $post_url, string $image_url = '' ): array|WP_Error {
		$token = Ratesight_OAuth_Client::get_access_token( 'gbp' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Clean the summary for plain-text GBP posting:
		// 1. Expand any remaining shortcodes so we don't send raw [shortcode] strings.
		// 2. Strip all HTML tags.
		// 3. Decode HTML entities (WordPress stores curly quotes, em-dashes etc.
		//    as entities; sending them un-decoded produces garbage characters on GBP).
		// 4. Collapse whitespace.
		$summary = strip_shortcodes( $summary );
		$summary = wp_strip_all_tags( $summary );
		$summary = html_entity_decode( $summary, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$summary = preg_replace( '/\s+/u', ' ', trim( $summary ) );

		// GBP enforces a 1500-character limit. Truncate at a word boundary so
		// the text isn't cut mid-word, and append an ellipsis.
		if ( mb_strlen( $summary ) > 1500 ) {
			$trimmed    = mb_substr( $summary, 0, 1497 );
			$last_space = mb_strrpos( $trimmed, ' ' );
			$summary    = ( $last_space !== false ? mb_substr( $trimmed, 0, $last_space ) : $trimmed ) . '…';
		}

		$body = array(
			'topicType'    => 'STANDARD',
			'summary'      => $summary,
			'callToAction' => array(
				'actionType' => Ratesight_Options::get( 'gbp_cta_type' ) ?: 'LEARN_MORE',
				'url'        => esc_url_raw( $post_url ),
			),
		);

		if ( ! empty( $image_url ) ) {
			$body['media'] = array(
				array(
					'mediaFormat' => 'PHOTO',
					'sourceUrl'   => esc_url_raw( $image_url ),
				),
			);
		}

		// Posts endpoint: https://mybusiness.googleapis.com/v4/{accounts/X/locations/Y}/localPosts
		$location_path = ltrim( $location_resource, '/' );
		$url           = 'https://mybusiness.googleapis.com/v4/' . $location_path . '/localPosts';
		return self::post( $url, $token, $body );
	}

	// -------------------------------------------------------------------------
	// Connection state helpers
	// -------------------------------------------------------------------------

	public static function is_locked() {
		return (bool) get_option( 'ratesight_gbp_locked', false );
	}

	public static function get_selection() {
		return (array) get_option( 'ratesight_gbp_selection', array() );
	}

	public static function lock_selection( string $location_id, string $label ) {
		update_option( 'ratesight_gbp_selection', array( 'id' => $location_id, 'label' => $label ), false );
		update_option( 'ratesight_gbp_locked', 1, false );
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	private static function get( string $url, string $token ): array|WP_Error {
		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		) );
		return self::parse( $response, $url );
	}

	private static function post( string $url, string $token, array $body ): array|WP_Error {
		$response = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );
		return self::parse( $response, $url );
	}

	private static function parse( $response, string $url = '' ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'rs_gbp_http', 'GBP request failed: ' . $response->get_error_message() );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 429 ) {
			$retry_after = $headers['retry-after'] ?? 60;
			return new \WP_Error( 'rs_gbp_rate_limit', "GBP rate limited — retry after {$retry_after}s. Sync will resume on next scheduled run." );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = $decoded['error']['message'] ?? "HTTP {$code}";

			// Extract field-level violations from Google's error details so the log
			// shows exactly which argument is invalid (e.g. "media[0].sourceUrl").
			$violations = array();
			foreach ( $decoded['error']['details'] ?? array() as $detail ) {
				foreach ( $detail['fieldViolations'] ?? array() as $v ) {
					$field = $v['field'] ?? '';
					$desc  = $v['description'] ?? '';
					if ( $field || $desc ) {
						$violations[] = trim( "{$field}: {$desc}", ': ' );
					}
				}
			}
			if ( ! empty( $violations ) ) {
				$message .= ' [' . implode( '; ', $violations ) . ']';
			}

			$debug = $url ? " (URL: {$url})" : '';
			return new \WP_Error( 'rs_gbp_api', 'GBP API error: ' . $message . $debug );
		}

		return $decoded ?? array();
	}
}
