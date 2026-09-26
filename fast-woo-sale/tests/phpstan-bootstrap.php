<?php
/**
 * Runtime constants declared for PHPStan only.
 *
 * نسخهٔ ۲.۱۲.۶ (G-23): FWS_VERSION دیگر عدد دست‌نویسِ کهنه نیست (۲.۸.۰ از نسخهٔ ۲.۸
 * عقب مانده بود) — از هدر خود افزونه خوانده می‌شود تا همیشه هم‌رو باشد.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$fws_plugin_src = (string) file_get_contents( __DIR__ . '/../fast-woo-sale.php' );
preg_match( '/^\s*\*\s*Version:\s*([\d.]+)/m', $fws_plugin_src, $fws_version_matches );
define( 'FWS_VERSION', isset( $fws_version_matches[1] ) ? $fws_version_matches[1] : '0.0.0' );
define( 'FWS_PLUGIN_DIR', __DIR__ . '/../' );
define( 'FWS_PLUGIN_URL', 'https://example.test/wp-content/plugins/fast-woo-sale/' );
define( 'FWS_MIN_CONFIDENCE', 60 );
define( 'FWS_BUNDLE_DISCOUNT', 12 );
