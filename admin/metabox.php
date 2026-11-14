<?php
if (!defined('ABSPATH')) exit;

/**
 * 🔹 واکشی گزینه‌های ارز از CPT
 */
function ani_navasan_get_currency_options($for_select = false) {
    $currencies = get_posts([
        'post_type'      => 'mnswmc',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC'
    ]);

    $options = [];

    foreach ($currencies as $currency) {
        $rate   = ani_navasan_get_currency_rate($currency->ID);
        $label  = $currency->post_title;

        if ($for_select) {
            // برای select ووکامرس فقط label
            $options[$currency->ID] = $label;
        } else {
            // برای جاوااسکریپت + پیش‌نمایش
            $options[$currency->ID] = [
                'label' => $label,
                'rate'  => $rate,
            ];
        }
    }

    return $options;
}



//** ✅ اضافه کردن فیلدها برای محصولات ساده (تب "همگانی")
add_action('woocommerce_product_options_pricing', function () {
    global $post; // 🟢 رفع هشدار Undefined $post

    echo '<div class="options_group ani-navasan-group">';

    // 🟢 کف قیمت فروش (تومانی)
    woocommerce_wp_text_input(array(
        'id'          => '_torob_scraper_min_price',
        'label'       => 'کف قیمت فروش (تومانی)',
        'type'        => 'number',
        'desc_tip'    => true,
    ));

    // 🟢 فعال بودن وابستگی ارزی
    woocommerce_wp_checkbox(array(
        'id'          => '_mnswmc_active',
        'label'       => 'فعال‌سازی وابستگی ارزی',
        'description' => 'اگر تیک بزنید، این محصول بر اساس نرخ ارز محاسبه خواهد شد.',
    ));

    // 🟢 نوع وابستگی ارزی (simple / advanced) — قرارداد داده‌ای افزونه اصلی
    $current_type = get_post_meta($post->ID, '_mnswmc_dependence_type', true);
    if ($current_type === 'variable') {
        $current_type = 'advanced'; // مقدار قدیمی (legacy)
    }
    if (!in_array($current_type, array('simple', 'advanced'), true)) {
        $current_type = 'simple'; // پیش‌فرض محصول ساده
    }

    woocommerce_wp_select(array(
        'id'          => '_mnswmc_dependence_type',
        'label'       => 'نوع وابستگی ارزی',
        'options'     => array(
            'simple'   => 'ساده (Simple)',
            'advanced' => 'پیشرفته (Advanced)',
        ),
        'value'       => $current_type,
        'desc_tip'    => true,
    ));

    // 🟢 پیام اطلاع‌رسانی حالت پیشرفته (پیش‌فرض مخفی؛ با JS نمایش داده می‌شود)
    echo '<div class="ani_navasan_advanced_notice" style="display:none; margin:0 0 10px; padding:8px 10px; border:1px solid #ffb900; background:#fcf9e8; color:#767676; border-radius:4px; font-size:12px; line-height:1.7;">';
    echo '⚠️ در نسخه Lite امکان تعریف و استفاده از فرمول‌های محاسباتی پیشرفته وجود ندارد. در حال حاضر، محاسبه قیمت در هر دو نوع وابستگی ساده و پیشرفته با ضرب قیمت ارزی در نرخ ارز انتخاب‌شده انجام می‌شود.';
    echo '</div>';

    // 🟢 انتخاب ارز با نرخ
    $currencies    = ani_navasan_get_currency_options();
    $current_value = get_post_meta($post->ID, '_mnswmc_currency_id', true);

    echo '<p class="form-field _mnswmc_currency_id_field">';
    echo '<label for="_mnswmc_currency_id">انتخاب ارز</label>';
    echo '<select id="_mnswmc_currency_id" name="_mnswmc_currency_id" class="select short">';
    echo '<option value="">— انتخاب کنید —</option>';

    foreach ($currencies as $id => $data) {
        $selected = selected($current_value, $id, false);
        echo '<option value="' . esc_attr($id) . '" data-rate="' . esc_attr($data['rate']) . '" ' . $selected . '>'
            . esc_html($data['label'])
            . '</option>';
    }

    echo '</select>';
    echo '</p>';


    // 🟢 قیمت ارزی
    woocommerce_wp_text_input(array(
        'id'                => '_mnswmc_regular_price',
        'label'             => 'قیمت ارزی',
        'type'              => 'number',
        'desc_tip'          => true,
        'description'       => 'قیمت این محصول به واحد ارزی انتخاب‌شده.',
        'custom_attributes' => array(
            'step' => '0.01',
            'min'  => '0'
        ),
    ));

    // 🟢 قیمت ویژه ارزی
    woocommerce_wp_text_input(array(
        'id'                => '_mnswmc_sale_price',
        'label'             => 'قیمت ویژه ارزی',
        'type'              => 'number',
        'desc_tip'          => true,
        'description'       => 'قیمت ویژه این محصول به واحد ارزی انتخاب‌شده. اگر خالی باشد، قیمت تخفیفی تومانی ساخته نمی‌شود.',
        'custom_attributes' => array(
            'step' => '0.01',
            'min'  => '0'
        ),
    ));

    // 🟢 کف قیمت ارزی
    woocommerce_wp_text_input(array(
        'id'                => '_torob_scraper_min_price_currency',
        'label'             => 'کف قیمت فروش (ارزی)',
        'type'              => 'number',
        'desc_tip'          => true,
        'custom_attributes' => array(
            'step' => '0.01',
            'min'  => '0'
        ),
    ));


    // 🟢 بخش پیش‌نمایش زنده (پیش‌فرض مخفی)
    echo '<div class="ani_navasan_preview" style="margin:10px 0; padding:8px; border:1px solid #ddd; background:#fafafa; display:none;">';
    echo '<strong>پیش‌نمایش محاسبات:</strong><br>';
    echo '🔹 نرخ ارز: <span class="ani_rate">—</span><br>';
    echo '🔹 قیمت فروش نهایی (تومان): <span class="ani_final_price">—</span><br>';
    echo '🔹 قیمت ویژه نهایی (تومان): <span class="ani_final_sale_price">—</span><br>';
    echo '🔹 کف قیمت فروش نهایی (تومان): <span class="ani_final_min_price">—</span>';
    echo '</div>';

    echo '</div>';
});


/**
 * ✅ ذخیره مقادیر برای محصولات ساده
 */
add_action('woocommerce_admin_process_product_object', function ($product) {
    $fields = array(
        '_mnswmc_active',
        '_mnswmc_currency_id',
        '_mnswmc_regular_price',
        '_mnswmc_sale_price',
        '_torob_scraper_min_price',
        '_torob_scraper_min_price_currency',
    );

    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $value = wc_clean($_POST[$field]);
            $product->update_meta_data($field, $value);
        } else {
            if ($field === '_mnswmc_active') {
                $product->update_meta_data($field, 'no');
            }
        }
    }

    // نوع وابستگی ارزی (simple / advanced) — پیش‌فرض: simple
    // پیش‌فرض فقط زمانی اعمال می‌شود که مقدار معتبر قبلی ثبت نشده باشد
    $posted_type = isset($_POST['_mnswmc_dependence_type']) ? wc_clean($_POST['_mnswmc_dependence_type']) : '';
    $dependence_type = ani_navasan_resolve_dependence_type($posted_type, $product->get_meta('_mnswmc_dependence_type', true));
    $product->update_meta_data('_mnswmc_dependence_type', $dependence_type);

    // ---------- محاسبه سروری (fallback) ----------
    try {
        $active = $product->get_meta('_mnswmc_active', true);
        if ($active === 'yes') {
            $currency_id = $product->get_meta('_mnswmc_currency_id', true);

            if ($currency_id) {
                $rate = ani_navasan_get_currency_rate(intval($currency_id));

                if ($rate > 0) {
                    $currency_price      = floatval($product->get_meta('_mnswmc_regular_price', true));
                    $currency_sale_price = floatval($product->get_meta('_mnswmc_sale_price', true));

                    if ($currency_price > 0) {
                        $toman = ani_navasan_round_to_thousand($rate * $currency_price);
                        $product->set_regular_price($toman);
                    }

                    if ($currency_sale_price > 0) {
                        $sale_toman = ani_navasan_round_to_thousand($rate * $currency_sale_price);
                        $product->set_sale_price($sale_toman);
                    } else {
                        $product->set_sale_price('');
                    }

                    $min_price_currency = $product->get_meta('_torob_scraper_min_price_currency', true);
                    if ($min_price_currency !== '') {
                        $min_toman = ani_navasan_round_to_thousand(floatval($min_price_currency) * $rate);
                        $product->update_meta_data('_torob_scraper_min_price', $min_toman);
                    }
                }
            }
        }
    } catch (Exception $e) {}
});

// ✅ اضافه کردن فیلدها به متغیرها (محصولات متغیر)
add_action('woocommerce_product_after_variable_attributes', function ($loop, $variation_data, $variation) {
    echo '<div class="options_group ani-navasan-group" style="border:1px solid #ddd; margin:10px 0; padding:10px;">';

    // 🟢 کف قیمت تومانی
    woocommerce_wp_text_input(array(
        'id'    => "_torob_scraper_min_price_{$loop}",
        'name'  => "_torob_scraper_min_price[{$loop}]",
        'label' => 'کف قیمت فروش (تومانی)',
        'value' => get_post_meta($variation->ID, '_torob_scraper_min_price', true),
        'type'  => 'number',
    ));

    // 🟢 فعال بودن وابستگی ارزی
    woocommerce_wp_checkbox(array(
        'id'    => "_mnswmc_active_{$loop}",
        'name'  => "_mnswmc_active[{$loop}]",
        'label' => 'فعال‌سازی وابستگی ارزی',
        'value' => get_post_meta($variation->ID, '_mnswmc_active', true),
    ));

    // 🟢 نوع وابستگی ارزی (simple / advanced) — قرارداد داده‌ای افزونه اصلی
    $current_type = get_post_meta($variation->ID, '_mnswmc_dependence_type', true);
    if ($current_type === 'variable') {
        $current_type = 'advanced'; // مقدار قدیمی (legacy)
    }
    if (!in_array($current_type, array('simple', 'advanced'), true)) {
        $current_type = 'simple'; // پیش‌فرض Variation
    }

    woocommerce_wp_select(array(
        'id'      => "_mnswmc_dependence_type_{$loop}",
        'name'    => "_mnswmc_dependence_type[{$loop}]",
        'label'   => 'نوع وابستگی ارزی',
        'options' => array(
            'simple'   => 'ساده (Simple)',
            'advanced' => 'پیشرفته (Advanced)',
        ),
        'value'   => $current_type,
    ));

    // 🟢 پیام اطلاع‌رسانی حالت پیشرفته (پیش‌فرض مخفی؛ با JS نمایش داده می‌شود)
    echo '<div class="ani_navasan_advanced_notice" style="display:none; margin:0 0 10px; padding:8px 10px; border:1px solid #ffb900; background:#fcf9e8; color:#767676; border-radius:4px; font-size:12px; line-height:1.7;">';
    echo '⚠️ در نسخه Lite امکان تعریف و استفاده از فرمول‌های محاسباتی پیشرفته وجود ندارد. در حال حاضر، محاسبه قیمت در هر دو نوع وابستگی ساده و پیشرفته با ضرب قیمت ارزی در نرخ ارز انتخاب‌شده انجام می‌شود.';
    echo '</div>';

    // 🟢 انتخاب ارز
    $currencies = ani_navasan_get_currency_options();
    $current_value = get_post_meta($variation->ID, '_mnswmc_currency_id', true);

    echo '<p class="form-field _mnswmc_currency_id_field">';
    echo '<label for="_mnswmc_currency_id_' . $loop . '">انتخاب ارز</label>';
    echo '<select id="_mnswmc_currency_id_' . $loop . '" name="_mnswmc_currency_id[' . $loop . ']" class="select short">';
    echo '<option value="">— انتخاب کنید —</option>';

    foreach ($currencies as $id => $data) {
        $selected = selected($current_value, $id, false);
        echo '<option value="' . esc_attr($id) . '" data-rate="' . esc_attr($data['rate']) . '" ' . $selected . '>'
            . esc_html($data['label'])
            . '</option>';
    }

    echo '</select>';
    echo '</p>';

    // 🟢 قیمت ارزی
    woocommerce_wp_text_input(array(
        'id'                => "_mnswmc_regular_price_{$loop}",
        'name'              => "_mnswmc_regular_price[{$loop}]",
        'label'             => 'قیمت ارزی',
        'value'             => get_post_meta($variation->ID, '_mnswmc_regular_price', true),
        'type'              => 'number',
        'custom_attributes' => array(
            'step' => '0.01',
            'min'  => '0'
        ),
    ));

    // 🟢 قیمت ویژه ارزی
    woocommerce_wp_text_input(array(
        'id'                => "_mnswmc_sale_price_{$loop}",
        'name'              => "_mnswmc_sale_price[{$loop}]",
        'label'             => 'قیمت ویژه ارزی',
        'value'             => get_post_meta($variation->ID, '_mnswmc_sale_price', true),
        'type'              => 'number',
        'custom_attributes' => array(
            'step' => '0.01',
            'min'  => '0'
        ),
    ));

    // 🟢 کف قیمت ارزی
    woocommerce_wp_text_input(array(
        'id'                => "_torob_scraper_min_price_currency_{$loop}",
        'name'              => "_torob_scraper_min_price_currency[{$loop}]",
        'label'             => 'کف قیمت فروش (ارزی)',
        'value'             => get_post_meta($variation->ID, '_torob_scraper_min_price_currency', true),
        'type'              => 'number',
        'custom_attributes' => array(
            'step' => '0.01',
            'min'  => '0'
        ),
    ));


    // 🟢 نمایش محاسبات لحظه‌ای (پیش‌فرض مخفی)
    echo '<div id="mnswmc-preview-' . $loop . '" class="mnswmc-preview" style="display:none; margin-top:10px; padding:8px; background:#f9f9f9; border:1px dashed #bbb;">';
    echo '<strong>پیش‌نمایش محاسبات:</strong><br>';
    echo 'نرخ ارز: <span class="rate">-</span><br>';
    echo 'قیمت نهایی (تومانی): <span class="final">-</span><br>';
    echo 'قیمت ویژه نهایی (تومانی): <span class="sale-final">-</span><br>';
    echo 'کف قیمت نهایی (تومانی): <span class="min-final">-</span>';
    echo '</div>';

    echo '</div>';
}, 10, 3);



/**
 * ✅ ذخیره متای محصولات متغیر
 */
add_action('woocommerce_save_product_variation', function ($variation_id, $i) {
    $fields = array(
        '_mnswmc_active',
        '_mnswmc_currency_id',
        '_mnswmc_regular_price',
        '_mnswmc_sale_price',
        '_torob_scraper_min_price',
        '_torob_scraper_min_price_currency',
    );

    foreach ($fields as $field) {
        if ($field === '_mnswmc_active') {
            // چک‌باکس → مقدار پیش‌فرض no
            $val = isset($_POST[$field][$i]) && $_POST[$field][$i] === 'yes' ? 'yes' : 'no';
            update_post_meta($variation_id, $field, $val);
        } else {
            if (isset($_POST[$field][$i])) {
                update_post_meta($variation_id, $field, wc_clean($_POST[$field][$i]));
            }
        }
    }

    // نوع وابستگی ارزی (simple / advanced) — پیش‌فرض: simple
    // پیش‌فرض فقط زمانی اعمال می‌شود که مقدار معتبر قبلی ثبت نشده باشد
    $posted_type = isset($_POST['_mnswmc_dependence_type'][$i]) ? wc_clean($_POST['_mnswmc_dependence_type'][$i]) : '';
    $dependence_type = ani_navasan_resolve_dependence_type($posted_type, get_post_meta($variation_id, '_mnswmc_dependence_type', true));
    update_post_meta($variation_id, '_mnswmc_dependence_type', $dependence_type);

    // ---------- محاسبه سروری (fallback) ----------
    try {
        $active = get_post_meta($variation_id, '_mnswmc_active', true);
        if ($active === 'yes') {
            $currency_id = get_post_meta($variation_id, '_mnswmc_currency_id', true);

            if ($currency_id) {
                $rate = ani_navasan_get_currency_rate(intval($currency_id));

                if ($rate > 0) {
                    $currency_price      = floatval(get_post_meta($variation_id, '_mnswmc_regular_price', true));
                    $currency_sale_price = floatval(get_post_meta($variation_id, '_mnswmc_sale_price', true));

                    $product = wc_get_product($variation_id);
                    if ($product) {
                        if ($currency_price > 0) {
                            $toman = ani_navasan_round_to_thousand($rate * $currency_price);
                            $product->set_regular_price($toman);
                        }

                        if ($currency_sale_price > 0) {
                            $sale_toman = ani_navasan_round_to_thousand($rate * $currency_sale_price);
                            $product->set_sale_price($sale_toman);
                        } else {
                            $product->set_sale_price('');
                        }

                        $min_price_currency = get_post_meta($variation_id, '_torob_scraper_min_price_currency', true);
                        if ($min_price_currency !== '') {
                            $min_toman = ani_navasan_round_to_thousand(floatval($min_price_currency) * $rate);
                            $product->update_meta_data('_torob_scraper_min_price', $min_toman);
                        }

                        $product->save();
                    }
                }
            }
        }
    } catch (Exception $e) {}
}, 10, 2);
