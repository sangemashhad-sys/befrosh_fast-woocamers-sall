<?php
/**
 * Class FWS_Database_Miner
 * ماینر فوق‌سریع، بهینه و بدون بار اضافی روی دیتابیس (پشتیبانی دوگانه HPOS و ساختار کلاسیک، رفع کامل N+1، کاهش ۵۰٪ بار پردازش با روابط متقارن و Bulk Insert)
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Database_Miner {

        const TABLE_AFFINITY = 'fws_product_affinity_cache';
        const CACHE_GROUP    = 'fws_affinity_rules';

        /**
         * اتصال اکشن کرون‌جاب دوره‌ای به ماینر
         */
        public static function init() {
                add_action( 'fws_daily_database_mining_event', array( __CLASS__, 'run_scheduled_mining' ) );
                add_action( 'fws_weekly_db_cleanup_event', array( __CLASS__, 'cleanup_orphaned_and_stale_records' ) );
                // نسخهٔ ۲.۱۰.۳ (B-19): بازهٔ «weekly» در وردپرس هسته وجود ندارد (فقط hourly/
                // twicedaily/daily). بدون ثبت آن، wp_schedule_event(hoze 'weekly') بی‌صدا false
                // برمی‌گرداند و پاکسازی هفتگی جدول از ابتدای عمر افزونه هرگز اجرا نشده بود.
                add_filter( 'cron_schedules', array( __CLASS__, 'register_weekly_schedule' ) );
                // نسخهٔ ۲.۱۰.۳: مسیر ارتقای شِمای جدول affinity — فقط یک بار در هر تغییر نسخه اجرا می‌شود
                // (قبلاً تغییر ستون‌ها فقط با غیرفعال/فعال‌سازی دستی اعمال می‌شد و فروشگاه‌های به‌روزشده
                // روی شِمای قدیمی می‌ماندند).
                add_action( 'init', array( __CLASS__, 'maybe_upgrade_schema' ), 20 );
                // نسخهٔ ۲.۱۰.۴ (B-25): خودترمیمی زمان‌بند کرون‌های ماینینگ/پاکسازی — مستقل از
                // مسیر ارتقای شِما. wp_next_scheduled آرایهٔ کرونِ autoload را از حافظه
                // می‌خواند؛ هیچ کوئری اضافه‌ای به درخواست نمی‌افزاید.
                add_action( 'init', array( __CLASS__, 'ensure_crons_scheduled' ), 30 );
                // نسخه ۲.۷: هوک حذف محصول باید در هر درخواست ثبت شود؛ ثبت آن فقط در activation عملاً مرده بود
                add_action( 'before_delete_post', array( __CLASS__, 'on_product_deleted' ) );
                if ( defined( 'WP_CLI' ) && WP_CLI ) {
                        WP_CLI::add_command( 'fws mine', array( __CLASS__, 'cli_run_mining' ) );
                        WP_CLI::add_command( 'fws vacuum', array( __CLASS__, 'cli_run_vacuum' ) );
                }
        }

        public static function cli_run_vacuum( $args, $assoc_args ) {
                WP_CLI::log( 'Starting Fast Woo Database Vacuum and Orphan Pruning...' );
                self::cleanup_orphaned_and_stale_records();
                WP_CLI::success( 'Database sanitized, indexes defragmented, and cache flushed.' );
        }

        /**
         * نسخهٔ ۲.۱۰.۴ (B-25): خودترمیمی زمان‌بند کرون‌های ماینینگ روزانه و پاکسازی هفتگی.
         * قبلاً فقط create_tables_and_schedule (فعال‌سازی/ارتقای شِما) زمان‌بندی می‌ساخت؛
         * اگر رویداد حذف شده بود تا ارتقای بعدی دوباره ساخته نمی‌شد.
         *
         * @return void
         */
        public static function ensure_crons_scheduled() {
                if ( ! wp_next_scheduled( 'fws_daily_database_mining_event' ) ) {
                        wp_schedule_event( time() + 120, 'daily', 'fws_daily_database_mining_event' );
                }
                if ( ! wp_next_scheduled( 'fws_weekly_db_cleanup_event' ) ) {
                        wp_schedule_event( time() + 600, 'weekly', 'fws_weekly_db_cleanup_event' );
                }
        }

        public static function run_scheduled_mining() {
                self::run_market_basket_analysis();
        }

        /**
         * نسخهٔ ۲.۱۰.۳ (B-19): ثبت بازهٔ هفتگی در WP-Cron
         * وردپرس به‌صورت پیش‌فرض فقط hourly / twicedaily / daily را می‌شناسد؛ بدون این فیلتر،
         * زمان‌بندی «weekly» هرگز ساخته نمی‌شد و cleanup_orphaned_and_stale_records هیچ‌گاه
         * اجرا نمی‌شد. (اگر افزونهٔ دیگری weekly را ثبت کرده باشد، به احترام آن دست نمی‌زنیم.)
         *
         * @param array $schedules
         * @return array
         */
        public static function register_weekly_schedule( $schedules ) {
                if ( ! isset( $schedules['weekly'] ) ) {
                        $schedules['weekly'] = array(
                                'interval' => WEEK_IN_SECONDS,
                                'display'  => 'یک‌بار در هفته (Fast Woo)',
                        );
                }
                return $schedules;
        }

        /**
         * نسخهٔ ۲.۱۰.۳ — ارتقای خودکار شِمای جدول affinity بر اساس نسخهٔ کد:
         * ۱) dbDelta با شِمای جدید (ستون‌های عریض‌تر)
         * ۲) ALTER صریح lift_score به DECIMAL(10,4) — مستقل از دیف-دیافلت dbDelta تا سرریز
         *    سرریز DECIMAL(5,2) (lift بالای ۹۹۹٫۹۹ در فروشگاه‌های کم‌سفارش/پرتکرار) قطعاً رفع شود؛
         *    در حالت strict، درجِ سرریازده باعث شکست کل INSERT گروهی و سقوط بی‌صدای کل بچ می‌شد.
         * ۳) انتقال گزینهٔ fws_cache_version به autoload=no — این گزینه با هر purge بازنویسی می‌شود؛
         *    autoload بودنش یعنی باخته‌شدن کشِ گزینه‌های autoloaded همهٔ درخواست‌ها در هر پاکسازی.
         * ۴) اطمینان از زمان‌بندی کرون هفتگی برای نصب‌های قدیمی (که به‌دلیل B-19 هرگز ساخته نشده بود).
         *
         * @return void
         */
        public static function maybe_upgrade_schema() {
                $prev = (string) get_option( 'fws_affinity_db_version', '' );
                if ( FWS_VERSION === $prev ) {
                        return;
                }

                self::create_tables_and_schedule();

                global $wpdb;
                $table = $wpdb->prefix . self::TABLE_AFFINITY;

                // ALTER صریح: نوع واقعی ستون از INFORMATION_SCHEMA خوانده می‌شود تا حتی اگر
                // دیف dbDelta ستون را از قلم انداخته باشد، عریض‌سازی حتماً اعمال شود.
                $col = $wpdb->get_row(
                        $wpdb->prepare(
                                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'lift_score'",
                                DB_NAME,
                                $table
                        )
                );
                if ( $col && false === strpos( strtolower( (string) $col->COLUMN_TYPE ), '10,4' ) ) {
                        $wpdb->query( "ALTER TABLE {$table} MODIFY lift_score DECIMAL(10,4) NOT NULL DEFAULT 1.00" );
                }

                // fws_cache_version → autoload=no (فقط روی نصب‌های موجود؛ نصب تازه از همین نسخه
                // با پارامتر سوم update_option اصلاً autoloaded ساخته نمی‌شود)
                if ( '' !== $prev ) {
                        $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => 'fws_cache_version' ) );

                        // نسخهٔ ۲.۱۰.۴ (B-22): گزینه‌های اصلی افزونه باید autoload=yes باشند —
                        // هر سه در هر درخواست خوانده می‌شوند و روی نصب‌هایی که «اولین نوشتن»شان با
                        // پارامتر false انجام شده بود، برای همیشه non-autoload مانده بودند (تلهٔ
                        // autoload وردپرس در WP < 6.6) و در هر درخواست یک کوئری اختصاصی می‌ساختند.
                        // حجم fws_prediction_settings حتی در فروشگاه‌های پرقانون هم به‌صرفهٔ
                        // autoload است (یک خواندنِ اشتراکی به‌جای کوئری مجزا).
                        $wpdb->query(
                                "UPDATE {$wpdb->options} SET autoload = 'yes'
                                 WHERE option_name IN ('fws_prediction_settings','fws_db_version','fws_ab_tests')
                                   AND autoload = 'no'"
                        );

                        // نسخهٔ ۲.۱۲.۴ (F-04 — بازسازی پس از اصلاح HPOS): روی فروشگاه‌های HPOS،
                        // ماینینج معیوب (وضعیت بدون پسوند) صفر قانون تولید می‌کرد و هرسِ پس از اجرا
                        // ماتریس موجود را هم می‌زداید. با اصلاح کوئری‌ها، یک اجرای فوری کرون (۲ دقیقه
                        // بعد) قوانین را از داده‌های موجود بازسازی می‌کند تا ویجت‌ها منتظر کرون شبانه
                        // نمانند. قفل fws_mining_lock از اجرای موازی با کرون روزانه جلوگیری می‌کند.
                        if ( '' !== $prev && version_compare( $prev, '2.12.4', '<' )
                                && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
                                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                                if ( ! wp_next_scheduled( 'fws_daily_database_mining_event' ) ) {
                                        wp_schedule_event( time() + 120, 'daily', 'fws_daily_database_mining_event' );
                                }
                                wp_schedule_single_event( time() + 120, 'fws_daily_database_mining_event' );
                        }
                }

                update_option( 'fws_affinity_db_version', FWS_VERSION, false );
        }

        /**
         * نسخهٔ ۲.۱۰.۳ — خودترمیمی جدول affinity:
         * اگر جدول به هر دلیل (حذف دستی، شکست dbDelta در فعال‌سازی، جابه‌جایی هاست) وجود نداشته
         * باشد، کوئری‌های موتور بی‌صدا خطای SQL می‌دادند و همهٔ ویجت‌های مبتنی بر قوانین تا بازِ
         * فعال‌سازی مجدد خالی می‌ماندند. این گارد، جدول را یک‌بار در هر درخواست می‌سازد؛
         * کول‌داون ۵ دقیقه‌ای جلوی تلاشِ سنگینِ dbDelta در هر درخواست روی نصب‌های خراب را می‌گیرد.
         *
         * @param bool $self_heal در صورت نبود جدول، ساخت مجدد تلاش شود؟
         * @return bool
         */
        public static function affinity_table_exists( $self_heal = true ) {
                static $exists = null;
                if ( null !== $exists ) {
                        return $exists;
                }
                global $wpdb;
                $table = $wpdb->prefix . self::TABLE_AFFINITY;
                $found = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

                if ( ! $found && $self_heal && ! get_transient( 'fws_affinity_heal_cooldown' ) ) {
                        set_transient( 'fws_affinity_heal_cooldown', 1, 5 * MINUTE_IN_SECONDS );
                        self::create_tables_and_schedule( true ); // (F-20) بدون OPTIMIZE در درخواست وب
                        $found = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
                }
                $exists = $found;
                return $exists;
        }

        /**
         * اجرای مستقیم تحلیل سبد خرید از طریق ترمینال لینوکس و WP-CLI با صفر درصد تأثیر روی سرور وب
         * مثال: wp fws mine --days=90
         */
        public static function cli_run_mining( $args, $assoc_args ) {
                $lookback = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 90;
                WP_CLI::log( "Starting Fast Woo Market Basket Mining (Lookback: {$lookback} days)..." );
                $start = microtime( true );
                delete_transient( 'fws_mining_lock' );
                $count    = self::run_market_basket_analysis( $lookback );
                $duration = round( microtime( true ) - $start, 2 );
                WP_CLI::success( "Mining finished in {$duration}s. Generated and indexed {$count} affinity rules." );
        }

        public static function get_memory_limit_bytes() {
                $val = ini_get( 'memory_limit' );
                if ( ! $val || $val === '-1' ) {
                        return 512 * 1024 * 1024;
                }
                $val     = trim( $val );
                $last    = strtolower( $val[ strlen( $val ) - 1 ] );
                $int_val = (int) $val;
                switch ( $last ) {
                        case 'g':
                                $int_val *= 1024 * 1024 * 1024;
                                break;
                        case 'm':
                                $int_val *= 1024 * 1024;
                                break;
                        case 'k':
                                $int_val *= 1024;
                                break;
                }
                return max( 128 * 1024 * 1024, $int_val );
        }

        /**
         * ایجاد جدول کش ماتریس همبستگی با ایندکس‌های ترکیبی پوششی (Covering Indexes)
         */
        public static function create_tables_and_schedule( $skip_optimize = false ) {
                global $wpdb;
                $table_name      = $wpdb->prefix . self::TABLE_AFFINITY;
                $charset_collate = $wpdb->get_charset_collate();

                // نسخهٔ ۲.۱۰.۱ — اصلاح فرمت dbDelta:
                // dbDelta روی فرمت دستور CREATE TABLE حساس است؛ «IF NOT EXISTS» را نمی‌شناسد
                // (منطق دیفرنس آن به‌هم می‌ریزد و در ارتقاها ستون/ایندکس جدید اعمال نمی‌شد) و
                // ایندکس باید با کلیدواژه «KEY» تعریف شود نه «INDEX». خودِ دستور CREATE را
                // dbDelta فقط برای جدولِ تازه اجرا می‌کند و برای جدول موجود، صرفاً ALTERهای
                // موردنیاز را استخراج می‌کند؛ پس حذف IF NOT EXISTS هیچ خطایی ایجاد نمی‌کند.
                $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_product_id BIGINT(20) UNSIGNED NOT NULL,
            recommended_product_id BIGINT(20) UNSIGNED NOT NULL,
            co_occurrence INT(11) UNSIGNED NOT NULL DEFAULT 1,
            confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            lift_score DECIMAL(10,4) NOT NULL DEFAULT 1.00,
            last_calculated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY product_pair (source_product_id, recommended_product_id),
            KEY idx_affinity_scoring (source_product_id, confidence_score, lift_score),
            KEY idx_rec_lookup (recommended_product_id, co_occurrence),
            KEY idx_calc_time (last_calculated)
        ) {$charset_collate} ENGINE=InnoDB ROW_FORMAT=DYNAMIC;";

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta( $sql );

                // Optimize table structure
                // (F-20) نسخهٔ ۲.۱۲.۴: مسیر self-heal این تابع را داخل یک درخواست وب صدا می‌زند؛
                // OPTIMIZE TABLE (بازسازی کامل جدول InnoDB) در همان درخواست، رندر اولین بازدیدکننده
                // را چند ثانیه قفل می‌کرد. در self-heal بهینه‌سازی رد می‌شود (جدول تازه خالی است و
                // OPTIMIZE هیچ سودی ندارد) و کرون روزانه بعدی کار بهینه‌سازی را انجام می‌دهد.
                if ( ! $skip_optimize ) {
                        self::optimize_indexes();
                }

                // Schedule periodic mining cron
                if ( ! wp_next_scheduled( 'fws_daily_database_mining_event' ) ) {
                        wp_schedule_event( time() + 60, 'daily', 'fws_daily_database_mining_event' );
                }

                // Schedule weekly database hygiene & orphan cleanup
                if ( ! wp_next_scheduled( 'fws_weekly_db_cleanup_event' ) ) {
                        wp_schedule_event( time() + 3600, 'weekly', 'fws_weekly_db_cleanup_event' );
                }

                // نسخهٔ ۲.۱۰.۳: علامت‌گذاری نسخهٔ شِما تا ارتقای خودکار (maybe_upgrade_schema)
                // روی نصب‌های تازه دوباره اجرا نشود.
                update_option( 'fws_affinity_db_version', FWS_VERSION, false );

                // Schedule initial calculation asynchronously to prevent activation timeouts on heavy stores
                // نسخهٔ ۲.۹.۳ — گارد رویداد تکی: اگر کرونِ نسخهٔ قبلی نصب (مثلاً حذف دستی پوشه بدون
                // غیرفعال‌سازی و نصب مجدد) باقی مانده باشد، wp_schedule_single_event رویداد دوم را
                // روی همان هوک می‌نوشت و ماینینگ در کمتر از ۵ دقیقه دو بار اجرا می‌شد. فقط زمانی
                // رویداد فوری می‌سازیم که هیچ اجرایی برای این هوک در ۵ دقیقهٔ آینده برنامه‌ریزی نشده باشد.
                $fws_next_mining = wp_next_scheduled( 'fws_daily_database_mining_event' );
                if ( ! $fws_next_mining || $fws_next_mining > ( time() + 5 * MINUTE_IN_SECONDS ) ) {
                        wp_schedule_single_event( time() + 5, 'fws_daily_database_mining_event' );
                }
        }

        public static function clear_scheduled_events() {
                wp_clear_scheduled_hook( 'fws_daily_database_mining_event' );
                wp_clear_scheduled_hook( 'fws_weekly_db_cleanup_event' );
                delete_transient( 'fws_mining_lock' );
        }

        /**
         * حذف فوری رکوردهای جدول در زمان حذف فیزیکی محصول از ووکامرس (Zero Orphan Records)
         */
        public static function on_product_deleted( $post_id ) {
                if ( get_post_type( $post_id ) === 'product' ) {
                        global $wpdb;
                        $table_name = $wpdb->prefix . self::TABLE_AFFINITY;
                        $wpdb->query(
                                $wpdb->prepare(
                                        "
                DELETE FROM {$table_name} 
                WHERE source_product_id = %d OR recommended_product_id = %d
            ",
                                        $post_id,
                                        $post_id
                                )
                        );
                        self::purge_cache();
                }
        }

        /**
         * بهینه‌سازی ساختار دیتابیس، پاکسازی رکوردهای منقضی‌شده و بدون استفاده (Database Vacuuming)
         */
        public static function cleanup_orphaned_and_stale_records() {
                global $wpdb;
                $table_name = $wpdb->prefix . self::TABLE_AFFINITY;

                // 1. حذف رکوردهایی که محصول اصلی یا مکمل آن‌ها دیگر در ووکامرس وجود ندارد یا پاک شده است
                // نسخهٔ ۲.۱۰.۳ — دسته‌ای: DELETE چندجدولی در MySQL سقف LIMIT ندارد؛ پس شناسه‌ها
                // بچ‌بچ (۵۰۰۰تایی) انتخاب و حذف می‌شوند تا روی جدول‌های بزرگ قفل طولانی نسازد.
                $orphan_start = microtime( true );
                do {
                        $orphan_ids = $wpdb->get_col(
                                "
            SELECT aff.id FROM {$table_name} aff
            LEFT JOIN {$wpdb->posts} p1 ON aff.source_product_id = p1.ID AND p1.post_type = 'product'
            LEFT JOIN {$wpdb->posts} p2 ON aff.recommended_product_id = p2.ID AND p2.post_type = 'product'
            WHERE p1.ID IS NULL OR p2.ID IS NULL OR p1.post_status = 'trash' OR p2.post_status = 'trash'
            LIMIT 5000
        "
                        );
                        if ( empty( $orphan_ids ) ) {
                                break;
                        }
                        $orphan_in = implode( ',', array_map( 'intval', $orphan_ids ) );
                        $wpdb->query( "DELETE FROM {$table_name} WHERE id IN ({$orphan_in})" );
                        if ( count( $orphan_ids ) < 5000 ) {
                                break;
                        }
                        if ( ( microtime( true ) - $orphan_start ) > 25 ) {
                                break; // بودجهٔ زمانی؛ ادامه در اجرای هفتگی بعدی
                        }
                        usleep( 20000 );
                } while ( true );

                // 2. هرس کردن رکوردهای بسیار قدیمی و فاقد ارزش آماری (Below threshold cleanup)
                // نسخهٔ ۲.۱۰.۳ — دسته‌ای با LIMIT؛ تغییر آستانهٔ اطمینان دیگر یک DELETE بی‌کران نمی‌سازد.
                // نسخهٔ ۲.۱۰.۴: NOW() سرور از current_time('mysql') سایت می‌تواند منحرف باشد؛
                // ستون last_calculated با ساعت سایت نوشته می‌شود، پس برش هم با ساعت سایت ساخته می‌شود.
                $min_conf = floatval( FWS_Settings::get( 'min_confidence', 60 ) );
                $stale_time_cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 120 * DAY_IN_SECONDS );
                $stale_start2 = microtime( true );
                do {
                        // (F-19) نسخهٔ ۲.۱۲.۴: دو DELETE تک‌گزاره‌ای جای OR مشترک. با «OR»،
                        // بهینه‌ساز نمی‌توانست برای هر دو شرط هم‌زمان از ایندکس استفاده کند و هر
                        // دسته به اسکن کامل جدول بدل می‌شد (جدول چندمیلیونی = چند ثانیه برای هر دسته).
                        // اکنون شاخهٔ اطمینان از ایندکس پیشرویِ confidence_score (idx_affinity_scoring)
                        // و شاخهٔ زمان از idx_calc_time استفاده می‌کنند.
                        $stale_conf = (int) $wpdb->query(
                                $wpdb->prepare(
                                        "DELETE FROM {$table_name} WHERE confidence_score < %f LIMIT 5000",
                                        max( 20.0, $min_conf - 20.0 )
                                )
                        );
                        $stale_time = (int) $wpdb->query(
                                $wpdb->prepare(
                                        "DELETE FROM {$table_name} WHERE last_calculated < %s LIMIT 5000",
                                        $stale_time_cutoff
                                )
                        );
                        $stale_del = $stale_conf + $stale_time;
                        if ( $stale_del < 5000 ) {
                                break;
                        }
                        if ( ( microtime( true ) - $stale_start2 ) > 25 ) {
                                break;
                        }
                        usleep( 20000 );
                } while ( true );

                // 3. Defragment و بهینه‌سازی دیسک و ایندکس‌ها
                self::optimize_indexes();
        }

        /**
         * بهینه‌سازی و Defragment کردن جداول و پاکسازی کش‌ها
         */
        public static function optimize_indexes() {
                global $wpdb;
                $table_name = $wpdb->prefix . self::TABLE_AFFINITY;
                $wpdb->query( "OPTIMIZE TABLE {$table_name}" );
                self::purge_cache();
        }

        /**
         * پاکسازی امن کش با پشتیبانی از کش گروهی و ارتقای نسخه کلید کش (Cache Busting)
         */
        public static function purge_cache() {
                if ( function_exists( 'wp_cache_flush_group' ) ) {
                        wp_cache_flush_group( self::CACHE_GROUP );
                }
                $current_ver = (int) get_option( 'fws_cache_version', 1 );
                // نسخهٔ ۲.۱۰.۳ — پارامتر سوم: این گزینه پرنوشت است و نباید در لیست autoload
                // باشد (نصب‌های موجود با maybe_upgrade_schema منتقل می‌شوند).
                update_option( 'fws_cache_version', $current_ver + 1, false );
                // نسخهٔ ۲.۹.۳ — نسخهٔ و مموایز درون‌همان-درخواست هم صفر شود تا خواندن/نوشتنِ
                // بعد از بی‌اعتبارسازی، زیر «نسخهٔ قدیم» انجام نشود (توضیح کامل در
                // FWS_Prediction_Engine::reset_cache_version).
                if ( class_exists( 'FWS_Prediction_Engine' ) ) {
                        FWS_Prediction_Engine::reset_cache_version();
                }
        }

        /**
         * تشخیص نحوه ذخیره‌سازی سفارشات (HPOS یا Posts کلاسیک)
         */
        public static function get_orders_table_info() {
                global $wpdb;
                // رفع خطای مهلک نسخه ۲.۵.۰: بک‌اسلش مضاعف (\\) در فراخوانی کلاس باعث Parse Error کل افزونه می‌شد
                $is_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
                                        \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

                // نسخهٔ ۲.۱۲ (S-04): فهرست وضعیت‌های «پرداخت‌شده» از منبع رسمی ووکامرس
                // wc_get_is_paid_statuses() (فیلترپذیر با woocommerce_is_paid_statuses) —
                // فروشگاه‌هایی که وضعیت سفارشی (مثل «ارسال‌شده») به فهرست پرداخت‌شده‌ها اضافه
                // کرده‌اند، دیگر از ماینینج حذف نمی‌شوند.
                // نسخهٔ ۲.۱۲.۴ (F-04 — بحرانی): ووکامرس در هر دو موتور ذخیره‌سازی مقدار ستون
                // وضعیت را «با پسوند wc-» می‌نویسد (OrdersTableDataStore::update_order_from_object
                // ← get_post_status که پسوند wc- می‌گذارد؛ OrdersTableQuery هم ورودی را به
                // 'wc-' نرمال می‌کند). قبلاً تصور می‌شد HPOS بدون پسوند است؛ نتیجه: روی فروشگاه‌های
                // HPOS کوئری‌های ماینر صفر سفارش برمی‌گرداند و پاک‌سازی «قوانین کهنه» پس از هر
                // اجرا کل ماتریس وابستگی را می‌زداید. اکنون هر دو مسیر با پسوند ساخته می‌شوند.
                $paid_statuses = function_exists( 'wc_get_is_paid_statuses' )
                        ? (array) wc_get_is_paid_statuses()
                        : array( 'completed', 'processing' );
                $paid_statuses = array_values( array_unique( array_filter( array_map( 'sanitize_key', $paid_statuses ) ) ) );
                if ( empty( $paid_statuses ) ) {
                        $paid_statuses = array( 'completed', 'processing' );
                }
                // (F-04) هر دو موتور ذخیره‌سازی مقدار wc-prefixed نگه می‌دارند — مستندات غلطِ
                // «بدون پسوند در HPOS» با سورس واقعی WC اصلاح شد؛ $is_hpos فقط برای انتخاب جدول است.
                $status_list = implode(
                        ', ',
                        array_map(
                                static function ( $status ) {
                                        return "'wc-" . $status . "'";
                                },
                                $paid_statuses
                        )
                );

                if ( $is_hpos ) {
                        return array(
                                'table'      => "{$wpdb->prefix}wc_orders",
                                'id_col'     => 'id',
                                'status_col' => 'status',
                                'date_col'   => 'date_created_gmt',
                                // نسخهٔ ۲.۱۰.۴ (B-28): فقط سفارش واقعی؛ ردیف‌های refund هم‌جدول‌اند
                                'type_filter' => " AND type = 'shop_order'",
                                'status_list' => $status_list,
                        );
                } else {
                        return array(
                                'table'      => "{$wpdb->prefix}posts",
                                'id_col'     => 'ID',
                                'status_col' => 'post_status',
                                'date_col'   => 'post_date_gmt',
                                'type_filter' => " AND post_type = 'shop_order'",
                                'status_list' => $status_list,
                        );
                }
        }

        /**
         * بررسی وجود جدول Analytics ووکامرس (wc_order_product_lookup)
         * در صورت عدم وجود، ماینینگ بی‌صدا شکست می‌خورد؛ این گارد از آن جلوگیری می‌کند.
         * @return bool
         */
        public static function analytics_lookup_table_exists() {
                global $wpdb;
                static $exists = null;
                if ( null !== $exists ) {
                        return $exists;
                }
                $table  = $wpdb->prefix . 'wc_order_product_lookup';
                $exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
                return $exists;
        }

        /**
         * تحلیل دیتابیس با مکانیسم دسته‌ای بهینه‌شده، بدون N+1، کاهش ۵۰٪ پردازش، قفل مانع از تداخل و Bulk Insert سریع
         * @param int $lookback_days بازه زمانی آنالیز سفارشات (صفر = خواندن از پنل تنظیمات)
         * @return int تعداد قوانین همبستگی کشف‌شده
         */
        public static function run_market_basket_analysis( $lookback_days = 0 ) {
                // گارد پایداری: بدون جدول Analytics ووکامرس، هیچ تحلیلی ممکن نیست
                if ( ! self::analytics_lookup_table_exists() ) {
                        update_option( 'fws_analytics_missing', 1, false );
                        return 0;
                }
                delete_option( 'fws_analytics_missing' );

                if ( $lookback_days <= 0 ) {
                        $lookback_days = max( 7, (int) FWS_Settings::get( 'lookback_days', 90 ) );
                }

                $lock_key = 'fws_mining_lock';
                if ( get_transient( $lock_key ) ) {
                        return 0; // جلوگیری از تداخل و اجرای همزمان دو پروسه ماینینگ
                }
                set_transient( $lock_key, time(), 15 * MINUTE_IN_SECONDS );
                // نسخهٔ ۲.۱۲ (S-06): شروع ماینینگ دیگر بی‌صدا نیست
                FWS_Logger::info(
                        sprintf( 'Market-basket analysis started (lookback=%dd, min_support=%d).', $lookback_days, (int) FWS_Settings::get( 'min_support', 3 ) ),
                        array(),
                        'mining'
                );

                // BUG-07 fix (v2.8.1): if PHP is killed mid-run (FPM/proxy timeout, fatal, OOM) the
                // lock used to stay for 15 minutes and every retry was rejected. Release it on shutdown
                // when we did not reach the normal end of the run; also re-enable cache addition.
                $GLOBALS['fws_mining_finished'] = false;
                register_shutdown_function(
                        static function () use ( $lock_key ) {
                                if ( empty( $GLOBALS['fws_mining_finished'] ) ) {
                                        delete_transient( $lock_key );
                                        // نسخهٔ ۲.۱۰.۴: اگر PHP وسط اجرا کشته شود (FPM timeout، OOM،
                                        // fatal)، قوانین تا آن لحظه «ناقص» هستند؛ نشانهٔ ناقص ثبت شود
                                        // تا مدیر بداند و اجرای بعدی آن را پاک کند. مسیر موفق
                                        // fws_mining_finished=true می‌گذارد و وارد این شاخه نمی‌شود.
                                        update_option( 'fws_mining_incomplete', time(), false );
                                        if ( function_exists( 'wp_suspend_cache_addition' ) ) {
                                                wp_suspend_cache_addition( false );
                                        }
                                }
                        }
                );

                // تخصیص بهینه منابع سرور در طول پردازش
                if ( function_exists( 'wp_raise_memory_limit' ) ) {
                        wp_raise_memory_limit( 'admin' );
                }
                if ( ! ini_get( 'safe_mode' ) ) {
                        @set_time_limit( 300 );
                }
                if ( function_exists( 'wp_suspend_cache_addition' ) ) {
                        wp_suspend_cache_addition( true );
                }

                global $wpdb;
                $affinity_table = $wpdb->prefix . self::TABLE_AFFINITY;
                // تمیزکاری نسخه ۲.۹: max(100, intval(500)) گمراه‌کننده بود؛ بچ ثابت ۵۰۰ جفت در هر پاس
                $batch_size = 500;

                // نسخه ۲.۷ — سازگاری منطقه زمانی:
                // جداول Analytics ووکامرس (wc_order_product_lookup) با «ساعت سایت» پر می‌شوند؛
                // اما ستون‌های تاریخ جدول سفارشات (post_date_gmt / date_created_gmt) بر مبنای UTC هستند.
                $lookback_seconds = max( 7, (int) $lookback_days ) * DAY_IN_SECONDS;
                $cutoff_local     = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $lookback_seconds ); // برای جداول Analytics
                $cutoff_gmt       = gmdate( 'Y-m-d H:i:s', time() - $lookback_seconds );                  // برای جدول سفارشات

                $order_info = self::get_orders_table_info();

                // BUG-03 fix (v2.9.0): wc_order_product_lookup is filled by WooCommerce for EVERY
                // order (pending, cancelled, failed, refunded...) — the status filter only existed in
                // total_orders, so abandoned checkouts were counted as successful co-purchases and
                // the lift numerator/denominator were inconsistent. Every mining query below now
                // joins the orders table and keeps paid/successful orders only.
                // نسخهٔ ۲.۱۰.۴ (B-28): فیلتر «نوع» رکورد — در کلاسیک، پست‌های shop_order_refund هم
                // post_status = 'wc-completed' دارند و در HPOS ردیف‌های refund داخل همان جدول
                // wc_orders با type = 'shop_order_refund' نشسته‌اند؛ بدون این فیلتر، total_orders
                // (مخرج Lift) متورم می‌شد و همهٔ Liftها به نسبت (N+R)/N بزرگ‌نمایی می‌شدند.
                $type_filter = isset( $order_info['type_filter'] ) ? $order_info['type_filter'] : '';
                // نسخهٔ ۲.۱۲ (S-04): وضعیت‌ها از فهرست رسمی wc_get_is_paid_statuses() می‌آیند
                $order_join_freq = " INNER JOIN {$order_info['table']} AS wo ON wo.{$order_info['id_col']} = l.order_id AND wo.{$order_info['status_col']} IN ({$order_info['status_list']}){$type_filter}";

                // Count eligible orders within lookback window
                $total_orders = (int) $wpdb->get_var(
                        $wpdb->prepare(
                                "
            SELECT COUNT(*) FROM {$order_info['table']}
            WHERE {$order_info['status_col']} IN ({$order_info['status_list']})
              {$type_filter}
              AND {$order_info['date_col']} >= %s
        ",
                                $cutoff_gmt
                        )
                );
                if ( $total_orders < 1 ) {
                        $total_orders = 1;
                }

                $inserted_count = 0;
                $has_more       = true;

                // نسخه ۲.۹.۱ — باگ ۱: فلگ «اجرای کامل» + مهر زمانی شروع اجرا (ساعت سایت، سازگار با
                // ستون last_calculated که با current_time('mysql') نوشته می‌شود). قبلاً break گارد
                // حافظه مستقیم به delete_option('fws_mining_incomplete') می‌افتاد؛ یعنی نشان
                // «تحلیل ناقص» که چند خط قبل ثبت شده بود توسط همان اجرا پاک می‌شد و ادمین هرگز
                // آن را نمی‌دید. مهر شروع اجرا نیز مبنای جاروب قوانین کهنه پس از پایان موفق است.
                $run_started_at = current_time( 'mysql' );
                $run_completed  = true;

                // Local in-memory frequency cache to completely ELIMINATE N+1 queries
                // مقدار ۰ یعنی «فرکانس این محصول در بازه تحلیل یافت نشد» (سنتینل سلامت داده)
                $freq_cache = array();

                // Helper lambda for cached product order frequency (bounded by the exact lookback window)
                $get_freq = function ( $pid ) use ( $wpdb, &$freq_cache, $cutoff_local, $order_join_freq ) {
                        if ( isset( $freq_cache[ $pid ] ) ) {
                                return $freq_cache[ $pid ];
                        }
                        // BUG-03 fix (v2.9.0): count only orders with an eligible (paid/successful) status
                        $cnt                = (int) $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT COUNT(DISTINCT l.order_id) FROM {$wpdb->prefix}wc_order_product_lookup l{$order_join_freq} WHERE l.product_id = %d AND l.date_created >= %s",
                                        $pid,
                                        $cutoff_local
                                )
                        );
                        $freq_cache[ $pid ] = $cnt;
                        return $freq_cache[ $pid ];
                };

                // نسخه ۲.۷ — صفحه‌بندی کلیدی (Keyset) به‌جای OFFSET:
                // OFFSET هر دفعه کل تجمیع سنگین GROUP BY را از نو اجرا می‌کرد (رفتار O(n²))؛
                // با کلید (pid1,pid2) فقط داده‌های جدید هر دفعه پیمایش می‌شود.
                $last_pid1 = 0;
                $last_pid2 = 0;

                while ( $has_more ) {
                        $query = "
                SELECT 
                    item1.product_id AS pid1,
                    item2.product_id AS pid2,
                    COUNT(DISTINCT item1.order_id) AS pair_orders
                FROM {$wpdb->prefix}wc_order_product_lookup item1
                INNER JOIN {$wpdb->prefix}wc_order_product_lookup item2 
                    ON item1.order_id = item2.order_id 
                    AND item1.product_id < item2.product_id
                INNER JOIN {$order_info['table']} AS wo
                    ON wo.{$order_info['id_col']} = item1.order_id
                   AND wo.{$order_info['status_col']} IN ({$order_info['status_list']})
                   {$type_filter}
                WHERE item1.product_id > 0
                  AND item2.product_id > 0
                  AND item1.date_created >= %s
                  AND item2.date_created >= %s
                  AND (item1.product_id > %d OR (item1.product_id = %d AND item2.product_id > %d))
                GROUP BY item1.product_id, item2.product_id
                HAVING pair_orders >= %d
                ORDER BY item1.product_id ASC, item2.product_id ASC
                LIMIT %d
            ";

                        $results = $wpdb->get_results(
                                $wpdb->prepare(
                                        $query,
                                        $cutoff_local,
                                        $cutoff_local,
                                        $last_pid1,
                                        $last_pid1,
                                        $last_pid2,
                                        max( 1, (int) FWS_Settings::get( 'min_support', 3 ) ),
                                        $batch_size
                                )
                        );

                        if ( empty( $results ) ) {
                                $has_more = false;
                                break;
                        }

                        // Bulk prefetch uncached product frequencies in a single SQL query
                        $uncached_pids = array();
                        foreach ( $results as $row ) {
                                $p1_temp = (int) $row->pid1;
                                $p2_temp = (int) $row->pid2;
                                if ( ! isset( $freq_cache[ $p1_temp ] ) ) {
                                        $uncached_pids[ $p1_temp ] = true;
                                }
                                if ( ! isset( $freq_cache[ $p2_temp ] ) ) {
                                        $uncached_pids[ $p2_temp ] = true;
                                }
                        }
                        if ( ! empty( $uncached_pids ) ) {
                                $pid_list  = implode( ',', array_map( 'intval', array_keys( $uncached_pids ) ) );
                                // BUG-03 fix (v2.9.0): same eligible-status filter as the single-product lambda
                                // نسخهٔ ۲.۱۰.۴ (B-28): فیلتر type هم اضافه شد (هم‌سو با بقیهٔ کوئری‌ها)
                                $freq_rows = $wpdb->get_results(
                                        $wpdb->prepare(
                                                "SELECT l.product_id, COUNT(DISTINCT l.order_id) AS cnt 
                     FROM {$wpdb->prefix}wc_order_product_lookup l
                     INNER JOIN {$order_info['table']} AS wo ON wo.{$order_info['id_col']} = l.order_id AND wo.{$order_info['status_col']} IN ({$order_info['status_list']}){$type_filter}
                     WHERE l.product_id IN ({$pid_list}) AND l.date_created >= %s 
                     GROUP BY l.product_id",
                                                $cutoff_local
                                        )
                                );
                                if ( ! empty( $freq_rows ) ) {
                                        foreach ( $freq_rows as $frow ) {
                                                $freq_cache[ (int) $frow->product_id ] = (int) $frow->cnt;
                                        }
                                }
                                // محصولات بدون هیچ سفارشی در بازه: سنتینل ۰ (قوانین ساخته نمی‌شوند)
                                foreach ( $uncached_pids as $upid => $_ ) {
                                        if ( ! isset( $freq_cache[ $upid ] ) ) {
                                                $freq_cache[ $upid ] = 0;
                                        }
                                }
                        }

                        $rows_to_insert = array();
                        $now_mysql      = current_time( 'mysql' );
                        $min_confidence = floatval( FWS_Settings::get( 'min_confidence', 60 ) );

                        foreach ( $results as $row ) {
                                $p1         = (int) $row->pid1;
                                $p2         = (int) $row->pid2;
                                $pair_count = (int) $row->pair_orders;

                                $freq1 = $get_freq( $p1 );
                                $freq2 = $get_freq( $p2 );

                                // نسخه ۲.۷ — سلامت آماری: به‌جای فرض freq=1 (که اطمینان ساختگی ۱۰۰٪ می‌ساخت)،
                                // جفتی که فرکانس قابل‌اعتماد ندارد کلاً رد می‌شود.
                                if ( $freq1 < 1 || $freq2 < 1 ) {
                                        continue;
                                }

                                $lift = round( ( $pair_count * $total_orders ) / ( $freq1 * $freq2 ), 4 );

                                // Symmetric expansion: Rule A -> B
                                $conf1 = round( ( $pair_count / $freq1 ) * 100, 2 );
                                if ( $conf1 >= $min_confidence ) {
                                        $rows_to_insert[] = array(
                                                'src'  => $p1,
                                                'rec'  => $p2,
                                                'cnt'  => $pair_count,
                                                'conf' => $conf1,
                                                'lift' => $lift,
                                        );
                                }

                                // Symmetric expansion: Rule B -> A
                                $conf2 = round( ( $pair_count / $freq2 ) * 100, 2 );
                                if ( $conf2 >= $min_confidence ) {
                                        $rows_to_insert[] = array(
                                                'src'  => $p2,
                                                'rec'  => $p1,
                                                'cnt'  => $pair_count,
                                                'conf' => $conf2,
                                                'lift' => $lift,
                                        );
                                }
                        }

                        // High-Performance Bulk Multi-Row Insert (Turns hundreds of DB queries into 1 bulk statement)
                        if ( ! empty( $rows_to_insert ) ) {
                                $placeholders = array();
                                $values       = array();
                                foreach ( $rows_to_insert as $item ) {
                                        $placeholders[] = '(%d, %d, %d, %f, %f, %s)';
                                        $values[]       = $item['src'];
                                        $values[]       = $item['rec'];
                                        $values[]       = $item['cnt'];
                                        $values[]       = $item['conf'];
                                        $values[]       = $item['lift'];
                                        $values[]       = $now_mysql;
                                }

                                $bulk_sql = "INSERT INTO {$affinity_table} 
                    (source_product_id, recommended_product_id, co_occurrence, confidence_score, lift_score, last_calculated) 
                    VALUES " . implode( ', ', $placeholders ) . '
                    ON DUPLICATE KEY UPDATE 
                        co_occurrence = VALUES(co_occurrence),
                        confidence_score = VALUES(confidence_score),
                        lift_score = VALUES(lift_score),
                        last_calculated = VALUES(last_calculated)';

                                $wpdb->query( $wpdb->prepare( $bulk_sql, $values ) );
                                // نسخهٔ ۲.۱۰.۴: شمارندهٔ صادق — قبلاً count($rows_to_insert) تعداد
                                // «ارسال‌شده» را می‌شمارد حتی اگر INSERT بی‌اثر می‌بود؛ rows_affected
                                // تعداد واقعی ردیف‌های نوشته/به‌روزرسانی‌شده را برمی‌گرداند.
                                $inserted_count += (int) $wpdb->rows_affected;
                        }

                        $last_row  = end( $results );
                        $last_pid1 = (int) $last_row->pid1;
                        $last_pid2 = (int) $last_row->pid2;
                        if ( count( $results ) < $batch_size ) {
                                $has_more = false;
                        }

                        // Memory Safety Guard: If script consumes over 80% of max allowed PHP memory, break gracefully
                        // نسخهٔ ۲.۱۰.۳ — قلب‌تپندهٔ قفل: به‌روزرسانی TTL قفل در هر بچ، تا اجرای قانونیِ
                        // طولانی (فروشگاه‌های چندصد‌هزار سفارشی) وسط کار قفلش منقضی نشود و اجرای
                        // کرون بعدی روی همان جدول موازی شروع نکند. یک UPDATE سبک در هر بچِ ۵۰۰تایی.
                        set_transient( $lock_key, time(), 15 * MINUTE_IN_SECONDS );

                        if ( function_exists( 'memory_get_usage' ) && memory_get_usage( true ) > ( 0.80 * self::get_memory_limit_bytes() ) ) {
                                update_option( 'fws_mining_incomplete', time(), false );
                                $run_completed = false;
                                // نسخهٔ ۲.۱۲ (S-06): توقف ناقص دیگر بی‌صدا نیست — قوانین تا اجرای کامل بعدی
                                // ناقص‌اند و مدیر باید بداند.
                                FWS_Logger::warning(
                                        sprintf( 'Mining aborted by memory guard at %d%% of memory_limit — rules are INCOMPLETE until the next full run.', (int) round( ( memory_get_usage( true ) / self::get_memory_limit_bytes() ) * 100 ) ),
                                        array(),
                                        'mining'
                                );
                                break;
                        }

                        // CPU Throttling: Micro-pause between batches to keep CPU load below 20% on shared hosting
                        usleep( 15000 ); // 15ms breath pause (تمیزکاری نسخه ۲.۹: if (true) زائد حذف شد)
                }

                if ( function_exists( 'wp_suspend_cache_addition' ) ) {
                        wp_suspend_cache_addition( false );
                }
                delete_transient( $lock_key );
                $GLOBALS['fws_mining_finished'] = true;

                if ( $run_completed ) {
                        // نسخه ۲.۹.۱ — باگ ۲: جاروب قوانین کهنه. قبلاً INSERT..ON DUPLICATE KEY UPDATE فقط
                        // به‌روزرسانی/افزودن می‌کرد؛ قوانینِ محاسبه‌شده در بازه/آستانه‌های قدیمی (مثلاً قواعد
                        // lookback ۳۶۵ روز، پیش از تغییر تنظیم به ۳۰ روز) در جدول باقی می‌ماندند و موتور
                        // همچنان آن‌ها را پیشنهاد می‌داد — در تضاد با تنظیم «بازه تحلیل سفارشات». پس از یک
                        // اجرای کامل، هر ردیفی که در همین اجرا بازنویسی یا ساخته نشده باشد کهنه است.
                        // مقایسه اکید (<) است تا ردیف‌های همین اجرا که در همان ثانیه نوشته شده‌اند حذف نشوند.
                        // نسخهٔ ۲.۱۰.۳ — حذفِ دسته‌ای: تغییر lookback (مثلاً ۳۶۵→۳۰ روز) قبلاً یک DELETE
                        // بی‌کران اجرا می‌کرد که روی جدول چندصدهزار ردیفی قفل طولانی و تأخیر ریلیکیشن
                        // می‌ساخت؛ حالا هر پاس حداکثر ۵۰۰۰ ردیف حذف می‌کند (کرون روزانه ادامه‌اش را می‌دهد).
                        $stale_start   = microtime( true );
                        $stale_batch   = 5000;
                        do {
                                $stale_deleted = (int) $wpdb->query(
                                        $wpdb->prepare(
                                                "DELETE FROM {$affinity_table} WHERE last_calculated < %s LIMIT %d",
                                                $run_started_at,
                                                $stale_batch
                                        )
                                );
                                if ( $stale_deleted < $stale_batch ) {
                                        break;
                                }
                                if ( ( microtime( true ) - $stale_start ) > 25 ) {
                                        break; // بودجهٔ زمانی؛ باقیمانده در اجرای بعدی پاک می‌شود
                                }
                                usleep( 20000 );
                        } while ( true );
                        delete_option( 'fws_mining_incomplete' ); // اجرا تا پایان سالم رسید — نشان ناقص صفر می‌شود
                }

                // نسخهٔ ۲.۱۲ (S-06): پایان ماینینگ با شمارندهٔ واقعی ردیف‌ها در لاگ ووکامرس
                FWS_Logger::info(
                        sprintf( 'Market-basket analysis finished (complete=%s, affected rule rows=%d).', $run_completed ? 'yes' : 'no', $inserted_count ),
                        array(),
                        'mining'
                );

                self::purge_cache();
                return $inserted_count;
        }
}
