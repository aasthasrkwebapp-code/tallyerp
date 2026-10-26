/*
 * Invoice entry: add/remove items, fill party details, and preview totals.
 * The server recalculates everything on saving; this mirrors its rules
 * (integer paise, tax rounded per line, CGST = SGST = half the GST).
 */
(function () {
    'use strict';

    var cfg = window.invoiceForm || {};
    var form = document.getElementById('invoice-form');
    if (!form) return;

    var itemsBox = document.getElementById('invoice-items');
    var template = document.getElementById('item-template');
    var party = document.getElementById('party_account_id');
    var pos = document.getElementById('place_of_supply');
    var nextIndex = itemsBox.querySelectorAll('.invoice-item').length;

    function scaled(value, scale) {
        value = String(value || '').replace(/,/g, '').trim();
        if (!/^\d*(\.\d*)?$/.test(value) || value === '' || value === '.') return 0;
        var parts = value.split('.');
        var frac = (parts[1] || '').padEnd(scale + 1, '0');
        var n = parseInt(parts[0] || '0', 10) * Math.pow(10, scale) + parseInt(frac.substr(0, scale) || '0', 10);
        if (parseInt(frac.charAt(scale), 10) >= 5) n++;
        return n;
    }
    function roundDiv(a, b) { return a < 0 ? -Math.floor((-a + Math.floor(b / 2)) / b) : Math.floor((a + Math.floor(b / 2)) / b); }
    function money(paise) {
        var neg = paise < 0; paise = Math.abs(paise);
        var whole = String(Math.floor(paise / 100)), frac = String(paise % 100).padStart(2, '0');
        if (whole.length > 3) whole = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + whole.slice(-3);
        return (neg ? '-' : '') + whole + '.' + frac;
    }

    function partyData() {
        var id = parseInt(party.value, 10);
        return (cfg.parties || []).find(function (p) { return p.id === id; }) || null;
    }

    function taxMode() {
        if (!cfg.gstEnabled) return 'none';
        var p = partyData();
        var reg = p ? p.reg : null;
        if (cfg.purchase && ['composition', 'unregistered', 'consumer'].indexOf(reg) !== -1) return 'none';
        if (['overseas', 'sez'].indexOf(reg) !== -1) return 'inter';
        var state = cfg.purchase ? ((p && p.state) || pos.value) : (pos.value || (p && p.state));
        if (!cfg.companyState || !state) return 'intra';
        return cfg.companyState.trim().toLowerCase() === state.trim().toLowerCase() ? 'intra' : 'inter';
    }

    function recalc() {
        var mode = taxMode();
        var t = { gross: 0, discount: 0, taxable: 0, cgst: 0, sgst: 0, igst: 0 };
        itemsBox.querySelectorAll('.invoice-item').forEach(function (row) {
            var qty = scaled(row.querySelector('.item-qty').value, 3);
            var rate = scaled(row.querySelector('.item-rate').value, 2);
            var disc = Math.min(scaled(row.querySelector('.item-disc').value, 2), 10000);
            var gst = mode === 'none' ? 0 : scaled(row.querySelector('.item-gst').value, 2);
            var gross = rate * Math.floor(qty / 1000) + roundDiv(rate * (qty % 1000), 1000);
            var discount = roundDiv(gross * disc, 10000);
            var taxable = gross - discount;
            var half = mode === 'intra' ? roundDiv(taxable * gst, 20000) : 0;
            var igst = mode === 'inter' ? roundDiv(taxable * gst, 10000) : 0;
            row.querySelector('.item-amount').value = money(taxable);
            t.gross += gross; t.discount += discount; t.taxable += taxable;
            t.cgst += half; t.sgst += half; t.igst += igst;
        });
        var before = t.taxable + t.cgst + t.sgst + t.igst;
        var grand = cfg.roundOff ? roundDiv(before, 100) * 100 : before;
        t.round = grand - before; t.grand = grand;

        Object.keys(t).forEach(function (k) {
            var cell = document.querySelector('[data-total="' + k + '"]');
            if (cell) cell.textContent = money(t[k]);
        });
        document.querySelectorAll('.tax-row').forEach(function (tr) {
            var tax = tr.dataset.tax;
            tr.hidden = mode === 'none' || (mode === 'intra' && tax === 'igst') || (mode === 'inter' && tax !== 'igst');
        });
        document.getElementById('tax-mode').textContent = {
            intra: 'Intra-state supply: CGST + SGST',
            inter: 'Inter-state supply: IGST',
            none: cfg.gstEnabled ? 'No GST on this document' : 'GST is turned off for this company'
        }[mode];
    }

    function onParty(initial) {
        var p = partyData();
        var info = document.getElementById('party-info');
        info.textContent = p ? [p.gstin ? 'GSTIN ' + p.gstin : null, p.state, p.credit_days ? p.credit_days + ' days credit' : null].filter(Boolean).join(' · ') : '';
        if (!initial && p) {
            var billing = document.getElementById('billing_address');
            var shipping = document.getElementById('shipping_address');
            billing.value = p.billing || '';
            shipping.value = p.shipping || '';
            pos.value = '';
        }
        var original = document.getElementById('original_invoice_id');
        if (original) {
            Array.prototype.forEach.call(original.options, function (o) {
                if (!o.value) return;
                o.hidden = p ? parseInt(o.dataset.party, 10) !== p.id : false;
                if (o.hidden && o.selected) original.value = '';
            });
        }
        recalc();
    }

    function bindRow(row) {
        row.querySelector('.item-remove').addEventListener('click', function () {
            if (itemsBox.querySelectorAll('.invoice-item').length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach(function (i) { if (!i.readOnly) i.value = i.classList.contains('item-qty') ? '1' : ''; });
                row.querySelector('.item-ledger').value = '';
                var st = row.querySelector('.item-stock');
                if (st) st.value = '';
            }
            recalc();
        });
        // Choosing a stock item fills its ledger, description, unit, HSN, GST rate and rate.
        var stock = row.querySelector('.item-stock');
        if (stock) {
            stock.addEventListener('change', function () {
                var id = parseInt(stock.value, 10);
                var s = (cfg.stockItems || []).find(function (x) { return x.id === id; });
                if (!s) return;
                var set = function (sel, value) { var el = row.querySelector(sel); if (el && value !== null && value !== undefined && value !== '') el.value = value; };
                if (s.ledger) set('.item-ledger', String(s.ledger));
                set('.item-desc', s.name);
                set('.item-unit', s.unit);
                set('.item-hsn', s.hsn);
                set('.item-gst', s.gst);
                set('.item-rate', s.rate);
                recalc();
            });
        }
        var ledger = row.querySelector('.item-ledger');
        ledger.addEventListener('change', function () {
            var opt = ledger.options[ledger.selectedIndex];
            if (!opt || !opt.value) return;
            var hsn = row.querySelector('.item-hsn');
            if (!hsn.value && opt.dataset.hsn) hsn.value = opt.dataset.hsn;
            if (opt.dataset.gst) row.querySelector('.item-gst').value = opt.dataset.gst;
            recalc();
        });
    }

    document.getElementById('add-item').addEventListener('click', function () {
        var html = template.innerHTML.replace(/__I__/g, String(nextIndex++));
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var row = wrap.firstElementChild;
        itemsBox.appendChild(row);
        bindRow(row);
        (row.querySelector('.item-stock') || row.querySelector('.item-ledger')).focus();
        recalc();
    });

    itemsBox.querySelectorAll('.invoice-item').forEach(bindRow);
    itemsBox.addEventListener('input', recalc);
    itemsBox.addEventListener('change', recalc);
    party.addEventListener('change', function () { onParty(false); });
    pos.addEventListener('change', recalc);

    var type = document.getElementById('voucher_type_id');
    var number = document.getElementById('voucher_number');
    function onType() {
        var opt = type.options[type.selectedIndex];
        var manual = opt && opt.dataset.manual === '1';
        number.readOnly = !manual;
        number.placeholder = manual ? 'Enter number' : 'Automatic';
        if (!manual && !number.defaultValue) number.value = '';
    }
    type.addEventListener('change', onType);
    onType();

    onParty(true);
})();
