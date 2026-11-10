<?php
if (!defined('ABSPATH')) exit;

/**
 * فایل functions.php
 *
 * این فایل مخصوص قرار دادن توابع کمکی افزونه است.
 * مثال: دریافت نرخ ارز از دیتابیس، محاسبات قیمت،
 * یا هر فانکشنی که در چند جای افزونه استفاده می‌شود.
 */

/**
 * 🟢 گرد کردن قیمت تومانی به نزدیک‌ترین ۱۰۰۰ تومان
 *
 * سیاست گرد کردن نهایی: تمام قیمت‌های تومانی نهایی محاسبه‌شده توسط این افزونه
 * باید به نزدیک‌ترین ۱۰۰۰ تومان گرد شوند.
 *
 * @param float|int $value مقدار قیمت تومانی
 * @return int قیمت گرد شده به نزدیک‌ترین ۱۰۰۰ تومان
 */
function ani_navasan_round_to_thousand($value) {
    return (int) round((float) $value / 1000) * 1000;
}

/**
 * 🟢 بروزرسانی قیمت تومانی محصولات برای یک ارز مشخص
 */
add_action('ani_navasan_lite_recalc_currency', function ($currency_id, $rate = null) {
    global $wpdb;

    // اگر نرخ پاس داده نشده، از متای خود ارز بخون
    if ($rate === null) {
        $rate = (float) get_post_meta($currency_id, '_mnswmc_rate', true);
    }

    // همه محصولات (ساده + وریشن) که به این ارز وابسته‌اند
    $query = "
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_mnswmc_currency_id'
          AND meta_value = %d
    ";
    $product_ids = $wpdb->get_col($wpdb->prepare($query, $currency_id));

    if (!$product_ids) return;

    $parent_ids = [];

    foreach ($product_ids as $pid) {
        $active = get_post_meta($pid, '_mnswmc_active', true);
        if ($active !== 'yes') continue;

        $product = wc_get_product($pid);
        if (!$product) continue;

        $price_currency = (float) get_post_meta($pid, '_mnswmc_regular_price', true);
        $floor_currency = (float) get_post_meta($pid, '_torob_scraper_min_price_currency', true);

        $needs_save = false;

        if ($price_currency > 0) {
            $price_tmn = ani_navasan_round_to_thousand($price_currency * $rate);
            // استفاده از API ووکامرس (CRUD) برای همگام‌سازی صحیح قیمت فعال (_price)
            // بر اساس وضعیت واقعی تخفیف (is_on_sale) و حفظ قیمت تخفیف (_sale_price).
            // این کار کاش‌ها و جداول lookup ووکامرس را نیز به‌روزرسانی می‌کند.
            $product->set_regular_price($price_tmn);
            $needs_save = true;
        }

        if ($floor_currency > 0) {
            $floor_tmn = ani_navasan_round_to_thousand($floor_currency * $rate);
            $product->update_meta_data('_torob_scraper_min_price', $floor_tmn);
            $needs_save = true;
        }

        if ($needs_save) {
            $product->save();
        }

        // ثبت شناسه محصولات متغیر والد برای همگام‌سازی بازه قیمت
        if ($product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $parent_ids[$parent_id] = true;
            }
        }
    }

    // همگام‌سازی قیمت فعال (_price) محصولات متغیر والد با وریشن‌ها
    // استفاده از API استاندارد ووکامرس (WC_Product_Variable::sync) به‌جای set_price + save:
    // - set_price + save برای محصولات متغیر، متای _price را به‌روزرسانی نمی‌کند
    //   (چون _price در meta_key_to_props نیست و handle_updated_props آن را برای
    //   محصولات متغیر/گروهی نادیده می‌گیرد).
    // - WC_Product_Variable::sync متای _price را به‌صورت چند ردیف (یک ردیف برای هر
    //   قیمت متمایز وریشن) به‌روزرسانی می‌کند، جدول lookup (wc_product_meta_lookup)
    //   را همگام می‌سازد، وضعیت موجودی را همگام می‌کند و کاش‌ها را پاک می‌کند.
    //
    // با $save = false از ذخیره کامل محصول (product save) خودداری می‌شود:
    // - sync_price() متای _price و جدول lookup را مستقیماً در دیتابیس به‌روزرسانی می‌کند
    //   (بدون نیاز به save).
    // - sync_attributes() برای محصولات مدرن (نسخه >= 2.4.0) بی‌اثر است.
    // - sync_stock_status() فقط مقدار حافظه‌ای را تنظیم می‌کند؛ چون در بازمحاسبه قیمت
    //   وضعیت موجودی تغییر نمی‌کند، نیازی به ذخیره آن نیست.
    // - اجتناب از save کامل، از اجرای مجدد هوک‌های ذخیره محصول و بازنویسی
    //   _product_attributes (که ریسک double-serialization دارد) جلوگیری می‌کند.
    // چون sync_price() کاش‌ها را پاک نمی‌کند، کاش‌های مربوطه به‌صورت صریح پاک می‌شوند.
    foreach (array_keys($parent_ids) as $parent_id) {
        $parent = wc_get_product($parent_id);
        if ($parent && $parent->is_type('variable')) {
            WC_Product_Variable::sync($parent, false);
            wc_delete_product_transients($parent_id);
            WC_Cache_Helper::invalidate_cache_group('product_' . $parent_id);
            clean_post_cache($parent_id);
        }
    }
}, 10, 2);