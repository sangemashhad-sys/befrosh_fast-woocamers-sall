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

                // نسخهٔ ۲.۱۳ (I-51): کدِ داخلی کوپن مجازی (fws_bundle_discount) قبلاً در
                // جدول جمع سبد، صفحهٔ checkout و ایمیل‌های سفارش به مشتری نشان داده می‌شد.
                // اکنون برچسب خوانا و فروشگاهی جایگزین می‌شود (قابل‌بازنویسی با فیلتر).
                add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'customer_friendly_label' ), 10, 2 );
                // نسخهٔ ۲.۱۲.۳ (R4): جمع‌کردن کوپن مجازیِ به‌جامانده پس از تعویض حالت تخفیف
                add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'tidy_leftover_virtual_coupon' ), 5 );
        }

        /**
         * نسخهٔ ۲.۱۳ (I-51): برچسب مشتری‌پسند برای کوپن مجازی پکیج.
         * کد داخلی fws_bundle_discount بی‌معنا و فنی است؛ در جدول جمع سبد/checkout/ایمیل
         * برچسب «تخفیف خرید پکیجی» نمایش داده می‌شود. با فیلتر fws_bundle_coupon_label
         * قابل بازنویسی است.
         *
         * @param string    $label  برچسب پیش‌فرض ووکامرس (کد کوپن)
         * @param WC_Coupon $coupon کوپن
         * @return string
         */
        public static function customer_friendly_label( $label, $coupon ) {
                if ( $coupon instanceof WC_Coupon && self::VIRTUAL_CODE === strtolower( (string) $coupon->get_code() ) ) {
                        $label = apply_filters( 'fws_bundle_coupon_label', 'تخفیف خرید پکیجی', $coupon );
                }
                return $label;
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
                // نسخهٔ ۲.۱۲.۵ (F-43): کد مجازی قابل تایپ دستی است؛ اگر مشتری آن را با کمتر
                // از دو کالای پکیج در سبد وارد کند، کوپنِ ۰٫۰۰ معتبر ساخته و به سبد وصل
                // می‌شد — ردیف «کد تخفیف: … ۰» در سبد/فاکتور/ایمیل بدون هیچ ارزشی.
                // پس فقط برای «اعمالِ جدید» بدون حداقل‌۲ داده نمی‌شود؛ اما اگر کوپن همین
                // حالا روی سبد است (سشن به‌جامانده پس از حذف قلم)، مثل قبل ۰٫۰۰ می‌دهیم
                // تا ووکامرس خطای گمراه‌کنندهٔ «کوپن وجود ندارد» نسازد — همان رفتاری که
                // R4 برای سوییچ حالت تضمین کرده بود. (مبلغ همیشه از compute_discount_total
                // می‌آید که خودش حداقل‌۲ را رعایت می‌کند و صفر برمی‌گرداند.)
                $fws_already_applied = in_array( self::VIRTUAL_CODE, (array) WC()->cart->get_applied_coupons(), true );
                if ( count( self::bundle_hits() ) < 2 && ! $fws_already_applied ) {
                        return $data;
                }

                // نسخهٔ ۲.۱۲.۷ (H-25): کوپن virtual قبلاً fixed_cart با product_ids خالی بود؛
                // مبلغ فقط از محصولات پکیج محاسبه می‌شد ولی ووکامرس تخفیف fixed_cart را بین
                // «همهٔ» اقلام سبد (حتی کالاهای خارج از پکیج) تناسبی پخش می‌کند — جمعِ کل
                // درست می‌ماند ولی در جمع‌های ردیفی سفارش، کالای غیرپکیجی هم تخفیف‌خورده
                // حساب می‌شد (استرداد قلم‌به‌قلم، حسابداری و سهم مالیات کلاس‌های مختلف به‌هم
                // می‌ریخت). اکنون کوپن fixed_product با product_ids محدود به اقلامِ پکیجِ حاضر
                // در سبد ساخته می‌شود؛ مبلغ = تخفیفِ هدف تقسیم بر «تعداد واحدهای مشمول» —
                // جمع کل دقیقاً همان مبلغ قبلی است ولی تخفیف فقط روی محصولات پکیج می‌نشیند.
                // اگر مبلغ واحد از ارزان‌ترین کالای پکیج گران‌تر شود (ووکامرس تخفیف fixed_product
                // را تا سقف قیمت همان کالا می‌کاهد و جمعِ هدف از دست می‌رود) یا ساختار سبد
                // محاسبه‌ناپذیر باشد، محافظه‌کارانه به همان شکل قدیمی fixed_cart سقوط می‌کنیم
                // (جمع کل همیشه درست است). سشنِ به‌جاماندهٔ F-43 هم همان شکل قدیمی را می‌گیرد
                // (product_ids خالی = همیشه معتبر، بدون خطای «کوپن قابل استفاده نیست»).
                $fws_breakdown = self::compute_discount_breakdown();
                $amount        = (float) $fws_breakdown['total'];
                $fws_per_unit  = ( $fws_breakdown['units'] > 0 && count( $fws_breakdown['product_ids'] ) > 0 && $fws_breakdown['min_live_price'] > 0 )
                        ? round( $amount / $fws_breakdown['units'], wc_get_price_decimals() )
                        : 0.0;

                if ( $amount > 0 && $fws_per_unit > 0 && $fws_per_unit <= $fws_breakdown['min_live_price'] ) {
                        return array(
                                'discount_type'          => 'fixed_product',
                                'amount'                 => $fws_per_unit,
                                'individual_use'         => false,
                                'product_ids'            => array_map( 'absint', $fws_breakdown['product_ids'] ),
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

                // سقوط محافظه‌کارانه: شکل قدیمی fixed_cart (جمع کل همیشه دقیق؛ صرفاً توزیع
                // ردیفی بین همهٔ اقلام است) — شامل مبلغ ۰٫۰۰ سشن به‌جاماندهٔ F-43.
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
                $fws_breakdown = self::compute_discount_breakdown();
                return (float) $fws_breakdown['total'];
        }

        /**
         * نسخهٔ ۲.۱۲.۷ (H-25): همان ریاضیاتِ compute_discount_total به‌همراه داده‌های
         * تکمیلی برای ساخت کوپن fixed_product — تعداد واحدهای مشمول تخفیف، شناسه‌های
         * (والد + واریشن) اقلام پکیجِ حاضر در سبد و ارزان‌ترین قیمت زندهٔ واحدها
         * (گارد سقوط: اگر مبلغ واحدِ تخفیف از آن گران‌تر شود، fixed_product جمعِ هدف
         * را کامل تحویل نمی‌دهد).
         *
         * @return array{total:float, units:int, product_ids:int[], min_live_price:float}
         */
        public static function compute_discount_breakdown() {
                $fws_empty = array(
                        'total'          => 0.0,
                        'units'          => 0,
                        'product_ids'    => array(),
                        'min_live_price' => 0.0,
                );
                if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
                        return $fws_empty;
                }
                $bundle_items = (array) WC()->session->get( 'fws_bundle_items', array() );
                if ( empty( $bundle_items ) || count( self::bundle_hits() ) < 2 ) {
                        return $fws_empty;
                }

                $discount_percentage = (int) FWS_Settings::get( 'bundle_discount', defined( 'FWS_BUNDLE_DISCOUNT' ) ? FWS_BUNDLE_DISCOUNT : 12 );
                $pct                 = max( 0, min( 90, $discount_percentage ) ) / 100;
                if ( $pct <= 0 ) {
                        return $fws_empty;
                }
                $qty_limit = max( 0, min( 99, (int) FWS_Settings::get( 'bundle_discount_qty_limit', 0 ) ) );

                $total_discount = 0.0;
                $total_units    = 0;
                $item_ids       = array();
                $min_live_price = 0.0;
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
                        $total_units     += $discounted_units;
                        if ( $pid > 0 ) {
                                $item_ids[] = $pid;
                        }
                        if ( $vid > 0 ) {
                                $item_ids[] = $vid;
                        }
                        if ( $fresh_product instanceof WC_Product ) {
                                $fws_live = (float) $fresh_product->get_price();
                                if ( $fws_live > 0 && ( 0.0 === $min_live_price || $fws_live < $min_live_price ) ) {
                                        $min_live_price = $fws_live;
                                }
                        }
                }

                return array(
                        'total'          => round( $total_discount, wc_get_price_decimals() ),
                        'units'          => $total_units,
                        'product_ids'    => array_values( array_unique( array_map( 'absint', $item_ids ) ) ),
                        'min_live_price' => $min_live_price,
                );
        }
}
