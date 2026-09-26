<?php
/**
 * نسخهٔ ۲.۱۲.۶ (G-23): تست واحد ریاضیات رنگ (قابل‌تست خالص، بدون وردپرس).
 * darken_hex هستهٔ رنگ hover همهٔ دکمه‌های ویجت است؛ F-01 قبلاً نشان داد یک
 * اشتباه در همین تابع می‌تواند «همهٔ» سایت‌ها را رنگ خراب بدهد — اکنون رفتارش قفل است.
 */
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-fws-style-manager.php';

final class ColorMathTest extends TestCase {

        public function test_darkens_six_digit_hex_by_percent(): void {
                // #f97316 با ۱۰۰٪ تیره‌سازی = سیاه
                $this->assertSame( '#000000', FWS_Style_Manager::darken_hex( '#f97316', 100 ) );
                // بدون تیره‌سازی = خود رنگ (نرمال‌شده حروف کوچک)
                $this->assertSame( '#f97316', FWS_Style_Manager::darken_hex( '#F97316', 0 ) );
        }

        public function test_three_digit_hex_is_normalized(): void {
                // #fff → #ffffff → با ۵۰٪ تیره‌سازی = #808080 (گرد شدن 127.5 به بالا)
                $this->assertSame( '#808080', FWS_Style_Manager::darken_hex( '#fff', 50 ) );
        }

        public function test_invalid_input_falls_back_to_black_not_garbage(): void {
                $this->assertSame( '#000000', FWS_Style_Manager::darken_hex( 'not-a-color', 12 ) );
                $this->assertSame( '#000000', FWS_Style_Manager::darken_hex( '#12345', 12 ) );
        }

        public function test_hover_default_percent_matches_manager_settings(): void {
                // hover پیش‌فرض ۱۲٪ — نتیجه باید بازهٔ مجاز (کمی تیره‌تر از اصلی) باشد
                $dark = FWS_Style_Manager::darken_hex( '#f97316', 12 );
                $this->assertMatchesRegularExpression( '/^#[a-f0-9]{6}$/', $dark );
                $this->assertNotSame( '#f97316', $dark );
        }
}
