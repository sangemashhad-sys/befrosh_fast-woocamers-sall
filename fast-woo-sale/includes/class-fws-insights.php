<?php
/**
 * Class FWS_Insights
 * هشدارهای هوشمند مدیر — نسخه ۲.۹ «افزونه‌ای که حرف می‌زند»
 *
 * افزونه داده دارد؛ این کلاس داده را به بینش عملیاتی تبدیل می‌کند:
 *  ۱) ویجت فعالِ بدون هیچ نمایش در ۳۰ روز (نشانه کش صفحه یا بی‌بازدیدی)
 *  ۲) ویجت پرنمایشِ بدون هیچ افزودن به سبد (نشانه نیاز به A/B تست متن/جایگاه)
 *  ۳) محصول بلک‌لیست‌شده‌ای که پرتکرارترین مکمل فروشگاه است (پیشنهاد بازبینی تصمیم)
 *  ۴) کالای مکمل پرتکرار در آستانه اتمام موجودی
 *  ۵) تست A/B با برنده معنادار اعلام‌شده
 *  ۶) مودال خروج پرنمایش بدون حتی یک اعمال کوپن
 *  ۷) یادآوری خاموش بودن ردیابی (بدون آن گزارش درآمد و A/B کار نمی‌کند)
 *
 * همه بینش‌ها کاملاً از داده واقعی ساخته می‌شوند — هیچ عدد ساختگی وجود ندارد.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FWS_Insights {

    const CACHE_KEY = 'fws_insights_cache';

    /**
     * تولید/خواندن بینش‌ها (کش ۱۰ دقیقه‌ای)
     * @return array
     */
    public static function get_insights() {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $insights = array();

        // ۱) ردیابی خاموش است
        if (!FWS_Tracker::tracking_enabled()) {
            $insights[] = array(
                'type'  => 'info',
                'title' => 'ردیابی در حال حاضر خاموش است',
                'text'  => 'گزارش درآمد، قیف تبدیل و A/B تست برای کارکرد صحیح به داده ردیابی نیاز دارند. اگر قصد استفاده از این قابلیت‌ها را دارید، کلید «ردیابی قیف تبدیل» را در بخش گزارش و بهینه‌سازی روشن کنید.',
            );
            set_transient(self::CACHE_KEY, $insights, 10 * MINUTE_IN_SECONDS);
            return $insights;
        }

        $report = FWS_Tracker::get_report(30);

        // ۲) ویجت فعالِ بدون نمایش / ۳) ویجت پرنمایشِ بدون تبدیل
        $setting_map = array(
            'product'  => 'enable_widget_product',
            'cart'     => 'enable_widget_cart',
            'thankyou' => 'enable_widget_thankyou',
            'shipping' => 'enable_widget_shipping',
            'account'  => 'enable_widget_account',
            'search'   => 'enable_search_banner',
        );

        // نویز‌گیری نصب تازه: اگر هیچ ویجتی هنوز نمایشی نداشته باشد، فقط یک پیام کلی
        $total_impressions = isset($report['totals']['impression']) ? (int) $report['totals']['impression'] : 0;
        if (0 === $total_impressions) {
            $insights[] = array(
                'type'  => 'info',
                'title' => 'هنوز هیچ داده‌ای ثبت نشده است',
                'text'  => 'قیف تبدیل و گزارش درآمد با اولین بازدیدهای واقعی فروشگاه شکل می‌گیرد. اگر بازدید دارید و اعداد صفر می‌مانند، دو عامل رایج را بررسی کنید: کش صفحه (Page Cache) قالب، یا صفحهٔ سبد خریدِ ساخته‌شده با «بلوک سبد» ووکامرس (نوار ارسال و پیشنهادات سبد در آن حالا از مسیر بلوک هم رندر می‌شوند — مطمئن شوید نسخهٔ افزونه به‌روز است).',
            );
            set_transient(self::CACHE_KEY, $insights, 10 * MINUTE_IN_SECONDS);
            return $insights;
        }

        foreach (FWS_AB_Testing::testable_widgets() as $slug => $label) {
            // فقط ویجت‌هایی که از تنظیمات ظاهر فعال‌اند
            $setting_key = isset($setting_map[$slug]) ? $setting_map[$slug] : ('enable_' . $slug);
            if ('yes' !== FWS_Settings::get($setting_key, 'yes')) {
                continue; // ویجت خاموش است — طبیعی است نمایش ندارد
            }
            $w = isset($report['widgets'][$slug]) ? $report['widgets'][$slug] : array(
                'impression' => 0, 'add_to_cart' => 0, 'coupon' => 0, 'purchase' => 0, 'revenue' => 0.0,
            );

            if ($w['impression'] < 10) {
                $insights[] = array(
                    'type'  => 'info',
                    'title' => '«' . $label . '» فعال است ولی نمایشی ثبت نشده',
                    'text'  => 'این ویجت در ۳۰ روز گذشته کمتر از ۱۰ نمایش داشته است. عوامل رایج: کش صفحه خروجی را برای بازدیدکننده‌های تکراری ثابت نگه می‌دارد، موتور هنوز قانونی پیدا نکرده است (یک بار «تحلیل مجدد دیتابیس» را اجرا کنید)، یا برای ویجت‌های سبد/نوار ارسال، صفحهٔ سبد با «بلوک سبد ووکامرس» ساخته شده است که از نسخهٔ ۲.۱۱ پشتیبانی می‌شود.',
                );
            } elseif ($w['impression'] >= 200 && 0 === $w['add_to_cart']) {
                $insights[] = array(
                    'type'  => 'warn',
                    'title' => '«' . $label . '» دیده می‌شود ولی هیچ افزودنی به سبد نداشته است',
                    'text'  => 'این ویجت بیش از ۲۰۰ نمایش در ۳۰ روز داشته ولی حتی یک افزودن به سبد هم ثبت نشده است. پیشنهاد: متن و رنگ آن را با یک A/B تست بسنجید یا جایگاهش را در صفحه جابه‌جا کنید.',
                );
            }
        }

        // ۴ و ۵) بینش‌های مبتنی بر قوانین ماینر
        self::add_rules_insights($insights);

        // ۶) تست A/B خاتمه‌یافته با برنده (اخبار تازه: ۱۴ روز گذشته)
        $fresh_winner = null;
        foreach (FWS_AB_Testing::get_tests() as $test) {
            if (in_array($test['status'], array('winner_a', 'winner_b'), true)
                && !empty($test['concluded']) && (time() - (int) $test['concluded']) < 14 * DAY_IN_SECONDS) {
                $fresh_winner = $test;
                break;
            }
        }
        if ($fresh_winner) {
            // نسخهٔ ۲.۱۱: آمار کارت برندهٔ تازه فقط از پنجرهٔ واقعی «تست» (تا لحظهٔ قفل)
            // محاسبه می‌شود؛ پس از قفل، همهٔ ترافیک زیر نسخهٔ برنده ثبت می‌شود و
            // اضافه‌شدنش به پنجره، نرخ نسخهٔ بازنده را بی‌سبب خالی/کج نشان می‌داد.
            $stats  = FWS_AB_Testing::test_stats($fresh_winner, (int) $fresh_winner['concluded']);
            $labels_ab = FWS_AB_Testing::testable_widgets();
            $widget_label = isset($labels_ab[$fresh_winner['widget']]) ? $labels_ab[$fresh_winner['widget']] : $fresh_winner['widget'];
            $winner = ('winner_b' === $fresh_winner['status']) ? 'B' : 'A';
            $crA = $stats['A']['impression'] > 0 ? round(100 * $stats['A']['add_to_cart'] / $stats['A']['impression'], 1) : 0;
            $crB = $stats['B']['impression'] > 0 ? round(100 * $stats['B']['add_to_cart'] / $stats['B']['impression'], 1) : 0;
            $insights[] = array(
                'type'  => 'success',
                'title' => 'برنده A/B تست ویجت «' . $widget_label . '» مشخص شد: نسخه ' . $winner,
                // نسخهٔ ۲.۱۲.۴ (F-13): ادعای معناداری آماری فقط وقتی p_value واقعاً محاسبه شده
                // (نتیجه‌گیری خودکار) نمایش داده می‌شود؛ قفل دستی مدیر هرگز آزمون معناداری
                // نداشته و قبلاً بی‌صدا «p < 0.05» جعل می‌شد — تصمیم کسب‌وکاری بر پایهٔ عدد غیرواقعی.
                'text'  => 'نرخ افزودن به سبد نسخه A برابر ' . $crA . '٪ و نسخه B برابر ' . $crB . '٪ بود؛ '
                    . ( ! empty( $fresh_winner['p_value'] )
                        ? 'تفاوت از نظر آماری معنادار است (p < ' . FWS_AB_Testing::SIGNIFICANCE . '). '
                        : 'قفل برنده به‌صورت دستی توسط مدیر انجام شده است (بدون آزمون معناداری آماری). ' )
                    . 'نسخه برنده اکنون برای همه بازدیدکنندگان قفل شده است.',
            );
        }

        // ۷) مودال خروج پرنمایش بدون اعمال کوپن
        $exit_w = isset($report['widgets']['exit_modal']) ? $report['widgets']['exit_modal'] : null;
        $coupon_configured = ('' !== trim((string) FWS_Settings::get('exit_intent_coupon', '')));
        if ($exit_w && $exit_w['impression'] >= 100 && 0 === $exit_w['coupon']) {
            $insights[] = array(
                'type'  => $coupon_configured ? 'warn' : 'info',
                'title' => 'مودال خروج دیده می‌شود ولی کوپنی اعمال نمی‌شود',
                'text'  => $coupon_configured
                    ? 'مودال خروج بیش از ۱۰۰ بار نمایش داده شده ولی حتی یک بار هم کوپن اعمال نشده است. درصد یا جذابیت پیشنهاد را بررسی کنید.'
                    : 'مودال خروج بیش از ۱۰۰ بار نمایش داده شده ولی هیچ کد تخفیفی در پنل برای آن پیکربندی نشده است؛ در نتیجه تنها پیام بدون وعده تخفیف نمایش داده می‌شود. تعریف یک کد تخفیف واقعی معمولاً نرخ نجات سبد را بالا می‌برد.',
            );
        }

        set_transient(self::CACHE_KEY, $insights, 10 * MINUTE_IN_SECONDS);
        return $insights;
    }

    /**
     * بینش‌های بلک‌لیست و موجودی از قوانین برتر ماینر
     * @param array $insights (by reference)
     */
    private static function add_rules_insights(&$insights) {
        global $wpdb;
        $table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        if (!$exists) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT source_product_id, recommended_product_id, confidence_score, co_occurrence
             FROM {$table}
             ORDER BY confidence_score DESC, co_occurrence DESC
             LIMIT 20"
        );
        if (empty($rows)) {
            // نسخهٔ ۲.۱۰.۱ — بینش «قانونی تولید نشد»: وابستگی به Analytics و آستانه‌ها شفاف شود
            // (فروشگاه کم‌فروش با min_support بالا نباید بی‌توضیح بماند)
            global $wpdb;
            $lookup        = $wpdb->prefix . 'wc_order_product_lookup';
            $lookup_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) === $lookup);
            $lookup_empty  = true;
            if ($lookup_exists) {
                // کوئری ارزان: با اولین ردیف متوقف می‌شود (COUNT سنگین روی جدول بزرگ نمی‌زنیم)
                $one          = $wpdb->get_var("SELECT 1 FROM {$lookup} LIMIT 1");
                $lookup_empty = (null === $one);
            }

            if (!$lookup_exists || $lookup_empty) {
                $insights[] = array(
                    'type'  => 'warn',
                    'title' => 'جداول Analytics ووکامرس هنوز داده ندارند',
                    'text'  => 'منبع تحلیل این افزونه جدول wc_order_product_lookup است؛ تا وقتی این جدول از سفارشات پرداخت‌شده پر نشود، هیچ قاعده‌ای تولید نمی‌شود و ویجت‌ها خالی می‌مانند. از مسیر ووکامرس → وضعیت → ابزارها گزینه «بازسازی جداول Analytics» را اجرا کنید و مطمئن شوید گزارش‌های ووکامرس فعال‌اند.',
                );
            } else {
                $insights[] = array(
                    'type'  => 'info',
                    'title' => 'تحلیل اجرا شده ولی هنوز قاعده‌ای تولید نشده است',
                    'text'  => sprintf(
                        'با آستانه‌های فعلی (حداقل %1$d خرید مشترک در بازه %2$d روز) هیچ جفت‌کالایی تأیید نشده است؛ در فروشگاه کم‌فروش یا تازه‌شروع این طبیعی است. می‌توانید موقتاً «حداقل خرید مشترک» را روی ۲ بگذارید، بازه تحلیل را بلندتر کنید یا صبر کنید تا فروش رشد کند — تا آن زمان فال‌بک دسته‌بندی پیشنهادها را پوشش می‌دهد.',
                        (int) FWS_Settings::get('min_support', 3),
                        (int) FWS_Settings::get('lookback_days', 90)
                    ),
                );
            }
            return;
        }

        $blacklist = FWS_Settings::sanitize_blacklist_ids((array) FWS_Settings::get('product_blacklist', array()));

        foreach ($rows as $row) {
            $target_id = (int) $row->recommended_product_id;

            // محصول بلک‌لیست‌شده در قوانین برتر
            if (in_array($target_id, $blacklist, true)) {
                $insights[] = array(
                    'type'  => 'info',
                    'title' => 'محصول بلک‌لیست‌شده «' . get_the_title($target_id) . '» پرتکرارترین مکمل فروشگاه است',
                    'text'  => 'این کالا با اطمینان ' . number_format_i18n((float) $row->confidence_score, 1) . '٪ جزو قوی‌ترین پیوندهای خرید فروشگاه است ولی در لیست سیاه شما قرار دارد؛ اگر دلیل آن (حاشیه سود، انقضا و…) دیگر معتبر نیست، ارزش بازبینی تصمیم را دارد.',
                    'once'  => 'blacklist_' . $target_id,
                );
                continue; // برای همین کالا دو بینش نده
            }

            // موجودی کم کالای مکمل پرتکرار
            $product = wc_get_product($target_id);
            if ($product && $product->get_manage_stock()) {
                $stock = $product->get_stock_quantity();
                if (null !== $stock && $stock <= 3 && $stock >= 0) {
                    $insights[] = array(
                        'type'  => 'warn',
                        'title' => 'موجودی «' . get_the_title($target_id) . '» رو به اتمام است',
                        'text'  => 'این کالا یکی از پرتکرارترین مکمل‌های پیشنهادی سیستم است (خرید مشترک: ' . number_format_i18n((int) $row->co_occurrence) . ' بار) و فقط ' . number_format_i18n((int) $stock) . ' عدد موجودی دارد؛ پس از اتمام موجودی به‌طور خودکار از پیشنهادها کنار می‌رود و جای آن ویجت‌ها خالی‌تر می‌شود.',
                        'once'  => 'stock_' . $target_id,
                    );
                }
            }
        }
    }

    /**
     * خالی کردن کش بینش‌ها (پس از تغییر قوانین/تنظیمات/رخداد جدید)
     */
    public static function flush() {
        delete_transient(self::CACHE_KEY);
    }
}
