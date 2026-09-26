<?php
/**
 * نسخهٔ ۲.۱۲.۶ (G-23): تست واحد واقعیِ ریاضیات قیمت آپسل.
 * هستهٔ محاسبهٔ قیمت (compute_upsell_prices) تابعی «خالص» است — بدون وردپرس/ووکامرس
 * — و قواعد پولی زیر را قفل می‌کند:
 *  ۱) مبنای تخفیف = قیمت عادی کاتالوگ (BUG-08)
 *  ۲) درصد تخفیف بین ۰ تا ۹۰ clamp می‌شود
 *  ۳) قیمت نهایی هرگز از قیمت زندهٔ فعلی گران‌تر نیست (سقف حراج عمیق‌تر)
 */
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-fws-prediction-engine.php';

final class UpsellPriceMathTest extends TestCase {

        public function test_normal_discount_computes_from_regular_basis(): void {
                $r = FWS_Prediction_Engine::compute_upsell_prices( 100000.0, 100000.0, 20, 0 );
                $this->assertSame( 100000.0, $r['basis'] );
                $this->assertSame( 80000.0, $r['discounted'] );
        }

        public function test_discount_basis_is_regular_price_not_live(): void {
                // کالای حراج‌شده: زنده ۵۰٪ پایین‌تر از عادی — تخفیف آپسل از «عادی» محاسبه می‌شود
                $r = FWS_Prediction_Engine::compute_upsell_prices( 200000.0, 100000.0, 10, 0 );
                $this->assertSame( 180000.0, $r['discounted'] );
        }

        public function test_live_sale_deeper_than_upsell_caps_the_price(): void {
                // سقف v2.10.2: حراج عمیق‌تر از تخفیف آپسل → همان قیمت زنده ملاک است
                $r = FWS_Prediction_Engine::compute_upsell_prices( 100000.0, 50000.0, 20, 0 );
                $this->assertSame( 50000.0, $r['discounted'] );
        }

        public function test_discount_percent_is_clamped_to_0_90(): void {
                // ۱۵۰ → سقف ۹۰٪ → ده درصدِ مبنا
                $this->assertSame( 10000.0, FWS_Prediction_Engine::compute_upsell_prices( 100000.0, 0, 150, 0 )['discounted'] );
                // -۵۰ → کف ۰٪ → خودِ مبنا
                $this->assertSame( 100000.0, FWS_Prediction_Engine::compute_upsell_prices( 100000.0, 0, -50, 0 )['discounted'] );
        }

        public function test_zero_basis_never_produces_negative_price(): void {
                $r = FWS_Prediction_Engine::compute_upsell_prices( 0.0, 5000.0, 20, 2 );
                $this->assertSame( 0.0, $r['basis'] );
                $this->assertSame( 0.0, $r['discounted'] );
        }

        public function test_rounding_respects_woocommerce_decimals(): void {
                // ۳۳۳۳۳٫۳۳ با ۲۰٪ تخفیف = ۲۶۶۶۶٫۶۶۴ → با ۲ اعشار گرد می‌شود
                $r = FWS_Prediction_Engine::compute_upsell_prices( 33333.33, 0, 20, 2 );
                $this->assertSame( 26666.66, $r['discounted'] );
        }

        public function test_zero_or_negative_live_price_means_no_cap(): void {
                $r = FWS_Prediction_Engine::compute_upsell_prices( 100000.0, 0.0, 25, 0 );
                $this->assertSame( 75000.0, $r['discounted'] );
        }
}
