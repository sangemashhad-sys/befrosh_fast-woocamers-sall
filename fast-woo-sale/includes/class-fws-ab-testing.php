<?php
/**
 * Class FWS_AB_Testing
 * موتور A/B تست ویجت‌ها — نسخه ۲.۹
 *
 * مأموریت: مقایسه واقعی دو نسخه از عنوان/رنگ یک ویجت روی دو گروه تصادفی و پایدار
 * از بازدیدکنندگان، و اعلام برنده بر اساس معناداری آماری (z-test دو نسبت).
 *
 * اصول کنترل (قانون طلایی نسخه ۲.۹):
 *  ۱) تست فقط روی ویجتی اجرا می‌شود که از تنظیمات نسخه ۲.۸ فعال باشد؛ ویجت خاموش = تست هم بی‌اثر
 *  ۲) هر ویجت در هر لحظه حداکثر یک تست فعال دارد
 *  ۳) نسخه A همیشه «کنترل» است (ظاهر فعلی مدیر)؛ فقط B می‌تواند عنوان/رنگ جایگزین داشته باشد
 *  ۴) اختصاص نسخه در سشن ووکامرس ذخیره می‌شود تا کاربر در کل بازدیدش نسخه ثابت ببیند
 *  ۵) پس از اعلام برنده، نسخه برنده تا حذف/بازنشانی تست قفل می‌شود (تغییر ناگهانی ظاهر ندارد)
 *  ۶) تست متوقف‌شده هیچ اثری روی ظاهر ندارد
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_AB_Testing {

    const OPTION_KEY = 'fws_ab_tests';

    /** حداکثر تعداد تست‌های نگهداری‌شده (فعال + خاتمه‌یافته) */
    const MAX_TESTS = 12;

    /** حداقل نمایش هر نسخه برای اعتبار اعلام برنده */
    const MIN_IMPRESSIONS = 100;

    /** آستانه معناداری آماری (p-value) */
    const SIGNIFICANCE = 0.05;

    /**
     * نسخهٔ ۲.۱۲.۲ (B-43): سقف عمر پیش‌فرض تست فعال (روز).
     * آمار تست از جدول رخدادها می‌آید که با کلید نگهداری (پیش‌فرض ۶۰ روز) پاکسازی
     * می‌شود؛ تستِ بی‌سقف‌عمر یعنی کرون شبانه رخدادهای اولیه‌اش را می‌خورد و آمار
     * «متحرک» می‌شد (پنجرهٔ داده کوچک‌تر از عمر تست) — نتیجه‌گیری روی دادهٔ ناقص.
     * سقف نهایی با فیلتر fws_ab_max_age_days و کلید نگهداری فروشگاه clamp می‌شود:
     * هرگز بیشتر از retention تا پاکسازی هرگز زیر تستِ فعال رخ ندهد.
     */
    const MAX_AGE_DAYS = 30;

    /**
     * فهرست ویجت‌های قابل تست (شناسه کوتاه = برچسب فارسی)
     * شورت‌کد و مودال خروج عمداً خارج هستند؛ شورت‌کد انتخاب صریح مدیر است و
     * مودال خروج مسیر نمایش متفاوتی دارد.
     * @return array
     */
    public static function testable_widgets() {
        return array(
            'product'  => 'باکس پکیج هوشمند (صفحه محصول)',
            'cart'     => 'پیشنهادات مکمل (سبد خرید)',
            'thankyou' => 'آپسل یک‌کلیکی (صفحه تشکر)',
            'shipping' => 'نوار ارسال رایگان',
            'account'  => 'پیش‌بینی خرید بعدی (حساب کاربری)',
            'search'   => 'بنر پیشنهاد (نتایج جستجو)',
        );
    }

    /* ───────────────────────── ذخیره‌سازی تست‌ها ───────────────────────── */

    /**
     * @return array
     */
    public static function get_tests() {
        $tests = get_option(self::OPTION_KEY, array());
        return is_array($tests) ? $tests : array();
    }

    /**
     * @param array $tests
     * @return bool
     */
    // نسخهٔ ۲.۱۰.۴ (B-22): این گزینه در هر صفحهٔ دارای ویجت خوانده می‌شود؛ حجم آن حداکثر
    // ~۴KB (سقف ۱۲ تست) است، پس autoload=yes درست است — قبلاً autoload=false یک کوئری
    // اختصاصی در هر درخواستِ دارای ویجت می‌ساخت. نصب‌های موجود با مهاجرتِ داخل
    // FWS_Database_Miner::maybe_upgrade_schema منتقل می‌شوند.
    public static function save_tests($tests) {
        return update_option(self::OPTION_KEY, array_values($tests), true);
    }

    /**
     * تست فعال (running) برای یک ویجت — حداکثر یکی
     * @param string $widget_slug
     * @return array|null
     */
    public static function running_test_for($widget_slug) {
        static $cache = array();
        $widget_slug = sanitize_key((string) $widget_slug);
        if (!array_key_exists($widget_slug, $cache)) {
            $found = null;
            foreach (self::get_tests() as $test) {
                if ('running' === $test['status'] && $widget_slug === $test['widget']) {
                    $found = $test;
                    break;
                }
            }
            $cache[$widget_slug] = $found;
        }
        return $cache[$widget_slug];
    }

    /**
     * تست خاتمه‌یافته با برنده قفل‌شده برای یک ویجت
     * @param string $widget_slug
     * @return array|null
     */
    private static function concluded_test_for($widget_slug) {
        foreach (self::get_tests() as $test) {
            if ($widget_slug === $test['widget'] && in_array($test['status'], array('winner_a', 'winner_b'), true)) {
                return $test;
            }
        }
        return null;
    }

    /* ───────────────────────── اختصاص نسخه به بازدیدکننده ───────────────────────── */

    /**
     * نسخه موثر برای این بازدیدکننده روی این ویجت: '' | 'A' | 'B'
     * برنده قفل‌شده همیشه برمی‌گردد؛ تست running بر اساس سشن؛ بقیه خالی
     *
     * @param string $widget_slug
     * @return string
     */
    public static function effective_variant($widget_slug) {
        $widget_slug = sanitize_key((string) $widget_slug);

        // ۱) برنده قفل‌شده
        $concluded = self::concluded_test_for($widget_slug);
        if ($concluded) {
            return ('winner_b' === $concluded['status']) ? 'B' : 'A';
        }

        // ۲) تست در حال اجرا
        $test = self::running_test_for($widget_slug);
        if (!$test) {
            return '';
        }
        return self::session_assignment($test);
    }

    /**
     * اختصاص پایدار نسخه در سشن ووکامرس (سقوط به تخصیص قطعی هش‌محور)
     * @param array $test
     * @return string 'A'|'B'
     */
    private static function session_assignment($test) {
        if (!function_exists('WC') || !WC()->session) {
            return 'A'; // بدون سشن (کش سرور و...) همیشه کنترل — رفتار امن
        }
        $map = WC()->session->get('fws_ab_assignments');
        if (!is_array($map)) {
            $map = array();
        }
        if (isset($map[$test['id']]) && in_array($map[$test['id']], array('A', 'B'), true)) {
            return $map[$test['id']];
        }
        $split   = max(5, min(50, (int) $test['split']));
        // نسخهٔ ۲.۱۱: abs(crc32) — روی PHP ۳۲بیت crc32 می‌تواند منفی باشد و %100 نتیجهٔ منفی
        // می‌سازد که همیشه از split کوچک‌تر است؛ یعنی حدوداً نصف سشن‌ها به‌اجبار A می‌شدند
        // و ترافیک واقعی B نصفِ نسبت پیکربندی‌شده بود.
        $variant = ((abs(crc32($test['id'] . '|' . FWS_Tracker::session_hash())) % 100) < $split) ? 'B' : 'A';
        $map[$test['id']] = $variant;
        WC()->session->set('fws_ab_assignments', $map);
        return $variant;
    }

    /* ───────────────────────── اعمال نسخه روی ظاهر ───────────────────────── */

    /**
     * عنوان موثر ویجت — جایگزین امن برای رشته‌های ثابت رندرها
     * نسخه A (کنترل) همیشه عنوان پیش‌فرض/ذخیره‌شده مدیر را برمی‌گرداند
     *
     * @param string $widget_slug
     * @param string $default_title
     * @return string
     */
    public static function widget_title($widget_slug, $default_title) {
        $variant = self::effective_variant($widget_slug);
        if ('' === $variant) {
            return $default_title;
        }

        if ('A' === $variant) {
            $test = self::running_test_for($widget_slug);
            // نسخهٔ ۲.۱۲.۱ (F-03): پس از قفل برندهٔ A هم عنوان نسخهٔ A حفظ شود؛
            // قبلاً فقط تستِ running جست‌وجو می‌شد و اگر مدیر عنوانِ A را سفارشی
            // پر کرده بود، با قفل‌شدن برندهٔ A به‌بی‌صدا به عنوان پیش‌فرض برمی‌گشت
            // (نسخهٔ B قفل‌شده عنوان B خودش را نگه می‌داشت — ناهم‌تاری).
            if (!$test) {
                $test = self::concluded_test_for($widget_slug);
            }
            $title_a = ($test && !empty($test['title_a'])) ? $test['title_a'] : $default_title;
            return (string) $title_a;
        }

        // نسخه B
        $test = self::concluded_test_for($widget_slug);
        if (!$test) {
            $test = self::running_test_for($widget_slug);
        }
        if ($test && !empty($test['title_b'])) {
            return (string) $test['title_b'];
        }
        return $default_title;
    }

    /**
     * نسخهٔ ۲.۱۲.۱ (B-38): رنگ تاکیدی نسخهٔ B دیگر در متغیر «سراسری» --fws-accent
     * نوشته نمی‌شود. قبلاً این متد روی فیلتر fws_style_variables (بلوک متغیرهای همهٔ
     * ویجت‌ها) رنگ نسخهٔ B هر ویجتی را به کل صفحه اعمال می‌کرد؛ یعنی با دو تست هم‌زمان،
     * کاربرِ گروه B در تست سبد، باکس پکیج صفحهٔ محصول را هم با رنگ B می‌دید (گروه کنترل
     * آلوده می‌شد) و رنگِ برندهٔ قفل‌شدهٔ یک ویجت، رنگ همهٔ ویجت‌ها را برای همیشه عوض
     * می‌کرد. ضمناً همین حلقه برای هر ۶ ویجت در هر صفحه تخصیص سشن می‌ساخت (نوشتن سشن ×۶).
     *
     * اکنون: خروجی این فیلتر (fws_widget_accent_overrides: [slug => hex]) در
     * FWS_Style_Manager به بلوک‌های CSS «اسکوپ‌شدهٔ ریشهٔ همان ویجت» تبدیل می‌شود و
     * فقط وقتی برای ویجتی پیکربندی رنگِ تست وجود دارد سشن لمس می‌شود (خوانش گزینهٔ
     * تست‌ها وابسته به سشن نیست).
     *
     * هوک: fws_widget_accent_overrides
     *
     * @param array $overrides
     * @return array
     */
    public static function filter_widget_accent_overrides($overrides) {
        $overrides = is_array($overrides) ? $overrides : array();
        foreach (self::testable_widgets() as $slug => $label) {
            $hex = self::variant_color_for($slug);
            if ('' !== $hex) {
                $overrides[$slug] = $hex;
            }
        }
        return $overrides;
    }

    /**
     * رنگ موثر نسخهٔ B برای یک ویجت — '' یعنی رنگی از A/B روی این ویجت اعمال نمی‌شود
     * فقط برندهٔ قفل‌شدهٔ B با رنگ، یا تستِ running با رنگ که سشنِ جاری در آن B است.
     * نخست «پیکربندی» رنگ بررسی می‌شود (خوانش گزینه — بدون ساخت تخصیص سشن)؛ فقط اگر
     * رنگی در کار باشد effective_variant (و تخصیص سشن) فراخوانی می‌شود.
     *
     * @param string $widget_slug
     * @return string کد HEX نرمال‌شدهٔ ۶ رقمی یا رشتهٔ خالی
     */
    public static function variant_color_for($widget_slug) {
        $widget_slug = sanitize_key((string) $widget_slug);

        $test = null;
        $concluded = self::concluded_test_for($widget_slug);
        if ($concluded) {
            if ('winner_b' === $concluded['status'] && !empty($concluded['color_b'])) {
                $test = $concluded; // رنگ برندهٔ قفل‌شدهٔ B — فقط همین ویجت
            }
        } else {
            $running = self::running_test_for($widget_slug);
            if ($running && !empty($running['color_b'])) {
                $test = $running;
            }
        }
        if (!$test) {
            return '';
        }
        if ('B' !== self::effective_variant($widget_slug)) {
            return '';
        }
        $accent = strtolower((string) $test['color_b']);
        if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $accent)) {
            return '';
        }
        if (4 === strlen($accent)) {
            $accent = '#' . $accent[1] . $accent[1] . $accent[2] . $accent[2] . $accent[3] . $accent[3];
        }
        return $accent;
    }

    /* ───────────────────────── آمار و معناداری ───────────────────────── */

    /**
     * آمار زنده یک تست از جدول رخدادها (از لحظهٔ شروع تست)
     * نسخهٔ ۲.۱۱ — شمارش «سشن محور»:
     *  نمایش و افزودن با COUNT(DISTINCT session_hash) شمرده می‌شوند نه تعداد ردیف؛
     *  قبلاً یک کلیک پکیج تا ۱۰ ردیف add_to_cart می‌ساخت و نرخ تبدیل می‌توانست از ۱۰۰٪
     *  بگذرد و z-test با شمارشِ کالاهای ردیفی، برندهٔ کاذب زودهنگام می‌ساخت.
     *  خرید با COUNT(DISTINCT order_id) شمرده می‌شود (یک سفارش = یک خرید).
     * نسخهٔ ۲.۱۱ — سقف اختیاری پنجره ($until_ts): کارت «برندهٔ تازهٔ» بینش‌ها با تا
     *  لحظهٔ قفل برنده محاسبه می‌شود تا ترافیک پس از قفل (۱۰۰٪ زیر برنده) نرخ نسخهٔ بازنده
     *  را بی‌سبب رقیق/تورم ندهد.
     * @param array $test
     * @param int   $until_ts سقف پنجره (یونیکس)؛ صفر = اکنون
     * @return array ['A'=>['impression'=>..,'add_to_cart'=>..,'purchase'=>..,'revenue'=>..], 'B'=>[...]]
     */
    public static function test_stats($test, $until_ts = 0) {
        $blank = array('impression' => 0, 'add_to_cart' => 0, 'purchase' => 0, 'revenue' => 0.0);
        $out = array('A' => $blank, 'B' => $blank);

        global $wpdb;
        $table = $wpdb->prefix . FWS_Tracker::TABLE_EVENT;
        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        if (!$exists) {
            return $out;
        }

        $since_ts = (int) $test['created'];
        $until_ts = ($until_ts > 0) ? min((int) $until_ts, time()) : time();
        if ($until_ts < $since_ts) {
            $until_ts = $since_ts;
        }
        $since = gmdate('Y-m-d H:i:s', $since_ts);
        $until = gmdate('Y-m-d H:i:s', $until_ts);
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT variant, event_type,
                    COUNT(DISTINCT CASE WHEN event_type = 'purchase' THEN order_id ELSE session_hash END) AS cnt,
                    SUM(revenue) AS revenue
             FROM {$table}
             WHERE widget = %s AND event_time >= %s AND event_time <= %s AND variant IN ('A','B')
             GROUP BY variant, event_type",
            sanitize_key($test['widget']), $since, $until
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $v = ('B' === $row['variant']) ? 'B' : 'A';
            $t = (string) $row['event_type'];
            if (array_key_exists($t, $out[$v])) {
                $out[$v][$t] += (int) $row['cnt'];
            }
            $out[$v]['revenue'] += (float) $row['revenue'];
        }
        return $out;
    }

    /**
     * بررسی خودکار همه تست‌های running و قفل برنده در صورت معناداری
     * (کرون روزانه + دکمه دستی ادمین)
     * @return array فهرست تست‌هایی که همین لحظه برنده‌شان اعلام شد
     */
    public static function check_conclusions() {
        $concluded_now = array();
        $tests = self::get_tests();
        $changed = false;

        foreach ($tests as $i => $test) {
            if ('running' !== $test['status']) {
                continue;
            }
            $stats = self::test_stats($test);
            $impA  = $stats['A']['impression'];
            $impB  = $stats['B']['impression'];

            if ($impA < self::MIN_IMPRESSIONS || $impB < self::MIN_IMPRESSIONS) {
                continue; // هنوز داده کافی نیست
            }
            $convA = $stats['A']['add_to_cart'];
            $convB = $stats['B']['add_to_cart'];
            if (0 === ($convA + $convB)) {
                continue; // هیچ تبدیلی — معنادار نیست
            }

            $p_value = self::two_proportion_p_value($convA, $impA, $convB, $impB);
            // نسخهٔ ۲.۱۲.۱ (B-40): گارد is_finite — NAN >= 0.05 برابر false است و
            // null === NAN هم false؛ بدون این گارد تستِ دارای p_value=NaN برنده اعلام می‌شد.
            if (null === $p_value || !is_finite($p_value) || $p_value >= self::SIGNIFICANCE) {
                continue;
            }

            // نسخهٔ ۲.۱۰.۴ (B-20): برنده باید با «نرخ تبدیل» قفل شود، نه تعداد مطلق.
            // قبلاً ($convB > $convA) بود؛ چون split نسخهٔ B حداکثر ۵۰٪ است، در آزمونِ
            // «A ۳۵/۷۰۰ (۵٪) در برابر B ۲۷/۳۰۰ (۹٪)» که z ≈ ۲٫۵۳ و p ≈ ۰٫۰۱۱ (معنادار)
            // است، برنده به‌غلط winner_a اعلام می‌شد چون ۳۵ > ۲۷. مقایسهٔ نرخ‌ها هم‌راستا
            // با آزمون معناداری است؛ برابری نرخ‌ها (که با p<0.05 نمی‌رسد) محافظه‌کارانه به A می‌رود.
            $rateA = $convA / max(1, $impA);
            $rateB = $convB / max(1, $impB);
            $winner          = ($rateB > $rateA) ? 'winner_b' : 'winner_a';
            $tests[$i]['status']    = $winner;
            $tests[$i]['concluded'] = time();
            $tests[$i]['p_value']   = round($p_value, 4);
            $changed = true;
            $concluded_now[] = $tests[$i];
        }

        if ($changed) {
            self::save_tests($tests);
        }
        return $concluded_now;
    }

    /**
     * نسخهٔ ۲.۱۲.۲ (B-43): سقف عمر تست فعال بر حسب روز.
     * فیلتر توسعه‌دهنده: fws_ab_max_age_days — همیشه به بازهٔ [۷، کلید نگهداری]
     * محدود می‌شود تا پاکسازی روزانه هرگز زیر تستِ فعال رخ ندهد.
     * @return int
     */
    public static function max_age_days() {
        $days      = (int) apply_filters('fws_ab_max_age_days', self::MAX_AGE_DAYS);
        $retention = max(7, (int) FWS_Settings::get('tracking_retention_days', 60));
        return max(7, min($days, $retention));
    }

    /**
     * نسخهٔ ۲.۱۲.۲ (B-43): خاتمهٔ خودکار تست‌های رسیده به سقف عمر.
     * مستقل از کلید «قفل خودکار برنده» اجرا می‌شود (کرون روزانه، بعد از فرصت آخر
     * بررسی معناداری) — تستِ رسیده به سقف، «متوقف بدون قفل» می‌شود: ظاهر ویجت به
     * حالت عادی برمی‌گردد، آمار از شروع تا پایان پنجرهٔ کامل باقی می‌ماند (کاملاً
     * داخل بازهٔ نگهداری) و مدیر با فهرست تست‌ها آزادانه تصمیم بعدی را می‌گیرد.
     * @return array شناسهٔ تست‌های خاتمه‌یافته
     */
    public static function age_out_expired_tests() {
        $max_age = self::max_age_days() * DAY_IN_SECONDS;
        $tests   = self::get_tests();
        $changed = false;
        $aged    = array();

        foreach ($tests as $i => $test) {
            if ('running' !== $test['status']) {
                continue;
            }
            $created = (int) $test['created'];
            if ($created > 0 && (time() - $created) >= $max_age) {
                $tests[$i]['status']      = 'stopped';
                $tests[$i]['concluded']   = time();
                $tests[$i]['stop_reason'] = 'max_age';
                $changed = true;
                $aged[]  = $test['id'];
            }
        }

        if ($changed) {
            self::save_tests($tests);
            if (class_exists('FWS_Logger')) {
                FWS_Logger::info(
                    sprintf('A/B tests auto-stopped at max age (%1$d days): %2$s', self::max_age_days(), implode(', ', $aged)),
                    array(),
                    'ab-testing'
                );
            }
            if (class_exists('FWS_Insights')) {
                FWS_Insights::flush();
            }
        }
        return $aged;
    }

    /**
     * p-value آزمون z دو نسبت (تبدیل نسخه B در برابر A)
     * @param int $convA @param int $impA @param int $convB @param int $impB
     * @return float|null
     */
    private static function two_proportion_p_value($convA, $impA, $convB, $impB) {
        $convA = max(0, (int) $convA);
        $convB = max(0, (int) $convB);
        // نسخهٔ ۲.۱۲.۱ (B-40): هر مبدل دست‌کم یک نمایش است. سشن‌هایی که ردیف نمایششان
        // ثبت نشده ولی افزودن‌شان ثبت شده (رندر بدون سشن که به‌اجبار A شمرده می‌شد،
        // افت لحظه‌ای جدول، قطع‌ووصل کلید ردیابی در میانهٔ تست) می‌توانند «تبدیل > نمایش»
        // بسازند؛ در نتیجه p>1 و عبارت p*(1-p) منفی و sqrt(منفی) = NAN می‌شد — و چون
        // NAN هیچ شرطی را پاس نمی‌کرد، تست با p_value=NaN برنده اعلام می‌شد. کفِ
        // نمایش = تبدیل هم ریاضیات z-test را درست نگه می‌دارد هم دامنهٔ sqrt را.
        $impA = max(1, (int) $impA, $convA);
        $impB = max(1, (int) $impB, $convB);
        $pA   = $convA / $impA;
        $pB   = $convB / $impB;
        $p    = ($convA + $convB) / ($impA + $impB);
        $se   = sqrt($p * (1 - $p) * (1 / $impA + 1 / $impB));
        if ($se <= 0 || !is_finite($se)) {
            return null;
        }
        $z = abs($pB - $pA) / $se;
        if (!is_finite($z)) {
            return null;
        }
        $p_value = 2 * (1 - self::norm_cdf($z));
        return is_finite($p_value) ? $p_value : null;
    }

    /**
     * تابع توزیع تجمعی نرمال استاندارد — تقریب erf (بدون افزونه PHP-Stats)
     * @param float $x
     * @return float
     */
    private static function norm_cdf($x) {
        $t = 1 / (1 + 0.3275911 * abs($x));
        $poly = ((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592;
        $erf  = 1 - $poly * $t * exp(-$x * $x);
        return 0.5 * (1 + ($x >= 0 ? $erf : -$erf));
    }

    /* ───────────────────────── چرخه حیات تست (فراخوانی از ادمین) ───────────────────────── */

    /**
     * ساخت تست جدید با اعتبارسنجی کامل (خروجی: [success, message])
     * @param string $widget_slug @param string $title_b @param string $title_a @param string $color_b @param int $split
     * @return array
     */
    public static function create_test($widget_slug, $title_b, $title_a, $color_b, $split) {
        $widget_slug = sanitize_key((string) $widget_slug);
        $title_a     = sanitize_text_field((string) $title_a);
        $title_b     = sanitize_text_field((string) $title_b);
        $color_b     = strtoupper(trim((string) $color_b));

        if (!array_key_exists($widget_slug, self::testable_widgets())) {
            return array(false, 'ویجت انتخابی برای تست معتبر نیست.');
        }
        if (mb_strlen($title_a) > 120 || mb_strlen($title_b) > 120) {
            return array(false, 'طول عنوان‌ها حداکثر ۱۲۰ کاراکتر است.');
        }
        $color_valid = ('' !== $color_b) && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color_b);
        if ('' === $title_b && !$color_valid) {
            return array(false, 'نسخه B باید دست‌کم یک تغییر داشته باشد: عنوان جدید یا رنگ جدید.');
        }
        if (self::running_test_for($widget_slug)) {
            return array(false, 'این ویجت هم‌اکنون یک تست فعال دارد؛ ابتدا آن را متوقف یا منتشر کنید.');
        }
        // نسخهٔ ۲.۱۱ (B-05): «اسلات» ویجت فقط با تستِ running اشغال نمی‌شود؛ برندهٔ قفل‌شده هم
        // ظاهر ویجت را در اختیار دارد (effective_variant برنده را برمی‌گرداند). قبلاً با وجود
        // قفلِ برنده می‌شد تست جدید ساخت؛ تست جدید هیچ ترافیکی نمی‌گرفت ( zombie) و هرگز
        // به نتیجه نمی‌رسید.
        if (self::concluded_test_for($widget_slug)) {
            return array(false, 'برای این ویجت یک برندهٔ قفل‌شده وجود دارد؛ ابتدا قفل آن را از فهرست تست‌ها آزاد کنید و سپس تست جدید بسازید.');
        }
        // نسخهٔ ۲.۱۲.۱ (B-38): محدودیت «حداکثر یک تست رنگی هم‌زمان» حذف شد — این قید
        // به‌خاطر نوشتن رنگ در متغیر «سراسری» --fws-accent وجود داشت. اکنون رنگ نسخهٔ B
        // فقط به ریشهٔ همان ویجتِ تحت تست اسکوپ می‌شود؛ دو تست رنگی روی دو ویجت متفاوت
        // هم‌پوشانی و آلودگی ندارند (تست دوم روی «همان» ویجت همچنان توسط گارد بالا رد می‌شود).

        $tests = self::get_tests();
        if (count($tests) >= self::MAX_TESTS) {
            // نسخهٔ ۲.۱۱ (B-06): هرس «هرگز» تست‌های running و برنده‌های قفل‌شده را حذف نمی‌کند.
            // قبلاً array_filter فقط running را نگه می‌داشت؛ یعنی با رسیدن به سقف، همهٔ تاریخچه
            // و همهٔ قفل‌های برنده (ظاهر منتشرشدهٔ نسخهٔ B) بی‌صدا پاک می‌شد. اکنون فقط قدیمی‌ترین
            // تست‌های خاتمه‌یافتهٔ بدون قفل (stopped) حذف می‌شوند؛ اگر جای خالی پیدا نشد، ساخت
            // تست با پیام صریح رد می‌شود تا مدیر خودش حذف کند.
            $prunable = array();
            foreach ($tests as $idx => $t) {
                if ('running' === $t['status'] || in_array($t['status'], array('winner_a', 'winner_b'), true)) {
                    continue;
                }
                $prunable[$idx] = isset($t['concluded']) ? (int) $t['concluded'] : (int) $t['created'];
            }
            asort($prunable); // قدیمی‌ترین اول
            $need = count($tests) - self::MAX_TESTS + 1;
            foreach (array_slice(array_keys($prunable), 0, $need) as $idx) {
                unset($tests[$idx]);
            }
            if (count($tests) >= self::MAX_TESTS) {
                return array(false, 'سقف تاریخچهٔ تست‌ها پر است و همهٔ تست‌های خاتمه‌یافته، برندهٔ قفل‌شده یا فعال‌اند؛ یکی از تست‌های خاتمه‌یافته را دستی حذف کنید. (برنده‌های قفل‌شده برای حفظ ظاهر سایت خودکار حذف نمی‌شوند.)');
            }
            $tests = array_values($tests);
        }

        // نسخهٔ ۲.۱۲.۱ (F-02): شناسهٔ یکتا — پیش‌تر دو ساخت در یک ثانیه با شانسِ
        // تصادمِ rand می‌توانستند id تکراری بسازند؛ در آن صورت آمار دو تست در سشن‌ها
        // ادغام و توقف/حذف روی تستِ اشتباه اعمال می‌شد.
        $existing_ids = array();
        foreach ($tests as $t) {
            $existing_ids[isset($t['id']) ? (string) $t['id'] : ''] = true;
        }
        do {
            $test_id = 'ab_' . time() . '_' . wp_rand(100, 999);
        } while (isset($existing_ids[$test_id]));

        $tests[] = array(
            'id'       => $test_id,
            'widget'   => $widget_slug,
            'title_a'  => $title_a,
            'title_b'  => $title_b,
            'color_b'  => $color_valid ? strtolower($color_b) : '',
            'split'    => max(5, min(50, (int) $split)),
            'status'   => 'running',
            'created'  => time(),
            'concluded'=> 0,
        );
        self::save_tests($tests);
        return array(true, 'تست A/B با موفقیت آغاز شد؛ از این لحظه ترافیک بین نسخه A و B تقسیم می‌شود.');
    }

    /**
     * توقف/انتشار/بازنشانی تست
     * keep: 'publish_b' = نسخه B قفل شود | 'keep_a' = بازگشت به ظاهر فعلی (A) | 'reset' = بدون قفل
     * برای تست‌های قفل‌شده (winner) فقط 'reset' معنا دارد: آزاد کردن قفل
     * @param string $test_id @param string $keep
     * @return array
     */
    public static function stop_test($test_id, $keep) {
        $tests = self::get_tests();
        foreach ($tests as $i => $test) {
            if ($test['id'] !== (string) $test_id) {
                continue;
            }
            $is_running = ('running' === $test['status']);
            $is_locked  = in_array($test['status'], array('winner_a', 'winner_b'), true);
            if (!$is_running && !$is_locked) {
                continue;
            }

            if ($is_running && 'publish_b' === $keep) {
                $tests[$i]['status']    = 'winner_b';
                $tests[$i]['concluded'] = time();
                $tests[$i]['p_value']   = '';
                $msg = 'نسخه B به‌صورت دائمی منتشر شد و برای همه بازدیدکنندگان قفل شد.';
            } elseif ($is_running && 'keep_a' === $keep) {
                $tests[$i]['status']    = 'winner_a';
                $tests[$i]['concluded'] = time();
                $tests[$i]['p_value']   = '';
                $msg = 'تست متوقف و نسخه کنترل (A) برای همه بازدیدکنندگان قفل شد.';
            } else {
                // running + reset یا آزادسازی قفل برنده
                $tests[$i]['status']    = 'stopped';
                $tests[$i]['concluded'] = time();
                $msg = $is_locked
                    ? 'قفل برنده آزاد شد؛ ظاهر ویجت به حالت عادی تنظیمات بازگشت.'
                    : 'تست متوقف شد؛ ظاهر ویجت به حالت عادی بازگشت (بدون قفل).';
            }
            self::save_tests($tests);
            return array(true, $msg);
        }
        return array(false, 'تست فعال مورد نظر یافت نشد.');
    }

    /**
     * حذف کامل تست از تاریخچه
     * نسخهٔ ۲.۱۰.۴ (B-32): ردیف‌های رخدادِ همین تست (widget + پنجرهٔ زمانی تست + variant A/B)
     * نیز پاک می‌شوند؛ قبلاً فقط ردیف گزینه حذف می‌شد و ردیف‌های جدول رخدادها «یتیم» می‌ماندند
     * و جدول را بی‌دلیل سنگین می‌کرد. پنجره با سقف (concluded یا اکنون) محدود شده تا اگر
     * بعداً برای همان ویجت تست جدیدی ساخته شده باشد، آمار تستِ جدید دست‌نخورده بماند.
     * @param string $test_id
     * @return array
     */
    public static function delete_test($test_id) {
        $tests   = self::get_tests();
        $kept    = array();
        $found   = false;
        $deleted = null;
        foreach ($tests as $test) {
            if ($test['id'] === (string) $test_id) {
                $found   = true;
                $deleted = $test;
                continue;
            }
            $kept[] = $test;
        }
        if (!$found) {
            return array(false, 'تست مورد نظر یافت نشد.');
        }
        self::save_tests($kept);
        if ($deleted) {
            self::delete_test_events($deleted);
        }
        return array(true, 'تست از تاریخچه حذف و ردیف‌های آماری مرتبط با آن پاک‌سازی شد.');
    }

    /**
     * پاکسازی دسته‌ای ردیف‌های رخداد یک تستِ حذف‌شده (B-32)
     * ستون event_time با current_time('mysql', true) یعنی UTC نوشته می‌شود؛
     * پس برش‌های زمانی هم با gmdate (UTC) ساخته می‌شوند — هم‌سو با test_stats.
     * @param array $test
     * @return void
     */
    private static function delete_test_events($test) {
        global $wpdb;
        if (!class_exists('FWS_Tracker')) {
            return;
        }
        $table = $wpdb->prefix . FWS_Tracker::TABLE_EVENT;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }
        $created = (int) $test['created'];
        $ended   = !empty($test['concluded']) ? (int) $test['concluded'] : time();
        if ($ended < $created) {
            $ended = $created;
        }
        $start = microtime(true);
        do {
            $affected = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE widget = %s AND variant IN ('A','B')
                   AND event_time >= %s AND event_time <= %s
                 LIMIT 5000",
                sanitize_key($test['widget']),
                gmdate('Y-m-d H:i:s', $created),
                gmdate('Y-m-d H:i:s', $ended)
            ));
            if ($affected < 5000) {
                break;
            }
            if ((microtime(true) - $start) > 15) {
                break; // بودجهٔ زمانی اکشن ادمین؛ باقی‌مانده صرفاً بloat بی‌آثر آماری است
            }
            usleep(10000);
        } while (true);
    }

    /**
     * ثبت هوک‌های همیشگی
     */
    public static function init() {
        // نسخهٔ ۲.۱۲.۱ (B-38): جایگزین فیلتر سراسری fws_style_variables — خروجی اسکوپ‌شدهٔ per-widget
        add_filter('fws_widget_accent_overrides', array(__CLASS__, 'filter_widget_accent_overrides'), 20);
        // بررسی روزانه معناداری هم‌زمان با کرون پاکسازی ردیابی
        // نسخهٔ ۲.۱۲.۱: قفل خودکار برنده پیش‌فرض خاموش است (توصیهٔ بازبینی مستقل + قانون
        // طلایی: قفلِ خودکارِ ظاهرِ مشتری باید خاموش‌شدنی باشد)؛ مسیر کرون از
        // auto_check_conclusions می‌گذرد و دکمهٔ دستی «بررسی برنده‌ها» مستقیم check_conclusions
        // را صدا می‌زند (تصمیم صریح مدیر همیشه کار می‌کند).
        add_action('fws_daily_tracking_cleanup_event', array(__CLASS__, 'auto_check_conclusions'));
        // نسخهٔ ۲.۱۲.۲ (B-43): خاتمهٔ تست‌های رسیده به سقف عمر — مستقل از کلید قفل
        // خودکار، «بعد از» فرصت آخر معناداری (اولویت ۲۰) تا تستی که در روز آخرش
        // معنادار شده بتواند برنده اعلام شود؛ و «پیش از» شروع رخدادهای پاکسازی‌پذیر.
        add_action('fws_daily_tracking_cleanup_event', array(__CLASS__, 'age_out_expired_tests'), 20);
        // نسخهٔ ۲.۱۱: هدرهای no-cache هنگام تست فعال — اختصاص نسخهٔ A/B سمت سرور انجام
        // می‌شود؛ روی فروشگاه‌های با کش صفحه، همهٔ بازدیدکننده‌ها نسخهٔ منجمدشدهٔ HTML را
        // می‌دیدند ولی رخداد add_to_cart آن‌ها با نسخهٔ سشن خودشان ثبت می‌شد؛ یعنی تبدیل‌ها
        // به نسخه‌ای منتسب می‌شد که هرگز ندیده بودند. تا وقتی تست فعالی جاری است، صفحه‌ها
        // no-cache می‌شوند (هزینه: افت موقت کش فقط در دورهٔ تست).
        add_action('send_headers', array(__CLASS__, 'maybe_send_nocache_headers'));
    }

    /**
     * بررسی روزانهٔ معناداری — فقط وقتی مدیر «قفل خودکار برنده» را روشن کرده باشد
     * نسخهٔ ۲.۱۲.۱: پیش‌فرض خاموش؛ تا بازطراحی کامل سنجش، قفل خودکارِ برنده نباید
     * بدون آگاهی مدیر انجام شود (توصیهٔ بازبین مستقل هفتم). با فیلتر
     * fws_ab_auto_conclude_enabled توسعه‌دهندگان می‌توانند رفتار را کنترل کنند.
     * @return array
     */
    public static function auto_check_conclusions() {
        $enabled = ('yes' === FWS_Settings::get('ab_auto_conclude', 'no'));
        if (!apply_filters('fws_ab_auto_conclude_enabled', $enabled)) {
            return array();
        }
        return self::check_conclusions();
    }

    /**
     * نسخهٔ ۲.۱۱: اگر تست A/B فعالی جاری است، هدرهای no-cache ارسال می‌شود
     * نسخهٔ ۲.۱۲.۱ (B-39): علاوه بر هدرها، ثابت‌های DONOTCACHEPAGE / DONOTCACHEOBJECT /
     * DONOTCACHEDB هم ست می‌شوند — قرارداد شناخته‌شدهٔ افزونه‌های کش (WP Rocket، W3TC،
     * LiteSpeed Cache، WP Super Cache و...) که پیش‌تر فقط به هدرها تکیه می‌کرد؛
     * بدون آن‌ها بعضی کش‌ها صفحه را با HTML منجمدشدهٔ یک نسخه served می‌کردند ولی
     * رخداد add_to_cart با نسخهٔ سشنِ فعلی ثبت می‌شد (تبدیل به نسخه‌ای منتسب می‌شد که
     * دیده بود نشده). ماندهٔ ریسک: CDN های «cache-everything» که هدرها را هم نادیده
     * می‌گیرند — در مستندات نسخه توصیه شده حین تست مستثنا شوند.
     * @return void
     */
    public static function maybe_send_nocache_headers() {
        // نسخهٔ ۲.۱۲.۴ (F-22): REST هم از گارد خارج شد — در زمان اجرای تست، «همهٔ»
        // پاسخ‌های REST (StoreAPI سبد/تسویهٔ بلوکی و wp/v2 بی‌ربط) no-cache می‌شدند و
        // کل API سایت تا ۳۰ روز کش‌ناپذیر می‌شد؛ در حالی که سوئیچ واریانت فقط در
        // رندر سمت سرور صفحات اتفاق می‌افتد.
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
            return;
        }
        foreach (self::get_tests() as $test) {
            if ('running' === $test['status']) {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                if (!defined('DONOTCACHEOBJECT')) {
                    define('DONOTCACHEOBJECT', true);
                }
                if (!defined('DONOTCACHEDB')) {
                    define('DONOTCACHEDB', true);
                }
                nocache_headers();
                return;
            }
        }
    }
}
