<?php

$checks = 0;
$failures = 0;

function check_client_accessibility_case( string $name, bool $ok ): void {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) $failures++;
	echo ( $ok ? 'ok     ' : 'NOT OK ' ) . $name . PHP_EOL;
}

$root = dirname( __DIR__ );
$widgets = file_get_contents( $root . '/admin/partials/tab-widgets.php' );
$support = file_get_contents( $root . '/admin/partials/tab-support.php' );
$support_route = file_get_contents( $root . '/admin/partials/tab-widget-settings.php' );
$performance = file_get_contents( $root . '/admin/partials/tab-performance-dashboard.php' );
$script = file_get_contents( $root . '/admin/js/ratesight-admin.js' );
$styles = file_get_contents( $root . '/admin/css/ratesight-admin.css' );

check_client_accessibility_case( 'copy controls have polite status regions', substr_count( $widgets, 'role="status" aria-live="polite"' ) === 2 && str_contains( $widgets, 'aria-describedby="rs-leave-reviews-copy-status"' ) && str_contains( $widgets, 'aria-describedby="rs-all-reviews-copy-status"' ) );
check_client_accessibility_case( 'clipboard fallback restores focus and reports failure', str_contains( $script, 'var activeElement = document.activeElement;' ) && str_contains( $script, 'activeElement.focus()' ) && str_contains( $script, 'Copy failed. Select and copy the shortcode manually.' ) );
check_client_accessibility_case( 'color controls expose values and descriptions', substr_count( $widgets, '<output id="rs-' ) === 2 && str_contains( $widgets, 'rs-star-color-help rs-star-color-value' ) && str_contains( $widgets, 'rs-review-text-color-help rs-review-text-color-value' ) );
check_client_accessibility_case( 'inactive custom color is disabled without losing its stored value', str_contains( $widgets, 'id="rs-review-text-color-preserve"' ) && str_contains( $widgets, 'class="screen-reader-text">Review text color</label>' ) && str_contains( $script, 'textColor.disabled = ! customText.checked;' ) && str_contains( $script, 'preservedTextColor.value = textColor.value;' ) );
check_client_accessibility_case( 'color guidance checks readable contrast on white', str_contains( $widgets, 'rs-star-contrast-status' ) && str_contains( $widgets, 'rs-text-contrast-status' ) && str_contains( $script, "contrastRatio( starColor.value ), 3" ) && str_contains( $script, "), 4.5" ) );
check_client_accessibility_case( 'widget preview is announced as an example and has no fake link', str_contains( $widgets, 'role="img" aria-label="Example review widget' ) && str_contains( $widgets, 'Reviews-page link' ) && ! str_contains( $widgets, '<a href=' ) );
check_client_accessibility_case( 'focus indicators cover custom controls', str_contains( $styles, 'input[type="color"]:focus-visible' ) && str_contains( $styles, 'input[type="checkbox"]:focus-visible' ) && str_contains( $styles, '.rs-btn-copy:focus-visible' ) );
check_client_accessibility_case( 'mobile settings reflow without fixed table widths', str_contains( $styles, 'display: block; width: auto !important;' ) && str_contains( $styles, '.rs-card select { max-width: 100%; font-size: 16px; }' ) );
check_client_accessibility_case( 'mobile copy controls meet touch sizing and motion preferences are respected', str_contains( $styles, '.rs-btn-copy { min-height: 44px; width: 100%; }' ) && str_contains( file_get_contents( $root . '/admin/partials/page-wrapper.php' ), '@media (prefers-reduced-motion:reduce)' ) );
check_client_accessibility_case( 'overview result collections have list semantics', substr_count( $performance, 'role="list"' ) >= 4 && substr_count( $performance, 'role="listitem"' ) >= 4 );
check_client_accessibility_case( 'technical version stays off client Support', ! str_contains( $support, 'Installed plugin version' ) && str_contains( $support_route, 'Installed plugin version' ) );

if ( $failures ) {
	echo "\nFAIL — {$checks} checks, {$failures} failure(s)\n";
	exit( 1 );
}

echo "\nPASS — {$checks} client accessibility checks\n";
