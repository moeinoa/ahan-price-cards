jQuery(function ($) {
    var $wrap = $('#apc-items'), tpl = $('#apc-tpl').html(), idx = $wrap.children().length + 100;
    $wrap.sortable({ handle: '.apc-handle' });
    function toggleAdd() { $('#apc-add').prop('disabled', $wrap.children().length >= APC_ADMIN.max); }
    $('#apc-add').on('click', function () {
        if ($wrap.children().length >= APC_ADMIN.max) return;
        $wrap.append(tpl.replace(/__i__/g, idx++));
        toggleAdd();
    });
    $wrap.on('click', '.apc-remove', function () { $(this).closest('.apc-item').remove(); toggleAdd(); });
    $(document).on('click', '.apc-pick', function (e) {
        e.preventDefault();
        var $m = $(this).closest('.apc-media');
        var frame = wp.media({ multiple: false, library: { type: 'image' } });
        frame.on('select', function () {
            var a = frame.state().get('selection').first().toJSON();
            $m.find('input').val(a.id);
            $m.find('img').attr('src', a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url).show();
        });
        frame.open();
    });
    $(document).on('click', '.apc-clear', function () {
        var $m = $(this).closest('.apc-media');
        $m.find('input').val(0);
        $m.find('img').hide();
    });
    toggleAdd();

    /* ── نوار فروش ویژه: جدول ← ردیف‌ها (چک‌باکس + قیمت دستی) ── */
    var $sel = $('#apc-sale-table'), $rows = $('#apc-sale-rows'), $count = $('#apc-sale-count'), $rowSearch = $('#apc-rows-search');
    if (!$sel.length) return;

    function esc(t) { return $('<div>').text(t == null ? '' : t).html(); }
    function norm(t) {
        return String(t).toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک')
            .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
            .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; });
    }
    function max() { return Math.max(1, Math.min(12, +$('#apc-sale-max').val() || 5)); }
    function updateCount() {
        var n = $rows.find('input.apc-ck:checked').length;
        $count.text(n + ' از ' + max() + ' انتخاب شده').toggleClass('is-full', n >= max());
    }

    function loadRows(selected, prices) {
        prices = prices || {};
        $rowSearch.val('');
        var id = +$sel.val();
        if (!id) { $rows.html('<p class="apc-empty">ابتدا یک جدول انتخاب کنید.</p>'); updateCount(); return; }
        $rows.html('<p class="apc-empty">در حال بارگذاری…</p>');
        $.post(ajaxurl, { action: 'apc_sale_rows', nonce: APC_ADMIN.nonce, table: id }, function (res) {
            if (!res || !res.success || !res.data.length) { $rows.html('<p class="apc-empty">ردیفی پیدا نشد.</p>'); updateCount(); return; }
            var html = '<table class="apc-rtable"><thead><tr><th class="c-ck"></th><th>ردیف</th><th>قیمت جدول</th><th>قیمت دستی (تومان)</th></tr></thead><tbody>';
            res.data.forEach(function (r) {
                var on = selected.indexOf(r.key) > -1;
                var cells = (r.cells && r.cells.length ? r.cells : [r.label]).map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('');
                html += '<tr class="' + (on ? 'is-on' : '') + '" data-q="' + esc(norm((r.cells || [r.label]).join(' ') + ' ' + r.price)) + '">'
                    + '<td class="c-ck"><input type="checkbox" class="apc-ck" name="apc_settings[sale_keys][]" value="' + esc(r.key) + '"' + (on ? ' checked' : '') + '></td>'
                    + '<td class="c-cells">' + cells + '</td>'
                    + '<td class="c-price' + (r.priced ? '' : ' is-call') + '">' + esc(r.price) + '</td>'
                    + '<td><input type="text" class="apc-sale-price" placeholder="' + (r.priced ? 'قیمت جدول' : 'قیمت بدهید') + '" name="apc_settings[sale_prices][' + esc(r.key) + ']" value="' + esc(prices[r.key] || '') + '"' + (on ? '' : ' disabled') + '></td>'
                    + '</tr>';
            });
            $rows.html(html + '</tbody></table>');
            updateCount();
        });
    }

    // کلیک روی هر جای ردیف، تیک را تغییر می‌دهد
    $rows.on('click', 'tbody tr', function (e) {
        if ($(e.target).is('input')) return;
        var $c = $(this).find('input.apc-ck');
        $c.prop('checked', !$c.prop('checked')).trigger('change');
    });
    $rows.on('change', 'input.apc-ck', function () {
        if ($rows.find('input.apc-ck:checked').length > max()) { this.checked = false; alert('حداکثر ' + max() + ' ردیف (طبق «حداکثر تعداد ردیف در نوار»).'); }
        var $tr = $(this).closest('tr');
        $tr.toggleClass('is-on', this.checked).find('input.apc-sale-price').prop('disabled', !this.checked);
        if (this.checked) $tr.find('input.apc-sale-price').trigger('focus');
        updateCount();
    });
    $('#apc-sale-max').on('input', updateCount);

    $rowSearch.on('input', function () {
        var q = norm($rowSearch.val()).trim();
        $rows.find('tbody tr').each(function () { $(this).toggleClass('is-hidden', !!q && $(this).attr('data-q').indexOf(q) < 0); });
    });

    // جست‌وجوی جدول (یکسان‌سازی ی/ک عربی و ارقام فارسی)؛ لیست همیشه باز و کامل است
    var $search = $('#apc-sale-search'), allOpts = $sel.find('option').toArray();
    $search.on('input', function () {
        var q = norm($search.val()).trim(), cur = $sel.val();
        var list = allOpts.filter(function (o) { return o.value === '0' || o.value === cur || !q || norm(o.textContent).indexOf(q) > -1; });
        $sel.empty().append(list).val(cur);
    });
    $sel.on('change', function () { loadRows([]); });

    var saved = [], prices = {};
    try { saved = JSON.parse($rows.attr('data-selected') || '[]'); prices = JSON.parse($rows.attr('data-prices') || '{}'); } catch (e) {}
    loadRows(saved, prices);
});
