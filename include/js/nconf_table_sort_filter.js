/*
 * nconf_table_sort_filter.js
 *
 * Makes every table whose <thead> has the "ui-widget-header" class sortable
 * (click a column header) and filterable (one text box per column).
 * Self-contained: no jQuery dependency (the bundled jQuery is v1.5.1), injects
 * its own CSS, never inserts HTML built from cell contents.
 *
 * - Works only on rows already in the page.
 * - Header cells that already link somewhere keep their link, so the server
 *   still orders those columns.
 * - Header cells spanning several columns (Actions), holding form controls
 *   (select-all checkbox) or marked data-no-sort-filter are left alone.
 * - Columns whose cells show only images (no visible text) get no filter box.
 * - A header with data-filter="select" gets a dropdown of the values in the
 *   rows instead of a text box; its options follow the other active filters.
 * - Rows joined by rowspan (inheritance preview) move and filter as one group.
 * - A whole table opts out with data-no-sort-filter on <table>.
 *
 * Server-side lists (overview.php) filter, sort and page in SQL instead:
 *   <form id="f" data-server-side>  GET form owning the filter controls,
 *                                   which join it via form="f"
 *   data-list-part="f"              element swapped in place from the
 *                                   server's response; never enhanced here
 *   <a data-list-link>              sort/pager/size link inside a part,
 *                                   loaded in place instead of navigating
 * Typing or picking a filter reloads the parts, so the page does not flash;
 * any failure falls back to a normal page load of the same URL.
 */
(function () {
    'use strict';

    var ENHANCED_ATTR = 'data-ntsf';
    var OPT_OUT_ATTR  = 'data-no-sort-filter';
    var FILTER_DELAY  = 150; /* ms; keeps typing smooth on "all" with many rows */
    var SERVER_FILTER_DELAY = 500; /* ms; one request per pause in typing, not per key */

    /* Each pair alternates per visible group so striping survives sort/filter. */
    var STRIPE_PAIRS = [['color_list1', 'color_list2'], ['odd', 'even']];

    var collator = (window.Intl && Intl.Collator)
        ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
        : null;

    function compareText(a, b) {
        if (collator) { return collator.compare(a, b); }
        return a.localeCompare(b);
    }

    function injectStyles() {
        if (document.getElementById('ntsf-styles')) { return; }
        var css = ''
            + '.ntsf-sortable{cursor:pointer;user-select:none;white-space:nowrap;}'
            + '.ntsf-sortable:focus{outline:1px dotted currentColor;}'
            + '.ntsf-ind{display:inline-block;width:1em;margin-left:3px;font-size:9px;opacity:0.4;}'
            + '.ntsf-sorted .ntsf-ind{opacity:1;}'
            + '.ntsf-filter-row td,.ntsf-filter-row th{padding:2px 3px;font-weight:normal;}'
            + '.ntsf-loading{opacity:0.6;}'
            + '.ntsf-filter{box-sizing:border-box;width:100%;min-width:40px;padding:1px 4px;'
            +   'border:1px solid #7f9db9;border-radius:2px;font:inherit;font-size:11px;}';
        var style = document.createElement('style');
        style.id = 'ntsf-styles';
        style.type = 'text/css';
        style.appendChild(document.createTextNode(css));
        (document.head || document.getElementsByTagName('head')[0]).appendChild(style);
    }

    /* Icon-only cells (OS logo, status icons) are compared by their alt/title. */
    function cellText(cell) {
        if (!cell) { return ''; }
        var text = cell.textContent || '';
        var imgs = cell.getElementsByTagName('img');
        for (var i = 0; i < imgs.length; i++) {
            text += ' ' + (imgs[i].getAttribute('alt') || imgs[i].getAttribute('title') || '');
        }
        return text.replace(/\s+/g, ' ').replace(/^ | $/g, '');
    }

    function span(cell, attr) {
        var n = parseInt(cell.getAttribute(attr), 10);
        return (n > 1) ? n : 1;
    }

    /* Maps header cells to their first column index, honouring colspan. */
    function headerColumns(headerRow) {
        var cols = [];
        var index = 0;
        for (var i = 0; i < headerRow.cells.length; i++) {
            var cell = headerRow.cells[i];
            cols.push({ cell: cell, index: index, span: span(cell, 'colspan') });
            index += span(cell, 'colspan');
        }
        return cols;
    }

    function isUsableColumn(col) {
        var cell = col.cell;
        if (col.span > 1 || cell.hasAttribute(OPT_OUT_ATTR)) { return false; }
        if (cell.getAttribute('name') === 'checkbox_toggle_all') { return false; }
        return cell.querySelector('input, select, textarea, button') === null;
    }

    /*
     * Builds a row x column grid of cells (resolving rowspan/colspan) and splits
     * the body into groups: rows held together by a rowspan never get separated.
     */
    function buildGroups(tbody) {
        var rows = tbody.rows;
        var grid = [];
        var r, c, i, k;

        for (r = 0; r < rows.length; r++) { grid[r] = grid[r] || []; }

        for (r = 0; r < rows.length; r++) {
            c = 0;
            for (i = 0; i < rows[r].cells.length; i++) {
                var cell = rows[r].cells[i];
                while (grid[r][c]) { c++; }
                var rs = span(cell, 'rowspan');
                var cs = span(cell, 'colspan');
                for (k = 0; k < rs && r + k < rows.length; k++) {
                    for (var j = 0; j < cs; j++) { grid[r + k][c + j] = cell; }
                }
                c += cs;
            }
        }

        var groups = [];
        r = 0;
        while (r < rows.length) {
            var end = r;
            for (k = r; k <= end; k++) {
                for (i = 0; i < rows[k].cells.length; i++) {
                    end = Math.max(end, Math.min(rows.length - 1, k + span(rows[k].cells[i], 'rowspan') - 1));
                }
            }

            var group = { rows: [], raw: [], texts: [], hasText: [], fixed: false };
            for (k = r; k <= end; k++) {
                group.rows.push(rows[k]);
                var raw = [];
                var texts = [];
                for (c = 0; c < grid[k].length; c++) {
                    raw.push(cellText(grid[k][c]));
                    texts.push(raw[c].toLowerCase());
                    /* Visible text only; alt/title do not count, see isImageOnlyColumn. */
                    if (grid[k][c] && /\S/.test(grid[k][c].textContent || '')) { group.hasText[c] = true; }
                }
                group.raw.push(raw);
                group.texts.push(texts);
            }
            group.sortTexts = [];
            for (c = 0; c < grid[r].length; c++) { group.sortTexts.push(cellText(grid[r][c])); }

            /* A lone full-width cell is a message row ("nothing found"), not data. */
            group.fixed = rows[r].cells.length === 1 && span(rows[r].cells[0], 'colspan') > 1;

            groups.push(group);
            r = end + 1;
        }
        return groups;
    }

    /* Columns showing only icons (OS logo, edit/delete) have nothing a user could type to filter. */
    function isImageOnlyColumn(groups, index) {
        for (var g = 0; g < groups.length; g++) {
            if (!groups[g].fixed && groups[g].hasText[index]) { return false; }
        }
        return true;
    }

    function restripe(groups) {
        var visibleIndex = 0;
        for (var g = 0; g < groups.length; g++) {
            var group = groups[g];
            if (group.fixed || group.hidden) { continue; }
            for (var r = 0; r < group.rows.length; r++) {
                var row = group.rows[r];
                for (var p = 0; p < STRIPE_PAIRS.length; p++) {
                    var pair = STRIPE_PAIRS[p];
                    if (!row.classList.contains(pair[0]) && !row.classList.contains(pair[1])) { continue; }
                    row.classList.remove(pair[0], pair[1]);
                    row.classList.add(pair[visibleIndex % 2]);
                }
            }
            visibleIndex++;
        }
    }

    function enhance(table) {
        if (table.hasAttribute(ENHANCED_ATTR) || table.hasAttribute(OPT_OUT_ATTR)) { return; }
        /* Server-side lists hold one page only; filtering it here would hide matches on other pages. */
        if (table.hasAttribute('data-list-part')) { return; }
        var thead = table.tHead;
        var tbody = table.tBodies[0];
        if (!thead || !tbody || !thead.rows.length) { return; }
        table.setAttribute(ENHANCED_ATTR, '1');

        var headerRow = thead.rows[thead.rows.length - 1];
        var cols = headerColumns(headerRow);
        var groups = buildGroups(tbody);
        var filters = {};
        var sortState = { index: -1, dir: 1, cell: null };

        function render() {
            var ordered = groups.slice();
            if (sortState.index >= 0) {
                var idx = sortState.index;
                var dir = sortState.dir;
                ordered.sort(function (a, b) {
                    if (a.fixed !== b.fixed) { return a.fixed ? -1 : 1; }
                    var res = compareText(a.sortTexts[idx] || '', b.sortTexts[idx] || '') * dir;
                    /* Stable fallback: keep the server's original order for equal values. */
                    return res !== 0 ? res : groups.indexOf(a) - groups.indexOf(b);
                });
            }

            var terms = activeTerms(-1);

            var fragment = document.createDocumentFragment();
            for (var g = 0; g < ordered.length; g++) {
                var group = ordered[g];
                group.hidden = !group.fixed && terms.length > 0 && !groupMatches(group, terms);
                for (var r = 0; r < group.rows.length; r++) {
                    group.rows[r].style.display = group.hidden ? 'none' : '';
                    fragment.appendChild(group.rows[r]);
                }
            }
            tbody.appendChild(fragment);
            restripe(ordered);
            refreshSelects();
        }

        /* skipIndex leaves one column's own filter out, for building its options. */
        function activeTerms(skipIndex) {
            var terms = [];
            for (var key in filters) {
                if (filters.hasOwnProperty(key) && +key !== skipIndex && filters[key].term !== '') {
                    terms.push({ index: +key, term: filters[key].term, exact: filters[key].exact });
                }
            }
            return terms;
        }

        function rowMatches(texts, terms) {
            for (var t = 0; t < terms.length; t++) {
                var text = texts[terms[t].index] || '';
                if (terms[t].exact ? text !== terms[t].term : text.indexOf(terms[t].term) === -1) { return false; }
            }
            return true;
        }

        function groupMatches(group, terms) {
            for (var r = 0; r < group.texts.length; r++) {
                if (rowMatches(group.texts[r], terms)) { return true; }
            }
            return false;
        }

        /*
         * Options are rebuilt from the rows on every render, so they only offer
         * values reachable under the other filters, with how many rows hold each.
         */
        function refreshSelects() {
            selects.forEach(function (entry) {
                var terms = activeTerms(entry.index);
                /* Keyed case-insensitively, matching how the filter compares. */
                var counts = {};
                var labels = {};
                var keys = [];
                groups.forEach(function (group) {
                    if (group.fixed) { return; }
                    for (var r = 0; r < group.raw.length; r++) {
                        var key = group.texts[r][entry.index] || '';
                        if (key === '' || !rowMatches(group.texts[r], terms)) { continue; }
                        if (!counts.hasOwnProperty(key)) {
                            counts[key] = 0;
                            labels[key] = group.raw[r][entry.index];
                            keys.push(key);
                        }
                        counts[key]++;
                    }
                });

                var select = entry.select;
                var current = select.value;
                /* Keep a chosen value listed even when other filters leave no rows for it. */
                if (current !== '' && !counts.hasOwnProperty(current)) {
                    counts[current] = 0;
                    labels[current] = entry.labels[current] || current;
                    keys.push(current);
                }
                keys.sort(function (a, b) { return compareText(labels[a], labels[b]); });

                select.options.length = 1;
                keys.forEach(function (key) {
                    select.options.add(new Option(labels[key] + ' (' + counts[key] + ')', key));
                });
                select.value = current;
                entry.labels = labels;
            });
        }

        function sortBy(col) {
            if (sortState.cell && sortState.cell !== col.cell) {
                sortState.cell.classList.remove('ntsf-sorted');
                sortState.cell.removeAttribute('aria-sort');
                sortState.cell.querySelector('.ntsf-ind').textContent = '▲';
            }
            sortState.dir = (sortState.index === col.index) ? -sortState.dir : 1;
            sortState.index = col.index;
            sortState.cell = col.cell;
            col.cell.classList.add('ntsf-sorted');
            col.cell.setAttribute('aria-sort', sortState.dir === 1 ? 'ascending' : 'descending');
            col.cell.querySelector('.ntsf-ind').textContent = sortState.dir === 1 ? '▲' : '▼';
            render();
        }

        var filterRow = document.createElement('tr');
        filterRow.className = 'ntsf-filter-row';
        var timer = null;
        var selects = [];

        cols.forEach(function (col) {
            var filterCell = document.createElement(col.cell.tagName === 'TH' ? 'th' : 'td');
            if (col.span > 1) { filterCell.colSpan = col.span; }
            filterRow.appendChild(filterCell);
            if (!isUsableColumn(col)) { return; }

            var label = cellText(col.cell) || ('column ' + (col.index + 1));
            if (!isImageOnlyColumn(groups, col.index)) { addFilter(col, filterCell, label); }
            addSort(col, label);
        });

        function addFilter(col, filterCell, label) {
            if (col.cell.getAttribute('data-filter') === 'select') {
                var select = document.createElement('select');
                select.className = 'ntsf-filter';
                select.setAttribute('aria-label', 'Filter ' + label);
                select.options.add(new Option('all', ''));
                /* "change" also fires from the searchable-select widget that wraps it. */
                select.addEventListener('change', function () {
                    filters[col.index] = { term: select.value.toLowerCase(), exact: true };
                    render();
                });
                filterCell.appendChild(select);
                selects.push({ index: col.index, select: select, labels: {} });
            } else {
                var input = document.createElement('input');
                input.type = 'search';
                input.className = 'ntsf-filter';
                input.placeholder = 'filter';
                input.setAttribute('aria-label', 'Filter ' + label);
                input.addEventListener('input', function () {
                    filters[col.index] = {
                        term: input.value.replace(/^\s+|\s+$/g, '').toLowerCase(),
                        exact: false
                    };
                    clearTimeout(timer);
                    timer = setTimeout(render, FILTER_DELAY);
                });
                /* Tables sit inside search forms on some pages; Enter must not submit them. */
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); }
                });
                filterCell.appendChild(input);
            }
        }

        function addSort(col, label) {
            /* Linked headers keep their server-side ordering across all pages. */
            if (col.cell.querySelector('a[href]')) { return; }

            var ind = document.createElement('span');
            ind.className = 'ntsf-ind';
            ind.setAttribute('aria-hidden', 'true');
            ind.textContent = '▲';
            col.cell.appendChild(ind);
            col.cell.classList.add('ntsf-sortable');
            col.cell.tabIndex = 0;
            col.cell.title = 'Sort by ' + label;
            col.cell.addEventListener('click', function () { sortBy(col); });
            col.cell.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    sortBy(col);
                }
            });
        }

        headerRow.parentNode.insertBefore(filterRow, headerRow.nextSibling);
        refreshSelects();
    }

    function enhanceAll(root) {
        var heads = root.querySelectorAll('thead.ui-widget-header');
        for (var i = 0; i < heads.length; i++) {
            if (heads[i].parentNode && heads[i].parentNode.tagName === 'TABLE') { enhance(heads[i].parentNode); }
        }
    }

    /* The control's own form, when that form drives a server-side list. */
    function serverForm(control) {
        var form = control && control.form;
        return (form && form.hasAttribute('data-server-side')) ? form : null;
    }

    function serverUrl(form) {
        /* FormData includes controls that join the form through form="". */
        var query = new URLSearchParams(new FormData(form)).toString();
        return form.action.split('?')[0] + '?' + query;
    }

    function listParts(form) {
        return document.querySelectorAll('[data-list-part="' + form.id + '"]');
    }

    /* The user may type on while a request runs: keep their text, not the server's older copy. */
    function captureFocus(form) {
        var el = document.activeElement;
        if (!el || el.form !== form || el.tagName !== 'INPUT' || !el.name) { return null; }
        return { name: el.name, value: el.value, start: el.selectionStart, end: el.selectionEnd };
    }

    function restoreFocus(form, saved) {
        if (!saved) { return; }
        var el = form.elements.namedItem(saved.name);
        if (!el || el.tagName !== 'INPUT') { return; }
        el.value = saved.value;
        el.focus();
        try { el.setSelectionRange(saved.start, saved.end); } catch (ignore) { /* type without a caret */ }
    }

    /* Swaps every part, or none: a response missing one is not the list (e.g. the login page). */
    function swapParts(form, doc) {
        var parts = listParts(form);
        var fresh = [];
        for (var i = 0; i < parts.length; i++) {
            var next = parts[i].id ? doc.getElementById(parts[i].id) : null;
            if (!next) { return false; }
            fresh.push(next);
        }
        if (!parts.length) { return false; }

        var saved = captureFocus(form);
        for (var j = 0; j < parts.length; j++) {
            parts[j].parentNode.replaceChild(document.adoptNode(fresh[j]), parts[j]);
        }
        restoreFocus(form, saved);
        return true;
    }

    function initServerSide() {
        var timer = null;
        var latest = 0;

        function load(form, url) {
            var id = ++latest;
            clearTimeout(timer);
            Array.prototype.forEach.call(listParts(form), function (part) {
                part.classList.add('ntsf-loading');
                part.setAttribute('aria-busy', 'true');
            });
            fetch(url, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    return res.text();
                })
                .then(function (html) {
                    /* An answer to an older request would undo what the user did since. */
                    if (id !== latest) { return; }
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    if (!swapParts(form, doc)) { throw new Error('list missing from response'); }
                    /* Reload and the edit pages' "back" then return to this exact list. */
                    history.replaceState(null, '', url);
                })
                .catch(function () {
                    /* A full load shows whatever the server answered: error, login, the list. */
                    if (id === latest) { window.location.href = url; }
                });
        }

        /* Text filters: wait for a pause in typing. */
        document.addEventListener('input', function (e) {
            var form = serverForm(e.target);
            if (!form || e.target.tagName !== 'INPUT') { return; }
            clearTimeout(timer);
            timer = setTimeout(function () { load(form, serverUrl(form)); }, SERVER_FILTER_DELAY);
        });

        /* Dropdown filters, including picks made in the searchable-select widget. */
        document.addEventListener('change', function (e) {
            var form = serverForm(e.target);
            if (form && e.target.tagName === 'SELECT') { load(form, serverUrl(form)); }
        });

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.hasAttribute || !form.hasAttribute('data-server-side')) { return; }
            e.preventDefault();
            load(form, serverUrl(form));
        });

        document.addEventListener('click', function (e) {
            /* Modified clicks keep their browser meaning (new tab, new window). */
            if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
            var link = e.target.closest && e.target.closest('a[data-list-link][href]');
            var part = link && link.closest('[data-list-part]');
            var form = part && document.getElementById(part.getAttribute('data-list-part'));
            if (!form || !form.hasAttribute('data-server-side')) { return; }
            e.preventDefault();
            load(form, link.href);
        });
    }

    function init() {
        injectStyles();
        enhanceAll(document);
        /* Older browsers keep working through plain links and the form's GET submit. */
        if (window.fetch && window.DOMParser && window.URLSearchParams && window.history.replaceState) {
            initServerSide();
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
