<?php
/**
 * Plugin Name: نوسان آنی لایت (Ani Navasan Lite)
 * Description: نسخه لایت افزونه نوسان برای مدیریت فروش ارزی ووکامرس
 * Version: 1.2.1
 * Author: ramin4068@gmail.com
 * Text Domain: ani-navasan-lite
 * Requires at least: 7.0.8
 * Requires PHP: 7.4
 * Tested up to: 7.1
 * WC requires at least: 9.6.2
 * WC tested up to: 11.2.2
 */

if (!defined('ABSPATH')) exit;

/**
 * جلوگیری از اجرای همزمان با افزونه نوسان اصلی
 */
add_action('admin_init', function () {
    $main_plugin = 'mns-woocommerce-rate-based-products/mns-woocommerce-rate-based-products.php';

    if (is_plugin_active($main_plugin)) {
        // غیرفعال کردن افزونه لایت
        deactivate_plugins(plugin_basename(__FILE__));

        // نمایش پیام خطا به مدیر
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    ❌ افزونه <strong>نوسان آنی لایت</strong> غیرفعال شد، زیرا افزونه <strong>نوسان اصلی</strong> فعال است. 
                    برای جلوگیری از تداخل، فقط یکی از این دو افزونه می‌تواند همزمان فعال باشد.
                </p>
            </div>
            <?php
        });
    }
});

/**
 * پیام فعال‌سازی (فقط یک بار بعد از فعال‌سازی)
 */
register_activation_hook(__FILE__, function () {
    update_option('ani_navasan_lite_activated', 1);

    // مهاجرت‌های یک‌باره به قرارداد داده‌ای افزونه اصلی
    require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
    if (function_exists('ani_navasan_lite_migrate_rate_key')) {
        ani_navasan_lite_migrate_rate_key();
    }
    if (function_exists('ani_navasan_lite_migrate_dependence_type')) {
        ani_navasan_lite_migrate_dependence_type();
    }
});

// پیام فعال‌سازی (فقط یک بار) در includes/init.php ثبت و نمایش داده می‌شود؛
// این‌جا تکرار نمی‌شود تا منطق در یک نقطهٔ واحد باقی بماند.

/**
 * ادامه‌ی کدهای افزونه (فقط اگر افزونه اصلی فعال نباشد)
 */
add_action('plugins_loaded', function () {
    if (!is_plugin_active('mns-woocommerce-rate-based-products/mns-woocommerce-rate-based-products.php')) {
        require_once plugin_dir_path(__FILE__) . 'includes/init.php';
    }
});
