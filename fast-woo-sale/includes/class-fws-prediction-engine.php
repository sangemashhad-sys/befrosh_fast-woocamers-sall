<?php
/**
 * Class FWS_Prediction_Engine
 * موتور استخراج پیشنهادات هوشمند، لایه کش دو سطحی (Redis / Transients API) جهت سرعت صفر تأخیر
 *
 * نسخه ۲.۷: استراتژی سه‌حالته موتور (خودکار / ترکیبی / دستی)، قوانین دستی مدیر با اولویت،
 * و فیلتر لیست سیاه در تمام مسیرهای پیشنهاددهی
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Prediction_Engine {

        private static $instance       = null;
        private static $memoized_cache = array();
        private static $cache_ver      = null;

        public static function get_instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function get_cache_version() {
                if ( null === self::$cache_ver ) {
                        self::$cache_ver = (int) get_option( 'fws_cache_version', 1 );
                }
                return self::$cache_ver;
        }

        /**
         * نسخهٔ ۲.۹.۳ — هماهنگی کش درون‌همان-درخواست پس از بی‌اعتبارسازی:
         * FWS_Database_Miner::purge_cache() شمارهٔ نسخه را در دیتابیس بالا می‌برد، اما
         * `$cache_ver` و `$memoized_cache` همین درخواست کش (Static) قدیمی را نگه می‌داشتند؛
         * در نتیجه هر خواندن/نوشتن کشِ بعد از بی‌اعتبارسازی در همان درخواست، زیر «نسخهٔ قدیم»
         * انجام می‌شد و رکوردهایی که باید می‌مردند زنده می‌ماندند (مثلاً پس از حذف محصول،
         * کشِ همبستگیِ همان محصول دوباره زیر نسخهٔ قبلی نوشته می‌شد). اکنون پس از هر
         * purge، نسخهٔ و مموایز درون‌درخواست هم صفر می‌شود.
         * @return void
         */
        public static function reset_cache_version() {
                self::$cache_ver       = null;
                self::$memoized_cache  = array();
        }

        /**
         * نسخهٔ ۲.۹.۳ — باگ کشِ کهنهٔ ۲۴ ساعته:
         * آرایه‌های کش‌شدهٔ پیشنهاد «name/price/regular_price/image» را در لحظهٔ ساخت
         * اسنپ‌شات می‌کنند و حکم is_recommendable (قیمت‌دار/نمایان/موجود/قابل‌خرید) را هم
         * با خود منجمد می‌کنند. تغییر قیمت، شروع/پایان حراج، صفر شدن موجودی یا مخفی‌شدن
         * محصول (catalog_visibility) تا ۲۴ ساعت در ویجت‌ها دیده نمی‌شد: قیمت نمایشی باکس
         * پکیج از مبلغ واقعیِ محاسبه‌شدهٔ سبد فاصله می‌گرفت و دکمهٔ افزودنِ یک‌کلیکی روی
         * کالای ناموجود می‌ماند. با هوک‌های رسمی ووکامرس (همراه props تغییر یافته در
         * woocommerce_product_object_updated_props که برای واریاسیون‌ها هم از همان
         * Data_Store ارثی صدا زده می‌شود) هر تغییر این پراپرتی‌ها کشِ موتور را بی‌اعتبار می‌کند.
         *
         * @param WC_Product $product  شیء محصول/واریاسیون ذخیره‌شده
         * @param array      $changed  کلیدهای پراپرتی‌های تغییر یافته
         * @return void
         */
        public static function on_product_data_changed( $product, $changed_props ) {
                // نسخهٔ ۲.۱۰.۳ (B-02): «stock_quantity» از فهرست حذف شد. هر فروش، موجودی را
                // کم می‌کرد و کل کش پیشنهادات فرو می‌ریخت؛ در فروشگاه پرتردد یعنی کش همیشه
                // سرد و صفر بودن نرخ hit. هیچ ویجتی «تعداد دقیق موجودی» را نمایش نمی‌دهد؛
                // حکم قابل‌پیشنهاد‌بودن فقط به stock_status وابسته است که همچنان در فهرست است
                // و هوک‌های اختصاصی woocommerce_(variation_)set_stock_status هم پوششش می‌کنند
                // (رسیدن موجودی به صفر، وضعیت را عوض می‌کند و پاکسازی همان‌جا انجام می‌شود).
                // فهرست از این نسخه توسعه‌پذیر است تا قاعدهٔ طلایی قابل‌تنظیم بماند.
                $watched = apply_filters(
                        'fws_cache_purge_watched_props',
                        array(
                                'price',
                                'regular_price',
                                'sale_price',
                                'stock_status',
                                'catalog_visibility',
                                // نسخهٔ ۲.۱۱: تغییر نام محصول هم کش را پاک کند — ردیف‌های کش نام را
                                // منجمد می‌کنند؛ قبلاً تغییر نام تا ۲۴ ساعت در ویجت‌ها اعمال نمی‌شد.
                                'name',
                        )
                );
                if ( array_intersect( $watched, (array) $changed_props ) ) {
                        self::purge_engine_cache();
                }
        }

        /**
         * بی‌اعتبارسازی کامل کش موتور (نسخهٔ ۲.۹.۳ — برای هوک‌های وضعیت موجودی)
         * @return void
         */
        public static function purge_engine_cache() {
                if ( class_exists( 'FWS_Database_Miner' ) ) {
                        FWS_Database_Miner::purge_cache();
                }
        }

        /**
         * شناسه‌های لیست سیاه محصولات (هرگز نباید پیشنهاد شوند)
         * @return array
         */
        private function get_blacklist_ids() {
                return array_map( 'absint', (array) FWS_Settings::get( 'product_blacklist', array() ) );
        }

        /**
         * سیستم کش ۳ لایه فوق‌سریع:
         * لایه ۰: حافظه موقت PHP (Static Memoization)
         * لایه ۱: Redis / Memcached Persistent Object Cache
         * لایه ۲: جدول Transients پایگاه‌داده
         */

        /**
         * Whether a product may be SHOWN as a recommendation.
         *
         * نسخهٔ ۲.۱۱ (B-27): محصولات «متغیر» حالا قابل پیشنهادند. سابقهٔ ماجرا: ماینر روی
         * wc_order_product_lookup می‌سازد که برای واریاسیون‌ها شناسهٔ «والد» را ثبت می‌کند؛
         * پس قوانین فروشگاه‌های دارای محصول متغیر، شناسهٔ والدِ متغیر حمل می‌کنند و با گارد
         * قبلی، صددرصد این قوانین در هیچ ویجتی نمایش داده نمی‌شد (شکستِ داده/نمایش).
         * متغیر والد دیگر بلاک نیست (خودش قابل خرید نیست ولی صفحهٔ انتخاب گزینه دارد).
         * گروهی/پیوندی همچنان بلاک‌اند (هیچ صفحهٔ خرید مستقیمی ندارند/خارج از سایت‌اند).
         * «قابل افزودن یک‌کلیکی» بودن یک حکم جداگانه است: is_quick_addable().
         *
         * @param WC_Product|false|null $product
         * @return bool
         */
        public static function is_recommendable( $product ) {
                if ( ! $product instanceof WC_Product ) {
                        return false;
                }
                // نسخهٔ ۲.۱۲.۷ (H-28): وضعیت انتشار — محصولِ پیش‌نویس/خصوصی/زباله‌دانی که بعد
                // از استخراج قوانین از کاتالوگ حذف شده، دیگر نباید هیچ‌جا (ویجت، بنر جستجو،
                // تزریق نتایج، پکیج) پیشنهاد شود. is_visible() وضعیت پست را چک نمی‌کند.
                // استثنای «variation»: post_status واریشن‌ها می‌تواند inherit باشد و مفهومش
                // با والد تفاوت دارد؛ دسترس‌پذیری واریشن با is_visible/is_purchasable سنجیده
                // می‌شود (همین بالا) — گارد publish برای محصولات سطح بالاست.
                if ( ! $product->is_type( 'variation' ) && 'publish' !== $product->get_status() ) {
                        return false;
                }
                if ( ! $product->is_visible() || ! $product->is_in_stock() || ! $product->is_purchasable() ) {
                        return false;
                }
                $blocked_types = apply_filters( 'fws_non_recommendable_product_types', array( 'grouped', 'external' ) );
                if ( $product->is_type( (array) $blocked_types ) ) {
                        return false;
                }
                return (bool) apply_filters( 'fws_is_recommendable_product', true, $product );
        }

        /**
         * Whether a product can be added to the cart with ONE click (no variation picker).
         * نسخهٔ ۲.۱۱ (B-27): حکم «افزودن یک‌کلیکی» جدا شد از حکم «قابل پیشنهاد»؛
         * متغیر/گروهی/پیوندی یک‌کلیکی قابل افزودن نیستند و در UI به‌جای دکمهٔ افزودن،
         * لینک «مشاهده و انتخاب گزینه» می‌گیرند. مسیرهای افزودنِ سرور هم همین حکم را
         * به‌عنوان گارد سخت چک می‌کنند.
         *
         * @param WC_Product|false|null $product
         * @return bool
         */
        public static function is_quick_addable( $product ) {
                if ( ! $product instanceof WC_Product ) {
                        return false;
                }
                $blocked_types = apply_filters( 'fws_non_addable_product_types', array( 'variable', 'grouped', 'external' ) );
                return ! $product->is_type( (array) $blocked_types );
        }

        /**
         * قیمت «نمایشی» هم‌رو با تنظیمات مالیات ووکامرس — نسخهٔ ۲.۱۲.۲ (B-42)
         *
         * مشکل: همهٔ ویجت‌ها `get_price()` خام کاتالوگ را wc_price می‌کردند؛ در فروشگاهِ
         * دارای مالیات، قیمت کالا در ویجت با قیمت همان کالا در صفحهٔ خودش (که از
         * wc_get_price_to_display می‌گذرد) و با مبلغ سبد نمی‌خواند. حالا هر جای رندر که
         * عدد به مشتری نشان داده می‌شود از همین هلپر می‌گذرد.
         *
         * context:
         *  - 'shop'  (پیش‌فرض): همان رفتار `wc_get_price_to_display` — ویجت‌های صفحهٔ محصول،
         *    حساب کاربری و نتایج جستجو دقیقاً با قیمت صفحهٔ خود کالا می‌خوانند.
         *  - 'cart': گزینهٔ `woocommerce_tax_display_cart` — ویجت‌های سبد/نوار ارسال با
         *    اعداد همان صفحه می‌خوانند.
         *
         * نکتهٔ کش: آرایه‌های کاندیدای موتور «خام» می‌مانند و تبدیل در لایهٔ رندر انجام
         * می‌شود تا قیمت وابسته به آدرس مالیاتیِ بازدیدکننده در کش ۲۴ ساعتهِ مشترک منجمد
         * نشود (تنها استثنا: فیلرهای نوار ارسال که اصلاً کش نمی‌شوند و برای سازگاری
         * bridges_gap با «مانده تا سقف نمایشی» در خود موتور تبدیل می‌شوند).
         *
         * @param WC_Product|false|null $product
         * @param float|null            $amount  مبلغ خام؛ null = قیمت فعلی محصول
         * @param string                $context 'shop' | 'cart'
         * @return float
         */
        public static function display_price( $product, $amount = null, $context = 'shop' ) {
                $price = ( null === $amount ) ? ( ( $product instanceof WC_Product ) ? (float) $product->get_price() : 0.0 ) : (float) $amount;
                if ( ! $product instanceof WC_Product ) {
                        return $price;
                }
                if ( 'cart' !== $context && function_exists( 'wc_get_price_to_display' ) ) {
                        return (float) wc_get_price_to_display( $product, array( 'price' => $price ) );
                }
                if ( ! function_exists( 'wc_get_price_including_tax' ) || ! function_exists( 'wc_get_price_excluding_tax' ) ) {
                        return $price;
                }
                $display_option = ( 'cart' === $context ) ? 'woocommerce_tax_display_cart' : 'woocommerce_tax_display_shop';
                $incl           = ( 'incl' === get_option( $display_option, 'excl' ) );
                return $incl
                        ? (float) wc_get_price_including_tax( $product, array( 'price' => $price ) )
                        : (float) wc_get_price_excluding_tax( $product, array( 'price' => $price ) );
        }

        /**
         * قیمت نمایشی آپسل صفحهٔ تشکر بر مبنای نرخ مالیاتی «سفارش» — نسخهٔ ۲.۱۲.۲ (B-42)
         *
         * مبلغ ورودی در «فضای کاتالوگ» است (همان فضایی که get_regular_price/get_price
         * برمی‌گردانند — در فروشگاه‌های «ثبت قیمت همراه با مالیات» یعنی همراه با مالیات و
         * در حالت بدون‌ثبات یعنی بدون مالیات). خروجی در فضای نمایشی سبد
         * (woocommerce_tax_display_cart) با نرخ آدرس همین سفارش برمی‌گردد تا عدد باکس با
         * مبلغی که مشتری در همین صفحه می‌بیند یکی باشد.
         *
         * نسخهٔ ۲.۱۲.۳ (R3): شاخهٔ «نمایش بدون مالیات» دیگر ورودی را خام برنمی‌گرداند؛
         * wc_get_price_excluding_tax اعمال می‌شود — در فروشگاه‌های «ثبت قیمت همراه با
         * مالیات» ورودیِ کاتالوگ همراه با مالیات است و خام‌برگردانی یعنی نمایش مبلغ
         * با مالیات در فضای بدون مالیات (کشش بیش از واقعیت به اندازهٔ مالیات). هر دو
         * هلپر رسمی ووکامرس حول wc_prices_include_tax متقارن‌اند، پس همین فراخوانیِ ساده
         * هر دو حالتِ ثبت قیمت را درست تبدیل می‌کند.
         *
         * @param WC_Product|false|null $product
         * @param float                 $amount مبلغ در فضای کاتالوگ
         * @param WC_Order|null         $order
         * @return float
         */
        public static function order_display_price( $product, $amount, $order = null ) {
                $amount = (float) $amount;
                if ( ! $product instanceof WC_Product ) {
                        return $amount;
                }
                $display_incl = ( 'incl' === get_option( 'woocommerce_tax_display_cart', 'excl' ) );
                if ( ! function_exists( 'wc_get_price_including_tax' ) || ! function_exists( 'wc_get_price_excluding_tax' ) ) {
                        return $amount;
                }

                // نسخهٔ ۲.۱۲.۶ (G-08): هلپرهای رسمی ووکامرس آرگومان order را در تشخیص «موقعیت
                // مالیاتی» نادیده می‌گیرند (نرخ‌ها همیشه از مشتریِ جاری/پایهٔ فروشگاه می‌آیند)؛
                // نتیجه: قیمت باکس آپسل با نرخ موقعیتِ «همین مرورگر» محاسبه می‌شد نه موقعیتِ
                // سفارش. اکنون نرخ‌ها مستقیماً از آدرس سفارش خوانده می‌شوند؛ وقتی نرخ سفارش
                // قابل تشخیص نیست (مبنای مالیات = پایهٔ فروشگاه، یا سفارش غایب) همان مسیر
                // رسمی قبلی حفظ می‌شود.
                $rates = self::order_tax_rates( $product, $order );
                if ( is_array( $rates ) ) {
                        $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
                        if ( wc_prices_include_tax() ) {
                                $taxes = WC_Tax::calc_tax( $amount, $rates, true );
                                $excl  = $amount - array_sum( $taxes );
                        } else {
                                $excl = $amount;
                        }
                        if ( ! $display_incl ) {
                                return round( $excl, $decimals );
                        }
                        $taxes = WC_Tax::calc_tax( $excl, $rates, false );
                        return round( $excl + array_sum( $taxes ), $decimals );
                }

                $args = array( 'price' => $amount );
                if ( $order instanceof WC_Order ) {
                        $args['order'] = $order; // نرخ مالیاتی آدرس مشتریِ همین سفارش
                }
                if ( $display_incl ) {
                        return (float) wc_get_price_including_tax( $product, $args );
                }
                return (float) wc_get_price_excluding_tax( $product, $args );
        }

        /**
         * نسخهٔ ۲.۱۲.۶ (G-08): نرخ‌های مالیاتی بر مبنای «موقعیت سفارش».
         * خروجی: آرایهٔ نرخ‌ها (خالی = بدون مالیات) یا null یعنی تشخیص ممکن نیست /
         * لازم نیست (مبنای پایهٔ فروشگاه، سفارش غایب، کالای غیرمالیات‌پذیر) — در آن حالت
         * فراخواننده به هلپرهای رسمی ووکامرس سقوط می‌کند.
         * @param WC_Product      $product
         * @param WC_Order|null   $order
         * @return array|null
         */
        public static function order_tax_rates( $product, $order = null ) {
                if ( ! $product instanceof WC_Product || ! $product->is_taxable() || ! $order instanceof WC_Order || ! class_exists( 'WC_Tax' ) || ! function_exists( 'wc_prices_include_tax' ) ) {
                        return null;
                }
                $based_on = get_option( 'woocommerce_tax_based_on', 'shipping' );
                if ( 'base' === $based_on ) {
                        return null; // نرخ پایه برای همه یکسان است — هلپر رسمی خودش درست می‌دهد
                }
                if ( 'billing' === $based_on ) {
                        $country  = $order->get_billing_country();
                        $state    = $order->get_billing_state();
                        $city     = $order->get_billing_city();
                        $postcode = $order->get_billing_postcode();
                } else {
                        $country  = $order->get_shipping_country();
                        $state    = $order->get_shipping_state();
                        $city     = $order->get_shipping_city();
                        $postcode = $order->get_shipping_postcode();
                        if ( '' === $country ) {
                                // فروشگاه‌هایی که مالیات را از آدرس صورت‌حساب می‌گیرند وقتی ارسال ندارد
                                $country  = $order->get_billing_country();
                                $state    = $order->get_billing_state();
                                $city     = $order->get_billing_city();
                                $postcode = $order->get_billing_postcode();
                        }
                }
                if ( '' === $country ) {
                        return null;
                }
                $rates = WC_Tax::find_rates( array(
                        'country'   => $country,
                        'state'     => $state,
                        'city'      => $city,
                        'postcode'  => $postcode,
                        'tax_class' => $product->get_tax_class(),
                ) );
                return is_array( $rates ) ? $rates : array();
        }

        /**
         * نسخهٔ ۲.۱۲.۶ (G-08): تبدیل قیمت «فضای کاتالوگ» به فضای بدون مالیات با نرخِ
         * موقعیت سفارش — برای مبنای تخفیف و سقف قیمت زنده در اندپوینت آپسل.
         * در فروشگاه‌های «ثبت قیمت بدون مالیات» ورودی از قبل بدون مالیات است و دست‌نخورده
         * برمی‌گردد؛ در فروشگاه‌های «ثبت همراه با مالیات»، کسر مالیات با نرخ سفارش انجام
         * می‌شود (قبلاً wc_get_price_excluding_tax با نرخ مشتریِ جاری می‌کشت — همان انحراف
         * G-08). اگر نرخ سفارش قابل تشخیص نباشد، سقوط به هلپر رسمی (رفتار قبلی).
         * @param WC_Product      $product
         * @param float           $amount قیمت کاتالوگ (کاتالوگ = همراه‌با‌مالیات وقتی فروشگاه همراه‌با‌مالیات است)
         * @param WC_Order|null   $order
         * @return float
         */
        public static function order_price_excl( $product, $amount, $order = null ) {
                $amount = (float) $amount;
                if ( ! function_exists( 'wc_prices_include_tax' ) || ! wc_prices_include_tax() ) {
                        return $amount;
                }
                if ( ! function_exists( 'wc_get_price_excluding_tax' ) ) {
                        return $amount;
                }
                $rates = self::order_tax_rates( $product, $order );
                if ( ! is_array( $rates ) ) {
                        return (float) wc_get_price_excluding_tax( $product, array( 'price' => $amount ) );
                }
                $taxes    = WC_Tax::calc_tax( $amount, $rates, true );
                $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
                return round( $amount - array_sum( $taxes ), $decimals );
        }

        /**
         * نسخهٔ ۲.۱۲.۶ (G-23): هستهٔ خالصِ ریاضیات قیمت آپسل — قابل تست واحد بدون وردپرس.
         * مبنای تخفیف = قیمت عادی کاتالوگ (BUG-08)، درصد clamp بین ۰ تا ۹۰، و سقفِ
         * «هیچ‌وقت گران‌تر از قیمت زندهٔ فعلی» (v2.10.2). ورودی‌ها از قبل در فضای
         * بدون‌مالیاتِ سفارش (order_price_excl) تبدیل شده‌اند؛ این تابع فقط ریاضیات است.
         * @param float $basis        قیمت عادی (مبنای تخفیف)
         * @param float $live_price   قیمت زندهٔ فعلی (سقف)؛ صفر/منفی = بدون سقف
         * @param float $discount_pct درصد تخفیف آپسل
         * @param int   $decimals     اعشار قیمت ووکامرس
         * @return array{basis: float, discounted: float}
         */
        public static function compute_upsell_prices( $basis, $live_price, $discount_pct, $decimals = 2 ) {
                $basis  = max( 0.0, (float) $basis );
                $pct    = max( 0, min( 90, (float) $discount_pct ) );
                $disc   = round( $basis * ( ( 100 - $pct ) / 100 ), (int) $decimals );
                $live   = (float) $live_price;
                if ( $live > 0 && $disc > $live ) {
                        $disc = round( $live, (int) $decimals );
                }
                return array( 'basis' => $basis, 'discounted' => $disc );
        }

        private function get_cache( $key ) {
                // Tier 0: In-Memory Static Cache
                // نسخهٔ ۲.۱۲.۴ (F-18): array_key_exists به‌جای isset — مقدار null ذخیره‌شده
                // با isset همیشه «نبود» تفسیر می‌شد و درخواست‌های بعدی همان درخواست دوباره
                // مسیر سنگین را می‌رفتند.
                if ( array_key_exists( $key, self::$memoized_cache ) ) {
                        return self::$memoized_cache[ $key ];
                }

                $ver      = $this->get_cache_version();
                $full_key = "fws_{$key}_v{$ver}" . $this->locale_currency_suffix();

                // Tier 1: Check Object Cache / Redis
                $cached = wp_cache_get( $full_key, FWS_Database_Miner::CACHE_GROUP );
                if ( false !== $cached ) {
                        self::$memoized_cache[ $key ] = $cached;
                        return $cached;
                }

                // Tier 2: Fallback to Transients API for hosts without Redis
                $transient = get_transient( $full_key );
                if ( false !== $transient ) {
                        // باگ ۱۱ نسخهٔ ۲.۹: ارتقای لایهٔ ۲ به لایهٔ ۱ قبلاً با TTL ثابت ۲۴ ساعته انجام
                        // می‌شد؛ درنتیجه ورودی‌های TTL کوتاه (مثل تطبیق عنوان جستجو با TTL ۱۰ دقیقه‌ای)
                        // در Object Cache تا ۲۴ ساعت تازه‌تر از عمر واقعی‌شان باقی می‌ماندند. سقف ۱۵ دقیقه.
                        wp_cache_set( $full_key, $transient, FWS_Database_Miner::CACHE_GROUP, 15 * MINUTE_IN_SECONDS );
                        self::$memoized_cache[ $key ] = $transient;
                        return $transient;
                }

                return false;
        }

        private function set_cache( $key, $data, $ttl = null ) {
                self::$memoized_cache[ $key ] = $data;
                $ver                          = $this->get_cache_version();
                $full_key                     = "fws_{$key}_v{$ver}" . $this->locale_currency_suffix();
                $ttl                          = $ttl ? (int) $ttl : 24 * HOUR_IN_SECONDS;

                wp_cache_set( $full_key, $data, FWS_Database_Miner::CACHE_GROUP, $ttl );
                set_transient( $full_key, $data, $ttl );
        }

        /**
         * نسخهٔ ۲.۱۲.۶ (G-13): پسوند زبان/ارز برای کلیدهای کش موتور.
         * نام کالاها (چندزبانه: Polylang/WPML) و قیمت‌های فیلترشده به ارز (چندارزی)
         * قبلاً بین زبان‌ها/ارزها مشترک بود و بازدیدکنندهٔ هر زبان/ارز، نام یا قیمتِ
         * بازدیدکنندهٔ قبلی را می‌دید. کشِ مشترک حالا به تفکیک زبان و ارز ذخیره/خوانده
         * می‌شود؛ فروشگاه‌های تک‌زبانه/تک‌ارزی هیچ تغییری حس نمی‌کنند.
         * @return string
         */
        private function locale_currency_suffix() {
                $locale   = (string) get_locale();
                $currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
                if ( '' === $locale && '' === $currency ) {
                        return '';
                }
                return '_' . sanitize_key( str_replace( '-', '_', $locale ) ) . '_' . sanitize_key( $currency );
        }

        /**
         * استخراج قوانین دستی مدیر (Pin) برای یک محصول خاص — مرتب بر اساس اولویت
         * @param int   $product_id
         * @param array $blacklist
         * @return array
         */
        public function get_manual_rules_for_product( $product_id, $blacklist = array() ) {
                $product_id = absint( $product_id );
                if ( $product_id <= 0 ) {
                        return array();
                }

                $rules = (array) FWS_Settings::get( 'manual_rules', array() );
                if ( empty( $rules ) ) {
                        return array();
                }

                // اولویت نمایش بر اساس درصد اطمینان فرضی تعیین‌شده توسط مدیر
                usort(
                        $rules,
                        function ( $a, $b ) {
                                return (int) ( isset( $b['confidence'] ) ? $b['confidence'] : 0 ) <=> (int) ( isset( $a['confidence'] ) ? $a['confidence'] : 0 );
                        }
                );

                $out = array();
                foreach ( $rules as $rule ) {
                        if ( ! is_array( $rule ) ) {
                                continue;
                        }
                        if ( (int) ( isset( $rule['source'] ) ? $rule['source'] : 0 ) !== $product_id ) {
                                continue;
                        }

                        $target = absint( isset( $rule['target'] ) ? $rule['target'] : 0 );
                        if ( $target <= 0 || $target === $product_id ) {
                                continue;
                        }
                        if ( ! empty( $blacklist ) && in_array( $target, $blacklist, true ) ) {
                                continue;
                        }

                        $product = wc_get_product( $target );
                        if ( self::is_recommendable( $product ) ) {
                                $out[] = array(
                                        'product_id'    => $product->get_id(),
                                        'name'          => $product->get_name(),
                                        'price'         => (float) $product->get_price(),
                                        'is_variable'   => $product->is_type( 'variable' ),
                                        'regular_price' => (float) $product->get_regular_price(),
                                        'image'         => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                                        'confidence'    => (float) max( 50, min( 100, (int) ( isset( $rule['confidence'] ) ? $rule['confidence'] : 95 ) ) ),
                                        'co_orders'     => 0,
                                        'lift'          => 0.0,
                                        'is_fallback'   => false,
                                        'is_manual'     => true,
                                );
                        }
                }
                return $out;
        }

        /**
         * دریافت محصولات مکمل پیشنهادی — با احترام به استراتژی موتور (خودکار/ترکیبی/دستی)
         */
        public function get_recommendations_for_product( $product_id, $limit = 3 ) {
                $product_id = absint( $product_id );
                if ( $product_id <= 0 ) {
                        return array();
                }

                $limit = max( 1, (int) $limit );
                $mode  = (string) FWS_Settings::get( 'manual_override_mode', FWS_Settings::MODE_AUTOMATIC );

                $cache_key = "rec_{$product_id}_{$limit}_{$mode}";
                $cached    = $this->get_cache( $cache_key );
                if ( false !== $cached ) {
                        return $cached;
                }

                $blacklist       = $this->get_blacklist_ids();
                $seen            = array( $product_id => true ); // جلوگیری از تکرار محصول فعلی و آیتم‌های اضافه‌شده
                $recommendations = array();

                // ─── ۱) قوانین دستی مدیر (در حالت ترکیبی: اولویت‌دار | در حالت دستی: تنها منبع) ───
                if ( FWS_Settings::MODE_AUTOMATIC !== $mode ) {
                        foreach ( $this->get_manual_rules_for_product( $product_id, $blacklist ) as $manual_rec ) {
                                if ( count( $recommendations ) >= $limit ) {
                                        break;
                                }
                                $mpid = (int) $manual_rec['product_id'];
                                if ( isset( $seen[ $mpid ] ) ) {
                                        continue;
                                }
                                $seen[ $mpid ]     = true;
                                $recommendations[] = $manual_rec;
                        }
                }

                // ─── ۲) قوانین کش‌شده دیتابیس (در حالت ۱۰۰٪ دستی به‌کلی کنار گذاشته می‌شود) ───
                // نسخهٔ ۲.۱۰.۳ — گارد خودترمیم: نبود جدول affinity دیگر خطای SQL نمی‌دهد؛
                // مسیر مستقیم به فال‌بک دسته‌بندی می‌رود (جدول داخل گارد خودش را می‌سازد).
                if ( FWS_Settings::MODE_MANUAL !== $mode && count( $recommendations ) < $limit
                        && FWS_Database_Miner::affinity_table_exists() ) {
                        global $wpdb;
                        $affinity_table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;

                        // Candidate lookahead buffer: دریافت کاندیداهای بیشتر جهت تضمین پر بودن پیشنهادها
                        $candidate_limit = max( 10, ( $limit + count( $recommendations ) ) * 3 );
                        $results         = $wpdb->get_results(
                                $wpdb->prepare(
                                        "
                SELECT 
                    recommended_product_id,
                    confidence_score,
                    co_occurrence,
                    lift_score
                FROM {$affinity_table}
                WHERE source_product_id = %d
                  AND confidence_score >= %f
                ORDER BY confidence_score DESC, co_occurrence DESC
                LIMIT %d
            ",
                                        $product_id,
                                        floatval( FWS_Settings::get( 'min_confidence', 60 ) ),
                                        $candidate_limit
                                )
                        );

                        if ( ! empty( $results ) ) {
                                // Prime Core WordPress Post & PostMeta caches in a SINGLE query (رفع N+1)
                                $rec_pids = array();
                                foreach ( $results as $item ) {
                                        $rec_pids[] = absint( $item->recommended_product_id );
                                }
                                if ( ! empty( $rec_pids ) && function_exists( '_prime_post_caches' ) ) {
                                        _prime_post_caches( $rec_pids, true, true );
                                }

                                foreach ( $results as $item ) {
                                        if ( count( $recommendations ) >= $limit ) {
                                                break;
                                        }
                                        $rec_pid = absint( $item->recommended_product_id );
                                        if ( isset( $seen[ $rec_pid ] ) || in_array( $rec_pid, $blacklist, true ) ) {
                                                continue;
                                        }

                                        $product = wc_get_product( $rec_pid );
                                        if ( self::is_recommendable( $product ) ) {
                                                $seen[ $rec_pid ]  = true;
                                                $recommendations[] = array(
                                                        'product_id'    => $product->get_id(),
                                                        'name'          => $product->get_name(),
                                                        'price'         => (float) $product->get_price(),
                                                        'is_variable'   => $product->is_type( 'variable' ),
                                                        'regular_price' => (float) $product->get_regular_price(),
                                                        'image'         => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                                                        'confidence'    => (float) $item->confidence_score,
                                                        'co_orders'     => (int) $item->co_occurrence,
                                                        'lift'          => (float) $item->lift_score,
                                                        'is_fallback'   => false,
                                                        'is_manual'     => false,
                                                );
                                        }
                                }
                        }
                }

                // ─── ۳) فال‌بک هوشمند دسته‌بندی (در حالت ۱۰۰٪ دستی غیرفعال) ───
                if ( count( $recommendations ) < $limit
                        && FWS_Settings::MODE_MANUAL !== $mode
                        && 'yes' === FWS_Settings::get( 'enable_fallback', 'yes' )
                ) {
                        $needed      = $limit - count( $recommendations );
                        $exclude_ids = array_merge( array_keys( $seen ), $blacklist );
                        $fallbacks   = $this->get_category_fallbacks( $product_id, $needed, $exclude_ids );
                        foreach ( $fallbacks as $fb ) {
                                $recommendations[] = $fb;
                        }
                }

                $recommendations = apply_filters( 'fws_product_recommendations', $recommendations, $product_id, $limit );
                $this->set_cache( $cache_key, $recommendations );
                return $recommendations;
        }

        /**
         * فال‌بک هوشمند دسته‌بندی برای کالاهای بدون سابقه سبد خرید (حل مسئله Cold-Start)
         */
        public function get_category_fallbacks( $product_id, $limit = 2, $exclude_ids = array() ) {
                $terms = wc_get_product_term_ids( $product_id, 'product_cat' );
                if ( empty( $terms ) ) {
                        return array();
                }

                $args = array(
                        'post_type'              => 'product',
                        'post_status'            => 'publish',
                        'posts_per_page'         => max( 4, $limit * 2 ),
                        'post__not_in'           => $exclude_ids,
                        'tax_query'              => array(
                                array(
                                        'taxonomy' => 'product_cat',
                                        'field'    => 'term_id',
                                        'terms'    => $terms,
                                ),
                        ),
                        'meta_key'               => 'total_sales',
                        'orderby'                => 'meta_value_num',
                        'order'                  => 'DESC',
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'update_post_term_cache' => false,
                        'update_post_meta_cache' => false,
                );

                // نسخهٔ ۲.۱۳ (I-58): فقط limit×۲ نامزد خوانده می‌شد و پس از فیلترِ
                // is_recommendable (ناموجود/مخفی/…) کمبود جایگزین می‌ماند و کوئری دوباره
                // اجرا نمی‌شد. اکنون تا پرشدن سهمیه صفحه‌بندی می‌شود (سقف پیش‌فرض ۴ صفحه ×
                // limit×۲ = حداکثر ~۳۲ نامزد برای limit=۴) تا ویجت همیشه تعداد تنظیم‌شده را
                // نشان دهد؛ معنای فیلتر دقیقاً همان is_recommendable می‌ماند.
                $fallbacks = array();
                $per_page  = max( 4, $limit * 2 );
                $page      = 1;
                $max_pages = max( 1, (int) apply_filters( 'fws_fallback_max_pages', 4, $product_id, $limit ) );

                while ( count( $fallbacks ) < $limit && $page <= $max_pages ) {
                        $args['paged'] = $page;
                        $query         = new WP_Query( $args );

                        if ( ! $query->have_posts() ) {
                                break;
                        }
                        $returned = count( $query->posts );

                        foreach ( $query->posts as $fb_id ) {
                                if ( count( $fallbacks ) >= $limit ) {
                                        break;
                                }
                                $prod = wc_get_product( $fb_id );
                                if ( self::is_recommendable( $prod ) ) {
                                        $fallbacks[] = array(
                                                'product_id'    => $prod->get_id(),
                                                'name'          => $prod->get_name(),
                                                'price'         => (float) $prod->get_price(),
                                                'is_variable'   => $prod->is_type( 'variable' ),
                                                'regular_price' => (float) $prod->get_regular_price(),
                                                'image'         => wp_get_attachment_image_url( $prod->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                                                // صداقت آماری: برای فال‌بک هیچ درصد همبستگی ساختگی نمایش داده نمی‌شود
                                                'confidence'    => 0.0,
                                                'co_orders'     => 0,
                                                'lift'          => 0.0,
                                                'is_fallback'   => true,
                                                'is_manual'     => false,
                                        );
                                }
                        }

                        if ( $returned < $per_page ) {
                                break; // آخرین صفحهٔ موجود — صفحهٔ بعد خالی است
                        }
                        $page++;
                }

                return apply_filters( 'fws_category_fallbacks', $fallbacks, $product_id, $limit );
        }

        /**
         * هوش مالی سبد خرید: پیدا کردن کالای پرکننده بهینه برای رسیدن به سقف ارسال رایگان
         */
        public function get_free_shipping_fillers( $cart_total, $threshold, $limit = 0 ) {
                if ( $cart_total >= $threshold ) {
                        return array();
                }
                // BUG-09 fix (v2.8.1): honour the admin "recommendations per widget" setting and the
                // minimum confidence instead of the hard-coded 4 / LIMIT 8.
                $limit          = $limit > 0 ? (int) $limit : max( 1, min( 6, (int) FWS_Settings::get( 'recs_limit', 3 ) ) );
                $min_confidence = (float) FWS_Settings::get( 'min_confidence', 60 );
                $sql_limit      = $limit * 3;

                $gap              = $threshold - $cart_total;
                $cart_items       = WC()->cart ? WC()->cart->get_cart() : array();
                $cart_product_ids = array();
                foreach ( $cart_items as $item ) {
                        $cart_product_ids[] = absint( $item['product_id'] );
                }

                $cart_product_ids = array_filter( array_unique( array_map( 'absint', $cart_product_ids ) ) );
                if ( empty( $cart_product_ids ) ) {
                        return array();
                }

                $blacklist = $this->get_blacklist_ids();

                global $wpdb;
                // نسخهٔ ۲.۱۰.۳ — گارد خودترمیم جدول affinity (بدون جدول، بدون خطا)
                if ( ! FWS_Database_Miner::affinity_table_exists() ) {
                        return array();
                }
                $affinity_table  = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
                $in_placeholders = implode( ',', array_fill( 0, count( $cart_product_ids ), '%d' ) );

                // Find accessories or complementary items whose price bridges the free-shipping gap
                $candidates = $wpdb->get_results(
                        $wpdb->prepare(
                                "
            SELECT recommended_product_id, MAX(confidence_score) as max_conf
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
              AND confidence_score >= %f
            GROUP BY recommended_product_id
            ORDER BY max_conf DESC
            LIMIT %d
        ",
                                array_merge( $cart_product_ids, $cart_product_ids, array( $min_confidence, $sql_limit ) )
                        )
                );

                $fillers = array();
                if ( ! empty( $candidates ) ) {
                        foreach ( $candidates as $cand ) {
                                if ( count( $fillers ) >= $limit ) {
                                        break;
                                }
                                $cand_pid = absint( $cand->recommended_product_id );
                                if ( in_array( $cand_pid, $blacklist, true ) ) {
                                        continue;
                                }

                                $prod = wc_get_product( $cand_pid );
                                if ( self::is_recommendable( $prod ) ) {
                                        $price     = (float) $prod->get_price();
                                        // نسخهٔ ۲.۱۲.۲ (B-42): فیلرها کش نمی‌شوند، پس تبدیل به «فضای نمایشی
                                        // سبد» همین‌جا انجام می‌شود — هم عدد ویجت و هم قضاوت bridges_gap با
                                        // «مانده تا سقف» (که خودش از جمع نمایشی سبد می‌آید) هم‌فضا می‌شوند؛
                                        // قبلاً قیمت خام کاتالوگ با ماندهٔ نمایشی مقایسه می‌شد و در فروشگاه
                                        // دارای مالیات قاعدهٔ «پر کردن شکاف» دروغ می‌گفت.
                                        $disp_price = self::display_price( $prod, $price, 'cart' );
                                        $fillers[] = array(
                                                'product_id'  => $prod->get_id(),
                                                'name'        => $prod->get_name(),
                                                'price'       => $disp_price,
                                                'is_variable' => $prod->is_type( 'variable' ),
                                                'image'       => wp_get_attachment_image_url( $prod->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                                                'confidence'  => (float) $cand->max_conf,
                                                // نسخهٔ ۲.۱۱ (B-27): برای محصول متغیر، قیمت = ارزان‌ترین واریاسیون؛
                                                // چون انتخاب گزینهٔ نهایی با مشتری است، وعدهٔ «پر کردن شکاف» صادقانه
                                                // false می‌شود تا زیر عنوان عمومی نمایش داده شود نه وعدهٔ سقف.
                                                'bridges_gap' => ( ! $prod->is_type( 'variable' ) && $disp_price >= $gap ),
                                        );
                                }
                        }
                }

                // نسخه ۲.۹ — کالاهایی که شکاف ارسال رایگان را واقعاً پر می‌کنند اول نمایش داده می‌شوند
                // تا عنوان «برای رسیدن به سقف ارسال رایگان» برای ردیف اول معنا داشته باشد.
                usort(
                        $fillers,
                        static function ( $a, $b ) {
                                if ( $a['bridges_gap'] !== $b['bridges_gap'] ) {
                                        return $a['bridges_gap'] ? -1 : 1;
                                }
                                return $b['confidence'] <=> $a['confidence'];
                        }
                );

                return $fillers;
        }

        /**
         * پیش‌بینی محصول مکمل برتر پس از ثبت سفارش در صفحه تشکر (1-Click Post Purchase Upsell)
         * نسخه ۲.۷: پیمایش ۵ کاندیدای برتر + فیلتر لیست سیاه + بررسی is_visible
         */
        public function get_post_purchase_upsell( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                        return null;
                }

                $purchased_ids = array();
                foreach ( $order->get_items() as $item ) {
                        $purchased_ids[] = absint( $item->get_product_id() );
                }
                $purchased_ids = array_filter( array_unique( array_map( 'absint', $purchased_ids ) ) );
                if ( empty( $purchased_ids ) ) {
                        return null;
                }

                $blacklist = $this->get_blacklist_ids();

                // نسخهٔ ۲.۱۰.۳ — گارد خودترمیم جدول affinity
                if ( ! FWS_Database_Miner::affinity_table_exists() ) {
                        return null;
                }

                global $wpdb;
                $affinity_table  = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
                $in_placeholders = implode( ',', array_fill( 0, count( $purchased_ids ), '%d' ) );

                // BUG-02 fix (v2.9.0): the admin "minimum confidence" setting is honoured here too;
                // previously only get_recommendations_for_product / get_free_shipping_fillers filtered it.
                $min_confidence = floatval( FWS_Settings::get( 'min_confidence', 60 ) );
                $top_matches    = $wpdb->get_results(
                        $wpdb->prepare(
                                "
            SELECT recommended_product_id, MAX(confidence_score) as best_conf, MAX(lift_score) as best_lift
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
              AND confidence_score >= %f
            GROUP BY recommended_product_id
            ORDER BY best_conf DESC, best_lift DESC
            LIMIT 5
        ",
                                array_merge( $purchased_ids, $purchased_ids, array( $min_confidence ) )
                        )
                );

                if ( empty( $top_matches ) ) {
                        return null;
                }

                foreach ( $top_matches as $top_match ) {
                        $candidate_pid = absint( $top_match->recommended_product_id );
                        if ( in_array( $candidate_pid, $blacklist, true ) ) {
                                continue;
                        }

                        $product = wc_get_product( $candidate_pid );
                        // نسخهٔ ۲.۱۱ (B-27): آپسل = افزودن یک‌کلیکیِ واقعی به سفارش؛ محصول متغیر
                        // بدون انتخاب گزینه قابل افزودن نیست پس کاندیدای آپسل نمی‌شود (کاندیدای
                        // بعدی بررسی می‌شود) تا «باکس تخفیف با دکمهٔ مرده» رندر نشود.
                        if ( ! self::is_recommendable( $product ) || ! self::is_quick_addable( $product ) ) {
                                continue;
                        }

                        // BUG-08 fix (v2.9.0): the struck-through "was" price must be the catalogue
                        // regular price, not the current price which may itself be on sale — otherwise
                        // the displayed discount exaggerates the real saving. Fall back to the current
                        // price when no regular price is set (0/null).
                        $regular = (float) $product->get_regular_price();
                        if ( $regular <= 0 ) {
                                $regular = (float) $product->get_price();
                        }
                        $discount_pct = max( 0, min( 90, floatval( FWS_Settings::get( 'upsell_discount', 20 ) ) ) );
                        $discounted   = round( $regular * ( ( 100 - $discount_pct ) / 100 ), wc_get_price_decimals() );

                        // نسخه ۲.۱۰.۲ — گام ۱ (قیمت آپسل هرگز از فروشگاه گران‌تر نمی‌شود):
                        // اگر محصول هم‌اکنون حراج عمیق‌تری از تخفیف آپسل داشته باشد، قیمت
                        // نهایی تا قیمت فعلی فروشگاه محدود می‌شود تا مشتری از باکس پیشنهاد
                        // گران‌تر از خرید مستقیم پرداخت نکند.
                        //
                        // نسخهٔ ۲.۱۲.۳ (R2): سقف در «همان فضای» مبلغ تخفیف اعمال می‌شود —
                        // فضای کاتالوگ. قبلاً get_price به wc_get_price_excluding_tax می‌گذشت
                        // (بازماندهٔ هم‌ترازی B-31) و در فروشگاه‌های «ثبت قیمت همراه با مالیات»
                        // عددِ فضای بدون‌مالیات با ۸۰٪ِ فضای کاتالوگ مقایسه می‌شد؛ سقفِ
                        // قلم‌شده برای نمایش از فضای اشتباه می‌آمد و باکس کمتر از مبلغ واقعی
                        // واریزی نشان می‌داد. شارژ نهایی توسط endpoint مستقلاً در فضای
                        // بدون‌مالیات و با همان سقفِ درست محاسبه می‌شود؛ اینجا فقط نمایش است.
                        $fresh_price = (float) $product->get_price();
                        if ( $fresh_price > 0 && $discounted > $fresh_price ) {
                                $discounted = $fresh_price;
                        }

                        return array(
                                'product_id'       => $product->get_id(),
                                'name'             => $product->get_name(),
                                'regular_price'    => $regular,
                                'discounted_price' => $discounted,
                                'discount_percent' => $discount_pct,
                                'confidence'       => (float) $top_match->best_conf,
                                'image'            => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                        );
                }

                return null;
        }

        /**
         * پیش‌بینی خرید بعدی کاربر لاگین‌شده بر اساس سابقه خریدهای قبلی (Personalized RFM)
         * نسخه ۲.۷: فیلتر لیست سیاه + کش نتیجه منفی (حذف کوئری‌های تکراری صفحه حساب کاربری)
         */
        /**
         * Invalidate the cached "next purchase" prediction of a single user.
         *
         * BUG-11 fix (v2.8.1): the 24h `user_rec_{id}` entry was never purged when the customer
         * placed a new order, so the account widget kept recommending the product just bought.
         * Hooked to woocommerce_new_order / order status transitions in FWS_Display_Hooks.
         *
         * @param int $user_id
         * @return void
         */
        public function purge_user_prediction_cache( $user_id ) {
                $user_id = absint( $user_id );
                if ( $user_id <= 0 ) {
                        return;
                }
                $key      = "user_rec_{$user_id}";
                $full_key = "fws_{$key}_v" . $this->get_cache_version();
                unset( self::$memoized_cache[ $key ] );
                wp_cache_delete( $full_key, FWS_Database_Miner::CACHE_GROUP );
                delete_transient( $full_key );
        }

        /**
         * Order hook adapter: purge the prediction cache of the order's customer.
         *
         * @param int $order_id
         * @return void
         */
        public static function on_order_changed( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order && $order->get_user_id() ) {
                        self::get_instance()->purge_user_prediction_cache( $order->get_user_id() );
                }
        }

        public function get_user_next_purchase_prediction( $user_id ) {
                $user_id = absint( $user_id );
                if ( $user_id <= 0 ) {
                        return null;
                }

                $cache_key = "user_rec_{$user_id}";
                $cached    = $this->get_cache( $cache_key );
                if ( false !== $cached ) {
                        // نسخهٔ ۲.۱۲.۴ (F-18): نماد «بدون پیشنهاد» (آرایهٔ خالی) دوباره null می‌شود؛
                        // قرارداد خروجی متد برای فراخواننده‌ها دست‌نخورده می‌ماند.
                        return ( is_array( $cached ) && empty( $cached ) ) ? null : $cached;
                }

                $prediction = $this->build_user_prediction( $user_id );

                // کش حتی برای حالت «بدون پیشنهاد» تا صفحه حساب کاربری هر بار کوئری سنگین نزند.
                // (F-18) نُل از لایهٔ ترانزینت قابل بازگشت نیست (set_transient با null →
                // get_transient آن را «نبود» می‌خواند) پس نماد آرایهٔ خالی ذخیره می‌شود.
                $this->set_cache( $cache_key, null === $prediction ? array() : $prediction );
                return $prediction;
        }

        /**
         * ساخت پیش‌بینی خرید بعدی کاربر (بدون لایه کش)
         */
        private function build_user_prediction( $user_id ) {
                $blacklist = $this->get_blacklist_ids();

                $customer_orders = wc_get_orders(
                        array(
                                'customer' => $user_id,
                                'limit'    => 10,
                                // نسخهٔ ۲.۱۲ (S-04): وضعیت‌های پرداخت‌شده از منبع رسمی ووکامرس —
                                // فروشگاه با وضعیت سفارشی «پرداخت‌شده» هم پوشش داده می‌شود.
                                'status'   => function_exists( 'wc_get_is_paid_statuses' )
                                        ? (array) wc_get_is_paid_statuses()
                                        : array( 'completed', 'processing' ),
                                'orderby'  => 'date',
                                'order'    => 'DESC',
                        )
                );

                if ( empty( $customer_orders ) ) {
                        return null;
                }

                $purchased_product_ids = array();
                foreach ( $customer_orders as $order ) {
                        foreach ( $order->get_items() as $item ) {
                                $purchased_product_ids[] = absint( $item->get_product_id() );
                        }
                }
                $purchased_product_ids = array_filter( array_unique( array_map( 'absint', $purchased_product_ids ) ) );
                if ( empty( $purchased_product_ids ) ) {
                        return null;
                }

                // نسخهٔ ۲.۱۰.۳ — گارد خودترمیم جدول affinity
                if ( ! FWS_Database_Miner::affinity_table_exists() ) {
                        return null;
                }

                global $wpdb;
                $affinity_table  = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
                $in_placeholders = implode( ',', array_fill( 0, count( $purchased_product_ids ), '%d' ) );

                // BUG-02 fix (v2.9.0): min_confidence filter added to the user-prediction path too.
                $candidates = $wpdb->get_results(
                        $wpdb->prepare(
                                "
            SELECT recommended_product_id, AVG(confidence_score) as avg_conf, SUM(co_occurrence) as sum_orders
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
              AND confidence_score >= %f
            GROUP BY recommended_product_id
            ORDER BY avg_conf DESC
            LIMIT 8
        ",
                                array_merge( $purchased_product_ids, $purchased_product_ids, array( floatval( FWS_Settings::get( 'min_confidence', 60 ) ) ) )
                        )
                );

                if ( ! empty( $candidates ) ) {
                        foreach ( $candidates as $candidate ) {
                                $candidate_pid = absint( $candidate->recommended_product_id );
                                if ( in_array( $candidate_pid, $blacklist, true ) ) {
                                        continue;
                                }

                                $product = wc_get_product( $candidate_pid );
                                if ( self::is_recommendable( $product ) ) {
                                        return array(
                                                'product_id' => $product->get_id(),
                                                'name'       => $product->get_name(),
                                                'price'      => (float) $product->get_price(),
                                                'is_variable' => $product->is_type( 'variable' ),
                                                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                                                'confidence' => round( $candidate->avg_conf, 1 ),
                                        );
                                }
                        }
                }

                return null;
        }

        /**
         * استخراج فوق‌سریع کل پیشنهادات سبد خرید با یک کوئری تجمیعی ریاضی به جای کوئری‌های تکراری
         */
        public function get_cart_recommendations( $cart_product_ids, $limit = 3 ) {
                $cart_product_ids = array_filter( array_unique( array_map( 'absint', (array) $cart_product_ids ) ) );
                if ( empty( $cart_product_ids ) ) {
                        return array();
                }

                // نسخهٔ ۲.۹.۳ — مرتب‌سازی شناسه‌ها: کلید کش نسبت به ترتیب آیتم‌های سبد
                // بی‌حس می‌شود؛ سبد [A,B] و [B,A] دیگر دو ردیف کش جداگانه نمی‌سازند.
                // (کلید کش از ورودیِ اصلی ساخته می‌شود تا با منطق گسترش H-27 مستقل بماند.)
                $cart_product_ids = array_values( $cart_product_ids );
                sort( $cart_product_ids );
                $cache_key = 'cart_recs_' . md5( implode( '_', $cart_product_ids ) ) . "_{$limit}";
                $cached    = $this->get_cache( $cache_key );
                if ( false !== $cached ) {
                        return $cached;
                }

                // نسخهٔ ۲.۱۲.۷ (H-27): قوانین affinity همیشه با شناسهٔ «والد» ذخیره می‌شوند، ولی
                // فراخواننده ممکن است شناسهٔ واریشن بفرستد (یا برعکس). ورودی به مجموعهٔ کامل
                // والد/واریشن گسترش داده می‌شود تا هم IN قوانین را از دست ندهد و هم NOT IN
                // والدِ کالایِ داخل سبد را حتماً حذف کند — موتور دیگر به قرارداد فراخواننده وابسته نیست.
                // (بعد از cache-check تا مسیر کش‌خورده هیچ کوئری اضافه نپردازد.)
                $fws_cart_related = $cart_product_ids;
                foreach ( $cart_product_ids as $fws_cid ) {
                        $fws_cprod = wc_get_product( $fws_cid );
                        if ( $fws_cprod instanceof WC_Product ) {
                                if ( $fws_cprod->is_type( 'variation' ) ) {
                                        $fws_parent_id = (int) $fws_cprod->get_parent_id();
                                        if ( $fws_parent_id > 0 ) {
                                                $fws_cart_related[] = $fws_parent_id;
                                        }
                                } else {
                                        $fws_cart_related[] = (int) $fws_cprod->get_id();
                                }
                        }
                }
                $fws_cart_related = array_values( array_unique( array_filter( array_map( 'absint', $fws_cart_related ) ) ) );

                $blacklist = $this->get_blacklist_ids();

                global $wpdb;
                // نسخهٔ ۲.۱۰.۳ — گارد خودترمیم جدول affinity (بدون جدول، بدون خطا)
                if ( ! FWS_Database_Miner::affinity_table_exists() ) {
                        $this->set_cache( $cache_key, array() );
                        return array();
                }
                $affinity_table  = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
                $fws_cart_related = array_values( $fws_cart_related );
                sort( $fws_cart_related );
                $in_placeholders = implode( ',', array_fill( 0, count( $fws_cart_related ), '%d' ) );

                $candidate_limit = max( 10, $limit * 3 );
                // BUG-02 fix (v2.9.0): min_confidence filter added to the cart-widget path too.
                $query_params    = array_merge(
                        $fws_cart_related,
                        $fws_cart_related,
                        array( floatval( FWS_Settings::get( 'min_confidence', 60 ) ), $candidate_limit )
                );
                $results         = $wpdb->get_results(
                        $wpdb->prepare(
                                "
            SELECT 
                recommended_product_id, 
                MAX(confidence_score) as best_conf, 
                SUM(co_occurrence) as total_co
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
              AND confidence_score >= %f
            GROUP BY recommended_product_id
            ORDER BY best_conf DESC, total_co DESC
            LIMIT %d
        ",
                                $query_params
                        )
                );

                $recommendations = array();
                if ( ! empty( $results ) ) {
                        // Prime Core WordPress Post & PostMeta caches in a single query
                        $rec_pids = array();
                        foreach ( $results as $row ) {
                                $rec_pids[] = absint( $row->recommended_product_id );
                        }
                        if ( ! empty( $rec_pids ) && function_exists( '_prime_post_caches' ) ) {
                                _prime_post_caches( $rec_pids, true, true );
                        }

                        // نسخهٔ ۲.۱۲.۷ (H-27): گارد dedup نتیجه — SQL با GROUP BY یکتاست ولی
                        // حلقهٔ رندر هرگز نباید به یکتایی سطح کوئری تکیه کند.
                        $fws_seen = array();
                        foreach ( $results as $row ) {
                                if ( count( $recommendations ) >= $limit ) {
                                        break;
                                }
                                $rec_pid = absint( $row->recommended_product_id );
                                if ( isset( $fws_seen[ $rec_pid ] ) ) {
                                        continue;
                                }
                                $fws_seen[ $rec_pid ] = true;
                                if ( in_array( $rec_pid, $blacklist, true ) ) {
                                        continue;
                                }

                                $product = wc_get_product( $rec_pid );
                                if ( self::is_recommendable( $product ) ) {
                                        $recommendations[] = array(
                                                'product_id' => $product->get_id(),
                                                'name'       => $product->get_name(),
                                                'price'      => (float) $product->get_price(),
                                                'is_variable' => $product->is_type( 'variable' ),
                                                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                                                'confidence' => (float) $row->best_conf,
                                        );
                                }
                        }
                }

                $this->set_cache( $cache_key, $recommendations );
                return $recommendations;
        }

        /**
         * پیش‌بینی و استخراج کالاهای مکمل با بیشترین نرخ خرید همزمان برای عبارت جستجوشده کاربر
         */
        public function get_search_co_occurrence_recommendations( $search_query, $limit = 2 ) {
                $search_query = sanitize_text_field( trim( $search_query ) );
                if ( empty( $search_query ) || mb_strlen( $search_query ) < 2 ) {
                        return array();
                }
                // BUG-06 fix (v2.8.1): the search term is attacker-controlled; never derive a persistent
                // cache key from it (each unique term used to create two 24h transient rows in wp_options).
                // Cap the term length, and key the cache on the *set of matched product ids* below, whose
                // key space is bounded by the catalogue. Empty match-sets are persisted as well (short
                // 10-minute TTL) so repeated junk terms never re-trigger the heavy LIKE scan.
                $search_query = mb_substr( $search_query, 0, 60 );

                $blacklist = $this->get_blacklist_ids();

                global $wpdb;

                // 1. پیدا کردن آیدی محصولات تطبیق‌یافته با کلمه کلیدی جستجو (فقط روی عنوان کالا جهت استفاده ۱۰۰٪ از ایندکس)
                // BUG-06 fix (v2.9.0): this LIKE '%term%' scan cannot use any index; BUG-06 of v2.8.1 only
                // cached the SECOND query (affinity), so this heavy scan still ran on every search page
                // view before any cache check. The term match step itself is now cached for a short
                // TTL. The key space is bounded in practice: terms are capped at 60 chars and rows
                // expire after 10 minutes (and are lazily purged / handled by wp_scheduled_delete),
                // so the wp_options growth that BUG-06 (v2.8.1) worried about does not come back.
                $match_cache_key      = 'search_match_' . md5( $search_query );
                $matching_product_ids = $this->get_cache( $match_cache_key );
                if ( false === $matching_product_ids ) {
                        $wildcard             = '%' . $wpdb->esc_like( $search_query ) . '%';
                        $matching_product_ids = $wpdb->get_col(
                                $wpdb->prepare(
                                        "
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product'
              AND post_status = 'publish'
              AND post_title LIKE %s
            ORDER BY ID DESC
            LIMIT 6
        ",
                                        $wildcard
                                )
                        );
                        $matching_product_ids = $matching_product_ids ? array_map( 'intval', $matching_product_ids ) : array();
                        sort( $matching_product_ids );
                        $this->set_cache( $match_cache_key, $matching_product_ids, 10 * MINUTE_IN_SECONDS );
                }
                $matching_product_ids = array_map( 'intval', (array) $matching_product_ids );

                if ( empty( $matching_product_ids ) ) {
                        return array();
                }

                $cache_key = 'search_rec_' . md5( implode( ',', $matching_product_ids ) ) . "_{$limit}";
                $cached    = $this->get_cache( $cache_key );
                if ( false !== $cached ) {
                        return $cached;
                }

                $affinity_table  = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
                $in_placeholders = implode( ',', array_fill( 0, count( $matching_product_ids ), '%d' ) );

                // 2. کوئری استخراج مکمل‌ها با استفاده از ایندکس ترکیبی پوششی (Covering Index)
                // BUG-02 fix (v2.9.0): min_confidence filter added to the search-banner path too.
                // نسخهٔ ۲.۱۰.۳ — گارد خودترمیم جدول affinity
                if ( ! FWS_Database_Miner::affinity_table_exists() ) {
                        $this->set_cache( $cache_key, array(), 10 * MINUTE_IN_SECONDS );
                        return array();
                }
                $query_params = array_merge(
                        $matching_product_ids,
                        $matching_product_ids,
                        array( floatval( FWS_Settings::get( 'min_confidence', 60 ) ), $limit * 3 )
                );
                $results      = $wpdb->get_results(
                        $wpdb->prepare(
                                "
            SELECT
                recommended_product_id,
                MAX(source_product_id) AS source_product_id,
                MAX(confidence_score) as max_conf,
                SUM(co_occurrence) as total_co
            FROM {$affinity_table}
            WHERE source_product_id IN ({$in_placeholders})
              AND recommended_product_id NOT IN ({$in_placeholders})
              AND confidence_score >= %f
            GROUP BY recommended_product_id
            ORDER BY total_co DESC, max_conf DESC
            LIMIT %d
        ",
                                $query_params
                        )
                );

                $recommendations = array();
                if ( ! empty( $results ) ) {
                        $prime_ids = array();
                        foreach ( $results as $row ) {
                                $prime_ids[] = absint( $row->recommended_product_id );
                                $prime_ids[] = absint( $row->source_product_id );
                        }
                        if ( ! empty( $prime_ids ) && function_exists( '_prime_post_caches' ) ) {
                                _prime_post_caches( array_unique( $prime_ids ), true, true );
                        }

                        foreach ( $results as $row ) {
                                if ( count( $recommendations ) >= $limit ) {
                                        break;
                                }
                                $rec_pid = absint( $row->recommended_product_id );
                                if ( in_array( $rec_pid, $blacklist, true ) ) {
                                        continue;
                                }

                                $product = wc_get_product( $rec_pid );
                                $source  = wc_get_product( $row->source_product_id );
                                if ( self::is_recommendable( $product ) ) {
                                        $recommendations[] = array(
                                                'product_id'    => $product->get_id(),
                                                'name'          => $product->get_name(),
                                                'price'         => (float) $product->get_price(),
                                                'is_variable'   => $product->is_type( 'variable' ),
                                                'regular_price' => (float) $product->get_regular_price() ?: (float) $product->get_price(),
                                                'image'         => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
                                                'confidence'    => round( (float) $row->max_conf, 1 ),
                                                'co_occurrence' => (int) $row->total_co,
                                                'source_name'   => $source ? $source->get_name() : $search_query,
                                                'reason'        => sprintf( 'پرفروش‌ترین مکمل خریداری‌شده در کنار «%s»', $source ? $source->get_name() : $search_query ),
                                        );
                                }
                        }
                }

                // نسخه ۲.۹.۲ — نتیجهٔ خالی هم کش می‌شود: قبلاً فقط نتایج غیرخالی در کلید
                // «search_rec_*» ذخیره می‌شدند؛ در فروشگاه‌هایی که محصولاتِ تطبیق‌یافته با عبارت
                // جستجو هنوز هیچ قانون همبستگی ندارند (مثل فروشگاه‌های تازه‌کار یا عبارت‌های
                // بی‌ربط)، کوئری تجمیعی ایندکس‌دارِ پایین در «هر» بار بازدید صفحهٔ جستجو — و به
                // دلیل اجرای هم‌زمان بنر و تزریق نتایج، دو بار — تکرار می‌شد. خالی‌ها با TTL
                // کوتاه ۱۰ دقیقه‌ای کش می‌شوند تا پاسخ به تغییر قوانین تازه‌ساز هم بماند.
                $this->set_cache( $cache_key, $recommendations, ! empty( $recommendations ) ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
                return $recommendations;
        }
}
