jQuery(document).ready(function ($) {

    // ============================
    // helper: گرد کردن قیمت تومانی به نزدیک‌ترین ۱۰۰۰ تومان
    // سیاست گرد کردن نهایی: تمام قیمت‌های تومانی نهایی به نزدیک‌ترین ۱۰۰۰ تومان گرد می‌شوند.
    // ============================
    function mnswmcRoundToThousand(value) {
        return Math.round((parseFloat(value) || 0) / 1000) * 1000;
    }

    // ============================
    // helper: پیدا کردن regular price ساده
    // ============================
    function findSimpleRegularPrice() {
        let $r = $('input#_regular_price');
        if ($r.length) return $r;
        $r = $('input#regular_price');
        if ($r.length) return $r;
        $r = $('input[name="_regular_price"]');
        if ($r.length) return $r;
        $r = $('input[name="regular_price"]');
        if ($r.length) return $r;
        $r = $('input.wc_input_price').first();
        if ($r.length) return $r;
        return $();
    }

    // ============================
    // محصول ساده
    // ============================
    function setupSimpleProductBehavior() {
        const $checkbox = $('#_mnswmc_active');
        if (!$checkbox.length) {
            console.log('mnswmc: simple checkbox not present');
            return;
        }

        const $minPriceToman = $('#_torob_scraper_min_price'); // همیشه نمایش داده میشه
        const $currencySelectP = $('#_mnswmc_currency_id').closest('p.form-field');
        const $currencyPriceP  = $('#_mnswmc_regular_price').closest('p.form-field');
        const $minPriceCurrencyP = $('#_torob_scraper_min_price_currency').closest('p.form-field');

        const $currencyFieldsGroup = $currencySelectP.add($currencyPriceP).add($minPriceCurrencyP);

        // ---- محاسبه و آپدیت قیمت‌های تومان + نمایش لحظه‌ای
        function calculateAndApplySimple() {
            if (! $checkbox.is(':checked')) return;

            const currencyId       = $('#_mnswmc_currency_id').val();
            const basePrice        = parseFloat($('#_mnswmc_regular_price').val()) || 0;
            const minPriceCurrency = parseFloat($('#_torob_scraper_min_price_currency').val()) || 0;

            if (! currencyId) {
                console.warn('mnswmc: no currency selected, skipping calc');
                return;
            }

            // AJAX برای نرخ (سرور) — اگر موفق نبود، از data-rate داخل option استفاده می‌کنیم
            $.post(mnswmc_ajax.url, {
                action: 'mnswmc_get_exchange_rate',
                currency_id: currencyId,
                _ajax_nonce: mnswmc_ajax.nonce
            }, function (response) {
                if (response && response.success) {
                    const rate = parseFloat(response.data.rate) || 0;
                    applySimpleRate(rate, basePrice, minPriceCurrency);
                } else {
                    console.warn('mnswmc: failed to get rate via ajax, trying data-rate fallback', response);
                    const rateFallback = parseFloat($('#_mnswmc_currency_id').find(':selected').data('rate')) || 0;
                    applySimpleRate(rateFallback, basePrice, minPriceCurrency);
                }
            }).fail(function () {
                // در صورت خطای شبکه یا عدم پاسخ، fallback
                const rateFallback = parseFloat($('#_mnswmc_currency_id').find(':selected').data('rate')) || 0;
                applySimpleRate(rateFallback, basePrice, minPriceCurrency);
            });
        }

        function applySimpleRate(rate, basePrice, minPriceCurrency) {
            rate = parseFloat(rate) || 0;
            const regularToman  = mnswmcRoundToThousand(basePrice * rate);
            const minPriceToman = mnswmcRoundToThousand(minPriceCurrency * rate);

            // مقداردهی به فیلد قیمت اصلی تومانی (با یافتن مقاوم)
            const $regularInput = findSimpleRegularPrice();
            if ($regularInput.length) {
                $regularInput.val(regularToman).trigger('change');
            } else {
                // fallback id قدیمی
                const $r2 = $('#_regular_price');
                if ($r2.length) $r2.val(regularToman).trigger('change');
            }

            // مقداردهی به فیلد کف قیمت تومانی
            if ($minPriceToman && $minPriceToman.length !== undefined) {
                // nothing - using variable name collision guard below
            }
            if ($('#_torob_scraper_min_price').length) {
                $('#_torob_scraper_min_price').val(minPriceToman).trigger('change');
            }

            // آپدیت preview ها
            $('#ani_navasan_rate_display').text(rate ? rate.toLocaleString() : '—');
            $('#ani_navasan_regular_toman').text(regularToman ? regularToman.toLocaleString() : '—');
            $('#ani_navasan_min_toman').text(minPriceToman ? minPriceToman.toLocaleString() : '—');

            $('.ani_navasan_preview .ani_rate').text(rate ? rate.toLocaleString() : '—');
            $('.ani_navasan_preview .ani_final_price').text(regularToman ? regularToman.toLocaleString() : '—');
            $('.ani_navasan_preview .ani_final_min_price').text(minPriceToman ? minPriceToman.toLocaleString() : '—');

            console.log('mnswmc: calculated simple', { rate, regularToman, minPriceToman });
        }

        function applySimple() {
            const checked = $checkbox.is(':checked');
            const $regular = findSimpleRegularPrice();

            console.log('mnswmc:simple -> applySimple, checked=', checked, 'regularFound=', !!$regular.length);

            if (checked) {
                $currencyFieldsGroup.show();
                $('.ani_navasan_preview').show();
                if ($minPriceToman.length) $minPriceToman.prop('readonly', true).css('background', '#f5f5f5');
                if ($regular.length) $regular.prop('readonly', true).css('background', '#f5f5f5');

                // محاسبه آنی
                calculateAndApplySimple();
            } else {
                $currencyFieldsGroup.hide();
                $('.ani_navasan_preview').hide();
                if ($minPriceToman.length) $minPriceToman.prop('readonly', false).css('background', '');
                if ($regular.length) $regular.prop('readonly', false).css('background', '');
            }
        }

        // وصل کردن هندلرها
        $checkbox.off('change.mnswmc_simple').on('change.mnswmc_simple', function () {
            applySimple();
        });

        $('#_mnswmc_currency_id, #_mnswmc_regular_price, #_torob_scraper_min_price_currency').off('input.mnswmc change.mnswmc').on('input.mnswmc change.mnswmc', function () {
            if ($checkbox.is(':checked')) {
                if (window.mnswmc_calc_timeout) clearTimeout(window.mnswmc_calc_timeout);
                window.mnswmc_calc_timeout = setTimeout(function () {
                    calculateAndApplySimple();
                }, 120);
            }
        });

        // اجرای اولیه با تاخیر مختصر (تا DOM کامل آماده شود)
        setTimeout(applySimple, 60);
    } // end setupSimpleProductBehavior


    // ============================
    // متغیرها (variations)
    // ============================
    // هر ورییشن یک preview مجزا (#mnswmc-preview-{index}) دارد

    // محاسبه/آپدیت preview برای یک ورییشن مشخص
    function updatePreviewForVariation(index) {
        if (index === '' && index !== 0) return;
        const $preview = $('#mnswmc-preview-' + index);
        // پیدا کردن ریشه‌ی ورییشن
        let $root = $preview.closest('.woocommerce_variation');
        if (!$root.length) {
            $root = $('#_mnswmc_active_' + index).closest('.woocommerce_variation');
        }
        if (!$root.length) {
            // fallback: سعی کن مستقیماً عنصری با id preview را بگیریم
            if (!$preview.length) return;
        }

        const $checkbox = $root.find('#_mnswmc_active_' + index);
        const $currency = $root.find('#_mnswmc_currency_id_' + index);
        const $price = $root.find('#_mnswmc_regular_price_' + index);
        const $minPriceCurrency = $root.find('#_torob_scraper_min_price_currency_' + index);
        const $regularTomanInput = $root.find('#variable_regular_price_' + index).first();
        const $minTomanInput = $root.find('#_torob_scraper_min_price_' + index);

        if (!$preview.length) return;

        // Helper: انجام محاسبه با نرخ مشخص
        function applyRateForVariation(rate) {
            rate = parseFloat(rate) || 0;
            const priceVal = parseFloat($price.val()) || 0;
            const minVal = parseFloat($minPriceCurrency.val()) || 0;

            if (rate > 0 && priceVal > 0) {
                const final = mnswmcRoundToThousand(rate * priceVal);
                const minFinal = (minVal > 0) ? mnswmcRoundToThousand(rate * minVal) : '';

                $preview.find('.rate').text(rate ? rate.toLocaleString() : '-');
                $preview.find('.final').text(final ? final.toLocaleString() : '-');
                $preview.find('.min-final').text(minFinal ? minFinal.toLocaleString() : '-');

                // مقداردهی به فیلدهای تومان مربوط به ورییشن (اگر وجود دارند)
                if ($regularTomanInput.length) {
                    $regularTomanInput.val(final).trigger('change');
                }
                if ($minTomanInput.length && minFinal !== '') {
                    $minTomanInput.val(minFinal).trigger('change');
                }
            } else {
                $preview.find('.rate').text('-');
                $preview.find('.final').text('-');
                $preview.find('.min-final').text('-');
            }
        }

        // اگر checkbox فعال است preview را نشان بده و محاسبه را انجام بده
        if ($checkbox.length && $checkbox.is(':checked')) {
            $preview.show();

            // سعی کن از data-rate در option استفاده کنیم
            const dataRate = parseFloat($currency.find(':selected').data('rate')) || 0;
            const currencyId = $currency.val();

            if (dataRate > 0) {
                applyRateForVariation(dataRate);
            } else if (currencyId) {
                // fallback به AJAX
                $.post(mnswmc_ajax.url, {
                    action: 'mnswmc_get_exchange_rate',
                    currency_id: currencyId,
                    _ajax_nonce: mnswmc_ajax.nonce
                }, function (response) {
                    if (response && response.success) {
                        applyRateForVariation(response.data.rate);
                    } else {
                        console.warn('mnswmc: variation ajax failed or no rate', response);
                        applyRateForVariation(0);
                    }
                }).fail(function () {
                    console.warn('mnswmc: variation ajax failed (network)');
                    applyRateForVariation(0);
                });
            } else {
                applyRateForVariation(0);
            }
        } else {
            $preview.hide();
        }
    }

    // نمایش/مخفی کردن فیلدها و readonly کردن فیلدهای تومان برای یک ورییشن
    function applyCurrencyDependencyForVariationByIndex(index, $checkbox) {
        const isChecked = $checkbox.is(':checked');
        const $variationRoot = $checkbox.closest('.woocommerce_variation');
        const $context = $variationRoot.length ? $variationRoot : $(document);

        const $minPriceToman = $context.find('#_torob_scraper_min_price_' + index);
        const $currencyFields = $context.find(
            '#_mnswmc_currency_id_' + index +
            ', #_mnswmc_regular_price_' + index +
            ', #_torob_scraper_min_price_currency_' + index
        );
        const $regular = $context.find('#variable_regular_price_' + index + ', input[name="variable_regular_price[' + index + ']"]').first();
        const $preview = $('#mnswmc-preview-' + index);

        if (isChecked) {
            $currencyFields.closest('p.form-field').show();
            if ($minPriceToman.length) $minPriceToman.prop('readonly', true).css('background', '#f5f5f5');
            if ($regular.length) $regular.prop('readonly', true).css('background', '#f5f5f5');
            if ($preview.length) $preview.show();
        } else {
            $currencyFields.closest('p.form-field').hide();
            if ($minPriceToman.length) $minPriceToman.prop('readonly', false).css('background', '');
            if ($regular.length) $regular.prop('readonly', false).css('background', '');
            if ($preview.length) $preview.hide();
        }

        // آپدیت preview پس از اعمال حالت
        updatePreviewForVariation(index);
    }

    function processVariation($variation) {
        const $checkbox = $variation.find('input[type=checkbox][id^="_mnswmc_active_"]').first();
        if (!$checkbox.length) return;

        const id = $checkbox.attr('id') || '';
        const m = id.match(/_(\d+)$/);
        const index = m ? m[1] : '';

        // اجرای اولیه
        applyCurrencyDependencyForVariationByIndex(index, $checkbox);

        // هندل تغییر تیک
        $checkbox.off('change.mnswmc_var').on('change.mnswmc_var', function () {
            applyCurrencyDependencyForVariationByIndex(index, $(this));
        });

        // هندل تغییر فیلدهای ارزی برای بروزرسانی زنده preview
        $variation.find(
            '#_mnswmc_currency_id_' + index +
            ', #_mnswmc_regular_price_' + index +
            ', #_torob_scraper_min_price_currency_' + index
        ).off('input.mnswmc_var change.mnswmc_var').on('input.mnswmc_var change.mnswmc_var', function () {
            // debounce کوچک
            if (window.mnswmc_var_timeout && window.mnswmc_var_timeout[index]) clearTimeout(window.mnswmc_var_timeout[index]);
            window.mnswmc_var_timeout = window.mnswmc_var_timeout || {};
            window.mnswmc_var_timeout[index] = setTimeout(function () {
                updatePreviewForVariation(index);
            }, 120);
        });

        console.log('mnswmc: processed variation idx=' + index + ' active=' + ($checkbox.is(':checked') ? 'yes' : 'no'));
    }

    function initVariableFields($context) {
        $context = $context || $(document);

        // پردازش همه wrapperهای ورییشن (اگر موجود باشند)
        $context.find('.woocommerce_variation').each(function () {
            processVariation($(this));
        });

        // fallback: اگر چک‌باکس‌ها مستقیم در DOM باشند
        $context.find('input[type=checkbox][id^="_mnswmc_active_"]').each(function () {
            const $cb = $(this);
            const $variation = $cb.closest('.woocommerce_variation');
            if ($variation.length) {
                processVariation($variation);
            } else {
                const id = $cb.attr('id') || '';
                const m = id.match(/_(\d+)$/);
                const index = m ? m[1] : '';
                applyCurrencyDependencyForVariationByIndex(index, $cb);
                $cb.off('change.mnswmc_var').on('change.mnswmc_var', function () {
                    applyCurrencyDependencyForVariationByIndex(index, $(this));
                });
            }
        });
    }

    // ============================
    // راه‌اندازی کلی
    // ============================
    setupSimpleProductBehavior();

    initVariableFields($(document));
    $(document).on('woocommerce_variations_loaded woocommerce_variations_added', function () {
        console.log('mnswmc:event -> woocommerce_variations_loaded/added');
        initVariableFields($('#variable_product_options'));
    });

    // وقتی آکاردئون ورییشن باز میشود (کاربر روی Edit کلیک می کند)
    $(document).on('click', '.woocommerce_variation .edit_variation, .edit_variation', function () {
        const $variation = $(this).closest('.woocommerce_variation');
        setTimeout(function () {
            processVariation($variation);
        }, 80);
    });

});

// ============================
// ذخیره‌سازی فیلدهای سفارشی ورییشن
// ============================
$(document).on('woocommerce_variations_save_variations', function (event, $form) {
    $form.find('.woocommerce_variation').each(function () {
        const $variation = $(this);
        const variationId = $variation.find('input.variable_post_id').val();

        if (!variationId) return;

        // فیلدهای سفارشی ما
        const $active = $variation.find('#_mnswmc_active_' + variationId);
        const $currency = $variation.find('#_mnswmc_currency_id_' + variationId);
        const $price = $variation.find('#_mnswmc_regular_price_' + variationId);
        const $minPriceCurrency = $variation.find('#_torob_scraper_min_price_currency_' + variationId);

        // داده‌ها رو به hidden input مناسب اضافه کنیم
        if ($active.length) {
            $('<input>').attr({
                type: 'hidden',
                name: 'variable_mnswmc_active[' + variationId + ']',
                value: $active.is(':checked') ? 'yes' : ''
            }).appendTo($variation);
        }
        if ($currency.length) {
            $('<input>').attr({
                type: 'hidden',
                name: 'variable_mnswmc_currency_id[' + variationId + ']',
                value: $currency.val() || ''
            }).appendTo($variation);
        }
        if ($price.length) {
            $('<input>').attr({
                type: 'hidden',
                name: 'variable_mnswmc_regular_price[' + variationId + ']',
                value: $price.val() || ''
            }).appendTo($variation);
        }
        if ($minPriceCurrency.length) {
            $('<input>').attr({
                type: 'hidden',
                name: 'variable_torob_scraper_min_price_currency[' + variationId + ']',
                value: $minPriceCurrency.val() || ''
            }).appendTo($variation);
        }
    });
});
