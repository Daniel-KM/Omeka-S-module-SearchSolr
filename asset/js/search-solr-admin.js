'use strict';

(function() {
    var select = document.getElementById('filter-resource-type');
    var countSpan = document.getElementById('maps-count');
    if (!select) return;

    function filterMaps() {
        var type = select.value;
        var rows = document.querySelectorAll('.by-solr-index > table > tbody > tr');
        var shown = 0;
        rows.forEach(function(row) {
            var subRows = row.querySelectorAll('.solr-maps-table-body tbody tr');
            var hasVisible = false;
            subRows.forEach(function(sub) {
                var rType = sub.querySelector('.field-generic');
                var match = !type || (rType && rType.textContent.trim() === type);
                sub.style.display = match ? '' : 'none';
                if (match) hasVisible = true;
            });
            row.style.display = hasVisible ? '' : 'none';
            if (hasVisible) shown++;
        });
        countSpan.textContent = type
            ? shown + ' / ' + rows.length
            : rows.length;
        var url = new URL(window.location);
        if (type) {
            url.searchParams.set('resource_type', type);
        } else {
            url.searchParams.delete('resource_type');
        }
        history.replaceState(null, '', url);
    }

    select.addEventListener('change', filterMaps);
    filterMaps();
})();
