<?php
if (!defined('ABSPATH')) exit;

/**
 * 📌 متاباکس اطلاعات ارز برای CPT: mnswmc
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'mnswmc_meta',
        'مشخصات ارز',
        'ani_navasan_lite_currency_meta_box',
        'mnswmc',
        'normal',
        'default'
    );
});

/**
 * 🔹 محتوای متاباکس ارز
 */
function ani_navasan_lite_currency_meta_box($post) {
    // nonce برای امنیت
    wp_nonce_field('mnswmc_meta_save', 'mnswmc_meta_nonce');

    // مقادیر ذخیره شده قبلی
    $symbol = get_post_meta($post->ID, '_mnswmc_symbol', true); // مثل AED, USD
    $rate   = get_post_meta($post->ID, '_mnswmc_rate', true);   // نرخ ارز به تومان
    ?>
    <p>
        <label for="mnswmc_symbol"><strong>نماد اختصاری (ISO):</strong></label>
        <input type="text" id="mnswmc_symbol" name="mnswmc_symbol"
               value="<?php echo esc_attr($symbol); ?>"
               placeholder="مثال: AED" class="widefat">
        <small>مثال: USD, AED, EUR</small>
    </p>

    <p>
        <label for="mnswmc_rate"><strong>نرخ ارز (به تومان):</strong></label>
        <input type="number" step="0.01" id="mnswmc_rate" name="mnswmc_rate"
               value="<?php echo esc_attr($rate); ?>" class="widefat">
        <small>مقدار هر واحد ارز انتخابی به تومان (مثلاً 15000 تومان)</small>
    </p>

    <p>
        <label><strong>نماد مرجع ووکامرس:</strong></label><br>
        <input type="text" value="تومان" readonly class="widefat">
        <small>این مقدار ثابت است و قابل تغییر نیست.</small>
    </p>
    <?php
}

/**
 * 🔹 ذخیره‌سازی داده‌های متاباکس + شروع بروزرسانی محصولات ارزی
 */
add_action('save_post_mnswmc', function ($post_id) {
    // بررسی nonce
    if (!isset($_POST['mnswmc_meta_nonce']) || !wp_verify_nonce($_POST['mnswmc_meta_nonce'], 'mnswmc_meta_save')) {
        return;
    }

    // بررسی مجوز
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // ذخیره نماد اختصاری
    if (isset($_POST['mnswmc_symbol'])) {
        update_post_meta($post_id, '_mnswmc_symbol', sanitize_text_field($_POST['mnswmc_symbol']));
    }

    // ذخیره نرخ ارز
    if (isset($_POST['mnswmc_rate'])) {
        $rate = floatval($_POST['mnswmc_rate']);
        update_post_meta($post_id, '_mnswmc_rate', $rate);

        // 📌 بعد از ذخیره نرخ، بروزرسانی محصولات همین ارز
        if ($rate > 0) {
            do_action('ani_navasan_lite_recalc_currency', $post_id, $rate);
        }
    }
});
