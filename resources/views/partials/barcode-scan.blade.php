{{--
    Barcode scan box for a lines table. Expects $stockItems. The table rows need
    .js-item (ledger), .js-stock, .js-qty and .js-price fields, and an #add-line button.
    A USB scanner types the code and presses Enter.
--}}
@php
    $barcodes = $stockItems->whereNotNull('barcode')->mapWithKeys(fn ($i) => [$i->barcode => $i->id]);
@endphp
@if ($barcodes->isNotEmpty())
    <div class="input-group mb-3" style="max-width: 420px">
        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
        <input type="text" id="barcode-scan" class="form-control" placeholder="Scan barcode (or type it and press Enter)" aria-label="Scan barcode" autocomplete="off">
    </div>
    <div class="small mb-2" id="barcode-scan-message" role="status"></div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const codes = @json($barcodes);
        const input = document.getElementById('barcode-scan');
        const message = document.getElementById('barcode-scan-message');
        const body = document.querySelector('#lines tbody');

        input.addEventListener('keydown', e => {
            if (e.key !== 'Enter') return;
            e.preventDefault(); // don't submit the form
            const code = input.value.trim();
            input.value = '';
            const id = codes[code];
            if (!id) {
                message.className = 'small mb-2 text-danger';
                message.textContent = 'No item with barcode ' + code + '.';
                return;
            }

            // Same item already on the bill: one more of it.
            let row = [...body.rows].find(r => r.querySelector('.js-stock').value === String(id));
            if (row) {
                const qty = row.querySelector('.js-qty');
                qty.value = (parseFloat(qty.value) || 0) + 1;
                qty.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                row = [...body.rows].find(r => !r.querySelector('.js-stock').value && !r.querySelector('.js-taxable').value);
                if (!row) {
                    document.getElementById('add-line').click();
                    row = body.rows[body.rows.length - 1];
                }
                // Use the ledger already chosen on the bill, else the first one.
                const ledger = row.querySelector('.js-item');
                if (!ledger.value) {
                    const chosen = [...body.rows].map(r => r.querySelector('.js-item').value).find(v => v);
                    ledger.value = chosen || [...ledger.options].find(o => o.value)?.value || '';
                }
                const stock = row.querySelector('.js-stock');
                stock.value = String(id);
                stock.dispatchEvent(new Event('change', { bubbles: true }));
                row.querySelector('.js-qty').value = 1;
                row.querySelector('.js-qty').dispatchEvent(new Event('input', { bubbles: true }));
            }

            message.className = 'small mb-2 text-success';
            message.textContent = 'Added ' + row.querySelector('.js-stock').selectedOptions[0].text.trim() + '.';
        });
    });
    </script>
@endif
