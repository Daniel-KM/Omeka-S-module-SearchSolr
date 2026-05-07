'use strict';

(function() {

    // --- Resource type filter ---

    var select = document.getElementById('filter-resource-type');
    var countSpan = document.getElementById('maps-count');

    function filterMaps() {
        if (!select) return;
        var type = select.value;
        var rows = document.querySelectorAll(
            '.by-solr-index > table > tbody > tr'
        );
        var shown = 0;
        rows.forEach(function(row) {
            var subRows = row.querySelectorAll(
                '.solr-maps-table-body tbody tr'
            );
            var hasVisible = false;
            subRows.forEach(function(sub) {
                var rType = sub.querySelector('.field-generic');
                var match = !type
                    || (rType && rType.textContent.trim() === type);
                sub.style.display = match ? '' : 'none';
                if (match) hasVisible = true;
            });
            row.style.display = hasVisible ? '' : 'none';
            if (hasVisible) shown++;
        });
        if (countSpan) {
            countSpan.textContent = type
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
