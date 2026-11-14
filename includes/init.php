<?php
if (!defined('ABSPATH')) exit;

// بارگذاری فایل‌های داخلی افزونه
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'hooks.php';
require_once plugin_dir_path(__FILE__) . '../admin/admin.php';
require_once plugin_dir_path(__FILE__) . '../admin/metabox.php';
require_once plugin_dir_path(__FILE__) . 'currencies-cpt.php';
require_once plugin_dir_path(__FILE__) . 'currencies-metabox.php';
require_once plugin_dir_path(__FILE__) . 'ajax.php';

// اجرای هوک اولیه فقط بار اول بعد از فعال‌سازی
add_action('admin_init', function () {
    $activated = get_option('ani_navasan_lite_activated', 0);
    if ($activated == 1) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>✅ افزونه <strong>نوسان آنی لایت</strong> فعال شد و آماده استفاده است.</p>
            </div>
            <?php
        });
        update_option('ani_navasan_lite_activated', 0); // دیگه نیاد
    }
});

// بارگذاری CSS اختصاصی افزونه فقط در پیشخوان
add_action('admin_enqueue_scripts', function ($hook) {
    // فقط در صفحات محصول ووکامرس
    if ($hook === 'post.php' || $hook === 'post-new.php') {
        global $post;
        if ($post && $post->post_type === 'product') {
            wp_enqueue_style(
                'ani-navasan-lite-admin',
                plugin_dir_url(__FILE__) . '../assets/css/admin.css',
                array(),
                '1.2.0'
            );
        }
    }
});

