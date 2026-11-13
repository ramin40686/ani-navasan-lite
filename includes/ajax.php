<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_mnswmc_get_exchange_rate', function () {
    if (! isset($_POST['_ajax_nonce']) || ! wp_verify_nonce($_POST['_ajax_nonce'], 'mnswmc_nonce')) {
        wp_send_json_error(['message' => 'Nonce verification failed'], 403);
    }

    if (! current_user_can('edit_products')) {
        wp_send_json_error(['message' => 'Insufficient permissions'], 403);
    }

    $currency_id = isset($_POST['currency_id']) ? intval($_POST['currency_id']) : 0;
    if (! $currency_id) {
        wp_send_json_error(['message' => 'Currency ID not provided'], 400);
    }

    $currency_post = get_post($currency_id);
    if (! $currency_post || $currency_post->post_type !== 'mnswmc') {
        wp_send_json_error(['message' => 'Currency not found'], 404);
    }

    $rate   = ani_navasan_get_currency_rate($currency_id);
    $symbol = get_post_meta($currency_id, '_mnswmc_symbol', true);
    $title  = $currency_post->post_title;

    if ($rate <= 0) {
        wp_send_json_error(['message' => 'Rate not available'], 404);
    }

    wp_send_json_success([
        'currency_id'     => $currency_id,
        'currency_title'  => $title,
        'currency_symbol' => $symbol,
        'rate'            => $rate
    ]);
});
