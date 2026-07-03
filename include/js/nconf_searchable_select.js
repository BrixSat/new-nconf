/*
 * nconf_searchable_select.js
 *
 * Turns every single <select> on the page into a type-to-filter dropdown.
 * Self-contained: no jQuery dependency (the bundled jQuery is v1.5.1, too old
 * for Select2/Chosen), no external assets, injects its own CSS.
 *
 * - The original <select> is kept in the DOM (hidden) so form submission and
 *   existing scripts that read/write its value keep working unchanged.
 * - Selecting an option writes back to the native <select> and fires a native
 *   "change" event, so inline onchange="" handlers and jQuery .change() bindings
 *   (e.g. #check_command_select) still fire.
 * - Disabled selects (e.g. check_command / host on the service modify page) stay
 *   READ-ONLY: you can open them and type to filter/find, but the value cannot be
 *   changed and is not submitted (the native disabled <select> handles that).
 * - <select multiple> (the two-column assign-many widgets) is left untouched.
 */
(function () {
    'use strict';

    var ENHANCED_ATTR = 'data-nsel';
    var MIN_OPTIONS   = 0; /* 0 = enhance every single select */

    function injectStyles() {
        if (document.getElementById('nsel-styles')) { return; }
        var css = ''
            + '.nsel-wrap{position:relative;display:inline-block;vertical-align:middle;font:inherit;}'
            + '.nsel-control{box-sizing:border-box;min-width:120px;max-width:100%;padding:2px 22px 2px 6px;'
            +   'border:1px solid #7f9db9;background:#fff;color:#000;cursor:pointer;position:relative;'
            +   'min-height:20px;line-height:18px;font:inherit;white-space:nowrap;overflow:hidden;'
            +   'text-overflow:ellipsis;border-radius:2px;}'
            + '.nsel-control:focus{outline:1px solid #3b7bbf;}'
            + '.nsel-arrow{position:absolute;right:6px;top:6px;font-size:10px;color:#666;pointer-events:none;}'
            + '.nsel-disabled .nsel-control{background:#ececec;color:#666;cursor:default;}'
            + '.nsel-panel{position:absolute;z-index:99999;left:0;top:100%;margin-top:1px;background:#fff;'
            +   'border:1px solid #7f9db9;box-shadow:0 2px 6px rgba(0,0,0,0.25);min-width:120px;max-width:480px;}'
            + '.nsel-search{box-sizing:border-box;width:100%;padding:4px 6px;border:0;border-bottom:1px solid #ccc;'
            +   'font:inherit;outline:none;}'
            + '.nsel-list{list-style:none;margin:0;padding:0;max-height:240px;overflow-y:auto;}'
            + '.nsel-opt{padding:3px 8px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#000;}'
            + '.nsel-opt:hover,.nsel-opt.nsel-hl{background:#3b7bbf;color:#fff;}'
            + '.nsel-opt.nsel-selected{font-weight:bold;}'
            + '.nsel-disabled .nsel-opt{cursor:default;}'
            + '.nsel-disabled .nsel-opt:hover,.nsel-disabled .nsel-opt.nsel-hl{background:#fff;color:#000;}'
            + '.nsel-empty{padding:6px 8px;color:#888;font-style:italic;}';
        var style = document.createElement('style');
        style.id = 'nsel-styles';
        style.type = 'text/css';
        if (style.styleSheet) { style.styleSheet.cssText = css; } /* old IE */
        else { style.appendChild(document.createTextNode(css)); }
        (document.head || document.getElementsByTagName('head')[0]).appendChild(style);
    }

    function currentText(select) {
        var i = select.selectedIndex;
        return (i >= 0 && select.options[i]) ? select.options[i].text : '';
    }

    function scrollIntoViewSafe(el) {
        if (!el || !el.scrollIntoView) { return; }
        try { el.scrollIntoView({ block: 'nearest' }); }
        catch (e) { try { el.scrollIntoView(false); } catch (e2) {} }
    }

    function fireChange(select) {
        var ev;
        try { ev = new Event('change', { bubbles: true }); }
        catch (e) {
            ev = document.createEvent('HTMLEvents');
            ev.initEvent('change', true, false);
        }
        select.dispatchEvent(ev);
    }

    function enhance(select) {
        if (!select || select.tagName !== 'SELECT') { return; }
        if (select.multiple) { return; }                 /* leave assign-many widgets alone */
        if (select.getAttribute(ENHANCED_ATTR)) { return; }
        if (select.options.length < MIN_OPTIONS) { return; }
        select.setAttribute(ENHANCED_ATTR, '1');

        var wrap = document.createElement('div');
        wrap.className = 'nsel-wrap';

        var measuredWidth = select.offsetWidth;

        var control = document.createElement('div');
        control.className = 'nsel-control';
        control.setAttribute('tabindex', '0');
        var valSpan = document.createElement('span');
        valSpan.className = 'nsel-value';
        var arrow = document.createElement('span');
        arrow.className = 'nsel-arrow';
        arrow.innerHTML = '&#9662;';
        control.appendChild(valSpan);
        control.appendChild(arrow);

        var panel = document.createElement('div');
        panel.className = 'nsel-panel';
        panel.style.display = 'none';
        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'nsel-search';
        search.setAttribute('placeholder', 'Type to filter…');
        search.setAttribute('autocomplete', 'off');
        var list = document.createElement('ul');
        list.className = 'nsel-list';
        var empty = document.createElement('div');
        empty.className = 'nsel-empty';
        empty.style.display = 'none';
        empty.appendChild(document.createTextNode('No matches'));
        panel.appendChild(search);
        panel.appendChild(list);
        panel.appendChild(empty);

        if (measuredWidth > 0) {
            control.style.minWidth = measuredWidth + 'px';
            panel.style.minWidth = measuredWidth + 'px';
        }

        /* place wrapper before select, then move control/panel/select inside */
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(control);
        wrap.appendChild(panel);
        wrap.appendChild(select);
        select.style.display = 'none';

        var items = [];
        var open = false;

        function isDisabled() { return !!select.disabled; }

        function syncDisabledClass() {
            if (isDisabled()) { addClass(wrap, 'nsel-disabled'); }
            else { removeClass(wrap, 'nsel-disabled'); }
        }

        function addClass(el, c) { if ((' ' + el.className + ' ').indexOf(' ' + c + ' ') < 0) { el.className += (el.className ? ' ' : '') + c; } }
        function removeClass(el, c) { el.className = (' ' + el.className + ' ').replace(' ' + c + ' ', ' ').replace(/^\s+|\s+$/g, ''); }
        function hasClass(el, c) { return (' ' + el.className + ' ').indexOf(' ' + c + ' ') >= 0; }

        function setValueText() { valSpan.innerHTML = ''; valSpan.appendChild(document.createTextNode(currentText(select) || ' ')); }

        function buildList() {
            list.innerHTML = '';
            items = [];
            for (var i = 0; i < select.options.length; i++) {
                var opt = select.options[i];
                var li = document.createElement('li');
                li.className = 'nsel-opt' + (i === select.selectedIndex ? ' nsel-selected' : '');
                li.setAttribute('data-index', i);
                li.appendChild(document.createTextNode(opt.text || ' '));
                list.appendChild(li);
                items.push(li);
            }
        }

        function visibleItems() {
            var vis = [];
            for (var i = 0; i < items.length; i++) { if (items[i].style.display !== 'none') { vis.push(items[i]); } }
            return vis;
        }

        function setHighlight(li) {
            for (var i = 0; i < items.length; i++) { removeClass(items[i], 'nsel-hl'); }
            if (li) { addClass(li, 'nsel-hl'); scrollIntoViewSafe(li); }
        }

        function filter(q) {
            q = (q || '').toLowerCase();
            var any = false;
            for (var i = 0; i < items.length; i++) {
                var match = items[i].textContent !== undefined
                    ? items[i].textContent.toLowerCase().indexOf(q) >= 0
                    : (items[i].innerText || '').toLowerCase().indexOf(q) >= 0;
                items[i].style.display = match ? '' : 'none';
                if (match) { any = true; }
            }
            empty.style.display = any ? 'none' : 'block';
            var vis = visibleItems();
            setHighlight(vis.length ? vis[0] : null);
        }

        function openPanel() {
            if (open) { return; }
            open = true;
            syncDisabledClass();
            buildList();                 /* rebuild in case options changed via AJAX */
            panel.style.display = 'block';
            addClass(wrap, 'nsel-open');
            search.value = '';
            filter('');
            var sel = null;
            for (var i = 0; i < items.length; i++) { if (hasClass(items[i], 'nsel-selected')) { sel = items[i]; break; } }
            if (sel) { scrollIntoViewSafe(sel); }
            search.focus();
        }

        function closePanel() {
            if (!open) { return; }
            open = false;
            panel.style.display = 'none';
            removeClass(wrap, 'nsel-open');
            setHighlight(null);
        }

        function toggle() { open ? closePanel() : openPanel(); }

        function commit(index) {
            if (isDisabled()) { closePanel(); return; }   /* read-only: no change */
            if (index < 0 || index >= select.options.length) { return; }
            select.selectedIndex = index;
            setValueText();
            for (var i = 0; i < items.length; i++) { removeClass(items[i], 'nsel-selected'); }
            if (items[index]) { addClass(items[index], 'nsel-selected'); }
            fireChange(select);
            closePanel();
            control.focus();
        }

        control.onclick = function (e) { if (e && e.preventDefault) { e.preventDefault(); } toggle(); };
        control.onkeydown = function (e) {
            var k = e.keyCode;
            if (k === 13 || k === 40 || k === 32) { if (e.preventDefault) { e.preventDefault(); } openPanel(); }
        };

        search.oninput = function () { filter(search.value); };
        search.onkeyup = function () { filter(search.value); }; /* IE fallback */
        search.onkeydown = function (e) {
            var vis = visibleItems();
            var cur = null, idx = -1;
            for (var i = 0; i < vis.length; i++) { if (hasClass(vis[i], 'nsel-hl')) { cur = vis[i]; idx = i; break; } }
            var k = e.keyCode;
            if (k === 40) { if (e.preventDefault) { e.preventDefault(); } if (vis.length) { idx = idx < 0 ? 0 : Math.min(idx + 1, vis.length - 1); setHighlight(vis[idx]); } }
            else if (k === 38) { if (e.preventDefault) { e.preventDefault(); } if (vis.length) { idx = idx <= 0 ? 0 : idx - 1; setHighlight(vis[idx]); } }
            else if (k === 13) { if (e.preventDefault) { e.preventDefault(); } if (cur) { commit(parseInt(cur.getAttribute('data-index'), 10)); } }
            else if (k === 27) { if (e.preventDefault) { e.preventDefault(); } closePanel(); control.focus(); }
        };

        list.onclick = function (e) {
            var t = e.target || e.srcElement;
            while (t && t !== list && !hasClass(t, 'nsel-opt')) { t = t.parentNode; }
            if (t && hasClass(t, 'nsel-opt')) { commit(parseInt(t.getAttribute('data-index'), 10)); }
        };

        /* keep the control label in sync when other scripts change the native value */
        if (select.addEventListener) {
            select.addEventListener('change', function () { setValueText(); });
        }

        setValueText();
        syncDisabledClass();
    }

    /* close any open panel on an outside click (single global handler) */
    function onDocClick(e) {
        var t = e.target || e.srcElement;
        var wraps = document.getElementsByClassName ? document.getElementsByClassName('nsel-wrap') : [];
        for (var i = 0; i < wraps.length; i++) {
            if (hasClassG(wraps[i], 'nsel-open') && !containsNode(wraps[i], t)) {
                var panel = wraps[i].getElementsByClassName('nsel-panel')[0];
                if (panel) { panel.style.display = 'none'; }
                wraps[i].className = (' ' + wraps[i].className + ' ').replace(' nsel-open ', ' ').replace(/^\s+|\s+$/g, '');
            }
        }
    }
    function hasClassG(el, c) { return (' ' + el.className + ' ').indexOf(' ' + c + ' ') >= 0; }
    function containsNode(parent, node) {
        while (node) { if (node === parent) { return true; } node = node.parentNode; }
        return false;
    }

    function enhanceAll(root) {
        var selects = (root || document).getElementsByTagName('select');
        var arr = [];
        for (var i = 0; i < selects.length; i++) { arr.push(selects[i]); }   /* snapshot: DOM mutates as we go */
        for (var j = 0; j < arr.length; j++) { enhance(arr[j]); }
    }

    function init() {
        injectStyles();
        enhanceAll(document);
        if (document.addEventListener) { document.addEventListener('click', onDocClick, true); }

        if (window.MutationObserver && document.body) {
            var mo = new MutationObserver(function (muts) {
                for (var i = 0; i < muts.length; i++) {
                    var added = muts[i].addedNodes;
                    for (var k = 0; k < added.length; k++) {
                        var n = added[k];
                        if (!n || n.nodeType !== 1) { continue; }
                        if (n.tagName === 'SELECT') { enhance(n); }
                        else if (n.getElementsByTagName) { enhanceAll(n); }
                    }
                }
            });
            mo.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
