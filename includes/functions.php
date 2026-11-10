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

    // همگام‌سازی قیمت فعال (_price) محصولات متغیر والد با حداقل قیمت وریشن‌ها
    foreach (array_keys($parent_ids) as $parent_id) {
        $parent = wc_get_product($parent_id);
        if ($parent && $parent->is_type('variable')) {
            $min_price = $parent->get_variation_price('min');
            if ($min_price !== '' && $min_price !== null) {
                $parent->set_price($min_price);
                $parent->save();
            }
        }
    }
}, 10, 2);