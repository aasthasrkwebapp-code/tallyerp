// Business ERP - small progressive enhancements.
(function () {
    'use strict';

    // Sidebar collapse on large screens, remembered per browser.
    var KEY = 'erp.sidebarCollapsed';
    try {
        if (localStorage.getItem(KEY) === '1') document.body.classList.add('sidebar-collapsed');
    } catch (e) {}

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-erp-collapse]');
        if (!btn) return;
        var collapsed = document.body.classList.toggle('sidebar-collapsed');
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
    });

    // Confirm dialogs for destructive forms: <form data-confirm="...">
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) e.preventDefault();
    });

    // "/" or Ctrl+K jumps to the search box (or the search page on small screens).
    document.addEventListener('keydown', function (e) {
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
        var slash = e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey;
        var ctrlK = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k';
        if (!slash && !ctrlK) return;
        var box = document.getElementById('global-search');
        e.preventDefault();
        if (box && box.offsetParent !== null) { box.focus(); box.select(); return; }
        var link = document.querySelector('[data-search-link]');
        if (link) window.location = link.href;
    });

    // Search as you type: <input data-search-suggest="/search"> shows the first matches of
    // each group under the box. Arrow keys move, Enter opens, Escape closes.
    document.querySelectorAll('[data-search-suggest]').forEach(function (box) {
        var url = box.getAttribute('data-search-suggest');
        var list = document.createElement('div');
        var timer = null, seq = 0, active = -1;
        list.className = 'erp-suggest dropdown-menu shadow';
        list.id = (box.id || 'search') + '-suggest';
        list.setAttribute('role', 'listbox');
        box.setAttribute('role', 'combobox');
        box.setAttribute('aria-autocomplete', 'list');
        box.setAttribute('aria-controls', list.id);
        box.setAttribute('aria-expanded', 'false');
        var holder = box.closest('form') || box.parentNode;
        holder.style.position = 'relative';
        holder.appendChild(list);

        function items() { return list.querySelectorAll('[role=option]'); }
        function close() { list.classList.remove('show'); box.setAttribute('aria-expanded', 'false'); active = -1; box.removeAttribute('aria-activedescendant'); }
        function mark(i) {
            var all = items();
            if (!all.length) return;
            active = (i + all.length) % all.length;
            all.forEach(function (a, n) { a.classList.toggle('active', n === active); a.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
            box.setAttribute('aria-activedescendant', all[active].id);
            all[active].scrollIntoView({ block: 'nearest' });
        }
        function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
        function render(data, term) {
            list.innerHTML = '';
            active = -1;
            var n = 0;
            if (!data.groups.length) {
                list.appendChild(el('div', 'dropdown-item-text small text-secondary', 'No matches for "' + term + '".'));
            }
            data.groups.forEach(function (g) {
                var head = el('h6', 'dropdown-header');
                var icon = el('i', 'bi ' + g.icon + ' me-1');
                head.appendChild(icon);
                head.appendChild(document.createTextNode(g.label));
                list.appendChild(head);
                g.rows.slice(0, 5).forEach(function (r) {
                    var a = el('a', 'dropdown-item');
                    a.href = r.url;
                    a.id = list.id + '-' + (n++);
                    a.setAttribute('role', 'option');
                    a.appendChild(el('div', 'text-truncate', r.title));
                    if (r.meta) a.appendChild(el('div', 'small text-secondary text-truncate', r.meta));
                    list.appendChild(a);
                });
            });
            var all = el('a', 'dropdown-item border-top small fw-semibold', 'All results for "' + term + '"');
            all.href = url + '?q=' + encodeURIComponent(term);
            all.id = list.id + '-' + n;
            all.setAttribute('role', 'option');
            list.appendChild(all);
            list.classList.add('show');
            box.setAttribute('aria-expanded', 'true');
        }
        box.addEventListener('input', function () {
            clearTimeout(timer);
            var term = box.value.trim();
            if (term.length < 2) { close(); return; }
            timer = setTimeout(function () {
                var mine = ++seq;
                fetch(url + '?q=' + encodeURIComponent(term), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) { if (data && mine === seq && box.value.trim() === term) render(data, term); })
                    .catch(function () {});
            }, 200);
        });
        box.addEventListener('keydown', function (e) {
            if (!list.classList.contains('show')) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
            else if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); window.location = items()[active].href; }
        });
        box.addEventListener('blur', function () { setTimeout(close, 150); });
        box.addEventListener('focus', function () { if (list.children.length && box.value.trim().length >= 2) { list.classList.add('show'); box.setAttribute('aria-expanded', 'true'); } });
    });

    // Keyboard shortcuts (Alt + key), shown in the help modal.
    document.addEventListener('keydown', function (e) {
        if (!e.altKey || e.ctrlKey || e.metaKey) return;
        var link = document.querySelector('[data-shortcut="' + e.key.toLowerCase() + '"]');
        if (link) { e.preventDefault(); link.click(); }
    });
})();
