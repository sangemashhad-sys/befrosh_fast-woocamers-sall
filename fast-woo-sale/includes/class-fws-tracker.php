<?php
/**
 * Class FWS_Tracker
 * موتور ردیابی و گزارش درآمد افزونه — نسخه ۲.۹ «حلقه گمشده اندازه‌گیری»
 *
 * مأموریت: ثبت قیف تبدیل هر ویجت (نمایش → افزودن به سبد → خرید) و انتساب درآمد
 * هر سفارش به ویجت مبدا، تا مدیر بالاخره بفهمد «این افزونه چقدر برای او فروش آورده».
 *
 * اصول حریم خصوصی و کنترل (قانون طلایی نسخه ۲.۹):
 *  ۱) کلید اصلی خاموش/روشن ردیابی (tracking_enable) — خاموش = هیچ ردیفی ثبت نمی‌شود
 *  ۲) ردیابی هر ویجت از گیت خود ویجت (کلیدهای نسخه ۲.۸) پیروی می‌کند؛ ویجت خاموش = هیچ نمایشی ثبت نمی‌شود
 *  ۳) ناشناس‌سازی IP پیش‌فرض (آخرین بایت IPv4 / ۸۰ بیت IPv6 قبل از هش حذف می‌شود)
 *  ۴) هش سشن یک‌طرفه (md5) است و هیچ‌گاه IP خام یا شناسه شخصی ذخیره نمی‌شود
 *  ۵) ربات‌ها و مدیران فروشگاه (قابل تنظیم) ثبت نمی‌شوند
 *  ۶) پاکسازی خودکار روزانه رخدادهای قدیمی‌تر از مدت نگهداری انتخابی
 *  ۷) فیلتر توسعه‌دهندگان: fws_tracking_enabled
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Tracker {

    const TABLE_EVENT     = 'fws_events';
    const DB_VERSION_KEY  = 'fws_db_version';
    // نسخهٔ ۲.۱۰.۴ — ارتقای نسخهٔ شِما برای افزودن ایندکس widget_time (widget, event_time)
    const DB_VERSION      = '2.10.4';

    /**
     * بازه فشرده‌سازی ردیف‌های نمایش (ساعت):
     * نسخهٔ ۲.۱۰.۱ — پیش‌تر برای «هر رندرِ هر ویجت» یک ردیف INSERT می‌شد؛ در فروشگاه پرتردید
     * با نگهداری ۱۸۰ روزه به میلیون‌ها ردیف می‌رسید. حالا هر (سشن، ویجت) در بازه ۶ ساعته فقط
     * یک ردیف نمایش ثبت می‌کند — رفرش صفحه و جابه‌جایی چندصفحه‌ایِ یک کاربر دیگر ردیف تولید
     * نمی‌کند، ولی معنای آماریِ «نمایش» (یک بازدیدکننده واقعی) دقیق‌تر هم می‌شود.
     * رویدادهای کم‌حجم (افزودن به سبد / کوپن / خرید) بدون تغییر ذخیره می‌شوند.
     */
    const IMPRESSION_DEDUP_HOURS = 6;

    /** @var array|null کش ردیف‌های ثبت‌شده در همین درخواست (ضد تکرار) */
    private static $request_logged = array();

    /** @var bool|null */
    private static $enabled = null;

    /** @var bool|null */
    private static $table_ok = null;

    /** @var string|null */
    private static $session_hash = null;

    /* ───────────────────────── نقشه ویجت‌ها ───────────────────────── */

    /**
     * نگاشت کلیدهای تنظیمات ویجت‌ها (نسخه ۲.۸) به شناسه کوتاه ردیابی
     * @return array
     */
    private static function key_to_slug() {
        return array(
            'enable_widget_product'  => 'product',
            'enable_widget_cart'     => 'cart',
            'enable_widget_thankyou' => 'thankyou',
            'enable_widget_shipping' => 'shipping',
            'enable_widget_account'  => 'account',
            'enable_search_banner'   => 'search',
            'enable_exit_intent'     => 'exit_modal',
        );
    }

    /**
     * برچسب فارسی هر شناسه ویجت برای گزارش پنل مدیریت
     * @return array
     */
    public static function widget_labels() {
        return array(
            'product'    => 'باکس پکیج هوشمند (صفحه محصول)',
            'cart'       => 'پیشنهادات مکمل (سبد خرید)',
            'thankyou'   => 'آپسل یک‌کلیکی (صفحه تشکر)',
            'shipping'   => 'نوار ارسال رایگان (پرکننده‌ها)',
            'account'    => 'پیش‌بینی خرید بعدی (حساب کاربری)',
            'search'     => 'بنر پیشنهاد (نتایج جستجو)',
            'exit_modal' => 'مودال خروج (Exit-Intent)',
            'shortcode'  => 'شورت‌کدها (انتخاب صریح مدیر)',
            'unknown'    => 'منبع نامشخص',
        );
    }

    /**
     * تبدیل کلید تنظیمات (یا شناسه کوتاه) به شناسه استاندارد ردیابی
     * @param string $key_or_slug
     * @return string
     */
    public static function resolve_slug($key_or_slug) {
        $key_or_slug = sanitize_key((string) $key_or_slug);
        $map = self::key_to_slug();
        if (isset($map[$key_or_slug])) {
            return $map[$key_or_slug];
        }
        return array_key_exists($key_or_slug, self::widget_labels()) ? $key_or_slug : '';
    }

    /* ───────────────────────── کلیدها و گیت‌ها ───────────────────────── */

    /**
     * کلید اصلی ردیابی — خاموش بودن آن یعنی هیچ ردیفی در هیچ مسیری ثبت نمی‌شود
     * @return bool
     */
    public static function tracking_enabled() {
        if (null === self::$enabled) {
            $enabled = ('yes' === FWS_Settings::get('tracking_enable', 'yes'));
            self::$enabled = apply_filters('fws_tracking_enabled', $enabled);
        }
        return self::$enabled;
    }

    /**
     * آیا این بازدیدکننده نباید ثبت شود؟ (ربات / مدیر فروشگاه در صورت تنظیم)
     * @return bool
     */
    private static function should_exclude_visitor() {
        if (defined('DOING_CRON') && DOING_CRON) return true;
        if (defined('WP_CLI') && WP_CLI) return true;
        if (self::is_bot()) return true;
        if ('yes' === FWS_Settings::get('tracking_exclude_admins', 'yes') && current_user_can('manage_woocommerce')) {
            return true;
        }
        return false;
    }

    /**
     * تشخیص ساده ربات‌ها از روی User-Agent
     * @return bool
     */
    private static function is_bot() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ('' === $ua) {
            return true;
        }
        $needles = array('bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python', 'headless',
            'lighthouse', 'pagespeed', 'monitor', 'pingdom', 'uptime', 'fetcher', 'archiver', 'spammer');
        foreach ($needles as $needle) {
            if (false !== strpos($ua, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * هش یک‌طرفه سشن بازدیدکننده با ناشناس‌سازی IP (قابل خاموش‌کردن برای تحلیل دقیق‌تر)
     * @return string
     */
    public static function session_hash() {
        if (null !== self::$session_hash) {
            return self::$session_hash;
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        // نسخهٔ ۲.۱۲.۴ (F-09): منبع IP از REMOTE_ADDR خام به هلپر مشترک CDN-آگاه
        // (همان منطق B-41/R1: پروکسی‌های معتبر پنل + پیمایش راست‌به‌چپ XFF) تغییر کرد؛
        // پشت CDN (آروان/کلودفلر) همهٔ بازدیدکنندگان یک REMOTE_ADDR مشترک داشتند و
        // پس از ناشناس‌سازی /24، هشِ سشنِ هزاران بازدیدکننده واقعی یکی می‌شد —
        // فشرده‌سازی نمایش، کиф تبدیل و شمارش سشن متمایز A/B همه خراب می‌شد.
        if (class_exists('FWS_Ajax_Handler')) {
            $ip = (string) FWS_Ajax_Handler::get_client_ip();
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        }

        if ('yes' === FWS_Settings::get('tracking_anonymize_ip', 'yes')) {
            $bin = @inet_pton($ip);
            if (false !== $bin && 4 === strlen($bin)) {
                // IPv4: صفر کردن آخرین بایت (سطح /24) — کافی برای تفکیک سشن، بدون ذخیره IP کامل
                $bin[3] = "\x00";
                $ip = bin2hex($bin);
            } elseif (false !== $bin && 16 === strlen($bin)) {
                // IPv6: صفر کردن ۸۰ بیت پایین (سطح /48)
                for ($i = 6; $i < 16; $i++) {
                    $bin[$i] = "\x00";
                }
                $ip = bin2hex($bin);
            }
        }

        self::$session_hash = md5($ip . '|' . $ua);
        return self::$session_hash;
    }

    /* ───────────────────────── نصب، ارتقا و پاکسازی ───────────────────────── */

    /**
     * ساخت جدول رخدادها + زمان‌بندی پاکسازی روزانه (در activation و ارتقای خودکار)
     */
    public static function maybe_upgrade() {
        $prev_version = (string) get_option(self::DB_VERSION_KEY, '');
        if (self::DB_VERSION === $prev_version) {
            return;
        }
        self::create_tables();

        // نسخهٔ ۲.۱۰.۱ — مهاجرت یک‌بارهٔ بازه نگهداری:
        // پیش‌فرض قدیمی ۱۸۰ روز برای فروشگاه‌های پرتردیدِ هاست اشتراکی سنگین است
        // (توصیهٔ بازبینی امنیتی: ۳۰ تا ۶۰ روز). فقط وقتی مقدار ذخیره‌شده همان
        // پیش‌فرض قدیمی (۱۸۰) باشد به ۶۰ کاهش می‌یابد؛ انتخاب آگاهانهٔ مدیری که
        // مقدار دیگری ذخیره کرده باشد دست‌نخورده می‌ماند.
        if ('' !== $prev_version && version_compare($prev_version, '2.10.1', '<')) {
            if (180 === (int) FWS_Settings::get('tracking_retention_days', 180)) {
                FWS_Settings::persist_key('tracking_retention_days', 60);
            }
        }

        update_option(self::DB_VERSION_KEY, self::DB_VERSION, true);

        if (!wp_next_scheduled('fws_daily_tracking_cleanup_event')) {
            wp_schedule_event(time() + 3600, 'daily', 'fws_daily_tracking_cleanup_event');
        }
    }

    /**
     * نسخهٔ ۲.۱۰.۴ (B-25): خودترمیمی زمان‌بند کرون پاکسازی.
     * قبلاً زمان‌بندی فقط داخل maybe_upgrade (مسیرِ گیت‌خورده به تغییر DB_VERSION) اجرا
     * می‌شد؛ اگر رویداد به هر دلیل (پلاگین‌های مدیریت کرون، wp_clear_scheduled_hook
     * افزونهٔ سوم، تغییر نسخهٔ دستی فایل‌ها) پاک می‌شد، تا ارتقای بعدیِ شِما هیچ‌گاه
     * دوباره ساخته نمی‌شد و پاکسازی روزانه + قفل‌شدن برنده‌های A/B (که روی همین هوک
     * سوارند) متوقف می‌ماند. این چک روی init اجرا می‌شود؛ wp_next_scheduled آرایهٔ
     * کرونِ autoload شده را در حافظه می‌خواند — هیچ کوئری اضافه‌ای به درخواست نمی‌افزاید.
     *
     * @return void
     */
    public static function ensure_cleanup_cron() {
        if (!wp_next_scheduled('fws_daily_tracking_cleanup_event')) {
            wp_schedule_event(time() + 300, 'daily', 'fws_daily_tracking_cleanup_event');
        }
    }

    /**
     * ساخت جدول fws_events با dbDelta
     */
    public static function create_tables() {
        global $wpdb;
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $table   = $wpdb->prefix . self::TABLE_EVENT;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_time DATETIME NOT NULL,
            event_type VARCHAR(20) NOT NULL DEFAULT '',
            widget VARCHAR(30) NOT NULL DEFAULT '',
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            variant VARCHAR(20) NOT NULL DEFAULT '',
            session_hash CHAR(32) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            revenue DECIMAL(16,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY widget_type (widget, event_type),
            KEY widget_time (widget, event_time),
            KEY event_time (event_time),
            KEY order_id (order_id),
            KEY session_widget_time (session_hash, widget, event_time)
        ) {$charset};";
        dbDelta($sql);
        // نسخهٔ ۲.۱۰.۳ — کش وضعیت جدول (کلاس و آبجکت‌کش) ریست شود
        self::$table_ok = null;
        wp_cache_delete('fws_events_table_ok', 'fws_db_checks');
    }

    /**
     * بررسی (و در صورت نیاز خودترمیمی) وجود جدول — حداکثر یک بار در هر درخواست
     * @return bool
     */
    private static function ensure_table() {
        if (null !== self::$table_ok) {
            return self::$table_ok;
        }
        // نسخهٔ ۲.۱۰.۳ (B-15): نتیجهٔ SHOW TABLES در آبجکت‌کش با TTL پنج‌دقیقه‌ای کش می‌شود.
        // روی هاست‌های بدون آبجکت‌کش رفتار قبلی (حداکثر یک کوئری در هر درخواست) حفظ می‌شود؛
        // روی هاست‌های Redis/Memcached، کوئری SHOW TABLES از هر درخواستِ فرانت حذف می‌شود.
        $cached = wp_cache_get('fws_events_table_ok', 'fws_db_checks');
        if ('1' === $cached || '0' === $cached) {
            self::$table_ok = ('1' === $cached);
            return self::$table_ok;
        }
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE_EVENT;
        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        if (!$exists) {
            self::create_tables();
            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        }
        self::$table_ok = $exists;
        wp_cache_set('fws_events_table_ok', $exists ? '1' : '0', 'fws_db_checks', 5 * MINUTE_IN_SECONDS);
        return $exists;
    }

    /**
     * هوک‌های همیشگی (خرید، متای آیتم سفارش، کرون پاکسازی)
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'));
        // نسخهٔ ۲.۱۰.۴ (B-25): خودترمیمی زمان‌بند کرون — مستقل از مسیر ارتقای شِما
        add_action('init', array(__CLASS__, 'ensure_cleanup_cron'), 30);
        add_action('fws_daily_tracking_cleanup_event', array(__CLASS__, 'cleanup_old_events'));

        // انتساب خرید: اولین گذار سفارش به وضعیت قابل‌اتکا → یک بار برای همیشه
        // نسخهٔ ۲.۱۲ (S-04): جایگزینی هوک‌های هاردکد processing/completed با هوک عمومی
        // گذار وضعیت + فهرست رسمی wc_get_is_paid_statuses() — فروشگاه‌هایی که با فیلتر
        // رسمی woocommerce_is_paid_statuses وضعیت سفارشی (مثل «ارسال‌شده») را
        // «پرداخت‌شده» تعریف می‌کنند هم پوشش داده می‌شوند؛ گارد $from از فراخوانی
        // تکراری در زنجیرهٔ processing→shipped→completed جلوگیری می‌کند.
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'maybe_attribute_on_status_change'), 10, 4);

        // انتقال متای منبع از دیتای آیتم سبد به متای آیتم سفارش (سازگار با HPOS)
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'attach_order_item_meta'), 10, 4);
    }

    /**
     * پاکسازی رخدادهای قدیمی‌تر از مدت نگهداری انتخابی مدیر
     * نسخهٔ ۲.۱۰.۳ — حذفِ دسته‌ای: DELETE بی‌کران روی جدول چندمیلیونی، قفل طولانی و
     * تأخیر ریلیکیشن می‌سازد؛ حالا هر پاس حداکثر ۵۰۰۰ ردیف حذف می‌کند و با بودجهٔ
     * زمانی ۲۰ ثانیه‌ای، مابقی پاکسازی به اجرای روزانهٔ بعدی کرون واگذار می‌شود.
     */
    public static function cleanup_old_events() {
        global $wpdb;
        if (!self::ensure_table()) {
            return;
        }
        $days   = max(30, min(365, (int) FWS_Settings::get('tracking_retention_days', 60)));
        $table  = $wpdb->prefix . self::TABLE_EVENT;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        $batch     = 5000;
        $started   = microtime(true);
        do {
            $deleted = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE event_time < %s LIMIT %d",
                $cutoff,
                $batch
            ));
            if ($deleted < $batch) {
                break; // همهٔ ردیف‌های منقضی همین پاس پاک شدند
            }
            if ((microtime(true) - $started) > 20) {
                break; // بودجهٔ زمانی کرون؛ ادامه فردا
            }
            usleep(20000); // ۲۰ms نفس بین بچ‌ها
        } while (true);
    }

    /* ───────────────────────── ثبت رخدادها ───────────────────────── */

    /**
     * درج خام یک رخداد در جدول
     * نسخهٔ ۲.۱۱ (B-24): پارامتر اختیاری $identity برای مسیر انتساب خرید — ردیف‌های خرید
     * هویت خود را از «سفارش» می‌گیرند نه از درخواست جاری؛ وقتی ادمین در wp-admin وضعیت
     * سفارش را عوض می‌کند (COD → processing)، قبلاً session_hash = هش ادمین و user_id =
     * شناسه ادمین ثبت می‌شد و آمار سشن/کاربر آلوده می‌شد.
     *
     * @param string $type       impression | add_to_cart | purchase | coupon
     * @param string $widget     شناسه کوتاه ویجت
     * @param int    $product_id
     * @param string $variant    '' | 'A' | 'B'
     * @param int    $order_id
     * @param float  $revenue
     * @param array|null $identity در صورت ارسال: ['session_hash' => '', 'user_id' => int]
     */
    private static function insert_event($type, $widget, $product_id = 0, $variant = '', $order_id = 0, $revenue = 0.0, $identity = null) {
        global $wpdb;
        if (!self::ensure_table() || '' === $widget || '' === $type) {
            return false;
        }
        $session_hash = is_array($identity) && isset($identity['session_hash'])
            ? (string) $identity['session_hash']
            : self::session_hash();
        $user_id      = is_array($identity) && isset($identity['user_id'])
            ? (int) $identity['user_id']
            : get_current_user_id();
        $inserted = $wpdb->insert(
            $wpdb->prefix . self::TABLE_EVENT,
            array(
                'event_time'   => current_time('mysql', true), // UTC — هم‌راستا با cutoff های ماینر
                'event_type'   => sanitize_key($type),
                'widget'       => sanitize_key($widget),
                'product_id'   => absint($product_id),
                'variant'      => sanitize_text_field($variant),
                'session_hash' => $session_hash,
                'user_id'      => $user_id,
                'order_id'     => absint($order_id),
                'revenue'      => (float) $revenue,
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%f')
        );

        // نسخهٔ ۲.۱۲.۴ (F-10): نتیجهٔ INSERT نادیده گرفته نمی‌شود؛ شکست (لاک قفل در
        // انفجار تسویه، پر شدن دیسک و…) قبلاً بی‌صدا رد می‌شد و فراخوانندهٔ خرید حتی
        // متا را «منتسب» ثبت می‌کرد — درآمد آن ردیف برای همیشه از گزارش‌ها می‌افتاد.
        if (false === $inserted) {
            FWS_Logger::error('insert_event failed for ' . $type . '/' . $widget . ': ' . $wpdb->last_error, array(), 'tracker');
            return false;
        }
        return true;
    }

    /**
     * آیا همین (سشن، ویجت) در بازه فشرده‌سازیِ نمایش، قبلاً ردیف نمایش ثبت کرده است؟
     * کوئری نقطه‌ای با ایندکس اختصاصی session_widget_time — حتی روی جدول چندمیلیونی ارزان است.
     *
     * @param string $slug شناسه کوتاه ویجت
     * @return bool
     */
    private static function impression_recent($slug) {
        global $wpdb;
        if (!self::ensure_table()) {
            return false; // جدول در دسترس نیست؛ مسیر عادی خودش وضعیت را مدیریت می‌کند
        }
        $table  = $wpdb->prefix . self::TABLE_EVENT;
        $window = gmdate('Y-m-d H:i:s', time() - self::IMPRESSION_DEDUP_HOURS * HOUR_IN_SECONDS);
        $dup    = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE session_hash = %s AND widget = %s AND event_type = 'impression' AND event_time >= %s
             LIMIT 1",
            self::session_hash(),
            sanitize_key($slug),
            $window
        ));
        return !empty($dup);
    }

    /**
     * ثبت نمایش ویجت — فقط زمانی که ویجت واقعاً محتوایی رندر کرده است
     * از gated_output نمایش‌ها فراخوانی می‌شود؛ مودال خروج استثناست (از JS ثبت می‌شود)
     *
     * @param string $widget_key کلید تنظیمات یا شناسه کوتاه ویجت
     * @param int    $product_id محصول مبدأ (در صورت وجود)
     */
    public static function log_impression($widget_key, $product_id = 0) {
        if (!self::tracking_enabled() || self::should_exclude_visitor()) {
            return;
        }
        $slug = self::resolve_slug($widget_key);
        if ('' === $slug) {
            return;
        }
        $dedup_key = 'imp_' . $slug;
        if (isset(self::$request_logged[$dedup_key])) {
            return; // چند رندر یک ویجت در یک درخواست = یک نمایش
        }
        self::$request_logged[$dedup_key] = true;

        // نسخهٔ ۲.۱۰.۱ — فشرده‌سازی ردیف‌های نمایش (هر سشن×ویجت در بازه ۶ ساعته = یک ردیف)
        if (self::impression_recent($slug)) {
            return;
        }

        $variant = class_exists('FWS_AB_Testing') ? FWS_AB_Testing::effective_variant($slug) : '';
        self::insert_event('impression', $slug, absint($product_id), $variant);
    }

    /**
     * ثبت افزودن موفق به سبد از مسیر AJAX — با اعتبارسنجی سخت نام ویجت
     * @param string $widget       شناسه کوتاه ویجت (از دیتای دکمه فرانت)
     * @param array  $product_ids
     */
    public static function log_add_to_cart($widget, $product_ids) {
        if (!self::tracking_enabled() || self::should_exclude_visitor()) {
            return;
        }
        $slug = self::resolve_slug($widget);
        if ('' === $slug) {
            $slug = 'unknown'; // JS کش‌شده قدیمی که هنوز زمینه ویجت نمی‌فرستد
        }
        $variant = class_exists('FWS_AB_Testing') ? FWS_AB_Testing::effective_variant($slug) : '';
        $count   = 0;
        foreach ((array) $product_ids as $pid) {
            if ($count >= 10) break; // هم‌سو با سقف پکیج
            $pid = absint($pid);
            if ($pid > 0) {
                self::insert_event('add_to_cart', $slug, $pid, $variant);
                $count++;
            }
        }
    }

    /**
     * ثبت نمایش واقعی مودال خروج از سمت JS (تنها مسیر ثبت نمایش مودال، بدون تکرار)
     */
    public static function log_exit_modal_shown() {
        if (!self::tracking_enabled() || self::should_exclude_visitor()) {
            return;
        }
        if (isset(self::$request_logged['exit_shown'])) {
            return;
        }
        self::$request_logged['exit_shown'] = true;

        // نسخهٔ ۲.۱۰.۱ — همان فشرده‌سازی نمایش‌ها برای مودال خروج (بیکن JS با هر ورود به سبد/تسویه)
        if (self::impression_recent('exit_modal')) {
            return;
        }

        $variant = class_exists('FWS_AB_Testing') ? FWS_AB_Testing::effective_variant('exit_modal') : '';
        self::insert_event('impression', 'exit_modal', 0, $variant);
    }

    /**
     * ثبت اعمال موفق کوپن مودال خروج (تعامل سطح بالای مودال)
     */
    public static function log_coupon_apply() {
        if (!self::tracking_enabled() || self::should_exclude_visitor()) {
            return;
        }
        $variant = class_exists('FWS_AB_Testing') ? FWS_AB_Testing::effective_variant('exit_modal') : '';
        self::insert_event('coupon', 'exit_modal', 0, $variant);
    }

    /* ───────────────────────── انتساب خرید ───────────────────────── */

    /**
     * انتقال متای منبع (fws_source / fws_variant) از دیتای آیتم سبد به آیتم سفارش
     * هوک: woocommerce_checkout_create_order_line_item
     *
     * @param WC_Order_Item_Product $item
     * @param string $cart_item_key
     * @param array  $values
     * @param WC_Order $order
     */
    public static function attach_order_item_meta($item, $cart_item_key, $values, $order) {
        if (!empty($values['fws_source'])) {
            // نسخه ۲.۱۰.۲ (B-09): متای مخفی (پیشوند _) — متای بدون «_» در ایمیل سفارش و
            // صفحهٔ مشاهده سفارش مشتری نمایش داده می‌شد.
            $item->add_meta_data('_fws_source', sanitize_key((string) $values['fws_source']), true);
        }
        if (!empty($values['fws_variant'])) {
            $variant = sanitize_text_field((string) $values['fws_variant']);
            if (in_array($variant, array('A', 'B'), true)) {
                $item->add_meta_data('_fws_variant', $variant, true);
            }
        }
    }

    /**
     * نسخهٔ ۲.۱۲ (S-04): انتساب بر اساس هوک عمومی گذار وضعیت + فهرست رسمی
     * wc_get_is_paid_statuses() — جایگزین هوک‌های هاردکد processing/completed.
     * سفارش‌های با وضعیت سفارشیِ تعریف‌شده به‌عنوان «پرداخت‌شده» (فیلتر رسمی
     * woocommerce_is_paid_statuses، مثل «ارسال‌شده» یا COD های مسیر سفارشی) هم
     * در لحظهٔ اولین ورود به وضعیت پرداخت‌شده منتسب می‌شوند؛ گذارهای بعدی
     * (شipped→completed) به‌خاطر گارد $from دوباره فراخوانی نمی‌شوند.
     *
     * @param int    $order_id
     * @param string $from وضعیت قبلی
     * @param string $to   وضعیت جدید
     * @param mixed  $order
     */
    public static function maybe_attribute_on_status_change($order_id, $from = '', $to = '', $order = null) {
        $paid_statuses = function_exists('wc_get_is_paid_statuses')
            ? (array) wc_get_is_paid_statuses()
            : array('completed', 'processing');
        $to   = (string) $to;
        $from = (string) $from;
        if ('' === $to || !in_array($to, $paid_statuses, true)) {
            return;
        }
        // اولین ورود به دنیای پرداخت‌شده؛ گذارهای درون‌دنیای پرداخت منتسب مجاز نیستند
        if ('' !== $from && in_array($from, $paid_statuses, true)) {
            return;
        }
        self::attribute_order($order_id);
    }

    /**
     * انتساب درآمد سفارش به ویجت‌های مبدأ — نسخه ۲.۱۰.۲ (B-01): انتساب دلتایی per-item
     * درآمد هر آیتم = مبلغ نهایی خط پس از همه تخفیف‌ها (line total)
     *
     * @param int $order_id
     */
    public static function attribute_order($order_id) {
        if (!self::tracking_enabled()) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || !method_exists($order, 'get_items')) {
            return;
        }

        // نسخه ۲.۱۰.۲ (B-01): انتساب دلتایی. گارد سفارش-سطح قبلی باعث می‌شد آیتم
        // آپسلی که «بعد از» گذار processing به سفارش اضافه می‌شود هرگز منتسب نشود
        // (گزارش ویجت تشکر همیشه صفر). اکنون هر آیتم فلگ منتسبیِ خودش را دارد و
        // فراخوانی‌های بعدی فقط آیتم‌های جدید را منتسب می‌کنند.
        $legacy_done = (bool) $order->get_meta('_fws_attributed', true);

        $has_item_flag = false;
        $pending_items = array();
        foreach ($order->get_items() as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }
            if ($item->get_meta('_fws_attributed_item', true)) {
                $has_item_flag = true;
                continue;
            }
            $pending_items[] = $item;
        }

        // سازگاری با نسخه‌های قبل: سفارش‌های قدیمی که با گارد سفارش-سطح کامل منتسب
        // شده‌اند (متای سفارش هست ولی هیچ فلگ آیتمی وجود ندارد) و هیچ آیتم تازه‌ای
        // ندارند، دوباره منتسب نمی‌شوند تا درآمد دوبرابر ثبت نشود.
        if ($legacy_done && !$has_item_flag && empty($pending_items)) {
            return;
        }

        $attributed_any = false;
        foreach ($pending_items as $item) {
            $widget = (string) $item->get_meta('_fws_source', true);
            if ('' === $widget) {
                // سازگاری با سفارش‌های قدیمی‌تر از ۲.۱۰.۲ (متای بدون زیرخط)
                $widget = (string) $item->get_meta('fws_source', true);
            }
            if ('' === $widget) {
                continue; // خرید ارگانیک (خارج از ویجت‌های افزونه) — انتساب نمی‌شود
            }
            $slug = self::resolve_slug($widget);
            if ('' === $slug) {
                continue;
            }
            $variant = (string) $item->get_meta('_fws_variant', true);
            if ('' === $variant) {
                $variant = (string) $item->get_meta('fws_variant', true);
            }
            if (!in_array($variant, array('A', 'B'), true)) {
                $variant = '';
            }
            $revenue = (float) $item->get_total();
            // نسخهٔ ۲.۱۱ (B-24): هویت ردیف خرید از خود سفارش — user_id = صاحب سفارش؛
            // session_hash = خالی (هش سشنِ لحظهٔ خرید فقط از درخواستِ بازدید قابل ساختن
            // است و اگر ادمین وضعیت را عوض کند، هشِ ادمین ثبت می‌شد). شمارش سفارش‌های
            // منسوب در گزارش‌ها با order_id انجام می‌شود پس خالی بودنش هیچ آماری را نمی‌شکند.
            $fws_identity = array(
                'session_hash' => '',
                'user_id'      => (int) $order->get_customer_id(),
            );
            // نسخهٔ ۲.۱۲.۴ (F-10): متای انتساب فقط پس از INSERT موفق ثبت می‌شود؛ در غیر
            // این صورت طراحی «اختلافیِ» B-01 هرگز این ردیف را دوباره تلاش نمی‌کرد و
            // درآمدش برای همیشه گم می‌شد.
            $insert_ok = self::insert_event('purchase', $slug, (int) $item->get_product_id(), $variant, $order->get_id(), $revenue, $fws_identity);
            if ($insert_ok) {
                $item->add_meta_data('_fws_attributed_item', current_time('mysql', true), true);
                $attributed_any = true;
            }
        }

        if ($attributed_any) {
            $order->update_meta_data('_fws_attributed', current_time('mysql', true));
            $order->save();
            self::flush_report_cache();
        }
    }

    /* ───────────────────────── گزارش‌گیری ───────────────────────── */

    /**
     * گزارش قیف تبدیل و درآمد به تفکیک ویجت (کش ۵ دقیقه‌ای)
     * @param int $days
     * @return array
     */
    public static function get_report($days = 30) {
        $days = max(1, min(365, (int) $days));
        $cache_key = 'fws_report_' . $days;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $empty = array('days' => $days, 'widgets' => array(), 'totals' => array(
            'impression' => 0, 'add_to_cart' => 0, 'coupon' => 0, 'purchase' => 0, 'revenue' => 0.0,
        ));

        global $wpdb;
        if (!self::ensure_table()) {
            return $empty;
        }
        $table = $wpdb->prefix . self::TABLE_EVENT;
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT widget, event_type,
                    COUNT(DISTINCT CASE WHEN event_type = 'purchase' THEN order_id ELSE session_hash END) AS cnt,
                    SUM(revenue) AS revenue
             FROM {$table}
             WHERE event_time >= %s
             GROUP BY widget, event_type",
            $since
        ), ARRAY_A);

        // نسخهٔ ۲.۱۱ — شمارش «سشن/سفارش محور»:
        //  نمایش و افزودن = COUNT(DISTINCT session_hash) (یک بازدیدکننده، نه چند ردیف —
        //  قبلاً یک کلیک پکیج تا ۱۰ ردیف افزودن می‌ساخت و «نرخ افزودن» از ۱۰۰٪ می‌گذشت)
        //  خرید = COUNT(DISTINCT order_id) (یک سفارش = یک خرید، نه به تعداد اقلام)

        $report = array('days' => $days, 'widgets' => array(), 'totals' => array(
            'impression' => 0, 'add_to_cart' => 0, 'coupon' => 0, 'purchase' => 0, 'revenue' => 0.0,
        ));

        foreach ((array) $rows as $row) {
            $widget = (string) $row['widget'];
            $type   = (string) $row['event_type'];
            if (!isset($report['widgets'][$widget])) {
                $report['widgets'][$widget] = array(
                    'impression' => 0, 'add_to_cart' => 0, 'coupon' => 0, 'purchase' => 0, 'revenue' => 0.0,
                );
            }
            if (array_key_exists($type, $report['widgets'][$widget])) {
                $report['widgets'][$widget][$type] += (int) $row['cnt'];
                $report['widgets'][$widget]['revenue'] += (float) $row['revenue'];

                $report['totals'][$type] += (int) $row['cnt'];
                $report['totals']['revenue'] += (float) $row['revenue'];
            }
        }

        set_transient($cache_key, $report, 5 * MINUTE_IN_SECONDS);
        return $report;
    }

    /**
     * خالی کردن کش گزارش‌ها (پس از انتساب سفارش یا تغییرات ادمین)
     */
    public static function flush_report_cache() {
        foreach (array(7, 30, 90, 365) as $d) {
            delete_transient('fws_report_' . $d);
        }
        if (class_exists('FWS_Insights')) {
            FWS_Insights::flush();
        }
    }
}
