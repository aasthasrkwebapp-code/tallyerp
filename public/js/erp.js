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

    // Keyboard shortcuts (Alt + key), shown in the help modal.
    document.addEventListener('keydown', function (e) {
        if (!e.altKey || e.ctrlKey || e.metaKey) return;
        var link = document.querySelector('[data-shortcut="' + e.key.toLowerCase() + '"]');
        if (link) { e.preventDefault(); link.click(); }
    });
})();
