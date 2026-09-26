<?php
/**
 * Class FWS_Ajax_Handler
 * پردازشگر امن و فوق‌سریع AJAX (حفاظت صددرصدی در برابر IDOR، اعتبارسنجی نانس، محافظت از انبار و ثبت تخفیف‌های هوشمند)
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Ajax_Handler {

        private static $instance = null;

        public static function get_instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        public function __construct() {
                add_action( 'wp_ajax_fws_add_bundle', array( $this, 'add_bundle_to_cart' ) );
                add_action( 'wp_ajax_nopriv_fws_add_bundle', array( $this, 'add_bundle_to_cart' ) );

                add_action( 'wp_ajax_fws_add_single', array( $this, 'add_single_to_cart' ) );
                add_action( 'wp_ajax_nopriv_fws_add_single', array( $this, 'add_single_to_cart' ) );

                add_action( 'wp_ajax_fws_thankyou_upsell', array( $this, 'process_thankyou_upsell' ) );
                add_action( 'wp_ajax_nopriv_fws_thankyou_upsell', array( $this, 'process_thankyou_upsell' ) );

                // اعمال کد تخفیف مودال خروج — تنها کد پیکربندی‌شده در پنل ادمین اعمال می‌شود
                add_action( 'wp_ajax_fws_apply_exit_coupon', array( $this, 'apply_exit_coupon' ) );
                add_action( 'wp_ajax_nopriv_fws_apply_exit_coupon', array( $this, 'apply_exit_coupon' ) );

                // BUG-10 fix (v2.8.1): nonce refresh for pages served from a full-page cache.
                add_action( 'wp_ajax_fws_refresh_nonce', array( $this, 'refresh_nonce' ) );
                add_action( 'wp_ajax_nopriv_fws_refresh_nonce', array( $this, 'refresh_nonce' ) );

                // نسخه ۲.۱۰: بیکن سبک ثبت نمایش واقعی مودال خروج از سمت مرورگر
                add_action( 'wp_ajax_fws_track_event', array( $this, 'track_event' ) );
                add_action( 'wp_ajax_nopriv_fws_track_event', array( $this, 'track_event' ) );

                add_action( 'wp_ajax_fws_recalculate_rules', array( $this, 'recalculate_rules' ) );
                add_action( 'wp_ajax_fws_optimize_database', array( $this, 'optimize_database' ) );
        }

        /**
         * شناسایی IP واقعی کاربر — نسخه ۲.۷: مقاوم در برابر جعل هدر
         * نسخه ۲.۱۲.۲ (B-41): پشتیبانی کامل فروشگاه پشت CDN (ابرآروان/کلودفلر و…)
         *
         * مشکل: پیش‌فرضِ «لیست پروکسی خالی» یعنی روی CDN همهٔ بازدیدکننده‌ها یک REMOTE_ADDR
         * (آی‌پی لبهٔ CDN) دارند و همهٔ سقف‌های نرخ (کوپن ۵/دقیقه، آپسل ۱۰/دقیقه، پکیج
         * ۲۵/دقیقه، nonce ۲۰/دقیقه) برای «کل فروشگاه» یکی می‌شد؛ مشتری واقعی در کمپین
         * خطای «کمی صبر کنید» می‌گرفت. حالا فهرست پروکسی‌های معتبر علاوه بر فیلتر
         * `fws_trusted_proxies` از فیلد پنل («پروکسی‌های معتبر») هم خوانده می‌شود.
         *
         * امنیت (درس نسخهٔ ۲.۷ — دور زدن سقف با XFF جعلی): به هدرهای پروکسی فقط وقتی
         * اعتماد می‌شود که REMOTE_ADDR خودش پروکسی معتبرِ ثبت‌شده باشد؛ در آن حالت
         * «راست‌ترین» IPِ غیرمعتبرِ زنجیرهٔ XFF (و هدر اختصاصی CF-Connecting-IP /
         * Ar-Real-IP) ملاک است، نه اولین IP از چپ (که توسط کلاینت قابل جعل است).
         * استثنای امن: REMOTE_ADDR خصوصی/لوکال (ریورس‌پروکسی محلی مثل Nginx روی همان
         * هاست) هم زنجیره را لو می‌دهد — کلاینتِ بیرونی هرگز نمی‌تواند مستقیم با IP
         * خصوصی متصل شود.
         *
         * نسخهٔ ۲.۱۲.۳ (R1 — تنگ‌ترکردن گارد نسخهٔ ۲.۱۲.۲): هدرهای اختصاصی لبهٔ CDN
         * (CF-Connecting-IP / Ar-Real-IP) فقط وقتی پذیرفته می‌شوند که REMOTE_ADDR خودِ
         * درخواست «پروکسی معتبرِ ثبت‌شدهٔ عمومی» باشد؛ در حالت ریورس‌پروکسی محلی
         * (REMOTE_ADDR خصوصی) فقط پیمایش X-Forwarded-For انجام می‌شود. دلیل: Nginx
         * هدرهای ناشناخته را به‌طور پیش‌فرض حذف نمی‌کند و کلاینتِ بیرونی می‌توانست
         * CF-Connecting-IP جعلی را از میان پروکسی محلی عبور دهد و با هر درخواست یک
         * IP تازه جعل کند تا سقف نرخ را کلاً دور بزند — در حالی که الگوی استاندارد
         * «proxy_add_x_forwarded_for»، IP واقعی کلاینت را راست‌ترین عضو XFF می‌گذارد
         * و همان با پیمایش راست‌به‌چپ به‌درستی استخراج می‌شود.
         * نسخهٔ ۲.۱۲.۴ (F-09): عمومی و استاتیک — منبع واحد تشخیص IP واقعی مشتری
         * (B-41/R1) برای rate-limit و هش سشن tracker (رفع فروپاشی هویت پشت CDN).
         * @return string
         */
        public static function get_client_ip() {
                $remote = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
                if ( '' === $remote ) {
                        return '0.0.0.0';
                }

                $trusted = (array) apply_filters( 'fws_trusted_proxies', array() );
                // نسخهٔ ۲.۱۲.۲ (B-41): فهرست پنل — IP یا CIDR جداشده با کاما/فاصله/خط جدید
                $setting = (string) FWS_Settings::get( 'trusted_proxies', '' );
                if ( '' !== $setting ) {
                        $trusted = array_merge( $trusted, preg_split( '/[\s,;]+/', $setting ) );
                }
                $trusted = array_filter( array_map( 'trim', array_map( 'strval', $trusted ) ) );

                $is_trusted_proxy = ( ! empty( $trusted ) && $this->ip_matches_ranges( $remote, $trusted ) );
                $is_local_proxy   = $this->is_private_ip( $remote );

                if ( $is_trusted_proxy || $is_local_proxy ) {
                        // نسخهٔ ۲.۱۲.۳ (R1): هدرهای لبهٔ CDN فقط با پروکسی معتبرِ «عمومی»
                        $real = $this->client_ip_from_proxy_headers( $trusted, ( $is_trusted_proxy && ! $is_local_proxy ) );
                        if ( '' !== $real ) {
                                return $real;
                        }
                }
                return $remote;
        }

        /**
         * نسخهٔ ۲.۱۲.۲ (B-41): استخراج IP مشتری از هدرهای پروکسی — فقط زمانی فراخوانی
         * می‌شود که REMOTE_ADDR پروکسی معتبر/لوکال باشد.
         * ترتیب: هدر اختصاصی لبهٔ CDN (که CDN بازنویسی‌اش می‌کند و قابل جعل نیست) — فقط
         * وقتی پارامتر دوم true باشد یعنی اتصال‌دهندهٔ مستقیم، پروکسی معتبرِ «عمومی»
         * ثبت‌شده است — سپس پیمایش X-Forwarded-For از راست به چپ با پرش از IPهای خودِ
         * پروکسی‌های معتبر (زنجیره‌های چندلایه) — نه اولین IP از چپ که کلاینت می‌سازد.
         * @param array $trusted فهرست IP/CIDR پروکسی‌های معتبر
         * @param bool  $allow_edge_headers اجازهٔ پذیرش هدرهای اختصاصی CDN
         * @return string IP معتبر یا رشتهٔ خالی (یعنی هدر قابل‌اعتمادی نبود)
         */
        private function client_ip_from_proxy_headers( $trusted, $allow_edge_headers = true ) {
                $candidates = array();
                if ( $allow_edge_headers && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) { // Cloudflare
                        $candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
                }
                if ( $allow_edge_headers && ! empty( $_SERVER['HTTP_AR_REAL_IP'] ) ) { // ابرآروان
                        $candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AR_REAL_IP'] ) );
                }
                if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                        $chain = preg_split( '/[\s,]+/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
                        foreach ( array_reverse( (array) $chain ) as $hop ) { // راست‌ترین اول
                                $candidates[] = $hop;
                        }
                }

                foreach ( $candidates as $ip ) {
                        $ip = trim( (string) $ip );
                        if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                                continue;
                        }
                        // عضو خود زنجیرهٔ پروکسی (لایهٔ دیگر CDN) مشتری نیست؛ رد شود
                        if ( ! empty( $trusted ) && $this->ip_matches_ranges( $ip, $trusted ) ) {
                                continue;
                        }
                        return $ip;
                }
                return '';
        }

        /**
         * نسخهٔ ۲.۱۲.۲ (B-41): آیا IP در بازهٔ خصوصی/رزرو (RFC1918، loopback، CGNAT و…)
         * است؟ REMOTE_ADDR خصوصی یعنی درخواست از یک ریورس‌پروکسی محلی رسیده است.
         * @param string $ip
         * @return bool
         */
        private function is_private_ip( $ip ) {
                if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                        return false;
                }
                return ! (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        }

        /**
         * تطبیق IP با لیست IP/CIDR (پروکسی‌های معتبر)
         */
        private function ip_matches_ranges( $ip, $ranges ) {
                foreach ( $ranges as $range ) {
                        $range = trim( (string) $range );
                        if ( '' === $range ) {
                                continue;
                        }
                        if ( false !== strpos( $range, '/' ) ) {
                                if ( $this->ip_in_cidr( $ip, $range ) ) {
                                        return true;
                                }
                        } elseif ( $ip === $range ) {
                                return true;
                        }
                }
                return false;
        }

        /**
         * بررسی عضویت IP در یک بازه CIDR (پشتیبانی IPv4 و IPv6)
         */
        private function ip_in_cidr( $ip, $cidr ) {
                list($subnet, $bits) = array_pad( explode( '/', $cidr, 2 ), 2, null );
                $ip_bin              = @inet_pton( $ip );
                $subnet_bin          = @inet_pton( $subnet );
                if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
                        return false;
                }
                $max  = strlen( $ip_bin ) * 8;
                $bits = ( null === $bits ) ? $max : (int) $bits;
                if ( $bits < 0 || $bits > $max ) {
                        return false;
                }

                $bytes = (int) floor( $bits / 8 );
                $rem   = $bits % 8;
                if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
                        return false;
                }
                if ( $rem > 0 ) {
                        $mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;
                        if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $subnet_bin[ $bytes ] ) & $mask ) ) {
                                return false;
                        }
                }
                return true;
        }

        /**
         * بررسی سقف نرخ مجاز درخواست‌ها (Rate Limiting) جهت حفاظت در برابر ربات‌ها، حملات DoS و Cart Stuffing
         */
        private function check_rate_limit( $action = 'cart_action', $limit = 30, $window = 60 ) {
                $ip  = self::get_client_ip();
                $key = 'fws_rate_' . md5( $action . '_' . $ip );

                // BUG-13 fix (v2.8.1) + completion (v2.9.0): the old get_transient()/set_transient()
                // pair was a read-then-write race, so N parallel requests all saw the same counter
                // and all passed.
                //
                //  - Object cache present: atomic wp_cache_add() + wp_cache_incr(). If the key
                //    expires between add() and incr(), Redis previously created an orphan key
                //    WITHOUT a TTL that lived forever; we now retry add() to restore the TTL.
                //  - No object cache (most shared hosts): a single atomic INSERT ... ON DUPLICATE
                //    KEY UPDATE against wp_options (UNIQUE index on option_name) — the counter can
                //    never go backwards and parallel requests are correctly limited. The bucket
                //    value embeds its own expiry (count:unix_ts) and stale buckets are garbage-
                //    collected opportunistically (~2% of calls).
                $bucket_key = $key . '_' . (int) floor( time() / max( 1, $window ) );
                if ( wp_using_ext_object_cache() ) {
                        $added = wp_cache_add( $bucket_key, 1, 'fws_rate', $window );
                        if ( $added ) {
                                $attempts = 1;
                        } else {
                                $incr = wp_cache_incr( $bucket_key, 1, 'fws_rate' );
                                if ( false === $incr ) {
                                        // Key expired between add() and incr() — restore it with a TTL.
                                        $added    = wp_cache_add( $bucket_key, 1, 'fws_rate', $window );
                                        $attempts = $added ? 1 : (int) wp_cache_incr( $bucket_key, 1, 'fws_rate' );
                                } else {
                                        $attempts = (int) $incr;
                                }
                        }
                } else {
                        global $wpdb;
                        $bucket_value = '1:' . ( time() + max( 1, $window ) );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic rate limiting primitive
                        $wpdb->query(
                                $wpdb->prepare(
                                        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
                                        ON DUPLICATE KEY UPDATE option_value = CONCAT( CAST( SUBSTRING_INDEX( option_value, ':', 1 ) AS UNSIGNED ) + 1, ':', SUBSTRING_INDEX( option_value, ':', -1 ) )",
                                        $bucket_key,
                                        $bucket_value
                                )
                        );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $stored = $wpdb->get_var(
                                $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $bucket_key )
                        );
                        $attempts = 1;
                        if ( null !== $stored ) {
                                $parts    = explode( ':', (string) $stored );
                                $attempts = (int) $parts[0];
                        }
                        // Opportunistic GC: remove expired buckets so wp_options stays lean.
                        // NOTE (باگ ۱۰ نسخهٔ ۲.۹): الگوی LIKE هرگز نباید به‌صورت literal داخل prepare
                        // نوشته شود — «%_» در PHP 8 داخل vsprintf خطای Unknown format specifier
                        // (ValueError) می‌دهد؛ الگو باید آرگومانِ %s باشد.
                        if ( 0 === mt_rand( 0, 49 ) ) {
                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                                $wpdb->query(
                                        $wpdb->prepare(
                                                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST( SUBSTRING_INDEX( option_value, ':', -1 ) AS UNSIGNED ) < %d",
                                                $wpdb->esc_like( 'fws_rate_' ) . '%',
                                                time()
                                        )
                                );
                        }
                }
                if ( $attempts > $limit ) {
                        wp_send_json_error( array( 'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً کمی صبر کرده و سپس تلاش نمایید.' ) );
                }
        }

        /**
         * نسخهٔ ۲.۱۱ (B-10): یافتن خط سبدِ «ساده» یک محصول (بدون واریاسیون) برای ادغام
         * به‌جای خط تکراری.
         * @param int $pid
         * @return string کلید خط سبد یا خالی
         */
        private function find_simple_cart_line( $pid ) {
                if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                        return '';
                }
                foreach ( WC()->cart->get_cart() as $key => $item ) {
                        if ( absint( $item['product_id'] ) === absint( $pid ) && empty( $item['variation_id'] ) ) {
                                return (string) $key;
                        }
                }
                return '';
        }

        /**
         * اعمال کد تخفیف مودال خروج به سبد خرید
         * امنیت: تنها کد تخفیفی که ادمین در پنل تنظیمات ثبت کرده اعمال می‌شود؛
         * ورودی کاربر هرگز به‌عنوان کد تخفیف مورد اعتماد قرار نمی‌گیرد.
         */
        public function apply_exit_coupon() {
                check_ajax_referer( 'fws_prediction_nonce', 'nonce' );
                $this->check_rate_limit( 'apply_coupon', 5, 60 );

                $configured_coupon = trim( (string) FWS_Settings::get( 'exit_intent_coupon', '' ) );
                if ( '' === $configured_coupon ) {
                        wp_send_json_error( array( 'message' => 'در حال حاضر کد تخفیف ویژه‌ای تعریف نشده است.' ) );
                }

                // نسخهٔ ۲.۱۱ (B-30): پیش‌اعتبارسنجی کوپن — قبلاً هر رشتهٔ پیکربندی‌شده مستقیم
                // به apply_coupon می‌رسید و پیام‌های خطای عمومی/گمراه‌کننده می‌ساخت. حالا:
                // وجود کوپن، انقضا، محدودیت استفاده، هم‌کار بودن با کوپن‌های فعلی
                // (individual_use) و اعمال‌شده‌بودن قبلی، با پیام صریح چک می‌شود.
                $coupon_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $configured_coupon ) : 0;
                if ( ! $coupon_id ) {
                        wp_send_json_error( array( 'message' => 'کد تخفیف پیکربندی‌شده در فروشگاه یافت نشد؛ با پشتیبانی فروشگاه تماس بگیرید.' ) );
                }
                $coupon = new WC_Coupon( $coupon_id );
                if ( $coupon->get_status() && 'publish' !== $coupon->get_status() ) {
                        wp_send_json_error( array( 'message' => 'این کد تخفیف در حال حاضر فعال نیست.' ) );
                }
                $date_expires = $coupon->get_date_expires();
                if ( $date_expires && $date_expires->getTimestamp() + DAY_IN_SECONDS < time() ) {
                        wp_send_json_error( array( 'message' => 'مهلت استفاده از این کد تخفیف به پایان رسیده است.' ) );
                }
                if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
                        wp_send_json_error( array( 'message' => 'ظرفیت استفاده از این کد تخفیف تکمیل شده است.' ) );
                }

                if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                        wp_send_json_error( array( 'message' => 'سبد خرید در دسترس نیست. لطفاً صفحه را تازه‌سازی کنید.' ) );
                }

                // اگر همین کوپن قبلاً روی سبد اعمال شده، دوباره تلاش نکن؛ مستقیم به تسویه برو
                if ( in_array( $configured_coupon, (array) WC()->cart->get_applied_coupons(), true ) ) {
                        wp_send_json_success(
                                array(
                                        'message'      => 'کد تخفیف از قبل روی سبد شما فعال است.',
                                        'checkout_url' => wc_get_checkout_url(),
                                )
                        );
                }
                // individual_use: اگر کوپن‌های دیگری روی سبد است و این کوپن انحصاری است،
                // پیشاپیش با پیام شفاف رد می‌شود (در غیر این صورت WC یکی را بی‌صدا حذف می‌کند)
                if ( $coupon->get_individual_use() && ! empty( (array) WC()->cart->get_applied_coupons() ) ) {
                        wp_send_json_error( array( 'message' => 'این کد تخفیف قابل ترکیب با کد تخفیف دیگر نیست؛ ابتدا کد قبلی سبد را حذف کنید.' ) );
                }

                // Ensure guest session exists so the coupon can be persisted on the cart
                if ( WC()->session && ! WC()->session->has_session() ) {
                        WC()->session->set_customer_session_cookie( true );
                }

                // نسخهٔ ۲.۱۲.۴ (F-12): فقط اعلان‌های خودِ این اعمال پاک/مصرف می‌شوند؛ خطاهای
                // صف‌شدهٔ درخواست‌های قبلی سشن دست‌نخورده می‌مانند (wc_clear_notices صرف
                // همهٔ اعلان‌های در انتظار را بی‌صدا نابود می‌کرد).
                $fws_notice_snap = self::notices_snapshot();
                $applied = WC()->cart->apply_coupon( $configured_coupon );

                if ( ! $applied ) {
                        $fws_fresh  = self::clear_new_notices_since( $fws_notice_snap );
                        $fws_errors = array_values( array_filter( $fws_fresh, static function ( $n ) { return 'error' === $n['type']; } ) );
                        $notice_msg = ! empty( $fws_errors ) ? wp_strip_all_tags( $fws_errors[0]['notice'] ) : 'امکان اعمال این کد تخفیف وجود ندارد.';
                        wp_send_json_error( array( 'message' => $notice_msg ) );
                }

                // جلوگیری از نمایش دوباره پیام موفقیت در بارگذاری بعدی صفحه — فقط اعلان‌های خودِ این اعمال
                self::clear_new_notices_since( $fws_notice_snap );

                // نسخه ۲.۱۰: ثبت تعامل سطح بالای مودال خروج در قیف تبدیل
                if ( class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::log_coupon_apply();
                }

                wp_send_json_success(
                        array(
                                'message'      => 'کد تخفیف با موفقیت روی سبد خرید شما اعمال شد.',
                                'checkout_url' => wc_get_checkout_url(),
                        )
                );
        }

        /**
         * افزودن دسته‌ای اقلام پکیج و تنظیم سشن تخفیف
         *
         * امنیت نسخه ۲.۷: امضای HMAC سمت سرور — فقط شناسه‌هایی که در رندر باکس پکیج
         * (صفحه محصول) امضا شده‌اند مجاز به دریافت تخفیف پکیج هستند؛ در نتیجه ارسال
         * دستی product_ids دلخواه (Cart Stuffing) عملاً غیرممکن می‌شود.
         * تخفیف پکیج فقط با حداقل ۲ کالای واقعی از پکیج اعمال می‌شود.
         */
        public function add_bundle_to_cart() {
                check_ajax_referer( 'fws_prediction_nonce', 'nonce' );
                $this->check_rate_limit( 'add_bundle', 25, 60 );

                if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                        wp_send_json_error( array( 'message' => 'سبد خرید در دسترس نیست. لطفاً صفحه را تازه‌سازی کنید.' ) );
                }

                $main_id   = isset( $_POST['main_id'] ) ? absint( $_POST['main_id'] ) : 0;
                $official  = isset( $_POST['bundle_ids'] ) ? array_filter( array_map( 'absint', preg_split( '/[,\\s]+/', sanitize_text_field( wp_unslash( $_POST['bundle_ids'] ) ) ) ) ) : array();
                $signature = isset( $_POST['bundle_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle_sig'] ) ) : '';
                $raw_ids   = isset( $_POST['product_ids'] ) ? (array) $_POST['product_ids'] : array();
                $submitted = array_filter( array_unique( array_map( 'absint', $raw_ids ) ) );

                // BUG-07 fix (v2.9.0): defense-in-depth for the variable/grouped/external source bug —
                // the box is no longer rendered for such products, but a hand-crafted POST could still
                // send their id as main_id; add_to_cart() would fail for them and only the extras
                // (with the discount) would land in the cart. Reject honestly instead.
                $main_product = wc_get_product( $main_id );
                if ( $main_product && $main_product->is_type( (array) apply_filters( 'fws_non_addable_product_types', array( 'variable', 'grouped', 'external' ) ) ) ) {
                        wp_send_json_error(
                                array(
                                        'message' => 'پکیج هوشمند برای محصولات متغیر/گروهی/پیوندی فعال نیست؛ ابتدا گزینه یا محصول مشخص را انتخاب کنید.',
                                )
                        );
                }

                // اعتبارسنجی امضای پکیج: امضا فقط برای جفت «محصول مبدأ + لیست رسمی پیشنهادها» معتبر است
                $expected_sig = hash_hmac( 'sha256', $main_id . '|' . implode( ',', $official ), wp_salt( 'auth' ) );
                if ( $main_id <= 0 || empty( $official ) || ! hash_equals( $expected_sig, $signature ) ) {
                        // نسخهٔ ۲.۱۲ (S-06): امضای نامعتبر دیگر بی‌صدا رد نمی‌شود — ردپای تلاش
                        // Cart Stuffing در لاگ ووکامرس ثبت می‌شود.
                        FWS_Logger::warning(
                                'Bundle signature mismatch (possible cart-stuffing attempt).',
                                array( 'main_id' => $main_id, 'submitted_count' => count( $submitted ) ),
                                'security'
                        );
                        wp_send_json_error( array( 'message' => 'امضای پکیج نامعتبر است؛ لطفاً صفحه محصول را تازه‌سازی کرده و دوباره تلاش کنید.' ) );
                }

                // محصول اصلی همیشه بخشی از پکیج است
                if ( ! in_array( $main_id, $submitted, true ) ) {
                        $submitted[] = $main_id;
                }

                // فقط شناسه‌های داخل امضا پذیرفته می‌شوند؛ بقیه بی‌صدا حذف می‌شوند
                $allowed   = array_merge( array( $main_id ), $official );
                $valid_ids = array_values( array_intersect( $submitted, $allowed ) );
                if ( empty( $valid_ids ) ) {
                        wp_send_json_error( array( 'message' => 'هیچ محصول معتبری از پکیج انتخاب نشده است.' ) );
                }
                if ( count( $valid_ids ) > 10 ) {
                        $valid_ids = array_slice( $valid_ids, 0, 10 );
                }

                // Ensure customer session is created and persisted for guest users
                if ( WC()->session && ! WC()->session->has_session() ) {
                        WC()->session->set_customer_session_cookie( true );
                }

                // BUG-04 fix (v2.8.1): the discount must depend on what actually landed in the cart,
                // not on how many ids were submitted (an add_to_cart() call can fail, e.g. for a
                // variable parent or a stock race). Only successfully added ids are tracked.
                $added_ids = array();
                // نسخهٔ ۲.۱۲.۴ (F-12): اسنپ‌شات پیش از عملیات افزودن — خطاهای تازه‌ی همین
                // درخواست بعداً از همین مرز تفکیک می‌شوند.
                $fws_notice_snap = self::notices_snapshot();
                // نسخه ۲.۱۰: برچسب منبع و نسخه A/B روی هر آیتم سبد ثبت می‌شود تا خرید نهایی به ویجت مبدا منتسب شود
                $fws_src_meta = array(
                        'fws_source'  => 'product',
                        'fws_variant' => class_exists( 'FWS_AB_Testing' ) ? FWS_AB_Testing::effective_variant( 'product' ) : '',
                );
                foreach ( $valid_ids as $pid ) {
                        $product = wc_get_product( $pid );
                        if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
                                // نسخهٔ ۲.۱۱ (B-10): ادغام به‌جای خط تکراری — اگر همان محصول از قبل
                                // خطِ سادهٔ سبد است، تعداد همان خط بالا می‌رود؛ اقلام پکیج هم از طریق
                                // سشن fws_bundle_items تخفیف می‌گیرند پس افزودنِ واقعیِ جدید لازم نیست.
                                $existing_key = $this->find_simple_cart_line( $pid );
                                if ( $existing_key ) {
                                        $fws_item     = WC()->cart->get_cart_item( $existing_key );
                                        $new_quantity = (int) $fws_item['quantity'] + 1;
                                        if ( $product->has_enough_stock( $new_quantity ) ) {
                                                WC()->cart->set_quantity( $existing_key, $new_quantity );
                                        }
                                        $added_ids[] = (int) $pid;
                                        continue;
                                }
                                $cart_item_key = WC()->cart->add_to_cart( $pid, 1, 0, array(), $fws_src_meta );
                                if ( $cart_item_key ) {
                                        $added_ids[] = (int) $pid;
                                }
                        }
                }
                $added = count( $added_ids );

                // Bundle discount only makes sense when at least 2 bundle items are really in the cart.
                $apply_discount = ( $added >= 2 );

                if ( $added === 0 ) {
                        // نسخهٔ ۲.۱۲.۴ (F-12): فقط خطاهای همین درخواست مصرف می‌شوند
                        $fws_fresh  = self::clear_new_notices_since( $fws_notice_snap );
                        $fws_errors = array_values( array_filter( $fws_fresh, static function ( $n ) { return 'error' === $n['type']; } ) );
                        $notice_msg = ! empty( $fws_errors ) ? wp_strip_all_tags( $fws_errors[0]['notice'] ) : 'محصولات انتخابی در انبار موجود یا قابل سفارش نیستند.';
                        wp_send_json_error( array( 'message' => $notice_msg ) );
                }

                // سشن تخفیف فقط پس از افزودن موفق و فقط برای لیست اعتبارسنجی‌شده ثبت می‌شود (رفع آلودگی سشن در مسیر خطا)
                if ( $apply_discount && WC()->session ) {
                        // نسخهٔ ۲.۱۲.۴ (F-23): قفل اتمی برای «خواندن→ادغام→نوشتن» کلیدهای سشن
                        // پکیج. دو درخواست هم‌زمان (دو تب/دو دستگاه؛ غیرفعال‌شدن دکمه فقط یک
                        // تب را می‌پوشاند) هر دو یک $existing یکسان می‌خواندند و هر دو می‌نوشتند؛
                        // نوشتنِ دیرتر، شناسهٔ افزوده‌شدهٔ درخواست اول را گم می‌کرد — آن کالا
                        // بی‌صدا از تخفیف پکیج جا می‌ماند و Undo هم آن را برنمی‌گرداند.
                        // همان الگوی قفل اتمی upsell: INSERT IGNORE روی option_name یکتا +
                        // بازپس‌گیری قفل مردهٔ بیش از ۲ دقیقه + تلاش کوتاه (حداکثر ~۳۰۰ms).
                        global $wpdb;
                        $fws_bundle_lock = 'fws_bundle_lock_' . md5( (string) WC()->session->get_customer_id() );
                        $fws_merge_locked = false;
                        for ( $fws_lock_try = 0; $fws_lock_try < 12 && ! $fws_merge_locked; $fws_lock_try++ ) {
                                $wpdb->query( $wpdb->prepare(
                                        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                                        $fws_bundle_lock,
                                        (string) time()
                                ) );
                                if ( $wpdb->rows_affected ) {
                                        $fws_merge_locked = true;
                                } else {
                                        $fws_lock_at = (int) get_option( $fws_bundle_lock, 0 );
                                        if ( ( time() - $fws_lock_at ) >= 120 ) {
                                                update_option( $fws_bundle_lock, (string) time(), false );
                                                $fws_merge_locked = true;
                                        } else {
                                                usleep( 25000 );
                                        }
                                }
                        }
                        if ( $fws_merge_locked ) {
                                $existing = (array) WC()->session->get( 'fws_bundle_items', array() );
                                $merged   = array_unique( array_merge( $existing, $added_ids ) );
                                WC()->session->set( 'fws_bundle_items', $merged );

                                // نسخهٔ ۲.۱۲ (S-02): در حالت «کوپن برنامه‌ای»، کوپن مجازی پکیج همین‌جا
                                // به سبد وصل می‌شود؛ تخفیف به‌صورت ردیف مستقل در سبد/فاکتور/گزارش دیده می‌شود.
                                FWS_Bundle_Coupon::apply_if_eligible();

                                // نسخه ۲.۹.۱ — مجموعهٔ کامل «امضاشده»: مبنای بازگردانی (Undo) قلم‌های حذف‌شده
                                // در fast-woo-sale.php. این مجموعه هرگز با حذف قلم کوچک نمی‌شود؛ فقط شناسه‌های
                                // HMAC-تأییدشده را نگه می‌دارد (سقف ۵۰ — کهن‌ترین‌ها حذف می‌شوند) و چون قاعدهٔ
                                // «حداقل ۲ کالا» در before_calculate_totals سر جایش است، بازگرداندن شناسه‌ها
                                // هیچ مسیر تخفیف نابهجایی باز نمی‌کند.
                                $signed     = (array) WC()->session->get( 'fws_bundle_signed', array() );
                                $signed_all = array_slice( array_values( array_unique( array_map( 'intval', array_merge( $signed, $added_ids ) ) ) ), -50 );
                                WC()->session->set( 'fws_bundle_signed', $signed_all );

                                delete_option( $fws_bundle_lock );
                        }
                        // اگر قفل آزاد نشد: آیتم‌ها در سبد هستند ولی از ادغام سشن صرف‌نظر می‌شود
                        // (نوشتنِ رقابتیِ گم‌کننده به‌جای حفظ صحت، رد می‌شود)؛ افزودن بعدی مشتری
                        // مجموعه را کامل می‌کند.
                }

                // نسخه ۲.۱۰: ثبت رخداد «افزودن به سبد» در قیف تبدیل ویجت باکس محصول
                if ( class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::log_add_to_cart( 'product', $added_ids );
                }

                ob_start();
                woocommerce_mini_cart();
                $mini_cart = ob_get_clean();

                $message = $apply_discount
                        ? sprintf( '%d محصول با تخفیف پکیج به سبد خرید افزوده شد.', $added )
                        : sprintf( '%d محصول به سبد افزوده شد؛ تخفیف پکیج فقط با انتخاب حداقل ۲ کالا اعمال می‌شود.', $added );

                wp_send_json_success(
                        array(
                                'message'    => $message,
                                'cart_count' => WC()->cart->get_cart_contents_count(),
                                'cart_url'   => wc_get_cart_url(),
                                'cart_hash'  => WC()->cart->get_cart_hash(),
                                'fragments'  => apply_filters(
                                        'woocommerce_add_to_cart_fragments',
                                        array(
                                                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
                                        )
                                ),
                        )
                );
        }

        /**
         * افزودن سریع کالای پرکننده یا پیشنهادی
         */
        public function add_single_to_cart() {
                check_ajax_referer( 'fws_prediction_nonce', 'nonce' );
                $this->check_rate_limit( 'add_single', 30, 60 );

                $pid        = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
                // نسخه ۲.۱۰: زمینه ویجت مبدا از دیتای دکمه (با اعتبارسنجی وایت‌لیست سمت سرور)
                $src_widget = isset( $_POST['fws_widget'] ) ? sanitize_key( wp_unslash( $_POST['fws_widget'] ) ) : '';
                // نسخهٔ ۲.۱۱ (B-26): وایت‌لیست سختِ منبع‌های مجازِ «افزودن سریع» — فقط ویجت‌هایی
                // که واقعاً دکمهٔ افزودن سریع دارند (سبد، نوار ارسال، حساب، بنر جستجو، شورت‌کد).
                // قبلاً resolve_slug همهٔ برچسب‌ها را می‌پذیرفت و یک درخواست دست‌ساز می‌توانست
                // خرید را به ویجت‌های بدون دکمه (مثل thankyou/exit_modal) منتسب کند و گزارش
                // درآمد به تفکیک ویجت آلوده می‌شد.
                $resolved_src    = ( class_exists( 'FWS_Tracker' ) ) ? FWS_Tracker::resolve_slug( $src_widget ) : '';
                $allowed_sources = apply_filters( 'fws_quick_add_sources', array( 'cart', 'shipping', 'account', 'search', 'shortcode' ) );
                if ( ! in_array( $resolved_src, (array) $allowed_sources, true ) ) {
                        $resolved_src = 'unknown';
                }
                if ( $pid > 0 ) {
                        $product = wc_get_product( $pid );
                        if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
                                // نسخهٔ ۲.۱۱ (B-27): گارد سخت افزودن یک‌کلیکی — محصول متغیر بدون انتخاب
                                // گزینه قابل افزودن نیست؛ کارت‌های متغیر در فرانت لینک‌محورند و این
                                // گارد فقط درخواست‌های دست‌ساز را رد می‌کند.
                                if ( ! FWS_Prediction_Engine::is_quick_addable( $product ) ) {
                                        wp_send_json_error( array( 'message' => 'این کالا گزینهٔ مشخص دارد؛ از صفحهٔ محصول، گزینهٔ دلخواه را انتخاب و افزودن را انجام دهید.' ) );
                                }
                                if ( WC()->session && ! WC()->session->has_session() ) {
                                        WC()->session->set_customer_session_cookie( true );
                                }
                                // نسخهٔ ۲.۱۲.۴ (F-12): اسنپ‌شات اعلان‌ها پیش از عملیات سبد
                                $fws_notice_snap = self::notices_snapshot();
                                $fws_src_meta = array(
                                        'fws_source'  => $resolved_src,
                                        'fws_variant' => class_exists( 'FWS_AB_Testing' ) ? FWS_AB_Testing::effective_variant( $resolved_src ) : '',
                                );
                                // نسخهٔ ۲.۱۱ (B-10): اگر همان محصول از قبل به‌صورت خطِ ساده در سبد است،
                                // به‌جای ساختن خط تکراری (تفاوت متا در هش ادغام WC)، تعداد همان خط
                                // افزایش می‌یابد — رفتار مورد انتظار مشتری از دکمهٔ «افزودن».
                                $cart_item_key = $this->find_simple_cart_line( $pid );
                                if ( $cart_item_key ) {
                                        $fws_item     = WC()->cart->get_cart_item( $cart_item_key );
                                        $new_quantity = (int) $fws_item['quantity'] + 1;
                                        if ( $product->has_enough_stock( $new_quantity ) ) {
                                                WC()->cart->set_quantity( $cart_item_key, $new_quantity );
                                        }
                                } else {
                                        $cart_item_key = WC()->cart->add_to_cart( $pid, 1, 0, array(), $fws_src_meta );
                                }
                                if ( $cart_item_key ) {
                                        // نسخه ۲.۱۰: ثبت رخداد «افزودن به سبد» در قیف تبدیل ویجت مبدا
                                        // (نسخهٔ ۲.۱۱ — B-26: همان منبعِ وایت‌لیست‌شده، نه ورودی خام کلاینت)
                                        if ( class_exists( 'FWS_Tracker' ) ) {
                                                FWS_Tracker::log_add_to_cart( $resolved_src, array( $pid ) );
                                        }
                                        ob_start();
                                        woocommerce_mini_cart();
                                        $mini_cart = ob_get_clean();

                                        wp_send_json_success(
                                                array(
                                                        'message'    => 'محصول با موفقیت به سبد افزوده شد.',
                                                        'cart_count' => WC()->cart->get_cart_contents_count(),
                                                        'cart_url'   => wc_get_cart_url(),
                                                        'cart_hash'  => WC()->cart->get_cart_hash(),
                                                        'fragments'  => apply_filters(
                                                                'woocommerce_add_to_cart_fragments',
                                                                array(
                                                                        'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
                                                                )
                                                        ),
                                                )
                                        );
                                } else {
                                        // نسخهٔ ۲.۱۲.۴ (F-12): فقط خطاهای همین درخواست مصرف می‌شوند
                                        $fws_fresh  = self::clear_new_notices_since( $fws_notice_snap );
                                        $fws_errors = array_values( array_filter( $fws_fresh, static function ( $n ) { return 'error' === $n['type']; } ) );
                                        $notice_msg = ! empty( $fws_errors ) ? wp_strip_all_tags( $fws_errors[0]['notice'] ) : 'امکان افزودن این کالا به سبد خرید وجود ندارد.';
                                        wp_send_json_error( array( 'message' => $notice_msg ) );
                                }
                        } else {
                                wp_send_json_error( array( 'message' => 'این کالا در حال حاضر موجود یا قابل سفارش نیست.' ) );
                        }
                }
                wp_send_json_error( array( 'message' => 'شناسه محصول نامعتبر است.' ) );
        }

        /**
         * Return a fresh front-end nonce.
         *
         * BUG-10 fix (v2.8.1): the nonce printed into product/cart HTML is frozen by page caches
         * (WP Rocket, LiteSpeed, CDN) and expires after 12-24h, after which every button failed with
         * "invalid signature". The JS retries a failed request once with a nonce from this endpoint.
         * The endpoint is intentionally nonce-free (a nonce is not a secret, it only binds a session)
         * and is rate limited like every other public action.
         *
         * نسخهٔ ۲.۱۲ (S-10): مدل امنیتی اکشن‌های nopriv به rate-limit تقلیل شده بود؛ این endpoint
         * صادرکنندهٔ نانس است و حالا درخواست‌های متقاطع (Origin/Referer خارج از دامنهٔ سایت)
         * را رد می‌کند — مهاجمان بیرونی دیگر نمی‌توانند برای سشن خود نانس تازه بگیرند.
         * هدر غایب (مرورگرهای حریم‌خصوصی) رد نمی‌شود؛ rate-limit همان‌طور که بوده فعال است.
         */
        public function refresh_nonce() {
                if ( ! $this->is_same_site_request() ) {
                        FWS_Logger::warning( 'refresh_nonce rejected: cross-site origin/referer.', array(), 'security' );
                        wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 403 );
                }
                $this->check_rate_limit( 'refresh_nonce', 20, 60 );
                nocache_headers();
                wp_send_json_success( array( 'nonce' => wp_create_nonce( 'fws_prediction_nonce' ) ) );
        }

        /**
         * نسخهٔ ۲.۱۲ (S-10): بررسی هم‌سایت‌بودن درخواست بر اساس Origin/Referer.
         * فقط وقتی هدر «موجود» باشد و دامنه‌اش با دامنهٔ سایت تفاوت داشته باشد رد می‌شود؛
         * نبودِ هر دو هدر (سخت‌گیرانه‌ترین حالت حریم خصوصی) رد نمی‌شود تا مرورگرهای
         * حذف‌کنندهٔ هدر نشکنند — برای آن حالت rate-limit و نانس سر جایش است.
         *
         * @return bool
         */
        private function is_same_site_request() {
                $allowed_hosts = array_filter( array_unique( array_map( 'strtolower', array_merge(
                        array( (string) wp_parse_url( home_url(), PHP_URL_HOST ), (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
                        (array) apply_filters( 'fws_allowed_origins', array() )
                ) ) ) );
                if ( empty( $allowed_hosts ) ) {
                        return true; // پیکربندی عجیب دامنه — به رفتار قبلی (rate-limit) برمی‌گردیم
                }
                foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $header ) {
                        if ( empty( $_SERVER[ $header ] ) ) {
                                continue;
                        }
                        $candidate = esc_url_raw( wp_unslash( $_SERVER[ $header ] ) );
                        if ( '' === $candidate ) {
                                continue;
                        }
                        $host = (string) wp_parse_url( $candidate, PHP_URL_HOST );
                        if ( '' === $host ) {
                                return false;
                        }
                        if ( ! in_array( strtolower( $host ), $allowed_hosts, true ) ) {
                                return false;
                        }
                }
                return true;
        }

        /**
         * افزودن ۱ کلیکی آپسل به سفارش جاری (محافظت کامل از IDOR و جلوگیری از ثبت تکراری)
         */
        public function process_thankyou_upsell() {
                check_ajax_referer( 'fws_prediction_nonce', 'nonce' );
                $this->check_rate_limit( 'thankyou_upsell', 10, 60 );

                // نسخه ۲.۱۰.۲ — گام ۱ (کلید قطع واقعی): تا پیش از این فقط «رندر» ویجت به
                // enable_widget_thankyou وابسته بود و خودِ endpoint بدون توجه به این تنظیم
                // زنده می‌ماند؛ یعنی خاموش‌کردن ویجت از پنل، دروازهٔ AJAX را نمی‌بست.
                if ( 'yes' !== FWS_Settings::get( 'enable_widget_thankyou', 'no' ) ) {
                        wp_send_json_error( array( 'message' => 'افزودن کالای مکمل به سفارش در حال حاضر غیرفعال است.' ) );
                }

                $order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
                $order_key  = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
                $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

                $order   = wc_get_order( $order_id );
                $product = wc_get_product( $product_id );

                if ( ! $order || ! $product ) {
                        wp_send_json_error( array( 'message' => 'اطلاعات سفارش یا کالا نامعتبر است.' ) );
                }

                // نسخهٔ ۲.۱۲.۴ (F-07): گارد نوع سفارش — wc_get_order برای شناسهٔ «ریفاند»،
                // WC_Order_Refund برمی‌گرداند (true است و از گارد بالا رد می‌شود) اما متد
                // get_order_key() فقط روی WC_Order تعریف شده؛ درخواست ساده با order_id یک
                // ریفاند، Fatal Error (AJAX 500) تکرارپذیر می‌ساخت. ریفاند و هر نوع دیگری
                // غیر از سفارش واقعی، همان‌جا با پیام خطای تمیز رد می‌شود.
                if ( ! is_a( $order, 'WC_Order' ) || 'shop_order' !== $order->get_type() ) {
                        wp_send_json_error( array( 'message' => 'اطلاعات سفارش یا کالا نامعتبر است.' ) );
                }

                // Strict Anti-IDOR Authorization Check: Verify either authenticated customer ID or cryptographic Order Key
                $current_user_id = get_current_user_id();
                $is_owner        = ( $current_user_id > 0 && $order->get_user_id() === $current_user_id );
                $valid_key       = ( ! empty( $order_key ) && hash_equals( $order->get_order_key(), $order_key ) );

                if ( ! $is_owner && ! $valid_key && ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => 'خطای امنیتی: دسترسی غیرمجاز به این سفارش.' ) );
                }

                // نسخهٔ ۲.۱۲ (S-01): انتخاب معماری آپسل — suborder (پیش‌فرض) سفارشِ ثبت‌شده را
                // دست نمی‌زند (ایمیل/فاکتور/حسابداری/درگاه ناسازگار نمی‌شوند)؛ legacy_append
                // همان رفتار قدیمی الحاق قلم به همان سفارش است.
                $upsell_mode = FWS_Settings::get( 'upsell_mode', 'suborder' );

                if ( 'suborder' === $upsell_mode ) {
                        // سفارش اصلی دست‌نخورده می‌ماند، پس گاردِ «وضعیت قابل‌تغییر» و گاردِ
                        // BUG-03 (سفارش پرداخت‌شدهٔ آنلاین) اینجا بی‌معناست؛ فقط وضعیت‌های
                        // بی‌معنی (ابطال/برگشت/ناموفق) رد می‌شوند. نتیجهٔ جانبی مثبت: آپسل برای
                        // سفارش‌های پرداخت‌شدهٔ آنلاین هم که قبلاً به‌کلی رد می‌شد، حالا کار می‌کند.
                        $forbidden_statuses = apply_filters(
                                'fws_upsell_suborder_forbidden_statuses',
                                array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft', 'auto-draft' )
                        );
                        if ( in_array( $order->get_status(), (array) $forbidden_statuses, true ) ) {
                                wp_send_json_error( array( 'message' => 'برای سفارش با وضعیت فعلی امکان ثبت پیشنهاد وجود ندارد.' ) );
                        }
                } else {
                        // Only allow modification for orders in modifiable status
                        if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing' ), true ) ) {
                                wp_send_json_error( array( 'message' => 'امکان تغییر سفارش با وضعیت فعلی آن وجود ندارد.' ) );
                        }

                        // BUG-03 fix (v2.8.1): never raise the total of an order that was already paid through an
                        // online gateway - no new payment would be collected and the order would become "half paid".
                        // Allowed: orders that still need payment (customer is redirected to the pay page below)
                        // and orders placed with offline gateways (cash on delivery, bank transfer, cheque).
                        $offline_gateways = apply_filters( 'fws_upsell_offline_gateways', array( 'cod', 'bacs', 'cheque' ) );
                        $is_offline       = in_array( $order->get_payment_method(), (array) $offline_gateways, true );
                        if ( $order->is_paid() && ! $is_offline ) {
                                wp_send_json_error(
                                        array(
                                                'message' => 'پرداخت این سفارش انجام شده و امکان افزودن کالا به آن وجود ندارد. می‌توانید این کالا را جداگانه سفارش دهید.',
                                        )
                                );
                        }
                }

                // Idempotency: Prevent duplicate addition via order meta and existing order items
                $meta_flag = '_fws_upsell_added_' . $product_id;
                if ( $order->get_meta( $meta_flag ) ) {
                        wp_send_json_error( array( 'message' => 'این محصول قبلاً به سفارش شما افزوده شده است.' ) );
                }
                // نسخه ۲.۱۰.۲ — گام ۱ (سقف هر سفارش): جلوگیری از «آپسل زنجیره‌ای». پیش‌تر
                // جلوگیری از تکرار فقط «هر محصول یک‌بار» بود؛ پس از افزودن کالای B، موتور
                // کالای C را پیشنهاد می‌داد و همهٔ گاردها برای C هم رد می‌شدند. اکنون هر
                // سفارش در کل عمر خود فقط یک کالای مکمل با تخفیف ویژه می‌پذیرد.
                foreach ( $order->get_meta_data() as $order_meta ) {
                        $meta_data = $order_meta->get_data();
                        if ( isset( $meta_data['key'] ) && 0 === strpos( (string) $meta_data['key'], '_fws_upsell_added_' ) ) {
                                wp_send_json_error( array( 'message' => 'برای هر سفارش فقط یک کالای مکمل با تخفیف ویژه قابل افزودن است.' ) );
                        }
                }
                foreach ( $order->get_items() as $existing_item ) {
                        if ( $existing_item->get_product_id() === $product_id || $existing_item->get_variation_id() === $product_id ) {
                                wp_send_json_error( array( 'message' => 'این محصول قبلاً در این سفارش ثبت شده است.' ) );
                        }
                }

                // Stock & purchasable verification
                if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
                        wp_send_json_error( array( 'message' => 'متأسفانه موجودی این کالا در انبار به اتمام رسیده است.' ) );
                }

                // نسخهٔ ۲.۱۱ (B-27): گارد سخت آپسل — محصول متغیر بدون واریاسیون قابل افزودن به
                // سفارش نیست؛ رندر برای متغیرها اصلاً دکمهٔ افزودن ندارد و این گارد
                // درخواست‌های دست‌ساز را با پیام شفاف رد می‌کند.
                if ( ! FWS_Prediction_Engine::is_quick_addable( $product ) ) {
                        wp_send_json_error( array( 'message' => 'این کالا گزینهٔ مشخص دارد و قابل افزودن یک‌کلیکی به سفارش نیست؛ از صفحهٔ محصول گزینهٔ دلخواه را انتخاب کنید.' ) );
                }

                // نسخه ۲.۷ — ضد دستکاری تخفیف: فقط کالایی که موتور پیش‌بینی واقعاً برای این سفارش
                // پیشنهاد داده است با تخفیف آپسل قابل افزودن است؛ ارسال product_id دلخواه رد می‌شود.
                //
                // نسخهٔ ۲.۱۲.۲ (B-44): مسیر «امضای رندر» به‌عنوان مرجع اول پذیرش.
                // قبلاً کاندیدا در لحظهٔ کلیک «دوباره» محاسبه می‌شد؛ هر تغییر بین رندر و کلیک
                // (اتمام موجودی و برگشتنش، ماینینگ شبانه، بی‌اعتبارسازی کش با هر فروش) کاندیدای
                // متفاوتی می‌ساخت و مشتریِ درست‌حرف، پیام غلط «جزو پیشنهادها نیست» می‌گرفت —
                // در حالی که باکس را واقعاً برای همین سفارش دیده بود. حالا باکس رندرشده
                // امضای HMAC (order_id|product_id با wp_salt) دارد؛ امضای معتبر = همان
                // پیشنهاد واقعیِ سرور در لحظهٔ رندر. برای مارک‌آپ قدیمیِ کش‌شده (بدون امضا)
                // مسیر محاسبهٔ مجدد به‌عنوان fallback حفظ شده است. قیمت و موجودی همچنان در
                // همین لحظه اعتبارسنجی می‌شوند (امضای معتبر = مجوز تخفیف، نه چشم‌بستن به موجودی).
                $upsell_sig      = isset( $_POST['upsell_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['upsell_sig'] ) ) : '';
                $expected_sig    = hash_hmac( 'sha256', $order_id . '|' . $product_id, wp_salt( 'auth' ) );
                $is_signed_offer = ( '' !== $upsell_sig ) && hash_equals( $expected_sig, $upsell_sig );
                // نسخهٔ ۲.۱۲.۳ (R5): با امضای معتبرِ رندر، محاسبهٔ مجددِ کش‌نشدنیِ موتور
                // (کوئری affinity + بارگذاری محصولات) لازم نیست — فقط مسیر fallback
                // (مارک‌آپ قدیمیِ بدون امضا) آن را اجرا می‌کند.
                if ( ! $is_signed_offer ) {
                        $engine          = FWS_Prediction_Engine::get_instance();
                        $expected_upsell = $engine->get_post_purchase_upsell( $order_id );
                        if ( ! $expected_upsell || (int) $expected_upsell['product_id'] !== $product_id ) {
                                wp_send_json_error( array( 'message' => 'این کالا جزو پیشنهادهای اختصاصی سیستم برای سفارش شما نیست.' ) );
                        }
                }

                // Calculate discounted price
                // BUG-08 fix (v2.9.0): the discount basis must be the catalogue regular price (the
                // honest "was" price), not the current price which may already be on sale.
                // BUG-01 fix (v2.9.0): order line totals are always stored tax-EXCLUSIVE
                // (WC_Abstract_Order::add_product uses wc_get_price_excluding_tax). On stores with
                // "prices entered with tax" enabled (common in Iranian shops) get_price()/
                // get_regular_price() are tax-INCLUSIVE; passing them straight into subtotal/total
                // made calculate_totals() stack tax on top of an already-taxed amount and the
                // customer was overcharged versus the displayed box price. Normalize first.
                $discount_pct = max( 0, min( 90, floatval( FWS_Settings::get( 'upsell_discount', 20 ) ) ) );
                $basis_price  = (float) $product->get_regular_price();
                if ( $basis_price <= 0 ) {
                        $basis_price = (float) $product->get_price();
                }
                if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
                        // نسخهٔ ۲.۱۱ (B-31): مبنای مالیات = آدرس مشتریِ همین سفارش (پارامتر order)،
                        // نه نرخ پایهٔ فروشگاه — در فروشگاه‌های با نرخ مالیاتی وابسته به آدرس،
                        // بدون این پارامتر مبلغ خط سفارش با نرخ اشتباه محاسبه می‌شد.
                        $basis_price = (float) wc_get_price_excluding_tax( $product, array( 'price' => $basis_price, 'order' => $order ) );
                }
                $discounted_price = round( $basis_price * ( ( 100 - $discount_pct ) / 100 ), wc_get_price_decimals() );

                // نسخه ۲.۱۰.۲ — گام ۱: قیمت نهایی هرگز از قیمت فعلی فروشگاه گران‌تر نیست؛
                // اگر محصول هم‌اکنون حراج عمیق‌تری از تخفیف آپسل داشته باشد، همان قیمت فعلی
                // ملاک است نه قیمت تخفیف‌خورده از قیمت عادی.
                $live_price = (float) $product->get_price();
                if ( $live_price > 0 && function_exists( 'wc_get_price_excluding_tax' ) ) {
                        $live_price = (float) wc_get_price_excluding_tax( $product, array( 'price' => $live_price, 'order' => $order ) );
                }
                if ( $live_price > 0 && $discounted_price > $live_price ) {
                        $discounted_price = $live_price;
                }

                // نسخه ۲.۱۰.۲ — گام ۱ (قفل اتمی ضد درخواست موازی): چک-سپس-نوشتنِ متای
                // ضدتکرار اتمی نیست؛ دو درخواست هم‌زمان می‌توانستند هر دو از گاردها رد
                // شوند و دو آیتم تکراری ثبت کنند. INSERT IGNORE روی ایندکس یکتای
                // option_name اتمی است؛ قفل کهنه‌تر از ۲ دقیقه (درخواست مرده) بازپس‌گیری می‌شود.
                // نسخهٔ ۲.۱۲.۴ (F-08): قفل «فقط روی سفارش» است نه سفارش+محصول — با کلید
                // قبلی، دو درخواست هم‌زمان برای «دو محصول متفاوت» از هم عبور می‌کردند و
                // سقف «هر سفارش فقط یک کالای مکمل» شکسته می‌شد (دو سفارش وابسته). حالا
                // درخواست دوم خطای مشغول‌بودن می‌گیرد و در تلاش بعدی، گارد متای ضدتکرار
                // آن را به‌درستی رد می‌کند.
                global $wpdb;
                $lock_key = 'fws_upsell_lock_' . $order_id;
                $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                        $lock_key,
                        (string) time()
                ) );
                if ( ! $wpdb->rows_affected ) {
                        $locked_at = (int) get_option( $lock_key, 0 );
                        if ( ( time() - $locked_at ) < 120 ) {
                                wp_send_json_error( array( 'message' => 'درخواست قبلی در حال پردازش است؛ لطفاً چند لحظه بعد دوباره تلاش کنید.' ) );
                        }
                        update_option( $lock_key, (string) time(), false );
                }

                // ───────── نسخهٔ ۲.۱۲ (S-01): مسیر «سفارش وابسته» ─────────
                if ( 'suborder' === $upsell_mode ) {
                        $child = wc_create_order(
                                array(
                                        'customer_id' => $order->get_customer_id(),
                                        'created_via' => 'fws_thankyou_upsell',
                                        'status'      => 'pending',
                                )
                        );
                        if ( is_wp_error( $child ) || ! is_a( $child, 'WC_Order' ) ) {
                                delete_option( $lock_key );
                                wp_send_json_error( array( 'message' => 'خطا در ساخت سفارش وابسته. لطفاً مجدداً تلاش کنید.' ) );
                        }
                        $child->set_parent_id( $order->get_id() );
                        $child->set_currency( $order->get_currency() );
                        $child->set_address( $order->get_address( 'billing' ), 'billing' );
                        $child->set_address( $order->get_address( 'shipping' ), 'shipping' );
                        $child->set_payment_method( $order->get_payment_method() );
                        $child->set_payment_method_title( $order->get_payment_method_title() );

                        // خط سفارش: subtotal = قیمت عادی کاتالوگ («قیمت قبلی» صادقانه در ادمین)،
                        // total = قیمت تخفیف‌دار — تخفیف آپسل در خود ردیف قابل‌مشاهده و حسابرسی‌پذیر است.
                        $child_item_id = $child->add_product(
                                $product,
                                1,
                                array(
                                        'subtotal' => $basis_price,
                                        'total'    => $discounted_price,
                                )
                        );
                        if ( ! $child_item_id ) {
                                $child->delete( true );
                                delete_option( $lock_key );
                                wp_send_json_error( array( 'message' => 'خطا در الحاق محصول به سفارش وابسته. لطفاً مجدداً تلاش کنید.' ) );
                        }
                        if ( function_exists( 'wc_add_order_item_meta' ) ) {
                                wc_add_order_item_meta( $child_item_id, '_fws_source', 'thankyou' );
                                $fws_upsell_variant = class_exists( 'FWS_AB_Testing' ) ? FWS_AB_Testing::effective_variant( 'thankyou' ) : '';
                                if ( in_array( $fws_upsell_variant, array( 'A', 'B' ), true ) ) {
                                        wc_add_order_item_meta( $child_item_id, '_fws_variant', $fws_upsell_variant );
                                }
                        }

                        $child->update_meta_data( '_fws_upsell_parent', $order->get_id() );
                        $child->add_order_note(
                                sprintf(
                                        'سفارش وابستهٔ آپسل برای سفارش #%1$d — کالای «%2$s» با %3$s٪ تخفیف اختصاصی (%4$s).',
                                        $order->get_id(),
                                        $product->get_name(),
                                        $discount_pct,
                                        wc_price( $discounted_price )
                                )
                        );
                        // مالیات و جمع نهایی طبق نرخ آدرس مشتریِ کپی‌شده؛ خطوط آیتم دست‌نخورده می‌مانند
                        $child->calculate_totals();
                        $child->save();

                        // فلگ‌های ضدرتکرار روی والد می‌مانند تا همهٔ گاردهای رندر/endpoint بدون تغییر کار کنند.
                        $order->update_meta_data( $meta_flag, current_time( 'mysql' ) );
                        $order->update_meta_data( '_fws_upsell_child', $child->get_id() );
                        $order->add_order_note(
                                sprintf(
                                        'سفارش وابستهٔ آپسل #%1$d برای کالای «%2$s» ساخته شد؛ سفارش اصلی بدون تغییر ماند.',
                                        $child->get_id(),
                                        $product->get_name()
                                )
                        );
                        $order->save();

                        FWS_Logger::info(
                                sprintf( 'Upsell sub-order #%1$d created for parent #%2$d (product #%3$d, %4$s%% off).', $child->get_id(), $order->get_id(), $product_id, $discount_pct ),
                                array(),
                                'upsell'
                        );

                        $offline_gateways = apply_filters( 'fws_upsell_offline_gateways', array( 'cod', 'bacs', 'cheque' ) );
                        $child_offline    = in_array( $child->get_payment_method(), (array) $offline_gateways, true );
                        if ( $child_offline ) {
                                // درگاه آفلاین (COD/کارت‌به‌کارت/چک): مثل ثبت عادی با همان درگاه، سفارش
                                // وابسته همین حالا قطعی می‌شود؛ ایمیل‌ها و کاهش موجودی در گذار وضعیتِ
                                // طبیعی ووکامرس اجرا می‌شوند (نه دست‌ساز).
                                $child->payment_complete();
                                if ( class_exists( 'FWS_Tracker' ) ) {
                                        FWS_Tracker::attribute_order( $child->get_id() );
                                }
                                $response = array(
                                        'message'   => 'کالای مکمل با تخفیف ویژه به‌صورت سفارش وابستهٔ جداگانه ثبت شد؛ فاکتور همین سفارش بدون تغییر ماند!',
                                        'new_total' => $child->get_formatted_order_total(),
                                );
                        } else {
                                // درگاه آنلاین: مشتری برای مبلغ سفارش وابسته به صفحهٔ پرداخت می‌رود؛
                                // پس از پرداخت، ایمیل‌ها/وبهوک‌ها/فاکتورِ سفارش وابسته به‌صورت عادی اجرا می‌شوند.
                                $response = array(
                                        'message'   => 'سفارش وابستهٔ شما ثبت شد؛ در حال انتقال به صفحهٔ پرداخت مبلغ آن…',
                                        'new_total' => $child->get_formatted_order_total(),
                                        'pay_url'   => $child->get_checkout_payment_url(),
                                );
                        }
                        delete_option( $lock_key );
                        wp_send_json_success( $response );
                }
                // ───────── پایان مسیر suborder — ادامه: رفتار قدیمی legacy_append ─────────

                $item_id = $order->add_product(
                        $product,
                        1,
                        array(
                                'subtotal' => $discounted_price,
                                'total'    => $discounted_price,
                        )
                );

                if ( ! $item_id ) {
                        delete_option( $lock_key );
                        wp_send_json_error( array( 'message' => 'خطا در الحاق محصول به سفارش. لطفاً مجدداً تلاش کنید.' ) );
                }

                // نسخه ۲.۱۰: برچسب منبع روی آیتم سفارش برای انتساب درآمد به ویجت «صفحه تشکر»
                if ( function_exists( 'wc_add_order_item_meta' ) ) {
                        // نسخه ۲.۱۰.۲ (B-09): متای بدون پیشوند «_» در ایمیل سفارش و صفحهٔ
                        // مشاهده سفارش مشتری نمایش داده می‌شد؛ اکنون مخفی است.
                        wc_add_order_item_meta( $item_id, '_fws_source', 'thankyou' );
                        $fws_upsell_variant = class_exists( 'FWS_AB_Testing' ) ? FWS_AB_Testing::effective_variant( 'thankyou' ) : '';
                        if ( in_array( $fws_upsell_variant, array( 'A', 'B' ), true ) ) {
                                wc_add_order_item_meta( $item_id, '_fws_variant', $fws_upsell_variant );
                        }
                }

                // Mark as added and record customer note
                $order->update_meta_data( $meta_flag, current_time( 'mysql' ) );
                $order->calculate_totals();
                $order->add_order_note(
                        sprintf(
                                'محصول مکمل «%s» با %s٪ تخفیف اختصاصی (%s) از طریق سیستم پیشنهاد هوشمند به سفارش افزوده شد.',
                                $product->get_name(),
                                $discount_pct,
                                wc_price( $discounted_price )
                        )
                );
                $order->save();

                // BUG-02 fix (v2.8.1): WooCommerce reduces stock once, on the payment/status transition.
                // An item appended later never triggers that hook. If stock has already been reduced for
                // this order, reduce it for the new line too (wc_reduce_stock_levels() is idempotent per
                // item via the _reduced_stock item meta). For unpaid orders WC will reduce it on payment.
                if ( $order->get_data_store()->get_stock_reduced( $order_id ) ) {
                        wc_reduce_stock_levels( $order );
                }

                // نسخه ۲.۱۰: سفارش صفحه تشکر معمولاً همین حالا در وضعیت processing است و
                // دیگر گذار وضعیت ندارد؛ انتساب همین‌جا هم اجرا می‌شود.
                // نسخه ۲.۱۰.۲ (B-01): انتساب «دلتایی» شد — فقط آیتم‌های بدون فلگ منتسب
                // می‌شوند؛ بنابراین آیتم آپسلی که بعد از گذار processing اضافه شده است هم
                // دیگر قربانی گارد یک‌بارِ سفارش نمی‌شود و درآمدش گزارش می‌شود.
                if ( class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::attribute_order( $order->get_id() );
                }

                $response = array(
                        'message'   => 'کالای مکمل با موفقیت و با تخفیف ویژه به سفارش شما اضافه شد!',
                        'new_total' => $order->get_formatted_order_total(),
                );
                if ( $order->needs_payment() ) {
                        $response['pay_url'] = $order->get_checkout_payment_url();
                        $response['message'] = 'کالا به سفارش افزوده شد؛ در حال انتقال به صفحه پرداخت مبلغ جدید…';
                }
                delete_option( $lock_key );
                wp_send_json_success( $response );
        }

        /**
         * بازسازی الگوها با احراز هویت ادمین
         */
        public function recalculate_rules() {
                check_ajax_referer( 'fws_admin_nonce', 'nonce' );

                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => 'عدم دسترسی مجاز.' ) );
                }

                if ( get_transient( 'fws_mining_lock' ) ) {
                        wp_send_json_error( array( 'message' => 'عملیات تحلیل داده‌ها هم‌اکنون در پس‌زمینه در حال اجرا است. لطفاً شکیبا باشید.' ) );
                }

                $count = FWS_Database_Miner::run_market_basket_analysis();
                wp_send_json_success( array( 'rules_count' => $count ) );
        }

        /**
         * بهینه‌سازی دیتابیس با احراز هویت ادمین
         */
        public function optimize_database() {
                check_ajax_referer( 'fws_admin_nonce', 'nonce' );

                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => 'عدم دسترسی مجاز.' ) );
                }

                FWS_Performance_Optimizer::optimize_database_tables();
                wp_send_json_success( array( 'message' => 'جداول با موفقیت Defragment، ایندکس‌ها بهینه‌سازی و ترنزینت‌های منقضی پاکسازی شدند.' ) );
        }

        /**
         * نسخه ۲.۱۰ — بیکن سبک ثبت رخدادهای فرانت (فعلاً: نمایش واقعی مودال خروج)
         * امنیت: نانس صفحه + Rate-Limit + وایت‌لیست سخت نوع رخداد؛ هیچ ورودی آزادی‌ای پذیرفته نمی‌شود.
         */
        public function track_event() {
                check_ajax_referer( 'fws_prediction_nonce', 'nonce' );
                $this->check_rate_limit( 'track_event', 60, 60 );

                $type = isset( $_POST['track_type'] ) ? sanitize_key( wp_unslash( $_POST['track_type'] ) ) : '';
                if ( 'exit_shown' === $type && class_exists( 'FWS_Tracker' ) ) {
                        FWS_Tracker::log_exit_modal_shown();
                }
                // پاسخ کم‌حجم و بی‌صدا — بیکن نباید هیچ اثر جانبی UI داشته باشد
                wp_send_json_success( array( 'ok' => true ) );
        }

        /* ───── نسخهٔ ۲.۱۲.۴ (F-12): بهداشت اعلان‌های ووکامرس ─────
         * wc_clear_notices() «همهٔ» اعلان‌های در انتظار — از جمله خطاهای صف‌شدهٔ
         * درخواست‌های قبلی سشن (مثلاً خطای موجودی صفحهٔ محصول) — را بی‌صدا پاک می‌کرد.
         * الگوی زیر فقط اعلان‌های «صف‌شده پس از اسنپ‌شات» را برمی‌گرداند تا caller
         * بتواند آن‌ها را در JSON مصرف کند و اعلان‌های قدیمی سشن دست‌نخورده بمانند.
         */

        /**
         * اسنپ‌شات اعلان‌های فعلی ووکامرس (پیش از عملیاتی که اعلان صف می‌کند)
         * @return array
         */
        private static function notices_snapshot() {
                return ( function_exists( 'wc_get_notices' ) ) ? wc_get_notices() : array();
        }

        /**
         * حذف فقط اعلان‌های تازهٔ صف‌شده پس از اسنپ‌شات؛ اعلان‌های قدیمی سشن بازسازی می‌شوند.
         * @param array $snapshot خروجی notices_snapshot()
         * @return array اعلان‌های تازه به شکل [ ['type' => ..., 'notice' => ...], ... ]
         */
        private static function clear_new_notices_since( $snapshot ) {
                if ( ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_clear_notices' ) || ! function_exists( 'wc_add_notice' ) ) {
                        return array();
                }
                $now = wc_get_notices();
                if ( $now === $snapshot ) {
                        return array(); // هیچ اعلان تازه‌ای صف نشده؛ اعلان‌های قدیمی دست‌نخورده
                }
                wc_clear_notices();
                $fresh = array();
                foreach ( $now as $type => $items ) {
                        $old    = isset( $snapshot[ $type ] ) ? (array) $snapshot[ $type ] : array();
                        $items  = array_values( (array) $items );
                        $count_old = count( $old );
                        foreach ( $items as $idx => $notice ) {
                                $text = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : (string) $notice;
                                if ( $idx < $count_old && isset( $old[ $idx ] ) && $old[ $idx ] === $notice ) {
                                        // اعلان قدیمی سشن — به همان نوع بازگردانی می‌شود
                                        wc_add_notice( $text, $type );
                                } else {
                                        $fresh[] = array(
                                                'type'   => $type,
                                                'notice' => $text,
                                        );
                                }
                        }
                }
                return $fresh;
        }
}
