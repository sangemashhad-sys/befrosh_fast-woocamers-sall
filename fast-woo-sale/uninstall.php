<?php
/**
 * Uninstall Fast Woo Predictive Purchase
 * پاکسازی امن داده‌های موقت، ترنزینت‌ها و کرون‌های زمان‌بندی‌شده هنگام حذف افزونه
 *
 * نسخهٔ ۲.۱۲.۶ (G-20): پاکسازی کامل و شبکه‌ای —
 *  ۱) جدول کش ماتریس همبستگی (fws_product_affinity_cache) هم مثل بقیهٔ جداول حذف
 *     می‌شود؛ «نگه‌داشتن عمدی» قبلی با حذف جدول رخدادها ناسازگار بود و دادهٔ
 *     یتیم در دیتابیس می‌گذاشت (ری‌نصب همیشه ماینینگ تازه می‌سازد).
 *  ۲) روی چندسایته‌ها پاکسازی برای «همهٔ» سایت‌های شبکه انجام می‌شود؛ قبلاً فقط
 *     سایت جاری پاک می‌شد و سایت‌های دیگر دادهٔ یتیم نگه می‌داشتند.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        exit;
}

global $wpdb;

/**
 * پاکسازی کامل داده‌های افزونه برای سایت جاری ($wpdb به سایت جاری اشاره می‌کند)
 */
function fws_uninstall_purge_current_site() {
        global $wpdb;

        // Clear scheduled cron tasks (BUG-12 fix v2.8.1: the weekly cleanup event was left orphaned)
        wp_clear_scheduled_hook( 'fws_daily_database_mining_event' );
        wp_clear_scheduled_hook( 'fws_weekly_db_cleanup_event' );
        wp_clear_scheduled_hook( 'fws_daily_tracking_cleanup_event' ); // نسخه ۲.۱۰
        wp_clear_scheduled_hook( 'fws_ab_delete_test_events_resume' ); // نسخهٔ ۲.۱۲.۶ (G-19): کرون ادامهٔ حذف تست
        delete_transient( 'fws_insights_cache' ); // نسخهٔ ۲.۱۰

        // Clean up option configurations
        delete_option( 'fws_prediction_settings' );
        delete_option( 'fws_cache_version' );
        delete_option( 'fws_analytics_missing' );
        delete_option( 'fws_mining_incomplete' );
        delete_option( 'fws_ab_tests' );                // نسخه ۲.۱۰: تست‌های A/B
        delete_option( 'fws_db_version' );              // نسخه ۲.۱۰: نسخه اسکیمای دیتابیس
        delete_option( 'fws_affinity_db_version' );     // نسخهٔ ۲.۱۲.۵ (F-39)
        delete_option( 'fws_mining_lock' );             // نسخهٔ ۲.۱۲.۶ (G-09): قفل option-محور ماینینگ
        delete_option( 'fws_sidebar_shortcode_flag' );  // نسخهٔ ۲.۱۲.۶ (G-21): پرچم کش اسکن ویجت‌ها

        // Clear transient caches and atomic rate-limit buckets from options table (v2.9.0)
        // نسخهٔ ۲.۱۲.۴ (F-21): قفل‌های اتمی بدون TTL هم پاک می‌شوند — fws_upsell_lock_* فقط
        // در مسیرهای خروج حذف می‌شد و اگر درخواست آپسل بین INSERT و delete می‌مُرد (تایم‌اوت،
        // فتال در کال‌بک درگاه)، ردیفش تا همیشه در wp_options می‌ماند؛ fws_bundle_lock_* هم
        // قفل ادغام سشن پکیج است (v2.12.4).
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_fws_%' OR option_name LIKE '%_transient_timeout_fws_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fws\\_rate\\_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fws\\_upsell\\_lock\\_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fws\\_bundle\\_lock\\_%'" );

        // نسخهٔ ۲.۱۱: پاکسازی کلیدهای سشنی افزونه از جدول سشن ووکامرس
        // (fws_bundle_items / fws_bundle_signed / fws_ab_assignments در blob سشن می‌ماندند و
        // فقط با انقضای سشن پاک می‌شدند)
        $sessions_table  = $wpdb->prefix . 'woocommerce_sessions';
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
        $events_table  = $wpdb->prefix . 'fws_events';
        $events_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) ) === $events_table );
        if ( $events_exists ) {
                $wpdb->query( "DROP TABLE {$events_table}" );
        }

        // نسخهٔ ۲.۱۲.۶ (G-20): جدول کش ماتریس همبستگی هم حذف می‌شود — قبلاً «عمداً»
        // نگه داشته می‌شد که با حذف جدول رخدادها تناقض داشت و ردیف‌های یتیم جا می‌گذاشت.
        $affinity_table  = $wpdb->prefix . 'fws_product_affinity_cache';
        $affinity_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $affinity_table ) ) === $affinity_table );
        if ( $affinity_exists ) {
                $wpdb->query( "DROP TABLE {$affinity_table}" );
        }
}

if ( is_multisite() ) {
        // نسخهٔ ۲.۱۲.۶ (G-20): پاکسازی همهٔ سایت‌های شبکه — قبلاً فقط سایت جاری پاک می‌شد.
        // در وردپرس چندسایته، جدول‌ها/گزینه‌ها per-site هستند؛ پاکسازی ناقص یعنی دادهٔ یتیم
        // در بقیهٔ سایت‌ها تا همیشه می‌ماند.
        $fws_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0, 'update_site_cache' => false ) );
        foreach ( (array) $fws_site_ids as $fws_site_id ) {
                switch_to_blog( (int) $fws_site_id );
                fws_uninstall_purge_current_site();
                restore_current_blog();
        }
} else {
        fws_uninstall_purge_current_site();
}
