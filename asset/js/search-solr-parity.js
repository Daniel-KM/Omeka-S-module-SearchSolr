'use strict';

/**
 * Fill the parity indicator of each Solr engine in the search manager.
 *
 * The comparison between the resources of the api and the documents of the
 * index costs some hundreds of milliseconds, so it is loaded after the page and
 * served from a cached result, like the module list of Easy Admin.
 */
(function () {
    var indicators = document.querySelectorAll('.solr-parity[data-url]');
    if (!indicators.length) {
        return;
    }

    var render = function (element, data) {
        var types = data.resource_types || {};
        var details = [];
        var hasStale = false;
        Object.keys(types).forEach(function (type) {
            var row = types[type];
            // The indexed documents come first, as a progress: "14300/70010
            // indexed", clearer than two numbers separated by a slash.
            var detail = type + ': ' + row.total_index + '/' + row.total_api
                + ' ' + element.dataset.labelIndexed;
            if (row.total_stale) {
                hasStale = true;
                detail += ' + ' + row.total_stale + ' ' + element.dataset.labelStale;
            }
            details.push(detail);
        });

        if (data.status === 'error') {
            element.className = 'solr-parity o-icon-warning';
            element.textContent = element.dataset.labelError;
        } else if (data.status === 'mismatch') {
            element.className = 'solr-parity o-icon-warning';
            element.textContent = element.dataset.labelMismatch + ' (' + details.join(', ') + ')';
        } else if (hasStale) {
            element.className = 'solr-parity o-icon-warning';
            element.textContent = element.dataset.labelStaleOnly + ' (' + details.join(', ') + ')';
        } else {
            element.className = 'solr-parity o-icon-success';
            element.textContent = element.dataset.labelOk + ' (' + details.join(', ') + ')';
        }

        if (data.warnings && data.warnings.length) {
            element.title = data.warnings.join(' ');
        }
    };

    indicators.forEach(function (element) {
        element.textContent = '…';
        fetch(element.dataset.url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (response) {
                return response.ok ? response.json() : Promise.reject(response.status);
            })
            .then(function (data) {
                render(element, data);
            })
            .catch(function () {
                element.className = 'solr-parity o-icon-warning';
                element.textContent = element.dataset.labelError;
            });
    });
})();
