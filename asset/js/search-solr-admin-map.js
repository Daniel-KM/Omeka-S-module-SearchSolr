'use strict';

/*
 * Copyright BibLibre, 2016
 * Copyright Paul Sarrassat, 2018
 * Copyright Daniel Berthereau, 2017-2025
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

(function() {

    const chosenOptions = {
        allow_single_deselect: true,
        disable_search_threshold: 10,
        width: '100%',
        search_contains: true,
        include_group_label_in_selected: true,
    };

    // Schema and sourceLabels are set in the form.
    // var schema = schema || {};
    // var sourceLabels = sourceLabels || {};

    var fieldTypesByName = {};
    var fieldsByName = {};

    for (let i in schema.fieldTypes) {
        var type = schema.fieldTypes[i];
        fieldTypesByName[type.name] = type;
    }

    for (let i in schema.fields) {
        var field = schema.fields[i];
        fieldsByName[field.name] = field;
    }

    for (let i in schema.dynamicFields) {
        var field = schema.dynamicFields[i];
        fieldsByName[field.name] = field;
    }

    function updateSourceData() {
        const resourceName = $('input[name="o:resource_name"]').val();
        if (resourceName) {
            const source = $('fieldset#o-source').closest('.field');
            const newSource = $('fieldset#o-source-' + resourceName).closest('.field');
            if (source.length) {
                $(source).html($(newSource[0]).html().replace('o:source/' + resourceName, '').replace('o-source-' + resourceName, ''));
                $('fieldset#o-source').closest('.field').find('select.chosen-select').chosen(chosenOptions);
            }
        }
    }

    function generateFieldName() {
        var field = $('#field-selector').val();
        if (!field) {
            return;
        }
        var fieldName = field;
        var input = $('input[name="o:field_name"]');
        var indexOfStar = field.indexOf('*');
        if (indexOfStar != -1) {
            var source = $('select[name="o:source[0][source]"]').val();
            source = source.replace(/[^a-zA-Z0-9]/g, '_');
            fieldName = field.replace('*', source);

            var inputIndexForLink = $('input[name="o:settings[index_for_link]"]');
            if (inputIndexForLink.is(':checked')) {
                var linkInsertPos = indexOfStar + source.length;
                fieldName = fieldName.slice(0, linkInsertPos) + '_link' + fieldName.slice(linkInsertPos);
            }

            setTimeout(function() {
                var htmlInput = input.get(0);
                htmlInput.focus();
                htmlInput.setSelectionRange(indexOfStar, indexOfStar + source.length);
            }, 100);
        }
        input.val(fieldName).trigger('change');
    }

    function generateSourceLabel() {
        var source = $('#source-selector').val();
        if (!source) {
            return;
        }

        var label = '';
        if (~source.indexOf('/')) {
            label = source.substr(0, source.indexOf('/'));
            label = sourceLabels.hasOwnProperty(label)
                ? sourceLabels[label]
                : (label.charAt(0).toUpperCase() + label.slice(1));
            label += ' / ';
            source = source.substr(source.indexOf('/') + 1);
        }
        label += sourceLabels.hasOwnProperty(source)
            ? sourceLabels[source]
            : (source.charAt(0).toUpperCase() + source.slice(1));

        var input = $('input[name="o:settings[label]"]');
        input.val(label.replace(/_/g, ' '));
    }

    function showTypeInfo() {
        var objectToUL = function(obj) {
            var ul = $('<ul>');
            for (let key in obj) {
                var value = obj[key];
                var li = $('<li>');
                li.append('<strong>' + key + ':</strong> ');
                if (typeof value === 'string') {
                    li.append(value);
                } else if (typeof value === 'boolean') {
                    li.append(value ? 'yes' : 'no');
                } else if (typeof value === 'object') {
                    li.append(objectToUL(value));
                }
                ul.append(li);
            }

            return ul;
        }

        var fieldName = $('#field-selector').val();
        var fieldInfo = $('#field-info');
        if (fieldInfo.length == 0) {
            var fieldInfoContents = $('<div>', { id: 'field-info-contents' })
                .hide();

            var fieldInfoLink = $('<a>', {
                    id: 'field-info-link',
                    href: '#',
                })
                .html('Field info')
                .on('click', function(e) {
                    e.preventDefault();
                    if (fieldInfoContents.is(':visible')) {
                        fieldInfoContents.hide();
                        $(this).removeClass('show');
                    } else {
                        fieldInfoContents.show();
                        $(this).addClass('show');
                    }
                });

            fieldInfo = $('<div>', { id: 'field-info' })
                .append(fieldInfoLink)
                .append(fieldInfoContents);

            $('input[name="o:field_name"]')
                .after(fieldInfo)
        }
        if (fieldName) {
            var field = fieldsByName[fieldName];
            var type = fieldTypesByName[field.type];

            fieldInfo.find('#field-info-contents').empty()
                .append('<h4>' + Omeka.jsTranslate('Field') + '</h4>')
                .append(objectToUL(field))
                .append('<h4>' + Omeka.jsTranslate('Type') + '</h4>')
                .append(objectToUL(type));

            fieldInfo.show();
        } else {
            fieldInfo.hide();
        }
    }

    function subPropertyChange(checkbox, index) {
        var container = $(checkbox).parent().parent().parent().parent(); //fieldset
        var template = container.children('span').attr('data-template');
        var count = container.children('fieldset').length;
        if (checkbox.checked) {
            template = template.replace(/__index__/g, count);
            container.append(template);
            $('input[name="o:source['+count+'][set_sub]"]').on('change', function() {
                subPropertyChange(this, count);
            });
        } else {
            container.children('fieldset').slice(index+1).remove();
        }
    }

    $(document).ready(function() {

        $('input[name="o:resource_name"]').on('change', function() {
            updateSourceData();
        });

        $('select[name="o:source[0][source]"]')
            .attr('id', 'source-selector');

        $('select[name="o:source[0][source]"]')
            .chosen({
                allow_single_deselect: true,
                disable_search_threshold: 10,
                width: '100%',
                search_contains: true,
                include_group_label_in_selected: true,
            })
            .on('change', function() {
                generateFieldName();
                generateSourceLabel();
            });

        // Sub-property managing.
        $('input[name="o:source[0][set_sub]"]')
            .on('change', function() {
                subPropertyChange(this, 0);
            });

        // Init main select and input.

        var select = $('<select>', {
            id: 'field-selector',
            'data-placeholder': Omeka.jsTranslate('Choose a field…'),
        });

        var inputIndexForLink = $('input[name="o:settings[index_for_link]"]');

        var inputParts = $('input[name="o:settings[parts][]"]');

        var emptyOption = $('<option>').val('');
        select.append(emptyOption);

        var fields = schema.fields.filter(function(f) {
            if (f.name.startsWith('_') && f.name.endsWith('_')) {
                return false;
            }
            if (f.name === 'id') {
                return false;
            }
            var type = fieldTypesByName[f.type];
            var indexed = 'indexed' in f ? f.indexed : type.indexed;
            if (!indexed){
                return false;
            }

            return true;
        });

        if (fields.length) {
            var fieldsOptGroup = $('<optgroup>', {
                label: Omeka.jsTranslate('Field'),
            });
            for (let i in fields) {
                var field = fields[i];
                if (field.name.startsWith('_') && field.name.endsWith('_'))
                var option = $('<option>')
                    .val(field.name)
                    .html(field.name + ' (' + field.type + ')');
                fieldsOptGroup.append(option);
            }
            select.append(fieldsOptGroup);
        }

        var dynamicFields = schema.dynamicFields.filter(function(f) {
            var type = fieldTypesByName[f.type];
            var indexed = 'indexed' in f ? f.indexed : type.indexed;
            return indexed ? true : false;
        });

        if (dynamicFields.length) {
            var dynamicFieldsOptGroup = $('<optgroup>', {
                label: Omeka.jsTranslate('Dynamic field'),
            });
            for (let i in dynamicFields) {
                var field = dynamicFields[i];
                var option = $('<option>')
                    .val(field.name)
                    .html(field.name + ' (' + field.type + ')');
                dynamicFieldsOptGroup.append(option);
            }
            select.append(dynamicFieldsOptGroup);
        }

        select.on('change', function() {
            generateFieldName();
        });

        select.on('change chosen:updated', function() {
            showTypeInfo();
        });

        var input = $('input[name="o:field_name"]');

        input.before(select);

        select.chosen(chosenOptions);

        inputIndexForLink.on('change', function() {
            generateFieldName();
            if ($(this).is(':checked')) {
                inputParts.filter('[value="link"]').prop('checked', true);
            }
        });

        var timeout = 0;
        var regexps = {};
        input
            .on('keyup', function() {
                clearTimeout(timeout);
                var value = $(this).val();
                timeout = setTimeout(function() {
                    var matchedField = '';
                    for (let i in fields) {
                        var field = fields[i];
                        if (field.name == value) {
                            matchedField = field.name;
                            break;
                        }
                    }
                    if (!matchedField) {
                        for (let i in dynamicFields) {
                            var field = dynamicFields[i];
                            if (!(field.name in regexps)) {
                                var pattern = '^' + field.name.replace('*', '.*') + '$';
                                regexps[field.name] = new RegExp(pattern);
                            }
                            if (value.match(regexps[field.name])) {
                                matchedField = field.name;
                                break;
                            }
                        }
                    }
                    select.val(matchedField).trigger('chosen:updated');
                }, 200);
            })
            .trigger('keyup');

        // Display the info for each formatter.

        function displayInfoFormatter(){
            const field = $('fieldset[name="o:settings"] .field .inputs')[0];
            const selectedRadio = $(field).find('input[type=radio]:checked');
            const msg = selectedRadio.length ? selectedRadio.attr('title') : '';
            const info = $(field).find('.input-info');
            info.text(msg);
        }

        $('input[name="o:settings[formatter]"]').on('click', displayInfoFormatter);

        // Display the specific settings of each formatter.

        function toggleSettingsFormatter() {
            const val = $('input[type=radio][name="o:settings[formatter]"]:checked').val();
            $('[data-formatter]:not([data-formatter="' + val +'"])').closest('.field').hide();
            $('[data-formatter="' + val +'"]').closest('.field').show();
        }

        $('input[type=radio][name="o:settings[formatter]"]')
            .on('change', toggleSettingsFormatter);

        // On load.
        $('fieldset[name="o:settings"] .field .inputs').append('<div class="input-info"></div>');
        displayInfoFormatter();

        toggleSettingsFormatter();

        // Display the specific settings of each normalization.

        function toggleSettingsNormalization() {
            // Hide all settings unchecked, then display the ones checked.
            $('input[type=checkbox][name="o:settings[normalization]"]:not(checked)').closest('.field').hide();
            const checkeds = $('input[type=checkbox][name="o:settings[normalization]"]:checked').closest('.field').show();
        }

        $('input[type=checkbox][name="o:settings[normalization]"]')
            .on('change',toggleSettingsNormalization);

        // On submit.
        $('#solr-map-form').on('submit', function() {
            $('input[name^="o:source/"]').remove();
        });

    });
})();
