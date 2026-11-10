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

    foreach ($product_ids as $pid) {
        $active = get_post_meta($pid, '_mnswmc_active', true);
        if ($active !== 'yes') continue;

        $price_currency = (float) get_post_meta($pid, '_mnswmc_regular_price', true);
        $floor_currency = (float) get_post_meta($pid, '_torob_scraper_min_price_currency', true);

        if ($price_currency > 0) {
            $price_tmn = ani_navasan_round_to_thousand($price_currency * $rate);
            update_post_meta($pid, '_regular_price', $price_tmn);

            // storefront از _price (قیمت فعال) می‌خواند؛ اگر محصول در حال تخفیف نیست،
            // _price باید با _regular_price هم‌خوانی داشته باشد.
            $sale_price = get_post_meta($pid, '_sale_price', true);
            $is_on_sale = ($sale_price !== '' && $sale_price !== null && (float) $sale_price > 0);
            if (!$is_on_sale) {
                update_post_meta($pid, '_price', $price_tmn);
            }
        }

        if ($floor_currency > 0) {
            $floor_tmn = ani_navasan_round_to_thousand($floor_currency * $rate);
            update_post_meta($pid, '_torob_scraper_min_price', $floor_tmn);
        }
    }
}, 10, 2);