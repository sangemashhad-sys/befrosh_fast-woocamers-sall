<?php
/**
 * Class FWS_Privacy
 * نسخهٔ ۲.۱۲ (S-09): انطباق با API حریم خصوصی وردپرس (GDPR / «داده‌های شخصی»).
 *
 * مشکل ریشه‌ای: جدول رخدادها {prefix}fws_events ستون user_id دارد، اما هیچ Exporter/Eraser
 * ثبت نشده بود؛ درخواست «صادرات داده‌های من» و «حذف داده‌های من» در ابزار رسمی وردپرس
 * این جدول را نمی‌دید و دادهٔ تحلیلی کاربر در آن می‌ماند.
 *
 * - Exporter: رخدادهای مرتبط با کاربر (بر پایهٔ user_id) به‌صورت گروه‌بندی‌شده صادر می‌شود؛
 *   فقط دادهٔ تحلیلی — بدون هش سشن (ناشناس‌سازی‌شدهٔ IP) و بدون هیچ دادهٔ تماس.
 * - Eraser: ردیف‌های کاربر دسته‌ای حذف می‌شوند (بچ ۵۰۰تایی با بودجهٔ زمانی در هر صفحه).
 *   ردیف‌های کاربران مهمان (user_id=0) شبه‌نام‌اند (هش IP/UA) و با پنجرهٔ نگهداری
 *   (tracking_retention_days) خودبه‌خود پاک می‌شوند؛ در پیام Eraser به این موضوع اشاره می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class FWS_Privacy {

        const BATCH = 500;

        public static function init() {
                add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ), 10 );
                add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 10 );
        }

        public static function register_exporter( $exporters ) {
                $exporters['fast-woo-sale-events'] = array(
                        'exporter_friendly_name' => 'رخدادهای تحلیلی افزونهٔ پیش‌بینی خرید (Fast Woo)',
                        'callback'               => array( __CLASS__, 'export_user_data' ),
                );
                return $exporters;
        }

        public static function register_eraser( $erasers ) {
                $erasers['fast-woo-sale-events'] = array(
                        'eraser_friendly_name' => 'رخدادهای تحلیلی افزونهٔ پیش‌بینی خرید (Fast Woo)',
                        'callback'             => array( __CLASS__, 'erase_user_data' ),
                );
                return $erasers;
        }

        /**
         * @return int|null شناسهٔ کاربر از ایمیل یا null
         */
        private static function resolve_user_id( $email_address ) {
                $email = trim( (string) $email_address );
                if ( '' === $email || ! is_email( $email ) ) {
                        return null;
                }
                $user = get_user_by( 'email', $email );
                return ( $user instanceof WP_User ) ? (int) $user->ID : null;
        }

        /**
         * نسخهٔ ۲.۱۳ (I-55): سفارش‌های «مهمان» (بدون حساب) صاحبِ user_id نیستند؛ فقط از
         * طریق ایمیل صورتحساب به این شخص وصل می‌شوند. رخدادهای خریدِ مهمان در جدول
         * user_id=0 و order_id=شناسهٔ سفارش دارند — این هلپر شناسهٔ آن سفارش‌ها را با
         * API رسمی ووکامرس می‌یابد (سازگار با HPOS) تا export/erase بر اساس order_id هم
         * اجرا شود. سقف ۵۰۰ سفارش برای مهار هزینهٔ کوئری؛ برای فروشگاه واقعی به‌مراتب کافی است.
         *
         * @param string $email_address
         * @return int[]
         */
        private static function customer_order_ids( $email_address ) {
                $email = trim( (string) $email_address );
                if ( '' === $email || ! is_email( $email ) || ! function_exists( 'wc_get_orders' ) ) {
                        return array();
                }
                $ids = wc_get_orders(
                        array(
                                'billing_email' => $email,
                                'limit'         => 500,
                                'return'        => 'ids',
                                'type'          => 'shop_order',
                        )
                );
                return array_map( 'absint', (array) $ids );
        }

        private static function table() {
                global $wpdb;
                return $wpdb->prefix . 'fws_events';
        }

        private static function table_exists() {
                global $wpdb;
                static $exists = null;
                if ( null !== $exists ) {
                        return $exists;
                }
                $table  = self::table();
                $exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
                return $exists;
        }

        /**
         * صادرات رخدادهای کاربر
         *
         * @param string $email_address
         * @param int    $page
         * @return array
         */
        public static function export_user_data( $email_address, $page = 1 ) {
                $page    = max( 1, (int) $page );
                $user_id = self::resolve_user_id( $email_address );
                $data    = array(
                        'data' => array(),
                        'done' => true,
                );
                if ( ! self::table_exists() ) {
                        return $data;
                }

                // نسخهٔ ۲.۱۳ (I-55): خروجی شامل رخدادهای حساب‌دار (user_id) و رخدادهای خریدِ
                // مهمان (order_id از سفارش‌های هم‌ایمیل) — قبلاً ردیف‌های مهمان (user_id=0)
                // کامل از خروجی GDPR جا می‌ماندند.
                $order_ids = self::customer_order_ids( $email_address );
                if ( null === $user_id && empty( $order_ids ) ) {
                        return $data;
                }

                global $wpdb;
                $table = self::table();

                $where      = '';
                $where_args = array();
                if ( null !== $user_id ) {
                        $where      .= 'user_id = %d';
                        $where_args[] = $user_id;
                }
                if ( ! empty( $order_ids ) ) {
                        if ( '' !== $where ) {
                                $where .= ' OR ';
                        }
                        $where      .= 'order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')';
                        $where_args = array_merge( $where_args, $order_ids );
                }
                $where_args[] = self::BATCH;
                $where_args[] = ( $page - 1 ) * self::BATCH;

                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, event_time, event_type, widget, product_id, variant, order_id, revenue
                                 FROM {$table} WHERE ( {$where} ) ORDER BY id ASC LIMIT %d OFFSET %d",
                                $where_args
                        )
                );

                if ( empty( $rows ) ) {
                        return $data;
                }

                $data_items = array();
                // نسخهٔ ۲.۱۲.۶ (G-10): هر آیتم خروجی حداکثر ۲۵ رخداد — قبلاً تا ۵۰۰ ردیف در
                // «یک» آیتم با item_idِ ردیف اول ادغام می‌شد و خروجیِ غیرقابل‌خواندنِ جابجای
                // می‌ساخت. حالا بلوک‌های ۲۵تایی با item_idِ بازهٔ شناسه‌ها صادر می‌شود.
                $rows_per_item = 25;
                $chunks        = array_chunk( (array) $rows, $rows_per_item );
                foreach ( $chunks as $chunk ) {
                        $props = array();
                        foreach ( $chunk as $row ) {
                                $props[] = array(
                                        'name'  => 'event_time',
                                        'value' => (string) $row->event_time,
                                );
                                $props[] = array(
                                        'name'  => 'event_type',
                                        'value' => (string) $row->event_type,
                                );
                                $props[] = array(
                                        'name'  => 'widget',
                                        'value' => (string) $row->widget,
                                );
                                $props[] = array(
                                        'name'  => 'product_id',
                                        'value' => (int) $row->product_id,
                                );
                                $props[] = array(
                                        'name'  => 'variant',
                                        'value' => (string) $row->variant,
                                );
                                $props[] = array(
                                        'name'  => 'order_id',
                                        'value' => (int) $row->order_id,
                                );
                                $props[] = array(
                                        'name'  => 'revenue',
                                        'value' => (float) $row->revenue,
                                );
                        }

                        $first_id = (int) $chunk[0]->id;
                        $last_id  = (int) $chunk[ count( $chunk ) - 1 ]->id;
                        $data_items[] = array(
                                'group_id'    => 'fws_events',
                                'group_label' => 'رخدادهای پیشنهاددهندهٔ هوشمند (Fast Woo)',
                                'item_id'     => 'fws-event-' . $first_id . ( $last_id > $first_id ? '-' . $last_id : '' ),
                                'data'        => $props,
                        );
                }

                $data['data'] = $data_items;
                $data['done'] = ( count( $rows ) < self::BATCH );
                return $data;
        }

        /**
         * حذف رخدادهای کاربر (بچ ۵۰۰تایی در هر صفحه تا کوئری سنگین نشود)
         *
         * @param string $email_address
         * @param int    $page
         * @return array
         */
        public static function erase_user_data( $email_address, $page = 1 ) {
                global $wpdb;
                $user_id = self::resolve_user_id( $email_address );

                $response = array(
                        'items_removed'  => false,
                        'items_retained' => false,
                        'messages'       => array(),
                        'done'           => true,
                );

                if ( ! self::table_exists() ) {
                        return $response;
                }

                // نسخهٔ ۲.۱۳ (I-55): حذف ردیف‌های حساب‌دار (user_id) + ردیف‌های خریدِ مهمان
                // (order_id از سفارش‌های هم‌ایمیل) — قبلاً سفارش‌های مهمان پوشش داده نمی‌شدند.
                $order_ids = self::customer_order_ids( $email_address );
                if ( null === $user_id && empty( $order_ids ) ) {
                        return $response;
                }

                $table = self::table();

                $where      = '';
                $where_args = array();
                if ( null !== $user_id ) {
                        $where      .= 'user_id = %d';
                        $where_args[] = $user_id;
                }
                if ( ! empty( $order_ids ) ) {
                        if ( '' !== $where ) {
                                $where .= ' OR ';
                        }
                        $where      .= 'order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')';
                        $where_args = array_merge( $where_args, $order_ids );
                }
                $where_args[] = self::BATCH;

                // نسخهٔ ۲.۱۲.۶ (G-18): نتیجهٔ DELETE بررسی می‌شود — قبلاً خطای دیتابیس هم
                // «done => true» می‌گرفت و ابزار وردپرس ادعای حذفِ کامل می‌کرد؛ اکنون شکست
                // = done=false (وردپرس صفحهٔ بعد را دوباره تلاش می‌کند) + پیام و لاگ خطا.
                $deleted = $wpdb->query(
                        $wpdb->prepare(
                                "DELETE FROM {$table} WHERE ( {$where} ) LIMIT %d",
                                $where_args
                        )
                );

                if ( false === $deleted ) {
                        $response['done']     = false;
                        $response['messages'][] = 'حذف ردیف‌های افزونهٔ پیش‌بینی خرید با خطای دیتابیس مواجه شد؛ تلاش مجدد می‌شود. (' . $wpdb->last_error . ')';
                        FWS_Logger::error( 'Privacy eraser DELETE failed for user #' . ( null !== $user_id ? $user_id : 0 ) . ': ' . $wpdb->last_error, array(), 'privacy' );
                        return $response;
                }
                $removed = (int) $deleted;

                if ( $removed > 0 ) {
                        $response['items_removed'] = true;
                        $response['done']          = ( $removed < self::BATCH );
                        FWS_Logger::info( sprintf( 'Privacy eraser removed %d event rows (user #%d, %d guest-order match(es), page %d).', $removed, ( null !== $user_id ? $user_id : 0 ), count( $order_ids ), max( 1, (int) $page ) ), array(), 'privacy' );
                }

                if ( $response['done'] ) {
                        $response['messages'][] = 'ردیف‌های مرورِ بی‌حساب (بدون سفارش) شبه‌نام‌اند (هش IP/UA) و با پنجرهٔ نگهداری رخدادها خودبه‌خود حذف می‌شوند.';
                }
                return $response;
        }
}
