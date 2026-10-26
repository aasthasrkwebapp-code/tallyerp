// Voucher entry: dynamic Dr/Cr lines, live totals and bill-wise allocation.
(function () {
    'use strict';
    var cfg = window.voucherForm;
    var container = document.getElementById('voucher-lines');
    var lineTpl = document.getElementById('line-template');
    var billTpl = document.getElementById('bill-template');
    var ledgerById = {};
    cfg.ledgers.forEach(function (l) { ledgerById[l.id] = l; });

    function toPaise(v) {
        v = String(v || '').replace(/[,\s₹]/g, '');
        if (!/^\d*(\.\d*)?$/.test(v) || v === '' || v === '.') return 0;
        var parts = v.split('.');
        var frac = ((parts[1] || '') + '000').slice(0, 3);
        var p = parseInt(parts[0] || '0', 10) * 100 + parseInt(frac.slice(0, 2), 10);
        if (parseInt(frac[2], 10) >= 5) p += 1;
        return p;
    }
    function fmt(p) {
        var neg = p < 0; p = Math.abs(p);
        var whole = String(Math.floor(p / 100)), frac = String(p % 100).padStart(2, '0');
        if (whole.length > 3) {
            var last3 = whole.slice(-3), rest = whole.slice(0, -3);
            whole = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3;
        }
        return (neg ? '-' : '') + whole + '.' + frac;
    }

    function ledgerOptions(select, selected) {
        var opt = document.createElement('option');
        opt.value = ''; opt.textContent = 'Select ledger…';
        select.appendChild(opt);
        cfg.ledgers.forEach(function (l) {
            var o = document.createElement('option');
            o.value = l.id;
            o.textContent = l.name + (l.alias ? ' (' + l.alias + ')' : '');
            if (String(selected) === String(l.id)) o.selected = true;
            select.appendChild(o);
        });
    }

    function renumber() {
        container.querySelectorAll('.voucher-line').forEach(function (row, i) {
            row.querySelector('.line-side').name = 'entries[' + i + '][side]';
            row.querySelector('.line-account').name = 'entries[' + i + '][account_id]';
            row.querySelector('.line-amount').name = 'entries[' + i + '][amount]';
            row.querySelector('.line-narration').name = 'entries[' + i + '][narration]';
            row.querySelector('.line-cc').name = 'entries[' + i + '][cost_centre_id]';
            row.querySelectorAll('.bill-row').forEach(function (b, j) {
                var pre = 'entries[' + i + '][bills][' + j + ']';
                b.querySelector('.bill-type').name = pre + '[bill_type]';
                b.querySelector('.bill-ref').name = pre + '[bill_ref]';
                b.querySelector('.bill-due').name = pre + '[due_date]';
                b.querySelector('.bill-amount').name = pre + '[amount]';
            });
        });
    }

    function totals() {
        var dr = 0, cr = 0;
        container.querySelectorAll('.voucher-line').forEach(function (row) {
            var p = toPaise(row.querySelector('.line-amount').value);
            if (row.querySelector('.line-side').value === 'Dr') dr += p; else cr += p;
            updateBillStatus(row);
        });
        document.getElementById('total-dr').textContent = fmt(dr);
        document.getElementById('total-cr').textContent = fmt(cr);
        document.getElementById('difference').textContent = fmt(Math.abs(dr - cr)) + (dr === cr ? '' : (dr > cr ? ' Dr' : ' Cr'));
        document.getElementById('difference-wrap').classList.toggle('text-danger', dr !== cr);
        document.getElementById('difference-wrap').classList.toggle('text-success', dr === cr && dr > 0);
        return { dr: dr, cr: cr };
    }

    function updateBillStatus(row) {
        var wrap = row.querySelector('.line-bills');
        if (wrap.hidden) return;
        var total = 0;
        row.querySelectorAll('.bill-amount').forEach(function (i) { total += toPaise(i.value); });
        var amount = toPaise(row.querySelector('.line-amount').value);
        var status = row.querySelector('.bill-status');
        if (!row.querySelector('.bill-row')) {
            status.textContent = 'No bills entered: the amount will be kept "on account".';
            status.className = 'small bill-status text-secondary';
        } else if (total === amount) {
            status.textContent = 'Allocated in full.';
            status.className = 'small bill-status text-success';
        } else {
            status.textContent = 'Allocated ' + fmt(total) + ' of ' + fmt(amount) + '.';
            status.className = 'small bill-status text-danger';
        }
    }

    function addBill(row, data) {
        var node = billTpl.content.firstElementChild.cloneNode(true);
        data = data || {};
        node.querySelector('.bill-type').value = data.bill_type || defaultBillType(row);
        node.querySelector('.bill-ref').value = data.bill_ref || '';
        node.querySelector('.bill-due').value = data.due_date || '';
        node.querySelector('.bill-amount').value = data.amount || '';
        if (row.dataset.listId) node.querySelector('.bill-ref').setAttribute('list', row.dataset.listId);
        node.querySelector('.bill-remove').addEventListener('click', function () { node.remove(); renumber(); totals(); });
        node.querySelector('.bill-amount').addEventListener('input', totals);
        node.querySelector('.bill-ref').addEventListener('change', function () { fillFromOpenBill(row, node); });
        row.querySelector('.bill-rows').appendChild(node);
        renumber();
    }

    function defaultBillType(row) {
        var base = cfg.baseType;
        return (base === 'sales' || base === 'purchase') ? 'new_ref' : 'agst_ref';
    }

    function fillFromOpenBill(row, billRow) {
        var bills = row._openBills || [];
        var ref = billRow.querySelector('.bill-ref').value.toLowerCase();
        bills.forEach(function (b) {
            if (b.ref.toLowerCase() === ref && !billRow.querySelector('.bill-amount').value) {
                billRow.querySelector('.bill-type').value = 'agst_ref';
                billRow.querySelector('.bill-amount').value = b.pending;
                totals();
            }
        });
    }

    function loadOpenBills(row, ledgerId) {
        row._openBills = [];
        var listId = 'bills-' + Math.random().toString(36).slice(2);
        row.dataset.listId = listId;
        var dl = document.createElement('datalist');
        dl.id = listId;
        row.appendChild(dl);
        fetch(cfg.openBillsUrl.replace('__ID__', ledgerId) + (cfg.excludeVoucher ? '?exclude=' + cfg.excludeVoucher : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (bills) {
                row._openBills = bills;
                bills.forEach(function (b) {
                    var o = document.createElement('option');
                    o.value = b.ref;
                    o.label = b.pending + ' ' + b.side + (b.due_date ? ' due ' + b.due_date : '');
                    dl.appendChild(o);
                });
                row.querySelectorAll('.bill-ref').forEach(function (i) { i.setAttribute('list', listId); });
                var balance = row.querySelector('.line-balance');
                balance.textContent = bills.length ? bills.length + ' pending bill(s)' : '';
            })
            .catch(function () {});
    }

    function onLedgerChange(row, keepBills) {
        var ledger = ledgerById[row.querySelector('.line-account').value];
        var billWrap = row.querySelector('.line-bills');
        billWrap.hidden = !(ledger && ledger.billwise);
        if (!keepBills) row.querySelector('.bill-rows').innerHTML = '';
        var ccWrap = row.querySelector('.line-cc-wrap');
        ccWrap.hidden = !(cfg.costCentres.length && ledger && (ledger.nature === 'income' || ledger.nature === 'expense'));
        if (ledger && ledger.billwise) loadOpenBills(row, ledger.id);
        totals();
    }

    function addLine(data) {
        data = data || {};
        var row = lineTpl.content.firstElementChild.cloneNode(true);
        ledgerOptions(row.querySelector('.line-account'), data.account_id);

        var cc = row.querySelector('.line-cc');
        var none = document.createElement('option'); none.value = ''; none.textContent = 'No cost centre';
        cc.appendChild(none);
        cfg.costCentres.forEach(function (c) {
            var o = document.createElement('option'); o.value = c.id; o.textContent = c.name;
            if (String(data.cost_centre_id) === String(c.id)) o.selected = true;
            cc.appendChild(o);
        });

        if (!data.side) {
            var t = totals();
            data.side = t.dr > t.cr ? 'Cr' : 'Dr';
            if (t.dr !== t.cr && !data.amount) data.amount = fmt(Math.abs(t.dr - t.cr)).replace(/,/g, '');
        }
        row.querySelector('.line-side').value = data.side;
        row.querySelector('.line-amount').value = data.amount || '';
        row.querySelector('.line-narration').value = data.narration || '';

        row.querySelector('.line-side').addEventListener('change', totals);
        row.querySelector('.line-amount').addEventListener('input', totals);
        row.querySelector('.line-amount').addEventListener('blur', function (e) {
            var p = toPaise(e.target.value);
            if (p) e.target.value = fmt(p).replace(/,/g, '');
        });
        row.querySelector('.line-account').addEventListener('change', function () { onLedgerChange(row, false); });
        row.querySelector('.line-remove').addEventListener('click', function () {
            if (container.querySelectorAll('.voucher-line').length > 1) { row.remove(); renumber(); totals(); }
        });
        row.querySelector('.add-bill').addEventListener('click', function () {
            var allocated = 0;
            row.querySelectorAll('.bill-amount').forEach(function (i) { allocated += toPaise(i.value); });
            var rest = toPaise(row.querySelector('.line-amount').value) - allocated;
            addBill(row, { amount: rest > 0 ? fmt(rest).replace(/,/g, '') : '' });
            totals();
        });

        container.appendChild(row);
        (data.bills || []).forEach(function (b) { addBill(row, b); });
        renumber();
        onLedgerChange(row, true);
        return row;
    }

    document.getElementById('add-line').addEventListener('click', function () {
        var row = addLine();
        row.querySelector('.line-account').focus();
    });

    (cfg.lines.length ? cfg.lines : [{ side: 'Dr' }, { side: 'Cr' }]).forEach(function (l) { addLine(l); });
    totals();
})();
