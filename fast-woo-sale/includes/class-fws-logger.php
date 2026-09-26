<?php
/**
 * Class FWS_Logger
 * نسخهٔ ۲.۱۲ (S-06): لایهٔ متمرکز ثبت رخدادهای عملیاتی روی لاگر رسمی ووکامرس.
 *
 * مشکل ریشه‌ای: در کل افزونه هیچ سازوکار گزارش‌گیری نبود — شکست ماینینگ، ارتقای شِما،
 * امضای نامعتبر پکیج، رد درخواست‌های مشکوک و … همه «بی‌صدا» بودند و مدیر فروشگاه برای
 * فهمیدن علت هر رفتار غریب فقط می‌توانست حدس بزند.
 *
 * این کلاس همهٔ رخدادها را با wc_get_logger() در منبع «fast-woo-sale» ثبت می‌کند؛
 * مدیر از مسیر رسمی ووکامرس (وضعیت → گزارش‌ها / Status → Logs) به آن‌ها دسترسی دارد.
 * حریم خصوصی: هیچ دادهٔ شخصی (نام، ایمیل، تلفن، آدرس، هش سشن) در لاگ‌ها نوشته نمی‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FWS_Logger {

	const SOURCE = 'fast-woo-sale';

	/**
	 * ثبت یک رخداد در لاگر ووکامرس
	 *
	 * @param string $message پیام (بدون دادهٔ شخصی).
	 * @param string $level   info|warning|error|debug.
	 * @param array  $context زمینهٔ ساختاری (شناسه‌ها، اعداد).
	 * @param string $tag     برچسب کوتاه ماژول (mining|schema|security|upsell|...).
	 * @return void
	 */
	public static function log( $message, $level = 'info', $context = array(), $tag = '' ) {
		// کلید قطع سراسری برای توسعه‌دهندگان (فیلتر رسمی الگو).
		if ( ! apply_filters( 'fws_logging_enabled', true ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}
		$final = ( '' !== $tag ) ? '[' . $tag . '] ' . $message : $message;
		$base  = is_array( $context ) ? $context : array();
		$base['source'] = self::SOURCE;
		$logger->log( $level, $final, $base );
	}

	public static function info( $message, $context = array(), $tag = '' ) {
		self::log( $message, 'info', $context, $tag );
	}

	public static function warning( $message, $context = array(), $tag = '' ) {
		self::log( $message, 'warning', $context, $tag );
	}

	public static function error( $message, $context = array(), $tag = '' ) {
		self::log( $message, 'error', $context, $tag );
	}

	public static function debug( $message, $context = array(), $tag = '' ) {
		self::log( $message, 'debug', $context, $tag );
	}
}
