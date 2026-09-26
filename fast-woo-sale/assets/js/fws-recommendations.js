/**
 * Fast Woo Predictive Recommendations Frontend Script
 */
(function($) {
  'use strict';

  function showToast(message, isError) {
    $('.fws-toast').remove();
    // امن: متن به‌صورت text-node تزریق می‌شود، نه HTML (محتوای پیام سرور قابل اعتماد نیست)
    var $toast = $('<div class="fws-toast" dir="rtl"></div>').text(message || '');
    if (isError) $toast.addClass('is-error');
    $('body').append($toast);
    setTimeout(function() {
      $toast.addClass('is-visible');
    }, 10);
    setTimeout(function() {
      $toast.removeClass('is-visible');
      setTimeout(function() { $toast.remove(); }, 350);
    }, 3500);
  }

  /**
   * POST helper with one automatic retry on an expired nonce (BUG-10 fix).
   * Pages served from a full-page cache carry a stale nonce; WordPress then answers
   * 403 / -1. We fetch a fresh nonce once and replay the request transparently.
   */
  function fwsPost(data, done) {
    data.nonce = fws_params.nonce;
    var jq = $.post(fws_params.ajax_url, data, done);
    var deferred = $.Deferred();
    jq.done(function(res) { deferred.resolve(res); }).fail(function(xhr) {
      var expired = xhr && (xhr.status === 403 || xhr.status === 400 || (xhr.responseText || '').trim() === '-1');
      if (!expired) { deferred.reject(xhr); return; }
      $.post(fws_params.ajax_url, { action: 'fws_refresh_nonce' }).done(function(r) {
        if (!(r && r.success && r.data && r.data.nonce)) { deferred.reject(xhr); return; }
        fws_params.nonce = r.data.nonce;
        data.nonce = r.data.nonce;
        $.post(fws_params.ajax_url, data, done).done(function(res) { deferred.resolve(res); })
          .fail(function(x) { deferred.reject(x); });
      }).fail(function() { deferred.reject(xhr); });
    });
    return deferred.promise();
  }

  /**
   * نسخهٔ ۲.۱۰.۴ (B-23): محاسبهٔ همگام با قاعدهٔ سرور.
   * سرور (fast-woo-sale.php) تخفیف پکیج را فقط وقتی اعمال می‌کند که دست‌کم «۲ قلم واقعی»
   * از اقلام پکیج در سبد باشد (count(unique hits) >= 2). پیش‌نمایش قبلی با هر تعداد
   * چک‌خورده — حتی تک‌کالا — تخفیف نشان می‌داد و عددِ نمایش‌داده‌شده با مبلغ واقعیِ
   * سبد نمی‌خواند. حالا: با کمتر از ۲ قلم، مبلغ کامل و بدون تخفیف نمایش داده می‌شود.
   */
  /**
   * نسخهٔ ۲.۱۲ (S-07): فرمت قیمت هم‌رو با ووکامرس — اعشار، جداکنندهٔ اعشار،
   * جداکنندهٔ هزارگان و جایگاه نماد ارز از تنظیمات خود ووکامرس (wp_localize_script)
   * خوانده می‌شود؛ قبلاً Math.round + fa-IR عدد را گِرد و جداکننده‌ها و جایگاه
   * نماد را نادیده می‌گرفت و پیش‌نمایش با wc_price سرور نمی‌خواند.
   * fallback: اگر پارامترها نبودند، همان رفتار قدیمی.
   */
  function fwsFormatPrice(amount) {
    var f = (typeof fws_params.price_format === 'object' && fws_params.price_format) || null;
    if (!f) {
      var sym0 = fws_params.currency_symbol || 'تومان';
      return Math.round(Number(amount) || 0).toLocaleString('fa-IR') + ' ' + sym0;
    }
    var decimals = parseInt(f.decimals, 10);
    if (isNaN(decimals) || decimals < 0 || decimals > 6) decimals = 0;
    var value = Number(amount) || 0;
    var neg = value < 0;
    value = Math.abs(value);
    var parts = value.toFixed(decimals).split('.');
    var thousandSep = (typeof f.thousand_sep === 'string') ? f.thousand_sep : ',';
    var decimalSep = (typeof f.decimal_sep === 'string') ? f.decimal_sep : '.';
    var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
    var out = (decimals > 0) ? intPart + decimalSep + parts[1] : intPart;
    var symbol = (typeof f.symbol === 'string' && f.symbol !== '') ? f.symbol : (fws_params.currency_symbol || 'تومان');
    var pos = f.position || 'right_space';
    switch (pos) {
      case 'left': out = symbol + out; break;
      case 'left_space': out = symbol + '\u00A0' + out; break;
      case 'right': out = out + symbol; break;
      case 'right_space':
      default: out = out + '\u00A0' + symbol; break;
    }
    return (neg ? '\u2212' : '') + out;
  }

  function fwsRecalcBundle($wrap) {
    if (!$wrap || !$wrap.length) return;
    var $checked = $wrap.find('.fws-check-item:checked');
    var total = 0;
    $checked.each(function() {
      total += parseFloat($(this).data('price')) || 0;
    });
    var discountPercent = parseFloat(fws_params.bundle_discount) || 0;
    var eligible = $checked.length >= 2 && discountPercent > 0;
    var discounted = eligible ? total * ((100 - discountPercent) / 100) : total;

    $wrap.find('.fws-original-price').text(fwsFormatPrice(total));
    $wrap.find('.fws-discounted-price').text(fwsFormatPrice(discounted));
  }

  $(document).ready(function() {
    // Checkbox toggle: recalculate bundle price dynamically (scoped to each bundle widget)
    $(document).on('change', '.fws-check-item', function() {
      fwsRecalcBundle($(this).closest('.fws-bundle-wrapper'));
    });

    // نسخهٔ ۲.۱۰.۴ (B-23): بازمحاسبهٔ یک‌بارهٔ همهٔ باکس‌ها هنگام بارگذاری تا پیش‌نمایش
    // اولیه هم در حالت‌های خاص (مثلاً بازگشت از bfcache) با قاعدهٔ سرور هم‌رو بماند.
    $('.fws-bundle-wrapper').each(function() {
      fwsRecalcBundle($(this));
    });

    // Add Bundle to Cart via Ajax (scoped to the clicked widget + signed payload)
    $(document).on('click', '.fws-add-bundle-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      var $wrap = $btn.closest('.fws-bundle-wrapper');
      var productIds = [];

      $wrap.find('.fws-check-item:checked').each(function() {
        productIds.push($(this).val());
      });

      if (productIds.length === 0) {
        showToast('لطفاً حداقل یک محصول از پکیج را انتخاب کنید.', true);
        return;
      }

      // نسخهٔ ۲.۹.۳ — برچسب اصلی قبل از هر تغییری ذخیره می‌شود تا بعد از عملیات،
      // دقیقاً همان برچسب رندرشدهٔ سرور (بدون ایموجی، اگر ادمین خاموش کرده) بازیابی شود.
      if (!$btn.data('fws-orig-label')) {
        $btn.data('fws-orig-label', $btn.text());
      }
      $btn.prop('disabled', true).text('در حال افزودن به سبد...');

      fwsPost({
        action: 'fws_add_bundle',
        product_ids: productIds,
        main_id: $btn.data('main-id'),
        bundle_ids: $btn.data('bundle-ids'),
        bundle_sig: $btn.data('bundle-sig')
      }, function(res) {
        $btn.prop('disabled', false).text($btn.data('fws-orig-label'));
        if (res.success) {
          showToast('✓ ' + fws_params.added_text, false);
          if (res.data && res.data.fragments) {
            $.each(res.data.fragments, function(key, val) {
              $(key).replaceWith(val);
            });
          }
          $(document.body).trigger('wc_fragment_refresh');
          $(document.body).trigger('added_to_cart', [res.data ? res.data.fragments : null, res.data ? res.data.cart_hash : null, $btn]);
          if (res.data.cart_url) {
            setTimeout(function() {
              window.location.href = res.data.cart_url;
            }, 600);
          }
        } else {
          showToast(res.data && res.data.message ? res.data.message : 'خطا در افزودن پکیج به سبد', true);
        }
      }).fail(function() {
        $btn.prop('disabled', false).text('تلاش مجدد');
        showToast('خطای ارتباط با سرور. لطفاً صفحه را تازه‌سازی نمایید.', true);
      });
    });

    // Quick Add Single Product (Free Shipping Fillers & Cart Recs)
    // نسخه ۲.۱۰: زمینه ویجت مبدا (data-fws-widget) برای گزارش درآمد ارسال می‌شود
    // نسخهٔ ۲.۱۱ (B-27): سلکتور فقط «button» — کارت‌های متغیر با کلاس یکسان اما تگ
    // <a> رندر می‌شوند (لینک صفحهٔ محصول) و نباید هواکآجف شوند.
    // نسخهٔ ۲.۱۱: برچسب اصلی هر دکمه در نخستین تعامل ذخیره و در خطا/نقص برمی‌گردد
    // (قبلاً رشتهٔ ثابت «+ افزودن» برچسب سفارشیِ احتمالی ادمین را خراب می‌کرد).
    $(document).on('click', 'button.fws-quick-add-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      var pid = $btn.data('product-id');
      var srcWidget = $btn.closest('[data-fws-widget]').data('fws-widget') || '';
      if (!$btn.data('fws-orig-label')) {
        $btn.data('fws-orig-label', $btn.text());
      }
      $btn.prop('disabled', true).text('...');

      fwsPost({
        action: 'fws_add_single',
        product_id: pid,
        fws_widget: String(srcWidget)
      }, function(res) {
        if (res.success) {
          $btn.prop('disabled', false).text('✓ افزوده شد');
          showToast(res.data && res.data.message ? res.data.message : 'کالای مکمل با موفقیت به سبد خرید افزوده شد.', false);
          if (res.data && res.data.fragments) {
            $.each(res.data.fragments, function(key, val) {
              $(key).replaceWith(val);
            });
          }
          $(document.body).trigger('wc_fragment_refresh');
          $(document.body).trigger('added_to_cart', [res.data ? res.data.fragments : null, res.data ? res.data.cart_hash : null, $btn]);
        } else {
          $btn.prop('disabled', false).text($btn.data('fws-orig-label') || '+ افزودن');
          showToast(res.data && res.data.message ? res.data.message : 'خطا در افزودن کالا.', true);
        }
      }).fail(function() {
        $btn.prop('disabled', false).text($btn.data('fws-orig-label') || '+ افزودن');
        showToast('خطا در افزودن کالا.', true);
      });
    });

    // Thank You Page 1-Click Upsell
    $(document).on('click', '.fws-thankyou-claim-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      var orderId  = $btn.data('order-id');
      var orderKey = $btn.data('order-key');
      var pid      = $btn.data('product-id');
      // نسخهٔ ۲.۱۲.۲ (B-44): امضای HMAC رندر — باکسِ دیده‌شده مستقیماً معتبر می‌شود و
      // تغییر کاندیدا بین رندر و کلیک دیگر پیام غلط «جزو پیشنهادها نیست» نمی‌سازد.
      var upsellSig = $btn.data('upsell-sig') || '';

      $btn.prop('disabled', true).text('در حال ثبت به سفارش...');

      fwsPost({
          action: 'fws_thankyou_upsell',
          order_id: orderId,
          order_key: orderKey,
          product_id: pid,
          upsell_sig: String(upsellSig)
      }).done(function(res) {
          if (res.success) {
            $btn.text('🎉 با موفقیت به سفارش افزوده شد').css({'background': '#047857', 'border-color': '#047857'});
            showToast(res.data && res.data.message ? res.data.message : '🎉 کالا با موفقیت به سفارش شما افزوده شد.', false);
            if (res.data && res.data.pay_url) {
              // Order still needs payment: send the customer to pay the updated total.
              setTimeout(function() { window.location.href = res.data.pay_url; }, 1200);
            }
          } else {
            showToast(res.data && res.data.message ? res.data.message : 'امکان ثبت سفارش وجود ندارد.', true);
            $btn.prop('disabled', false).text('تلاش مجدد');
          }
      }).fail(function() {
          showToast('خطای ارتباط با سرور. لطفاً صفحه را تازه‌سازی کنید.', true);
          $btn.prop('disabled', false).text('تلاش مجدد');
      });
    });

    // Exit-Intent Modal: apply the admin-configured real coupon, then go to checkout
    $(document).on('click', '.fws-modal-coupon-btn', function(e) {
      e.preventDefault();
      var $btn = $(this);
      $btn.prop('disabled', true).text('در حال اعمال تخفیف...');

      fwsPost({
        action: 'fws_apply_exit_coupon'
      }, function(res) {
        if (res.success && res.data && res.data.checkout_url) {
          showToast(res.data.message || 'کد تخفیف اعمال شد.', false);
          setTimeout(function() {
            window.location.href = res.data.checkout_url;
          }, 500);
        } else {
          showToast(res.data && res.data.message ? res.data.message : 'اعمال تخفیف ممکن نشد.', true);
          $btn.prop('disabled', false).text('ادامه خرید بدون تخفیف');
        }
      }).fail(function() {
        showToast('خطای ارتباط با سرور. لطفاً صفحه را تازه‌سازی نمایید.', true);
        $btn.prop('disabled', false).text('تلاش مجدد');
      });
    });

    // Exit Intent Handler (with safe private-browsing storage support)
    // نسخهٔ ۲.۱۲ (S-03): تریگر mouseleave فقط در دسکتاپ معنا دارد؛ در دستگاه لمسی
    // اگر کلید exit_intent_mobile روشن باشد، «اسکرول سریع رو به بالا» تریگر می‌شود.
    var exitShown = false;
    try {
      exitShown = !!sessionStorage.getItem('fws_exit_shown');
    } catch(e) {
      exitShown = false;
    }

    var $exitModal = $('#fws-exit-intent-modal');

    function fwsShowExitModal() {
      var alreadyShown = false;
      try { alreadyShown = !!sessionStorage.getItem('fws_exit_shown'); } catch(err) {}
      if (alreadyShown) return;
      try { sessionStorage.setItem('fws_exit_shown', '1'); } catch(err) {}
      $exitModal.fadeIn(200);
      // نسخه ۲.۱۰: ثبت نمایش واقعی مودال خروج در گزارش درآمد (بیکن سبک، بی‌صدا)
      if (fws_params.tracking_enable === 'yes') {
        fwsPost({ action: 'fws_track_event', track_type: 'exit_shown' });
      }
    }

    if (!exitShown && $exitModal.length > 0) {
      var isTouchDevice = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
      if (!isTouchDevice) {
        $(document).on('mouseleave', function(e) {
          if (e.clientY < 20) {
            fwsShowExitModal();
          }
        });
      } else if (fws_params.exit_intent_mobile === 'yes') {
        // تریگر موبایل: اسکرول سریع رو به بالا (حدود ۳۵۰px در کمتر از ۹۰۰ms)
        // نشانهٔ رفتاری رایج «قصد ترک صفحه» در دستگاه‌های لمسی است.
        var lastY = window.pageYOffset || document.documentElement.scrollTop || 0;
        var upward = 0;
        var firstUpTs = 0;
        $(window).on('scroll.fwsExit', function() {
          var y = window.pageYOffset || document.documentElement.scrollTop || 0;
          var dy = lastY - y; // مثبت = رو به بالا
          lastY = y;
          var now = Date.now();
          if (dy > 0) {
            if (!firstUpTs) firstUpTs = now;
            if (now - firstUpTs > 900) { upward = dy; firstUpTs = now; }
            else { upward += dy; }
            if (upward >= 350) {
              $(window).off('scroll.fwsExit');
              fwsShowExitModal();
            }
          } else {
            upward = 0;
            firstUpTs = 0;
          }
        });
      }
    }

    // Dismiss modal via close button or dismiss action
    $(document).on('click', '.fws-modal-close, .fws-modal-dismiss-btn', function() {
      $('#fws-exit-intent-modal').fadeOut(200);
    });

    // Dismiss on clicking modal backdrop outside content box
    $(document).on('click', '#fws-exit-intent-modal', function(e) {
      if ($(e.target).is('#fws-exit-intent-modal')) {
        $('#fws-exit-intent-modal').fadeOut(200);
      }
    });

    // Dismiss on pressing ESC key
    $(document).on('keydown', function(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        if ($('#fws-exit-intent-modal').is(':visible')) {
          $('#fws-exit-intent-modal').fadeOut(200);
        }
      }
    });

    // Reset button states on bfcache navigation (browser back/forward)
    // نسخهٔ ۲.۱۲.۴ (F-14): فقط دکمه‌هایی که واقعاً با آن‌ها تعامل شده (برچسب اصلی‌شان
    // ذخیره شده) بازنشانده می‌شوند؛ قبلاً «همهٔ» دکمه‌ها بازنویسی می‌شدند و دکمه‌های
    // دست‌نخورده با برچسب جایگزین «پیام موفقیت» (⚡ پکیج با موفقیت اضافه شد) یا برچسب
    // ثابت «+ افزودن» جای متن اصلی سرور (افزودن فوری/سریع و…) جایگزین می‌شدند.
    window.addEventListener('pageshow', function(event) {
      if (event.persisted) {
        $('.fws-add-bundle-btn, button.fws-quick-add-btn').each(function() {
          var $b = $(this);
          var orig = $b.data('fws-orig-label');
          if (orig) {
            $b.prop('disabled', false).text(orig);
          }
        });
      }
    });
  });
})(jQuery);
