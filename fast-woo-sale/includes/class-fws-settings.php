<?php
/**
 * Class FWS_Settings
 * مرکز مدیریت تنظیمات افزونه — حذف کامل مقادیر هاردکد از سراسر سیستم
 * تمام آستانه‌ها (تخفیف‌ها، حداقل اطمینان، سقف ارسال رایگان و...) از این کلاس خوانده می‌شوند.
 *
 * نسخه ۲.۷: افزودن استراتژی موتور پیشنهاددهنده (خودکار/ترکیبی/دستی)،
 * قوانین دستی مدیر (Pin) و لیست سیاه محصولات (Blacklist)
 *
 * نسخه ۲.۸: سیستم کامل شخصی‌سازی ظاهر — کلید خاموش/روشن برای همه استایل‌ها و
 * ویجت‌ها، سه پیش‌تنظیم نمایشی (پیش‌فرض/مینیمال/هماهنگ با قالب)، پالت رنگ،
 * تایپوگرافی و CSS سفارشی. هدف: صفر کردن تضاد ظاهری افزونه با قالب سایت.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Settings {

        const OPTION_KEY = 'fws_prediction_settings';
        // نسخهٔ ۲.۱۲.۵ (F-42): قفل اتمی نوشتنِ تنظیمات — الگوی قفل کرون هستهٔ وردپرس
        // (INSERT روی option_name یکتا؛ add_option در صورت موجودبودن false می‌دهد).
        const WRITE_LOCK_KEY = 'fws_settings_write_lock';

        const MODE_AUTOMATIC = 'automatic_only';
        const MODE_HYBRID    = 'hybrid';
        const MODE_MANUAL    = 'manual_only';

        const PRESET_DEFAULT = 'default';
        const PRESET_MINIMAL = 'minimal';
        const PRESET_THEME   = 'theme';

        /**
         * کلیدهای ویجت‌های تزریق‌شونده در فرانت‌اند (هرکدام یک کلید خاموش/روشن مستقل دارند)
         */
        public static function widget_keys() {
                return array(
                        'enable_widget_product'  => 'باکس پکیج هوشمند در صفحه محصول',
                        'enable_widget_cart'     => 'پیشنهادات مکمل در صفحه سبد خرید',
                        'enable_widget_thankyou' => 'آپسل یک‌کلیکی صفحه تشکر',
                        'enable_widget_shipping' => 'نوار پیشرفت ارسال رایگان',
                        'enable_widget_account'  => 'ویجت پیش‌بینی خرید بعدی (حساب کاربری)',
                        'enable_search_banner'   => 'بنر پیشنهاد هوشمند در نتایج جستجو',
                );
        }

        /**
         * مقادیر پیش‌فرض سازگار با نسخه ۲.۵.۰ (رفتار قبلی افزونه)
         */
        public static function defaults() {
                return array(
                        'bundle_discount'         => 12,               // درصد تخفیف پکیج هوشمند صفحه محصول
                        // نسخهٔ ۲.۱۲ (S-02): شیوهٔ اعمال تخفیف پکیج —
                        // coupon (پیش‌فرض): کوپن برنامه‌ای «fws_bundle_discount»؛ تخفیف در سبد/تسویه/فاکتور/گزارش
                        //   به‌صورت ردیف مستقل و قابل‌مشاهده اعمال می‌شود (حسابرسی‌پذیر).
                        // price: رفتار قدیمی set_price — نامرئی در فاکتور (برای فروشگاه‌هایی که وابسته به آن‌اند).
                        'bundle_discount_mode'    => 'coupon',
                        // نسخهٔ ۲.۱۰.۲ — سقف تعداد تخفیف‌دار از هر قلم پکیج؛ تصمیم صاحب فروشگاه:
                        // ۰ = تخفیف روی همهٔ تعداد (رفتار قبلی)، ۱ = فقط یک عدد از هر قلم، N = N عدد اول
                        'bundle_discount_qty_limit' => 0,
                        'upsell_discount'         => 20,               // درصد تخفیف آپسل یک‌کلیکی صفحه تشکر
                        // نسخهٔ ۲.۱۲ (S-01): معماری آپسل تشکر —
                        // suborder (پیش‌فرض): سفارش وابستهٔ جدید با parent_id ساخته می‌شود؛ سفارش ثبت‌شده
                        //   دست‌نخورده می‌ماند (ایمیل/فاکتور/حسابداری/درگاه دیگر ناسازگار نمی‌شوند) و چرخهٔ
                        //   کامل ایمیل/موجودی/درگاه برای سفارش وابسته طبیعی اجرا می‌شود.
                        // legacy_append: رفتار قدیمی الحاق قلم به همان سفارش (برای فروشگاه‌هایی که
                        //   فرایند مالی‌شان به الحاق وابسته است) — با همان هشدار حسابداری/مؤدیان.
                        'upsell_mode'             => 'suborder',
                        // نسخهٔ ۲.۱۳ (I-43): سیاست ارسال سفارش فرعی آپسل —
                        // none (پیش‌فرض): بدون هزینهٔ ارسال؛ سیاست «ارسال همراه سفارش اصلی» در یادداشت
                        //   سفارش صریحاً ثبت می‌شود (رفتار سابق، بدون تغییر پولی برای فروشگاه فعلی).
                        // parent: کپیِ خط‌های ارسال سفارش اصلی | recalculate: محاسبهٔ واقعی تک‌کالا با
                        //   روش‌های ووکامرس | flat: مبلغ ثابت upsell_shipping_cost.
                        'upsell_shipping'         => 'none',
                        'upsell_shipping_cost'    => 0,                  // هزینهٔ ثابت ارسال (فقط در حالت flat)
                        'min_confidence'          => 60,               // حداقل ضریب اطمینان (Confidence) قوانین همبستگی
                        'min_support'             => 3,                // حداقل تعداد خرید مشترک یک جفت‌کالا
                        'free_shipping_threshold' => 2000000,          // سقف مبلغ ارسال رایگان (تومان)
                        // نسخهٔ ۲.۱۲ (S-05): خواندن سقف از min_amount واقعی روش ارسال رایگان ووکامرس
                        // (زونِ متناظر با آدرس مشتری؛ بدون تطبیق، محافظه‌کارانه بزرگ‌ترین سقف).
                        // no = عدد دستی همین فیلد (رفتار قدیمی).
                        'shipping_bar_use_wc_method' => 'yes',
                        'lookback_days'           => 90,               // بازه تحلیل سفارشات تاریخی (روز)
                        'recs_limit'              => 3,                // تعداد پیشنهادات هر ویجت
                        'enable_shortcodes'       => 'yes',            // نسخهٔ ۲.۱۱ (قاعدهٔ طلایی): کلید سراسری شورت‌کدها
                        'enable_fallback'         => 'yes',            // فعال بودن فال‌بک دسته‌بندی (Cold-Start)
                        'enable_exit_intent'      => 'yes',            // فعال بودن مودال خروج
                        // نسخهٔ ۲.۱۲ (S-03): تریگر mouseleave در موبایل هرگز فایر نمی‌شود؛
                        // پیش‌فرض «خاموش» = مودال و HTML/JS آن روی دستگاه لمسی رندر نمی‌شود.
                        // روشن = روی موبایل تریگر «اسکرول سریع رو به بالا» فعال می‌شود.
                        'exit_intent_mobile'      => 'no',
                        'enable_search_injection' => 'yes',            // فعال بودن تزریق مکمل در نتایج جستجو
                        'exit_intent_coupon'      => '',               // کد تخفیف واقعی مودال خروج (خالی = بدون وعده تخفیف)
                        // ——— فیلدهای تخصصی (نسخه ۲.۷) ———
                        'manual_override_mode'    => self::MODE_AUTOMATIC, // استراتژی موتور پیشنهاددهنده
                        'manual_rules'            => array(),          // قوانین دستی مدیر: [ ['source'=>id,'target'=>id,'confidence'=>95], ... ]
                        'product_blacklist'       => array(),          // شناسه محصولات ممنوعه در پیشنهادها
                        // ——— ظاهر و شخصی‌سازی (نسخه ۲.۸) ———
                        'style_master_enable'     => 'yes',            // کلید اصلی: بارگذاری CSS افزونه در فرانت‌اند
                        'style_preset'            => self::PRESET_DEFAULT, // پیش‌تنظیم نمایشی: default / minimal / theme
                        'enable_widget_product'   => 'yes',            // باکس پکیج صفحه محصول
                        'enable_widget_cart'      => 'yes',            // پیشنهادات سبد خرید
                        // نسخهٔ ۲.۱۰.۱ — پیش‌فرض «خاموش» (توصیهٔ بازبینی مستقل):
                        // این قابلیت یک قلم را به سفارشِ «ثبت‌شده» اضافه می‌کند؛ با وجود همهٔ گاردها،
                        // با فاکتور، حسابداری و سامانهٔ مؤدیان تداخل می‌سازد. مدیر فروشگاه باید با
                        // آگاهی کامل و پس از تطبیق با فرایند مالی خود آن را روشن کند.
                        'enable_widget_thankyou'  => 'no',             // آپسل صفحه تشکر (خاموش پیش‌فرض: تداخل با حسابداری/مؤدیان)
                        'enable_widget_shipping'  => 'yes',            // نوار ارسال رایگان
                        'enable_widget_account'   => 'yes',            // ویجت حساب کاربری
                        'enable_search_banner'    => 'yes',            // بنر بالای نتایج جستجو
                        'accent_color'            => '#f97316',        // رنگ اصلی دکمه‌ها و تاکیدها
                        'accent_text_color'       => '#ffffff',        // رنگ متن روی رنگ اصلی
                        'badge_bg_color'          => '#ffedd5',        // پس‌زمینه بج‌ها
                        'badge_text_color'        => '#f97316',        // متن بج‌ها
                        'box_bg_color'            => '#ffffff',        // پس‌زمینه کارت‌ها
                        'box_border_color'        => '#e2e8f0',        // حاشیه کارت‌ها
                        // نسخهٔ ۲.۱۲ (S-08): رنگ متن ویجت‌ها — پیش‌فرض همان #0f172a قبلی؛ در قالب‌های
                        // دارک با تنظیم همین رنگ + پس‌زمینهٔ کارت‌ها خوانایی کامل برقرار می‌شود.
                        'widget_text_color'       => '#0f172a',
                        'inherit_theme_font'      => 'yes',            // استفاده از فونت قالب (هیچ فونتی از افزونه تحمیل نمی‌شود)
                        // نسخهٔ ۲.۱۳ (I-64): پیش‌فرض از is_rtl() — فروشگاه‌های چپ‌چین (انگلیسی) بدون
                        // تغییرِ دستی، ویجت را راست‌به‌چپ نمی‌بینند (قبلاً «yes» خاموش‌نکردنیِ نامرئی
                        // چیدمان‌های LTR را می‌شکست). هستهٔ is_rtl() بدون $wp_locale امن false است.
                        // فروشگاه‌های فعلیِ ذخیره‌شدهٔ «yes» دست‌نخورده می‌مانند.
                        'force_rtl'               => ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'yes' : 'no',  // تحمیل جهت RTL به ویجت‌ها
                        'border_radius'           => 16,               // گردی گوشه‌های کارت‌ها (px)
                        'base_font_size'          => 14,               // اندازه پایه متن ویجت‌ها (px)
                        'hide_confidence_tags'    => 'no',             // مخفی‌سازی برچسب‌های درصد اطمینان
                        'show_emojis'             => 'yes',            // نمایش ایموجی‌ها در متن ویجت‌ها
                        'custom_css'              => '',               // CSS سفارشی مدیر فروشگاه
                        // ——— گزارش و بهینه‌سازی (نسخه ۲.۱۰) ———
                        'tracking_enable'         => 'yes',            // کلید اصلی ردیابی قیف تبدیل و گزارش درآمد
                        'tracking_anonymize_ip'   => 'yes',            // ناشناس‌سازی IP قبل از هش سشن (حریم خصوصی)
                        'tracking_exclude_admins' => 'yes',            // ثبت نشدن بازدید و خرید مدیران فروشگاه
                        // نسخهٔ ۲.۱۰.۱ — پیش‌فرض ۱۸۰ به ۶۰ کاهش یافت (توصیهٔ بازبینی مستقل برای مهار حجم جدول
                        // رخدادها روی هاست اشتراکی). نصب‌های موجودِ دارای مقدار ۱۸۰ یک‌بار در
                        // FWS_Tracker::maybe_upgrade به ۶۰ مهاجرت می‌شوند.
                        'tracking_retention_days' => 60,               // مدت نگهداری رخدادها (روز) — پاکسازی خودکار
                        // نسخهٔ ۲.۱۲.۱ (B-40 + توصیهٔ بازبین مستقل هفتم): قفل خودکار برندهٔ A/B —
                        // پیش‌فرض خاموش: قفلِ خودکارِ ظاهر مشتری تصمیمی تجاری است و نباید بدون
                        // آگاهی مدیر رخ دهد؛ اعلام برنده با دکمهٔ «بررسی برنده‌ها» (تصمیم صریح)
                        // همیشه در دسترس است. روشن‌کردن = کرون روزانه برندهٔ معنادار را خودکار قفل کند.
                        'ab_auto_conclude'        => 'no',
                        // نسخهٔ ۲.۱۲.۲ (B-41): پروکسی‌های معتبر (IP یا CIDR، جداشده با کاما) —
                        // فروشگاه پشت ابرآروان/کلودفلر/ریورس‌پروکسی این بازه‌ها را ثبت می‌کند تا
                        // سقف‌های نرخ (کوپن/آپسل/پکیج/nonce) به‌ازای IP واقعی هر مشتری اعمال شوند
                        // نه یک بار برای کل فروشگاه. خالی = امن‌ترین حالت (REMOTE_ADDR ملاک).
                        'trusted_proxies'         => '',
                );
        }

        /**
         * دریافت کامل تنظیمات ذخیره‌شده merged با پیش‌فرض‌ها
         */
        /**
         * In-request memo of the merged settings (BUG-16 fix v2.8.1).
         * get() is called dozens of times per render; previously each call re-read the option and
         * re-ran wp_parse_args(). Reset whenever the option is written (persist_key() or the
         * Settings API), see flush_memo().
         *
         * @var array|null
         */
        private static $memo = null;

        public static function all() {
                if ( null === self::$memo ) {
                        self::$memo = wp_parse_args( self::get_saved_raw(), self::defaults() );
                }
                return self::$memo;
        }

        /**
         * Drop the in-request memo. Hooked to update_option_/add_option_/delete_option_{OPTION_KEY}.
         */
        public static function flush_memo() {
                self::$memo = null;
        }

        /**
         * مقادیر خام ذخیره‌شده (بدون merge با پیش‌فرض‌ها)
         */
        public static function get_saved_raw() {
                $saved = get_option( self::OPTION_KEY, array() );
                return is_array( $saved ) ? $saved : array();
        }

        /**
         * خواندن یک مقدار مشخص از تنظیمات
         */
        public static function get( $key, $fallback = null ) {
                $all = self::all();
                if ( ! array_key_exists( $key, $all ) ) {
                        return $fallback;
                }
                return $all[ $key ];
        }

        /**
         * ذخیره‌سازی بخشی از تنظیمات بدون دست‌زدن به بقیه کلیدها (برای مسیر AJAX پنل مدیریت)
         */
        public static function persist_key( $key, $value ) {
                // نسخهٔ ۲.۱۲.۵ (F-42): نوشتنِ کلید تکی هم از مسیرِ قفل‌دارِ خوانشِ تازه عبور می‌کند
                // (update_option کل آرایهٔ تنظیمات را بازنویسی می‌کند؛ پایهٔ به‌یادماندهٔ ابتدای
                // درخواست می‌توانست نوشتنِ همزمانِ ادمین دیگر را پس بگیرد).
                $locked = self::acquire_write_lock();
                $result = self::write_key_fresh( $key, $value );
                if ( $locked ) {
                        self::release_write_lock();
                }
                return $result;
        }

        /**
         * نسخهٔ ۲.۱۲.۵ (F-42): به‌روزرسانی اتمیکِ کلیدهای «فهرستی» (manual_rules / product_blacklist)
         * برای نقاط پایانی AJAX پنل. قفل اتمی ← خوانشِ تازهٔ دیتابیس ← اعمال $mutator روی
         * فهرست فعلی ← نوشتن کل آرایه با پایهٔ همان خوانشِ تازه ← آزادسازی قفل.
         * $mutator( $current_list ) باید فهرست جدید یا null (انصراف بدون نوشتن، مثلاً تکراری/سقف)
         * برگرداند؛ علتِ انصراف را با متغیر reference به بیرون مخابره کنید.
         * اگر قفل پس از ~۸۰۰ms به دست نیاید، بدون قفل می‌نویسیم (fail-open؛ همان رفتار
         * قدیمی) تا نوشتن هرگز بر اثر قفل به‌جامانده مسدود نشود.
         *
         * @param string   $key
         * @param callable $mutator
         * @return bool آیا نوشتن انجام شد؟
         */
        public static function update_list( $key, $mutator ) {
                $locked = self::acquire_write_lock();
                $saved   = self::get_saved_raw();
                $current = ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) ? $saved[ $key ] : array();
                $current = self::sanitize_rules_list_like( $key, $current );
                $new     = call_user_func( $mutator, $current );
                $result  = false;
                if ( null !== $new ) {
                        $result = self::write_key_fresh( $key, $new );
                }
                if ( $locked ) {
                        self::release_write_lock();
                }
                return $result;
        }

        /**
         * نسخهٔ ۲.۱۲.۵ (F-42): پاکسازی دفاعی فهرست خام خوانده‌شده، متناسب با نوع کلید
         * (هر دو پاکساز idempotent‌اند و روی مقادیر ذخیره‌شده بی‌اثرند — راستی‌آزمایی‌شده دور ۱۰).
         */
        private static function sanitize_rules_list_like( $key, $list ) {
                if ( 'manual_rules' === $key ) {
                        return self::sanitize_rules_list( $list );
                }
                if ( 'product_blacklist' === $key ) {
                        return self::sanitize_blacklist_ids( $list );
                }
                return $list;
        }

        /**
         * نسخهٔ ۲.۱۲.۵ (F-42): قفل اتمی نوشتن — add_option روی option_name یکتا اتمی است.
         * قفل مردهٔ بیش از ۶۰ ثانیه (مرگ PHP وسط نوشتن) آزاد و دوباره تلاش می‌شود.
         */
        private static function acquire_write_lock() {
                for ( $i = 0; $i < 4; $i++ ) {
                        if ( add_option( self::WRITE_LOCK_KEY, (string) time(), '', 'no' ) ) {
                                return true;
                        }
                        $ts = (int) get_option( self::WRITE_LOCK_KEY, 0 );
                        if ( $ts > 0 && ( time() - $ts ) > 60 ) {
                                delete_option( self::WRITE_LOCK_KEY );
                                continue;
                        }
                        usleep( 200000 );
                }
                return false;
        }

        private static function release_write_lock() {
                delete_option( self::WRITE_LOCK_KEY );
        }

        /**
         * نوشتنِ یک کلید با «پایهٔ تازه» — نسخهٔ ۲.۱۲.۵ (F-42): پایه از دیتابیس (نه مموی
         * درخواست) خوانده می‌شود تا نوشتنِ همزمان‌ها «ترکیب‌پذیر» باشند نه پس‌گیرنده.
         *
         * قرارداد F-05 (نسخهٔ ۲.۱۲.۴ — بحرانی): همیشه آرایهٔ کاملِ ادغام‌شده با پیش‌فرض‌ها
         * نوشته می‌شود، نه «raw + یک کلید»؛ وگرنه sanitize با آرایهٔ ناقص، هر چک‌باکسِ غایب
         * را «no» می‌بست و اولین persist_key روی نصب تازه همهٔ ویجت‌ها را نابود می‌کرد.
         * مقدار غایب یعنی «همان مقدار مؤثر فعلی». ذخیرهٔ عادی فرم (options.php) رفتار خودش
         * را دارد: چک‌باکس تیک‌نخورده در POST نمی‌آید = خاموش.
         *
         * پارامتر سوم update_option = true (B-22، نسخهٔ ۲.۱۰.۴): این گزینه در هر درخواست
         * خوانده می‌شود؛ اگر «اولین نوشتن» با false می‌بود، وردپرس آن را برای همیشه
         * autoload=no نگه می‌داشت (تلهٔ WP < 6.6) و هر درخواست یک کوئری اختصاصی می‌پرداخت.
         */
        private static function write_key_fresh( $key, $value ) {
                $all         = wp_parse_args( self::get_saved_raw(), self::defaults() );
                $all[ $key ] = $value;
                $result      = update_option( self::OPTION_KEY, $all, true );
                self::flush_memo();
                return $result;
        }

        /**
         * پاکسازی و اعتبارسنجی خروجی فرم تنظیمات قبل از ذخیره در دیتابیس
         */
        public static function sanitize( $input ) {
                $input    = is_array( $input ) ? $input : array();
                $defaults = self::defaults();
                $saved    = self::get_saved_raw();
                $clean    = array();

                // مقادیر عددی صحیح
                $int_keys = array(
                        'bundle_discount',
                        'upsell_discount',
                        'min_confidence',
                        'min_support',
                        'free_shipping_threshold',
                        'lookback_days',
                        'recs_limit',
                        'bundle_discount_qty_limit',
                        'tracking_retention_days',
                );
                foreach ( $int_keys as $key ) {
                        $clean[ $key ] = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : (int) $defaults[ $key ];
                }

                // محدودسازی بازه‌های مجاز جهت جلوگیری از پیکربندی غلط
                $clean['bundle_discount']         = min( 90, max( 0, $clean['bundle_discount'] ) );
                $clean['bundle_discount_qty_limit'] = min( 99, max( 0, $clean['bundle_discount_qty_limit'] ) );
                $clean['upsell_discount']         = min( 90, max( 0, $clean['upsell_discount'] ) );
                $clean['min_confidence']          = min( 100, max( 1, $clean['min_confidence'] ) );
                $clean['min_support']             = min( 1000, max( 1, $clean['min_support'] ) );
                $clean['free_shipping_threshold'] = max( 0, $clean['free_shipping_threshold'] );
                $clean['lookback_days']           = min( 365, max( 7, $clean['lookback_days'] ) );
                $clean['recs_limit']              = min( 6, max( 1, $clean['recs_limit'] ) );
                $clean['tracking_retention_days'] = min( 365, max( 30, $clean['tracking_retention_days'] ) );
                // نسخهٔ ۲.۱۳ (I-43): هزینهٔ ثابت ارسال آپسل — بدون سقفِ دست‌ساز (واحد پولی فروشگاه)
                $clean['upsell_shipping_cost']    = isset( $input['upsell_shipping_cost'] )
                        ? (float) ( function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $input['upsell_shipping_cost'] ) : $input['upsell_shipping_cost'] )
                        : (float) $defaults['upsell_shipping_cost'];
                if ( $clean['upsell_shipping_cost'] < 0 ) {
                        $clean['upsell_shipping_cost'] = 0;
                }

                // چک‌باکس‌ها (yes/no)
                $checkbox_keys = array(
                        'enable_fallback',
                        'enable_exit_intent',
                        'exit_intent_mobile',      // نسخهٔ ۲.۱۲ (S-03)
                        'shipping_bar_use_wc_method', // نسخهٔ ۲.۱۲ (S-05)
                        'enable_search_injection',
                        'enable_shortcodes',
                        // ——— ظاهر و شخصی‌سازی (نسخه ۲.۸) ———
                        'style_master_enable',
                        'enable_widget_product',
                        'enable_widget_cart',
                        'enable_widget_thankyou',
                        'enable_widget_shipping',
                        'enable_widget_account',
                        'enable_search_banner',
                        'inherit_theme_font',
                        'force_rtl',
                        'hide_confidence_tags',
                        'show_emojis',
                        // ——— گزارش و بهینه‌سازی (نسخه ۲.۱۰) ———
                        'tracking_enable',
                        'tracking_anonymize_ip',
                        'tracking_exclude_admins',
                        // نسخهٔ ۲.۱۲.۱ — «ab_auto_conclude» عمداً در فهرست چک‌باکس‌ها نیست:
                        // این کلید فقط از کارت A/B (که بیرون فرم تنظیمات است) با AJAX ذخیره
                        // می‌شود؛ اگر اینجا بود، ذخیرهٔ عادی فرم (که آن را POST نمی‌کند) آن را
                        // همیشه به «no» بازمی‌گرداند — همان الگوی باگ B-33 با علامت برعکس.
                        // حفظ مقدار در انتهای همین متد انجام می‌شود.
                );
                foreach ( $checkbox_keys as $key ) {
                        $clean[ $key ] = ( isset( $input[ $key ] ) && 'yes' === $input[ $key ] ) ? 'yes' : 'no';
                }

                // ——— پالت رنگ (نسخه ۲.۸): فقط کد رنگ HEX معتبر ———
                $color_keys = array(
                        'accent_color'      => '#f97316',
                        'accent_text_color' => '#ffffff',
                        'badge_bg_color'    => '#ffedd5',
                        'badge_text_color'  => '#f97316',
                        'box_bg_color'      => '#ffffff',
                        'box_border_color'  => '#e2e8f0',
                        'widget_text_color' => '#0f172a', // نسخهٔ ۲.۱۲ (S-08)
                );
                foreach ( $color_keys as $key => $default_hex ) {
                        $raw = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : $default_hex;
                        if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $raw ) ) {
                                $clean[ $key ] = strtolower( $raw );
                        } else {
                                $clean[ $key ] = $default_hex;
                        }
                }

                // پیش‌تنظیم نمایشی (۳ حالت مجاز)
                $preset                = isset( $input['style_preset'] ) ? $input['style_preset'] : self::PRESET_DEFAULT;
                $clean['style_preset'] = in_array( $preset, array( self::PRESET_DEFAULT, self::PRESET_MINIMAL, self::PRESET_THEME ), true )
                        ? $preset
                        : self::PRESET_DEFAULT;

                // گردی گوشه‌ها و اندازه فونت با بازه محدود
                $clean['border_radius']  = isset( $input['border_radius'] ) ? absint( $input['border_radius'] ) : (int) $defaults['border_radius'];
                $clean['border_radius']  = min( 30, max( 0, $clean['border_radius'] ) );
                $clean['base_font_size'] = isset( $input['base_font_size'] ) ? absint( $input['base_font_size'] ) : (int) $defaults['base_font_size'];
                $clean['base_font_size'] = min( 18, max( 11, $clean['base_font_size'] ) );

                // CSS سفارشی مدیر فروشگاه — حذف کامل تگ‌های HTML و کاراکترهای خطرناک
                // (فقط مدیر با دسترسی manage_woocommerce می‌تواند آن را ذخیره کند)
                // نسخه ۲.۹.۲ — باگ «سلکتورهای فرزند»: قبلاً «>» هم حذف می‌شد و سِلکتورهای
                // ترکیبی مثل «.menu > li» بی‌صدا خراب می‌شدند. تنها کاراکتر خطرناک در CSS درون‌خطی
                // «<» است (خروج از بستر <style>)؛ wp_strip_all_tags خودش تگ‌ها را می‌خورد و
                // «>» باقی‌مانده در یک <style> بسته‌شده هیچ توان تزریقی ندارد — پس حفظ می‌شود.
                if ( isset( $input['custom_css'] ) ) {
                        // نسخهٔ ۲.۱۱: wp_unslash داخلی حذف شد — options.php مقدار را پیش از
                        // فراخوانی sanitize_callback خودش wp_unslash می‌کند و persist_key هم
                        // آرایهٔ تمیز می‌فرستد؛ اسلش‌زدنِ دوم، بک‌اسلش‌های مجاز CSS (مثل \") را
                        // در هر ذخیره می‌خورد.
                        $css                 = wp_strip_all_tags( (string) $input['custom_css'] );
                        $css                 = str_replace( '<', '', $css );
                        $clean['custom_css'] = substr( $css, 0, 20000 );
                } else {
                        $clean['custom_css'] = '';
                }

                // کد تخفیف مودال خروج — فقط متن ساده و ایمن
                // (نسخهٔ ۲.۱۱: wp_unslash مضاعف حذف شد — توضیح بالای custom_css)
                $clean['exit_intent_coupon'] = isset( $input['exit_intent_coupon'] )
                        ? sanitize_text_field( (string) $input['exit_intent_coupon'] )
                        : '';

                // استراتژی موتور پیشنهاددهنده (۳ حالت مجاز)
                $mode                          = isset( $input['manual_override_mode'] ) ? $input['manual_override_mode'] : self::MODE_AUTOMATIC;
                $clean['manual_override_mode'] = in_array( $mode, array( self::MODE_AUTOMATIC, self::MODE_HYBRID, self::MODE_MANUAL ), true )
                        ? $mode
                        : self::MODE_AUTOMATIC;

                // نسخهٔ ۲.۱۲ (S-02): شیوهٔ اعمال تخفیف پکیج (کوپن برنامه‌ای / قیمت مستقیم)
                $bundle_mode                    = isset( $input['bundle_discount_mode'] ) ? $input['bundle_discount_mode'] : 'coupon';
                $clean['bundle_discount_mode'] = in_array( $bundle_mode, array( 'coupon', 'price' ), true )
                        ? $bundle_mode
                        : 'coupon';

                // نسخهٔ ۲.۱۲ (S-01): معماری آپسل تشکر (سفارش وابسته / الحاق قدیمی)
                $upsell_mode          = isset( $input['upsell_mode'] ) ? $input['upsell_mode'] : 'suborder';
                $clean['upsell_mode'] = in_array( $upsell_mode, array( 'suborder', 'legacy_append' ), true )
                        ? $upsell_mode
                        : 'suborder';
                // نسخهٔ ۲.۱۳ (I-43): سیاست ارسال سفارش فرعی — فهرست بسته
                $upsell_shipping          = isset( $input['upsell_shipping'] ) ? $input['upsell_shipping'] : 'none';
                $clean['upsell_shipping'] = in_array( $upsell_shipping, array( 'none', 'parent', 'recalculate', 'flat' ), true )
                        ? $upsell_shipping
                        : 'none';

                // نکته حیاتی: قوانین دستی و بلک‌لیست از طریق AJAX جداگانه مدیریت می‌شوند.
                //
                // نسخهٔ ۲.۱۱.۱ (B-33 — بحرانی): تا پیش از این نسخه این دو کلید «همیشه» از
                // دیتابیس خوانده می‌شد و ورودی دور ریخته می‌شد؛ اما زنجیرهٔ وردپرس چنین است:
                // register_setting() در admin_init فیلتر sanitize_option_fws_prediction_settings
                // را با همین متد ثبت می‌کند، update_option() هسته «همیشه» sanitize_option() را
                // صدا می‌زند و admin-ajax.php هم اکشن admin_init را اجرا می‌کند (is_admin در
                // AJAX صحیح است). نتیجه: ajax_add_manual_rule → persist_key → update_option
                // → sanitize() مقدار جدید manual_rules را از ورودی می‌گرفت و با مقدار قدیمی
                // دیتابیس جایگزین می‌کرد؛ یعنی افزودن/حذف قانون دستی و بلک‌لیست از پنل «هرگز
                // ذخیره نمی‌شد» — AJAX موفقیت برمی‌گرداند ولی پس از reload هیچ‌چیز نبود.
                // راه‌حل (اولویت ورودی): اگر کلید در $input موجود باشد (persist_key همیشه
                // آرایهٔ کامل تنظیمات را می‌فرستد) از ورودی پاکسازی و ذخیره می‌شود؛ اگر نباشد
                // (فرم تنظیمات این دو کلید را POST نمی‌کند) از «خوانش تازهٔ» دیتابیس حفظ
                // می‌شود. نسخهٔ ۲.۱۱ (B-29) فقط رقابت اسنپ‌شات خالی را حل کرده بود، نه دور
                // ریختن ورودی را. پاکسازی مقادیر ورودی همچنان کامل از طریق
                // sanitize_rules_list / sanitize_blacklist_ids اعمال می‌شود.
                // (مهاجرت ۱۸۰→۶۰ در Tracker روی init و «پیش از» admin_init اجرا می‌شود و
                // از ابتدا از این بلا در امان بود؛ با این فیکس مسیرهای بعدی هم سالم‌اند.)
                $fresh_snapshot = get_option( self::OPTION_KEY, array() );
                if ( ! is_array( $fresh_snapshot ) || empty( $fresh_snapshot ) ) {
                        $fresh_snapshot = $saved; // سقوط به اسنپ‌شات درخواست
                }

                if ( isset( $input['manual_rules'] ) && is_array( $input['manual_rules'] ) ) {
                        // مسیر persist_key (AJAX ادمین): مقدار جدیدِ ارسالی مرجع است.
                        $rules_source = $input['manual_rules'];
                } else {
                        // مسیر فرم تنظیمات: این کلید در فرم نیست → حفظ از خوانش تازه.
                        $rules_source = isset( $fresh_snapshot['manual_rules'] ) && is_array( $fresh_snapshot['manual_rules'] )
                                ? $fresh_snapshot['manual_rules']
                                : ( isset( $saved['manual_rules'] ) && is_array( $saved['manual_rules'] ) ? $saved['manual_rules'] : array() );
                }
                $clean['manual_rules'] = self::sanitize_rules_list( $rules_source );

                if ( isset( $input['product_blacklist'] ) && is_array( $input['product_blacklist'] ) ) {
                        // مسیر persist_key: آرایهٔ خالی هم معتبر است (حذف آخرین آیتم بلک‌لیست).
                        $blacklist_source = $input['product_blacklist'];
                } else {
                        $blacklist_source = isset( $fresh_snapshot['product_blacklist'] ) && is_array( $fresh_snapshot['product_blacklist'] )
                                ? $fresh_snapshot['product_blacklist']
                                : ( isset( $saved['product_blacklist'] ) && is_array( $saved['product_blacklist'] ) ? $saved['product_blacklist'] : array() );
                }
                $clean['product_blacklist'] = self::sanitize_blacklist_ids( $blacklist_source );

                // نسخهٔ ۲.۱۲.۱ — کلید قفل خودکار برندهٔ A/B (فقط از کارت A/B با AJAX ذخیره می‌شود):
                // اگر کلید در ورودی باشد (مسیر persist_key — همان منطق input-firstِ B-33) از ورودی
                // پاکسازی می‌شود؛ اگر نباشد (ذخیرهٔ عادی فرم تنظیمات که این کلید را POST نمی‌کند)
                // از «خوانش تازهٔ» دیتابیس حفظ می‌شود تا تنظیمِ مدیر بی‌صدا به «no» برگردد.
                if ( isset( $input['ab_auto_conclude'] ) ) {
                        $clean['ab_auto_conclude'] = ( 'yes' === $input['ab_auto_conclude'] ) ? 'yes' : 'no';
                } else {
                        $fresh_ab = isset( $fresh_snapshot['ab_auto_conclude'] )
                                ? $fresh_snapshot['ab_auto_conclude']
                                : ( isset( $saved['ab_auto_conclude'] ) ? $saved['ab_auto_conclude'] : 'no' );
                        $clean['ab_auto_conclude'] = ( 'yes' === $fresh_ab ) ? 'yes' : 'no';
                }

                // نسخهٔ ۲.۱۲.۲ (B-41): فهرست پروکسی‌های معتبر — متن سادهٔ IP/CIDR جداشده با
                // کاما/فاصله/خط جدید. اعتبارسنجی نهایی (inet_pton/CIDR) در FWS_Ajax_Handler
                // انجام می‌شود؛ اینجا فقط پاکسازی متن و سقف طول (۲۰۰۰ کاراکتر ≈ ده‌ها بازه).
                if ( isset( $input['trusted_proxies'] ) ) {
                        $clean['trusted_proxies'] = substr( sanitize_text_field( (string) $input['trusted_proxies'] ), 0, 2000 );
                } else {
                        // حفظ از خوانش تازه برای مسیرهایی که این کلید را POST نمی‌کنند (الگوی B-33)
                        $fresh_tp = isset( $fresh_snapshot['trusted_proxies'] )
                                ? $fresh_snapshot['trusted_proxies']
                                : ( isset( $saved['trusted_proxies'] ) ? $saved['trusted_proxies'] : '' );
                        $clean['trusted_proxies'] = substr( sanitize_text_field( (string) $fresh_tp ), 0, 2000 );
                }

                return $clean;
        }

        /**
         * پاکسازی و نرمال‌سازی لیست قوانین دستی مدیر
         * @param array $rules
         * @return array
         */
        public static function sanitize_rules_list( $rules ) {
                $clean = array();
                if ( ! is_array( $rules ) ) {
                        return $clean;
                }
                foreach ( $rules as $rule ) {
                        if ( ! is_array( $rule ) ) {
                                continue;
                        }
                        $source     = absint( isset( $rule['source'] ) ? $rule['source'] : 0 );
                        $target     = absint( isset( $rule['target'] ) ? $rule['target'] : 0 );
                        $confidence = absint( isset( $rule['confidence'] ) ? $rule['confidence'] : 95 );

                        if ( $source <= 0 || $target <= 0 || $source === $target ) {
                                continue;
                        }
                        $clean[] = array(
                                'source'     => $source,
                                'target'     => $target,
                                'confidence' => min( 100, max( 50, $confidence ) ),
                        );
                        // سقف ایمن: حداکثر ۱۰۰ قانون دستی
                        if ( count( $clean ) >= 100 ) {
                                break;
                        }
                }
                return $clean;
        }

        /**
         * پاکسازی لیست سیاه محصولات (فقط شناسه‌های مثبت یکتا)
         * @param array $ids
         * @return array
         */
        public static function sanitize_blacklist_ids( $ids ) {
                $clean = array();
                if ( ! is_array( $ids ) ) {
                        return $clean;
                }
                foreach ( $ids as $id ) {
                        $id = absint( $id );
                        if ( $id > 0 ) {
                                $clean[] = $id;
                        }
                }
                return array_values( array_unique( array_slice( $clean, 0, 200 ) ) );
        }
}
