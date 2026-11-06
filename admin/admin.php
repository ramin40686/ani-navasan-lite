<?php
if (!defined('ABSPATH')) exit;
// اینجا تنظیمات مدیریتی افزونه قرار می‌گیرد.

/**
 * بارگذاری اسکریپت‌های مخصوص ادمین
 */
function mnswmc_enqueue_admin_scripts($hook) {
    // فقط در صفحات ویرایش محصول
    if ($hook === 'post.php' || $hook === 'post-new.php') {
        wp_enqueue_script(
            'mnswmc-admin-js',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('mnswmc-admin-js', 'mnswmc_ajax', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mnswmc_nonce'),
        ]);
    }
}
add_action('admin_enqueue_scripts', 'mnswmc_enqueue_admin_scripts');

