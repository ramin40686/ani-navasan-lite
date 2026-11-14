<?php
if (!defined('ABSPATH')) exit;
// اینجا تنظیمات مدیریتی افزونه قرار می‌گیرد.

/**
 * بارگذاری اسکریپت‌های مخصوص ادمین
 */
function mnswmc_enqueue_admin_scripts($hook) {
    // فقط در صفحات ویرایش/ایجاد محصول (هم‌تراز با محدودهٔ CSS)؛ روی سایر
    // صفحات ویرایش پست (برگه، نوشته و ...) بارگذاری نمی‌شود.
    if ($hook === 'post.php') {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }
    } elseif ($hook === 'post-new.php') {
        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
        if ($post_type !== 'product') {
            return;
        }
    } else {
        return;
    }

    wp_enqueue_script(
        'mnswmc-admin-js',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
        ['jquery'],
        '1.2.0',
        true
    );

    wp_localize_script('mnswmc-admin-js', 'mnswmc_ajax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mnswmc_nonce'),
    ]);
}
add_action('admin_enqueue_scripts', 'mnswmc_enqueue_admin_scripts');

