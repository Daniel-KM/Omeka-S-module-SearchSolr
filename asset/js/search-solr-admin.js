'use strict';

(function() {

    // --- Filters: resource type and live text with highlight ---

    var select = document.getElementById('filter-resource-type');
    var countSpan = document.getElementById('maps-count');
    var textInput = document.getElementById('maps-filter-text');

    // Highlight the query in a container, resetting any previous highlight. The
    // previous <mark> nodes are unwrapped, so the sibling elements and their
    // listeners are preserved.
    function highlight(container, query) {
        if (!container) return;
        container.querySelectorAll('mark.map-filter-hit')
            .forEach(function(mark) {
                mark.replaceWith(document.createTextNode(mark.textContent));
            });
        container.normalize();
        if (!query) return;
        var walker = document.createTreeWalker(
            container, NodeFilter.SHOW_TEXT, null
        );
        var nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function(node) {
            var text = node.nodeValue;
            var lower = text.toLowerCase();
            var index = lower.indexOf(query);
            if (index === -1) return;
            var fragment = document.createDocumentFragment();
            var last = 0;
            while (index !== -1) {
                fragment.appendChild(
                    document.createTextNode(text.slice(last, index))
                );
                var mark = document.createElement('mark');
                mark.className = 'map-filter-hit';
                mark.textContent = text.slice(index, index + query.length);
                fragment.appendChild(mark);
                last = index + query.length;
                index = lower.indexOf(query, last);
            }
            fragment.appendChild(document.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(fragment, node);
        });
    }

    function filterSimpleList(type, query) {
        var rows = document.querySelectorAll(
            '.by-source tbody tr:not(.map-voc-group)'
        );
        rows.forEach(function(row) {
            var types = (row.dataset.resourceTypes || '').split(' ');
            var typeOk = !type || types.indexOf(type) !== -1;
            var textOk = !query
                || row.textContent.toLowerCase().indexOf(query) !== -1;
            var match = typeOk && textOk;
            row.style.display = match ? '' : 'none';
            highlight(row, match && query ? query : '');
            row.querySelectorAll('[data-resource-type]')
                .forEach(function(el) {
                    el.style.display = !type
                        || el.dataset.resourceType === type ? '' : 'none';
                });
        });
        // A vocabulary heading is visible when one of its rows is.
        document.querySelectorAll('.by-source tbody tr.map-voc-group')
            .forEach(function(group) {
                var hasVisible = false;
                var next = group.nextElementSibling;
                while (next && !next.classList.contains('map-voc-group')) {
                    if (next.style.display !== 'none') {
                        hasVisible = true;
                        break;
                    }
                    next = next.nextElementSibling;
                }
                group.style.display = hasVisible ? '' : 'none';
            });
    }

    function filterMaps() {
        var type = select ? select.value : '';
        var query = textInput ? textInput.value.trim().toLowerCase() : '';
        var rows = document.querySelectorAll(
            '.by-solr-index > table > tbody > tr'
        );
        var shown = 0;
        rows.forEach(function(row) {
            var indexCell = row.cells[0];
            // When the index name matches, the whole group stays visible.
            var indexMatch = !query
                || indexCell.textContent.toLowerCase().indexOf(query) !== -1;
            var subRows = row.querySelectorAll(
                '.solr-maps-table-body tbody tr'
            );
            var hasVisible = false;
            subRows.forEach(function(sub) {
                var rType = sub.querySelector('.field-generic');
                var typeOk = !type
                    || (rType && rType.textContent.trim() === type);
                var textOk = !query || indexMatch
                    || sub.textContent.toLowerCase().indexOf(query) !== -1;
                var match = typeOk && textOk;
                sub.style.display = match ? '' : 'none';
                highlight(sub, match && query ? query : '');
                if (match) hasVisible = true;
            });
            row.style.display = hasVisible ? '' : 'none';
            highlight(indexCell, hasVisible && query ? query : '');
            if (hasVisible) shown++;
        });
        filterSimpleList(type, query);
        if (countSpan) {
            countSpan.textContent = type || query
                ? shown + ' / ' + rows.length
                : rows.length;
        }
        var url = new URL(window.location);
        if (type) {
            url.searchParams.set('resource_type', type);
        } else {
            url.searchParams.delete('resource_type');
        }
        history.replaceState(null, '', url);
    }

    if (select) {
        select.addEventListener('change', filterMaps);
        filterMaps();
    }

    if (textInput) {
        var filterTimer = null;
        textInput.addEventListener('input', function() {
            if (filterTimer) window.clearTimeout(filterTimer);
            filterTimer = window.setTimeout(filterMaps, 120);
        });
        textInput.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                textInput.value = '';
                filterMaps();
            } else if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        // Global shortcut "/" to focus the filter, unless already typing.
        document.addEventListener('keydown', function(event) {
            if (event.key !== '/'
                || event.ctrlKey || event.metaKey || event.altKey
            ) {
                return;
            }
            var active = document.activeElement;
            if (active
                && active.matches('input, textarea, select, [contenteditable]')
            ) {
                return;
            }
            event.preventDefault();
            textInput.focus();
        });
    }

    // --- Tooltip of the indexes of the simple list ---

    // The tooltip must be dismissable with escape (wai-aria pattern).
    document.addEventListener('keydown', function(event) {
        var active = document.activeElement;
        if (event.key === 'Escape'
            && active && active.classList.contains('map-alias')
        ) {
            active.blur();
        }
    });

    // --- Simple/full list view radios ---

    document.querySelectorAll('input[name="maps-view"]')
        .forEach(function(radio) {
            radio.addEventListener('change', function() {
                var toSimple = radio.value === 'simple';
                document.querySelector('.by-solr-index').style.display = toSimple ? 'none' : '';
                document.querySelector('.by-source').style.display = toSimple ? '' : 'none';
                // The simple list is the default view.
                var url = new URL(window.location);
                if (toSimple) {
                    url.searchParams.delete('view');
                } else {
                    url.searchParams.set('view', 'full');
                }
                history.replaceState(null, '', url);
            });
        });

    // --- Sortable columns ---

    var table = document.querySelector('.by-solr-index > table');
    if (!table) return;

    var headers = table.querySelectorAll('thead > tr > th');
    // Main table header: "Index" (column 0).
    var indexTh = headers[0];
    // Sub-table headers inside column 1.
    var subHeaders = table.querySelectorAll(
        '.solr-maps-table-head th'
    );

    // Sort state: { column, asc }.
    var sortState = { column: null, asc: true };

    function getText(el) {
        // Get first text node or first span text, ignoring actions.
        var span = el.querySelector(':scope > span');
        return (span || el).textContent.trim().toLowerCase();
    }

    function sortByIndex(asc) {
        var tbody = table.querySelector(':scope > tbody');
        var rows = Array.from(tbody.querySelectorAll(':scope > tr'));
        rows.sort(function(a, b) {
            var ta = getText(a.cells[0]);
            var tb = getText(b.cells[0]);
            return asc ? ta.localeCompare(tb) : tb.localeCompare(ta);
        });
        rows.forEach(function(row) { tbody.appendChild(row); });
    }

    function sortBySubColumn(colIndex, asc) {
        var tbody = table.querySelector(':scope > tbody');
        var rows = Array.from(tbody.querySelectorAll(':scope > tr'));
        rows.sort(function(a, b) {
            var aCell = a.querySelector(
                '.solr-maps-table-body tbody tr td:nth-child('
                + (colIndex + 1) + ')'
            );
            var bCell = b.querySelector(
                '.solr-maps-table-body tbody tr td:nth-child('
                + (colIndex + 1) + ')'
            );
            var ta = aCell ? getText(aCell) : '';
            var tb = bCell ? getText(bCell) : '';
            return asc ? ta.localeCompare(tb) : tb.localeCompare(ta);
        });
        rows.forEach(function(row) { tbody.appendChild(row); });
    }

    function updateSortIndicators(activeTh, asc) {
        // Remove indicators from all sortable headers.
        table.querySelectorAll('th[data-sortable]')
            .forEach(function(th) {
                th.classList.remove('sort-asc', 'sort-desc');
                th.style.cursor = 'pointer';
            });
        if (activeTh) {
            activeTh.classList.add(asc ? 'sort-asc' : 'sort-desc');
        }
    }

    // Make "Index" header sortable.
    if (indexTh) {
        indexTh.setAttribute('data-sortable', 'index');
        indexTh.style.cursor = 'pointer';
        indexTh.addEventListener('click', function() {
            var asc = sortState.column === 'index'
                ? !sortState.asc : true;
            sortState = { column: 'index', asc: asc };
            sortByIndex(asc);
            updateSortIndicators(indexTh, asc);
        });
    }

    // Make sub-table headers sortable.
    subHeaders.forEach(function(th, i) {
        var key = 'sub' + i;
        th.setAttribute('data-sortable', key);
        th.style.cursor = 'pointer';
        th.addEventListener('click', function() {
            var asc = sortState.column === key
                ? !sortState.asc : true;
            sortState = { column: key, asc: asc };
            sortBySubColumn(i, asc);
            updateSortIndicators(th, asc);
        });
    });

})();
