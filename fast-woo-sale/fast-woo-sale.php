<?php
/**
 * Plugin Name: Fast Woo Predictive Purchase | سیستم هوشمند پیش‌بینی و پیشنهاد خرید ووکامرس
 * Plugin URI:  https://github.com/sangemashhad-sys/befrosh_fast-woocamers-sall
 * Description: موتور تحلیل پیشرفته دیتابیس سفارشات ووکامرس، استخراج سبدهای پرتکرار (Market Basket Analysis)، پیش‌بینی خرید بعدی و ارائه پیشنهادات هوشمند کالا.
 * Version:     2.13.0
 * Author:      تیم توسعه هوش تجاری ووکامرس
 * Author URI:  https://github.com/sangemashhad-sys
 * Text Domain: fast-woo-sale
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.3
 *
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly.
}

// Safe check for WooCommerce dependency (compatible with alphabetical plugin loading & multisite)
function fws_check_woocommerce_active() {
        $active_plugins = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
                $active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }
        return in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) || class_exists( 'WooCommerce' );
}

add_action(
        'admin_notices',
        function () {
                if ( ! fws_check_woocommerce_active() ) {
                        if ( ! current_user_can( 'activate_plugins' ) ) {
                                return;
                        }
                        echo '<div class="notice notice-error is-dismissible"><p><strong>سیستم هوشمند پیش‌بینی خرید (Fast Woo Predictive):</strong> برای اجرای این سیستم، نصب و فعال‌سازی افزونه WooCommerce الزامی است.</p></div>';
                }
        }
);

// Define Constants
// FWS_BUNDLE_DISCOUNT و FWS_MIN_CONFIDENCE برای سازگاری قبلی حفظ شده‌اند؛
// مقادیر واقعی از پنل تنظیمات (FWS_Settings) خوانده می‌شوند.
define( 'FWS_VERSION', '2.13.0' );
define( 'FWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FWS_MIN_CONFIDENCE', 60 );
define( 'FWS_BUNDLE_DISCOUNT', 12 );

// Declare HPOS & Blocks Compatibility (High-Performance Order Storage & Cart/Checkout Blocks)
add_action(
        'before_woocommerce_init',
        function () {
                if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
                        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
                }
        }
);

// Apply bundle discount directly into WooCommerce cart calculations
// نسخه ۲.۷ — رفع باگ تخفیف ترکیبی (Compounding Discount):
// مبنای محاسبه باید همیشه «قیمت پایه» باشد نه قیمت تخفیف‌خورده؛ به‌هرحال مبنای پایه هرگز
// از get_price()ِ شیء داخل سبد خوانده نمی‌شود تا تخفیف روی تخفیف سوار نشود.
// نسخهٔ ۲.۱۱ (B-10): هوک woocommerce_add_cart_item_data حذف شد — فیلد fws_base_price در
// cart_item_data در هش «ادغام خط سبد» وردپرس مشارکت می‌کرد و باعث می‌شد دو افزودنِ همان
// محصول از مسیرهای مختلف، دو خط جدا بسازد (سبد تکراری). مبنای تخفیف حالا در لحظهٔ
// بازمحاسبه از خود محصول خوانده می‌شود (regular تازه + clamp به قیمت حراج تازه — همان
// تضمین B-11). چون مبنای پایه هرگز از get_price()ِ شیء داخل سبد خوانده نمی‌شود (ستِ
// price افزونه روی همان شیء فقط price را عوض می‌کند نه regular)، رفتار ضدتراکمی ۲.۷ سر
// جایش است و سبد دیگر هیچ خط تکراری از مسیرهای افزونه نمی‌سازد.

add_action(
        'woocommerce_before_calculate_totals',
        function ( $cart ) {
                if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                        return;
                }
                if ( ! function_exists( 'WC' ) || ! WC()->session || ! is_a( $cart, 'WC_Cart' ) ) {
                        return;
                }

                // نسخهٔ ۲.۱۲ (S-02): حالت پیش‌فرض تخفیف پکیج «کوپن برنامه‌ای» است
                // (FWS_Bundle_Coupon) — نمایان، حسابرسی‌پذیر و هم‌سو با فاکتور/گزارش‌ها.
                // این مسیر set_price فقط در حالت قدیمی (price) فعال می‌ماند تا
                // تخفیفِ دوباره (کوپن + set_price) هرگز هم‌زمان اتفاق نیفتد.
                if ( FWS_Bundle_Coupon::mode_is_coupon() ) {
                        return;
                }

                $bundle_items = (array) WC()->session->get( 'fws_bundle_items', array() );
                if ( empty( $bundle_items ) ) {
                        return;
                }

                // نسخه ۲.۹ — قاعدهٔ «حداقل ۲ کالا» در لحظهٔ بازمحاسبه هم اعمال می‌شود:
                // قبلاً فقط هنگام افزودن چک می‌شد؛ اگر کاربر همهٔ اقلام پکیج را جز یکی از سبد
                // حذف می‌کرد (و هوک حذف، سشن را به یک آیتم می‌چید)، همان تک‌کالا همچنان
                // تخفیف پکیج می‌گرفت — یعنی تخفیفِ بدونِ پکیج.
                // نسخه ۲.۹.۲ — باگ شمارشِ واریاسیون‌ها: قبلاً همیشه «پدر» (product_id) در لیست
                // برخورد ثبت می‌شد؛ اگر دو واریاسیونِ متفاوت از یک محصولِ والدِ واحد عضو پکیج
                // بودند (سناریوی ممکن از مسیر فیلتر توسعه‌دهنده fws_product_recommendations)،
                // هر دو خط سبد فقط «یک» برخورد حساب می‌شد و قاعدهٔ حداقل‌۲ تخفیف را بی‌صدا
                // قطره‌چکانی می‌بُرد. اکنون شناسهٔ واقعاً برخوردی (والد یا واریاسیون) ثبت می‌شود.
                $cart_bundle_hits = array();
                foreach ( $cart->get_cart() as $cart_item ) {
                        $pid = absint( $cart_item['product_id'] );
                        $vid = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;
                        if ( in_array( $pid, $bundle_items, true ) ) {
                                $cart_bundle_hits[] = $pid;
                        } elseif ( $vid > 0 && in_array( $vid, $bundle_items, true ) ) {
                                $cart_bundle_hits[] = $vid;
                        }
                }
                if ( count( array_unique( $cart_bundle_hits ) ) < 2 ) {
                        return;
                }

                $discount_percentage = (int) FWS_Settings::get( 'bundle_discount', defined( 'FWS_BUNDLE_DISCOUNT' ) ? FWS_BUNDLE_DISCOUNT : 12 );
                $factor              = ( 100 - max( 0, min( 90, $discount_percentage ) ) ) / 100;
                // نسخه ۲.۱۰.۲ (B-03): سقف تعداد تخفیف‌دار از هر قلم — انتخاب خود صاحب فروشگاه.
                // ۰ = تخفیف روی همهٔ تعداد (رفتار قبلی)؛ N = فقط N عدد اولِ هر خط سبد تخفیف می‌گیرد.
                $qty_limit = max( 0, min( 99, (int) FWS_Settings::get( 'bundle_discount_qty_limit', 0 ) ) );

                // نسخهٔ ۲.۱۳ (I-44): منبع قیمت تازه در سطح «درخواست» هوشمند شد. ووکامرس این هوک را
                // در یک درخواست چند بار اجرا می‌کند (calculate_totals، نوار ارسال، نمایش جمع‌ها، …)؛
                // قبلاً هر بار برای هر قلم پکیج یک wc_get_product تازه (نمونه‌سازی کامل WC_Product)
                // می‌ساخت. کَش static فقط «درون همین درخواست» است — از بازی هر درخواست، محصول از
                // دیتابیس تازه خوانده می‌شود؛ پس دلالتِ B-10 (مبنای تازه، بدون انجماد سشن) سر جایش
                // است و فقط نمونه‌سازی تکراری درون‌درخواستی حذف می‌شود.
                static $fws_fresh_cache = array();

                foreach ( $cart->get_cart() as $cart_item ) {
                        $pid            = absint( $cart_item['product_id'] );
                        $vid            = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;
                        $is_bundle_item = in_array( $pid, $bundle_items, true ) || ( $vid > 0 && in_array( $vid, $bundle_items, true ) );

                        if ( $is_bundle_item && isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
                                // نسخهٔ ۲.۱۱ (B-10): مبنای تخفیف دیگر ذخیره نمی‌شود و هر بار از خودِ
                                // محصول (دیتابیس) خوانده می‌شود: regular تازه + clamp به قیمت فعلی تازه.
                                // برای سشن‌های قدیمیِ حامل fws_base_price، همان مقدار مبنای پایه می‌ماند
                                // (سازگاری ارتقا) ولی clampِ زیر همیشه اعمال می‌شود.
                                $fresh_id = $vid > 0 ? $vid : $pid;
                                // نسخهٔ ۲.۱۳ (I-44): اولین ملاقات در درخواست = خواندن تازه؛ بعدی = همان نمونه
                                if ( array_key_exists( $fresh_id, $fws_fresh_cache ) ) {
                                        $fresh_product = $fws_fresh_cache[ $fresh_id ];
                                } else {
                                        $fresh_product                     = $fresh_id > 0 ? wc_get_product( $fresh_id ) : null;
                                        $fws_fresh_cache[ $fresh_id ] = $fresh_product;
                                }

                                if ( isset( $cart_item['fws_base_price'] ) && (float) $cart_item['fws_base_price'] > 0 ) {
                                        $base_price = (float) $cart_item['fws_base_price'];
                                } else {
                                        $base_price = ( $fresh_product instanceof WC_Product ) ? (float) $fresh_product->get_regular_price() : (float) $cart_item['data']->get_regular_price();
                                        if ( $base_price <= 0 ) {
                                                $base_price = ( $fresh_product instanceof WC_Product ) ? (float) $fresh_product->get_price() : (float) $cart_item['data']->get_price();
                                        }
                                }

                                // نسخه ۲.۱۰.۲ (B-11) — همچنان برقرار: مبنای نهایی هرگز از قیمت فعلی
                                // فروشگاه گران‌تر نیست؛ اگر پس از افزودن به سبد حراجی شروع شود، مشتری
                                // باید از قیمت جدید نیز ارزان‌تر یا مساوی آن پرداخت کند.
                                if ( $fresh_product instanceof WC_Product ) {
                                        $fresh_price = (float) $fresh_product->get_price();
                                        if ( $fresh_price > 0 && $fresh_price < $base_price ) {
                                                $base_price = $fresh_price;
                                        }
                                }

                                if ( $base_price > 0 ) {
                                        $qty = (int) $cart_item['quantity'];
                                        if ( $qty <= 0 ) {
                                                continue;
                                        }
                                        if ( $qty_limit > 0 && $qty > $qty_limit ) {
                                                // فقط N عدد اول تخفیف؛ مابقی قیمت کامل — قیمت واحدِ خط، میانگینِ دو بخش است
                                                $blended    = ( ( $base_price * $factor * $qty_limit ) + ( $base_price * ( $qty - $qty_limit ) ) ) / $qty;
                                                $discounted = round( $blended, wc_get_price_decimals() );
                                        } else {
                                                $discounted = round( $base_price * $factor, wc_get_price_decimals() );
                                        }
                                        $cart_item['data']->set_price( $discounted );
                                }
                        }
                }
        },
        20,
        1
);

// Keep bundle session items synchronized if user removes items or empties cart
// (نسخهٔ ۲.۱۱ B-21 / ۲.۱۲.۵ F-25: پشتیبانی صفحهٔ سبدِ بلوکی این‌جا نبود — توضیح تاریخی
// زیر نزد متد inject_into_block_cart در FWS_Display_Hooks ثبت شده است.)
add_action(
        'woocommerce_cart_item_removed',
        function ( $cart_item_key, $cart ) {
                if ( WC()->session ) {
                        $bundle_items = (array) WC()->session->get( 'fws_bundle_items', array() );
                        if ( ! empty( $bundle_items ) ) {
                                $current_cart_pids = array();
                                foreach ( $cart->get_cart() as $item ) {
                                        $current_cart_pids[] = absint( $item['product_id'] );
                                        if ( ! empty( $item['variation_id'] ) ) {
                                                $current_cart_pids[] = absint( $item['variation_id'] );
                                        }
                                }
                                $updated_bundle = array_values( array_intersect( $bundle_items, $current_cart_pids ) );
                                WC()->session->set( 'fws_bundle_items', $updated_bundle );
                        }
                }
                // نسخهٔ ۲.۱۲ (S-02): اگر قاعدهٔ حداقل‌۲ با این حذف شکست، کوپن مجازی پکیج
                // هم باید از سبد برداشته شود (در حالت price این فراخوانی عملاً بی‌اثر است).
                FWS_Bundle_Coupon::sync_after_removal();
        },
        10,
        2
);

// نسخه ۲.۹.۱ — باگ ۴: بازگردانی آیتم حذف‌شده (Undo) در سبد خرید.
// هوک حذف، شناسهٔ قلم را از لیست تخفیف سشن بیرون می‌کشد تا «تک‌کالای یتیم» هرگز تخفیف پکیج نگیرد؛
// اما اگر کاربر بلافاصله Undo بزند، همان قلم به سبد برمی‌گردد و چون از سشن حذف شده بود، تخفیف
// پکیجش بی‌صدا از بین می‌رفت با آنکه هر دو کالای پکیج دوباره در سبد بودند. راه‌حل: مجموعهٔ کامل
// «امضاشده» (fws_bundle_signed — فقط شناسه‌های HMAC-تأییدشده، سقف ۵۰) جداگانه نگهداری می‌شود و
// در لحظهٔ بازگردانی، شناسه‌های معتبر دوباره به لیست تخفیف برمی‌گردند. قاعدهٔ «حداقل ۲ کالا» در
// woocommerce_before_calculate_totals همچنان ضامن صحت است (بدون دو قلمِ واقعی، هیچ تخفیفی اعمال نمی‌شود).
add_action(
        'woocommerce_cart_item_restored',
        function ( $cart_item_key, $cart ) {
                if ( ! function_exists( 'WC' ) || ! WC()->session ) {
                        return;
                }
                $item = $cart->get_cart_item( $cart_item_key );
                if ( empty( $item ) ) {
                        return;
                }
                $signed = (array) WC()->session->get( 'fws_bundle_signed', array() );
                if ( empty( $signed ) ) {
                        return;
                }
                $ids_to_check = array( absint( $item['product_id'] ) );
                if ( ! empty( $item['variation_id'] ) ) {
                        $ids_to_check[] = absint( $item['variation_id'] );
                }
                $items   = (array) WC()->session->get( 'fws_bundle_items', array() );
                $updated = $items;
                foreach ( $ids_to_check as $cid ) {
                        if ( $cid > 0 && in_array( $cid, $signed, true ) && ! in_array( $cid, $updated, true ) ) {
                                $updated[] = $cid;
                        }
                }
                if ( count( $updated ) !== count( $items ) ) {
                        WC()->session->set( 'fws_bundle_items', array_values( $updated ) );
                }
                // نسخهٔ ۲.۱۲ (S-02): پس از بازگردانی، اگر قاعدهٔ حداقل‌۲ دوباره برقرار است،
                // کوپن مجازی پکیج دوباره وصل می‌شود.
                FWS_Bundle_Coupon::apply_if_eligible();
        },
        10,
        2
);

add_action(
        'woocommerce_cart_emptied',
        function () {
                if ( WC()->session ) {
                        WC()->session->__unset( 'fws_bundle_items' );
                        WC()->session->__unset( 'fws_bundle_signed' );
                }
        }
);

// Load Core Modules (Settings must load first — all modules depend on it)
require_once FWS_PLUGIN_DIR . 'includes/class-fws-settings.php';
// Keep the FWS_Settings in-request memo coherent when the option is written by any code path.
add_action( 'update_option_' . FWS_Settings::OPTION_KEY, array( 'FWS_Settings', 'flush_memo' ) );
add_action( 'add_option_' . FWS_Settings::OPTION_KEY, array( 'FWS_Settings', 'flush_memo' ) );
add_action( 'delete_option_' . FWS_Settings::OPTION_KEY, array( 'FWS_Settings', 'flush_memo' ) );
// نسخهٔ ۲.۱۲: لاگر متمرکز (S-06)، کوپن مجازی پکیج (S-02) و حریم خصوصی (S-09)
require_once FWS_PLUGIN_DIR . 'includes/class-fws-logger.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-bundle-coupon.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-privacy.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-style-manager.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-tracker.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-database-miner.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-prediction-engine.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-ab-testing.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-display-hooks.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-ajax-handler.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-insights.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-admin-analytics.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-admin-page.php';
require_once FWS_PLUGIN_DIR . 'includes/class-fws-performance-optimizer.php';

// نسخهٔ ۲.۹.۳ — بی‌اعتبارسازی کش پیشنهادات پس از تغییر دادهٔ کاتالوگ (رفع باگ کشِ کهنهٔ ۲۴ ساعته):
// ردیف‌های کش‌شدهٔ موتور، نام/قیمت/تصویر محصول و حکم «قابل‌پیشنهاد بودن» را در لحظهٔ ساخت
// منجمد می‌کنند. تغییر قیمت، شروع/پایان حراج، صفر شدن موجودی یا مخفی‌شدن محصول تا ۲۴ ساعت
// در ویجت‌ها اعمال نمی‌شد: قیمت نمایشی باکس پکیج با مبلغ واقعیِ سبد نمی‌خواند و دکمهٔ
// افزودن روی کالای ناموجود مرده می‌ماند. دو هوک زیر (هر دو رسمی در ووکامرس ۳.۰+ و
// پوشش‌دهندهٔ واریاسیون‌ها) هر تغییر مرتبط را به پاکسازی کامل کش موتور گره می‌زنند؛
// پاکسازی ارزان است (یک update_option + flush گروه) و فقط با تغییر پراپرتی‌های نمایشی
// رخ می‌دهد، نه با هر به‌روزرسانی محصول.
add_action( 'woocommerce_product_object_updated_props', array( 'FWS_Prediction_Engine', 'on_product_data_changed' ), 10, 2 );
add_action( 'woocommerce_product_set_stock_status', array( 'FWS_Prediction_Engine', 'purge_engine_cache' ), 10, 0 );
add_action( 'woocommerce_variation_set_stock_status', array( 'FWS_Prediction_Engine', 'purge_engine_cache' ), 10, 0 );

// Plugin Activation: Create High-Performance Cache Index Table
// نسخهٔ ۲.۱۳ (I-57): فعال‌سازی شبکه‌ای — قبلاً فقط سایتِ جاری جدول‌ها/کرونش را می‌گرفت و
// بقیهٔ سایت‌های شبکه، افزونه را «فعال» می‌دیدند ولی بدون جدول و بدون کرون می‌ماندند.
// اکنون با $network_wide، روی همهٔ سایت‌های شبکه حلقه می‌زنیم (switch_to_blog/restore).
// نسخهٔ ۲.۱۲.۷ (H-42): ثبت فیلتر cron_schedules داخل خودِ create_tables_and_schedule است
// و در هر switch_to_blog دوباره اجرا می‌شود — امن.
register_activation_hook( __FILE__, 'fws_activate_plugin' );
register_deactivation_hook( __FILE__, array( 'FWS_Database_Miner', 'clear_scheduled_events' ) );

/**
 * نسخهٔ ۲.۱۳ (I-57): راه‌اندازی جدول‌ها و کرون در فعال‌سازی — تک‌سایتی یا شبکه‌ای.
 * جدول رخدادهای ردیاب (FWS_Tracker::maybe_upgrade) هم برای «هر» سایت ساخته می‌شود.
 *
 * @param bool $network_wide آیا فعال‌سازی در سطح شبکه است؟
 */
function fws_activate_plugin( $network_wide = false ) {
        if ( is_multisite() && $network_wide ) {
                $fws_site_ids = get_sites(
                        array(
                                'fields'     => 'ids',
                                'number'     => 0,
                                'network_id' => get_current_network_id(),
                        )
                );
                foreach ( (array) $fws_site_ids as $fws_site_id ) {
                        switch_to_blog( (int) $fws_site_id );
                        FWS_Database_Miner::create_tables_and_schedule();
                        FWS_Tracker::maybe_upgrade();
                        restore_current_blog();
                }
                return;
        }
        FWS_Database_Miner::create_tables_and_schedule();
        FWS_Tracker::maybe_upgrade();
}

// نسخهٔ ۲.۱۰.۱ — زیرساخت ترجمه (i18n):
// رشته‌ها فعلاً فارسی هاردکد هستند (مصرف هدف: فروشگاه فارسی‌زبان) و ترجمهٔ کامل رشته‌به‌رشته
// به نسخهٔ بزرگ‌تر موکول شده تا دیفی امن و بازبینی‌پذیر بماند؛ اما از همین نسخه دامنهٔ متن
// بارگذاری می‌شود تا فایل‌های ترجمهٔ آینده بدون تغییر در کدِ بارگذاری، بلافاصله کار کنند.
add_action(
        'init',
        function () {
                load_plugin_textdomain(
                        'fast-woo-sale',
                        false,
                        dirname( plugin_basename( __FILE__ ) ) . '/languages'
                );
        }
);

// Initialize Engine
add_action(
        'plugins_loaded',
        function () {
                // نسخهٔ ۲.۱۲.۵ (F-38): ثبت Exporter/Eraser حریم خصوصی «پیش از» گیت ووکامرس —
                // جدول fws_events حتی وقتی ووکامرس موقتاً غیرفعال است (عیب‌یابی به‌روزرسانی،
                // تعویض درگاه) باقی می‌ماند و ردیف‌های شناسایی‌پذیر دارد؛ در آن پنجره،
                // درخواست حذف/خروجی دادهٔ GDPR بی‌صدا از این داده‌ها می‌گذشت.
                // FWS_Privacy هیچ وابستگی به ووکامرس ندارد (لاگرش wc_get_logger-گاردشده است).
                FWS_Privacy::init();
                if ( ! class_exists( 'WooCommerce' ) ) {
                        return;
                }
                FWS_Database_Miner::init();
                FWS_Prediction_Engine::get_instance();
                FWS_Style_Manager::get_instance();
                FWS_Display_Hooks::get_instance();
                FWS_Ajax_Handler::get_instance();
                FWS_Performance_Optimizer::init();
                // نسخهٔ ۲.۱۰: موتور ردیابی و A/B تست — در همه مسیرها (خرید در فرانت هم ردیابی می‌شود)
                FWS_Tracker::init();
                FWS_AB_Testing::init();
                if ( is_admin() ) {
                        FWS_Admin_Page::get_instance();
                }
                // نسخهٔ ۲.۱۲ (S-02): کوپن مجازی تخفیف پکیج — فیلتر رسمی woocommerce_get_shop_coupon_data
                FWS_Bundle_Coupon::init();
        }
);
