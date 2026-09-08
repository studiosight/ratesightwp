<?php

$checks = 0;
$failures = 0;

function check_client_content_widgets_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

$root = dirname( __DIR__ );
$widgets = file_get_contents( $root . '/admin/partials/tab-widgets.php' );
$support = file_get_contents( $root . '/admin/partials/tab-widget-settings.php' );
$options = file_get_contents( $root . '/includes/class-ratesight-options.php' );
$cpt = file_get_contents( $root . '/includes/class-ratesight-cpt.php' );

check_client_content_widgets_case( 'client page leads with review controls and a live preview', str_contains( $widgets, 'Where Reviews Lead' ) && str_contains( $widgets, 'id="rs-widget-preview"' ) && str_contains( $widgets, 'id="rs-star-color"' ) && str_contains( $widgets, 'id="rs-custom-text-color"' ) );
check_client_content_widgets_case( 'client page shows only review shortcodes', str_contains( $widgets, '[rs_leave_reviews]' ) && str_contains( $widgets, '[rs_all_reviews]' ) && ! str_contains( $widgets, '[rs_jobs]' ) );
check_client_content_widgets_case( 'client page exposes only the Ratesight account ID, not legacy identity or authentication internals', str_contains( $widgets, 'wp_ratesight_code_id' ) && str_contains( $widgets, 'Ratesight ID' ) && ! preg_match( '/wp_ratesight_(campaign_id|domain_id)|Campaign ID|Domain ID|\bOID\b|authenticates|site_key|webhook_secret/i', $widgets ) );
check_client_content_widgets_case( 'Ratesight account ID uses a dedicated settings group while legacy widget IDs remain isolated', preg_match( "/'code_id'\s*=>.*'name'\s*=>\s*'wp_ratesight_code_id'.*'group'\s*=>\s*'site_identity'/", $options ) && preg_match( "/'campaign_id'\s*=>.*'group'\s*=>\s*'widget_identity'/", $options ) && preg_match( "/'domain_id'\s*=>.*'group'\s*=>\s*'widget_identity'/", $options ) );
check_client_content_widgets_case( 'site signing key has no form settings group', preg_match( "/'site_key'\s*=>.*'name'\s*=>\s*'wp_ratesight_site_key'.*'group'\s*=>\s*'internal'/", $options ) );
check_client_content_widgets_case( 'support route can recover legacy identity without exposing the site key', str_contains( $support, "settings_fields( 'ratesight_options_site_identity' )" ) && str_contains( $support, "settings_fields( 'ratesight_options_widget_identity' )" ) && str_contains( $support, 'wp_ratesight_code_id' ) && str_contains( $support, 'wp_ratesight_campaign_id' ) && str_contains( $support, 'wp_ratesight_domain_id' ) && ! str_contains( $support, 'wp_ratesight_site_key' ) );
check_client_content_widgets_case( 'WordPress labels use client language', str_contains( $cpt, "'name'               => 'SEO Content'" ) && str_contains( $cpt, "'name'          => 'SEO Categories'" ) && ! str_contains( $cpt, "'name'               => 'RS Pages'" ) && ! str_contains( $cpt, "'name'          => 'RS Categories'" ) );
check_client_content_widgets_case( 'internal content identifiers and rewrites stay unchanged', str_contains( $cpt, "register_post_type( 'ratesight_page'") && str_contains( $cpt, "register_taxonomy( 'rs_category', 'ratesight_page'") && str_contains( $cpt, "'slug'       => 'rs-page'") && str_contains( $cpt, "array( 'slug' => 'rs-category', 'with_front' => false )") );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

echo "\nPASS — {$checks} client content and widget checks\n";
