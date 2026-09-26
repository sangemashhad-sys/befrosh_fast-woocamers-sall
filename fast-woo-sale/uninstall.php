<?php
/**
 * Uninstall Fast Woo Predictive Purchase
 * پاکسازی امن داده‌های موقت، ترنزینت‌ها و کرون‌های زمان‌بندی‌شده هنگام حذف افزونه
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
}

// Clear scheduled cron tasks (BUG-12 fix v2.8.1: the weekly cleanup event was left orphaned)
wp_clear_scheduled_hook( 'fws_daily_database_mining_event' );
wp_clear_scheduled_hook( 'fws_weekly_db_cleanup_event' );
wp_clear_scheduled_hook( 'fws_daily_tracking_cleanup_event' ); // نسخه ۲.۱۰
delete_transient( 'fws_mining_lock' );
delete_transient( 'fws_insights_cache' ); // نسخه ۲.۱۰

// Clean up option configurations
delete_option( 'fws_prediction_settings' );
delete_option( 'fws_cache_version' );
delete_option( 'fws_analytics_missing' );
delete_option( 'fws_mining_incomplete' );
delete_option( 'fws_ab_tests' );   // نسخه ۲.۱۰: تست‌های A/B
delete_option( 'fws_db_version' ); // نسخه ۲.۱۰: نسخه اسکیمای دیتابیس

// Clear transient caches and atomic rate-limit buckets from options table (v2.9.0)
// نسخهٔ ۲.۱۲.۴ (F-21): قفل‌های اتمی بدون TTL هم پاک می‌شوند — fws_upsell_lock_* فقط
// در مسیرهای خروج حذف می‌شد و اگر درخواست آپسل بین INSERT و delete می‌مُرد (تایم‌اوت،
// فتال در کال‌بک درگاه)، ردیفش تا همیشه در wp_options می‌ماند؛ fws_bundle_lock_* هم
// قفل ادغام سشن پکیج است (v2.12.4).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_fws_%' OR option_name LIKE '%_transient_timeout_fws_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fws\\_rate\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fws\\_upsell\\_lock\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fws\\_bundle\\_lock\\_%'" );

// نسخهٔ ۲.۱۱: پاکسازی کلیدهای سشنی افزونه از جدول سشن ووکامرس
// (fws_bundle_items / fws_bundle_signed / fws_ab_assignments در blob سشن می‌ماندند و
// فقط با انقضای سشن پاک می‌شدند)
$sessions_table = $wpdb->prefix . 'woocommerce_sessions';
$sessions_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) === $sessions_table );
if ( $sessions_exists ) {
        $wpdb->query(
                "DELETE FROM {$sessions_table}
                 WHERE session_value LIKE '%fws_bundle_items%'
                    OR session_value LIKE '%fws_bundle_signed%'
                    OR session_value LIKE '%fws_ab_assignments%'"
        );
}

// نسخه ۲.۱۰: حذف جدول رخدادهای ردیابی (داده شمارشی بدون داده شخصی خام — با حذف افزونه پاک می‌شود)
$events_table = $wpdb->prefix . 'fws_events';
$events_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) ) === $events_table );
if ( $events_exists ) {
        $wpdb->query( "DROP TABLE {$events_table}" );
}

// Note: The affinity table {$wpdb->prefix}fws_product_affinity_cache is intentionally preserved
// to prevent catastrophic data loss if the plugin is temporarily deactivated or re-installed.
// Multisite note (v2.9.0): WordPress runs this file per-site during "Delete site data", but the
// affinity table is created per-site too — network admins who want a full wipe must run the
// uninstall per site (or drop {$wpdb->prefix}fws_product_affinity_cache on every site manually).
