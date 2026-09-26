<?php
/**
 * Class FWS_Bundle_Coupon
 * نسخهٔ ۲.۱۲ (S-02): اعمال تخفیف پکیج با «کوپن برنامه‌ای» به‌جای set_price() نامرئی.
 *
 * مشکل ریشه‌ای: تخفیف پکیج مستقیماً روی قیمت خط سبد ست می‌شد — نه خط‌خورده، نه برچسب،
 * نه ردیف تخفیف. در فاکتور، ایمیل، حسابداری و گزارش‌های فروش، مدیر دیگر نمی‌دانست چرا
 * این سفارش ارزان‌تر فروخته شده است.
 *
 * راه‌حل رسمی ووکامرس: کوپن مجازی با فیلتر رسمی woocommerce_get_shop_coupon_data —
 * بدون رکورد در جدول کوپن‌ها، بدون اثر جانبی، و «قابل‌مشاهده» در سبد، تسویه، سفارش،
 * ایمیل و گزارش تخفیف‌ها. مبلغ کوپن در هر بازمحاسبه از روی سبدِ واقعی و همان ریاضیاتِ
 * حالت قدیمی (مبنای تازه + clamp حراج + سقف تعداد B-03 + قاعدهٔ حداقل‌۲) محاسبه می‌شود.
 *
 * قاعدهٔ طلایی: حالت «کوپن» پیش‌فرض است؛ مدیر می‌تواند با کلید bundle_discount_mode
 * به رفتار قدیمی (price) برگردد — در آن حالت این کلاس عملاً غیرفعال است.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Bundle_Coupon {

        const VIRTUAL_CODE = 'fws_bundle_discount';

        public static function init() {
                add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'virtual_coupon_data' ), 10, 2 );
                add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'sync_after_removal' ), 20, 2 );
                add_action( 'woocommerce_cart_item_restored', array( __CLASS__, 'sync_after_restore' ), 20, 2 );
                // نسخهٔ ۲.۱۲.۳ (R4): جمع‌کردن کوپن مجازیِ به‌جامانده پس از تعویض حالت تخفیف
                add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'tidy_leftover_virtual_coupon' ), 5 );
        }

        /**
         * حالت جاری تخفیف پکیج — «کوپن» پیش‌فرض، «price» رفتار قدیمی
         */
        public static function mode_is_coupon() {
                return 'coupon' === FWS_Settings::get( 'bundle_discount_mode', 'coupon' );
        }

        /**
         * اعمال برنامه‌ای کوپن وقتی قاعدهٔ حداقل‌۲ برقرار است (مسیر افزودن پکیج / بازگردانی Undo)
         */
        public static function apply_if_eligible() {
                if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
                        return;
                }
                if ( ! self::mode_is_coupon() ) {
                        return;
                }
                if ( in_array( self::VIRTUAL_CODE, (array) WC()->cart->get_applied_coupons(), true ) ) {
                        return;
                }
                if ( count( self::bundle_hits() ) < 2 ) {
                        return;
                }
                if ( ! WC()->session->has_session() ) {
                        WC()->session->set_customer_session_cookie( true );
                }
                self::apply_coupon_silently();
        }

        /**
         * نسخهٔ ۲.۱۲.۴ (F-12): اعمال بی‌سروصدای کوپن مجازی بدون نابودی اعلان‌های دیگر.
         * wc_clear_notices() صرف، خطاهای «افزوده‌نشدن بخشی از اقلام پکیج» (صف‌شده توسط
         * add_to_cart) و هر اعلان در انتظار دیگری از سشن را هم پاک می‌کرد و مشتری هرگز
         * نمی‌فهمم کالایی جا افتاده. اکنون فقط اعلان‌های خودِ این اعمال حذف می‌شوند.
         */
        private static function apply_coupon_silently() {
                if ( ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_clear_notices' ) || ! function_exists( 'wc_add_notice' ) ) {
                        WC()->cart->apply_coupon( self::VIRTUAL_CODE );
                        return;
                }
                $before = wc_get_notices();
                WC()->cart->apply_coupon( self::VIRTUAL_CODE );
                $after = wc_get_notices();
                if ( $after !== $before ) {
                        // اعلان‌های تازه‌ی همین اعمال حذف؛ کل اسنپ‌شات قبلی بازسازی می‌شود
                        wc_clear_notices();
                        foreach ( $before as $type => $items ) {
                                foreach ( (array) $items as $notice ) {
                                        $text = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : (string) $notice;
                                        wc_add_notice( $text, $type );
                                }
                        }
                }
        }

        /**
         * حذف کوپن وقتی قاعدهٔ حداقل‌۲ می‌شکند (حذف قلم پکیج از سبد) — بی‌صدا و تمیز
         */
        public static function sync_after_removal() {
                if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                        return;
                }
                if ( ! in_array( self::VIRTUAL_CODE, (array) WC()->cart->get_applied_coupons(), true ) ) {
                        return;
                }
                if ( count( self::bundle_hits() ) >= 2 ) {
                        return;
                }
                WC()->cart->remove_coupon( self::VIRTUAL_CODE );
        }

        /**
         * پس از بازگردانی قلم حذف‌شده (Undo)، کوپن دوباره وصل می‌شود
         */
        public static function sync_after_restore( $cart_item_key, $cart ) {
                if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
                        return;
                }
                if ( ! self::mode_is_coupon() ) {
                        return;
                }
                if ( in_array( self::VIRTUAL_CODE, (array) WC()->cart->get_applied_coupons(), true ) ) {
                        return;
                }
                if ( count( self::bundle_hits() ) < 2 ) {
                        return;
                }
                self::apply_coupon_silently();
        }

        /**
         * تعداد «قلم یکتا»ی پکیج موجود در سبد (والد یا واریاسیون) — همان معیار سرور در fast-woo-sale.php
         */
        private static function bundle_hits() {
                if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
                        return array();
                }
                $bundle_items = (array) WC()->session->get( 'fws_bundle_items', array() );
                if ( empty( $bundle_items ) ) {
                        return array();
                }
                $hits = array();
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                        $pid = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
                        $vid = ( ! empty( $cart_item['variation_id'] ) ) ? absint( $cart_item['variation_id'] ) : 0;
                        if ( in_array( $pid, $bundle_items, true ) ) {
                                $hits[] = $pid;
                        } elseif ( $vid > 0 && in_array( $vid, $bundle_items, true ) ) {
                                $hits[] = $vid;
                        }
                }
                return array_unique( $hits );
        }

        /**
         * نسخهٔ ۲.۱۲.۳ (R4): اگر مدیر حالت تخفیف پکیج را از «کوپن» به «قیمت» تغییر دهد،
         * کوپن مجازیِ متصل به سبدِ مشتریانِ فعال بی‌اعتبار می‌شود (فیلتر دادهٔ کوپن دیگر
         * هیچ داده‌ای نمی‌دهد) و ووکامرس در بارگذاری سبد به مشتری خطای گمراه‌کنندهٔ
         * «کوپن وجود ندارد» می‌دهد — در حالی که مشتری هیچ کد تخفیفی وارد نکرده بود.
         * این جمع‌کننده، کوپنِ به‌جامانده را در نخستین بارگذاری سبد بی‌صدا برمی‌دارد.
         * حالت کوپن و سبد بدون این کوپن، دست‌نخورده عبور می‌کنند.
         * @return void
         */
        public static function tidy_leftover_virtual_coupon() {
                if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                        return;
                }
                if ( self::mode_is_coupon() ) {
                        return;
                }
                if ( ! in_array( self::VIRTUAL_CODE, (array) WC()->cart->get_applied_coupons(), true ) ) {
                        return;
                }
                WC()->cart->remove_coupon( self::VIRTUAL_CODE );
        }

        /**
         * دادهٔ کوپن مجازی — فیلتر رسمی woocommerce_get_shop_coupon_data
         *
         * @param mixed $data false پیش‌فرض.
         * @param string $code کد درخواستی.
         * @return mixed
         */
        public static function virtual_coupon_data( $data, $code ) {
                if ( self::VIRTUAL_CODE !== $code || ! self::mode_is_coupon() ) {
                        return $data;
                }
                $amount = self::compute_discount_total();
                return array(
                        'discount_type'          => 'fixed_cart',
                        'amount'                 => $amount,
                        'individual_use'         => false,
                        'product_ids'            => array(),
                        'excluded_product_ids'   => array(),
                        'usage_limit'            => 0,
                        'usage_limit_per_user'   => 0,
                        'limit_usage_to_x_items' => 0,
                        'expiry_date'            => null,
                        'free_shipping'          => false,
                        'exclude_sale_items'     => false,
                        'minimum_amount'         => 0,
                        'maximum_amount'         => 0,
                        'email_restrictions'     => array(),
                );
        }

        /**
         * محاسبهٔ مبلغ تخفیف پکیج از روی سبد واقعی — آینهٔ ریاضیات حالت price
         * (fast-woo-sale.php: مبنای تازه + clamp حراج B-11 + سقف تعداد B-03 + حداقل‌۲).
     * در دو کلاس تغییرات هم‌سو اعمال شود تا مبلغ کوپن همیشه با حالت price یکی باشد.
         *
         * @return float
         */
        public static function compute_discount_total() {
                if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
                        return 0.0;
                }
                $bundle_items = (array) WC()->session->get( 'fws_bundle_items', array() );
                if ( empty( $bundle_items ) || count( self::bundle_hits() ) < 2 ) {
                        return 0.0;
                }

                $discount_percentage = (int) FWS_Settings::get( 'bundle_discount', defined( 'FWS_BUNDLE_DISCOUNT' ) ? FWS_BUNDLE_DISCOUNT : 12 );
                $pct                 = max( 0, min( 90, $discount_percentage ) ) / 100;
                if ( $pct <= 0 ) {
                        return 0.0;
                }
                $qty_limit = max( 0, min( 99, (int) FWS_Settings::get( 'bundle_discount_qty_limit', 0 ) ) );

                $total_discount = 0.0;
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                        $pid            = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
                        $vid            = ( ! empty( $cart_item['variation_id'] ) ) ? absint( $cart_item['variation_id'] ) : 0;
                        $is_bundle_item = in_array( $pid, $bundle_items, true ) || ( $vid > 0 && in_array( $vid, $bundle_items, true ) );
                        if ( ! $is_bundle_item ) {
                                continue;
                        }

                        $fresh_id      = $vid > 0 ? $vid : $pid;
                        $fresh_product = $fresh_id > 0 ? wc_get_product( $fresh_id ) : null;

                        if ( isset( $cart_item['fws_base_price'] ) && (float) $cart_item['fws_base_price'] > 0 ) {
                                $base_price = (float) $cart_item['fws_base_price'];
                        } else {
                                $base_price = ( $fresh_product instanceof WC_Product ) ? (float) $fresh_product->get_regular_price() : ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? (float) $cart_item['data']->get_regular_price() : 0.0 );
                                if ( $base_price <= 0 ) {
                                        $base_price = ( $fresh_product instanceof WC_Product ) ? (float) $fresh_product->get_price() : ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? (float) $cart_item['data']->get_price() : 0.0 );
                                }
                        }
                        // B-11: مبنای نهایی هرگز از قیمت فعلی فروشگاه گران‌تر نیست.
                        if ( $fresh_product instanceof WC_Product ) {
                                $fresh_price = (float) $fresh_product->get_price();
                                if ( $fresh_price > 0 && $fresh_price < $base_price ) {
                                        $base_price = $fresh_price;
                                }
                        }
                        if ( $base_price <= 0 ) {
                                continue;
                        }

                        $qty = (int) $cart_item['quantity'];
                        if ( $qty <= 0 ) {
                                continue;
                        }
                        $discounted_units = ( $qty_limit > 0 ) ? min( $qty, $qty_limit ) : $qty;
                        $total_discount  += $base_price * $pct * $discounted_units;
                }

                return round( $total_discount, wc_get_price_decimals() );
        }
}
