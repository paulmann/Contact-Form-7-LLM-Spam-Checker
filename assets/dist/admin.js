/**
 * Spam Checker Pro for Contact Form 7 — admin application.
 *
 * Vanilla ES2017+, zero dependencies, zero external requests.
 * Talks to the plugin REST API (cf7sec/v1) with the localized nonce.
 */
(function () {
    'use strict';

    var CFG = window.CF7SEC_CONFIG;
    if (!CFG) { return; }

    /* ============================ helpers ============================ */

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function api(path, options) {
        options = options || {};
        var init = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': CFG.nonce }
        };
        if (options.body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return fetch(CFG.restUrl + path.replace(/^\//, ''), init).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = text; }
                if (!res.ok) {
                    var msg = (data && (data.message || data.code)) || ('HTTP ' + res.status);
                    throw new Error(msg);
                }
                return data;
            });
        });
    }

    function download(path, filename) {
        return fetch(CFG.restUrl + path.replace(/^\//, ''), {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': CFG.nonce }
        }).then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.blob();
        }).then(function (blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
            toast(CFG.i18n.exportStarted, 'success');
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    var ICONS = {
        check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4m0 4h.01"/></svg>'
    };

    function toast(message, type) {
        var root = $('#cf7sec-toasts');
        if (!root) { return; }
        var el = document.createElement('div');
        el.className = 'c-toast' + (type ? ' c-toast-' + type : '');
        var icon = type === 'success' ? ICONS.check : (type === 'error' ? ICONS.alert : '');
        el.innerHTML = icon + '<div>' + esc(message) + '</div>';
        root.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .25s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 260);
        }, type === 'error' ? 6000 : 3200);
    }

    function openModal(opts) {
        var root = $('#cf7sec-modal-root');
        if (!root) { return null; }
        closeModal();
        var overlay = document.createElement('div');
        overlay.className = 'c-modal-overlay';
        var modal = document.createElement('div');
        modal.className = 'c-modal' + (opts.wide ? ' c-modal-wide' : '');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var html = '';
        if (opts.title) {
            html += '<div class="c-modal-header"><span class="c-modal-title">' + esc(opts.title) + '</span>'
                + '<button type="button" class="c-btn c-btn-ghost c-btn-icon c-btn-sm" data-modal-close aria-label="Close">' + ICONS.x + '</button></div>';
        }
        html += '<div class="c-modal-body">' + (opts.body || '') + '</div>';
        if (opts.buttons && opts.buttons.length) {
            html += '<div class="c-modal-footer">';
            opts.buttons.forEach(function (b, i) {
                html += '<button type="button" class="c-btn c-btn-' + (b.variant || 'outline') + '" data-modal-btn="' + i + '">' + esc(b.label) + '</button>';
            });
            html += '</div>';
        }
        modal.innerHTML = html;
        overlay.appendChild(modal);
        root.appendChild(overlay);

        function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
        function onKey(e) { if (e.key === 'Escape') { close(); } }
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
        $$('[data-modal-close]', modal).forEach(function (b) { b.addEventListener('click', close); });
        (opts.buttons || []).forEach(function (b, i) {
            var btn = $('[data-modal-btn="' + i + '"]', modal);
            if (btn) {
                btn.addEventListener('click', function () {
                    if (!b.onClick || b.onClick(modal) !== false) { close(); }
                });
            }
        });
        return { el: modal, close: close };
    }

    function closeModal() {
        var root = $('#cf7sec-modal-root');
        if (root) { root.innerHTML = ''; }
    }

    function confirmDialog(message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var done = false;
            var m = openModal({
                title: opts.title || CFG.i18n.confirm,
                body: '<p class="c-muted" style="font-size:14px">' + esc(message) + '</p>',
                buttons: [
                    { label: opts.cancelLabel || 'Cancel', variant: 'outline', onClick: function () { done = true; resolve(false); } },
                    {
                        label: opts.okLabel || 'OK', variant: opts.danger ? 'destructive' : 'primary',
                        onClick: function () { done = true; resolve(true); }
                    }
                ]
            });
            if (m) {
                m.el.closest('.c-modal-overlay').addEventListener('click', function (e) {
                    if (e.target.classList.contains('c-modal-overlay') && !done) { resolve(false); }
                });
            }
        });
    }

    function spinner(btn, on) {
        if (!btn) { return; }
        if (on) {
            btn.dataset.busy = '1';
            btn.setAttribute('aria-disabled', 'true');
            var svg = btn.querySelector('svg');
            if (svg) { svg.classList.add('c-spin'); }
        } else {
            delete btn.dataset.busy;
            btn.removeAttribute('aria-disabled');
            var svg2 = btn.querySelector('svg');
            if (svg2) { svg2.classList.remove('c-spin'); }
        }
    }

    function fmtDate(iso) {
        if (!iso) { return '—'; }
        var d = new Date(String(iso).replace(' ', 'T') + (String(iso).endsWith('Z') ? '' : 'Z'));
        if (isNaN(d)) { return iso; }
        return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    /** Localized label with English fallback. */
    function t(key, fallback) {
        return (CFG.i18n && CFG.i18n[key]) || fallback;
    }

    /* ============================ theme ============================ */

    var THEME_KEY = 'cf7sec-theme';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function getTheme() {
        try { return localStorage.getItem(THEME_KEY) || 'system'; } catch (e) { return 'system'; }
    }

    function applyTheme(theme) {
        var dark = theme === 'dark' || (theme === 'system' && media && media.matches);
        document.documentElement.classList.toggle('cf7sec-dark', !!dark);
        document.documentElement.dataset.cf7secTheme = theme;
        var btn = $('#cf7sec-theme-toggle');
        if (btn) {
            var label = theme === 'dark' ? CFG.i18n.themeDark : (theme === 'light' ? CFG.i18n.themeLight : CFG.i18n.themeSystem);
            btn.title = label;
        }
    }

    function initTheme() {
        applyTheme(getTheme());
        var btn = $('#cf7sec-theme-toggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var next = { light: 'dark', dark: 'system', system: 'light' }[getTheme()] || 'light';
                try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* ignore */ }
                applyTheme(next);
            });
        }
        if (media && media.addEventListener) {
            media.addEventListener('change', function () { if (getTheme() === 'system') { applyTheme('system'); } });
        }
    }

    /* ============================ switches ============================ */

    function initSwitches() {
        document.addEventListener('click', function (e) {
            var sw = e.target.closest ? e.target.closest('[role="switch"]') : null;
            if (!sw) { return; }
            toggleSwitch(sw);
        });
        document.addEventListener('keydown', function (e) {
            if ((e.key === ' ' || e.key === 'Enter') && e.target && e.target.matches && e.target.matches('[role="switch"]')) {
                e.preventDefault();
                toggleSwitch(e.target);
            }
        });
    }

    function toggleSwitch(sw) {
        var on = sw.getAttribute('aria-checked') !== 'true';
        sw.setAttribute('aria-checked', on ? 'true' : 'false');
        var hidden = sw.parentElement.querySelector('[data-switch-input]');
        if (hidden) {
            hidden.value = on ? '1' : '0';
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /* ============================ settings form ============================ */

    var formsState = new WeakMap();

    function collectForm(form) {
        var data = {};
        $$('input[name], select[name], textarea[name]', form).forEach(function (el) {
            if (el.disabled) { return; }
            var name = el.getAttribute('name');
            var isArray = name.endsWith('[]');
            var key = isArray ? name.slice(0, -2) : name;
            var value;

            if (el.type === 'checkbox') {
                if (!el.checked) { return; }
                value = el.value === '1' ? true : el.value;
            } else if (el.hasAttribute('data-switch-input')) {
                value = el.value === '1';
            } else if (el.type === 'number') {
                value = el.value === '' ? 0 : Number(el.value);
            } else {
                value = el.value;
            }

            setPath(data, key.split('.'), isArray ? (getPathVariable(data, key) || []).concat([value]) : value);
        });
        return data;
    }

    function getPathVariable(data, key) {
        var node = data;
        var parts = key.split('.');
        for (var i = 0; i < parts.length; i++) {
            if (node === undefined || node === null) { return undefined; }
            node = node[parts[i]];
        }
        return node;
    }

    function setPath(obj, parts, value) {
        var node = obj;
        for (var i = 0; i < parts.length - 1; i++) {
            if (typeof node[parts[i]] !== 'object' || node[parts[i]] === null) { node[parts[i]] = {}; }
            node = node[parts[i]];
        }
        node[parts[parts.length - 1]] = value;
    }

    function initSettingsForms() {
        $$('[data-settings-form]').forEach(function (form) {
            var state = { baseline: JSON.stringify(collectForm(form)) };
            formsState.set(form, state);

            form.addEventListener('input', function () { markDirty(form); });
            form.addEventListener('change', function () { markDirty(form); });
        });

        var saveBtn = $('#cf7sec-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () { saveAllForms(); });
        }
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                var form = currentForm();
                if (form) { e.preventDefault(); saveAllForms(); }
            }
        });
    }

    function currentForm() {
        return $('[data-settings-form]');
    }

    function markDirty(form) {
        var state = formsState.get(form);
        if (!state) { return; }
        var dirty = JSON.stringify(collectForm(form)) !== state.baseline;
        var saveBtn = $('#cf7sec-save');
        if (saveBtn) {
            saveBtn.hidden = !dirty && !anyDirty();
            saveBtn.classList.toggle('c-btn-primary', true);
        }
    }

    function anyDirty() {
        var dirty = false;
        $$('[data-settings-form]').forEach(function (f) {
            var st = formsState.get(f);
            if (st && JSON.stringify(collectForm(f)) !== st.baseline) { dirty = true; }
        });
        return dirty;
    }

    function saveAllForms() {
        var form = currentForm();
        if (!form) { return Promise.resolve(); }
        var saveBtn = $('#cf7sec-save');
        spinner(saveBtn, true);
        return api('/settings', { method: 'POST', body: collectForm(form) })
            .then(function (resp) {
                // Re-mask secret fields with the server-side masked values.
                if (resp && resp.settings) {
                    $('.c-secret', form).forEach(function (el) {
                        var v = getPathVariable(resp.settings, el.getAttribute('name'));
                        if (typeof v === 'string') { el.value = v; el.dataset.mask = v; }
                    });
                }
                var st = formsState.get(form);
                if (st) { st.baseline = JSON.stringify(collectForm(form)); }
                if (saveBtn) { saveBtn.hidden = true; }
                toast(CFG.i18n.saved, 'success');
            })
            .catch(function (err) { toast(err.message || CFG.i18n.saveError, 'error'); })
            .finally(function () { spinner(saveBtn, false); });
    }

    function ensureSaved() {
        return anyDirty() ? saveAllForms() : Promise.resolve();
    }

    /* ============================ secret fields ============================ */

    function initSecrets() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.c-secret-toggle') : null;
            if (!btn) { return; }
            var input = document.getElementById(btn.dataset.target);
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? CFG.i18n.hideKey : CFG.i18n.showKey);
            btn.title = show ? CFG.i18n.hideKey : CFG.i18n.showKey;
        });
        document.addEventListener('focusin', function (e) {
            if (e.target.classList && e.target.classList.contains('c-secret') && e.target.value.indexOf('\u2022') !== -1) {
                e.target.value = '';
                e.target.placeholder = e.target.placeholder || 'sk-…';
            }
        });
        document.addEventListener('focusout', function (e) {
            if (e.target.classList && e.target.classList.contains('c-secret') && e.target.value === '') {
                var form = e.target.closest('[data-settings-form]');
                if (form) {
                    // restore mask from server state on next save cycle — leave blank means "unchanged"
                }
                e.target.value = e.target.dataset.mask || '';
            }
        });
        $$('.c-secret').forEach(function (el) { el.dataset.mask = el.value; });
    }

    /* ============================ tabs ============================ */

    function initTabs() {
        $$('[data-tabs]').forEach(function (bar) {
            $$('.c-tab', bar).forEach(function (tab) {
                tab.addEventListener('click', function () {
                    $$('.c-tab', bar).forEach(function (t) {
                        t.classList.remove('c-tab-active');
                        t.setAttribute('aria-selected', 'false');
                    });
                    tab.classList.add('c-tab-active');
                    tab.setAttribute('aria-selected', 'true');

                    var target = tab.dataset.tabTarget;
                    if (target) {
                        $$('[data-tab-panel]').forEach(function (p) {
                            p.hidden = p.dataset.tabPanel !== target;
                        });
                        if (history.replaceState) { history.replaceState(null, '', '#' + target); }
                    }
                    if (tab.dataset.ipTab !== undefined) {
                        ipState.type = tab.dataset.ipTab;
                        ipState.page = 1;
                        loadIpRules();
                    }
                });
            });
        });

        // deep-link via hash
        var hash = location.hash.replace('#', '');
        if (hash) {
            var tab = $('.c-tab[data-tab-target="' + hash + '"]');
            if (tab) { tab.click(); }
        }
    }

    function initShowIf() {
        function sync() {
            $$('[data-show-if]').forEach(function (el) {
                var name = el.dataset.showIf;
                var control = $('[name="' + name + '"]');
                if (!control) { return; }
                el.hidden = control.value !== el.dataset.showValue;
            });
        }
        document.addEventListener('change', sync);
        sync();
    }

    function initRanges() {
        $$('input[data-range-for]').forEach(function (range) {
            var num = $('[name="' + range.dataset.rangeFor + '"]');
            if (!num) { return; }
            range.addEventListener('input', function () {
                num.value = range.value;
                num.dispatchEvent(new Event('input', { bubbles: true }));
            });
            num.addEventListener('input', function () {
                range.value = num.value;
            });
        });
    }

    /* ============================ dashboard ============================ */

    function loadStats() {
        return api('/stats?days=30').then(function (data) {
            renderChart(data.series);
            var kpis = $('.c-kpi-grid');
            if (kpis) {
                var vals = kpis.querySelectorAll('.c-kpi-value');
                if (vals.length >= 5) {
                    vals[0].textContent = String(data.today.processed);
                    vals[1].textContent = String(data.today.blocked);
                    vals[2].textContent = String(data.bans);
                    vals[3].textContent = String(data.whitelist);
                    vals[4].textContent = String(data.blocked_registry);
                    var rate = data.totals.processed > 0
                        ? (Math.round(data.totals.blocked / data.totals.processed * 1000) / 10) + '%' : '0%';
                    vals[5].textContent = rate;
                }
            }
        }).catch(function (e) { console.warn('stats', e); });
    }

    function renderChart(series) {
        var host = $('#cf7sec-chart');
        if (!host || !series || !series.days) { return; }
        var W = 900, H = 240, PAD_L = 42, PAD_B = 26, PAD_T = 10;
        var n = series.days.length;
        var max = Math.max(10, Math.max.apply(null, series.processed), Math.max.apply(null, series.blocked));
        max = Math.ceil(max / 5) * 5;
        var plotW = W - PAD_L - 8, plotH = H - PAD_T - PAD_B;
        var slot = plotW / n;
        var barW = Math.max(2, Math.min(14, slot * 0.34));

        var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img">';
        // gridlines
        for (var g = 0; g <= 4; g++) {
            var y = PAD_T + plotH - (plotH * g / 4);
            var val = Math.round(max * g / 4);
            svg += '<line x1="' + PAD_L + '" y1="' + y + '" x2="' + W + '" y2="' + y + '" stroke="hsl(var(--cf7sec-border))" stroke-width="1" stroke-dasharray="' + (g === 0 ? '0' : '3,3') + '"/>';
            svg += '<text x="' + (PAD_L - 6) + '" y="' + (y + 4) + '" text-anchor="end" font-size="10" fill="hsl(var(--cf7sec-muted-foreground))">' + val + '</text>';
        }
        for (var i = 0; i < n; i++) {
            var x = PAD_L + slot * i + slot / 2;
            var h1 = Math.round(series.processed[i] / max * plotH);
            var h2 = Math.round(series.blocked[i] / max * plotH);
            var label = series.days[i].slice(5);
            svg += '<rect x="' + (x - barW - 1) + '" y="' + (PAD_T + plotH - h1) + '" width="' + barW + '" height="' + Math.max(h1, series.processed[i] > 0 ? 2 : 0) + '" rx="2" fill="hsl(var(--cf7sec-chart-1))" opacity="0.85"><title>' + label + ': ' + series.processed[i] + ' processed</title></rect>';
            svg += '<rect x="' + (x + 1) + '" y="' + (PAD_T + plotH - h2) + '" width="' + barW + '" height="' + Math.max(h2, series.blocked[i] > 0 ? 2 : 0) + '" rx="2" fill="hsl(var(--cf7sec-chart-2))" opacity="0.9"><title>' + label + ': ' + series.blocked[i] + ' blocked</title></rect>';
            if (i % Math.ceil(n / 8) === 0 || i === n - 1) {
                svg += '<text x="' + x + '" y="' + (H - 8) + '" text-anchor="middle" font-size="10" fill="hsl(var(--cf7sec-muted-foreground))">' + label + '</text>';
            }
        }
        svg += '</svg>';
        host.innerHTML = svg;
    }

    function loadHealth() {
        return api('/health').then(function (h) {
            var rows = [
                ['PHP ≥ 8.3', h.php, h.php_ok],
                ['WordPress', h.wp, true],
                ['Contact Form 7', h.cf7 || '—', h.cf7_ok],
                ['Database tables', h.tables_ok ? 'OK' : ('missing: ' + h.tables_missing.join(', ')), h.tables_ok],
                ['Log directory writable', h.log_dir, h.log_dir_ok],
                ['Daily cron', h.cron_scheduled ? 'scheduled' : 'NOT scheduled', h.cron_scheduled],
                ['AbuseIPDB daily budget', h.abuse_budget + ' queries', h.abuse_budget > 0]
            ];
            var html = rows.map(function (r) {
                return '<div class="c-health-row"><span class="c-health-label">'
                    + (r[2] ? '<span class="c-health-ok">' + ICONS.check + '</span>' : '<span class="c-health-bad">' + ICONS.alert + '</span>')
                    + esc(r[0]) + '</span><span class="c-health-val">' + esc(r[1]) + '</span></div>';
            }).join('');
            var host = $('#cf7sec-health');
            if (host) { host.innerHTML = html; }
            var hostFull = $('#cf7sec-health-full');
            if (hostFull) { hostFull.innerHTML = html; }
        }).catch(function (e) { console.warn('health', e); });
    }

    function verdictBadge(v) {
        var map = { blocked: 'destructive', allowed: 'success', error: 'warning', info: 'info' };
        return '<span class="c-badge c-badge-' + (map[v] || 'muted') + '">' + esc(v) + '</span>';
    }

    function loadRecentEvents() {
        var host = $('#cf7sec-recent');
        if (!host) { return Promise.resolve(); }
        return api('/events?per_page=8').then(function (data) {
            if (!data.items.length) {
                host.innerHTML = '<p class="c-empty">' + esc(CFG.i18n.empty) + '</p>';
                return;
            }
            host.innerHTML = eventsTable(data.items, false);
        }).catch(function (e) { host.innerHTML = '<p class="c-empty">' + esc(e.message) + '</p>'; });
    }

    function eventsTable(items, withSelect) {
        var html = '<table class="c-table"><thead><tr>'
            + '<th>' + esc(t('time', 'Time')) + '</th><th>' + esc(t('type', 'Type')) + '</th><th>' + esc(t('verdict', 'Verdict')) + '</th>'
            + '<th>' + esc(t('ip', 'IP')) + '</th><th>' + esc(t('reason', 'Reason')) + '</th><th></th></tr></thead><tbody>';
        items.forEach(function (ev) {
            var reason = (ev.reasons && ev.reasons[0]) || '—';
            html += '<tr>'
                + '<td class="c-cell-dim" style="white-space:nowrap">' + esc(fmtDate(ev.created_at)) + '</td>'
                + '<td><span class="c-badge c-badge-muted">' + esc(ev.event_type) + '</span></td>'
                + '<td>' + verdictBadge(ev.verdict) + '</td>'
                + '<td class="c-cell-mono">' + esc(ev.ip || '—') + (ev.country ? ' <span class="c-cell-dim">(' + esc(ev.country) + ')</span>' : '') + '</td>'
                + '<td class="c-cell-dim" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(reason) + '">' + esc(reason) + '</td>'
                + '<td><div class="c-table-actions">'
                + '<button type="button" class="c-btn c-btn-ghost c-btn-sm" data-action="event-details" data-id="' + ev.id + '">' + esc(t('details', 'Details')) + '</button>'
                + '</div></td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    /* ============================ registry page ============================ */

    var regState = { page: 1, perPage: 20, verdict: '', event_type: '', search: '', from: '', to: '' };

    function regQuery() {
        var q = ['page=' + regState.page, 'per_page=' + regState.perPage];
        if (regState.verdict) { q.push('verdict=' + encodeURIComponent(regState.verdict)); }
        if (regState.event_type) { q.push('event_type=' + encodeURIComponent(regState.event_type)); }
        if (regState.search) { q.push('search=' + encodeURIComponent(regState.search)); }
        if (regState.from) { q.push('from=' + encodeURIComponent(regState.from)); }
        if (regState.to) { q.push('to=' + encodeURIComponent(regState.to)); }
        return q.join('&');
    }

    function loadEvents() {
        var host = $('#cf7sec-events-table');
        if (!host) { return; }
        host.innerHTML = '<p class="c-muted">' + esc(CFG.i18n.loading) + '</p>';
        api('/events?' + regQuery()).then(function (data) {
            if (!data.items.length) {
                host.innerHTML = '<p class="c-empty">' + esc(CFG.i18n.empty) + '</p>';
            } else {
                host.innerHTML = eventsTable(data.items, true);
            }
            renderPager($('#cf7sec-events-pager'), data.total, regState.page, function (p) {
                regState.page = p; loadEvents();
            });
            var typeSel = $('[data-filter="event_type"]');
            if (typeSel && data.types && typeSel.options.length <= 1 + data.types.length) {
                if (typeSel.dataset.filled !== '1') {
                    data.types.forEach(function (t) {
                        var o = document.createElement('option');
                        o.value = t; o.textContent = t;
                        typeSel.appendChild(o);
                    });
                    typeSel.dataset.filled = '1';
                }
            }
        }).catch(function (e) { host.innerHTML = '<p class="c-empty">' + esc(e.message) + '</p>'; });
    }

    function renderPager(host, total, page, onGo) {
        if (!host) { return; }
        var pages = Math.max(1, Math.ceil(total / (regState.perPage || 20)));
        if (total === 0) { host.innerHTML = ''; return; }
        host.innerHTML = '<span>' + esc(CFG.i18n.page) + ' ' + page + ' ' + esc(CFG.i18n.of) + ' ' + pages + ' · ' + total + ' ' + esc(t('items', 'items')) + '</span>'
            + '<span class="c-pager-btns">'
            + '<button type="button" class="c-btn c-btn-outline c-btn-sm" data-pager="prev"' + (page <= 1 ? ' disabled' : '') + '>←</button>'
            + '<button type="button" class="c-btn c-btn-outline c-btn-sm" data-pager="next"' + (page >= pages ? ' disabled' : '') + '>→</button>'
            + '</span>';
        $$('[data-pager]', host).forEach(function (b) {
            b.addEventListener('click', function () {
                var p = b.dataset.pager === 'prev' ? page - 1 : page + 1;
                if (p >= 1 && p <= pages) { onGo(p); }
            });
        });
    }

    function showEventDetails(id) {
        api('/events/' + id).then(function (ev) {
            var body = '';
            body += '<div class="c-detail-section"><div class="c-detail-title">' + esc(t('submission', 'Submission')) + '</div>'
                + '<div class="c-lookup-grid">'
                + lookupRow('Time', fmtDate(ev.created_at))
                + lookupRow('Form ID', ev.form_id || '—')
                + lookupRow('IP', ev.ip || '—')
                + lookupRow('Country', ev.country || '—')
                + lookupRow('Verdict', ev.verdict)
                + lookupRow('Type', ev.event_type)
                + '</div></div>';
            body += '<div class="c-detail-section"><div class="c-detail-title">' + esc(t('userAgent', 'User agent')) + '</div><div class="c-json-view">' + esc(ev.user_agent || '—') + '</div></div>';
            if (ev.referrer) {
                body += '<div class="c-detail-section"><div class="c-detail-title">' + esc(t('referrer', 'Referrer')) + '</div><div class="c-json-view">' + esc(ev.referrer) + '</div></div>';
            }
            if (ev.checks && ev.checks.length) {
                body += '<div class="c-detail-section"><div class="c-detail-title">' + esc(t('checkResults', 'Check results')) + '</div><div class="c-check-list">';
                ev.checks.forEach(function (c) {
                    var cls = c.status === 'fail' ? ' c-check-row-fail' : (c.status === 'error' ? ' c-check-row-error' : '');
                    body += '<div class="c-check-row' + cls + '"><div style="min-width:0">'
                        + '<span class="c-check-id">' + esc(c.check) + '</span> '
                        + '<span class="c-badge c-badge-' + (c.status === 'fail' ? 'destructive' : c.status === 'pass' ? 'success' : c.status === 'error' ? 'warning' : 'muted') + '">' + esc(c.status) + '</span>'
                        + (c.reason ? '<div class="c-check-reason">' + esc(c.reason) + '</div>' : '')
                        + (c.details && Object.keys(c.details).length ? '<details style="margin-top:4px"><summary class="c-check-id" style="cursor:pointer">details</summary><div class="c-json-view" style="margin-top:4px">' + esc(JSON.stringify(c.details, null, 2)) + '</div></details>' : '')
                        + '</div></div>';
                });
                body += '</div></div>';
            }
            if (ev.payload) {
                body += '<div class="c-detail-section"><div class="c-detail-title">' + esc(t('payload', 'Payload')) + '</div><div class="c-json-view">' + esc(JSON.stringify(ev.payload, null, 2)) + '</div></div>';
            }

            var buttons = [];
            if (ev.ip && ev.verdict === 'blocked') {
                buttons.push({
                    label: t('banIp', 'Ban IP') + ' ' + ev.ip, variant: 'destructive', onClick: function () {
                        api('/ip-rules', { method: 'POST', body: { ip: ev.ip, type: 'ban', reason: 'From registry event #' + ev.id, duration_hours: 0 } })
                            .then(function () { toast(CFG.i18n.banned, 'success'); })
                            .catch(function (e) { toast(e.message, 'error'); });
                    }
                });
            }
            openModal({ title: 'Event #' + ev.id, body: body, buttons: buttons, wide: true });
        }).catch(function (e) { toast(e.message, 'error'); });
    }

    function lookupRow(k, v) {
        return '<div class="c-lookup-row"><span>' + esc(k) + '</span><span>' + esc(v) + '</span></div>';
    }

    /* ============================ IP rules page ============================ */

    var ipState = { type: 'ban', page: 1, perPage: 20, search: '' };

    function loadIpRules() {
        var host = $('#cf7sec-ip-table');
        if (!host) { return; }
        host.innerHTML = '<p class="c-muted">' + esc(CFG.i18n.loading) + '</p>';
        var q = 'type=' + ipState.type + '&page=' + ipState.page + '&per_page=' + ipState.perPage
            + (ipState.search ? '&search=' + encodeURIComponent(ipState.search) : '');
        api('/ip-rules?' + q).then(function (data) {
            var countHost = $('#cf7sec-rule-count');
            if (countHost) { countHost.textContent = data.total + ' ' + (ipState.type === 'ban' ? t('bans', 'bans') : CFG.i18n.whitelisted); }
            if (!data.items.length) {
                host.innerHTML = '<p class="c-empty">' + esc(CFG.i18n.empty) + '</p>';
            } else {
                host.innerHTML = ipTable(data.items);
            }
            var pager = $('#cf7sec-ip-pager');
            regState.perPage = ipState.perPage;
            renderPager(pager, data.total, ipState.page, function (p) { ipState.page = p; loadIpRules(); });
            regState.perPage = 20;
        }).catch(function (e) { host.innerHTML = '<p class="c-empty">' + esc(e.message) + '</p>'; });
    }

    function ipTable(items) {
        var html = '<table class="c-table"><thead><tr>'
            + '<th style="width:34px"><input type="checkbox" class="c-checkbox" data-action="toggle-all-rules" aria-label="' + esc(CFG.i18n.selectAll) + '"></th>'
            + '<th>' + esc(t('ip', 'IP')) + '</th><th>' + esc(t('reason', 'Reason')) + '</th><th>' + esc(t('source', 'Source')) + '</th><th>' + esc(t('strikes', 'Strikes')) + '</th>'
            + '<th>' + esc(t('created', 'Created')) + '</th><th>' + esc(t('expires', 'Expires')) + '</th><th></th></tr></thead><tbody>';
        items.forEach(function (r) {
            var expires = r.permanent
                ? '<span class="c-badge c-badge-destructive">' + esc('permanent') + '</span>'
                : (r.expired ? '<span class="c-badge c-badge-muted">' + esc('expired') + '</span>' : '<span class="c-cell-dim">' + esc(fmtDate(r.expires_at)) + '</span>');
            html += '<tr>'
                + '<td><input type="checkbox" class="c-checkbox" data-rule-ip="' + esc(r.ip) + '"></td>'
                + '<td class="c-cell-mono c-cell-main">' + esc(r.ip) + (r.list_type === 'whitelist' ? ' <span class="c-badge c-badge-success">wl</span>' : '') + '</td>'
                + '<td class="c-cell-dim" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.reason) + '">' + esc(r.reason || '—') + '</td>'
                + '<td><span class="c-badge c-badge-outline">' + esc(r.source || '—') + '</span></td>'
                + '<td>' + (r.strikes > 0 ? '<span class="c-badge c-badge-warning">' + r.strikes + '</span>' : '<span class="c-cell-dim">0</span>') + '</td>'
                + '<td class="c-cell-dim" style="white-space:nowrap">' + esc(fmtDate(r.created_at)) + '</td>'
                + '<td style="white-space:nowrap">' + expires + '</td>'
                + '<td><div class="c-table-actions">'
                + '<button type="button" class="c-btn c-btn-ghost c-btn-sm" data-action="lookup-ip-row" data-ip="' + esc(r.ip) + '" title="AbuseIPDB lookup">🔎</button>'
                + '<button type="button" class="c-btn c-btn-ghost c-btn-sm c-text-danger" data-action="delete-rule" data-ip="' + esc(r.ip) + '">✕</button>'
                + '</div></td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function selectedRuleIps() {
        return $$('[data-rule-ip]').filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.dataset.ruleIp; });
    }

    /* ============================ log viewer ============================ */

    function loadLogDates() {
        api('/logs/dates').then(function (data) {
            var sel = $('#cf7sec-log-date');
            if (!sel) { return; }
            sel.innerHTML = '';
            if (!data.dates.length) {
                sel.innerHTML = '<option value="">' + esc('No logs') + '</option>';
                return;
            }
            data.dates.forEach(function (d) {
                var o = document.createElement('option');
                o.value = d; o.textContent = d;
                sel.appendChild(o);
            });
            loadLogFile(data.dates[0]);
        }).catch(function () { /* silent */ });
    }

    function loadLogFile(date) {
        var view = $('#cf7sec-log-view');
        if (!view || !date) { return; }
        view.textContent = CFG.i18n.loading;
        api('/logs?date=' + encodeURIComponent(date)).then(function (data) {
            view.textContent = data.lines.length ? data.lines.join('\n') : '(empty)';
            view.scrollTop = view.scrollHeight;
        }).catch(function (e) { view.textContent = e.message; });
    }

    /* ============================ global actions ============================ */

    var ACTIONS = {
        'refresh-dashboard': function () { loadStats(); loadHealth(); loadRecentEvents(); },
        'refresh-health': function () { loadHealth(); },
        'apply-filters': function () {
            $$('[data-filter]').forEach(function (el) {
                var key = el.dataset.filter;
                regState[key] = el.value;
            });
            regState.page = 1;
            loadEvents();
        },
        'reset-filters': function () {
            $$('[data-filter]').forEach(function (el) { el.value = ''; });
            regState = { page: 1, perPage: 20, verdict: '', event_type: '', search: '', from: '', to: '' };
            loadEvents();
        },
        'event-details': function (btn) { showEventDetails(btn.dataset.id); },
        'export-events': function () {
            var q = [];
            if (regState.verdict) { q.push('verdict=' + encodeURIComponent(regState.verdict)); }
            if (regState.event_type) { q.push('event_type=' + encodeURIComponent(regState.event_type)); }
            if (regState.search) { q.push('search=' + encodeURIComponent(regState.search)); }
            if (regState.from) { q.push('from=' + encodeURIComponent(regState.from)); }
            if (regState.to) { q.push('to=' + encodeURIComponent(regState.to)); }
            download('/events/export' + (q.length ? '?' + q.join('&') : ''), 'cf7sec-registry.csv');
        },
        'clear-events': function () {
            confirmDialog(CFG.i18n.confirmClear, { danger: true, okLabel: t('deleteAll', 'Delete all') }).then(function (ok) {
                if (!ok) { return; }
                api('/events/clear', { method: 'POST' }).then(function () {
                    toast(CFG.i18n.deleted, 'success');
                    loadEvents();
                }).catch(function (e) { toast(e.message, 'error'); });
            });
        },
        'add-rule': function (btn) {
            var ip = ($('#rule-ip') || {}).value || '';
            var type = ($('#rule-type') || {}).value || 'ban';
            var duration = parseInt(($('#rule-duration') || {}).value || '1', 10);
            var reason = ($('#rule-reason') || {}).value || '';
            if (!ip) { toast(t('ipRequired', 'IP is required'), 'error'); return; }
            spinner(btn, true);
            api('/ip-rules', { method: 'POST', body: { ip: ip, type: type, duration_hours: duration, reason: reason } })
                .then(function () {
                    toast(type === 'ban' ? CFG.i18n.banned : CFG.i18n.whitelisted, 'success');
                    $('#rule-ip').value = '';
                    $('#rule-reason').value = '';
                    ipState.type = type;
                    $$('.c-tab[data-ip-tab]').forEach(function (t) {
                        var active = t.dataset.ipTab === type;
                        t.classList.toggle('c-tab-active', active);
                        t.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    ipState.page = 1;
                    loadIpRules();
                })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { spinner(btn, false); });
        },
        'delete-rule': function (btn) {
            var ip = btn.dataset.ip;
            confirmDialog(CFG.i18n.confirmUnban + '\n' + ip, { danger: true, okLabel: t('remove', 'Remove') }).then(function (ok) {
                if (!ok) { return; }
                api('/ip-rules/' + encodeURIComponent(ip), { method: 'DELETE' })
                    .then(function () { toast(CFG.i18n.unbanned, 'success'); loadIpRules(); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        },
        'toggle-all-rules': function (cb) {
            $$('[data-rule-ip]').forEach(function (x) { x.checked = cb.checked; });
        },
        'bulk-delete-rules': function () {
            var ips = selectedRuleIps();
            if (!ips.length) { toast('0 ' + CFG.i18n.selected, 'error'); return; }
            confirmDialog(ips.length + ' ' + CFG.i18n.selected + ' — ' + CFG.i18n.confirmClear, { danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('/ip-rules/bulk-delete', { method: 'POST', body: { ips: ips } })
                    .then(function (r) { toast(CFG.i18n.deleted + ' (' + r.deleted + ')', 'success'); loadIpRules(); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        },
        'export-ip-rules': function () { download('/ip-rules/export', 'cf7sec-ip-rules.csv'); },
        'lookup-ip-row': function (btn) { doLookup(btn.dataset.ip, null); },
        'lookup-ip': function (btn) {
            var ip = ($('#lookup-ip') || {}).value || '';
            if (!ip) { toast(t('ipRequired', 'IP is required'), 'error'); return; }
            doLookup(ip, $('#lookup-result'));
        },
        'test-llm': function (btn) {
            spinner(btn, true);
            ensureSaved().then(function () { return api('/test/llm', { method: 'POST' }); })
                .then(function (r) {
                    toast(r.message || (r.ok ? CFG.i18n.testOk : CFG.i18n.testFail), r.ok ? 'success' : 'error');
                })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { spinner(btn, false); });
        },
        'test-deepseek': function (btn) {
            spinner(btn, true);
            ensureSaved().then(function () { return api('/test/llm', { method: 'POST' }); })
                .then(function (r) {
                    toast(r.message || (r.ok ? CFG.i18n.testOk : CFG.i18n.testFail), r.ok ? 'success' : 'error');
                })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { spinner(btn, false); });
        },
        'test-abuseipdb': function (btn) {
            spinner(btn, true);
            ensureSaved().then(function () { return api('/test/abuseipdb', { method: 'POST' }); })
                .then(function (r) { toast(r.message || (r.ok ? CFG.i18n.testOk : CFG.i18n.testFail), r.ok ? 'success' : 'error'); })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { spinner(btn, false); });
        },
        'test-email': function (btn) {
            spinner(btn, true);
            var form = currentForm();
            var email = form ? ((form.querySelector('[name="notifications.email"]') || {}).value || '') : '';
            ensureSaved().then(function () { return api('/test/email', { method: 'POST', body: { email: email } }); })
                .then(function (r) { toast(r.message, r.ok ? 'success' : 'error'); })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { spinner(btn, false); });
        },
        'refresh-log': function () {
            var sel = $('#cf7sec-log-date');
            if (sel && sel.value) { loadLogFile(sel.value); } else { loadLogDates(); }
        },
        'delete-log': function () {
            var sel = $('#cf7sec-log-date');
            if (!sel || !sel.value) { return; }
            confirmDialog(CFG.i18n.confirmClear, { danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('/logs?date=' + encodeURIComponent(sel.value), { method: 'DELETE' })
                    .then(function () { toast(CFG.i18n.deleted, 'success'); loadLogDates(); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        },
        'export-settings': function () { download('/export', 'cf7sec-settings.json'); },
        'run-cleanup': function (btn) {
            spinner(btn, true);
            api('/cleanup', { method: 'POST' })
                .then(function (r) { toast(r.message, 'success'); })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { spinner(btn, false); });
        },
        'clear-geo': function (btn) {
            spinner(btn, true);
            api('/geo/clear', { method: 'POST' })
                .then(function () { toast(CFG.i18n.deleted, 'success'); setTimeout(function () { location.reload(); }, 600); })
                .catch(function (e) { toast(e.message, 'error'); })
                .finally(function () { spinner(btn, false); });
        },
        'reset-counters': function () {
            confirmDialog(CFG.i18n.confirmClear, { danger: true }).then(function (ok) {
                if (!ok) { return; }
                api('/counters/reset', { method: 'POST' })
                    .then(function () { toast(CFG.i18n.deleted, 'success'); loadStats(); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        },
        'reset-settings': function () {
            confirmDialog(t('resetConfirm', 'Reset every option to factory defaults? API keys will be erased.'), { danger: true, okLabel: t('resetEverything', 'Reset everything') }).then(function (ok) {
                if (!ok) { return; }
                api('/settings/reset', { method: 'POST' })
                    .then(function () { toast(CFG.i18n.saved, 'success'); setTimeout(function () { location.reload(); }, 700); })
                    .catch(function (e) { toast(e.message, 'error'); });
            });
        }
    };

    function doLookup(ip, host) {
        if (!ip) { return; }
        api('/ip-lookup', { method: 'POST', body: { ip: ip, refresh: false } }).then(function (r) {
            if (!r.ok) {
                if (host) { host.innerHTML = '<p class="c-muted">' + esc(r.message) + '</p>'; }
                else { toast(r.message, 'error'); }
                return;
            }
            var d = r.data;
            var html = '<div class="c-detail-title" style="margin-bottom:6px">AbuseIPDB · ' + esc(ip) + (d.cached ? ' (cached)' : ' (live)') + '</div>'
                + '<div class="c-lookup-grid">'
                + lookupRow('Abuse confidence', d.abuse_score + '%')
                + lookupRow('Total reports', d.total_reports)
                + lookupRow('Email Spam reports (recent)', d.email_spam_reports !== undefined ? d.email_spam_reports : '—')
                + lookupRow('Country', (d.country_code || '—') + ' ' + (d.country_name || ''))
                + lookupRow('ISP', d.isp || '—')
                + lookupRow('Usage type', d.usage_type || '—')
                + lookupRow('Domain', d.domain || '—')
                + lookupRow('Tor exit', d.is_tor ? 'yes' : 'no')
                + lookupRow('Whitelisted', d.is_whitelisted ? 'yes' : 'no')
                + lookupRow('Last reported', d.last_reported_at ? fmtDate(d.last_reported_at) : '—')
                + '</div>';
            if (host) { host.innerHTML = html; }
            else { openModal({ title: 'IP lookup — ' + ip, body: html }); }
        }).catch(function (e) {
            if (host) { host.innerHTML = '<p class="c-muted">' + esc(e.message) + '</p>'; }
            else { toast(e.message, 'error'); }
        });
    }

    function initActions() {
        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-action]') : null;
            if (!el) { return; }
            var fn = ACTIONS[el.dataset.action];
            if (fn) {
                e.preventDefault();
                fn(el, e);
            }
        });
        document.addEventListener('change', function (e) {
            var el = e.target;
            if (el.matches && el.matches('[data-action="toggle-all-rules"]')) {
                ACTIONS['toggle-all-rules'](el);
            }
        });
    }

    /* ============================ page bootstraps ============================ */

    function initPage() {
        var app = $('#cf7sec-app');
        if (!app) { return; }
        var slug = app.dataset.slug;

        if (slug === 'cf7sec') {
            loadStats(); loadHealth(); loadRecentEvents();
        }
        if (slug === 'cf7sec-settings') {
            loadLogDates();
            var sel = $('#cf7sec-log-date');
            if (sel) {
                sel.addEventListener('change', function () { loadLogFile(sel.value); });
            }
        }
        if (slug === 'cf7sec-ip') {
            loadIpRules();
            var search = $('#cf7sec-rule-search');
            if (search) {
                var t = null;
                search.addEventListener('input', function () {
                    clearTimeout(t);
                    t = setTimeout(function () { ipState.search = search.value; ipState.page = 1; loadIpRules(); }, 350);
                });
            }
        }
        if (slug === 'cf7sec-registry') {
            loadEvents();
            var search2 = $('[data-filter="search"]');
            if (search2) {
                var t2 = null;
                search2.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        clearTimeout(t2);
                        ACTIONS['apply-filters']();
                    }
                });
            }
        }
        if (slug === 'cf7sec-tools') {
            loadHealth();
            var file = $('#cf7sec-import-file');
            if (file) {
                file.addEventListener('change', function () {
                    var f = file.files && file.files[0];
                    if (!f) { return; }
                    var reader = new FileReader();
                    reader.onload = function () {
                        api('/import', { method: 'POST', body: { file_content: String(reader.result) } })
                            .then(function () { toast(CFG.i18n.importOk, 'success'); setTimeout(function () { location.reload(); }, 700); })
                            .catch(function () { toast(CFG.i18n.importFail, 'error'); });
                    };
                    reader.readAsText(f);
                    file.value = '';
                });
            }
        }
    }

    /* ============================ boot ============================ */

    function boot() {
        initTheme();
        initSwitches();
        initSettingsForms();
        initSecrets();
        initTabs();
        initShowIf();
        initRanges();
        initActions();
        initPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
