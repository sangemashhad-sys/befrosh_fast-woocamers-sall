<?php
/**
 * Class FWS_Admin_Page
 * پیشخوان مدیریتی و گزارش‌گیری الگوهای دیتابیس در ووکامرس
 * نسخه ۲.۷: رابط دولایه (ساده/تخصصی)، استراتژی موتور، قوانین دستی مدیر،
 * لیست سیاه محصولات، نقشه درختی ارتباطات (Treemap) و جدول قوانین برتر
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Admin_Page {

        private static $instance = null;

        public static function get_instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        public function __construct() {
                add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
                add_action( 'admin_init', array( $this, 'register_settings' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

                // پس از ذخیره تنظیمات، کش پیشنهادات فوراً با آستانه‌های جدید همگام می‌شود
                // نسخهٔ ۲.۹.۳ — فقط update_option_ گره خورده بود؛ «اولین ذخیرهٔ تاریخ» گزینه
                // (وقتی هنوز ردیف option وجود ندارد) از add_option_ می‌گذرد و حذف کامل گزینه
                // (بازنشانی) از delete_option_ — در هر دو مسیر، تنظیماتِ تازه با کشِ ساخته‌شده
                // با مقادیر پیش‌فرض تا ۲۴ ساعت ناسازگار می‌ماند. هر سه مسیر اکنون هم‌پوشانی دارند.
                add_action( 'update_option_' . FWS_Settings::OPTION_KEY, array( $this, 'flush_engine_cache' ) );
                add_action( 'add_option_' . FWS_Settings::OPTION_KEY, array( $this, 'flush_engine_cache' ) );
                add_action( 'delete_option_' . FWS_Settings::OPTION_KEY, array( $this, 'flush_engine_cache' ) );

                // مدیریت آنی قوانین دستی و لیست سیاه (فقط مدیر فروشگاه)
                add_action( 'wp_ajax_fws_admin_search_products', array( $this, 'ajax_search_products' ) );
                add_action( 'wp_ajax_fws_add_manual_rule', array( $this, 'ajax_add_manual_rule' ) );
                add_action( 'wp_ajax_fws_delete_manual_rule', array( $this, 'ajax_delete_manual_rule' ) );
                add_action( 'wp_ajax_fws_add_blacklist_product', array( $this, 'ajax_add_blacklist_product' ) );
                add_action( 'wp_ajax_fws_remove_blacklist_product', array( $this, 'ajax_remove_blacklist_product' ) );

                // نسخه ۲.۱۰: مدیریت A/B تست
                add_action( 'wp_ajax_fws_ab_create', array( $this, 'ajax_ab_create' ) );
                add_action( 'wp_ajax_fws_ab_stop', array( $this, 'ajax_ab_stop' ) );
                add_action( 'wp_ajax_fws_ab_delete', array( $this, 'ajax_ab_delete' ) );
                add_action( 'wp_ajax_fws_ab_check', array( $this, 'ajax_ab_check' ) );
                add_action( 'wp_ajax_fws_ab_auto', array( $this, 'ajax_ab_auto' ) ); // نسخهٔ ۲.۱۲.۱ — قفل خودکار برنده
        }

        public function add_admin_menu() {
                add_submenu_page(
                        'woocommerce',
                        'پیش‌بینی و پیشنهاد هوشمند خرید',
                        'پیش‌بینی هوشمند خرید',
                        'manage_woocommerce',
                        'fast-woo-predictive',
                        array( $this, 'render_admin_dashboard' )
                );
        }

        public function register_settings() {
                register_setting(
                        'fws_settings_group',
                        FWS_Settings::OPTION_KEY,
                        array(
                                'type'              => 'array',
                                'sanitize_callback' => array( 'FWS_Settings', 'sanitize' ),
                                'default'           => FWS_Settings::defaults(),
                        )
                );

                // نسخه ۲.۹.۲ — رفع باگ نقش‌ها: صفحهٔ تنظیمات با «manage_woocommerce» به مدیر فروشگاه
                // نشان داده می‌شود، اما wp-admin/options.php به‌طور پیش‌فرض برای ذخیره «manage_options»
                // می‌خواهد؛ در نتیجه مدیر فروشگاه فرم را می‌دید ولی هنگام ذخیره با wp_die مواجه می‌شد
                // («شما مجاز به مدیریت این گزینه‌ها نیستید»). ظرفیت ذخیرهٔ همین گروه هم‌تراز صفحه کردیم.
                add_filter(
                        'option_page_capability_fws_settings_group',
                        static function () {
                                return 'manage_woocommerce';
                        }
                );
        }

        public function enqueue_admin_assets( $hook ) {
                if ( false === strpos( (string) $hook, 'fast-woo-predictive' ) ) {
                        return;
                }
                wp_enqueue_style( 'fws-admin', FWS_PLUGIN_URL . 'assets/css/fws-admin.css', array(), FWS_VERSION );
                wp_enqueue_script( 'fws-admin', FWS_PLUGIN_URL . 'assets/js/fws-admin.js', array( 'jquery' ), FWS_VERSION, true );
                wp_localize_script(
                        'fws-admin',
                        'fws_admin_params',
                        array(
                                'ajax_url' => admin_url( 'admin-ajax.php' ),
                                'nonce'    => wp_create_nonce( 'fws_admin_nonce' ),
                                'i18n'     => array(
                                        'search_placeholder' => 'حداقل ۲ حرف تایپ کنید…',
                                        'no_results'         => 'محصولی یافت نشد',
                                        'select_product'     => 'انتخاب نشده',
                                ),
                        )
                );

                // نسخه ۲.۸: استایل فرانت‌اند + متغیرهای پویا برای «پیش‌نمایش زنده» پنل شخصی‌سازی
                if ( class_exists( 'FWS_Style_Manager' ) ) {
                        wp_enqueue_style( 'fws-recommendations', FWS_PLUGIN_URL . 'assets/css/fws-recommendations.css', array( 'fws-admin' ), FWS_VERSION );
                        $style_manager = FWS_Style_Manager::get_instance();
                        $dynamic       = $style_manager->build_variables_css() . "\n"
                                // هر دو بلوک پیش‌تنظیم منتشر می‌شود تا سوییچ زنده در پیش‌نمایش کار کند
                                . $style_manager->build_preset_css( true );
                        wp_add_inline_style( 'fws-recommendations', $dynamic );
                }
        }

        public function flush_engine_cache() {
                if ( class_exists( 'FWS_Database_Miner' ) ) {
                        FWS_Database_Miner::purge_cache();
                }
                // نسخه ۲.۱۰: بینش‌ها پس از هر تغییر تنظیمات تازه‌سازی شوند
                if ( class_exists( 'FWS_Insights' ) ) {
                        FWS_Insights::flush();
                }
        }

        /** ─────────────── AJAX: مدیریت آنی قوانین دستی و لیست سیاه ─────────────── */

        private function verify_admin_ajax() {
                check_ajax_referer( 'fws_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ) );
                }
        }

        public function ajax_search_products() {
                $this->verify_admin_ajax();
                $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
                if ( mb_strlen( $term ) < 2 ) {
                        wp_send_json_success( array( 'results' => array() ) );
                }
                global $wpdb;
                $like    = '%' . $wpdb->esc_like( $term ) . '%';
                $rows    = $wpdb->get_results(
                        $wpdb->prepare(
                                "
            SELECT ID, post_title FROM {$wpdb->posts}
            WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE %s
            ORDER BY post_date DESC LIMIT 20
        ",
                                $like
                        )
                );
                $results = array();
                foreach ( (array) $rows as $row ) {
                        $results[] = array(
                                'id'   => (int) $row->ID,
                                'text' => $row->post_title,
                        );
                }
                wp_send_json_success( array( 'results' => $results ) );
        }

        public function ajax_add_manual_rule() {
                $this->verify_admin_ajax();
                $source     = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
                $target     = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
                $confidence = isset( $_POST['confidence'] ) ? absint( $_POST['confidence'] ) : 95;

                if ( $source <= 0 || $target <= 0 ) {
                        wp_send_json_error( array( 'message' => 'هر دو محصول (مبدأ و مکمل) باید انتخاب شوند.' ) );
                }
                if ( $source === $target ) {
                        wp_send_json_error( array( 'message' => 'محصول مبدأ و مکمل نمی‌توانند یکسان باشند.' ) );
                }
                if ( 'product' !== get_post_type( $source ) || 'product' !== get_post_type( $target ) ) {
                        wp_send_json_error( array( 'message' => 'شناسه محصولات نامعتبر است.' ) );
                }

                $rules = FWS_Settings::sanitize_rules_list( (array) FWS_Settings::get( 'manual_rules', array() ) );
                foreach ( $rules as $rule ) {
                        if ( (int) $rule['source'] === $source && (int) $rule['target'] === $target ) {
                                wp_send_json_error( array( 'message' => 'این قانون قبلاً ثبت شده است.' ) );
                        }
                }
                if ( count( $rules ) >= 100 ) {
                        wp_send_json_error( array( 'message' => 'سقف ۱۰۰ قانون دستی پر شده است.' ) );
                }
                $rules[] = array(
                        'source'     => $source,
                        'target'     => $target,
                        'confidence' => max( 50, min( 100, $confidence ) ),
                );
                FWS_Settings::persist_key( 'manual_rules', $rules );
                FWS_Database_Miner::purge_cache();

                // نسخهٔ ۲.۱۲.۲ (B-45): هشدار شفاف در لحظهٔ ثبت اگر مقصد فعلاً قابل نمایش نباشد —
                // قانون ذخیره می‌شود (موجودی/وضعیت ممکن است بعداً برسد) ولی مدیر همان لحظه
                // می‌داند که ویجت خالی نمی‌ماند یا علت خالی‌ماندنش چیست.
                $fws_target_warning = $this->manual_rule_target_warning( wc_get_product( $target ) );

                wp_send_json_success(
                        array(
                                'message' => sprintf( 'قانون دستی «%s ➔ %s» با اولویت %d٪ ثبت شد.', get_the_title( $source ), get_the_title( $target ), max( 50, min( 100, $confidence ) ) )
                                        . ( '' !== $fws_target_warning ? ' ⚠️ ' . $fws_target_warning : '' ),
                                'count'   => count( $rules ),
                        )
                );
        }

        public function ajax_delete_manual_rule() {
                $this->verify_admin_ajax();
                $source = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
                $target = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;

                $rules   = FWS_Settings::sanitize_rules_list( (array) FWS_Settings::get( 'manual_rules', array() ) );
                $kept    = array();
                $removed = 0;
                foreach ( $rules as $rule ) {
                        if ( (int) $rule['source'] === $source && (int) $rule['target'] === $target && 0 === $removed ) {
                                ++$removed;
                                continue;
                        }
                        $kept[] = $rule;
                }
                if ( ! $removed ) {
                        wp_send_json_error( array( 'message' => 'قانون مورد نظر یافت نشد.' ) );
                }
                FWS_Settings::persist_key( 'manual_rules', $kept );
                FWS_Database_Miner::purge_cache();
                wp_send_json_success(
                        array(
                                'message' => 'قانون دستی حذف شد.',
                                'count'   => count( $kept ),
                        )
                );
        }

        public function ajax_add_blacklist_product() {
                $this->verify_admin_ajax();
                $pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                if ( $pid <= 0 || 'product' !== get_post_type( $pid ) ) {
                        wp_send_json_error( array( 'message' => 'محصول نامعتبر است.' ) );
                }
                $list = FWS_Settings::sanitize_blacklist_ids( (array) FWS_Settings::get( 'product_blacklist', array() ) );
                if ( in_array( $pid, $list, true ) ) {
                        wp_send_json_error( array( 'message' => 'این محصول قبلاً در لیست سیاه است.' ) );
                }
                $list[] = $pid;
                FWS_Settings::persist_key( 'product_blacklist', $list );
                FWS_Database_Miner::purge_cache();
                wp_send_json_success(
                        array(
                                'message' => sprintf( '«%s» به لیست سیاه اضافه شد.', get_the_title( $pid ) ),
                                'count'   => count( $list ),
                        )
                );
        }

        public function ajax_remove_blacklist_product() {
                $this->verify_admin_ajax();
                $pid  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                $list = FWS_Settings::sanitize_blacklist_ids( (array) FWS_Settings::get( 'product_blacklist', array() ) );
                $list = array_values( array_diff( $list, array( $pid ) ) );
                FWS_Settings::persist_key( 'product_blacklist', $list );
                FWS_Database_Miner::purge_cache();
                wp_send_json_success(
                        array(
                                'message' => 'محصول از لیست سیاه حذف شد.',
                                'count'   => count( $list ),
                        )
                );
        }

        /**
         * نسخهٔ ۲.۱۲.۲ (B-45): علت غیرقابل‌نمایش بودن مقصد یک قانون دستی — برای هشدار
         * صادقانهٔ پنل (جدول قوانین + پیام ثبت). رشتهٔ خالی = مقصد در همهٔ ویجت‌ها قابل
         * نمایش است. گیت‌های نمایش عیناً همان‌هایی‌اند که FWS_Prediction_Engine::
         * is_recommendable در رندر اعمال می‌کند؛ پس متن هشدار با رفتار واقعی ویجت یکی است.
         *
         * @param WC_Product|false|null $product
         * @return string
         */
        private function manual_rule_target_warning( $product ) {
                if ( ! $product instanceof WC_Product ) {
                        return 'محصول مقصد یافت نشد (حذف شده) و در هیچ ویجتی نمایش داده نمی‌شود.';
                }
                if ( $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
                        return 'محصول مقصد «گروهی/پیوندی» است و گیت نمایش، آن را در هیچ ویجتی نشان نمی‌دهد.';
                }
                if ( ! $product->is_visible() ) {
                        return 'محصول مقصد از کاتالوگ مخفی است و در ویجت‌ها نمایش داده نمی‌شود.';
                }
                if ( ! $product->is_in_stock() ) {
                        return 'محصول مقصد فعلاً ناموجود است؛ قانون ذخیره می‌ماند و با موجودی‌شدن خودکار نمایش داده می‌شود.';
                }
                if ( ! $product->is_purchasable() ) {
                        return 'محصول مقصد در وضعیت فعلی قابل خرید نیست و در ویجت‌ها نمایش داده نمی‌شود.';
                }
                if ( $product->is_type( 'variable' ) ) {
                        return 'محصول مقصد متغیر است: در ویجت‌ها با لینک «مشاهده و انتخاب گزینه» می‌آید ولی در باکس پکیج صفحهٔ محصول (افزودن یک‌کلیکی) نمایش داده نمی‌شود.';
                }
                return '';
        }

        /** ─────────────── رندر پیشخوان ─────────────── */

        public function render_admin_dashboard() {
                global $wpdb;
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        return;
                }

                $table        = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;
                $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
                $total_rules  = $table_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0;
                $analytics_ok = FWS_Database_Miner::analytics_lookup_table_exists();
                $settings     = FWS_Settings::all();
                ?>
                <div class="wrap" dir="rtl">
                        <h1>سیستم پیش‌بینی و پیشنهاد هوشمند خرید (مبتنی بر دیتابیس سفارشات)</h1>
                        <p>این افزونه با آنالیز جداول سفارشات ووکامرس، بدون ایجاد بار اضافی روی سرور، پیوندهای قوی بین خرید کالاها را شناسایی و پیشنهاد می‌دهد.</p>

                        <?php if ( ! $analytics_ok ) : ?>
                                <div class="notice notice-warning">
                                        <p><strong>هشدار پایداری:</strong> جدول Analytics ووکامرس (<code><?php echo esc_html( $wpdb->prefix ); ?>wc_order_product_lookup</code>) یافت نشد. تحلیل سفارشات تا زمان فعال بودن گزارش‌های ووکامرس (WooCommerce → وضعیت → ساخت مجدد جداول Analytics) نتیجه‌ای تولید نمی‌کند.</p>
                                </div>
                        <?php endif; ?>

                        <?php
                        // نسخه ۲.۹ — نشان «ماینینگ ناقص»: اگر اجرای قبلی تحلیل به‌دلیل سقف حافظه وسط کار
                        // قطع شده باشد، مدیر باید بداند که قوانین فعلی ممکن است کامل نباشند.
                        $fws_incomplete = (int) get_option( 'fws_mining_incomplete', 0 );
                        if ( $fws_incomplete > 0 ) :
                                ?>
                                <div class="notice notice-warning">
                                        <p><strong>هشدار تحلیل ناقص:</strong> آخرین اجرای «تحلیل دیتابیس سفارشات» پیش از رسیدن به پایان (به‌دلیل رسیدن به سقف حافظه) متوقف شد؛ قوانین فعلی ممکن است کامل نباشند. لطفاً «تحلیل مجدد» را دوباره اجرا کنید. <em>(<?php echo esc_html( date_i18n( 'Y/m/d H:i', $fws_incomplete ) ); ?>)</em></p>
                                </div>
                        <?php endif; ?>

                        <div class="card fws-admin-card">
                                <h2>📊 وضعیت موتور و کش ماتریس همبستگی</h2>
                                <table class="widefat striped" style="margin-top: 15px; margin-bottom: 20px;">
                                        <tbody>
                                                <tr>
                                                        <td><strong>قوانین همبستگی فعال در کش دیتابیس:</strong></td>
                                                        <td><span style="color:#059669; font-weight:bold;"><?php echo number_format_i18n( $total_rules ); ?> رابطه معتبر</span></td>
                                                </tr>
                                                <tr>
                                                        <td><strong>استراتژی موتور پیشنهاددهنده:</strong></td>
                                                        <td>
                                                        <?php
                                                                $modes = array(
                                                                        FWS_Settings::MODE_AUTOMATIC => '۱۰۰٪ خودکار',
                                                                        FWS_Settings::MODE_HYBRID    => 'ترکیبی هوشمند (اولویت با مدیر)',
                                                                        FWS_Settings::MODE_MANUAL    => '۱۰۰٪ دستی (فقط قوانین مدیر)',
                                                                );
                                                                $mode  = isset( $settings['manual_override_mode'] ) ? $settings['manual_override_mode'] : FWS_Settings::MODE_AUTOMATIC;
                                                                echo esc_html( isset( $modes[ $mode ] ) ? $modes[ $mode ] : $mode );
                                                                ?>
                                                                — <?php echo number_format_i18n( count( (array) $settings['manual_rules'] ) ); ?> قانون دستی، <?php echo number_format_i18n( count( (array) $settings['product_blacklist'] ) ); ?> محصول بلک‌لیست</td>
                                                </tr>
                                                <tr>
                                                        <td><strong>حداقل ضریب اطمینان / خرید مشترک:</strong></td>
                                                        <td><?php echo esc_html( number_format_i18n( $settings['min_confidence'] ) ); ?>٪ / <?php echo esc_html( number_format_i18n( $settings['min_support'] ) ); ?> سفارش (بازه <?php echo esc_html( number_format_i18n( $settings['lookback_days'] ) ); ?> روز)</td>
                                                </tr>
                                                <tr>
                                                        <td><strong>تخفیف پکیج / آپسل صفحه تشکر:</strong></td>
                                                        <td><?php echo esc_html( number_format_i18n( $settings['bundle_discount'] ) ); ?>٪ / <?php echo esc_html( number_format_i18n( $settings['upsell_discount'] ) ); ?>٪</td>
                                                </tr>
                                        </tbody>
                                </table>

                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                        <button class="button button-primary" id="fws-recalculate-btn">🔄 تحلیل مجدد دیتابیس سفارشات</button>
                                        <button class="button button-secondary" id="fws-optimize-db-btn">⚡ بهینه‌سازی ایندکس‌ها و پاکسازی کش</button>
                                        <button class="button button-secondary" id="fws-benchmark-btn">🚀 بنچمارک سرعت کوئری</button>
                                        <span id="fws-admin-status" style="font-weight: bold;"></span>
                                </div>
                        </div>

                        <?php $this->render_insights_card(); ?>

                        <?php $this->render_revenue_report_card(); ?>

                        <?php $this->render_ab_testing_card(); ?>

                        <?php $this->render_treemap_card(); ?>
                        <?php $this->render_top_rules_card(); ?>

                        <?php $this->render_settings_card( $settings ); ?>
                </div>
                <?php
        }

        /**
         * کارت هشدارهای هوشمند مدیر (نسخه ۲.۱۰) — داده واقعی، بدون اعداد ساختگی
         */
        private function render_insights_card() {
                $insights = FWS_Insights::get_insights();
                if ( empty( $insights ) ) {
                        return;
                }
                $seen = array();
                $type_labels = array(
                        'info'    => array( 'icon' => 'ℹ️', 'class' => 'fws-insight-info' ),
                        'warn'    => array( 'icon' => '⚠️', 'class' => 'fws-insight-warn' ),
                        'success' => array( 'icon' => '🎉', 'class' => 'fws-insight-success' ),
                        'danger'  => array( 'icon' => '⛔', 'class' => 'fws-insight-warn' ),
                );
                ?>
                <div class="card fws-admin-card fws-insights-card">
                        <h2>🧠 بینش‌های هوشمند — افزونه‌ای که حرف می‌زند</h2>
                        <p class="description">این کارت‌ها فقط از داده واقعی فروشگاه شما ساخته می‌شوند؛ هیچ عدد ساختگی وجود ندارد.</p>
                        <?php foreach ( $insights as $insight ) : ?>
                                <?php
                                $type = isset( $type_labels[ $insight['type'] ] ) ? $insight['type'] : 'info';
                                $meta = $type_labels[ $type ];
                                $dedup = isset( $insight['once'] ) && '' !== $insight['once'] ? $insight['once'] : '';
                                if ( '' !== $dedup ) {
                                        if ( isset( $seen[ $dedup ] ) ) {
                                                continue;
                                        }
                                        $seen[ $dedup ] = true;
                                }
                                ?>
                                <div class="fws-insight-row <?php echo esc_attr( $meta['class'] ); ?>">
                                        <span class="fws-insight-icon"><?php echo esc_html( $meta['icon'] ); ?></span>
                                        <div class="fws-insight-body">
                                                <strong><?php echo esc_html( $insight['title'] ); ?></strong>
                                                <p><?php echo esc_html( $insight['text'] ); ?></p>
                                        </div>
                                </div>
                        <?php endforeach; ?>
                </div>
                <?php
        }

        /**
         * کارت گزارش درآمد و قیف تبدیل ویجت‌ها (نسخه ۲.۱۰)
         */
        private function render_revenue_report_card() {
                $days = isset( $_GET['fws_days'] ) ? absint( $_GET['fws_days'] ) : 30;
                // نسخهٔ ۲.۱۲.۲ (B-43): بازه‌های گزارش هرگز از کلید نگهداری رخدادها بزرگ‌تر نیستند —
                // گزینهٔ «۹۰ روز» وقتی رخدادهای قدیمی‌تر از retention (پیش‌فرض ۶۰) پاک می‌شوند
                // «بی‌صدا ۶۰ روز» نشان می‌داد؛ حالا فقط بازه‌های کامل‌داده ارائه می‌شوند و
                // درخواست بزرگ‌تر با اطلاع شفاف به بزرگ‌ترین بازهٔ کامل clamp می‌شود.
                $fws_retention = (int) FWS_Settings::get( 'tracking_retention_days', 60 );
                $fws_periods   = array();
                foreach ( array( 7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز' ) as $fws_d => $fws_lbl ) {
                        if ( $fws_d <= $fws_retention ) {
                                $fws_periods[ $fws_d ] = $fws_lbl;
                        }
                }
                if ( empty( $fws_periods ) ) {
                        $fws_periods = array( 7 => '۷ روز' );
                }
                $fws_requested = $days;
                if ( ! in_array( $days, array_keys( $fws_periods ), true ) ) {
                        $days = isset( $fws_periods[30] ) ? 30 : (int) min( array_keys( $fws_periods ) );
                }
                $report      = FWS_Tracker::get_report( $days );
                $labels      = FWS_Tracker::widget_labels();
                $tracking_on = FWS_Tracker::tracking_enabled();
                $page_url    = admin_url( 'admin.php?page=fast-woo-predictive' );
                ?>
                <div class="card fws-admin-card" id="fws-revenue-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                <h2 style="margin-bottom:4px;">💰 گزارش درآمد افزونه — «این افزونه چقدر فروخته؟»</h2>
                                <div class="fws-period-switch">
                                        <?php foreach ( $fws_periods as $fws_d => $fws_lbl ) : ?>
                                                <a class="<?php echo $fws_d === $days ? 'is-active' : ''; ?>" href="<?php echo esc_url( $page_url . '&fws_days=' . $fws_d . '#fws-revenue-card' ); ?>"><?php echo esc_html( $fws_lbl ); ?></a>
                                        <?php endforeach; ?>
                                </div>
                        </div>
                        <p class="description">درآمد منسوب = فقط سفارش‌هایی که از ویجت‌های این افزونه شروع شده و به وضعیت «در حال پردازش» یا «تکمیل‌شده» رسیده‌اند. خریدهای ارگانیک (خارج از ویجت‌ها) انتساب داده نمی‌شوند تا عدد واقعی بماند.</p>
                        <?php if ( $fws_requested !== $days ) : ?>
                                <p style="padding:12px 16px; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px;">بازهٔ «<?php echo esc_html( number_format_i18n( $fws_requested ) ); ?> روز» با کلید نگهداری رخدادها (<?php echo esc_html( number_format_i18n( $fws_retention ) ); ?> روز) قابل ارائه نیست؛ رخدادهای قدیمی‌تر خودکار پاک شده‌اند و نمایش آن‌ها بی‌صدا ناقص می‌شد. بازه به «<?php echo esc_html( number_format_i18n( $days ) ); ?> روز» تغییر کرد — برای بازهٔ بلندتر، «مدت نگهداری رخدادها» را در کارت تنظیمات سیستم بالا ببرید.</p>
                        <?php endif; ?>

                        <?php if ( ! $tracking_on ) : ?>
                                <p style="padding:16px; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px;">ردیابی در حال حاضر خاموش است؛ تا روشن نشود هیچ داده‌ای ثبت نمی‌شود. کلید آن در کارت «تنظیمات سیستم» بخش «گزارش و بهینه‌سازی» است.</p>
                        <?php elseif ( empty( $report['widgets'] ) ) : ?>
                                <p style="padding:30px; text-align:center; color:#64748b; background:#f8fafc; border-radius:8px;">هنوز داده‌ای ثبت نشده است. با اولین بازدیدهای فروشگاه، قیف تبدیل همین‌جا شکل می‌گیرد.</p>
                        <?php else : ?>
                                <div class="fws-stat-grid">
                                        <div class="fws-stat-box">
                                                <span class="fws-stat-num"><?php echo number_format_i18n( $report['totals']['impression'] ); ?></span>
                                                <span class="fws-stat-label">نمایش ویجت‌ها</span>
                                        </div>
                                        <div class="fws-stat-box">
                                                <span class="fws-stat-num"><?php echo number_format_i18n( $report['totals']['add_to_cart'] + $report['totals']['coupon'] ); ?></span>
                                                <span class="fws-stat-label">افزودن به سبد / کوپن</span>
                                        </div>
                                        <div class="fws-stat-box">
                                                <span class="fws-stat-num"><?php echo number_format_i18n( $report['totals']['purchase'] ); ?></span>
                                                <span class="fws-stat-label">سفارش منسوب</span>
                                        </div>
                                        <div class="fws-stat-box is-revenue">
                                                <span class="fws-stat-num"><?php echo wp_kses_post( wc_price( $report['totals']['revenue'] ) ); ?></span>
                                                <span class="fws-stat-label">درآمد منسوب در <?php echo esc_html( number_format_i18n( $days ) ); ?> روز</span>
                                        </div>
                                </div>

                                <table class="widefat striped" style="margin-top:16px;">
                                        <thead>
                                                <tr>
                                                        <th>ویجت</th>
                                                        <th>نمایش</th>
                                                        <th>افزودن / کوپن</th>
                                                        <th>سفارش</th>
                                                        <th>درآمد منسوب</th>
                                                        <th>نرخ افزودن</th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( $report['widgets'] as $slug => $w ) : ?>
                                                        <?php
                                                        $wlabel    = isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
                                                        $interact  = $w['add_to_cart'] + $w['coupon'];
                                                        $rate      = $w['impression'] > 0 ? round( 100 * $interact / $w['impression'], 1 ) : 0;
                                                        ?>
                                                        <tr>
                                                                <td><strong><?php echo esc_html( $wlabel ); ?></strong></td>
                                                                <td><?php echo number_format_i18n( $w['impression'] ); ?></td>
                                                                <td><?php echo number_format_i18n( $interact ); ?></td>
                                                                <td><?php echo number_format_i18n( $w['purchase'] ); ?></td>
                                                                <td><?php echo wp_kses_post( wc_price( $w['revenue'] ) ); ?></td>
                                                                <td><?php echo esc_html( number_format_i18n( $rate, 1 ) ); ?>٪</td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                                <p class="description" style="margin-top:6px;">گزارش هر ۵ دقیقه تازه‌سازی می‌شود. نمایش مودال خروج فقط وقتی ثبت می‌شود که واقعاً باز شده باشد (بیکن سبک مرورگر).</p>
                        <?php endif; ?>
                </div>
                <?php
        }

        /**
         * کارت مدیریت A/B تست ویجت‌ها (نسخه ۲.۱۰)
         */
        private function render_ab_testing_card() {
                $tests       = FWS_AB_Testing::get_tests();
                $widgets     = FWS_AB_Testing::testable_widgets();
                $tracking_on = FWS_Tracker::tracking_enabled();
                $status_labels = array(
                        'running'  => array( 'در حال اجرا', '#2563eb', '#eff6ff' ),
                        'winner_a' => array( 'برنده: نسخه A (قفل‌شده)', '#059669', '#ecfdf5' ),
                        'winner_b' => array( 'برنده: نسخه B (قفل‌شده)', '#059669', '#ecfdf5' ),
                        'stopped'  => array( 'متوقف (بدون قفل)', '#64748b', '#f1f5f9' ),
                );
                ?>
                <div class="card fws-admin-card" id="fws-ab-card">
                        <h2>🧪 A/B تست زنده ویجت‌ها</h2>
                        <p class="description">دو نسخه از عنوان/رنگ یک ویجت را روی دو گروه تصادفی و پایدار از بازدیدکنندگان بسنجید. نسخه A همیشه «کنترل» (ظاهر فعلی شما) است و فقط نسخه B تغییر می‌کند. نسخهٔ ۲.۱۲.۱: قفل خودکار برنده پیش‌فرض خاموش است؛ وقتی روشن باشد، پس از معنادار شدن تفاوت برنده به‌صورت خودکار اعلام و برای همه قفل می‌شود و در حالت خاموش خودتان با دکمهٔ «بررسی برنده‌ها» تصمیم می‌گیرید. نسخهٔ ۲.۱۲.۲ (صداقت داده): هر تست فعال سقف عمر دارد (پیش‌فرض ۳۰ روز، با فیلتر <code>fws_ab_max_age_days</code> قابل تغییر و هرگز بیشتر از «مدت نگهداری رخدادها») تا کرون پاکسازی، رخدادهای اولیهٔ تست را نجوید و نتیجه‌گیری روی دادهٔ ناقص رخ ندهد؛ تستِ رسیده به سقف، خودکار «متوقف بدون قفل» می‌شود و آمار پنجرهٔ کاملش محفوظ می‌ماند.</p>

                        <?php if ( ! $tracking_on ) : ?>
                                <p style="padding:16px; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px;">A/B تست برای سنجش برنده به داده ردیابی نیاز دارد؛ ابتدا کلید «ردیابی قیف تبدیل» را روشن کنید.</p>
                        <?php endif; ?>

                        <div class="fws-ab-builder">
                                <div>
                                        <label>ویجت هدف:</label>
                                        <select id="fws-ab-widget">
                                                <?php foreach ( $widgets as $slug => $lbl ) : ?>
                                                        <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $lbl ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                </div>
                                <div>
                                        <label>عنوان نسخه B (جایگزین):</label>
                                        <input type="text" id="fws-ab-title-b" maxlength="120" placeholder="مثال: با این کالا این‌ها را هم دیدند">
                                </div>
                                <div>
                                        <label>عنوان نسخه A (اختیاری — خالی = عنوان فعلی):</label>
                                        <input type="text" id="fws-ab-title-a" maxlength="120" placeholder="خالی بگذارید = کنترل">
                                </div>
                                <div>
                                        <label>رنگ نسخه B (اختیاری):</label>
                                        <input type="color" id="fws-ab-color-b" value="#f97316">
                                </div>
                                <div>
                                        <label>سهم ترافیک نسخه B (٪):</label>
                                        <input type="number" id="fws-ab-split" min="5" max="50" value="30" class="small-text">
                                </div>
                                <div style="align-self:flex-end;">
                                        <button type="button" class="button button-primary" id="fws-ab-create-btn" <?php echo $tracking_on ? '' : 'disabled'; ?>>آغاز تست A/B</button>
                                        <button type="button" class="button" id="fws-ab-check-btn">بررسی برنده‌ها</button>
                                </div>
                        </div>
                        <!-- نسخهٔ ۲.۱۲.۱: قفل خودکار برنده — کارت A/B بیرون از فرم تنظیمات است؛ ذخیره با AJAX (persist_key) -->
                        <p style="margin:12px 0 0;">
                                <label for="fws-ab-auto-conclude">
                                        <input type="checkbox" id="fws-ab-auto-conclude" value="yes" <?php checked( 'yes', FWS_Settings::get( 'ab_auto_conclude', 'no' ) ); ?>>
                                        <strong>قفل خودکار برنده پس از معناداری آماری (کرون روزانه)</strong>
                                </label>
                                <span class="description">— پیش‌فرض خاموش (توصیهٔ بازبینی مستقل): تا وقتی روشن نکنید هیچ برنده‌ای به‌صورت خودکار و بدون آگاهی شما قفل نمی‌شود؛ سنجش معناداری هر شب انجام و فقط گزارش می‌شود. دکمهٔ «بررسی برنده‌ها» در هر حالت با تصمیم صریح شما برنده را قفل می‌کند.</span>
                        </p>
                        <p class="description">در عنوان نسخه B برای بنر جستجو می‌توانید از <code>{query}</code> به‌عنوان جای‌نگهدار عبارت جستجوی کاربر استفاده کنید. نکته (نسخهٔ ۲.۱۲.۱): رنگ نسخهٔ B فقط به ریشهٔ همان ویجتِ تحت تست اعمال می‌شود و ویجت‌های دیگر و بقیهٔ سایت را تغییر نمی‌دهد؛ بنابراین هم‌زمان‌بودن چند تست رنگی روی ویجت‌های متفاوت بی‌خطر است.</p>

                        <?php if ( empty( $tests ) ) : ?>
                                <p class="description" style="margin-top:12px;">هنوز تستی تعریف نشده است. پیشنهاد شروع: عنوان باکس پکیج صفحه محصول یا رنگ دکمه پیشنهادات سبد.</p>
                        <?php else : ?>
                                <table class="widefat striped fws-ab-table" style="margin-top:14px;">
                                        <thead>
                                                <tr>
                                                        <th>ویجت</th>
                                                        <th>وضعیت</th>
                                                        <th>نسخه A — نمایش / افزودن / نرخ</th>
                                                        <th>نسخه B — نمایش / افزودن / نرخ</th>
                                                        <th>تغییر B</th>
                                                        <th></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( $tests as $test ) : ?>
                                                        <?php
                                                        $st     = isset( $status_labels[ $test['status'] ] ) ? $status_labels[ $test['status'] ] : $status_labels['stopped'];
                                                        $stats  = FWS_AB_Testing::test_stats( $test );
                                                        $crA    = $stats['A']['impression'] > 0 ? round( 100 * $stats['A']['add_to_cart'] / $stats['A']['impression'], 1 ) : 0;
                                                        $crB    = $stats['B']['impression'] > 0 ? round( 100 * $stats['B']['add_to_cart'] / $stats['B']['impression'], 1 ) : 0;
                                                        $wlabel = isset( $widgets[ $test['widget'] ] ) ? $widgets[ $test['widget'] ] : $test['widget'];
                                                        $b_desc = trim( ( $test['title_b'] ? 'عنوان: «' . $test['title_b'] . '» ' : '' ) . ( $test['color_b'] ? 'رنگ: ' . $test['color_b'] : '' ) );
                                                        // نسخهٔ ۲.۱۲.۲ (B-43): شفافیت عمر تست و علت توقف خودکار
                                                        $fws_age_days  = (int) floor( ( time() - (int) $test['created'] ) / DAY_IN_SECONDS );
                                                        $fws_max_age   = FWS_AB_Testing::max_age_days();
                                                        $fws_age_badge = '';
                                                        if ( 'running' === $test['status'] ) {
                                                                $fws_age_badge = 'عمر: ' . number_format_i18n( $fws_age_days ) . ' از سقف ' . number_format_i18n( $fws_max_age ) . ' روز';
                                                        } elseif ( isset( $test['stop_reason'] ) && 'max_age' === $test['stop_reason'] ) {
                                                                $fws_age_badge = 'به سقف عمر رسید و خودکار متوقف شد (بدون قفل) — آمار پنجرهٔ کامل محفوظ است';
                                                        }
                                                        ?>
                                                        <tr data-test-id="<?php echo esc_attr( $test['id'] ); ?>">
                                                                <td><strong><?php echo esc_html( $wlabel ); ?></strong><br><small style="color:#64748b;">سهم B: <?php echo esc_html( number_format_i18n( $test['split'] ) ); ?>٪</small><?php if ( '' !== $fws_age_badge ) : ?><br><small style="color:#64748b;"><?php echo esc_html( $fws_age_badge ); ?></small><?php endif; ?></td>
                                                                <td><span class="fws-ab-status" style="color:<?php echo esc_attr( $st[1] ); ?>; background:<?php echo esc_attr( $st[2] ); ?>;"><?php echo esc_html( $st[0] ); ?></span><?php if ( ! empty( $test['p_value'] ) && is_finite( (float) $test['p_value'] ) ) : ?><br><small style="color:#64748b;">p = <?php echo esc_html( number_format_i18n( (float) $test['p_value'], 3 ) ); ?></small><?php endif; ?></td>
                                                                <td><?php echo number_format_i18n( $stats['A']['impression'] ); ?> / <?php echo number_format_i18n( $stats['A']['add_to_cart'] ); ?> / <?php echo esc_html( number_format_i18n( $crA, 1 ) ); ?>٪</td>
                                                                <td><?php echo number_format_i18n( $stats['B']['impression'] ); ?> / <?php echo number_format_i18n( $stats['B']['add_to_cart'] ); ?> / <?php echo esc_html( number_format_i18n( $crB, 1 ) ); ?>٪</td>
                                                                <td><small><?php echo esc_html( $b_desc ); ?></small></td>
                                                                <td class="fws-ab-actions">
                                                                        <?php if ( 'running' === $test['status'] ) : ?>
                                                                                <select class="fws-ab-stop-mode">
                                                                                        <option value="publish_b">انتشار نسخه B</option>
                                                                                        <option value="keep_a">قفل نسخه A</option>
                                                                                        <option value="reset">توقف بدون قفل</option>
                                                                                </select>
                                                                                <button type="button" class="button button-small fws-ab-stop-btn">اعمال</button>
                                                                        <?php elseif ( in_array( $test['status'], array( 'winner_a', 'winner_b' ), true ) ) : ?>
                                                                                <button type="button" class="button button-small fws-ab-release-btn" title="بازگشت ظاهر به حالت عادی">آزاد کردن قفل</button>
                                                                        <?php endif; ?>
                                                                        <button type="button" class="button-link fws-ab-delete-btn" style="color:#b91c1c;">حذف</button>
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                        <?php endif; ?>
                </div>
                <?php
        }

        /** ─────────────── AJAX: مدیریت A/B تست (نسخه ۲.۱۰) ─────────────── */

        public function ajax_ab_create() {
                $this->verify_admin_ajax();
                $widget  = isset( $_POST['widget'] ) ? sanitize_key( wp_unslash( $_POST['widget'] ) ) : '';
                $title_b = isset( $_POST['title_b'] ) ? sanitize_text_field( wp_unslash( $_POST['title_b'] ) ) : '';
                $title_a = isset( $_POST['title_a'] ) ? sanitize_text_field( wp_unslash( $_POST['title_a'] ) ) : '';
                $color_b = isset( $_POST['color_b'] ) ? sanitize_text_field( wp_unslash( $_POST['color_b'] ) ) : '';
                $split   = isset( $_POST['split'] ) ? absint( $_POST['split'] ) : 30;

                $result = FWS_AB_Testing::create_test( $widget, $title_b, $title_a, $color_b, $split );
                if ( ! $result[0] ) {
                        wp_send_json_error( array( 'message' => $result[1] ) );
                }
                FWS_Insights::flush();
                wp_send_json_success( array( 'message' => $result[1] ) );
        }

        public function ajax_ab_stop() {
                $this->verify_admin_ajax();
                $test_id = isset( $_POST['test_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_id'] ) ) : '';
                $keep    = isset( $_POST['keep'] ) ? sanitize_key( wp_unslash( $_POST['keep'] ) ) : 'reset';

                $result = FWS_AB_Testing::stop_test( $test_id, $keep );
                if ( ! $result[0] ) {
                        wp_send_json_error( array( 'message' => $result[1] ) );
                }
                FWS_Insights::flush();
                wp_send_json_success( array( 'message' => $result[1] ) );
        }

        public function ajax_ab_delete() {
                $this->verify_admin_ajax();
                $test_id = isset( $_POST['test_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_id'] ) ) : '';

                $result = FWS_AB_Testing::delete_test( $test_id );
                if ( ! $result[0] ) {
                        wp_send_json_error( array( 'message' => $result[1] ) );
                }
                FWS_Insights::flush();
                wp_send_json_success( array( 'message' => $result[1] ) );
        }

        public function ajax_ab_check() {
                $this->verify_admin_ajax();
                $concluded = FWS_AB_Testing::check_conclusions();
                FWS_Insights::flush();
                if ( empty( $concluded ) ) {
                        wp_send_json_success( array( 'message' => 'بررسی انجام شد؛ فعلاً هیچ تفاوت معناداری (با حداقل ' . FWS_AB_Testing::MIN_IMPRESSIONS . ' نمایش در هر نسخه) ثبت نشده است.' ) );
                }
                $names   = array();
                $widgets = FWS_AB_Testing::testable_widgets();
                foreach ( $concluded as $test ) {
                        $names[] = isset( $widgets[ $test['widget'] ] ) ? $widgets[ $test['widget'] ] : $test['widget'];
                }
                wp_send_json_success( array( 'message' => 'برنده اعلام شد: ' . implode( ' ، ', $names ) . ' — نسخه برنده برای همه قفل شد.' ) );
        }

        /**
         * نسخهٔ ۲.۱۲.۱ — کلید قفل خودکار برندهٔ A/B (کارت A/B بیرون از فرم تنظیمات است؛
         * ذخیره با persist_key در همان مسیر امنِ AJAX ادمین — اعمال sanitizeِ کلیدهای
         * چک‌باکس روی همین مسیر هم اجرا می‌شود).
         */
        public function ajax_ab_auto() {
                $this->verify_admin_ajax();
                $enabled = ( isset( $_POST['enabled'] ) && 'yes' === $_POST['enabled'] ) ? 'yes' : 'no';
                FWS_Settings::persist_key( 'ab_auto_conclude', $enabled );
                FWS_Insights::flush();
                wp_send_json_success( array(
                        'message' => ( 'yes' === $enabled )
                                ? 'قفل خودکار برنده روشن شد؛ از این پس سنجش روزانه در صورت معناداری، برنده را به‌صورت خودکار اعلام و قفل می‌کند.'
                                : 'قفل خودکار برنده خاموش شد؛ اعلام برنده فقط با دکمهٔ «بررسی برنده‌ها» و تصمیم صریح شما انجام می‌شود.',
                ) );
        }

        /**
         * کارت نقشه درختی ارتباطات سبد خرید (Treemap)
         */
        private function render_treemap_card() {
                $treemap_items = FWS_Admin_Analytics::get_treemap_items( 40 );
                $rects         = FWS_Admin_Analytics::layout_treemap( $treemap_items, 1000, 420 );
                ?>
                <div class="card fws-admin-card">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                <h2 style="margin-bottom:4px;">🗂️ نقشه درختی ارتباطات سبد خرید (Market Basket Treemap)</h2>
                                <span class="fws-treemap-count"><?php echo number_format_i18n( count( $treemap_items ) ); ?> ارتباط فعال</span>
                        </div>
                        <p class="description">وسعت هر کاشی متناسب با میزان تکرار خرید همزمان (Frequency) است؛ رنگ هر کاشی نشان‌دهنده دسته‌بندی محصول مبدأ است. ماوس را روی کاشی نگه دارید.</p>

                        <?php if ( empty( $rects ) ) : ?>
                                <p style="padding:30px; text-align:center; color:#64748b; background:#f8fafc; border-radius:8px;">هنوز داده‌ای برای نمایش وجود ندارد. ابتدا «تحلیل مجدد دیتابیس سفارشات» را اجرا کنید.</p>
                        <?php else : ?>
                                <div class="fws-treemap" role="img" aria-label="نقشه درختی ارتباط محصولات و مکمل‌ها">
                                        <?php
                                        foreach ( $rects as $rect ) :
                                                $item        = $rect['item'];
                                                $color       = FWS_Admin_Analytics::category_color( $item['category'] );
                                                $tip         = sprintf( '%s | اطمینان: %.1f٪ | خرید مشترک: %d | Lift: %.2f', $item['label'], $item['confidence'], $item['co'], $item['lift'] );
                                                $left        = ( $rect['x'] / 1000 ) * 100;
                                                $top         = ( $rect['y'] / 420 ) * 100;
                                                $bw          = ( $rect['w'] / 1000 ) * 100;
                                                $bh          = ( $rect['h'] / 420 ) * 100;
                                                $short_label = mb_strlen( $item['label'] ) > 26 ? mb_substr( $item['label'], 0, 24 ) . '…' : $item['label'];
                                                if ( $bw < 6 || $bh < 9 ) {
                                                        continue;
                                                }
                                                ?>
                                                <div class="fws-treemap-tile" style="left:<?php echo esc_attr( $left ); ?>%; top:<?php echo esc_attr( $top ); ?>%; width:<?php echo esc_attr( $bw ); ?>%; height:<?php echo esc_attr( $bh ); ?>%; background:<?php echo esc_attr( $color ); ?>;" title="<?php echo esc_attr( $tip ); ?>">
                                                        <?php if ( $bw >= 12 && $bh >= 18 ) : ?>
                                                                <span class="fws-tile-label"><?php echo esc_html( $short_label ); ?></span>
                                                                <?php if ( $bh >= 26 ) : ?>
                                                                        <span class="fws-tile-sub"><?php echo esc_html( number_format_i18n( $item['co'] ) ); ?> سفارش مشترک · <?php echo esc_html( number_format_i18n( $item['confidence'] ) ); ?>٪</span>
                                                                <?php endif; ?>
                                                        <?php endif; ?>
                                                </div>
                                        <?php endforeach; ?>
                                </div>

                                <?php
                                $legend = array();
                                foreach ( $treemap_items as $item ) {
                                        $legend[ $item['category'] ] = FWS_Admin_Analytics::category_color( $item['category'] );
                                        if ( count( $legend ) >= 10 ) {
                                                break;
                                        }
                                }
                                ?>
                                <div class="fws-treemap-legend">
                                        <?php foreach ( $legend as $cat => $color ) : ?>
                                                <span class="fws-legend-item"><span class="fws-legend-dot" style="background:<?php echo esc_attr( $color ); ?>;"></span><?php echo esc_html( $cat ); ?></span>
                                        <?php endforeach; ?>
                                </div>
                        <?php endif; ?>
                </div>
                <?php
        }

        /**
         * کارت جدول قوانین برتر بر اساس درصد اطمینان
         */
        private function render_top_rules_card() {
                $rules = FWS_Admin_Analytics::get_rules( 20, 'confidence_score' );
                if ( empty( $rules ) ) {
                        return;
                }
                ?>
                <div class="card fws-admin-card">
                        <h2>🏆 قوانین برتر بر اساس درصد اطمینان (Confidence)</h2>
                        <table class="widefat striped" style="margin-top:12px;">
                                <thead>
                                        <tr>
                                                <th>محصول مبدأ</th>
                                                <th>کالای مکمل</th>
                                                <th>دسته مبدأ</th>
                                                <th>اطمینان</th>
                                                <th>خرید مشترک</th>
                                                <th>Lift</th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php foreach ( $rules as $rule ) : ?>
                                                <tr>
                                                        <td><strong><?php echo esc_html( mb_substr( $rule['source'], 0, 40 ) ); ?></strong></td>
                                                        <td><?php echo esc_html( mb_substr( $rule['target'], 0, 40 ) ); ?></td>
                                                        <td><?php echo esc_html( $rule['category'] ); ?></td>
                                                        <td><?php echo esc_html( number_format_i18n( $rule['confidence'], 1 ) ); ?>٪</td>
                                                        <td><?php echo number_format_i18n( $rule['co'] ); ?></td>
                                                        <td><?php echo esc_html( number_format_i18n( $rule['lift'], 2 ) ); ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                </tbody>
                        </table>
                </div>
                <?php
        }

        /**
         * کارت تنظیمات دولایه: بخش ساده همیشه نمایش داده می‌شود؛ تنظیمات تخصصی پشت دکمه بازشو است
         */
        private function render_settings_card( $settings ) {
                $manual_rules  = FWS_Settings::sanitize_rules_list( (array) $settings['manual_rules'] );
                $blacklist_ids = FWS_Settings::sanitize_blacklist_ids( (array) $settings['product_blacklist'] );
                $mode          = isset( $settings['manual_override_mode'] ) ? $settings['manual_override_mode'] : FWS_Settings::MODE_AUTOMATIC;

                // ——— تنظیمات ظاهر و شخصی‌سازی (نسخه ۲.۸) ———
                $style_keys     = array(
                        'style_master_enable',
                        'style_preset',
                        'enable_widget_product',
                        'enable_widget_cart',
                        'enable_widget_thankyou',
                        'enable_widget_shipping',
                        'enable_widget_account',
                        'enable_search_banner',
                        'enable_shortcodes',
                        'accent_color',
                        'accent_text_color',
                        'badge_bg_color',
                        'badge_text_color',
                        'box_bg_color',
                        'box_border_color',
                        'widget_text_color',
                        'inherit_theme_font',
                        'force_rtl',
                        'border_radius',
                        'base_font_size',
                        'hide_confidence_tags',
                        'show_emojis',
                        'custom_css',
                );
                $style_defaults = FWS_Settings::defaults();
                $sty            = array();
                foreach ( $style_keys as $skey ) {
                        $sty[ $skey ] = FWS_Settings::get( $skey, isset( $style_defaults[ $skey ] ) ? $style_defaults[ $skey ] : '' );
                }
                $preset = in_array( $sty['style_preset'], array( FWS_Settings::PRESET_DEFAULT, FWS_Settings::PRESET_MINIMAL, FWS_Settings::PRESET_THEME ), true )
                        ? $sty['style_preset'] : FWS_Settings::PRESET_DEFAULT;
                ?>
                <?php
                // BUG-01 fix (v2.8.1): the settings cards were never wrapped in a <form>, so the
                // "Save settings" button did nothing. Post to options.php via the Settings API;
                // FWS_Settings::sanitize() (registered in register_settings()) cleans the payload
                // and preserves manual rules / blacklist that are managed over AJAX.
                // نسخهٔ ۲.۱۲.۴ (F-16): بدون آرگومان — پیام موفقیت هسته («تنظیمات ذخیره شد.»)
                // با اسلاگ general ثبت می‌شود و فیلترکردن با اسلاگ اختصاصی آن را حذف می‌کرد؛
                // ادمین پس از ذخیره هیچ تأییدی نمی‌دید.
                settings_errors();
                ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" id="fws-settings-form">
                <?php settings_fields( 'fws_settings_group' ); ?>
                <div class="card fws-admin-card" id="fws-style-card">
                        <h2>🎨 ظاهر و شخصی‌سازی — هماهنگی کامل با قالب سایت</h2>
                        <p class="description">همه استایل‌ها، فونت‌ها و رنگ‌های این افزونه از این بخش کنترل می‌شوند؛ می‌توانید هر ویجت را خاموش کنید، کل CSS افزونه را غیرفعال کنید یا رنگ‌ها را دقیقاً با پالت قالب خود تنظیم نمایید. هیچ استایلی اجباری نیست.</p>

                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row">استایل‌های افزونه</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[style_master_enable]" value="yes" <?php checked( $sty['style_master_enable'], 'yes' ); ?>> <strong>بارگذاری CSS افزونه در فرانت‌اند</strong></label>
                                                <p class="description">با برداشتن تیک، هیچ CSS‌ای از افزونه به سایت تزریق نمی‌شود؛ ویجت‌ها فقط با استایل خود قالب شما رندر می‌شوند (فایل استایل پیش‌فرض برای بازنشانی کامل).</p>
                                        </td>
                                </tr>
                        </table>

                        <h3 style="margin-top:6px;">پیش‌تنظیم نمایشی</h3>
                        <div class="fws-mode-grid fws-preset-grid">
                                <label class="fws-mode-card <?php echo FWS_Settings::PRESET_DEFAULT === $preset ? 'is-active' : ''; ?>">
                                        <input type="radio" name="fws_prediction_settings[style_preset]" value="<?php echo esc_attr( FWS_Settings::PRESET_DEFAULT ); ?>" <?php checked( $preset, FWS_Settings::PRESET_DEFAULT ); ?>>
                                        <strong>۱. طراحی پیش‌فرض</strong>
                                        <small>کارت‌های رنگی اختصاصی افزونه (شکل فعلی)</small>
                                </label>
                                <label class="fws-mode-card <?php echo FWS_Settings::PRESET_MINIMAL === $preset ? 'is-active' : ''; ?>">
                                        <input type="radio" name="fws_prediction_settings[style_preset]" value="<?php echo esc_attr( FWS_Settings::PRESET_MINIMAL ); ?>" <?php checked( $preset, FWS_Settings::PRESET_MINIMAL ); ?>>
                                        <strong>۲. مینیمال</strong>
                                        <small>تخت، بی‌سایه و بی‌گرادیان؛ فقط رنگ پالت شما</small>
                                </label>
                                <label class="fws-mode-card <?php echo FWS_Settings::PRESET_THEME === $preset ? 'is-active' : ''; ?>">
                                        <input type="radio" name="fws_prediction_settings[style_preset]" value="<?php echo esc_attr( FWS_Settings::PRESET_THEME ); ?>" <?php checked( $preset, FWS_Settings::PRESET_THEME ); ?>>
                                        <strong>۳. هماهنگ با قالب (پیشنهادی)</strong>
                                        <small>پس‌زمینه، متن و فونت از قالب ارث‌بری می‌شود؛ فقط رنگ تاکی باقی می‌ماند</small>
                                </label>
                        </div>

                        <h3 style="margin-top:22px;">خاموش/روشن هر ویجت (حذف کامل کدهای نمایشی هر بخش)</h3>
                        <table class="form-table" role="presentation">
                                <?php foreach ( FWS_Settings::widget_keys() as $wkey => $wlabel ) : ?>
                                <tr>
                                        <th scope="row"><?php echo esc_html( $wlabel ); ?></th>
                                        <td>
                                                <label class="fws-widget-switch">
                                                        <input type="checkbox" name="fws_prediction_settings[<?php echo esc_attr( $wkey ); ?>]" value="yes" <?php checked( $sty[ $wkey ], 'yes' ); ?>>
                                                        نمایش در سایت
                                                </label>
                                                <?php if ( 'enable_widget_thankyou' === $wkey ) : ?>
                                                        <p class="description" style="color:#8a5a00;">توجه (حسابداری / سامانه مؤدیان): این قابلیت پس از ثبت سفارش، یک قلم کالا به همان سفارش اضافه می‌کند و جمع مبلغ را تغییر می‌دهد؛ اگر فاکتور رسمی صادر می‌کنید یا به سامانه مؤدیان متصل هستید، ابتدا با فرایند مالی خود تطبیق دهید. به همین دلیل برای نصب‌های جدید به‌صورت پیش‌فرض خاموش است.</p>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr>
                                        <th scope="row">مودال خروج (Exit-Intent)</th>
                                        <td><p class="description">کلید این بخش در پایین همین صفحه، در جدول «تنظیمات عمومی» قرار دارد.</p></td>
                                </tr>
                                <tr>
                                        <th scope="row">شورت‌کدها (همهٔ چهار شورت‌کد)</th>
                                        <td>
                                                <label class="fws-widget-switch">
                                                        <input type="checkbox" name="fws_prediction_settings[enable_shortcodes]" value="yes" <?php checked( $sty['enable_shortcodes'], 'yes' ); ?>>
                                                        رندر شورت‌کدها در سایت
                                                </label>
                                                <p class="description">نسخهٔ ۲.۱۱ — قانون طلایی: حتی شورت‌کدها (که انتخاب صریح مدیرند) هم یک کلید خاموش/روشن سراسری دارند. خاموش‌کردن آن، خروجی هر چهار شورت‌کد fws_* را در همهٔ صفحات متوقف می‌کند (پیش‌فرض: روشن تا سایت‌های فعلی بی‌تغییر بمانند).</p>
                                        </td>
                                </tr>
                        </table>

                        <h3 style="margin-top:10px;">پالت رنگ ویجت‌ها</h3>
                        <div class="fws-color-grid">
                                <label class="fws-color-field"><span>رنگ اصلی (دکمه‌ها و تاکیدها)</span><input type="color" name="fws_prediction_settings[accent_color]" value="<?php echo esc_attr( $sty['accent_color'] ); ?>" data-fws-var="--fws-accent"></label>
                                <label class="fws-color-field"><span>متن روی رنگ اصلی</span><input type="color" name="fws_prediction_settings[accent_text_color]" value="<?php echo esc_attr( $sty['accent_text_color'] ); ?>" data-fws-var="--fws-accent-text"></label>
                                <label class="fws-color-field"><span>پس‌زمینه بج‌ها</span><input type="color" name="fws_prediction_settings[badge_bg_color]" value="<?php echo esc_attr( $sty['badge_bg_color'] ); ?>" data-fws-var="--fws-badge-bg"></label>
                                <label class="fws-color-field"><span>متن بج‌ها</span><input type="color" name="fws_prediction_settings[badge_text_color]" value="<?php echo esc_attr( $sty['badge_text_color'] ); ?>" data-fws-var="--fws-badge-text"></label>
                                <label class="fws-color-field"><span>پس‌زمینه کارت‌ها</span><input type="color" name="fws_prediction_settings[box_bg_color]" value="<?php echo esc_attr( $sty['box_bg_color'] ); ?>" data-fws-var="--fws-box-bg"></label>
                                <label class="fws-color-field"><span>حاشیه کارت‌ها</span><input type="color" name="fws_prediction_settings[box_border_color]" value="<?php echo esc_attr( $sty['box_border_color'] ); ?>" data-fws-var="--fws-box-border"></label>
                                <label class="fws-color-field"><span>متن ویجت‌ها (قالب‌های دارک)</span><input type="color" name="fws_prediction_settings[widget_text_color]" value="<?php echo esc_attr( $sty['widget_text_color'] ); ?>" data-fws-var="--fws-text-main"></label>
                        </div>

                        <h3 style="margin-top:22px;">تایپوگرافی و فرم</h3>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row"><label for="fws-border-radius">گردی گوشه‌ها (px)</label></th>
                                        <td>
                                                <input type="number" id="fws-border-radius" name="fws_prediction_settings[border_radius]" value="<?php echo esc_attr( $sty['border_radius'] ); ?>" min="0" max="30" step="1" class="small-text" data-fws-var="--fws-radius" data-fws-suffix="px">
                                                <p class="description">۰ = گوشه‌های کاملاً تیزی که با قالب‌های زاویه‌دار هماهنگ می‌شود.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="fws-base-font-size">اندازه پایه متن ویجت‌ها (px)</label></th>
                                        <td>
                                                <input type="number" id="fws-base-font-size" name="fws_prediction_settings[base_font_size]" value="<?php echo esc_attr( $sty['base_font_size'] ); ?>" min="11" max="18" step="1" class="small-text" data-fws-var="--fws-font-size" data-fws-suffix="px">
                                                <p class="description">کل تایپوگرافی ویجت‌ها نسبت به این اندازه مقیاس می‌شود.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">فونت</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[inherit_theme_font]" value="yes" <?php checked( $sty['inherit_theme_font'], 'yes' ); ?>> استفاده از فونت قالب (هیچ فونتی از افزونه تحمیل نمی‌شود)</label>
                                                <p class="description">این افزونه در هیچ حالتی font-family خودش را تزریق نمی‌کند؛ این گزینه صرفاً برای یادآوری این تعهد است.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">جهت چیدمان</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[force_rtl]" value="yes" <?php checked( $sty['force_rtl'], 'yes' ); ?>> تحمیل جهت راست‌به‌چپ به ویجت‌ها (برای قالب‌های چپ‌چین/دوزبانه خاموش کنید)</label>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">برچسب‌های آماری</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[hide_confidence_tags]" value="yes" <?php checked( $sty['hide_confidence_tags'], 'yes' ); ?>> مخفی‌سازی همه برچسب‌های درصد اطمینان و همبستگی</label>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">ایموجی‌ها</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[show_emojis]" value="yes" <?php checked( $sty['show_emojis'], 'yes' ); ?>> نمایش ایموجی‌ها در متن ویجت‌ها (⚡ 🛍️ 🎉 و…)</label>
                                        </td>
                                </tr>
                        </table>

                        <h3 style="margin-top:10px;">CSS سفارشی</h3>
                        <p class="description">اگر به سلکتور خاصی نیاز دارید، اینجا بنویسید (فقط CSS خالص؛ تگ‌های HTML خودکار حذف می‌شوند). این کد پس از همه استایل‌های افزونه بارگذاری می‌شود.</p>
                        <textarea id="fws-custom-css" name="fws_prediction_settings[custom_css]" rows="7" dir="ltr" class="large-text code" placeholder=".fws-bundle-wrapper { font-size: 15px; } "><?php echo esc_textarea( $sty['custom_css'] ); ?></textarea>
                        <details style="margin-top:8px;">
                                <summary style="cursor:pointer;">فهرست سلکتورهای اصلی افزونه (برای شخصی‌سازی دستی)</summary>
                                <p class="description" dir="ltr" style="text-align:left; line-height:1.9; font-family:monospace;">.fws-bundle-wrapper / .fws-bundle-badge / .fws-bundle-title / .fws-add-bundle-btn<br>.fws-cart-recommendations-wrapper / .fws-cart-item / .fws-quick-add-btn<br>.fws-thankyou-upsell-box / .fws-thankyou-claim-btn<br>.fws-shipping-bar-wrapper / .fws-progress-fill / .fws-filler-card<br>.fws-search-booster-banner / .fws-search-item-card / .fws-search-co-tag<br>.fws-account-prediction-box / .fws-modal-content / .fws-modal-confirm-btn<br>.fws-confidence-tag / .fws-toast<br>متغیرها: --fws-accent , --fws-box-bg , --fws-radius , --fws-font-size , --fws-badge-bg , --fws-text-main , --fws-price-color …</p>
                        </details>

                        <h3 style="margin-top:22px;">👁️ پیش‌نمایش زنده (تغییرات قبل از ذخیره، بلافاصله اعمال می‌شوند)</h3>
                        <div class="fws-style-preview-wrap" id="fws-style-preview-wrap">
                                <div id="fws-style-preview-root" class="fws-preset-<?php echo esc_attr( $preset ); ?>">
                                        <div class="fws-bundle-wrapper" dir="rtl">
                                                <div class="fws-bundle-header">
                                                        <span class="fws-bundle-badge">پیش‌نمایش زنده</span>
                                                        <h3 class="fws-bundle-title">پیشنهادهای هوشمند دیتابیس</h3>
                                                        <p class="fws-bundle-subtitle">این کارت دقیقاً با تنظیمات فعلی فرم شما رندر شده است</p>
                                                </div>
                                                <div class="fws-bundle-items">
                                                        <div class="fws-bundle-item is-primary">
                                                                <span class="fws-item-thumb fws-preview-thumb"></span>
                                                                <div class="fws-item-info">
                                                                        <span class="fws-item-tag">محصول فعلی</span>
                                                                        <strong class="fws-item-name">محصول نمونه فروشگاه شما</strong>
                                                                        <span class="fws-item-price">۱٬۲۰۰٬۰۰۰ تومان</span>
                                                                </div>
                                                        </div>
                                                        <div class="fws-plus-sign">+</div>
                                                        <div class="fws-bundle-item is-recommended">
                                                                <span class="fws-item-thumb fws-preview-thumb is-alt"></span>
                                                                <div class="fws-item-info">
                                                                        <span class="fws-confidence-tag">۹۲٪ سفارشات مشترک</span>
                                                                        <strong class="fws-item-name">مکمل پیشنهادی نمونه</strong>
                                                                        <span class="fws-item-price">۴۵۰٬۰۰۰ تومان</span>
                                                                </div>
                                                        </div>
                                                </div>
                                                <div class="fws-bundle-action-bar">
                                                        <div class="fws-pricing-breakdown">
                                                                <span class="fws-label">قیمت کل پکیج با تخفیف هوشمند (۱۲٪):</span>
                                                                <div class="fws-prices">
                                                                        <del class="fws-original-price">۱٬۶۵۰٬۰۰۰ تومان</del>
                                                                        <strong class="fws-discounted-price">۱٬۴۵۲٬۰۰۰ تومان</strong>
                                                                </div>
                                                        </div>
                                                        <button type="button" class="fws-add-bundle-btn">⚡ افزودن پکیج هوشمند</button>
                                                </div>
                                        </div>
                                </div>
                        </div>
                </div>

                <div class="card fws-admin-card">
                        <h2>⚙️ تنظیمات سیستم</h2>

                        <div class="fws-expert-toggle-row">
                                <div class="fws-expert-toggle-info">
                                        <span class="fws-expert-title">تنظیمات تخصصی و پیشرفته الگوریتم</span>
                                        <span class="fws-expert-hint">در حالت پیش‌فرض همه چیز به‌صورت اتوماتیک و بهینه کار می‌کند؛ برای دسترسی به اوزان ریاضی، متغیرهای تحلیل، استراتژی موتور، قوانین دستی و لیست سیاه کلیک کنید.</span>
                                </div>
                                <button type="button" class="button" id="fws-expert-toggle">
                                        <span class="fws-toggle-text-open">نمایش تنظیمات تخصصی</span>
                                        <span class="fws-toggle-text-close">بستن تنظیمات تخصصی</span> ▾
                                </button>
                        </div>

                        <div class="fws-expert-panel" id="fws-expert-panel" style="display:none;">

                                <h3 style="margin-top:18px;">استراتژی موتور پیشنهاددهنده</h3>
                                <div class="fws-mode-grid">
                                        <label class="fws-mode-card <?php echo FWS_Settings::MODE_AUTOMATIC === $mode ? 'is-active' : ''; ?>">
                                                <input type="radio" name="fws_prediction_settings[manual_override_mode]" value="<?php echo esc_attr( FWS_Settings::MODE_AUTOMATIC ); ?>" <?php checked( $mode, FWS_Settings::MODE_AUTOMATIC ); ?>>
                                                <strong>۱. صددرصد خودکار</strong>
                                                <small>فقط بر اساس داده‌های سفارشات</small>
                                        </label>
                                        <label class="fws-mode-card <?php echo FWS_Settings::MODE_HYBRID === $mode ? 'is-active' : ''; ?>">
                                                <input type="radio" name="fws_prediction_settings[manual_override_mode]" value="<?php echo esc_attr( FWS_Settings::MODE_HYBRID ); ?>" <?php checked( $mode, FWS_Settings::MODE_HYBRID ); ?>>
                                                <strong>۲. ترکیبی هوشمند (پیشنهادی)</strong>
                                                <small>اولویت با قوانین مدیر، سپس تحلیل دیتابیس</small>
                                        </label>
                                        <label class="fws-mode-card <?php echo FWS_Settings::MODE_MANUAL === $mode ? 'is-active' : ''; ?>">
                                                <input type="radio" name="fws_prediction_settings[manual_override_mode]" value="<?php echo esc_attr( FWS_Settings::MODE_MANUAL ); ?>" <?php checked( $mode, FWS_Settings::MODE_MANUAL ); ?>>
                                                <strong>۳. صددرصد دستی</strong>
                                                <small>فقط قوانینی که شخص شما تعریف کرده‌اید</small>
                                        </label>
                                </div>

                                <h3 style="margin-top:22px;">📌 پین کردن مکمل قطعی برای محصول (قوانین دست‌ساز مدیر)</h3>
                                <div class="fws-rule-builder">
                                        <div>
                                                <label>وقتی مشتری در صفحه این محصول است:</label>
                                                <div class="fws-ps" data-key="rule-source">
                                                        <input type="text" class="fws-ps-input" placeholder="جستجوی محصول…" autocomplete="off">
                                                        <input type="hidden" class="fws-ps-id" id="fws-rule-source-id">
                                                        <div class="fws-ps-results"></div>
                                                </div>
                                        </div>
                                        <div>
                                                <label>این محصول را به عنوان مکمل پیشنهاد بده:</label>
                                                <div class="fws-ps" data-key="rule-target">
                                                        <input type="text" class="fws-ps-input" placeholder="جستجوی محصول…" autocomplete="off">
                                                        <input type="hidden" class="fws-ps-id" id="fws-rule-target-id">
                                                        <div class="fws-ps-results"></div>
                                                </div>
                                        </div>
                                        <div>
                                                <label>درصد اطمینان فرضی (اولویت نمایش):</label>
                                                <div style="display:flex; gap:6px;">
                                                        <input type="number" id="fws-rule-confidence" min="50" max="100" value="95" class="small-text" style="width:80px;">
                                                        <button type="button" class="button button-primary" id="fws-add-rule-btn">ثبت قانون پین‌شده</button>
                                                </div>
                                        </div>
                                </div>

                                <?php if ( ! empty( $manual_rules ) ) : ?>
                                        <table class="widefat striped fws-rules-table">
                                                <thead><tr><th>محصول مبدأ</th><th>مکمل پین‌شده</th><th>اولویت</th><th></th></tr></thead>
                                                <tbody>
                                                        <?php foreach ( $manual_rules as $rule ) : ?>
                                                                <?php
                                                                // نسخهٔ ۲.۱۲.۲ (B-45): تیک سبزِ ویجتِ خالیِ بی‌توضیح ممنوع —
                                                                // اگر مقصد فعلاً قابل نمایش نباشد (ناموجود/مخفی/گروهی/متغیر/حذف‌شده)
                                                                // علت شفاف کنار همان قانون نمایش داده می‌شود؛ قانون ذخیره می‌ماند و
                                                                // با تغییر وضعیت محصول در رندرهای بعدی به‌روز می‌شود.
                                                                $fws_rule_warn = $this->manual_rule_target_warning( wc_get_product( $rule['target'] ) );
                                                                ?>
                                                                <tr>
                                                                        <td><?php echo esc_html( get_the_title( $rule['source'] ) ); ?></td>
                                                                        <td><?php echo esc_html( get_the_title( $rule['target'] ) ); ?><?php if ( '' !== $fws_rule_warn ) : ?><br><span style="color:#b45309; font-size:12px;">⚠️ <?php echo esc_html( $fws_rule_warn ); ?></span><?php endif; ?></td>
                                                                        <td><?php echo esc_html( number_format_i18n( $rule['confidence'] ) ); ?>٪</td>
                                                                        <td><button type="button" class="button-link fws-delete-rule" data-source="<?php echo esc_attr( $rule['source'] ); ?>" data-target="<?php echo esc_attr( $rule['target'] ); ?>">حذف</button></td>
                                                                </tr>
                                                        <?php endforeach; ?>
                                                </tbody>
                                        </table>
                                <?php else : ?>
                                        <p class="description" style="margin-top:8px;">هنوز قانون دستی ثبت نشده است. در حالت «ترکیبی» این قوانین بالای پیشنهادات دیتابیس نمایش داده می‌شوند و در حالت «صددرصد دستی» تنها منبع پیشنهاد هستند.</p>
                                <?php endif; ?>

                                <h3 style="margin-top:22px;">🚫 لیست سیاه محصولات (Blacklist)</h3>
                                <p class="description">کالاهایی که هرگز نباید به عنوان مکمل پیشنهاد شوند (در تمام ویجت‌ها و پیشنهادهای سیستم).</p>
                                <div class="fws-rule-builder">
                                        <div>
                                                <label>جستجو و افزودن محصول به لیست سیاه:</label>
                                                <div class="fws-ps" data-key="blacklist">
                                                        <input type="text" class="fws-ps-input" placeholder="جستجوی محصول…" autocomplete="off">
                                                        <input type="hidden" class="fws-ps-id" id="fws-blacklist-id">
                                                        <div class="fws-ps-results"></div>
                                                </div>
                                        </div>
                                        <div style="align-self:flex-end;">
                                                <button type="button" class="button button-secondary" id="fws-add-blacklist-btn">افزودن به لیست سیاه</button>
                                        </div>
                                </div>

                                <?php if ( ! empty( $blacklist_ids ) ) : ?>
                                        <div class="fws-blacklist-chips" style="margin-top:10px;">
                                                <?php foreach ( $blacklist_ids as $bl_id ) : ?>
                                                        <span class="fws-bl-chip">
                                                                <?php echo esc_html( get_the_title( $bl_id ) ); ?>
                                                                <button type="button" class="fws-bl-remove" data-product-id="<?php echo esc_attr( $bl_id ); ?>" title="حذف از لیست سیاه">×</button>
                                                        </span>
                                                <?php endforeach; ?>
                                        </div>
                                <?php endif; ?>

                                <h3 style="margin-top:22px;">اوزان ریاضی و متغیرهای تحلیل</h3>
                                <table class="form-table" role="presentation">
                                        <tr>
                                                <th scope="row"><label for="fws-min-confidence">حداقل ضریب اطمینان (٪)</label></th>
                                                <td>
                                                        <input type="number" id="fws-min-confidence" name="fws_prediction_settings[min_confidence]" value="<?php echo esc_attr( $settings['min_confidence'] ); ?>" min="1" max="100" step="1" class="small-text">
                                                        <p class="description">قوانین با اطمینان پایین‌تر از این مقدار استخراج و نمایش نمی‌شوند (۱ تا ۱۰۰).</p>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><label for="fws-min-support">حداقل خرید مشترک جفت‌کالا</label></th>
                                                <td>
                                                        <input type="number" id="fws-min-support" name="fws_prediction_settings[min_support]" value="<?php echo esc_attr( $settings['min_support'] ); ?>" min="1" max="1000" step="1" class="small-text">
                                                        <p class="description">جفت‌کالاهایی با خرید همزمان کمتر از این تعداد نادیده گرفته می‌شوند (۱ تا ۱۰۰۰).</p>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><label for="fws-lookback-days">بازه تحلیل سفارشات (روز)</label></th>
                                                <td>
                                                        <input type="number" id="fws-lookback-days" name="fws_prediction_settings[lookback_days]" value="<?php echo esc_attr( $settings['lookback_days'] ); ?>" min="7" max="365" step="1" class="small-text">
                                                        <p class="description">فقط سفارشات همین بازه اخیر تحلیل می‌شوند (۷ تا ۳۶۵ روز).</p>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th scope="row"><label for="fws-recs-limit">تعداد پیشنهادات هر ویجت</label></th>
                                                <td>
                                                        <input type="number" id="fws-recs-limit" name="fws_prediction_settings[recs_limit]" value="<?php echo esc_attr( $settings['recs_limit'] ); ?>" min="1" max="6" step="1" class="small-text">
                                                        <p class="description">حداکثر تعداد کالای مکمل در باکس پکیج صفحه محصول (۱ تا ۶).</p>
                                                </td>
                                        </tr>
                                        <tr>
                                                <th scope="row">فال‌بک دسته‌بندی (Cold-Start)</th>
                                                <td>
                                                        <label><input type="checkbox" name="fws_prediction_settings[enable_fallback]" value="yes" <?php checked( $settings['enable_fallback'], 'yes' ); ?>> نمایش پرفروش‌ترین کالای هم‌دسته برای محصولات بدون سابقه</label>
                                                        <p class="description">در حالت فعال، این پیشنهادها با برچسب «پیشنهاد فروشگاه برای شما» (بدون درصد ساختگی) نمایش داده می‌شوند.</p>
                                                </td>
                                        </tr>
                                </table>
                        </div>

                        <h3 style="margin-top:20px;">تخفیف‌ها و فروش (تنظیمات عمومی)</h3>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row"><label for="fws-bundle-discount">تخفیف پکیج هوشمند (٪)</label></th>
                                        <td>
                                                <input type="number" id="fws-bundle-discount" name="fws_prediction_settings[bundle_discount]" value="<?php echo esc_attr( $settings['bundle_discount'] ); ?>" min="0" max="90" step="1" class="small-text">
                                                <p class="description">درصد تخفیفی که با افزودن پکیج پیشنهادی به سبد اعمال می‌شود (۰ تا ۹۰).</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="fws-bundle-qty-limit">سقف تعداد تخفیف‌دار پکیج</label></th>
                                        <td>
                                                <input type="number" id="fws-bundle-qty-limit" name="fws_prediction_settings[bundle_discount_qty_limit]" value="<?php echo esc_attr( $settings['bundle_discount_qty_limit'] ); ?>" min="0" max="99" step="1" class="small-text">
                                                <p class="description">از هر قلم پکیج حداکثر چند عدد با تخفیف محاسبه شود؟ <strong>۰ = همهٔ تعداد</strong> (رفتار پیش‌فرض)، ۱ = فقط یک عدد از هر کالا تخفیف می‌گیرد و بقیه با قیمت عادی حساب می‌شوند. قیمت واحد خط سبد به‌صورت میانگین نمایش داده می‌شود و با تغییر تعداد، خودکار بازمحاسبه می‌شود.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">شیوهٔ اعمال تخفیف پکیج</th>
                                        <td>
                                                <label><input type="radio" name="fws_prediction_settings[bundle_discount_mode]" value="coupon" <?php checked( $settings['bundle_discount_mode'], 'coupon' ); ?>> <strong>کوپن برنامه‌ای (پیش‌فرض، توصیه‌شده)</strong> — تخفیف به‌صورت ردیف مستقل «کوپن» در سبد، تسویه، فاکتور، ایمیل و گزارش‌های فروش دیده می‌شود و حسابداری‌پذیر است</label><br>
                                                <label><input type="radio" name="fws_prediction_settings[bundle_discount_mode]" value="price" <?php checked( $settings['bundle_discount_mode'], 'price' ); ?>> <strong>تخفیف مستقیم قیمت (قدیمی)</strong> — قیمت اقلام پکیج مستقیم کم می‌شود؛ در فاکتور و گزارش‌ها «نامرئی» است و معلوم نمی‌شود چرا این سفارش ارزان‌تر فروخته شده</label>
                                                <p class="description">هر دو شیوه از همان قواعد (مبنای قیمت عادی تازه + سقف تعداد + حداقل ۲ قلم) استفاده می‌کنند؛ تفاوت فقط در «قابل‌مشاهده بودن» است.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="fws-upsell-discount">تخفیف آپسل صفحه تشکر (٪)</label></th>
                                        <td>
                                                <input type="number" id="fws-upsell-discount" name="fws_prediction_settings[upsell_discount]" value="<?php echo esc_attr( $settings['upsell_discount'] ); ?>" min="0" max="90" step="1" class="small-text">
                                                <p class="description">درصد تخفیف پیشنهاد اختصاصی پس از ثبت سفارش (۰ تا ۹۰). شیوهٔ ثبت کالا را گزینهٔ «معماری آپسل» زیر تعیین می‌کند (برای نصب‌های جدید ویجت پیش‌فرض خاموش است).</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">معماری آپسل صفحه تشکر</th>
                                        <td>
                                                <label><input type="radio" name="fws_prediction_settings[upsell_mode]" value="suborder" <?php checked( $settings['upsell_mode'], 'suborder' ); ?>> <strong>سفارش وابستهٔ جداگانه (پیش‌فرض، توصیه‌شده)</strong> — سفارش وابسته‌ای با ارجاع به سفارش اصلی ساخته می‌شود؛ فاکتور/ایمیل/حسابداری سفارش اصلی دست‌نخورده می‌ماند، سفارش وابسته چرخهٔ کامل ایمیل و پرداخت خودش را دارد و برای سفارش‌های پرداخت‌شدهٔ آنلاین هم کار می‌کند</label><br>
                                                <label><input type="radio" name="fws_prediction_settings[upsell_mode]" value="legacy_append" <?php checked( $settings['upsell_mode'], 'legacy_append' ); ?>> <strong>الحاق به همان سفارش (قدیمی)</strong> — قلم به سفارشِ ثبت‌شده اضافه می‌شود؛ ایمیل/فاکتورِ قبلی با مبلغ جدید نمی‌خواند (سازگاری با فاکتور رسمی، پیامک، حسابداری و مؤدیان را خودتان تطبیق دهید)</label>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="fws-shipping-threshold">سقف ارسال رایگان</label></th>
                                        <td>
                                                <input type="number" id="fws-shipping-threshold" name="fws_prediction_settings[free_shipping_threshold]" value="<?php echo esc_attr( $settings['free_shipping_threshold'] ); ?>" min="0" step="1" class="regular-text">
                                                <p class="description">مبلغ سقف ارسال رایگان نوار پیشرفت سبد خرید؛ عدد صفر = غیرفعال. اگر گزینهٔ بعدی روشن باشد، این عدد فقط به‌عنوان پشتیبانِ زمانی که روش ارسال رایگان ووکامرس تنظیم نشده است استفاده می‌شود.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">منبع سقف نوار ارسال رایگان</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[shipping_bar_use_wc_method]" value="yes" <?php checked( $settings['shipping_bar_use_wc_method'], 'yes' ); ?>> سقف واقعی از روش «ارسال رایگان» ووکامرس خوانده شود (توصیه‌شده)</label>
                                                <p class="description">نسخهٔ ۲.۱۲ — با روشن‌بودن، نوار دقیقاً با min_amount زونِ متناظر با آدرس مشتری کار می‌کند (فروشگاه چندمنطقه‌ای دیگر دروغ نمی‌گوید)؛ اگر زون قابل تشخیص نبود، محافظه‌کارانه بزرگ‌ترین سقف ملاک است. خاموش = همیشه همین عدد دستی بالا.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="fws-exit-coupon">کد تخفیف مودال خروج</label></th>
                                        <td>
                                                <input type="text" id="fws-exit-coupon" name="fws_prediction_settings[exit_intent_coupon]" value="<?php echo esc_attr( $settings['exit_intent_coupon'] ); ?>" class="regular-text" placeholder="مثال: WELCOME10">
                                                <p class="description">کد کوپن ووکامرس که با دکمه مودال خروج واقعاً اعمال می‌شود. <strong>خالی بگذارید تا هیچ وعده تخفیفی به مشتری نمایش داده نشود.</strong> کوپن باید از پیش در ووکامرس ساخته شده باشد.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">مودال خروج (Exit-Intent)</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[enable_exit_intent]" value="yes" <?php checked( $settings['enable_exit_intent'], 'yes' ); ?>> نمایش مودال تشویق به تکمیل خرید در سبد/تسویه‌حساب</label>
                                                <p class="description" style="margin-top:6px;"><label><input type="checkbox" name="fws_prediction_settings[exit_intent_mobile]" value="yes" <?php checked( $settings['exit_intent_mobile'], 'yes' ); ?>> فعال‌سازی تریگر موبایل (اسکرول سریع رو به بالا)</label> — تریگر خروجِ موس در موبایل کار نمی‌کند؛ با این کلید، در دستگاه لمسی «اسکرول سریع رو به بالا» مودال را نشان می‌دهد. خاموش = مودال اصلاً روی موبایل رندر نمی‌شود (سرعت بالاتر).</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">تزریق مکمل در نتایج جستجو</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[enable_search_injection]" value="yes" <?php checked( $settings['enable_search_injection'], 'yes' ); ?>> قرار دادن پرفروش‌ترین مکمل در صفحه اول نتایج جستجوی محصولات و بنر بالای نتایج</label>
                                                <p class="description">نتایج جستجوی وبلاگ و صفحات، تحت تأثیر قرار نمی‌گیرند.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="fws-trusted-proxies">پروکسی‌های معتبر (CDN / ریورس‌پروکسی)</label></th>
                                        <td>
                                                <input type="text" id="fws-trusted-proxies" name="fws_prediction_settings[trusted_proxies]" value="<?php echo esc_attr( $settings['trusted_proxies'] ); ?>" dir="ltr" class="regular-text code" placeholder="173.245.48.0/20, 103.21.244.0/22">
                                                <p class="description">نسخهٔ ۲.۱۲.۲ — اگر فروشگاه پشت ابرآروان، کلودفلر یا ریورس‌پروکسی است، بازه‌های IP آن (IP یا CIDR، جداشده با کاما) را ثبت کنید تا سقف‌های امنیتی (کوپن ۵/دقیقه، آپسل ۱۰/دقیقه، پکیج ۲۵/دقیقه، تازه‌سازی nonce ۲۰/دقیقه) به‌ازای IP واقعی هر مشتری اعمال شوند؛ بدون آن همهٔ بازدیدکنندگان پشت CDN یک IP مشترک دارند، سقف‌ها برای «کل فروشگاه» پر می‌شود و مشتری واقعی در کمپین خطای «کمی صبر کنید» می‌گیرد. هدرهای اختصاصی <code dir="ltr">CF-Connecting-IP</code> و <code dir="ltr">Ar-Real-IP</code> هم پشتیبانی می‌شوند. فیلتر توسعه‌دهنده: <code dir="ltr">fws_trusted_proxies</code>. <strong>فقط بازه‌های CDN خودتان را ثبت کنید</strong> — ثبت بازهٔ غلط یعنی اعتماد به هدر جعلی‌پذیر و بی‌اثر شدن سقف‌ها. پیش‌فرض خالی = امن‌ترین حالت (REMOTE_ADDR ملاک).</p>
                                        </td>
                                </tr>
                        </table>

                        <h3 style="margin-top:20px;">📈 گزارش و بهینه‌سازی (نسخه ۲.۱۰)</h3>
                        <p class="description">موتور ردیابی قیف تبدیل و گزارش درآمد. هیچ IP یا داده شخصی خامی ذخیره نمی‌شود؛ فقط رویدادهای شمارشی با هش یک‌طرفه سشن. ردیابی هر ویجت از کلید خاموش/روشن خودِ همان ویجت در بخش «ظاهر و شخصی‌سازی» پیروی می‌کند. برای مهار حجم جدول، هر (سشن، ویجت) در بازهٔ ۶ ساعته حداکثر یک ردیف نمایش ثبت می‌کند.</p>
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th scope="row">ردیابی قیف تبدیل</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[tracking_enable]" value="yes" <?php checked( $settings['tracking_enable'], 'yes' ); ?>> ثبت نمایش/افزودن/خرید هر ویجت و محاسبه درآمد منسوب</label>
                                                <p class="description">با خاموش‌کردن این کلید، هیچ ردیفی ثبت نمی‌شود و گزارش درآمد و A/B تست غیرفعال می‌مانند.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">ناشناس‌سازی IP</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[tracking_anonymize_ip]" value="yes" <?php checked( $settings['tracking_anonymize_ip'], 'yes' ); ?>> حذف انتهای IP قبل از هش (سازگار با حریم خصوصی)</label>
                                                <p class="description">در حالت فعال، آخرین بایت IPv4 (و ۸۰ بیت IPv6) پیش از هش صفر می‌شود؛ تفکیک سشن حفظ می‌شود ولی IP کامل هرگز ذخیره نمی‌شود.</p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row">ثبت بازدید مدیران</th>
                                        <td>
                                                <label><input type="checkbox" name="fws_prediction_settings[tracking_exclude_admins]" value="yes" <?php checked( $settings['tracking_exclude_admins'], 'yes' ); ?>> بازدید و خرید مدیران فروشگاه ثبت نشود (توصیه‌شده)</label>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="fws-tracking-retention">مدت نگهداری رخدادها (روز)</label></th>
                                        <td>
                                                <input type="number" id="fws-tracking-retention" name="fws_prediction_settings[tracking_retention_days]" value="<?php echo esc_attr( $settings['tracking_retention_days'] ); ?>" min="30" max="365" step="1" class="small-text">
                                                <p class="description">رخدادهای قدیمی‌تر به‌صورت خودکار و روزانه پاک می‌شوند (۳۰ تا ۳۶۵ روز). برای هاست اشتراکی بازه ۳۰ تا ۶۰ روز توصیه می‌شود؛ پیش‌فرض از نسخهٔ ۲.۱۰.۱ عدد ۶۰ است. گزارش درآمد فقط بازه‌های کامل‌داده (≤ همین کلید) را ارائه می‌کند و سقف عمر تست فعال A/B هم هرگز از همین مقدار بزرگ‌تر نمی‌شود (رفع B-43).</p>
                                        </td>
                                </tr>
                        </table>

                        <?php submit_button( 'ذخیره تنظیمات' ); ?>
                </div>
                </form>
                <?php
        }
}
