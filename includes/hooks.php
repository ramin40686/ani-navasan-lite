<?php
if (!defined('ABSPATH')) exit;

/**
 * فایل hooks.php
 *
 * این فایل مخصوص ثبت اکشن‌ها و فیلترهای افزونه است.
 * اگر در آینده بخوای اکشن یا فیلترهای ووکامرس/وردپرس رو تغییر بدی،
 * کدها رو اینجا قرار بده.
 */

/**
 * 🟢 ذخیره مقادیر ارزی با اعشار (تا دو رقم)
 */
add_action('save_post_product', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $currency_fields = [
        '_mnswmc_regular_price',
        '_torob_scraper_min_price_currency'
    ];

    foreach ($currency_fields as $field) {
        if (isset($_POST[$field])) {
            $value = floatval($_POST[$field]);
            $value = round($value, 2);
            update_post_meta($post_id, $field, $value);
        }
    }
});
