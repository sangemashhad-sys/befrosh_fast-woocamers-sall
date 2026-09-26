<?php
/**
 * Class FWS_Display_Hooks
 * تزریق ویجت‌های فرانت‌اند ووکامرس برای نمایش پیشنهادات دیتابیس
 * نسخه ۲.۸: همه تزریق‌های خودکار از گیت شخصی‌سازی ظاهر (FWS_Style_Manager) عبور می‌کنند؛
 * شورت‌کدها به‌عنوان انتخاب آگاهانه مدیر همیشه رندر می‌شوند.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Display_Hooks {

        private static $instance = null;

        /**
         * نسخهٔ ۲.۱۲.۴ (F-15): ویجت‌هایی که در همین درخواست قبلاً رندر شده‌اند
         * (کلید گیت خودکار). برای جلوگیری از رندر و ثبت نمایش تکراری وقتی شورت‌کدِ
         * همان ویجت روی همان صفحه قرار دارد.
         *
         * @var array
         */
        private static $rendered_widgets = array();

        public static function get_instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        public function __construct() {
                // نسخهٔ ۲.۱۲.۶ (G-21): هر تغییر در ویجت‌های بلوکی/متنی/HTML سفارشی، پرچم کش‌شدهٔ
                // اسکن شورت‌کد را باطل می‌کند تا اسکن بعدی نتیجهٔ تازه بسازد.
                foreach ( array( 'widget_block', 'widget_text', 'widget_custom_html' ) as $fws_watched_widget_option ) {
                        add_action( 'update_option_' . $fws_watched_widget_option, array( __CLASS__, 'invalidate_sidebar_flag' ) );
                        add_action( 'delete_option_' . $fws_watched_widget_option, array( __CLASS__, 'invalidate_sidebar_flag' ) );
                }
                // Enqueue Assets
                add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

                // Single Product Page Recommendation Box (از گیت شخصی‌سازی ظاهر عبور می‌کند)
                add_action( 'woocommerce_after_single_product_summary', array( $this, 'maybe_render_product_recommendations' ), 25 );

                // Cart Page Upsell Box
                add_action( 'woocommerce_after_cart_table', array( $this, 'maybe_render_cart_recommendations' ), 15 );

                // Sales Booster 1: Post-Purchase 1-Click Upsell in Thank-You Page
                add_action( 'woocommerce_thankyou', array( $this, 'maybe_render_thank_you_upsell' ), 10 );
                // نسخهٔ ۲.۱۳ (I-53): پوشش صفحهٔ تشکر بلوکی (order-confirmation) — با اولویتِ
                // دیرتر از do_shortcode (۱۰) و رندر بلوک‌ها، و با دفترچهٔ رندر ضد دوبل.
                add_filter( 'the_content', array( $this, 'maybe_append_thankyou_upsell_block' ), 99 );

                // Sales Booster 2: Dynamic Free Shipping Progress Bar with Smart Fillers
                add_action( 'woocommerce_before_cart_table', array( $this, 'maybe_render_free_shipping_progress_bar' ), 5 );

                // نسخهٔ ۲.۱۲.۴ (F-11): سبد خرید بلوکی (بلاک woocommerce/cart — پیش‌فرض فروشگاه‌های
                // جدید WC 8.3+) هوک‌های کلاسیک قالب cart.php را هرگز آتش نمی‌زند؛ نوار ارسال و
                // پیشنهادات سبد بی‌صدا و بدون هیچ خطایی ناپدید می‌شدند. تزریق به بلوک فقط
                // در فرانت و صفحهٔ سبد رخ می‌دهد (گاردهای داخل متد) و نیازی به تنظیم جدید ندارد.
                add_filter( 'render_block', array( $this, 'inject_into_block_cart' ), 10, 2 );

                // Sales Booster 3: Exit-Intent Cart Abandonment Rescue Modal
                add_action( 'wp_footer', array( $this, 'maybe_render_exit_intent_rescue_modal' ) );

                // Customer Account Next Purchase Prediction
                add_action( 'woocommerce_account_dashboard', array( $this, 'maybe_render_my_account_prediction' ), 10 );

                // Growth Tool 9: Predictive Search Booster (ارتقای نتایج جستجو با پرفروش‌ترین کالای مکمل)
                add_action( 'woocommerce_before_shop_loop', array( $this, 'maybe_render_search_complementary_banner' ), 12 );
                add_filter( 'the_posts', array( $this, 'inject_complementary_into_search_results' ), 10, 2 );

                // نسخهٔ ۲.۱۲.۵ (F-32): در قالب‌های بلوکی، جستجوی محصول از بلوک «Product Collection»
                // رندر می‌شود و هوک کلاسیک woocommerce_before_shop_loop هرگز آتش نمی‌زند —
                // بنر جستجو با وجود روشن‌بودن در پنل بی‌صدا غیب می‌شد (همان ردهٔ باگ B-21 که
                // برای سبد خرید در F-11 حل شد). تزریق فقط در فرانت + صفحهٔ جستجو و با همان
                // گیتِ enable_search_banner؛ دفترچهٔ رندر (F-15/F-28) جلوی تکرار را می‌گیرد.
                add_filter( 'render_block', array( $this, 'inject_search_banner_block_theme' ), 10, 2 );

                // نسخهٔ ۲.۱۲.۵ (F-45): تازه‌سازی ویجت‌ها پس از تغییر سبد — نوار ارسال رایگان و
                // پیشنهادات سبد HTML سمت سرورند و با فرگمنت‌های خود WC به‌روز نمی‌شدند؛
                // بعد از افزودن سریع، مشتری باقی‌ماندهٔ قدیمی را می‌دید و همان پرکننده را
                // دوباره پیشنهاد می‌گرفت تا رفرش دستی. ثبت به‌عنوان فرگمنت رسمی WC هم
                // افزودنِ بومی (wc-ajax=get_refreshed_fragments) و هم endpoints خود افزونه
                // (که همین فیلتر را اعمال می‌کنند) را یکجا پوشش می‌دهد — بدون تغییر JS.
                add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_widget_fragments' ) );

                // BUG-11 fix (v2.8.1): a new/paid order invalidates that customer's cached prediction.
                add_action( 'woocommerce_new_order', array( 'FWS_Prediction_Engine', 'on_order_changed' ), 10, 1 );
                add_action( 'woocommerce_order_status_processing', array( 'FWS_Prediction_Engine', 'on_order_changed' ), 10, 1 );
                add_action( 'woocommerce_order_status_completed', array( 'FWS_Prediction_Engine', 'on_order_changed' ), 10, 1 );

                // Shortcode support for Gutenberg, Elementor, and custom themes
                add_shortcode( 'fws_predicted_products', array( $this, 'shortcode_handler' ) );
                add_shortcode( 'fws_bundle', array( $this, 'render_bundle_shortcode' ) );
                add_shortcode( 'fws_free_shipping_bar', array( $this, 'render_free_shipping_shortcode' ) );
                add_shortcode( 'fws_cart_recommendations', array( $this, 'render_cart_recommendations_shortcode' ) );
        }

        /* ───── نسخه ۲.۸: گیت شخصی‌سازی ظاهر برای تزریق‌های خودکار ─────
         * هر ویجت فقط در صورتی رندر می‌شود که در پنل «ظاهر و شخصی‌سازی» فعال باشد؛
         * شورت‌کدها مستقل از این گیت‌ها هستند (قرار دادن شورت‌کد در صفحه = انتخاب صریح مدیر).
         * خروجی نهایی همه ویجت‌ها از فیلتر fws_widget_html عبور می‌کند تا قالب‌ها و
         * توسعه‌دهندگان بتوانند HTML را کاملاً بازنویسی یا استایل‌دهی کنند.
         *
         * نسخه ۲.۱۰: همین گیت نقطه ثبت «نمایش» هر ویجت در موتور گزارش درآمد است؛
         * ردیابی هر ویجت دقیقاً از کلید خاموش/روشن خودش پیروی می‌کند
         * (ویجت خاموش = نه نمایش، نه ردیف آماری). مودال خروج استثناست چون
         * نمایش واقعی‌اش فقط با JS اتفاق می‌افتد و از همان مسیر ثبت می‌شود.
         */
        private function gated_output( $widget_key, $callback ) {
                if ( ! FWS_Style_Manager::component_enabled( $widget_key ) ) {
                        return;
                }
                // نسخهٔ ۲.۱۲.۵ (F-28): دفترچهٔ F-15 تا امروز یک‌طرفه بود — شورت‌کد ثبتش
                // می‌کرد و می‌پرسید، ولی رندر خودکار (هوک‌ها/بلوک سبد) فقط «می‌نوشت» و هرگز
                // «نمی‌پرسید». نتیجه: شورت‌کد در توضیح کوتاه محصول (رندر در هوک early)
                // + جعبهٔ خودکار بعد از محصول = دو جعبهٔ یکسان روی یک صفحه. اکنون رندر
                // خودکار هم اگر همان ویجت قبلاً در این درخواست رندر شده باشد، بی‌صدا
                // خارج می‌شود (کمتر از یک رندر در صفحه به ازای هر ویجت — قرارداد F-15).
                if ( isset( self::$rendered_widgets[ $widget_key ] ) ) {
                        return;
                }
                ob_start();
                call_user_func( $callback );
                $html = ob_get_clean();

                if ( '' !== trim( $html ) ) {
                        // نسخهٔ ۲.۱۲.۴ (F-15): دفترچهٔ رندر درخواست جاری — شورت‌کدِ همان ویجت که
                        // بعداً در همان صفحه فراخوانی شود، دوباره رندر/ثبت نمایش نمی‌شود
                        // (دو بار UI + دو ردیف نمایش = شکستن نرخ تبدیل و آمار A/B).
                        self::$rendered_widgets[ $widget_key ] = true;
                        if ( 'enable_exit_intent' !== $widget_key && class_exists( 'FWS_Tracker' ) ) {
                                FWS_Tracker::log_impression( $widget_key );
                        }
                }

                echo apply_filters( 'fws_widget_html', $html, $widget_key );
        }

        /**
         * نسخهٔ ۲.۱۲.۴ (F-11): تزریق نوار ارسال رایگان و پیشنهادات سبد به بلوک سبد خرید.
         * هوک‌های کلاسیک (woocommerce_before/after_cart_table) فقط از قالب cart.php آتش
         * می‌خورند؛ روی صفحات سبدِ بلوکی هر دو ویجت به ابتدا و انتهای خروجی بلوک می‌چسبند.
         * گیت‌های خاموش/روشن و ثبت نمایش از همان مسیر استاندارد gated_output عبور می‌کنند.
         *
         * نسخهٔ ۲.۱۲.۵ (F-25): تنها تزریق‌کنندهٔ بلوک سبد همین متد است — کلوژر هم‌زادِ
         * نسخهٔ ۲.۱۱ در fast-woo-sale.php حذف شد (دو تزریق موازی = دو بار ویجت).
         *
         * @param string $block_content خروجی رندرشدهٔ بلوک
         * @param array  $block         اطلاعات بلوک
         * @return string
         */
        public function inject_into_block_cart( $block_content, $block ) {
                if ( empty( $block_content ) || is_admin() || is_feed() || ! is_cart() ) {
                        return $block_content;
                }
                if ( ! isset( $block['blockName'] ) || 'woocommerce/cart' !== $block['blockName'] ) {
                        return $block_content;
                }

                ob_start();
                $this->maybe_render_free_shipping_progress_bar();
                $fws_bar = ob_get_clean();

                ob_start();
                $this->maybe_render_cart_recommendations();
                $fws_recs = ob_get_clean();

                return $fws_bar . $block_content . $fws_recs;
        }

        /**
         * نسخهٔ ۲.۱۲.۵ (F-32): بنر جستجو در قالب‌های بلوکی — همان ردهٔ باگ F-11 ولی برای
         * صفحهٔ جستجو. هوک کلاسیک woocommerce_before_shop_loop فقط از archive-product.php
         * کلاسیک آتش می‌خورد؛ در قالب بلوکی، نتایج جستجوی محصول از بلوک «Product Collection»
         * می‌آید و بنر با وجود روشن‌بودن هرگز رندر نمی‌شد. بنر فقط یک‌بار (اولین بلوک
         * محصولِ نتایج جستجو) و فقط در فرانت بالای نتایج می‌نشیند؛ گیت و ثبت نمایش از
         * مسیر استاندارد gated_output است و دفترچهٔ رندر (F-15/F-28) جلوی تکرار را می‌گیرد.
         *
         * @param string $block_content خروجی رندرشدهٔ بلوک
         * @param array  $block         اطلاعات بلوک
         * @return string
         */
        public function inject_search_banner_block_theme( $block_content, $block ) {
                if ( empty( $block_content ) || is_admin() || is_feed() || ! is_search() ) {
                        return $block_content;
                }
                if ( ! isset( $block['blockName'] ) || 'woocommerce/product-collection' !== $block['blockName'] ) {
                        return $block_content;
                }

                ob_start();
                $this->maybe_render_search_complementary_banner();
                $fws_banner = ob_get_clean();

                return $fws_banner . $block_content;
        }

        /**
         * نسخهٔ ۲.۱۲.۵ (F-45): فرگمنت‌های تازه‌سازی ویجت‌ها — زیرمجموعهٔ رسمی
         * woocommerce_add_to_cart_fragments. اگر ویجت روی صفحه حاضر باشد، HTML تازهٔ
         * همان عنصر (شامل خودِ المنتِ پیچنده) با سلکتور دقیقش برمی‌گردد؛ انتخابگرهای
         * data-fws-widget فقط نمونهٔ رندرِ خودکار را نشانه می‌گیرند تا نمونهٔ شورت‌کد
         * (که کلاس fws-shortcode-wrapper و data-fws-widget="shortcode" دارد) جابه‌جا نشود.
         * خالی بودن خروجی (ویجت خاموش/سبد ناموجود) هیچ تأثیری ندارد — JS روی سلکتوری
         * که در صفحه نیست، no-op است. ثبت نمایش در این مسیر به لطف dedupe ۶ ساعتهٔ
         * سشن در log_impression، ردیف نمایش تازه‌ای نمی‌سازد.
         *
         * @param array $fragments فرگمنت‌های فعلی (مینی‌سبد و…)
         * @return array
         */
        public function add_widget_fragments( $fragments ) {
                if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
                        return $fragments;
                }

                ob_start();
                $this->maybe_render_free_shipping_progress_bar();
                $fws_bar = ob_get_clean();
                if ( '' !== trim( $fws_bar ) ) {
                        $fragments['.fws-shipping-bar-wrapper[data-fws-widget="shipping"]'] = $fws_bar;
                }

                ob_start();
                $this->maybe_render_cart_recommendations();
                $fws_recs = ob_get_clean();
                if ( '' !== trim( $fws_recs ) ) {
                        $fragments['.fws-cart-recommendations-wrapper[data-fws-widget="cart"]'] = $fws_recs;
                }

                return $fragments;
        }

        public function maybe_render_product_recommendations() {
                $this->gated_output(
                        'enable_widget_product',
                        function () {
                                $this->render_product_recommendations_box();
                        }
                );
        }

        public function maybe_render_cart_recommendations() {
                $this->gated_output(
                        'enable_widget_cart',
                        function () {
                                $this->render_cart_recommendations_box();
                        }
                );
        }

        public function maybe_render_thank_you_upsell( $order_id ) {
                $this->gated_output(
                        'enable_widget_thankyou',
                        function () use ( $order_id ) {
                                $this->render_thank_you_upsell_box( $order_id );
                        }
                );
        }

        /**
         * نسخهٔ ۲.۱۳ (I-53): پوشش صفحهٔ تشکرِ بلوکی — قالب بلاکیِ order-confirmation هوک
         * کلاسیک woocommerce_thankyou را فقط با بلوک «اطلاعات اضافه» آتش می‌زند؛ باکس آپسل
         * در چنین قالبی بی‌صدا غایب بود. این فیلترِ the_content، روی صفحهٔ order-received
         * اگر باکس هنوز رندر نشده باشد (یعنی مسیر کلاسیک اجرا نشده)، همان باکسِ گیت‌شده را
         * به انتهای محتوا می‌چسباند. دفترچهٔ rendered_widgets (گated_output) هر دوبل‌رندری
         * را می‌بندد؛ گیت enable_widget_thankyou و قاعدهٔ طلایی همین‌جا حفظ می‌شود.
         *
         * @param string $content محتوای پست/صفحه
         * @return string
         */
        public function maybe_append_thankyou_upsell_block( $content ) {
                if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
                        return $content;
                }
                if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() || ! is_main_query() ) {
                        return $content;
                }
                // اگر مسیر کلاسیک (هوک woocommerce_thankyou) همین باکس را رندر کرده، کاری نکن
                if ( isset( self::$rendered_widgets['enable_widget_thankyou'] ) ) {
                        return $content;
                }

                global $wp;
                $fws_thankyou_order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
                if ( $fws_thankyou_order_id <= 0 ) {
                        return $content;
                }

                ob_start();
                $this->maybe_render_thank_you_upsell( $fws_thankyou_order_id );
                $fws_box = ob_get_clean();

                if ( '' !== trim( $fws_box ) ) {
                        return $content . $fws_box;
                }
                return $content;
        }

        public function maybe_render_free_shipping_progress_bar() {
                $this->gated_output(
                        'enable_widget_shipping',
                        function () {
                                $this->render_free_shipping_progress_bar();
                        }
                );
        }

        public function maybe_render_exit_intent_rescue_modal() {
                $this->gated_output(
                        'enable_exit_intent',
                        function () {
                                $this->render_exit_intent_rescue_modal();
                        }
                );
        }

        public function maybe_render_my_account_prediction() {
                $this->gated_output(
                        'enable_widget_account',
                        function () {
                                $this->render_my_account_prediction_widget();
                        }
                );
        }

        public function maybe_render_search_complementary_banner() {
                $this->gated_output(
                        'enable_search_banner',
                        function () {
                                $this->render_search_complementary_banner();
                        }
                );
        }

        public function enqueue_scripts() {
                if ( is_admin() ) {
                        return;
                }

                // Conditional asset loading: only load scripts & styles where needed to protect PageSpeed & Core Web Vitals
                $should_load = is_product() || is_cart() || is_checkout() || is_account_page() || is_search();
                global $post;
                if ( ! $should_load && is_a( $post, 'WP_Post' ) ) {
                        if (
                                has_shortcode( $post->post_content, 'fws_predicted_products' ) ||
                                has_shortcode( $post->post_content, 'fws_bundle' ) ||
                                has_shortcode( $post->post_content, 'fws_free_shipping_bar' ) ||
                                has_shortcode( $post->post_content, 'fws_cart_recommendations' )
                        ) {
                                $should_load = true;
                        } else {
                                // صفحه‌سازها (مثل المنتور) شورت‌کدها را در متا ذخیره می‌کنند نه post_content
                                $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
                                if ( is_string( $elementor_data ) && false !== strpos( $elementor_data, 'fws_' ) ) {
                                        $should_load = true;
                                }
                        }
                }

                // نسخهٔ ۲.۹.۳ — باگ «شورت‌کد در ویجت سایدبار»: شورت‌کدهای fws_* داخل ویجت‌های
                // Text / Block / Custom-HTML در post_content صفحه نیستند؛ چک قبلی آن‌ها را نمی‌دید
                // و در نتیجه JS روی آن صفحه اصلاً لود نمی‌شد — یعنی همهٔ دکمه‌های «افزودن سریع»
                // و «افزودن پکیج»ِ ویجت بی‌صدا مرده بودند. اسکن سبکِ آپشن‌های ویجت‌های فعال
                // (همه autoload هستند و فقط strpos می‌شوند) این حفره را می‌بندد.
                if ( ! $should_load ) {
                        // نسخهٔ ۲.۱۲.۶ (G-21): اسکن گزینه‌های ویجت کش شد — قبلاً روی «هر» صفحهٔ
                        // بی‌ربط، سه option (گاه بسیار سنگین: widget_block) کامل پیمایش می‌شد.
                        // نتیجهٔ اسکن در یک option کوچک با TTL هفت‌روزه نگه داشته می‌شود و هر
                        // به‌روزرسانی/حذف ویجت (update_option_widget_* / delete_option_widget_*)
                        // پرچم را باطل می‌کند تا اسکن بعدی آن را تازه کند.
                        $fws_sidebar_flag = get_option( 'fws_sidebar_shortcode_flag', '' );
                        $fws_flag_val     = 0;
                        $fws_flag_ts      = 0;
                        if ( is_string( $fws_sidebar_flag ) && false !== strpos( $fws_sidebar_flag, '|' ) ) {
                                $fws_flag_parts = explode( '|', $fws_sidebar_flag );
                                $fws_flag_val   = (int) $fws_flag_parts[0];
                                $fws_flag_ts    = isset( $fws_flag_parts[1] ) ? (int) $fws_flag_parts[1] : 0;
                        }
                        if ( '' === $fws_sidebar_flag || ( time() - $fws_flag_ts ) > 7 * DAY_IN_SECONDS ) {
                                $fws_flag_val = 0;
                                foreach ( array( 'widget_block', 'widget_text', 'widget_custom_html' ) as $fws_widget_option ) {
                                        $fws_widget_instances = get_option( $fws_widget_option, array() );
                                        if ( ! is_array( $fws_widget_instances ) ) {
                                                continue;
                                        }
                                        foreach ( $fws_widget_instances as $fws_widget_instance ) {
                                                $fws_widget_content = '';
                                                if ( is_array( $fws_widget_instance ) ) {
                                                        foreach ( array( 'text', 'content' ) as $fws_content_key ) {
                                                                if ( isset( $fws_widget_instance[ $fws_content_key ] ) && is_string( $fws_widget_instance[ $fws_content_key ] ) ) {
                                                                        $fws_widget_content .= $fws_widget_instance[ $fws_content_key ];
                                                                }
                                                        }
                                                } elseif ( is_string( $fws_widget_instance ) ) {
                                                        $fws_widget_content = $fws_widget_instance;
                                                }
                                                if ( false !== strpos( $fws_widget_content, 'fws_' ) ) {
                                                        $fws_flag_val = 1;
                                                        break 2;
                                                }
                                        }
                                }
                                update_option( 'fws_sidebar_shortcode_flag', $fws_flag_val . '|' . time(), true );
                        }
                        if ( 1 === $fws_flag_val ) {
                                $should_load = true;
                        }
                }

                if ( ! $should_load ) {
                        return;
                }

                // نسخه ۲.۸: کلید اصلی استایل — اگر خاموش باشد هیچ CSS از افزونه لود نمی‌شود
                // (ویجت‌ها با استایل خود قالب رندر می‌شوند؛ JS عملکردی سر جایش می‌ماند)
                if ( FWS_Style_Manager::styles_enabled() ) {
                        wp_enqueue_style( 'fws-recommendations', FWS_PLUGIN_URL . 'assets/css/fws-recommendations.css', array(), FWS_VERSION );
                }
                wp_enqueue_script( 'fws-recommendations', FWS_PLUGIN_URL . 'assets/js/fws-recommendations.js', array( 'jquery' ), FWS_VERSION, true );

                // Defer script loading for optimal TTFB and First Contentful Paint
                add_filter(
                        'script_loader_tag',
                        function ( $tag, $handle ) {
                                if ( 'fws-recommendations' === $handle && false === strpos( $tag, 'defer' ) ) {
                                        return str_replace( ' src', ' defer="defer" src', $tag );
                                }
                                return $tag;
                        },
                        10,
                        2
                );

                wp_localize_script(
                        'fws-recommendations',
                        'fws_params',
                        array(
                                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                                'nonce'           => wp_create_nonce( 'fws_prediction_nonce' ),
                                'bundle_discount' => (int) FWS_Settings::get( 'bundle_discount', 12 ),
                                // نسخهٔ ۲.۱۲ (S-07): فرمت قیمت هم‌رو با ووکامرس — اعشار، جداکنندهٔ هزارگان،
                                // جداکنندهٔ اعشار و جایگاه نماد ارز از تنظیمات خود ووکامرس خوانده می‌شود
                                // تا پیش‌نمایش JS با wc_price سرور یکی باشد.
                                'price_format'    => array(
                                        'decimals'     => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 0,
                                        'decimal_sep'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
                                        'thousand_sep' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
                                        'symbol'       => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'تومان',
                                        'position'     => get_option( 'woocommerce_currency_pos', 'right_space' ),
                                ),
                                // نسخهٔ ۲.۱۲ (S-03): کلید تریگر اسکرول رو به بالا در موبایل
                                // نسخه ۲.۹: کلید بی‌مصرف free_shipping_limit حذف شد (در JS هرگز خوانده نمی‌شد)
                                'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'تومان',
                                'added_text'      => __( 'پکیج با موفقیت به سبد خرید اضافه شد', 'fast-woo-sale' ),
                                // نسخه ۲.۱۰: بیکن مودال خروج فقط وقتی ردیابی روشن است ارسال می‌شود
                                'tracking_enable' => FWS_Tracker::tracking_enabled() ? 'yes' : 'no',
                                'exit_intent_mobile' => FWS_Settings::get( 'exit_intent_mobile', 'no' ),
                        )
                );
        }

        /**
         * نسخهٔ ۲.۱۲.۶ (G-21): باطل‌کردن پرچم کش‌شدهٔ اسکن ویجت‌های سایدبار
         */
        public static function invalidate_sidebar_flag() {
                delete_option( 'fws_sidebar_shortcode_flag' );
        }

        /**
         * رندر باکس هوشمند محصولات خریداری‌شده با این کالا در صفحه محصول
         */
        public function render_product_recommendations_box( $custom_product_id = 0 ) {
                $product_id = $custom_product_id > 0 ? absint( $custom_product_id ) : 0;
                if ( $product_id <= 0 ) {
                        global $product;
                        $product_id = ( $product && is_a( $product, 'WC_Product' ) ) ? $product->get_id() : 0;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                        return;
                }

                // BUG-07 fix (v2.9.0): the one-click bundle cannot add the CURRENT product when it is
                // a variable/grouped/external parent (add_to_cart() fails for them, is_recommendable()
                // already protects only the recommended side). Rendering the box for such sources
                // produced "N items added with discount" without the main product itself. Skip honestly.
                $unaddable_types = apply_filters( 'fws_non_addable_product_types', array( 'variable', 'grouped', 'external' ) );
                if ( $product->is_type( (array) $unaddable_types ) ) {
                        return;
                }

                $engine          = FWS_Prediction_Engine::get_instance();
                $recommendations = $engine->get_recommendations_for_product( $product->get_id(), max( 1, (int) FWS_Settings::get( 'recs_limit', 3 ) ) );

                if ( empty( $recommendations ) ) {
                        return;
                }

                // نسخهٔ ۲.۱۱ (B-27): باکس پکیج = افزودن یک‌کلیکیِ همه با هم؛ فقط کالاهایی که
                // واقعاً یک‌کلیکی قابل افزودن‌اند (غیرمغتیر/گروهی/پیوندی) در آن می‌مانند.
                // پیشنهادهای متغیر در مسیرهای دیگری (سبد، فیلرها، حساب، جستجو) به‌صورت
                // لینک‌محور نمایش داده می‌شوند تا قوانین والدِ متغیر ماینر هدر نروند.
                // نسخهٔ ۲.۱۳ (I-52): شیء محصول در همان دور اول در آرایه نگه داشته می‌شود؛
                // قبلاً هر محصول دو بار wc_get_product می‌شد (فیلتر + حلقهٔ قیمت).
                $recommendations = array_values(
                        array_filter(
                                array_map(
                                        function ( $rec ) {
                                                $rec['fws_product_obj'] = wc_get_product( $rec['product_id'] );
                                                return $rec;
                                        },
                                        $recommendations
                                ),
                                function ( $rec ) {
                                        return $rec['fws_product_obj'] && FWS_Prediction_Engine::is_quick_addable( $rec['fws_product_obj'] );
                                }
                        )
                );
                if ( empty( $recommendations ) ) {
                        return;
                }

                // نسخهٔ ۲.۱۲.۲ (B-42): قیمت‌ها به «فضای نمایشی» ووکامرس (تنظیمات مالیات،
                // هم‌رو با قیمت همان کالا در صفحهٔ خودش) تبدیل می‌شوند؛ قبلاً get_price() خام
                // کاتالوگ نمایش داده می‌شد و در فروشگاه دارای مالیات، باکس پکیج با قیمت صفحهٔ
                // محصول و مبلغ سبد نمی‌خواند. آرایهٔ کاندیدای موتور (کش مشترک ۲۴ ساعته) خام
                // می‌ماند و تبدیل فقط در همین رندر انجام می‌شود. data-price دکمه‌ها هم از همین
                // مقادیر تغذیه می‌شود تا پیش‌نمایش JS با سرور یکی بماند.
                $main_price = FWS_Prediction_Engine::display_price( $product, null, 'shop' );
                foreach ( $recommendations as $fws_rkey => $fws_rec ) {
                        $recommendations[ $fws_rkey ]['price'] = FWS_Prediction_Engine::display_price( $fws_rec['fws_product_obj'], (float) $fws_rec['price'], 'shop' );
                }
                // نسخهٔ ۲.۱۳ (I-52): کلید کمکیِ شیء پیش از خروجیِ نهایی حذف می‌شود
                foreach ( $recommendations as $fws_rkey => $fws_rec ) {
                        unset( $recommendations[ $fws_rkey ]['fws_product_obj'] );
                }
                $total_bundle_original = $main_price;
                foreach ( $recommendations as $rec ) {
                        $total_bundle_original += (float) $rec['price'];
                }
                $bundle_discount         = max( 0, min( 90, (int) FWS_Settings::get( 'bundle_discount', 12 ) ) );
                $discount_factor         = ( 100 - $bundle_discount ) / 100;
                $total_bundle_discounted = $total_bundle_original * $discount_factor;

                // امضای HMAC سمت سرور: فقط شناسه‌های واقعاً رندرشده در این باکس مجاز به دریافت تخفیف پکیج هستند
                $official_rec_ids = array();
                foreach ( $recommendations as $rec ) {
                        $official_rec_ids[] = (int) $rec['product_id'];
                }
                $bundle_signature = hash_hmac( 'sha256', $product->get_id() . '|' . implode( ',', $official_rec_ids ), wp_salt( 'auth' ) );
                ?>
                <div class="fws-bundle-wrapper"<?php echo FWS_Style_Manager::dir_attr(); // جهت ویجت — قابل خاموش‌کردن از تنظیمات ?> data-fws-widget="product">
                        <div class="fws-bundle-header">
                                <span class="fws-bundle-badge">تحلیل دیتابیس سفارشات</span>
                                <h3 class="fws-bundle-title"><?php echo esc_html( FWS_AB_Testing::widget_title( 'product', 'پیشنهادهای هوشمند دیتابیس (خریداری‌شده با این کالا)' ) ); ?></h3>
                                <p class="fws-bundle-subtitle">بر اساس تحلیل سوابق خرید و رفتار مشتریان این فروشگاه</p>
                        </div>

                        <div class="fws-bundle-items">
                                <!-- Main Product -->
                                <div class="fws-bundle-item is-primary">
                                        <input type="checkbox" checked disabled class="fws-check-item" data-price="<?php echo esc_attr( $main_price ); ?>" data-price-raw="<?php echo esc_attr( wc_format_decimal( $main_price, wc_get_price_decimals() ) ); ?>" value="<?php echo esc_attr( $product->get_id() ); ?>">
                                        <span class="fws-item-thumb">
                                                <?php
                                                echo $product->get_image(
                                                        'thumbnail',
                                                        array(
                                                                'loading'  => 'lazy',
                                                                'decoding' => 'async',
                                                        )
                                                );
                                                ?>
                                        </span>
                                        <div class="fws-item-info">
                                                <span class="fws-item-tag">محصول فعلی</span>
                                                <strong class="fws-item-name"><?php echo esc_html( $product->get_name() ); ?></strong>
                                                <span class="fws-item-price"><?php echo wc_price( $main_price ); ?></span>
                                        </div>
                                </div>

                                <div class="fws-plus-sign">+</div>

                                <!-- Recommended Co-Occurrence Products -->
                                <?php foreach ( $recommendations as $idx => $rec ) : ?>
                                        <div class="fws-bundle-item is-recommended" data-product-id="<?php echo esc_attr( $rec['product_id'] ); ?>">
                                                <input type="checkbox" checked class="fws-check-item" data-price="<?php echo esc_attr( $rec['price'] ); ?>" data-price-raw="<?php echo esc_attr( wc_format_decimal( $rec['price'], wc_get_price_decimals() ) ); ?>" value="<?php echo esc_attr( $rec['product_id'] ); ?>">
                                                <span class="fws-item-thumb">
                                                        <img src="<?php echo esc_url( $rec['image'] ); ?>" alt="<?php echo esc_attr( $rec['name'] ); ?>" loading="lazy" decoding="async" width="56" height="56">
                                                </span>
                                                <div class="fws-item-info">
                                                        <?php if ( FWS_Style_Manager::show_confidence_tags() ) : ?>
                                                                <?php if ( ! empty( $rec['is_fallback'] ) ) : ?>
                                                                <!-- صداقت آماری: فال‌بک دسته‌بندی هیچ درصد همبستگی ساختگی نمایش نمی‌دهد -->
                                                                <span class="fws-confidence-tag is-fallback"><?php echo esc_html( FWS_Style_Manager::style_text( 'پیشنهاد فروشگاه برای شما' ) ); ?></span>
                                                        <?php elseif ( ! empty( $rec['is_manual'] ) ) : ?>
                                                                <span class="fws-confidence-tag is-manual"><?php echo esc_html( FWS_Style_Manager::style_text( 'پیشنهاد مدیر فروشگاه (' . $rec['confidence'] . '٪ اولویت)' ) ); ?></span>
                                                        <?php else : ?>
                                                                <span class="fws-confidence-tag">
                                                                        <?php echo esc_html( $rec['confidence'] ); ?>٪ سفارشات مشترک
                                                                </span>
                                                        <?php endif; ?>
                                                        <?php endif; ?>
                                                        <strong class="fws-item-name"><?php echo esc_html( $rec['name'] ); ?></strong>
                                                        <span class="fws-item-price"><?php echo wc_price( $rec['price'] ); ?></span>
                                                </div>
                                        </div>
                                        <?php if ( $idx < count( $recommendations ) - 1 ) : ?>
                                                <div class="fws-plus-sign">+</div>
                                        <?php endif; ?>
                                <?php endforeach; ?>
                        </div>

                        <!-- Bundle Action Bar -->
                        <div class="fws-bundle-action-bar">
                                <div class="fws-pricing-breakdown">
                                        <span class="fws-label">قیمت کل پکیج با تخفیف هوشمند (<?php echo esc_html( $bundle_discount ); ?>٪):</span>
                                        <div class="fws-prices">
                                                <del class="fws-original-price"><?php echo wc_price( $total_bundle_original ); ?></del>
                                                <strong class="fws-discounted-price"><?php echo wc_price( $total_bundle_discounted ); ?></strong>
                                        </div>
                                </div>
                                <button type="button" class="fws-add-bundle-btn"
                                                data-main-id="<?php echo esc_attr( $product->get_id() ); ?>"
                                                data-bundle-ids="<?php echo esc_attr( implode( ',', $official_rec_ids ) ); ?>"
                                                data-bundle-sig="<?php echo esc_attr( $bundle_signature ); ?>">
                                        <?php echo esc_html( FWS_Style_Manager::style_text( '⚡ افزودن پکیج هوشمند به سبد خرید' ) ); ?>
                                </button>
                        </div>
                </div>
                <?php
        }

        public function render_cart_recommendations_box() {
                if ( ! WC()->cart || WC()->cart->is_empty() ) {
                        return;
                }

                $cart_product_ids = array();
                foreach ( WC()->cart->get_cart() as $item ) {
                        $cart_product_ids[] = absint( $item['product_id'] );
                }

                $engine          = FWS_Prediction_Engine::get_instance();
                $recommendations = $engine->get_cart_recommendations( $cart_product_ids, max( 1, min( 6, (int) FWS_Settings::get( 'recs_limit', 3 ) ) ) ); // BUG-09 fix: was hard-coded 3

                if ( empty( $recommendations ) ) {
                        return;
                }

                // نسخهٔ ۲.۱۲.۲ (B-42): تبدیل به فضای نمایشی «سبد» (woocommerce_tax_display_cart)
                // تا قیمت‌ها با خطوط همان صفحهٔ سبد می‌خوانند؛ کش مشترک موتور خام می‌ماند.
                foreach ( $recommendations as $fws_rkey => $fws_rec ) {
                        $fws_rec_prod = wc_get_product( $fws_rec['product_id'] );
                        $recommendations[ $fws_rkey ]['price'] = FWS_Prediction_Engine::display_price( $fws_rec_prod, (float) $fws_rec['price'], 'cart' );
                }
                ?>
                <div class="fws-cart-recommendations-wrapper"<?php echo FWS_Style_Manager::dir_attr(); ?> data-fws-widget="cart">
                        <h4 class="fws-cart-title"><?php echo esc_html( FWS_Style_Manager::style_text( FWS_AB_Testing::widget_title( 'cart', '🛍️ مشتریانی که این سبد را خریدند، این کالاها را نیز تهیه کردند:' ) ) ); ?></h4>
                        <div class="fws-cart-grid">
                                <?php foreach ( $recommendations as $rec ) : ?>
                                        <div class="fws-cart-item">
                                                <img src="<?php echo esc_url( $rec['image'] ); ?>" alt="<?php echo esc_attr( $rec['name'] ); ?>" loading="lazy" decoding="async" width="56" height="56">
                                                <div class="fws-cart-item-details">
                                                        <strong><?php echo esc_html( $rec['name'] ); ?></strong>
                                                        <span class="fws-cart-item-price"><?php echo ( ! empty( $rec['is_variable'] ) ? 'از ' : '' ) . wc_price( $rec['price'] ); ?></span>
                                                        <?php if ( FWS_Style_Manager::show_confidence_tags() ) : ?>
                                                                <span class="fws-cart-confidence"><?php echo esc_html( $rec['confidence'] ); ?>٪ همبستگی خرید</span>
                                                        <?php endif; ?>
                                                </div>
                                                <?php if ( ! empty( $rec['is_variable'] ) ) : ?>
                                                        <!-- نسخهٔ ۲.۱۱ (B-27): محصول متغیر = لینک انتخاب گزینه، نه افزودن یک‌کلیکی -->
                                                        <a class="fws-quick-add-btn fws-view-product-btn" href="<?php echo esc_url( get_permalink( $rec['product_id'] ) ); ?>">مشاهده و انتخاب گزینه</a>
                                                <?php else : ?>
                                                        <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr( $rec['product_id'] ); ?>">+ افزودن به سبد</button>
                                                <?php endif; ?>
                                        </div>
                                <?php endforeach; ?>
                        </div>
                </div>
                <?php
        }

        /**
         * Sales Booster 1: Post-Purchase 1-Click Upsell Box on Thank You Page
         */
        public function render_thank_you_upsell_box( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                        return;
                }

                // نسخهٔ ۲.۱۲ (S-01): گیت‌های رندر هم‌سو با معماری انتخابی آپسل —
                // suborder: سفارش وابستهٔ جداگانه ساخته می‌شود؛ سفارش پرداخت‌شدهٔ آنلاین هم
                // حالا مجاز است (قبل از این نسخه به‌کلی رد می‌شد)؛ فقط وضعیت‌های بی‌معنی رد می‌شوند.
                // legacy_append: همان دو گارد قدیمی (وضعیت قابل‌تغییر + رد سفارش پرداخت‌شدهٔ آنلاین).
                if ( 'suborder' === FWS_Settings::get( 'upsell_mode', 'suborder' ) ) {
                        $forbidden_statuses = apply_filters(
                                'fws_upsell_suborder_forbidden_statuses',
                                array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft', 'auto-draft' )
                        );
                        if ( in_array( $order->get_status(), (array) $forbidden_statuses, true ) ) {
                                return;
                        }
                } else {
                        // Security check: Only allow active/processing orders
                        if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing' ) ) ) {
                                return;
                        }

                        // BUG-04 fix (v2.9.0): the v2.8.1 paid-order guard (BUG-03) was only applied to the
                        // AJAX handler, so for orders paid through an online gateway (usually status
                        // "processing") this box still rendered and every click was rejected by the server
                        // with an error. Mirror the exact same rule on the render side.
                        $offline_gateways = apply_filters( 'fws_upsell_offline_gateways', array( 'cod', 'bacs', 'cheque' ) );
                        $is_offline       = in_array( $order->get_payment_method(), (array) $offline_gateways, true );
                        if ( $order->is_paid() && ! $is_offline ) {
                                return;
                        }
                }

                // ─── نسخهٔ ۲.۱۳ (I-50): فروشگاه چندارزی — اگر ارز جاری سشن با ارز سفارش یکی
                // نباشد، قیمت کاتالوگ (get_price) با ارز دیگری خوانده می‌شود و ردیفِ سفارشِ
                // نهایی با ارز سفارش ثبت می‌شود؛ یعنی مبلغ غلط. باکس تا تغییر ارز مخفی می‌شود
                // (مگر این‌که افزونهٔ چندارزی با فیلتر زیر مسئولیت تبدیل را بپذیرد).
                $fws_order_currency   = (string) $order->get_currency();
                $fws_current_currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : $fws_order_currency;
                if ( $fws_current_currency !== $fws_order_currency
                        && ! apply_filters( 'fws_upsell_currency_handled', false, $order, null ) ) {
                        return;
                }

                $engine = FWS_Prediction_Engine::get_instance();
                $upsell = $engine->get_post_purchase_upsell( $order_id );
                if ( ! $upsell ) {
                        return;
                }

                // ─── نسخهٔ ۲.۱۲.۲ (B-42 + B-44) ───
                // B-44: امضای HMAC رندر — شناسهٔ سفارش و کالای پیشنهادی در لحظهٔ رندر امضا می‌شود
                // و با کلیک به سرور برمی‌گردد؛ تغییر کاندیدا بین رندر و کلیک (موجودی، ماینینگ
                // شبانه، purge کش با هر فروش) دیگر به مشتریِ درست‌حرف پیام غلط «جزو پیشنهادها
                // نیست» نمی‌دهد (امضای معتبر = همین پیشنهاد واقعی سرور بوده است).
                // نسخهٔ ۲.۱۲.۶ (G-12): امضا تاریخ انقضا دارد — hash(order|product|expires)؛
                // انقضای پیش‌فرض ۷ روز (فیلتر fws_upsell_sig_ttl، clamp بین ۱ ساعت و ۳۰ روز) —
                // باکس روی صفحهٔ تشکرِ کش‌شده تا یک هفته معتبر می‌ماند و مارک‌آپِ بی‌تاریخِ
                // ابدی دیگر وجود ندارد.
                $fws_sig_ttl     = max( HOUR_IN_SECONDS, min( 30 * DAY_IN_SECONDS, (int) apply_filters( 'fws_upsell_sig_ttl', 7 * DAY_IN_SECONDS ) ) );
                $upsell_expires  = time() + $fws_sig_ttl;
                $upsell_signature = hash_hmac( 'sha256', $order_id . '|' . $upsell['product_id'] . '|' . $upsell_expires, wp_salt( 'auth' ) );
                //
                // B-42: قیمت‌های نمایشی باکس باید با «مبلغی که همین صفحه نشان می‌دهد» بخواند.
                // نسخهٔ ۲.۱۲.۳ (R3): ریاضیاتِ تکراریِ رندر حذف شد — موتور (get_post_purchase_upsell)
                // دقیقاً همان منطق را در «فضای کاتالوگ» اجرا می‌کند (مبنای قیمت عادی BUG-08 +
                // سقف قیمت فعلی R2) و order_display_price آن را به فضای نمایشی سبد با نرخ
                // آدرس همین سفارش (B-31) تبدیل می‌کند. نسخهٔ ۲.۱۲.۲ رندر، ریاضیات را در فضای
                // «بدون مالیات» دوباره اجرا و بعد به order_display_price می‌داد؛ در فروشگاه‌های
                // «ثبت قیمت همراه با مالیات» ورودی بدون‌مالیات با فرضِ همراه-با-مالیاتِ هلپر
                // ترکیب می‌شد و باکس کمتر از مبلغ واقعی واریزی نشان می‌داد (بیش‌تحویل به مشتری).
                // فضای کاتالوگ ← هلپر نمایش = زنجیرهٔ یکدست برای هر دو حالتِ ثبت قیمت.
                $upsell_product            = wc_get_product( $upsell['product_id'] );
                $upsell_regular_display    = FWS_Prediction_Engine::order_display_price( $upsell_product, (float) $upsell['regular_price'], $order );
                $upsell_discounted_display = FWS_Prediction_Engine::order_display_price( $upsell_product, (float) $upsell['discounted_price'], $order );

                $is_suborder_mode = ( 'suborder' === FWS_Settings::get( 'upsell_mode', 'suborder' ) );
                $upsell_desc      = $is_suborder_mode
                        ? 'به عنوان تشکر، می‌توانید محصول <strong>«' . esc_html( $upsell['name'] ) . '»</strong> را با <strong>' . esc_html( $upsell['discount_percent'] ) . '٪ تخفیف اختصاصی</strong> به‌صورت <strong>سفارش وابستهٔ جداگانه</strong> ثبت کنید؛ فاکتور همین سفارش بدون تغییر می‌ماند.'
                        : 'به عنوان تشکر، می‌توانید محصول <strong>«' . esc_html( $upsell['name'] ) . '»</strong> را با <strong>' . esc_html( $upsell['discount_percent'] ) . '٪ تخفیف اختصاصی</strong> تنها با یک کلیک به همین سفارش اضافه نمایید!';
                $upsell_btn_label = $is_suborder_mode
                        ? FWS_Style_Manager::style_text( '✅ ثبت و پرداخت سفارش وابسته' )
                        : FWS_Style_Manager::style_text( '✅ افزودن آنی به سفارش جاری' );
                // Check if already claimed to prevent duplicate clicks
                if ( $order->get_meta( '_fws_upsell_added_' . $upsell['product_id'] ) ) {
                        return;
                }
                ?>
                <div class="fws-thankyou-upsell-box"<?php echo FWS_Style_Manager::dir_attr(); ?>>
                        <div class="fws-thankyou-badge"><?php echo esc_html( FWS_Style_Manager::style_text( '⚡ پیشنهاد اختصاصی و آنی (بدون هزینه ارسال مجدد)' ) ); ?></div>
                        <h3 class="fws-thankyou-title"><?php echo esc_html( FWS_AB_Testing::widget_title( 'thankyou', 'پیشنهاد مکمل بر اساس اقلام سفارش داده شده شما' ) ); ?></h3>
                        <p class="fws-thankyou-desc">
                                <?php echo wp_kses_post( $upsell_desc ); ?>
                        </p>
                        <div class="fws-thankyou-item-row">
                                <img src="<?php echo esc_url( $upsell['image'] ); ?>" alt="<?php echo esc_attr( $upsell['name'] ); ?>" loading="lazy" decoding="async" width="64" height="64">
                                <div class="fws-thankyou-pricing">
                                        <del><?php echo wc_price( $upsell_regular_display ); ?></del>
                                        <strong><?php echo wc_price( $upsell_discounted_display ); ?></strong>
                                        <?php if ( FWS_Style_Manager::show_confidence_tags() ) : ?>
                                                <span class="fws-thankyou-tag"><?php echo esc_html( $upsell['confidence'] ); ?>٪ همبستگی با سبد شما</span>
                                        <?php endif; ?>
                                </div>
                                <button type="button" class="fws-thankyou-claim-btn"
                                                data-order-id="<?php echo esc_attr( $order_id ); ?>"
                                                data-order-key="<?php echo esc_attr( $order->get_order_key() ); ?>"
                                                data-product-id="<?php echo esc_attr( $upsell['product_id'] ); ?>"
                                                data-upsell-sig="<?php echo esc_attr( $upsell_signature ); ?>"
                                                data-upsell-exp="<?php echo esc_attr( $upsell_expires ); ?>">
                                        <?php echo esc_html( $upsell_btn_label ); ?>
                                </button>
                        </div>
                </div>
                <?php
        }

        /**
         * Sales Booster 2: Dynamic Free Shipping Progress Bar with Smart Fillers
         */
        /**
         * نسخهٔ ۲.۱۲ (S-05): خواندن سقف ارسال رایگان از منبع حقیقت — روش‌های ارسال واقعی ووکامرس.
         *
         * ترتیب تصمیم:
         *  ۱) زونِ متناظر با آدرس حمل مشتری (WC_Shipping_Zones::get_zone_matching_package)؛
         *     میان روش‌های رایگان مبلغ‌محورِ آن زون، کوچک‌ترین سقف (دست‌نیافتنی‌ترینِ دست‌یافتنی) ملاک است.
         *  ۲) اگر زون مشتری روش مبلغ‌محور نداشت یا آدرسی ثبت نشده: محافظه‌کارانه «بزرگ‌ترین» سقف
         *     میان همهٔ زون‌ها — تا نوار هیچ‌وقت زودتر از واقعیت «تبریک» نگوید.
         *  ۳) هیچ روش مبلغ‌محوری وجود نداشت: بازگشت ۰ → فراخواننده به عدد دستی پنل سقوط می‌کند.
         * روش‌هایی که ارسال رایگان‌شان فقط با کوپن فعال می‌شود (requires=coupon) مبلغ‌محور نیستند
         * و در شمارش نمی‌آیند.
         *
         * @return float ۰ یعنی «از روش‌های ووکامرس قابل استخراج نبود»
         */
        private static function get_wc_free_shipping_min_amount() {
                if ( ! function_exists( 'WC' ) || ! WC()->cart || ! class_exists( 'WC_Shipping_Zones' ) ) {
                        return 0.0;
                }

                $fws_cands = self::get_free_shipping_candidates();
                $amounts   = array();
                foreach ( $fws_cands['candidates'] as $fws_info ) {
                        if ( (float) $fws_info['min_amount'] > 0 ) {
                                $amounts[] = (float) $fws_info['min_amount'];
                        }
                }

                if ( empty( $amounts ) ) {
                        return 0.0;
                }

                // ۲) بدون تطبیق: محافظه‌کارانه بزرگ‌ترین سقف — نوار هیچ‌وقت زودتر از واقعیت «تبریک» نمی‌گوید
                $cached = $fws_cands['fallback'] ? max( $amounts ) : min( $amounts );

                /**
                 * فیلتر توسعه‌دهنده برای منبع سقف ارسال رایگان نوار
                 *
                 * @param float $cached سقف استخراج‌شده (۰ = ناموجود)
                 */
                return (float) apply_filters( 'fws_free_shipping_min_amount', $cached );
        }

        /**
         * نسخهٔ ۲.۱۲.۷ (H-33): مجموعهٔ روش‌های ارسال رایگان مبلغ‌محور — با جزئیات گزینه‌ها
         * (requires / min_amount / ignore_discounts) تا نوار پیشرفت بتواند دقیقاً مثل
         * WC_Shipping_Free_Shipping::is_available() تصمیم بگیرد: حالت either با کوپن
         * ارسال رایگان هم برآورده می‌شود و ignore_discounts مبلغ را «قبل از تخفیف» می‌سنجد.
         * همان منطق دو مرحله‌ای قبلی: زون متناظر مشتری؛ در نبودش همهٔ زون‌ها (fallback).
         *
         * @return array{candidates: array<int, array{requires: string, min_amount: float, ignore_discounts: string}>, fallback: bool}
         */
        private static function get_free_shipping_candidates() {
                static $cached = null;
                if ( null !== $cached ) {
                        return $cached;
                }

                $fws_result    = array( 'candidates' => array(), 'fallback' => false );
                $collect = static function ( $methods ) use ( &$fws_result ) {
                        foreach ( (array) $methods as $method ) {
                                if ( ! is_a( $method, 'WC_Shipping_Free_Shipping' ) || 'yes' !== $method->enabled ) {
                                        continue;
                                }
                                $requires = (string) $method->get_option( 'requires', '' );
                                if ( 'coupon' === $requires ) {
                                        continue; // وابسته به کوپن است، نه مبلغ سبد
                                }
                                if ( ! in_array( $requires, array( 'min_amount', 'either' ), true ) ) {
                                        continue;
                                }
                                $amt = (float) $method->get_option( 'min_amount', 0 );
                                if ( $amt <= 0 ) {
                                        continue;
                                }
                                $fws_result['candidates'][] = array(
                                        'requires'         => $requires,
                                        'min_amount'       => $amt,
                                        'ignore_discounts' => (string) $method->get_option( 'ignore_discounts', 'no' ),
                                );
                        }
                };

                // ۱) زون متناظر با آدرس حمل مشتری (همان منطق F-06 — بستهٔ واقعی مشتری)
                try {
                        $fws_packages = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_shipping_packages() : array();
                        $package      = ! empty( $fws_packages ) ? reset( $fws_packages ) : array();
                        $zone         = WC_Shipping_Zones::get_zone_matching_package( $package );
                        if ( $zone ) {
                                $collect( $zone->get_shipping_methods() );
                        }
                } catch ( Exception $e ) {
                        FWS_Logger::debug( 'Free-shipping zone match failed: ' . $e->getMessage(), array(), 'shipping-bar' );
                }

                if ( empty( $fws_result['candidates'] ) ) {
                        $fws_result['fallback'] = true;
                        foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
                                $collect( isset( $zone_data['shipping_methods'] ) ? $zone_data['shipping_methods'] : array() );
                        }
                        $zone_zero = new WC_Shipping_Zone( 0 );
                        $collect( $zone_zero->get_shipping_methods() );
                }

                $cached = $fws_result;
                return $cached;
        }

        /**
         * نسخهٔ ۲.۱۲.۷ (H-33): روشِ انتخاب‌شدهٔ ارسال رایگان — همان عددی که سقف نوار از آن
         * می‌آید (کمترین سقف در زون مشتری؛ در fallback بیشترین) تا گزینه‌های همان روش
         * ملاک رفتار نوار باشد.
         *
         * @return array{requires: string, min_amount: float, ignore_discounts: string}|null
         */
        private static function get_chosen_free_shipping_method() {
                $fws_cands = self::get_free_shipping_candidates();
                if ( empty( $fws_cands['candidates'] ) ) {
                        return null;
                }
                $fws_pick = $fws_cands['fallback'] ? 0.0 : PHP_FLOAT_MAX;
                foreach ( $fws_cands['candidates'] as $fws_info ) {
                        $fws_amt = (float) $fws_info['min_amount'];
                        if ( $fws_cands['fallback'] ? $fws_amt > $fws_pick : $fws_amt < $fws_pick ) {
                                $fws_pick = $fws_amt;
                                $fws_chosen = $fws_info;
                        }
                }
                return isset( $fws_chosen ) ? $fws_chosen : null;
        }

        /**
         * نسخهٔ ۲.۱۲.۷ (H-33): آیا کوپنِ اعمال‌شدهٔ سبد، ارسال رایگان می‌دهد؟
         * معادل پیمانهٔ کوپن‌ها در WC_Shipping_Free_Shipping::is_available().
         *
         * @return bool
         */
        private static function cart_has_free_shipping_coupon() {
                if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                        return false;
                }
                foreach ( (array) WC()->cart->get_applied_coupons() as $fws_code ) {
                        try {
                                $fws_coupon = new WC_Coupon( $fws_code );
                        } catch ( Exception $fws_e ) {
                                continue;
                        }
                        if ( method_exists( $fws_coupon, 'is_valid' ) && ! $fws_coupon->is_valid() ) {
                                continue;
                        }
                        if ( wc_string_to_bool( $fws_coupon->get_free_shipping() ) ) {
                                return true;
                        }
                }
                return false;
        }

        public function render_free_shipping_progress_bar() {
                if ( ! WC()->cart ) {
                        return;
                }

                $threshold = (float) FWS_Settings::get( 'free_shipping_threshold', 2000000 );
                // نسخهٔ ۲.۱۲ (S-05): پیش‌فرض، سقف واقعی روش ارسال رایگان ووکامرس؛ اگر از
                // روش‌ها قابل استخراج نبود (۰) یا مدیر حالت دستی را انتخاب کرده بود، عدد پنل.
                $fws_fs_info         = null;
                $fws_free_via_coupon = false;
                if ( 'yes' === FWS_Settings::get( 'shipping_bar_use_wc_method', 'yes' ) ) {
                        $wc_amount = self::get_wc_free_shipping_min_amount();
                        if ( $wc_amount > 0 ) {
                                $threshold = $wc_amount;
                                // نسخهٔ ۲.۱۲.۷ (H-33): رفتار نوار دقیقاً مثل WC_Shipping_Free_Shipping::is_available():
                                // ۱) حالت either با کوپنِ ارسال رایگان هم برآورده می‌شود — مشتریِ کوپن‌دار
                                // دیگر «تا سقف مانده!» نمی‌بیند در حالی که ووکامرس ارسال رایگان داده؛
                                // ۲) ignore_discounts=yes یعنی مبلغ «قبل از تخفیف کوپن» ملاک است —
                                // کسر تخفیف از مبنا باید رد شود.
                                $fws_fs_info = self::get_chosen_free_shipping_method();
                                if ( $fws_fs_info && 'either' === $fws_fs_info['requires'] && self::cart_has_free_shipping_coupon() ) {
                                        $fws_free_via_coupon = true;
                                }
                        }
                }
                if ( $threshold <= 0 ) {
                        return;
                }

                // نسخه ۲.۹ — مبنای صادقانه نوار: همان عددی که مشتری در سبد می‌بیند (بر اساس حالت نمایش
                // مالیات) منهای تخفیف کوپن‌ها. get_subtotal() قبلی مالیات و کوپن را نادیده می‌گرفت؛
                // نوار می‌توانست «تبریک» بگوید در حالی که ارسال رایگان واقعی ووکامرس فعال نمی‌شد.
                $cart_total = (float) ( WC()->cart ? WC()->cart->get_displayed_subtotal() : 0 );
                // نسخهٔ ۲.۱۲.۷ (H-33): روشِ انتخاب‌شده ignore_discounts دارد → ووکامرس تخفیف‌ها را نادیده می‌گیرد؛ نوار هم همان کار را می‌کند.
                $fws_apply_discount_cut = ! ( $fws_fs_info && 'yes' === (string) $fws_fs_info['ignore_discounts'] );
                if ( $fws_apply_discount_cut && WC()->cart && method_exists( WC()->cart, 'get_discount_total' ) ) {
                        // نسخه ۲.۹.۱ — باگ ۳ (سازگاری حالت مالیاتی): get_displayed_subtotal() در
                        // فروشگاه‌های «نمایش قیمت همراه با مالیات» عدد بامالات برمی‌گرداند، اما
                        // get_discount_total() همیشه بدون مالیات است؛ کسر مستقیم، «مانده تا سقف» را
                        // بزرگ‌تر از واقعیت نشان می‌داد و نوار می‌توانست هنوز ناتمام را ناتمام نشان دهد
                        // (یا برعکس). سهم مالیاتیِ تخفیف هم فقط در همان حالت کسر می‌شود.
                        $discount_total = (float) WC()->cart->get_discount_total();
                        if ( method_exists( WC()->cart, 'display_prices_including_tax' )
                                && WC()->cart->display_prices_including_tax()
                                && method_exists( WC()->cart, 'get_discount_tax' ) ) {
                                $discount_total += (float) WC()->cart->get_discount_tax();
                        }
                        $cart_total -= $discount_total;
                }
                $cart_total = max( 0, $cart_total );
                // نسخهٔ ۲.۱۲.۷ (H-33): کوپن ارسال رایگان + حالت either → ووکامرس همین حالا ارسال رایگان می‌دهد؛ نوار «تبریک» می‌گوید.
                if ( $fws_free_via_coupon ) {
                        $cart_total = $threshold;
                }
                $percentage = min( 100, round( ( $cart_total / $threshold ) * 100 ) );
                $remaining  = max( 0, $threshold - $cart_total );

                $engine  = FWS_Prediction_Engine::get_instance();
                $fillers = ( $remaining > 0 ) ? $engine->get_free_shipping_fillers( $cart_total, $threshold ) : array();
                ?>
                <div class="fws-shipping-bar-wrapper"<?php echo FWS_Style_Manager::dir_attr(); ?> data-fws-widget="shipping">
                        <div class="fws-shipping-status">
                                <?php if ( $remaining <= 0 ) : ?>
                                        <span class="fws-shipping-success"><?php echo esc_html( FWS_Style_Manager::style_text( '🎉 تبریک! سفارش شما مشمول ارسال کاملاً رایگان شد!' ) ); ?></span>
                                <?php else : ?>
                                        <span>تنها <strong><?php echo wc_price( $remaining ); ?></strong> تا <strong>ارسال کاملاً رایگان</strong> سفارش شما مانده!</span>
                                <?php endif; ?>
                                <span class="fws-shipping-percent"><?php echo esc_html( $percentage ); ?>٪</span>
                        </div>
                        <div class="fws-progress-track">
                                <div class="fws-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></div>
                        </div>

                        <?php if ( ! empty( $fillers ) ) : ?>
                                <div class="fws-fillers-row">
                                        <?php
                                        // نسخه ۲.۹ — عنوان صادقانه: اگر هیچ‌یک از کالاهای پیشنهادی شکاف ارسال رایگان
                                        // را پر نمی‌کنند، دیگر ادعای «برای رسیدن به سقف» نمایش داده نمی‌شود.
                                        $all_bridge = true;
                                        foreach ( $fillers as $fil ) {
                                                if ( empty( $fil['bridges_gap'] ) ) {
                                                        $all_bridge = false;
                                                        break;
                                                }
                                        }
                                        ?>
                                        <span class="fws-fillers-title">
                                                <?php
                                                echo $all_bridge
                                                        ? esc_html__( 'کالاهای پیشنهادی متناسب با سبد برای رسیدن به سقف ارسال رایگان:', 'fast-woo-sale' )
                                                        : esc_html__( 'کالاهای پیشنهادی مکمل سبد خرید شما:', 'fast-woo-sale' );
                                                ?>
                                        </span>
                                        <div class="fws-fillers-list">
                                                <?php foreach ( $fillers as $fil ) : ?>
                                                        <div class="fws-filler-card">
                                                                <img src="<?php echo esc_url( $fil['image'] ); ?>" alt="<?php echo esc_attr( $fil['name'] ); ?>" loading="lazy" decoding="async" width="48" height="48">
                                                                <div class="fws-filler-info">
                                                                        <span class="fws-filler-name"><?php echo esc_html( $fil['name'] ); ?></span>
                                                                        <span class="fws-filler-price"><?php echo ( ! empty( $fil['is_variable'] ) ? 'از ' : '' ) . wc_price( $fil['price'] ); ?></span>
                                                                </div>
                                                                <?php if ( ! empty( $fil['is_variable'] ) ) : ?>
                                                                        <!-- نسخهٔ ۲.۱۱ (B-27): لینک انتخاب گزینه برای کالای متغیر -->
                                                                        <a class="fws-quick-add-btn fws-view-product-btn" href="<?php echo esc_url( get_permalink( $fil['product_id'] ) ); ?>">مشاهده و انتخاب گزینه</a>
                                                                <?php else : ?>
                                                                        <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr( $fil['product_id'] ); ?>">+ افزودن</button>
                                                                <?php endif; ?>
                                                        </div>
                                                <?php endforeach; ?>
                                        </div>
                                </div>
                        <?php endif; ?>
                </div>
                <?php
        }

        /**
         * Sales Booster 3: Exit-Intent Rescue Modal
         * نسخه صادقانه: تنها در صورت پیکربندی کد تخفیف واقعی از پنل، وعده تخفیف نمایش داده می‌شود؛
         * در غیر این صورت متن بدون وعده تخفیف نمایش داده می‌شود (رفع وعده تخفیف بی‌پشتوانه).
         */
        public function render_exit_intent_rescue_modal() {
                // نسخهٔ ۲.۱۱ (B-04): چک خام قبلی ('yes' !== FWS_Settings::get(...)) فیلتر
                // fws_component_enabled را دور می‌زد؛ حالا از گیت متمرکز کامپوننت‌ها
                // می‌گذرد (گیت gated_output والد هم سر جایش است — این لایه دوم محکم‌کاری است).
                if ( ! FWS_Style_Manager::component_enabled( 'enable_exit_intent' ) ) {
                        return;
                }
                if ( ! is_cart() && ! is_checkout() ) {
                        return;
                }
                // نسخهٔ ۲.۱۲ (S-03): تریگر mouseleave در موبایل هرگز فایر نمی‌شود؛ اگر مدیر
                // تریگر اسکرول موبایل را روشن نکرده، مودال (و هزینهٔ DOM آن) روی دستگاه لمسی
                // رندر نمی‌شود — نه کد مرده، نه بار اضافه برای ۷۰–۸۰٪ ترافیک ایران.
                if ( function_exists( 'wp_is_mobile' ) && wp_is_mobile() && 'yes' !== FWS_Settings::get( 'exit_intent_mobile', 'no' ) ) {
                        return;
                }
                if ( ! WC()->cart || WC()->cart->is_empty() ) {
                        return;
                }

                // نسخهٔ ۲.۱۳ (I-47): توکن یک‌بارمصرف اثباتِ نمایش — فقط وقتی ردیابی روشن است
                // (بدون ردیابی هیچ بیکنی فرستاده نمی‌شود و توکن بی‌مصرف می‌ماند). سشن مشتری
                // روی سبد/چک‌اوت همیشه در دسترس است؛ صفحات مذکور معمولاً کشِ کامل هم نمی‌گیرند.
                $fws_exit_token = '';
                if ( class_exists( 'FWS_Tracker' ) && FWS_Tracker::tracking_enabled() && WC()->session ) {
                        $fws_exit_token = wp_generate_password( 16, false );
                        WC()->session->set( 'fws_exit_token', $fws_exit_token );
                }

                $coupon_code = trim( (string) FWS_Settings::get( 'exit_intent_coupon', '' ) );
                $has_coupon  = ( '' !== $coupon_code );
                ?>
                <div id="fws-exit-intent-modal" class="fws-modal" style="display:none;"<?php echo FWS_Style_Manager::dir_attr(); ?> role="dialog" aria-modal="true" aria-labelledby="fws-modal-title"<?php echo ( '' !== $fws_exit_token ) ? ' data-exit-token="' . esc_attr( $fws_exit_token ) . '"' : ''; ?>>
                        <div class="fws-modal-content">
                                <button type="button" class="fws-modal-close" aria-label="بستن">&times;</button>
                                <div class="fws-modal-header">
                                        <?php if ( $has_coupon ) : ?>
                                                <span class="fws-modal-badge"><?php echo esc_html( FWS_Style_Manager::style_text( '🎁 هدیه پایانی' ) ); ?></span>
                                                <h3 id="fws-modal-title">آیا قبل از تکمیل سفارش قصد خروج دارید؟</h3>
                                                <p>همین حالا سفارش خود را تکمیل کنید؛ کد تخفیف <strong>«<?php echo esc_html( $coupon_code ); ?>»</strong> به‌صورت خودکار روی سبد شما اعمال می‌شود.</p>
                                        <?php else : ?>
                                                <span class="fws-modal-badge"><?php echo esc_html( FWS_Style_Manager::style_text( '🛒 سبد خرید شما آماده است' ) ); ?></span>
                                                <h3 id="fws-modal-title">آیا قبل از تکمیل سفارش قصد خروج دارید؟</h3>
                                                <p>اقلام انتخابی شما در سبد خرید محفوظ می‌ماند؛ هر زمان که آماده بودید می‌توانید خرید خود را تکمیل کنید.</p>
                                        <?php endif; ?>
                                </div>
                                <div class="fws-modal-actions">
                                        <?php if ( $has_coupon ) : ?>
                                                <button type="button" class="fws-modal-confirm-btn fws-modal-coupon-btn">اعمال تخفیف و تکمیل خرید</button>
                                        <?php else : ?>
                                                <a href="<?php echo wc_get_checkout_url(); ?>" class="fws-modal-confirm-btn">ادامه فرآیند خرید</a>
                                        <?php endif; ?>
                                        <button type="button" class="fws-modal-dismiss-btn">خیر، انصراف</button>
                                </div>
                        </div>
                </div>
                <?php
        }

        public function render_my_account_prediction_widget() {
                if ( ! is_user_logged_in() ) {
                        return;
                }
                $user_id    = get_current_user_id();
                $engine     = FWS_Prediction_Engine::get_instance();
                $prediction = $engine->get_user_next_purchase_prediction( $user_id );
                if ( $prediction ) {
                        // نسخهٔ ۲.۱۲.۲ (B-42): نمایش در فضای قیمت صفحهٔ خود کالا (تنظیمات مالیات فروشگاه)
                        $fws_pred_prod       = wc_get_product( $prediction['product_id'] );
                        $prediction['price'] = FWS_Prediction_Engine::display_price( $fws_pred_prod, (float) $prediction['price'], 'shop' );
                        ?>
                        <div class="fws-account-prediction-box"<?php echo FWS_Style_Manager::dir_attr(); ?> data-fws-widget="account">
                                <div class="fws-account-prediction-badge"><?php echo esc_html( FWS_Style_Manager::style_text( '✨ پیش‌بینی هوشمند سفارش بعدی شما' ) ); ?></div>
                                <h4 class="fws-account-title"><?php echo esc_html( FWS_AB_Testing::widget_title( 'account', 'کالای متناسب با سلیقه و سوابق خرید شما:' ) ); ?></h4>
                                <div class="fws-account-item">
                                        <img src="<?php echo esc_url( $prediction['image'] ); ?>" alt="<?php echo esc_attr( $prediction['name'] ); ?>" loading="lazy" decoding="async" width="56" height="56">
                                        <div class="fws-account-item-info">
                                                <strong><?php echo esc_html( $prediction['name'] ); ?></strong>
                                                <span class="fws-account-item-price"><?php echo ( ! empty( $prediction['is_variable'] ) ? 'از ' : '' ) . wc_price( $prediction['price'] ); ?></span>
                                                <?php if ( FWS_Style_Manager::show_confidence_tags() ) : ?>
                                                        <span class="fws-account-item-conf"><?php echo esc_html( $prediction['confidence'] ); ?>٪ احتمال علاقه‌مندی شما</span>
                                                <?php endif; ?>
                                        </div>
                                        <?php if ( ! empty( $prediction['is_variable'] ) ) : ?>
                                                <!-- نسخهٔ ۲.۱۱ (B-27): لینک انتخاب گزینه برای کالای متغیر -->
                                                <a class="fws-quick-add-btn fws-view-product-btn" href="<?php echo esc_url( get_permalink( $prediction['product_id'] ) ); ?>">مشاهده و انتخاب گزینه</a>
                                        <?php else : ?>
                                                <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr( $prediction['product_id'] ); ?>">+ افزودن سریع به سبد</button>
                                        <?php endif; ?>
                                </div>
                        </div>
                        <?php
                }
        }

        /**
         * شورت‌کد اختصاصی جهت نمایش پیشنهادهای کالا در هر بخش دلخواه قالب یا صفحه‌ساز
         * نحوه استفاده: [fws_predicted_products id="123" limit="3" title="کالاهای پیشنهادی"]
         */
        public function shortcode_handler( $atts ) {
                // نسخهٔ ۲.۱۱ (قاعدهٔ طلایی): کلید سراسری شورت‌کدها — پیش‌فرض روشن تا نصب‌های
                // فعلی بی‌تغییر بمانند؛ با خاموشی، هیچ شورت‌کدی خروجی رندر نمی‌کند.
                if ( 'yes' !== FWS_Settings::get( 'enable_shortcodes', 'yes' ) ) {
                        return '';
                }
                $atts = shortcode_atts(
                        array(
                                'id'    => 0,
                                'limit' => 3,
                                'title' => 'پیشنهادهای هوشمند دیتابیس (خریداری‌شده با این کالا)',
                        ),
                        $atts,
                        'fws_predicted_products'
                );

                $product_id = absint( $atts['id'] );
                if ( $product_id <= 0 ) {
                        global $product;
                        if ( $product && is_a( $product, 'WC_Product' ) ) {
                                $product_id = $product->get_id();
                        }
                }

                if ( $product_id <= 0 ) {
                        return '';
                }

                $engine          = FWS_Prediction_Engine::get_instance();
                $recommendations = $engine->get_recommendations_for_product( $product_id, absint( $atts['limit'] ) );
                if ( empty( $recommendations ) ) {
                        return '';
                }

                // نسخهٔ ۲.۱۲.۲ (B-42): نمایش در فضای قیمت صفحهٔ خود کالا (تنظیمات مالیات فروشگاه)
                foreach ( $recommendations as $fws_rkey => $fws_rec ) {
                        $fws_rec_prod = wc_get_product( $fws_rec['product_id'] );
                        $recommendations[ $fws_rkey ]['price'] = FWS_Prediction_Engine::display_price( $fws_rec_prod, (float) $fws_rec['price'], 'shop' );
                }

                // نسخه ۲.۱۰: شورت‌کد = انتخاب صریح مدیر؛ نمایشش به‌عنوان ویجت «شورت‌کد» ثبت می‌شود
                if ( class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::log_impression( 'shortcode' );
                }

                ob_start();
                ?>
                <div class="fws-cart-recommendations-wrapper fws-shortcode-wrapper"<?php echo FWS_Style_Manager::dir_attr(); ?> data-fws-widget="shortcode">
                        <h4 class="fws-cart-title"><?php echo esc_html( FWS_Style_Manager::style_text( $atts['title'] ) ); ?></h4>
                        <div class="fws-cart-grid">
                                <?php foreach ( $recommendations as $rec ) : ?>
                                        <div class="fws-cart-item">
                                                <img src="<?php echo esc_url( $rec['image'] ); ?>" alt="<?php echo esc_attr( $rec['name'] ); ?>" loading="lazy" decoding="async" width="56" height="56">
                                                <div class="fws-cart-item-details">
                                                        <strong><?php echo esc_html( $rec['name'] ); ?></strong>
                                                        <span class="fws-cart-item-price"><?php echo ( ! empty( $rec['is_variable'] ) ? 'از ' : '' ) . wc_price( $rec['price'] ); ?></span>
                                                        <?php if ( FWS_Style_Manager::show_confidence_tags() ) : ?>
                                                                <?php if ( ! empty( $rec['is_fallback'] ) ) : ?>
                                                                <span class="fws-cart-confidence is-fallback"><?php echo esc_html( FWS_Style_Manager::style_text( 'پیشنهاد فروشگاه برای شما' ) ); ?></span>
                                                        <?php elseif ( ! empty( $rec['is_manual'] ) ) : ?>
                                                                <span class="fws-cart-confidence is-manual"><?php echo esc_html( FWS_Style_Manager::style_text( 'پیشنهاد مدیر فروشگاه (' . $rec['confidence'] . '٪ اولویت)' ) ); ?></span>
                                                        <?php else : ?>
                                                                <span class="fws-cart-confidence"><?php echo esc_html( $rec['confidence'] ); ?>٪ تطابق سفارشات</span>
                                                        <?php endif; ?>
                                                        <?php endif; ?>
                                                </div>
                                                <?php if ( ! empty( $rec['is_variable'] ) ) : ?>
                                                        <!-- نسخهٔ ۲.۱۱ (B-27): لینک انتخاب گزینه برای کالای متغیر -->
                                                        <a class="fws-quick-add-btn fws-view-product-btn" href="<?php echo esc_url( get_permalink( $rec['product_id'] ) ); ?>">مشاهده و انتخاب گزینه</a>
                                                <?php else : ?>
                                                        <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr( $rec['product_id'] ); ?>">+ افزودن به سبد</button>
                                                <?php endif; ?>
                                        </div>
                                <?php endforeach; ?>
                        </div>
                </div>
                <?php
                return apply_filters( 'fws_widget_html', ob_get_clean(), 'shortcode_predicted_products' );
        }

        /**
         * شورت‌کد نمایش پکیج هوشمند کالا در هر صفحه یا تب دلخواه: [fws_bundle id="123"]
         */
        public function render_bundle_shortcode( $atts ) {
                // نسخهٔ ۲.۱۱ (قاعدهٔ طلایی): کلید سراسری شورت‌کدها
                if ( 'yes' !== FWS_Settings::get( 'enable_shortcodes', 'yes' ) ) {
                        return '';
                }
                // نسخهٔ ۲.۱۲.۴ (F-15): اگر نسخهٔ خودکار همین ویجت در این درخواست رندر شده،
                // شورت‌کد دوباره UI و ردیف نمایش نمی‌سازد (دو نسخهٔ موازی = آمار خراب).
                if ( isset( self::$rendered_widgets['enable_widget_product'] ) ) {
                        return '';
                }
                $atts = shortcode_atts(
                        array(
                                'id' => 0,
                        ),
                        $atts,
                        'fws_bundle'
                );

                $product_id = absint( $atts['id'] );
                if ( $product_id <= 0 && function_exists( 'is_product' ) && is_product() ) {
                        global $product;
                        $product_id = ( $product && is_a( $product, 'WC_Product' ) ) ? $product->get_id() : 0;
                }

                if ( $product_id <= 0 ) {
                        return '';
                }

                ob_start();
                $this->render_product_recommendations_box( $product_id );
                $fws_sc_html = ob_get_clean();

                // نسخه ۲.۱۰: نمایش شورت‌کد باندل زیر ویجت «صفحه محصول» شمرده می‌شود (محتوای یکسان)
                if ( '' !== trim( $fws_sc_html ) && class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::log_impression( 'product' );
                }
                // نسخهٔ ۲.۱۲.۵ (F-28): ثبت در دفترچهٔ رندر — تا پیش از این شورت‌کد فقط «می‌خواند»
                // و «می‌نوشت» نه؛ شورت‌کدِ اول + رندر خودکارِ بعدی = دو جعبهٔ یکسان در یک صفحه.
                if ( '' !== trim( $fws_sc_html ) ) {
                        self::$rendered_widgets['enable_widget_product'] = true;
                }
                return apply_filters( 'fws_widget_html', $fws_sc_html, 'shortcode_bundle' );
        }

        /**
         * شورت‌کد نوار پیشرفت ارسال رایگان: [fws_free_shipping_bar]
         */
        public function render_free_shipping_shortcode( $atts ) {
                // نسخهٔ ۲.۱۱ (قاعدهٔ طلایی): کلید سراسری شورت‌کدها
                if ( 'yes' !== FWS_Settings::get( 'enable_shortcodes', 'yes' ) ) {
                        return '';
                }
                // نسخهٔ ۲.۱۲.۴ (F-15): جلوگیری از رندر و ثبت نمایش تکراری در یک درخواست
                if ( isset( self::$rendered_widgets['enable_widget_shipping'] ) ) {
                        return '';
                }
                ob_start();
                $this->render_free_shipping_progress_bar();
                $fws_sc_html = ob_get_clean();

                // نسخه ۲.۱۰: نمایش شورت‌کد نوار ارسال زیر ویجت «نوار ارسال رایگان» شمرده می‌شود
                if ( '' !== trim( $fws_sc_html ) && class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::log_impression( 'shipping' );
                }
                // نسخهٔ ۲.۱۲.۵ (F-28): ثبت در دفترچهٔ رندر (توضیح در render_bundle_shortcode).
                if ( '' !== trim( $fws_sc_html ) ) {
                        self::$rendered_widgets['enable_widget_shipping'] = true;
                }
                return apply_filters( 'fws_widget_html', $fws_sc_html, 'shortcode_shipping_bar' );
        }

        /**
         * شورت‌کد پیشنهادات متناسب با سبد خرید: [fws_cart_recommendations]
         */
        public function render_cart_recommendations_shortcode( $atts ) {
                // نسخهٔ ۲.۱۱ (قاعدهٔ طلایی): کلید سراسری شورت‌کدها
                if ( 'yes' !== FWS_Settings::get( 'enable_shortcodes', 'yes' ) ) {
                        return '';
                }
                // نسخهٔ ۲.۱۲.۴ (F-15): جلوگیری از رندر و ثبت نمایش تکراری در یک درخواست
                if ( isset( self::$rendered_widgets['enable_widget_cart'] ) ) {
                        return '';
                }
                ob_start();
                $this->render_cart_recommendations_box();
                $fws_sc_html = ob_get_clean();

                // نسخه ۲.۱۰: نمایش شورت‌کد سبد زیر ویجت «پیشنهادات سبد» شمرده می‌شود
                if ( '' !== trim( $fws_sc_html ) && class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::log_impression( 'cart' );
                }
                // نسخهٔ ۲.۱۲.۵ (F-28): ثبت در دفترچهٔ رندر (توضیح در render_bundle_shortcode).
                if ( '' !== trim( $fws_sc_html ) ) {
                        self::$rendered_widgets['enable_widget_cart'] = true;
                }
                return apply_filters( 'fws_widget_html', $fws_sc_html, 'shortcode_cart_recommendations' );
        }

        /**
         * ارتقای هوشمند نتایج جستجو: تزریق پرفروش‌ترین کالای مکمل خریداری‌شده در کنار محصول جستجوشده به حلقه نتایج
         * مثال کاربر: خریدار لپ‌تاپ هنگام سرچ لپ‌تاپ، پرفروش‌ترین ماوس مکمل را بالاتر در نتایج مشاهده می‌کند
         */
        public function inject_complementary_into_search_results( $posts, $query ) {
                if ( 'yes' !== FWS_Settings::get( 'enable_search_injection', 'yes' ) ) {
                        return $posts;
                }
                if ( ! is_search() || is_admin() || ! $query->is_main_query() ) {
                        return $posts;
                }

                // فقط جستجوهای مرتبط با محصولات؛ نتایج وبلاگ/صفحات دست‌نخورده می‌مانند
                // BUG-08 fix (v2.8.1): a plain WordPress search (?s=...) has an EMPTY post_type and used to
                // pass this guard, so a product was spliced into blog/page results - contrary to the
                // setting's description. Inject only when the query is explicitly a product search.
                $q_post_type       = $query->get( 'post_type' );
                $is_product_search = ( 'product' === $q_post_type )
                        || ( is_array( $q_post_type ) && in_array( 'product', $q_post_type, true ) )
                        || ( function_exists( 'is_post_type_archive' ) && $query->is_post_type_archive( 'product' ) );
                if ( ! $is_product_search ) {
                        return $posts;
                }

                // تزریق فقط در صفحه اول نتایج: صفحات بعدی pagination نتیجه تکراری می‌بینند و شمارش صفحه به‌هم می‌ریخت
                $paged = (int) $query->get( 'paged' );
                if ( $paged > 1 ) {
                        return $posts;
                }

                $search_query = get_search_query();
                if ( empty( $search_query ) || empty( $posts ) ) {
                        return $posts;
                }

                $engine = FWS_Prediction_Engine::get_instance();
                // همان کلید کش بنر (limit=2) بازاستفاده می‌شود تا یک کوئری اضافی حذف شود
                $complements = $engine->get_search_co_occurrence_recommendations( $search_query, 2 );
                if ( empty( $complements ) ) {
                        return $posts;
                }

                $complement_id = absint( $complements[0]['product_id'] );
                $existing_ids  = wp_list_pluck( $posts, 'ID' );

                // اگر محصول مکمل در نتایج اولیه نیست، آن را در رتبه دوم نتایج تزریق کن
                // (نکته: تخصیص پراپرتی داینامیک به WP_Post در PHP 8.2+ منسوخ است؛ بنر دلیل پیشنهاد را جداگانه نمایش می‌دهد)
                if ( ! in_array( $complement_id, $existing_ids, true ) ) {
                        $complement_post = get_post( $complement_id );
                        // نسخهٔ ۲.۱۲.۷ (H-28): سه گارد صریح قبل از تزریق — نوع پست باید product،
                        // وضعیتش publish و ووکامرس بتواند آبجکت معتبر بسازد. قوانین استخراج‌شده
                        // ممکن است به کالایی اشاره کنند که بعداً حذف/پیش‌نویس شده؛ این‌ها هرگز
                        // وارد نتایج جستجو نمی‌شوند (گاردهای is_recommendable موتور هم اکنون
                        // وضعیت انتشار را چک می‌کنند — اینجا لایهٔ دفاع دوم است).
                        if ( $complement_post
                                && 'product' === $complement_post->post_type
                                && 'publish' === $complement_post->post_status
                                && wc_get_product( $complement_id ) instanceof WC_Product ) {
                                array_splice( $posts, 1, 0, array( $complement_post ) );
                        }
                }

                return $posts;
        }

        /**
         * رندر بنر هوشمند پیشنهاد کالای مکمل پرفروش در بالای لیست نتایج جستجو
         */
        public function render_search_complementary_banner() {
                if ( ! is_search() ) {
                        return;
                }
                // نسخه ۲.۸: گیت مستقل بنر جستجو (جدای تزریق نتایج)
                if ( ! FWS_Style_Manager::component_enabled( 'enable_search_banner' ) ) {
                        return;
                }

                $search_query = get_search_query();
                if ( empty( $search_query ) ) {
                        return;
                }

                $engine      = FWS_Prediction_Engine::get_instance();
                $complements = $engine->get_search_co_occurrence_recommendations( $search_query, 2 );
                if ( empty( $complements ) ) {
                        return;
                }

                // نسخهٔ ۲.۱۲.۲ (B-42): نمایش در فضای قیمت صفحهٔ خود کالا (تنظیمات مالیات فروشگاه)
                foreach ( $complements as $fws_ckey => $fws_item ) {
                        $fws_item_prod                       = wc_get_product( $fws_item['product_id'] );
                        $complements[ $fws_ckey ]['price'] = FWS_Prediction_Engine::display_price( $fws_item_prod, (float) $fws_item['price'], 'shop' );
                }

                ?>
                <div class="fws-search-booster-banner"<?php echo FWS_Style_Manager::dir_attr(); ?> data-fws-widget="search">
                        <div class="fws-search-booster-header">
                                <span class="fws-search-badge"><?php echo esc_html( FWS_Style_Manager::style_text( '🎯 پیشنهاد هوشمند خریداران قبلی' ) ); ?></span>
                                <?php
                                // نسخه ۲.۱۰: عنوان بنر جستجو از A/B تست عبور می‌کند؛
                                // در عنوان سفارشی می‌توان از {query} به‌عنوان جای‌نگهدار عبارت جستجو استفاده کرد
                                $fws_search_title = str_replace(
                                        '{query}',
                                        $search_query,
                                        FWS_AB_Testing::widget_title( 'search', 'خریداران «{query}» معمولاً این کالای مکمل را نیز خریده‌اند:' )
                                );
                                ?>
                                <h3 class="fws-search-title"><?php echo esc_html( $fws_search_title ); ?></h3>
                                <p class="fws-search-subtitle">بر اساس تحلیل داده‌کاوی سبدهای خرید، این محصول بیشترین نرخ خرید همزمان را داشته است.</p>
                        </div>
                        <div class="fws-search-items-row">
                                <?php foreach ( $complements as $item ) : ?>
                                        <div class="fws-search-item-card">
                                                <div class="fws-search-item-body">
                                                        <a href="<?php echo esc_url( get_permalink( $item['product_id'] ) ); ?>" class="fws-search-item-thumb">
                                                                <img src="<?php echo esc_url( $item['image'] ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>" loading="lazy" decoding="async" width="60" height="60">
                                                        </a>
                                                        <div class="fws-search-item-info">
                                                                <?php if ( FWS_Style_Manager::show_confidence_tags() ) : ?>
                                                                        <span class="fws-search-co-tag"><?php echo esc_html( FWS_Style_Manager::style_text( '🔥 ' . $item['confidence'] . '٪ سفارش همزمان' ) ); ?></span>
                                                                <?php endif; ?>
                                                                <a href="<?php echo esc_url( get_permalink( $item['product_id'] ) ); ?>" class="fws-search-name">
                                                                        <strong><?php echo esc_html( $item['name'] ); ?></strong>
                                                                </a>
                                                                <span class="fws-search-reason"><?php echo esc_html( $item['reason'] ); ?></span>
                                                                <div class="fws-search-action-bar">
                                                                        <span class="fws-search-price"><?php echo ( ! empty( $item['is_variable'] ) ? 'از ' : '' ) . wc_price( $item['price'] ); ?></span>
                                                                        <?php if ( ! empty( $item['is_variable'] ) ) : ?>
                                                                                <!-- نسخهٔ ۲.۱۱ (B-27): لینک انتخاب گزینه برای کالای متغیر -->
                                                                                <a class="fws-quick-add-btn fws-view-product-btn" href="<?php echo esc_url( get_permalink( $item['product_id'] ) ); ?>">مشاهده و انتخاب گزینه</a>
                                                                        <?php else : ?>
                                                                                <button type="button" class="fws-quick-add-btn" data-product-id="<?php echo esc_attr( $item['product_id'] ); ?>">
                                                                                        + افزودن فوری به سبد
                                                                                </button>
                                                                        <?php endif; ?>
                                                                </div>
                                                        </div>
                                                </div>
                                        </div>
                                <?php endforeach; ?>
                        </div>
                </div>
                <?php
        }
}
