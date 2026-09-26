=== Fast Woo Predictive Purchase ===
Contributors: sangemashhad
Tags: woocommerce, recommendations, market basket, cross-sell, upsell
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 2.12.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Market-basket analysis of your own WooCommerce orders, turned into product bundles, cart complements, one-click thank-you upsells and next-purchase predictions. Everything runs on your server.

== Description ==

* Mines paid orders (WooCommerce Analytics lookup tables) into a product affinity table with confidence and lift.
* Storefront widgets: product-page bundle, cart complements, thank-you one-click upsell, free-shipping progress bar, account next-purchase prediction, search banner.
* Manual rules and blacklist override the algorithm.
* Full appearance control: disable plugin CSS, presets, CSS variables, custom CSS. No font injection.
* No external requests, no telemetry.

== Changelog ==

= 2.12.4 =
* رفع بحرانی (F-04): روی فروشگاه‌های HPOS، کوئری‌های ماینینج وضعیت سفارش را «بدون پسوند» می‌ساختند در حالی که ووکامرس در هر دو موتور ذخیره‌سازی مقدار wc-prefixed می‌نویسد (تأییدشده با سورس هسته WC) — نتیجه: صفر سفارش در تحلیل + هرسِ پس از اجرا که کل ماتریس قوانین را در هر کرون شبانه می‌زداید؛ اکنون هر دو مسیر با پسوند + یک اجرای فوری کرون برای بازسازی قوانین پس از ارتقا
* رفع بحرانی (F-05): ذخیرهٔ AJAX تنظیمات (persist_key) آرایهٔ ناقص می‌فرستاد و شاخهٔ چک‌باکس‌ها هر کلید غایب را «no» می‌نوشت — اولین عمل AJAX روی نصب تازه (مثلاً افزودن قانون دستی پیش از اولین ذخیرهٔ فرم) هر ۱۷ چک‌باکس پیش‌فرض روشن را بی‌صدا خاموش می‌کرد؛ اکنون آرایهٔ کامل ادغام‌شده با پیش‌فرض‌ها نوشته می‌شود (رفتار ذخیرهٔ عادی فرم تغییری نکرده)
* رفع بحرانی (F-06): متد «get_shipping_package» (مفرد) در ووکامرس وجود ندارد و پارامتر زون همیشه خالی می‌شد؛ سقف ارسال رایگانِ متناظر با آدرس مشتری عملاً مرده بود (همیشه زون ۰ یا بزرگ‌ترین سقف همهٔ زون‌ها) — اکنون از get_shipping_packages() با بستهٔ واقعی مشتری
* رفع (F-07): درخواست با شناسهٔ «ریفاند» در endpoint آپسل تشکر، WC_Order_Refund برمی‌گرداند که get_order_key() ندارد → Fatal Error تکرارپذیر (AJAX 500)؛ گارد نوع سفارش اضافه شد
* رفع (F-08): قفل اتمی آپسل «سفارش+محصول» بود؛ دو درخواست موازی برای دو محصول متفاوت، سقف «هر سفارش فقط یک کالای مکمل» را می‌شکست (دو سفارش وابسته) — قفل اکنون فقط روی سفارش است
* رفع (F-09): هش سشن tracker از REMOTE_ADDR خام می‌ساخت؛ پشت CDN (آروان/کلودفلر) هزاران بازدیدکننده یک هش مشترک می‌گرفتند و فشرده‌سازی نمایش/قیف تبدیل/سشن متمایز A/B خراب می‌شد — اکنون از هلپر مشترک CDN-آگاه B-41/R1
* رفع (F-10): نتیجهٔ INSERT رخداد نادیده گرفته می‌شد و متای انتساب خرید حتی در شکست ثبت می‌شد → درآمد آن قلم برای همیشه از گزارش‌ها می‌افتاد؛ اکنون فقط پس از INSERT موفق + لاگ خطا
* رفع (F-11): روی صفحات سبد خرید «بلوکی» (پیش‌فرض فروشگاه‌های جدید WC 8.3+) هوک‌های کلاسیک آتش نمی‌خورند و نوار ارسال رایگان + پیشنهادات سبد بی‌صدا غایب بودند — تزریق به بلوک woocommerce/cart با همان گیت‌های خاموش/روشن
* رفع (F-12): wc_clear_notices() همهٔ اعلان‌های در انتظار را پاک می‌کرد؛ خطای «افزوده‌نشدن بخشی از اقلام پکیج» و اعلان‌های سشن قبلی بی‌صدا گم می‌شدند — پاکسازی اسکوپ‌شده فقط برای اعلان‌های خودِ همان عملیات (کوپن خروج، افزودن پکیج/تکی، کوپن مجازی پکیج)
* رفع (F-13): کارت بینش «برندهٔ تازهٔ A/B» برای قفل‌های دستی مدیر هم ادعای «p < 0.05» می‌کرد؛ اکنون ادعای معناداری فقط برای نتیجه‌گیری خودکار (با p_value واقعی) و برای قفل دستی متن شفاف «بدون آزمون معناداری»
* رفع (F-14): بازنشانی bfcache همهٔ دکمه‌ها را بازنویسی می‌کرد؛ دکمه‌های دست‌نخورده با برچسب «پیام موفقیت» یا «+ افزودن» ثابت جای متن اصلی سرور نشان داده می‌شدند — اکنون فقط دکمه‌های تعامل‌شده
* رفع (F-15): قرار دادن شورت‌کد همان ویجتِ خودکار روی یک صفحه → دو رندر + دو ردیف نمایش (خراب‌کردن نرخ تبدیل و A/B) — دفترچهٔ رندر درخواست جاری
* رفع (F-16): پیام موفقیت هسته («تنظیمات ذخیره شد.») به‌خاطر فیلتر اسلاگ اختصاصی هرگز نمایش داده نمی‌شد — settings_errors بدون آرگومان
* رفع (F-17): انتخاب پیش‌تنظیم نمایشی، حالت فعال «استراتژی موتور» را هم بصری پاک می‌کرد (و برعکس) — ریست محدود به گروه خود کارت
* رفع (F-18): پیش‌بینی «بدون پیشنهاد» (null) از لایهٔ ترانزینت قابل بازگشت نبود و صفحهٔ حساب کاربری هر بار کوئری سنگین می‌زد — نماد آرایهٔ خالی + array_key_exists در حافظهٔ درخواست
* رفع (F-19): هرس هفتگی قوانین با DELETE دوگانهٔ OR که هیچ ایندکسی را استفاده نمی‌کرد (اسکن کامل جدول در هر دسته) — تفکیک به دو DELETE تک‌گزاره‌ای با ایندکس اختصاصی
* رفع (F-20): مسیر خودترمیمی جدول affinity، OPTIMIZE TABLE (بازسازی کامل InnoDB) را داخل درخواست وب اولین بازدیدکننده اجرا می‌کرد — در self-heal بهینه‌سازی رد می‌شود
* رفع (F-21): قفل‌های اتمی بدون TTL (fws_upsell_lock_* و fws_bundle_lock_*) در uninstall حذف نمی‌شدند و در صورت مرگ میانی درخواست تا همیشه در wp_options می‌ماندند
* رفع (F-22): حین اجرای تست A/B، همهٔ پاسخ‌های REST سایت no-cache می‌شدند (StoreAPI بلوکی و wp/v2 بی‌ربط) — REST و فید از گارد خارج شدند
* رفع (F-23): ادغام سشن اقلام پکیج قفل اتمی نداشت؛ دو درخواست موازی می‌توانستند نوشتن هم را گم کنند و یک کالا بی‌صدا از تخفیف پکیج جا بماند — همان الگوی قفل اتمی با تلاش کوتاه
* بهسازی: نتیجهٔ get_client_ip اکنون منبع واحد IP مشتری در کل افزونه است (rate-limit + tracker)

= 2.12.3 =
* بازبینی رگرسیون کامل (همهٔ تغییرات ۲.۱۰.۲ تا ۲.۱۲.۲): مرور خط‌به‌خط همهٔ مسیرهای تغییر یافته و تعامل‌های بین رفع‌ها — چهار ایراد پنهان پیدا و رفع شد (R1..R4) + یک بهینه‌سازی (R5)
* سخت‌سازی (R1 — دنبالهٔ B-41): هدرهای اختصاصی لبهٔ CDN (CF-Connecting-IP / Ar-Real-IP) فقط وقتی پذیرفته می‌شوند که اتصال‌دهندهٔ مستقیم، پروکسی معتبرِ «عمومیِ» ثبت‌شده باشد؛ در حالت ریورس‌پروکسی محلی (REMOTE_ADDR خصوصی) فقط پیمایش استاندارد X-Forwarded-For (راست به چپ) انجام می‌شود — Nginx هدرهای ناشناخته را حذف نمی‌کند و کلاینت می‌توانست با CF-Connecting-IP جعلیِ عبوری از پروکسی محلی، سقف نرخ را با IPهای جعلیِ متغیر دور بزند
* رفع (R2 — دنبالهٔ B-42/B-31): سقف قیمت فعلی در موتور آپسل تشکر به‌جای فضای کاتالوگ از فضای «بدون مالیات» اعمال می‌شد؛ در فروشگاه‌های «ثبت قیمت همراه با مالیات» مقایسهٔ فضای اشتباه، باکس را کمتر از مبلغ واقعی واریزی نشان می‌داد. شارژ نهایی همچنان توسط endpoint مستقلاً و صحیح محاسبه می‌شود
* رفع (R3 — دنبالهٔ B-42): قیمت‌های نمایشی باکس آپسل تشکر حالا مستقیم از مقادیر کاتالوگ موتور + هلپر نمایش سفارش می‌آیند؛ شاخهٔ «نمایش بدون مالیات» هلپر هم اصلاح شد (قبلاً ورودیِ همراه-با-مالیات را خام برمی‌گرداند و در فروشگاه‌های «ثبت قیمت همراه با مالیات» باکس بیش از مبلغ واقعی نشان می‌داد). ریاضیات تکراری رندر حذف شد — زنجیرهٔ یکدست برای هر دو حالت ثبت قیمت
* رفع (R4 — دنبالهٔ S-02): تعویض حالت تخفیف پکیج از «کوپن» به «قیمت» دیگر برای مشتریانِ دارای سبد فعال خطای گمراه‌کنندهٔ «کوپن وجود ندارد» نمی‌سازد — کوپن مجازی به‌جامانده در نخستین بارگذاری سبد بی‌صدا جمع می‌شود
* بهینه‌سازی (R5 — دنبالهٔ B-44): با امضای معتبرِ رندر، محاسبهٔ مجددِ کش‌نشدنی موتور (کوئری affinity) در endpoint آپسل اجرا نمی‌شود — فقط مسیر fallback مارک‌آپ قدیمی

= 2.12.2 =
* رفع (B-41 — بالا): سقف‌های نرخ پشت CDN — فهرست «پروکسی‌های معتبر» به پنل اضافه شد (IP/CIDR با کاما)؛ روی فروشگاه‌های پشت ابرآروان/کلودفلر، بدون آن همهٔ مشتریان یک REMOTE_ADDR داشتند و سقف‌های کوپن (۵/دقیقه)، آپسل (۱۰/دقیقه)، پکیج (۲۵/دقیقه) و nonce (۲۰/دقیقه) برای «کل فروشگاه» پر می‌شد و مشتری واقعی خطای «کمی صبر کنید» می‌گرفت. هدرهای اختصاصی CF-Connecting-IP و Ar-Real-IP پشتیبانی می‌شوند؛ X-Forwarded-For از راست به چپ پیمایش می‌شود (نه اولین IP قابل‌جعل)؛ REMOTE_ADDR خصوصی (ریورس‌پروکسی محلی ثبت‌نشده) هم زنجیره را لو می‌دهد. فیلتر توسعه‌دهنده: fws_trusted_proxies
* رفع (B-42 — متوسط): قیمت‌های نمایشی همهٔ ویجت‌ها از مجرای رسمی ووکامرس (wc_get_price_to_display / تنظیمات مالیات) می‌گذرند — قبلاً get_price() خام کاتالوگ نمایش داده می‌شد و در فروشگاه دارای مالیات، قیمت ویجت با صفحهٔ خود کالا و مبلغ سبد نمی‌خواند؛ «قیمت کل پکیج» و <del> آپسل تشکر (بر مبنای نرخ مالیاتی آدرس سفارش — همسو با شارژ واقعی) اصلاح شد. آرایه‌های کش‌شدهٔ موتور خام می‌مانند و تبدیل در لایهٔ رندر است تا قیمت وابسته به آدرس مالیاتی در کش مشترک منجمد نشود
* رفع (B-43 — متوسط): صداقت داده — گزارش درآمد دیگر گزینهٔ «۹۰ روز» را وقتی کلید نگهداری رخدادها کمتر است بی‌صدا نشان نمی‌دهد (فقط بازه‌های کامل‌داده ارائه می‌شوند؛ درخواست بزرگ‌تر با اطلاع شفاف clamp می‌شود)؛ تست فعال A/B سقف عمر گرفت (پیش‌فرض ۳۰ روز، فیلتر fws_ab_max_age_days، هرگز بیشتر از کلید نگهداری) تا کرون شبانه رخدادهای اولیه‌اش را نخورد و نتیجه‌گیری روی دادهٔ ناقص رخ ندهد — تستِ رسیده به سقف خودکار «متوقف بدون قفل» می‌شود و آمار پنجرهٔ کاملش محفوظ می‌ماند
* رفع (B-44 — پایین): آپسل صفحهٔ تشکر همانند پکیج امضای HMAC رندر گرفت — تغییر کاندیدا بین رندر و کلیک (موجودی، ماینینگ شبانه، purge کش با هر فروش) دیگر به مشتری پیام غلط «این کالا جزو پیشنهادها نیست» نمی‌دهد؛ امضای معتبر = همین پیشنهاد واقعی سرور بوده است. موجودی، قیمت و همهٔ گاردها همچنان در لحظهٔ کلیک اعتبارسنجی می‌شوند؛ مارک‌آپ قدیمی کش‌شده با مسیر محاسبهٔ مجدد (fallback) کار می‌کند
* رفع (B-45 — پایین): مقصد غیرقابل‌نمایش قانون دستی دیگر «ویجت خالیِ بی‌توضیح» نمی‌سازد — پنل در لحظهٔ ثبت و در جدول قوانین علت را شفاف نشان می‌دهد (ناموجود/مخفی/گروهی-پیوندی/حذف‌شده/متغیر با توضیح رفتار باکس پکیج)؛ قانون ذخیره می‌ماند و با تغییر وضعیت محصول خودش را اصلاح می‌کند

= 2.12.1 =
* رفع (B-38 — بالا): رنگ نسخهٔ B تست A/B دیگر «سراسری» نیست — قبلاً از طریق متغیر --fws-accent به همهٔ ویجت‌های صفحه (و برندهٔ قفل‌شده به‌صورت همیشگی به کل سایت) اعمال می‌شد؛ با دو تست هم‌زمان، کاربر گروه B در یک تست، ویجت‌های دیگر را هم با رنگ B می‌دید و گروه کنترل آلوده می‌شد. حالا رنگ فقط به ریشهٔ همان ویجتِ تحت تست اسکوپ می‌شود؛ به همین دلیل محدودیت «فقط یک تست رنگی هم‌زمان» هم برداشته شد
* بهینه‌سازی (B-38): تخصیص نسخهٔ A/B دیگر برای هر ۶ ویجت در هر صفحه ساخته نمی‌شود — سشن فقط وقتی لمس می‌شود که برای ویجتی تستِ رنگی فعال/قفل‌شده وجود داشته باشد یا ویجت واقعاً رندر شود
* رفع (B-39 — بالا): گارد کش صفحهٔ تست A/B تقویت شد — علاوه بر هدرهای no-cache (نسخهٔ ۲.۱۱)، ثابت‌های استاندارد DONOTCACHEPAGE/DONOTCACHEOBJECT/DONOTCACHEDB هم ست می‌شوند تا افزونه‌های کش معروف (WP Rocket، LiteSpeed، W3TC، WP Super Cache) حین تست صفحه را با نسخهٔ منجمدشده serve نکنند (قبلاً تبدیل‌ها می‌توانست به نسخه‌ای منتسب شود که کاربر هرگز ندیده بود). حین تست فعال، مستثناکردن سایت از CDN «cache-everything» همچنان توصیه می‌شود
* رفع (B-40 — متوسط): NaN و برندهٔ کاذب — سشن‌هایی که نمایششان ثبت نشده ولی افزودن‌شان ثبت شده می‌توانستند «تبدیل > نمایش» بسازند؛ p>1 یعنی sqrt(منفی)=NAN و چون NAN هیچ شرطی را پاس نمی‌کرد، تست با p_value=NaN برنده اعلام می‌شد. حالا مبنای هر نسخه دست‌کم تعداد تبدیل خودش است و گارد is_finite در سه نقطه؛ (شمارش سشن‌محور COUNT(DISTINCT) از نسخهٔ ۲.۱۱ در این خط برقرار است)
* تصمیم (توصیهٔ بازبین مستقل + قانون طلایی): قفل خودکار برندهٔ A/B پیش‌فرض «خاموش» شد — چون ظاهر مشتری را بدون آگاهی مدیر تغییر می‌دهد؛ کلید آن در کارت A/B پنل است (ذخیرهٔ AJAX مستقل از فرم تنظیمات — ذخیرهٔ فرم آن را بازنویسی نمی‌کند) و دکمهٔ «بررسی برنده‌ها» (تصمیم صریح مدیر) در هر حالت کار می‌کند. فیلتر توسعه‌دهندگان: fws_ab_auto_conclude_enabled
* رفع (F-01): رنگ hover همهٔ دکمه‌های ویجت‌ها از نسخهٔ ۲.۸ به بعد همیشه «مشکی» بود — darken_hex مقدار رنگ را به‌اشتباه به‌عنوان «کلید تنظیمات» به get_hex می‌داد و همیشه fallback (#000000) برمی‌گشت؛ پیش‌نمایش زندهٔ پنل با JS درست محاسبه می‌کرد و همین انحراف پیش‌نمایش/سایت را پنهان کرده بود
* رفع (F-03): پس از قفل برندهٔ A، عنوان سفارشی نسخهٔ A حفظ می‌شود (قبلاً به‌بی‌صدا به عنوان پیش‌فرض برمی‌گشت در حالی که برندهٔ B عنوان خودش را نگه می‌داشت)
* سخت‌سازی (F-02): شناسهٔ تست A/B یکتا می‌شود — تصادم rand در دو ساختِ همان ثانیه آمار دو تست را ادغام و عملیات توقف/حذف را به تست اشتباه اعمال می‌کرد

= 2.12.0 =
* معماری (S-01): آپسل صفحهٔ تشکر دیگر سفارشِ ثبت‌شده را دست نمی‌زند — سفارش وابستهٔ جدید با parent_id ساخته می‌شود؛ فاکتور/ایمیل/پیامک/حسابداری/درگاه سفارش اصلی بدون ناسازگاری می‌ماند، سفارش وابسته چرخهٔ کامل ایمیل و پرداخت و موجودی خودش را دارد و آپسل برای سفارش‌های پرداخت‌شدهٔ آنلاین هم که قبلاً به‌کلی رد می‌شد حالا کار می‌کند؛ کلید انتخاب معماری (سفارش وابسته / الحاق قدیمی) در پنل
* معماری (S-02): تخفیف پکیج حالا با «کوپن برنامه‌ای» اعمال می‌شود — ردیف مستقل و قابل‌مشاهده در سبد، تسویه، فاکتور، ایمیل و گزارش‌های فروش (قبلاً set_price نامرئی بود و مدیر نمی‌دانست چرا سفارش ارزان فروخته شده)؛ همان قواعد (مبنای عادی + سقف تعداد + حداقل ۲) با فیلتر رسمی woocommerce_get_shop_coupon_data؛ کلید برگشت به حالت قدیمی در پنل
* رفع (S-04): حذف وضعیت‌های هاردکد سفارش از هر ۶ نقطه (ماینینگ، انتساب، پیش‌بینی کاربر، گیت آپسل) — فهرست رسمی wc_get_is_paid_statuses() ملاک است؛ فروشگاه با وضعیت سفارشی «پرداخت‌شده» (مثل ارسال‌شده) یا COD مسیر سفارشی دیگر از ماینینج و انتساب حذف نمی‌شود
* رفع (S-05): نوار ارسال رایگان با min_amount واقعی روش ارسال رایگان ووکامرس کار می‌کند (زونِ متناظر با آدرس مشتری؛ بدون تطبیق، محافظه‌کارانه بزرگ‌ترین سقف) — «تبریک! رایگان شد» دیگر در فروشگاه چندمنطقه‌ای دروغ نمی‌گوید؛ کلید برگشت به عدد دستی
* رفع (S-07): پیش‌نمایش قیمت JS با تنظیمات واقعی ووکامرس فرمت می‌شود (اعشار، جداکنندهٔ اعشار و هزارگان، جایگاه نماد ارز) — قبلاً Math.round + fa-IR با wc_price سرور نمی‌خواند
* رفع (S-03): مودال خروج روی موبایل دیگر «کد مرده» نیست — پیش‌فرض روی دستگاه لمسی اصلاً رندر نمی‌شود؛ با کلید جدید، تریگر «اسکرول سریع رو به بالا» فعال می‌شود
* رفع (S-08): متغیرهای CSS از :root سراسری به ریشه‌های خود ویجت‌ها اسکوپ شدند + رنگ متن ویجت‌ها قابل تنظیم شد (قالب‌های دارک دیگر سیاه‌روی‌سیاه نمی‌شوند)
* رفع (S-09): انطباق حریم خصوصی — Exporter/Eraser رسمی وردپرس برای جدول رخدادها ثبت شد؛ «صادرات داده‌های من» و «حذف داده‌های من» حالا این جدول را می‌بینند
* رفع (S-10): endpoint صدور nonce درخواست‌های متقاطع (Origin/Referer خارج از دامنه) را رد می‌کند — مدل امنیتی اکشن‌های nopriv دیگر فقط rate-limit نیست
* جدید (S-06): لاگر متمرکز روی wc_get_logger — شروع/پایان/توقف ماینینگ، حذف پاکسازی، امضای نامعتبر پکیج، رد درخواست متقاطع، ساخت سفارش وابسته؛ همه در وضعیت → گزارش‌های ووکامرس (بدون هیچ دادهٔ شخصی)؛ با فیلتر fws_logging_enabled خاموش‌شدنی

= 2.11.1 =
* رفع (B-33 — بحرانی): قوانین دستی و لیست سیاه از پنل «هرگز ذخیره نمی‌شدند» — زنجیرهٔ وردپرس (register_setting در admin_init → update_option هسته که همیشه sanitize_option را صدا می‌زند → admin-ajax.php که admin_init را اجرا می‌کند) باعث می‌شد sanitize() مقدار جدید manual_rules/product_blacklist را از ورودی بگیرد و با مقدار قدیمی دیتابیس جایگزین کند؛ AJAX «ثبت شد» می‌گفت ولی پس از reload هیچ‌چیز نبود. حالا کلیدهای AJAX‌مالک از ورودی ذخیره می‌شوند (اولویت ورودی) و در فرم تنظیمات از خوانش تازه حفظ می‌شوند — پاکسازی امنیتی هر دو مسیر دست‌نخورده ماند
* تأیید (B-34): نشت wp_unslash مضاعف روی custom_css در خط همین افزونه از نسخهٔ ۲.۱۱ حذف شده بود (options.php یک‌بار unslash می‌کند و persist_key آرایهٔ تمیز می‌فرستد)؛ کاراکترهای بک‌اسلش‌دار CSS مثل content:"\201C" سالم می‌مانند — در این نسخه فقط مستند و بازبینی شد

= 2.11.0 =
* جدید (B-27): محصولات متغیر پشتیبانی شدند — قوانین والدِ متغیر (که ماینر از ووکامرس ثبت می‌کند) دیگر هدر نمی‌روند؛ در ویجت‌های سبد/نوار ارسال/حساب/جستجو/شورت‌کد به‌صورت کارت لینک‌محور «مشاهده و انتخاب گزینه» با قیمت «از X» رندر می‌شوند و در باکس پکیج فقط کالاهای قابل افزودن یک‌کلیکی می‌مانند
* رفع (B-21): صفحهٔ سبدِ بلوکی ووکامرس (پیش‌فرض نصب‌های جدید) — نوار ارسال رایگان و پیشنهادات سبد حالا روی بلوک سبد هم رندر می‌شوند (قبلاً فقط قالب کلاسیک)؛ متن‌های بینش‌ها که علت را به‌غلط «کش صفحه» معرفی می‌کرد اصلاح شد
* رفع (B-05): ساخت تست A/B جدید برای ویجتی که برندهٔ قفل‌شده دارد رد می‌شود (قبلاً تست زامبی ساخته می‌شد که هرگز نتیجه نمی‌گرفت)
* رفع (B-06): هرس سقف ۱۲ تست دیگر برنده‌های قفل‌شده و تاریخچه را پاک نمی‌کند (قفل‌های ظاهری سایت حفظ می‌شوند)؛ فقط قدیمی‌ترین تست‌های متوقف‌شده حذف می‌شوند و در صورت پر بودن سقف، پیام صریح می‌دهد
* رفع (B-10): حذف fws_base_price از دیتای آیتم سبد (در هش ادغام خط سبد مشارکت می‌کرد) + ادغام به‌جای خط تکراری در مسیرهای افزودن افزونه — دیگر سبد خطوط تکراری نمی‌سازد
* رفع (B-24): ردیف‌های خرید هویت خود را از سفارش می‌گیرند نه از درخواست جاری — تغییر وضعیت سفارش توسط ادمین دیگر هش سشن/شناسه ادمین در آمار ثبت نمی‌کند
* رفع (B-26): وایت‌لیست سخت منبع‌های «افزودن سریع» — درخواست دست‌ساز دیگر خرید را به ویجت‌های بدون دکمه (thankyou/exit_modal) منتسب نمی‌کند
* رفع (B-29): قوانین دستی و بلک‌لیست هنگام ذخیرهٔ فرم تنظیمات از خوانش تازهٔ دیتابیس حفظ می‌شوند (مسابقهٔ هم‌زمانی با AJAX ادمین + سناریوی پاک‌شدن کامل)
* رفع (B-30): پیش‌اعتبارسنجی کوپن مودال خروج — وجود، انقضا، ظرفیت، انحصاری‌بودن و اعمال‌شده‌بودن با پیام صریح فارسی
* رفع (B-31): مبنای مالیات آپسل = آدرس مشتریِ سفارش (پارامتر order) نه نرخ پایهٔ فروشگاه — هم در قیمت نمایش و هم در شارژ واقعی
* بهبود (آمار): شمارش سشن/سفارش‌محور در گزارش درآمد و A/B (نرخ افزودن دیگر از ۱۰۰٪ نمی‌گذرد؛ «سفارش منسوب» به تعداد سفارش است نه اقلام) + abs(crc32) در تخصیص نسخه + حداکثر یک تست رنگی هم‌زمان + هدر no-cache هنگام تست فعال (صداقت آماری روی فروشگاه‌های کش‌دار) + آمار کارت برندهٔ تازه فقط از پنجرهٔ واقعی تست
* بهبود (قانون طلایی): کلید سراسری خاموش/روشن شورت‌کدها (پیش‌فرض روشن) + بای‌پسِ فیلتر کامپوننت در رندر مودال خروج بسته شد + تغییر نام محصول کش را پاک می‌کند + برچسب اصلی دکمه‌ها در JS حفظ می‌شود (خراب‌شدن برچسب‌های سفارشی در خطا/bfcache رفع شد) + پاکسازی کلیدهای سشنی افزونه در حذف کامل
* بهبود (ادمین): نتیجهٔ جستجوی محصولات در خطا بی‌صدا خالی نمی‌ماند و فشردن Enter در فیلدهای جستجو دیگر کل تنظیمات را سابمیت نمی‌کند

= 2.10.4 =
* رفع (آمار A/B): برندهٔ تست با «نرخ تبدیل» قفل می‌شود نه تعداد مطلق — قبلاً در آزمون‌های با تقسیم نامساوی ترافیک، برندهٔ اشتباه قفل می‌شد (مثال: A با ۵٪ از ۷۰۰ نمایش در برابر B با ۹٪ از ۳۰۰، به‌غلط A برنده اعلام می‌شد)
* رفع (آمار ماینینگ): مخرج Lift از رفاندها پاک شد — در کلاسیک پست‌های shop_order_refund و در HPOS ردیف‌های type=shop_order_refund از شمارش total_orders حذف شدند؛ همهٔ Liftها دیگر (N+R)/N بزرگ‌نمایی ندارند
* رفع (پایداری): خودترمیمی کرون — اگر رویدادهای کرون پاکسازی روزانه و ماینینگ/پاکسازی هفتگی به هر دلیل حذف شوند، در init خودبه‌خود بازسازی می‌شوند (قبلاً فقط با تغییر نسخهٔ شِما)
* رفع (پاکسازی): حذف یک تست A/B حالا ردیف‌های رخداد همان تست را هم پاک می‌کند (قبلاً یتیم می‌ماندند و جدول را سنگین می‌کردند)؛ پنجرهٔ حذف محدود به عمر همان تست است و به تست‌های بعدی دست نمی‌زند
* رفع (کارایی): تلهٔ autoload — گزینه‌های اصلی (تنظیمات، نسخهٔ دیتابیس، تست‌های A/B) دیگر با اولین نوشتن non-autoload «برای همیشه» گیر نمی‌کنند؛ روی نصب‌های موجود هم یک‌باره مهاجرت می‌شوند (حذف کوئری‌های اضافی هر درخواست)
* رفع (فرانت): پیش‌نمایش قیمت باکس پکیج با قاعدهٔ سرور هم‌رو شد — تخفیف فقط با ۲ قلم یا بیشتر نمایش داده می‌شود؛ با تک‌کالا مبلغ کامل (قبلاً عدد گمراه‌کننده نشان می‌داد)
* بهبود: ایندکس widget_time روی جدول رخدادها (پرس‌وجوهای A/B و پاکسازی سریع‌تر) + نشانهٔ «تحلیل ناقص» در مرگ وسط‌کاری ماینینگ + برش ۱۲۰روزه با ساعت سایت + شمارندهٔ صادق قوانین ماینینگ

= 2.10.3 =
* رفع (داده): سرریز ستون lift_score — قبلاً DECIMAL(5,2) بود و lift بالای ۹۹۹٫۹۹ (فروشگاه‌های کم‌سفارش) در حالت strict کل بچ درج را بی‌صدا شکست می‌داد؛ عریض شد به DECIMAL(10,4) + ارتقای خودکار شِما برای نصب‌های موجود
* رفع (کرون): بازهٔ «weekly» در وردپرس هسته وجود نداشت و پاکسازی هفتگی جدول از ابتدا هرگز زمان‌بندی نمی‌شد — بازه ثبت شد و برای نصب‌های قدیمی هم دوباره ساخته می‌شود
* رفع (کارایی): هر فروش، موجودی (stock_quantity) را کم می‌کرد و کل کش پیشنهادات فرو می‌ریخت (کش همیشه سرد) — حالا فقط تغییر وضعیت موجودی/قیمت/نمایان‌بودن کش را پاک می‌کند؛ فهرست پراپرتی‌ها با فیلتر fws_cache_purge_watched_props قابل‌تغییر است
* رفع (کارایی): حذف‌های بی‌کران (پاکسازی رخدادهای ردیابی، جاروب قوانین کهنه ماینینگ، پاکسازی هفتگی یتیم‌ها) به DELETEهای ۵۰۰۰تایی با بودجهٔ زمانی تبدیل شدند — بدون قفل طولانی و تأخیر ریلیکیشن
* رفع (کارایی): کوئری SHOW TABLES از هر درخواست فرانت حذف شد (کش ۵ دقیقه‌ای در آبجکت‌کش) + گزینهٔ fws_cache_version به autoload=no منتقل شد
* رفع (پایداری): اگر جدول affinity به هر دلیل حذف شده باشد، خودبه‌خود بازسازی می‌شود (خودترمیم با کول‌داون) و ویجت‌ها دیگر خطای SQL نمی‌دهند
* رفع (پایداری): قفل ماینینگ طولانی در هر بچ تازه‌سازی می‌شود تا اجرای سنگین وسط کار منقضی نشود و اجرای موازی شکل نگیرد

= 2.10.2 =
* جدید: تنظیم «سقف تعداد تخفیف‌دار پکیج» — انتخاب صاحب فروشگاه که از هر قلم پکیج چند عدد با تخفیف محاسبه شود (۰ = همهٔ تعداد)
* رفع: درآمد آپسل صفحه تشکر پس از گذار processing هرگز منتسب نمی‌شد (انتساب دلتایی per-item)
* رفع: قیمت آپسل و پکیج هرگز از قیمت فعلی فروشگاه گران‌تر نمی‌شود (clamp با قیمت فروش)
* رفع: endpoint آپسل حالا به کلید قطع enable_widget_thankyou احترام می‌گذارد
* رفع: سقف یک کالای مکمل برای هر سفارش (مانع آپسل زنجیره‌ای) + قفل اتمی ضد درخواست موازی
* رفع: متای fws_source/fws_variant مخفی شد و دیگر در ایمیل و صفحه سفارش مشتری نمایش داده نمی‌شود

= 2.10.1 =
* Fix (dbDelta): the affinity-table CREATE used `IF NOT EXISTS` and `INDEX` keywords — dbDelta cannot diff that format, so future schema changes (new columns/indexes) could silently fail to apply on upgrades. Rewritten in canonical dbDelta form (`CREATE TABLE`, `KEY`, `PRIMARY KEY` spacing).
* Fix (db growth): every widget render inserted one `fws_events` row; high-traffic stores with 180-day retention could reach millions of rows. Impressions are now deduplicated per (session, widget) within a 6-hour window via a dedicated `session_widget_time` index — refreshes and multi-page browsing no longer multiply rows, while clicks/coupons/purchases stay row-per-event. Meaning of "impression" becomes "unique visitor per window" (arguably more accurate).
* Change (default): `tracking_retention_days` default 180 → 60 days (recommended for shared hosting); installs still on the untouched 180 migrate once to 60 — deliberate custom values are preserved. Admin field hint updated.
* Change (default): thank-you one-click upsell now ships disabled for new installs. The upsell appends an item to a REGISTERED order, which can clash with invoicing, accounting and the Iranian Moadian (سامانه مؤدیان) e-invoicing workflow — shop managers must opt in knowingly; a warning is shown next to both the widget toggle and the upsell-discount field. Existing installs keep their current choice.
* New (insight): when mining produced zero rules, the dashboard now explains why — either the WooCommerce Analytics lookup tables are still empty (with the exact rebuild path) or the min-support threshold is not met yet at current order volume (with concrete suggestions). Adds transparency for low-volume stores.
* New (repo hygiene): Plugin URI / Author URI now point to the real GitHub repository (previously a placeholder repo and `example.com`), a full `LICENSE` file (GPL-2.0-or-later) ships with the plugin, and a lightweight GitHub Actions workflow (`php -l` on every file + informational PHPStan) runs on push.
* New (i18n groundwork): `load_plugin_textdomain('fast-woo-sale')` now runs on init; strings remain hardcoded Persian for now (target market), so future translation files work without any loader change.
* Meta: `WC tested up to` bumped 9.3 → 10.3.

= 2.10.0 =
* New: Revenue attribution engine — every widget's funnel (impressions, add-to-carts, attributed orders/revenue) is tracked in a dedicated `fws_events` table and shown in a new dashboard card with 7/30/90-day switches ("how much has this plugin actually sold?"). Organic purchases are never attributed, so the number stays honest.
* New: Live A/B testing for widget titles and accent color. Visitors get a stable variant via the WooCommerce session; when the two-proportion z-test reaches significance (p < 0.05, min 100 impressions per variant) the winner is announced automatically and locked for everyone. Manual stop/publish/lock-release controls included.
* New: Smart insights cards built from real store data only — enabled-but-never-seen widgets, high-impression/zero-conversion widgets, blacklisted products that are actually top complements, low-stock top complements, fresh A/B winners, exit modal shown without coupon applies.
* Privacy & control: tracking master switch, IP anonymization (default on), exclude shop managers, automatic daily cleanup with a configurable retention window (30–365 days). Per-widget tracking follows the existing per-widget display toggles — a disabled widget records nothing. `fws_tracking_enabled` filter for developers.
* New option keys: `tracking_enable`, `tracking_anonymize_ip`, `tracking_exclude_admins`, `tracking_retention_days`. New table `{prefix}fws_events` (auto-created and self-healing); dropped automatically on uninstall.

= 2.9.3 =
* Fix (freshness): recommendation caches held a 24h snapshot of product name/price/image and of the "is recommendable" verdict — price changes, sale start/end, stock-status flips or hiding a product kept widgets outdated for up to 24 hours (the bundle box total could disagree with what the cart actually charged, and one-click buttons stayed dead on sold-out products). Catalog changes through the official WooCommerce hooks now purge the engine cache immediately (products AND variations).
* Fix (cache coherence): after a cache purge, the same request still wrote new cache rows under the OLD cache version — the in-request version/memoization is now reset as part of every purge.
* Fix (settings sync): the engine cache was only flushed on option UPDATE; the very first save (add_option) and full option deletion (reset) now flush it too, so new thresholds take effect immediately on fresh installs.
* Fix (widgets): fws_* shortcodes placed in sidebar widgets (Text / Block / Custom HTML) never loaded the front-end script, leaving every quick-add button in the widget silently dead; active widget options are now scanned as well.
* Fix (mining): reinstalling the plugin while an orphaned cron event from a previous install survived could schedule a duplicate initial mining run; the immediate event is only scheduled when nothing is already pending within the next 5 minutes.
* Cleanup: cart-recommendation cache keys are now order-insensitive (cart [A,B] and [B,A] share one cache row).
* Cleanup: the bundle button restores its server-rendered label exactly (previously the ⚡ emoji was re-added after a click even when emojis were disabled in settings).

= 2.9.2 =
* Fix (roles): shop managers could open the plugin settings page but the "Save" form silently failed with "You need a higher level of permission" — wp-admin/options.php requires manage_options by default; the save capability for this settings group is now aligned with the page (manage_woocommerce).
* Fix (appearance): custom CSS child selectors like ".menu > li" were silently corrupted because the ">" character was stripped along with HTML tags; only "<" is removed now (tags are already stripped and inline CSS cannot escape its <style> container without "<").
* Fix (cart): the min-2-bundle-items rule counted only parent product ids, so two variations of the same parent (possible via the fws_product_recommendations developer filter) were counted as one and the bundle discount was silently dropped; the matched id (parent or variation) is now recorded.
* Fix (search): when matched search products had no affinity rules yet, the indexed aggregate query re-ran on every search page view (twice — banner + injection); empty results are now cached with a short 10-minute TTL.
* Fix (maintenance): the admin "optimize" button's orphan cleanup did not filter by post_type, so rules whose product id had been recycled by a non-product post survived until the weekly cron; now aligned with the miner's weekly cleanup.

= 2.9.1 =
* Fix (mining): the "incomplete mining" admin notice was erased by the very same run that set it (the memory-guard break fell through to the cleanup code) — it now survives until a clean run finishes.
* Fix (mining): re-mining no longer keeps stale affinity rules computed under an older lookback/min_support/min_confidence configuration; after a complete run, only rows refreshed by this run remain (recommendations now truly match the configured analysis window).
* Fix (cart): removing a bundle item and then using Undo no longer silently drops that item's bundle discount — a full signed-id set is kept per session and re-synced on restore; the min-2-items rule still guarantees no discount without two real bundle items.
* Fix (honesty): the free-shipping progress bar now subtracts coupon discounts with the matching tax portion when prices are displayed including tax (previously the remaining amount was overstated on tax-enabled stores).
* Cleanup: corrected a stale comment in the search-recommendation cache (empty match-sets are intentionally persisted with a short TTL).

= 2.9.0 =
* Fix (money): tax-inclusive stores no longer overcharge the thank-you upsell line — item totals are now normalized with wc_get_price_excluding_tax before being written to the order (order line totals are always tax-exclusive).
* Fix (honesty): the struck-through "was" price of the upsell box is now the catalogue regular price, not the current (already discounted) price.
* Fix (data quality): mining now joins the orders table and counts only paid/successful orders (completed/processing) — abandoned/failed/refunded checkouts no longer pollute confidence, lift and frequencies.
* Fix (consistency): the admin "minimum confidence" setting is now honoured in all six recommendation paths (cart, thank-you upsell, user prediction, search banner previously ignored it).
* Fix (UX): the thank-you upsell box is no longer rendered for orders already paid through an online gateway (the button was always rejected server-side).
* Fix (security): rate limiting on shared hosts is now a single atomic INSERT..ON DUPLICATE KEY UPDATE counter (parallel requests can no longer bypass it); the Redis orphan-key edge is handled too.
* Fix: variable/grouped/external products no longer get the bundle box as the SOURCE product either (render + AJAX guard) — no more "2 items added" without the main product.
* Fix: search-term title matching is cached for 10 minutes — the heavy LIKE scan no longer runs on every search page view.
* Fix: style_text no longer blanks a whole label when the source string is invalid UTF-8.
* Fix (PHP 8 fatal): the rate-limit garbage collection query passed a literal % pattern inside prepare() — now passed as a proper argument.
* Fix (money): removing all bundle items except one no longer keeps the 12% bundle discount on the lone item (min-2 rule is enforced at cart recalculation time too).
* Fix: tier-2 -> tier-1 cache promotion no longer extends short-TTL entries to 24h (capped at 15 minutes).
* Improvement: honest free-shipping bar — uses displayed subtotal minus coupon discounts; filler row title no longer claims "to reach free shipping" when no filler bridges the gap; bridging fillers are sorted first.
* Improvement: incomplete mining (memory guard) now leaves an admin notice; cleanups (batch size, dead if(true), unused free_shipping_limit JS param, multisite uninstall note).

= 2.8.1 =
* Fix: settings form was never wrapped in a <form>; "Save settings" now works (Settings API).
* Fix: thank-you upsell now reduces stock when the order stock was already reduced, and refuses to raise the total of an order already paid online (unpaid / offline-gateway orders are redirected to pay).
* Fix: bundle discount applies only to items actually added to the cart.
* Fix: variable/grouped/external products are no longer offered in one-click widgets.
* Fix: search-term cache is keyed on matched product ids (no more transient flooding).
* Fix: mining lock is released on fatal/timeout.
* Fix: search injection only on explicit product searches.
* Fix: recs_limit / min_confidence honoured in cart and free-shipping widgets.
* Fix: stale nonce on cached pages is refreshed automatically.
* Fix: user prediction cache purged on new order; uninstall clears weekly cron; atomic rate limiter; admin JS TypeError; 32-bit crc32; settings memoized per request.

= 2.8.0 =
* Appearance panel: style toggle, presets, palette, typography, custom CSS, live preview.
* Fixed unstyled cart grid and bundle item classes.

See CHANGELOG-*.md files for full history.
