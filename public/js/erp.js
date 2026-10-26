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

    // Keyboard shortcuts (Alt + key), shown in the help modal.
    document.addEventListener('keydown', function (e) {
        if (!e.altKey || e.ctrlKey || e.metaKey) return;
        var link = document.querySelector('[data-shortcut="' + e.key.toLowerCase() + '"]');
        if (link) { e.preventDefault(); link.click(); }
    });
})();
