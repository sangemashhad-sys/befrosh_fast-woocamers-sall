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
		if ( null === $user_id || ! self::table_exists() ) {
			return $data;
		}

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_time, event_type, widget, product_id, variant, order_id, revenue
				 FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				self::BATCH,
				( $page - 1 ) * self::BATCH
			)
		);

		if ( empty( $rows ) ) {
			return $data;
		}

		$props = array();
		foreach ( $rows as $row ) {
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

		$data['data'][] = array(
			'group_id'    => 'fws_events',
			'group_label' => 'رخدادهای پیشنهاددهندهٔ هوشمند (Fast Woo)',
			'item_id'     => 'fws-event-' . (int) $rows[0]->id,
			'data'        => $props,
		);
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

		if ( null === $user_id ) {
			return $response;
		}
		if ( ! self::table_exists() ) {
			return $response;
		}

		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d LIMIT %d",
				$user_id,
				self::BATCH
			)
		);
		$removed = (int) $wpdb->rows_affected;

		if ( $removed > 0 ) {
			$response['items_removed'] = true;
			$response['done']          = ( $removed < self::BATCH );
			FWS_Logger::info( sprintf( 'Privacy eraser removed %d event rows for user #%d (page %d).', $removed, $user_id, max( 1, (int) $page ) ), array(), 'privacy' );
		}

		if ( $response['done'] ) {
			$response['messages'][] = 'ردیف‌های کاربران مهمان (بدون حساب) شبه‌نام‌اند (هش IP/UA) و با پنجرهٔ نگهداری رخدادها خودبه‌خود حذف می‌شوند.';
		}
		return $response;
	}
}
