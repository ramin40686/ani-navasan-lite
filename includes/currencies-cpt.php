<?php
if (!defined('ABSPATH')) exit;

/**
 * ثبت CPT برای ارزها (mnswmc)
 */
add_action('init', function () {
    $labels = [
        'name'               => 'ارزها',
        'singular_name'      => 'ارز',
        'menu_name'          => 'مدیریت ارز نوسان لایت',
        'name_admin_bar'     => 'ارز',
        'add_new'            => 'افزودن ارز جدید',
        'add_new_item'       => 'افزودن ارز جدید',
        'new_item'           => 'ارز جدید',
        'edit_item'          => 'ویرایش ارز',
        'view_item'          => 'مشاهده ارز',
        'all_items'          => 'همه ارزها',
        'search_items'       => 'جستجوی ارز',
        'not_found'          => 'ارزی یافت نشد',
        'not_found_in_trash' => 'در زباله‌دان ارزی یافت نشد',
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-money-alt',
        'supports'           => ['title'],
        'has_archive'        => false,
        'rewrite'            => false,
        'capability_type'    => 'post',
    ];

    register_post_type('mnswmc', $args);
});
