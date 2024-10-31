/**
 * Pinpointe Plugin Admin JavaScript
 */
jQuery(document).ready(function() {

    const baseSelectOptions = {
        persist: false,
        create: false,
        hideSelected: true,
        closeAfterSelect: true,
        allowEmptyOption: true,
        highlight: true,
    }

    const tomSelectDefaultOptions = {
        plugins: {
            checkbox_options: {
                className: "custom-ts-checkbox"
            },
            dropdown_input: {},
        },
        ...baseSelectOptions
    };

    const tomSelectDefaultOptionsForMultipleSelect = {
        plugins: {
            checkbox_options: {
                className: "custom-ts-checkbox"
            },
            dropdown_input: {},
            remove_button: {
                title: 'Remove this item',
            },
            clear_button: {
                title: 'Remove all selected options',
                html: function (data) {
                    return `<div class="${data.className}" title="${data.title}"><i class="fa-regular fa-circle-xmark"></i></div>`
                }
            },
            caret_position: {},
        },
        ...baseSelectOptions,
        maxItems: null,
    }

    setTimeout(FormEditorChanged, 1000);

    /**
     * Admin hints
     */
    jQuery('form').each(function(){
        //show/hide confirmation_email depending on overide_confirmation value
        jQuery(this).find('.pinpointe_forms_send_confirmation').each(function(){
            pinpointe_hide_confirmation_email_trs(this);
            pinpointe_hide_confirmation_email_tr(jQuery(this).parent().parent().parent().find('.pinpointe_forms_overide_confirmation'));
        });
        jQuery(this).find('.pinpointe_forms_overide_confirmation').each(function(){
            pinpointe_hide_confirmation_email_tr(this);
        });
        //add hints
        jQuery(this).find(':input').each(function(){
            if (typeof pinpointe_hints !== 'undefined' && typeof pinpointe_hints[jQuery(this).prop('id')] !== 'undefined') {
                jQuery(this).parent().parent().find('th').append('<div class="pinpointe_tip" title="' + pinpointe_hints[jQuery(this).prop('id')] + '"><i class="fa-solid fa-question"></i></div>');
            }
        });
        jQuery(this).find(':checkbox').each(function(){
            if (jQuery(this).prop('id').indexOf('_1') !== -1 || jQuery(this).prop('id').indexOf('_administrator') !== -1) {
                if (jQuery(this).prop('id').indexOf('_1') !== -1) {
                    var this_tip_key = jQuery(this).prop('id').replace('_1', '');
                }
                else {
                    var this_tip_key = jQuery(this).prop('id').replace('_administrator', '');
                }

                if (typeof pinpointe_hints !== 'undefined' && typeof pinpointe_hints[this_tip_key] !== 'undefined') {
                    jQuery(this).parent().parent().parent().parent().find('th').append('<div class="pinpointe_tip" title="' + pinpointe_hints[this_tip_key] + '"><i class="fa-solid fa-question"></i></div>');
                }
            }
        });
    });
    jQuery.widget('ui.tooltip', jQuery.ui.tooltip, {
        options: {
            content: function() {
                return jQuery(this).prop('title');
            }
        }
    });
    jQuery('.pinpointe_tip').tooltip();

    /**
     * show/hide confirmation_email depending on overide_confirmation value
     */
    function pinpointe_hide_confirmation_email_tr(overide_obj) {
        var confirmation_email = jQuery(overide_obj).parent().parent().parent().find('.pinpointe_forms_confirmation_email_tr');
        if (jQuery(overide_obj).is(':checked')) {
            confirmation_email.show();
        } else {
            confirmation_email.hide();
        }
    }

    function form_fields_on_change_handler(current_select_id, current_field_id, value, merge_fields, has_chosen_values = false)
    {
        FormEditorChanged();
        regenerate_tag_chosen(current_field_id);

        if (has_chosen_values) {
            var values = value.split(',');
            value = values[values.length - 1];
        }

        var select_id = current_select_id.replace('pinpointe_field_tag', '');

        if (merge_fields[value]) {

            jQuery('#pinpointe_forms_fields_type' + select_id).val(merge_fields[value]['type']);
            jQuery('#pinpointe_forms_fields_req' + select_id).val(merge_fields[value]['req']);
            jQuery('#pinpointe_forms_fields_us_phone' + select_id).val(merge_fields[value]['us_phone']);
            jQuery('#pinpointe_forms_fields_choices' + select_id).val(merge_fields[value]['choices']);
        }
    }

    jQuery('.pinpointe_forms_overide_confirmation').click(function () {
        pinpointe_hide_confirmation_email_tr(this);
    });

    function pinpointe_hide_confirmation_email_trs(send_confirmation_obj) {
        var confirmation_overide = jQuery(send_confirmation_obj).parent().parent().parent().find('.pinpointe_forms_confirmation_email_trs');
        if (jQuery(send_confirmation_obj).is(':checked')) {
            confirmation_overide.show();
        } else {
            confirmation_overide.hide();
        }
    }

    jQuery('.pinpointe_forms_send_confirmation').click(function() {
        if (!jQuery(this).is(':checked')) {
            jQuery(this).parent().parent().next().find('.pinpointe_forms_overide_confirmation').prop("checked", false);
        }
        pinpointe_hide_confirmation_email_trs(this);
        pinpointe_hide_confirmation_email_tr(jQuery(this).parent().parent().parent().find('.pinpointe_forms_overide_confirmation'));
    });

    /**
     * Hide unused condition fields
     */
    function pinpointe_hide_unused_condition_fields() {

        jQuery('#pinpointe_forms_list').children().each(function() {

            var non_selected_keys = {};

            jQuery(this).find('.form_condition_key').each(function() {
                jQuery(this).children().children().each(function() {
                    if (!jQuery(this).is(':selected')) {
                        var this_key = jQuery(this).val().replace('_not', '');

                        if (typeof non_selected_keys[this_key] !== 'undefined') {
                            non_selected_keys[this_key]++;
                        } else {
                            non_selected_keys[this_key] = 1;
                        }
                    }
                });
            });
            for (var prop in non_selected_keys) {
                if (non_selected_keys[prop] !== 1) {
                    jQuery(this).find('.form_condition_key').parent().parent().parent().parent().parent().find('.form_condition_value_' + prop).each(function() {
                        jQuery(this).parent().parent().hide();
                    });
                }
            }

            jQuery(this).find('.form_condition_key').change(function() {
                var new_form_condition_key = jQuery(this).children().children().filter(':selected').val();

                jQuery(this).parent().parent().parent().find('.form_condition_value').each(function() {
                    jQuery(this).val('');

                    if (!jQuery(this).hasClass('form_condition_value_' + new_form_condition_key)) {
                        jQuery(this).parent().parent().hide();
                    }
                    else {
                        jQuery(this).parent().parent().show();
                    }
                });
            });

        });
    }

    pinpointe_hide_unused_condition_fields();

    /**
     * Load service status
     */
    jQuery('#pinpointe_api_key').each(function() {
        jQuery(this).parent().parent().after('<tr valign="top"><th scope="row">' + pinpointe_label_integration_status + '</th><td><p id="pinpointe-status" class="pinpointe_loading"><span class="pinpointe_loading_icon"></span>' + pinpointe_label_connecting_to_pinpointe + '</p></td></tr>');
    });


    jQuery('#pinpointe-status').each(function() {
        jQuery.post(
                ajaxurl,
                {
                    _wpnonce: jQuery('#pinpointe_status').val(),
                    'action': 'pinpointe_status'
                },
        function(response) {

            try {
                var result = jQuery.parseJSON(response);
            }
            catch (err) {
                jQuery('#pinpointe-status').html(pinpointe_label_bad_ajax_response);
            }

            if (result) {
                jQuery('#pinpointe-status').html(result['message']);
            }
        }
        );
    });

    function activateWPEditors(id) {
        wp.editor.initialize(id, {
            tinymce: {
                height: 500,
                toolbar1:
                    "bold italic underline strikethrough | bullist numlist | blockquote hr | alignleft aligncenter alignright | link unlink",
            },
            quicktags: true,
            mediaButtons: false,
        });
    }

    /**
     * Set up accordion (form management)
     */
    jQuery('#pinpointe_forms_list').accordion({
        header: "> div > h4",
        heightStyle: "content",
        create: function (event, ui) {
            FormEditorChanged();
        }, // SDL
        activate: function (event, ui) {
            var oldEditor = jQuery(ui.oldPanel).find("textarea.pinpointe-field.pinpointe_forms_html_confirmation_email").attr("id");
            
            if (tinyMCE.get(oldEditor) !== null || tinyMCE.get(oldEditor) !== undefined) {
                wp.editor.remove(oldEditor);
            }
            
            var newEditor = jQuery(ui.newPanel).find("textarea.pinpointe-field.pinpointe_forms_html_confirmation_email").attr("id");
            
            if (tinyMCE.get(newEditor)) {
                return;
            } else {
                activateWPEditors(newEditor);
            }
            // activate chosen on all mailing lists when we click on a form
            jQuery('.pinpointe-field.pinpointe_forms_mailing_tag').each(function () {
                if (this.tagName.toLowerCase() === 'select') {
                    if (!this.tomselect) {
                        var {results, selected} = transformMailingListSelectOptionsToObjectArray(this);
                        new TomSelect(this, getMailingListTomSelectOptions(results,selected));
                    }
                }
            });
        },
    }).sortable({
            handle: "h4",
            stop: function (event, ui) {
                regenerate_accordion_handle_titles();
            },
        });

    /**
     * Helper method to initialize a TomSelect element with minimal configuration.
     *
     * @param selectElement
     * @param placeholderText
     * @param options
     */
    function populateTomSelectEditor(selectElement, placeholderText, options = null) {
        if (selectElement === undefined || selectElement === null || selectElement.tagName !== 'SELECT') {
            return;
        }
        let selectOptions;

        if (selectElement.hasAttribute('multiple')) {
            selectOptions = tomSelectDefaultOptionsForMultipleSelect;
        } else {
            selectOptions = tomSelectDefaultOptions;
        }

        if (options) {
            selectOptions = {
                ...selectOptions,
                ...options,
            }
        }


        new TomSelect(
            selectElement, {
                ...selectOptions,
                placeholder: placeholderText,

            }
        );

    }

    function transformMailingListSelectOptionsToObjectArray(selectElement) {
        if (selectElement === undefined || selectElement === null || selectElement.tagName !== 'SELECT') {
            return;
        }

        let results = [];
        let selected = [];

        jQuery(selectElement).find('option').each(function () {
            const $option = jQuery(this);
            const id = $option.val();
            const name = $option.text();
            const description = $option.data('tag-description');

            if (!isNaN(id) && description !== undefined) {
                results.push({
                    id: parseInt(id),
                    name: name,
                    description: description
                });
            }

            if ($option.is(':selected')) {
                selected.push(id);
            }
        })

        return {
            selected,
            results
        };
    }

    function getMailingListTomSelectOptions(options,selectedOptions, placeholderText = 'Choose a mailing list.') {
        return {
            ...tomSelectDefaultOptionsForMultipleSelect,
            items: selectedOptions,
            valueField: 'id',
            searchField: 'name',
            placeholder: placeholderText ?? "Choose a mailing list.",
            options,
            onItemAdd: function (value, $item) {
                if (this.items.length) {
                    jQuery('#submit').prop('disabled', false);
                } else {
                    jQuery('#submit').prop('disabled', true);
                }
            },
            onItemRemove: function (value, $item) {
                if (this.items.length) {
                    jQuery('#submit').prop('disabled', false);
                } else {
                    jQuery('#submit').prop('disabled', true);
                }
            },
            render: {
                option: function (data, escape) {
                    return '<div class="relative flex items-center"><div class="ml-3 grow">' +
                        '<span id="tag-name">' + escape(data.name) + '</span>' +
                        '<span id="tag-description">' + escape(data.description) + '</span>' +
                        '</div></div>';
                },
                item: function(data, escape) {
                    return '<div title="' + escape(data.description) + '">' + escape(data.name) + '</div>';
                }
            },
        }
    }

    function activate_editors() {
        jQuery(
            "textarea.pinpointe-field.pinpointe_forms_html_confirmation_email",
        ).each(function (index, editor) {
            activateWPEditors(editor.id); 
        });
    }

    activate_editors();
    /**
     * Make pages, posts and post_categories fields chosen on form setup 
     */
    jQuery('.form_condition_value_pages').each(function() {
        populateTomSelectEditor(this, pinpointe_label_select_some_pages);
    });
    jQuery('.form_condition_value_posts').each(function() {
        populateTomSelectEditor(this, pinpointe_label_select_some_posts);
    });
    jQuery('.form_condition_value_categories').each(function() {
        populateTomSelectEditor(this, pinpointe_label_select_some_post_categories);
    });

    /**
     * Other "chosen" fields
     */
    jQuery('#pinpointe_after_posts_allowed_forms').each(function() {
        populateTomSelectEditor(this, pinpointe_label_select_some_forms);
    });

    /**
     * Dynamically change form title on the accordion handle
     */
    jQuery('.pinpointe_forms_title_field').each(function() {
        jQuery(this).keyup(function() {
            jQuery(this).parent().parent().parent().parent().parent().parent().find('.pinpointe_forms_title_name').html('- ' + jQuery(this).val());
        });
        jQuery(this).change(function() {
            jQuery(this).parent().parent().parent().parent().parent().parent().find('.pinpointe_forms_title_name').html('- ' + jQuery(this).val());
        });
    });

    var selected_options = [];
    /**
     * Forms page - lists and groups 
     */
    if (jQuery('#pinpointe_forms_list').length) {

        // Disable submit button until lists are loaded
        jQuery('#submit').prop('disabled', true);
        jQuery('#submit').prop('title', pinpointe_label_still_connecting_to_pinpointe);

        jQuery.post(
            ajaxurl,
            {
                _wpnonce: jQuery('#pinpointe_get_tags_with_multiple_groups_and_fields').val(),
                'action': 'pinpointe_get_tags_with_multiple_groups_and_fields',
                'data': pinpointe_selected_tags
            },

            function(response) {
                try {
                    var result = jQuery.parseJSON(response);
                }
                catch (err) {
                    jQuery('.pinpointe_forms_field_tag_groups').html(pinpointe_label_bad_ajax_response);
                    jQuery('.pinpointe_forms_field_fields').html(pinpointe_label_bad_ajax_response);
                }

                if (result && typeof result['message'] === 'object') {
                    /**
                     * Render lists and groups selection // TODO: Prepopulate form with fields from active list
                     */
                    var current_field_id = 0;

                    jQuery('.pinpointe_forms_field_tag_groups').each(function() {
                       //we are increment here cause on new forms i.e, how new forms are added by PHP (saved forms)
                        current_field_id++;
                        let selectedTags = (typeof pinpointe_selected_tags[current_field_id] !== "undefined" &&
                            typeof pinpointe_selected_tags[current_field_id].tags !== "undefined")
                            ? pinpointe_selected_tags[current_field_id].tags
                            : null;

                        // List selection
                        if (typeof result['message']['tags'] === 'object') {

                            var pinpointe_label_mailing_list_name = 'Mailing List Name';
                            var mailing_list_html = '<table id="pinpointe_mailing_list_table_' + current_field_id + '" class="pinpointe_fields_table" style="width: 98%; text-align: left;"><thead><tr><th>' + pinpointe_label_mailing_list_name + '</th></tr></thead><tbody>';

                            var fields = '<option value="">&nbsp;</option>';

                            if (selectedTags === null || selectedTags === undefined) {
                                selectedTags = [];
                            } else {
                                selectedTags = Array.from(selectedTags);
                            }

                            for (var prop in result['message']['tags']) {
                                // is_selected = selectedTags.includes(prop);
                                var tag = result['message']['tags'][prop];
                                var isSelected = selectedTags.includes(prop) === prop ? 'selected' : '';
                                fields += '<option value="' + prop + '" ' + isSelected + ' data-tag-description="' + tag.description + '">' + tag.name;
                                fields += '</option>';
                            }

                            var fieldField = '<select multiple required style="width: 100% !important;"' +
                                'data-placeholder="' + pinpointe_label_select_mailing_tag + '" id="pinpointe_forms_tag_field_' + current_field_id +
                                '" name="pinpointe_options[forms][' + current_field_id + '][tag_field][]" ' +
                                'class="pinpointe-field pinpointe_forms_mailing_tag" data-current-mail-list="' + current_field_id + '">'
                                + fields +
                                '</select>';

                            mailing_list_html += '<tr class="pinpointe_fields_table" id="pinpointe_mailing_list_' + current_field_id +  '"><td>' + fieldField + '</td></tr>';
                            // End table
                            mailing_list_html += '</tbody></table></div>';

                            jQuery(this).replaceWith(mailing_list_html);

                            var data = result['message']['tags'];
                            var transformedData =  Object.keys(data).map(id => ({
                                id: parseInt(id),
                                name: data[id].name,
                                description: data[id].description
                            }));

                            new TomSelect(
                                '#' + jQuery(fieldField).attr('id'),
                                getMailingListTomSelectOptions(transformedData, selectedTags,"Choose a mailing list."),
                            );

                        }
                         pinpointe_forms_page_hints();

                        jQuery('#submit').prop('disabled', true);

                    });
                }
            }
        );

        jQuery.post(
                ajaxurl,
                {
                    _wpnonce: jQuery('#pinpointe_get_lists_groups_fields').val(),
                    'action': 'pinpointe_get_lists_with_multiple_groups_and_fields',
                    'data': pinpointe_selected_lists
                },
        function(response) {

            try {
                var result = jQuery.parseJSON(response);
            }
            catch (err) {
                jQuery('.pinpointe_forms_field_list_groups').html(pinpointe_label_bad_ajax_response);
                jQuery('.pinpointe_forms_field_fields').html(pinpointe_label_bad_ajax_response);
            }

            if (result && typeof result['message'] === 'object') {

                /**
                 * Render lists and groups selection // TODO: Prepopulate form with fields from active list
                 */
                var current_field_id = 0;

                jQuery('.pinpointe_forms_field_list_groups').each(function() {

                    current_field_id++;

                    var current_selected_list = (typeof pinpointe_selected_lists[current_field_id] !== 'undefined' && typeof pinpointe_selected_lists[current_field_id]['list'] !== 'undefined' ? pinpointe_selected_lists[current_field_id]['list'] : null);

                    // List selection
                    if (typeof result['message']['lists'] === 'object') {
                        // var fields = '';
                        var fields = '<option value="">&nbsp;</option>'; // SDL
                        for (var prop in result['message']['lists']) {
                            fields += '<option value="' + prop + '" ' + (current_selected_list !== null && current_selected_list === prop ? 'selected="selected"' : '') + '>' + result['message']['lists'][prop] + '</option>';
                        }
                        var current_element_id = 'pinpointe_forms_list_field_' + current_field_id;
                        var field_field = '<select id="' + current_element_id + '" name="pinpointe_options[forms][' + current_field_id + '][list_field]" class="pinpointe-field pinpointe_forms_mailing_list">' + fields + '</select>';
                        var field_html = '<table class="form-table" style="margin-bottom: 0px;"><tbody><tr valign="top"><th scope="row">' + pinpointe_label_mailing_list + '</th><td>' + field_field + '</td></tr></tbody></table>';

                        jQuery(this).replaceWith(field_html);

                        new TomSelect('#pinpointe_forms_list_field_' + current_field_id,{
                            ...tomSelectDefaultOptions,
                            maxItems: 1,
                            placeholder: pinpointe_label_select_mailing_list,
                            onChange(data) {
                                var current_field_id = jQuery(`#${current_element_id}`).prop('id').replace('pinpointe_forms_list_field_', '');
                                pinpointe_update_groups_and_tags(current_field_id, data);
                            }
                        });

                     }

                     pinpointe_forms_page_hints();

                });

                /**
                 * Render merge fields selection
                 */
                var current_field_id = 0;

                if (typeof result['message']['merge'] === 'object') {

                    jQuery('.pinpointe_forms_field_fields').each(function() {

                        current_field_id++;

                        var current_selected_list = (typeof pinpointe_selected_lists[current_field_id] !== 'undefined' && typeof pinpointe_selected_lists[current_field_id]['list'] !== 'undefined' ? pinpointe_selected_lists[current_field_id]['list'] : null);
                        var current_selected_merge = (typeof pinpointe_selected_lists[current_field_id] !== 'undefined' && typeof pinpointe_selected_lists[current_field_id]['merge'] !== 'undefined' ? pinpointe_selected_lists[current_field_id]['merge'] : null);

                        render_forms_merge_fields_table(current_field_id, current_selected_list, current_selected_merge, result['message']['merge']);
                    });

                }

                /**
                 * Update accordion height
                 */
                jQuery('#pinpointe_forms_list').accordion('refresh');

                /**
                 * Enable add set button
                 */
                jQuery('#pinpointe_add_set').prop('disabled', false);
                jQuery('#pinpointe_add_set').prop('title', '');

                /**
                 * Enable submit button
                 */
                //jQuery('#submit').prop('disabled', false);
                //jQuery('#submit').prop('title', '');
                FormEditorChanged; // SDL

            }

        });
    }
    /**
     * Disable the selected options in all selects in a form
     * 
     */
    function hide_selected_options() {
        jQuery('.pinpointe-field.pinpointe_forms_mailing_tag').each(function (index) {
            var prevSelect = jQuery(this);

            for (var i = 0; i < selected_options.length; i++) {
                var optionValue = selected_options[i];
                var optionToDisable = prevSelect.find('option[value="' + optionValue + '"]');

                if (optionToDisable.length) {
                    optionToDisable.hide();
                }
            }
        });
    }

    /**
     * Enable option for the deleted select
     */
    function show_deleted_option(selected_value) {
        jQuery('.pinpointe-field.pinpointe_forms_mailing_tag').each(function (index) {
            var optionToEnable = jQuery(this).find('option[value="' + selected_value + '"]');

            if (optionToEnable.length) {
                optionToEnable.show();

                const indexToRemove = selected_options.indexOf(selected_value);

                // Remove the element at the specified index
                selected_options.splice(indexToRemove, 1);
            } 
        });
    }

    jQuery(document).on('click', '#pinpointe_add_mailing_list', function () {

        // Get the current mailing list table
        var mailingListTable = jQuery(this).parent().parent().parent().parent();

        var current_id = parseInt(mailingListTable.attr('id').split('_').pop(), 10);
        
        // Get the last row ID
        var last_mailing_list = parseInt(mailingListTable.find('tbody>tr:last').attr('id').split('_').pop(), 10);

        var last_select = jQuery('#pinpointe_forms_tag_field_' + current_id + '_' + last_mailing_list);
    
        var last_selected_value = last_select.find('option:selected').val();
        if (last_selected_value === '') {
            return;
        }
        if (!selected_options.includes(last_selected_value)) {
            selected_options.push(last_selected_value);
        }

        // hide_selected_options('#pinpointe_forms_tag_field_' + current_id + '_' + last_mailing_list);

        var last_select_selected_values = [];
        if  (last_select.get(0) !== undefined && last_select.get(0).tomselect) {
            last_select_selected_values = last_select.get(0).tomselect.getValue();
            last_select.get(0).tomselect.destroy();
        }

        var next_id = last_mailing_list + 1;

        // Clone the last row and insert after the last one
        var newRow = mailingListTable.find('tbody>tr:last').clone(true);
        newRow.attr('id', 'pinpointe_mailing_list_' + current_id + '_' + next_id);

        // Update IDs and clear values as needed
        newRow.find('select').val('');
        newRow.find('select').attr('id', 'pinpointe_forms_tag_field_' + current_id + '_' + next_id)
        newRow.find('select').attr('data-current-mail-list', current_id + '_' + next_id);

        newRow.find('#selected_name_' + current_id + '_' +  last_mailing_list).attr('id', 'selected_name_' + current_id + '_' + next_id);
        newRow.find('#selected_description_' + current_id + '_' +  last_mailing_list).attr('id', 'selected_description_' + current_id + '_' + next_id);

        newRow.find('.pinpointe_remove_mailing_list').attr('data-row-id', + current_id + '_' + next_id);

        // Re-activate Chosen on the cloned select element
        var newSelectRow = newRow.find('#pinpointe_forms_tag_field_' + current_id + '_' + next_id)
        newSelectRow.attr('required', true);
        if (newSelectRow && !newSelectRow.get(0).tomselect) {
            var { results, selected } = transformMailingListSelectOptionsToObjectArray(
                newSelectRow.get(0)
            );
            new TomSelect(
                '#' + newSelectRow.attr('id'),
                getMailingListTomSelectOptions(results, selected,pinpointe_label_select_tag)
            );
        }
      
        // Add the new row to the table
        mailingListTable.find('tbody').append(newRow);

      
        var options = transformMailingListSelectOptionsToObjectArray(last_select.get(0));

        if (last_select_selected_values.length) {
            options.selected = options.selected.concat(last_select_selected_values);
        }

        new TomSelect('#' + last_select.attr('id'), getMailingListTomSelectOptions(options.results, options.selected));

    });

    /**
     * Render merge fields table
     */
    function render_forms_merge_fields_table(current_field_id, current_selected_list, current_selected_merge, merge_fields) {

        if (current_selected_list !== null) {
            merge_fields = merge_fields[current_selected_list];
        }
        else {
            merge_fields = [];
        }

        // Generate options
        var field_options = '<option value>&nbsp;</option>';

        if (typeof merge_fields === 'object') {
            for (var prop in merge_fields) {
                if (merge_fields.hasOwnProperty(prop)) {
                    field_options += '<option value="' + prop + '">' + merge_fields[prop]['name'] + ' (' + prop + ', ' + merge_fields[prop]['type'] + (merge_fields[prop]['req'] ? ', ' + 'req' : '') + ')</option>';
                }
            }
        }

        // Set up name field depending on page type
        var input_field = '<input type="text" class="pinpointe_name_input" name="pinpointe_options[forms][' + current_field_id + '][fields][%%%id%%%][name]" id="pinpointe_forms_fields_name_' + current_field_id + '_%%%id%%%" value="%%%value%%%" />';

        // Set up list of Font Awesome icons
        var font_awesome_list = '<option value=""></option>';

        if (typeof pinpointe_font_awesome_icons === 'object') {
            for (var prop in pinpointe_font_awesome_icons) {
                font_awesome_list += '<option value="' + prop + '">' + pinpointe_font_awesome_icons[prop] + '</option>';
            }
        }

        // Begin table
        // SDL: var fields_table = '<table id="pinpointe_fields_table_' + current_field_id + '" class="pinpointe_fields_table"><thead><tr><th>' + pinpointe_label_fields_name + '</th><th>' + pinpointe_label_fields_tag + '</th><th>' + pinpointe_label_fields_icon + '</th><th></th></tr></thead><tbody>';
		// Moved field label after field
        var fields_table = '<table id="pinpointe_fields_table_' + current_field_id + '" class="pinpointe_fields_table"><thead><tr><th>' + pinpointe_label_fields_tag + '</th><th>' + pinpointe_label_fields_name + '</th><th>' + pinpointe_label_fields_icon + '</th><th></th></tr></thead><tbody>';

        // Table content with preselected options
        if (typeof current_selected_merge === 'object' && current_selected_merge !== null && Object.keys(current_selected_merge).length > 0) {
            for (var prop in current_selected_merge) {
                if (current_selected_merge.hasOwnProperty(prop) && typeof merge_fields != 'undefined' && typeof merge_fields[current_selected_merge[prop]['tag']] === 'object') {
                    var this_field = input_field.replace('%%%id%%%', prop);
                    this_field = this_field.replace('%%%id%%%', prop);
                    this_field = this_field.replace('%%%value%%%', current_selected_merge[prop]['name']);
					// SDL: Moved field label after field
                    fields_table += '<tr class="pinpointe_field_row" id="pinpointe_field_' + current_field_id + '_' + prop + '"><td><select class="pinpointe_tag_select" name="pinpointe_options[forms][' + current_field_id + '][fields][' + prop + '][tag]" id="pinpointe_field_tag_' + current_field_id + '_' + prop + '" style="width: 346px">' + field_options + '</select></td><td>' + this_field + '</td><td><select name="pinpointe_options[forms][' + current_field_id + '][fields][' + prop + '][icon]" id="pinpointe_field_icon_' + current_field_id + '_' + prop + '" class="pinpointe_fields_icon">' + font_awesome_list + '</select></td><td><button type="button" class="pinpointe_remove_field"><i class="fa-solid fa-xmark"></i></button></td><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][' + prop + '][type]" id="pinpointe_forms_fields_type_' + current_field_id + '_' + prop + '" value="' + (typeof merge_fields[current_selected_merge[prop]['tag']]['type'] !== 'undefined' ? merge_fields[current_selected_merge[prop]['tag']]['type'] : '') + '" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][' + prop + '][req]" id="pinpointe_forms_fields_req_' + current_field_id + '_' + prop + '" value="' + (typeof merge_fields[current_selected_merge[prop]['tag']]['req'] !== 'undefined' ? merge_fields[current_selected_merge[prop]['tag']]['req'] : '') + '" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][' + prop + '][us_phone]" id="pinpointe_forms_fields_us_phone_' + current_field_id + '_' + prop + '" value="' + (typeof merge_fields[current_selected_merge[prop]['tag']]['us_phone'] !== 'undefined' ? merge_fields[current_selected_merge[prop]['tag']]['us_phone'] : '') + '" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][' + prop + '][choices]" id="pinpointe_forms_fields_choices_' + current_field_id + '_' + prop + '" value="' + (typeof merge_fields[current_selected_merge[prop]['tag']]['choices'] !== 'undefined' ? merge_fields[current_selected_merge[prop]['tag']]['choices'] : '') + '" /></tr>';
                }
            }
        }

        // Table content with no preselected options
        else {
            var this_field = input_field.replace('%%%id%%%', '1');
            this_field = this_field.replace('%%%id%%%', '1');
            this_field = this_field.replace('%%%value%%%', '');
            // SDL: fields_table += '<tr class="pinpointe_field_row" id="pinpointe_field_' + current_field_id + '_1"><td>' + this_field + '</td><td><select class="pinpointe_tag_select" name="pinpointe_options[forms][' + current_field_id + '][fields][1][tag]" id="pinpointe_field_tag_' + current_field_id + '_1">' + field_options + '</select></td><td><select name="pinpointe_options[forms][' + current_field_id + '][fields][1][icon]" id="pinpointe_field_icon_' + current_field_id + '_1" class="pinpointe_fields_icon">' + font_awesome_list + '</select></td><td><button type="button" class="pinpointe_remove_field"><i class="fa-solid fa-xmark"></i></button></td><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][type]" id="pinpointe_forms_fields_type_' + current_field_id + '_1" value="" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][req]" id="pinpointe_forms_fields_req_' + current_field_id + '_1" value="" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][us_phone]" id="pinpointe_forms_fields_us_phone_' + current_field_id + '_1" value="" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][choices]" id="pinpointe_forms_fields_choices_' + current_field_id + '_1" value="" /></tr>';
            fields_table += '<tr class="pinpointe_field_row" id="pinpointe_field_' + current_field_id + '_1"><td><select class="pinpointe_tag_select" name="pinpointe_options[forms][' + current_field_id + '][fields][1][tag]" id="pinpointe_field_tag_' + current_field_id + '_1" style="width: 346px;">' + field_options + '</select></td><td>' + this_field + '</td><td><select name="pinpointe_options[forms][' + current_field_id + '][fields][1][icon]" id="pinpointe_field_icon_' + current_field_id + '_1" class="pinpointe_fields_icon">' + font_awesome_list + '</select></td><td><button type="button" class="pinpointe_remove_field"><i class="fa-solid fa-xmark"></i></button></td><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][type]" id="pinpointe_forms_fields_type_' + current_field_id + '_1" value="" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][req]" id="pinpointe_forms_fields_req_' + current_field_id + '_1" value="" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][us_phone]" id="pinpointe_forms_fields_us_phone_' + current_field_id + '_1" value="" /><input type="hidden" name="pinpointe_options[forms][' + current_field_id + '][fields][1][choices]" id="pinpointe_forms_fields_choices_' + current_field_id + '_1" value="" /></tr>';
        }

        // End table
        fields_table += '</tbody><tfoot><tr><td><button type="button" name="pinpointe_add_field" id="pinpointe_add_field" class="button button-primary" value="' + pinpointe_label_add_new + '"><i class="fa-solid fa-plus">&nbsp;&nbsp;' + pinpointe_label_add_new + '</i></button></td><td></td><td></td></tr></tfoot></table></div>';

        // Output table
        jQuery('#pinpointe_fields_table_' + current_field_id).replaceWith(fields_table);

        // Select preselected options
        if (typeof current_selected_merge === 'object' && current_selected_merge !== null && Object.keys(current_selected_merge).length > 0) {
            for (var prop in current_selected_merge) {
                if (current_selected_merge.hasOwnProperty(prop)) {
                    jQuery('#pinpointe_field_tag_' + current_field_id + '_' + prop).find('option[value="' + current_selected_merge[prop]['tag'] + '"]').prop('selected', true);
                    jQuery('#pinpointe_field_icon_' + current_field_id + '_' + prop).find('option[value="' + current_selected_merge[prop]['icon'] + '"]').prop('selected', true);
                }
            }
        }

        // Make all select fields chosen
        jQuery('#pinpointe_fields_table_' + current_field_id).find('.pinpointe_tag_select').each(function() {
            var select_item = jQuery(this);
            select_item.css('width', '346px');

            var current_select_id = select_item.attr('id');

            // // tom-select
            new TomSelect("#" + current_select_id,{
                ...tomSelectDefaultOptions,
                maxItems: 1,
                placeholder: pinpointe_label_select_tag ?? "Select a field",
                onChange(value) {
                    form_fields_on_change_handler(current_select_id, current_field_id, value, merge_fields);
                }
            });
        });

        // Regenerate fields (so we make selected fields disabled on other fields)
        regenerate_tag_chosen(current_field_id);

        /**
         * Handle new fields
         */
        jQuery('#pinpointe_forms_list_' + current_field_id).find('#pinpointe_add_field').each(function() {
            jQuery(this).click(function() {
                var $table = jQuery(this).parent().parent().parent().parent();

                // Get set id and last field id
                var table_last_tr_id = jQuery($table).find('tbody>tr:last').attr('id');
                table_last_tr_id = table_last_tr_id.replace('pinpointe_field_', '');
                table_last_tr_id = table_last_tr_id.split('_');

                var current_field_id = table_last_tr_id[0];
                var current_id = table_last_tr_id[1];

                // remove tomselect
                var current_field_tag_select = jQuery($table).find('#pinpointe_field_tag_' + current_field_id + '_' + current_id).get(0);
                var curruent_field_tag_select_value = current_field_tag_select.value;

                // remove tom select.
                current_field_tag_select.tomselect.destroy();

                // Clone row and insert after the last one
                var new_fields_row = jQuery($table).find('tbody>tr:last').clone(true);
                jQuery($table).find('tbody>tr:last').after(new_fields_row);

                jQuery($table).find('tbody>tr:last').each(function() {

                    // Change ids
                    var next_id = parseInt(current_id, 10) + 1;
                    jQuery(this).attr('id', 'pinpointe_field_' + current_field_id + '_' + next_id);
                    jQuery(this).find(':input').each(function() {
                        if (jQuery(this).is('input') && jQuery(this).hasClass('pinpointe_name_input')) {
                            jQuery(this).attr('id', 'pinpointe_forms_fields_name_' + current_field_id + '_' + next_id);
                            jQuery(this).attr('name', 'pinpointe_options[forms][' + current_field_id + '][fields][' + next_id + '][name]');
                            jQuery(this).val('');
                        }
                        else if (jQuery(this).is('select') && jQuery(this).hasClass('pinpointe_tag_select')) {
                            jQuery(this).attr('id', 'pinpointe_field_tag_' + current_field_id + '_' + next_id);
                            jQuery(this).attr('name', 'pinpointe_options[forms][' + current_field_id + '][fields][' + next_id + '][tag]');
                            jQuery(this).val('');
                        }
                        else if (jQuery(this).is('select') && jQuery(this).hasClass('pinpointe_fields_icon')) {
                            jQuery(this).attr('id', 'pinpointe_field_icon_' + current_field_id + '_' + next_id);
                            jQuery(this).attr('name', 'pinpointe_options[forms][' + current_field_id + '][fields][' + next_id + '][icon]');
                            jQuery(this).val('');
                        }
                        else if (jQuery(this).prop('id') === 'pinpointe_forms_fields_type_' + current_field_id + '_' + current_id) {
                            jQuery(this).attr('id', 'pinpointe_forms_fields_type_' + current_field_id + '_' + next_id);
                            jQuery(this).attr('name', 'pinpointe_options[forms][' + current_field_id + '][fields][' + next_id + '][type]');
                            jQuery(this).val('');
                        }
                        else if (jQuery(this).prop('id') === 'pinpointe_forms_fields_req_' + current_field_id + '_' + current_id) {
                            jQuery(this).attr('id', 'pinpointe_forms_fields_req_' + current_field_id + '_' + next_id);
                            jQuery(this).attr('name', 'pinpointe_options[forms][' + current_field_id + '][fields][' + next_id + '][req]');
                            jQuery(this).val('');
                        }
                        else if (jQuery(this).prop('id') === 'pinpointe_forms_fields_us_phone_' + current_field_id + '_' + current_id) {
                            jQuery(this).attr('id', 'pinpointe_forms_fields_us_phone_' + current_field_id + '_' + next_id);
                            jQuery(this).attr('name', 'pinpointe_options[forms][' + current_field_id + '][fields][' + next_id + '][us_phone]');
                            jQuery(this).val('');
                        }
                        else if (jQuery(this).prop('id') === 'pinpointe_forms_fields_choices_' + current_field_id + '_' + current_id) {
                            jQuery(this).attr('id', 'pinpointe_forms_fields_choices_' + current_field_id + '_' + next_id);
                            jQuery(this).attr('name', 'pinpointe_options[forms][' + current_field_id + '][fields][' + next_id + '][choices]');
                            jQuery(this).val('');
                        }
                    });

                    var cloned_select = jQuery('#pinpointe_field_tag_' + current_field_id + '_' + next_id);
                    cloned_select.css('width', '346px');

                    let tomSelectOptions = {
                        ...tomSelectDefaultOptions,
                        maxItems: 1,
                        placeholder: pinpointe_label_select_tag ?? "Select a field",
                        hidePlaceholder: false,
                    }
                    var current_select = jQuery('#pinpointe_field_tag_' + current_field_id + '_' + current_id);
                    new TomSelect(
                        "#" + current_select.attr('id'),
                        {
                            ...tomSelectOptions,
                            items: curruent_field_tag_select_value,
                            onChange(value) {
                                form_fields_on_change_handler(current_select.prop('id'), current_field_id, value, merge_fields);

                            }
                        }
                    );
                   new TomSelect(
                        "#" + cloned_select.prop('id'),
                       {
                           ...tomSelectOptions,

                           onChange(value) {
                               form_fields_on_change_handler(cloned_select.prop('id'), next_id, value, merge_fields, true);
                           }
                       }
                    );

                });

                regenerate_tag_chosen(current_field_id);
		        FormEditorChanged(); // SDL

                return false;

            });
        });

        /**
         * Handle field removal
         */
        jQuery('.pinpointe_remove_field').each(function() {
            jQuery(this).click(function() {
                // Do not remove the last set - reset field values instead
                if (jQuery(this).parent().parent().parent().children().length === 1) {
                    jQuery(this).parent().parent().find(':input').each(function() {
                        if (this.tomselect) {
                            this.tomselect.clear();
                        }

                        jQuery(this).val('');
                    });
                }
                else {
                    jQuery(this).parent().parent().remove();
                }

                FormEditorChanged();

                jQuery('.pinpointe_name_select').each(function() {
                    if (this.tomselect) {
                        this.tomselect.trigger("change");
                    }
                });

                regenerate_tag_chosen(current_field_id);
            });
        });

    }

    /**
     * Checkout - regenerate all chosen fields
     */
    function regenerate_tag_chosen(current_field_id) {
        var all_selected = {};

        // Get all selected fields
        jQuery('#pinpointe_forms_list_' + current_field_id).find('.pinpointe_tag_select').each(function() {
            if (jQuery(this).find(':selected').length > 0 && jQuery(this).find(':selected').val() !== '') {
                all_selected[jQuery(this).prop('id')] = jQuery(this).find(':selected').val();
            }
        });

        // Regenerate chosen fields
        jQuery('#pinpointe_forms_list_' + current_field_id).find('.pinpointe_tag_select').each(function() {

            if (Object.keys(all_selected).length !== 0) {

                for (var prop in all_selected) {

                    if (prop !== jQuery(this).prop('id')) {

                        // Disable
                        jQuery(this).find('option[value="' + all_selected[prop] + '"]').prop('disabled', true);

                        if (this.tomselect) { // add the already selected item to be selected. That way it cant be selected again.
                            this.tomselect.items.push(all_selected[prop]);
                        }
                    }

                    // Enable previously disabled values if they are available now
                    jQuery(this).find(':disabled').each(function() {

                        // Check if such disabled property exists within selected properties
                        var option_value = jQuery(this).val();
                        var exists = false;

                        for (var proper in all_selected) {
                            if (all_selected[proper] === option_value) {
                                exists = true;
                                break;
                            }
                        }

                        // Remove if it does not exist
                        if (!exists) {
                            jQuery(this).removeAttr('disabled');
                        }

                    });

                }
            }
            else {
                // Enable all properties on all fields if there's only one left
                jQuery(this).find(':disabled').each(function() {
                    jQuery(this).removeAttr('disabled');
                });
            }

            if (this.tomselect) {
                this.tomselect.trigger("load");
            }

        });
    }

    /**
     * Checkout - handle list change
     */
    function pinpointe_update_groups_and_tags(current_field_id, list_id) {

        // Replace groups field with loading animation
        var preloader = '<p id="pinpointe_forms_groups_' + current_field_id + '" class="pinpointe_loading"><span class="pinpointe_loading_icon"></span>' + pinpointe_label_connecting_to_pinpointe + '</p>';
        jQuery('#pinpointe_forms_groups_' + current_field_id).parent().html(preloader);

        // Replace fields section with loading animation
        var preloader = '<div class="pinpointe-status" id="pinpointe_fields_table_' + current_field_id + '"><p class="pinpointe_loading"><span class="pinpointe_loading_icon"></span>' + pinpointe_label_connecting_to_pinpointe + '</p></div>';
        jQuery('#pinpointe_fields_table_' + current_field_id).replaceWith(preloader);

        // Disable add set button until groups and fields are updated
        jQuery('#pinpointe_add_set').prop('disabled', true);
        jQuery('#pinpointe_add_set').prop('title', pinpointe_label_still_connecting_to_pinpointe);

        // Disable submit button until groups and fields are updated
        jQuery('#submit').prop('disabled', true);
        jQuery('#submit').prop('title', pinpointe_label_still_connecting_to_pinpointe);

        // Get data
        jQuery.post(
                ajaxurl,
                {
                    _wpnonce: jQuery('#pinpointe_update_groups_and_tags').val(),
                    'action': 'pinpointe_update_groups_and_tags',
                    'data': {'list': list_id}
                },
        function(response) {

            try {
                var result = jQuery.parseJSON(response);
            }
            catch (err) {
                jQuery('.pinpointe_loading').html(pinpointe_label_bad_ajax_response);
            }

            if (result && typeof result['message'] === 'object') {
                // Render groups field
                if (typeof result['message']['groups'] === 'object') {
                    var fields = '';

                    for (var prop in result['message']['groups']) {
                        fields += '<option value="' + prop + '">' + result['message']['groups'][prop] + '</option>';
                    }

                    // Update DOM
                    jQuery('#pinpointe_forms_groups_' + current_field_id).replaceWith('<select multiple id="pinpointe_forms_groups_' + current_field_id + '" name="pinpointe_options[forms][' + current_field_id + '][groups][]" class="pinpointe-field">' + fields + '</select>');
                }

                // Render merge fields table
                try {
                    render_forms_merge_fields_table(current_field_id, list_id, null, result['message']['merge']);
                } catch(e) {
                    // Ignore the exception because the list was likely just
                    // deleted and we're accessing a property of undefined
                }

                /**
                 * Enable add set button
                 */
                jQuery('#pinpointe_add_set').prop('disabled', false);
                jQuery('#pinpointe_add_set').prop('title', '');

                /**
                 * Enable submit button
                 */
                //jQuery('#submit').prop('disabled', false);
                //jQuery('#submit').prop('title', '');
                FormEditorChanged(); // SDL

            }
        }
        );
    }

    /**
     * Checkout - add new set
     */
    jQuery('#pinpointe_add_set').click(function() {

        // Get last field id
        var current_id = (jQuery('#pinpointe_forms_list>div:last-child').attr('id').replace('pinpointe_forms_list_', ''));

        // Remove chosen from all fields that have one
        // remove tomselect from all fields that have one.
        // var chosen_removed_from = [];
        var tomselect_removed_from_ids = [];

        jQuery('#pinpointe_forms_list>div:last-child').find('select').each(function() {
            if (!jQuery(this).hasClass('form_condition_key') && !jQuery(this).hasClass('pinpointe_forms_color_scheme') && !jQuery(this).hasClass('pinpointe_fields_icon') && !jQuery(this).hasClass('pinpointe_form_group_method')) {
                tomselect_removed_from_ids.push({
                    id: this.getAttribute('id'),
                    selected: this.tomselect.getValue(),
                });
                this.tomselect.destroy();
                // chosen_removed_from.push(jQuery(this).prop('id'));
                // jQuery(this).chosen('destroy');
            }
        });

        // Clone element and insert after the last one
        jQuery('#pinpointe_forms_list>div:last-child').clone(true).insertAfter('#pinpointe_forms_list>div:last-child');

        // Regenerate tomselect on previous fields
        for (var i = 0, len = tomselect_removed_from_ids.length; i < len; i++) {
            var additionalOptions = tomselect_removed_from_ids[i].selected.length
                ? { items: tomselect_removed_from_ids[i].selected}
                : null
            if (tomselect_removed_from_ids[i].id.search('pinpointe_forms_list_field_') !== -1) {
                // mailing lists databases.
                var selectElement = document.querySelector('#' + tomselect_removed_from_ids[i].id);
                var options = {
                    ...tomSelectDefaultOptions,
                    maxItems: 1,
                    placeholder: pinpointe_label_select_mailing_list,
                    onChange(data) {
                        var current_field_id = selectElement.getAttribute('id')
                            .replace('pinpointe_forms_list_field_', '');
                        pinpointe_update_groups_and_tags(current_field_id, data);
                    }
                }

                if (additionalOptions.items) {
                    options.items = additionalOptions.items
                }

                new TomSelect(
                    selectElement,
                    options
                )
            }
            else if (tomselect_removed_from_ids[i].id.search('pinpointe_forms_tag_field_') !== -1) {
                var select = document.querySelector('#' + tomselect_removed_from_ids[i].id);
                var {results, selected} = transformMailingListSelectOptionsToObjectArray(select);

                if (selected.length  <= 0) {
                    selected =  tomselect_removed_from_ids[i].selected
                } else {
                    selected =  selected.concat(tomselect_removed_from_ids[i].selected)
                }

                new TomSelect(
                    select,
                    getMailingListTomSelectOptions(results, selected)
                )
            }
            else if (tomselect_removed_from_ids[i].id.search('pinpointe_forms_groups_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + tomselect_removed_from_ids[i].id),
                    pinpointe_label_select_some_groups,
                    additionalOptions
                )
            }
            else if (tomselect_removed_from_ids[i].id.search('pinpointe_field_tag_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + tomselect_removed_from_ids[i].id),
                    pinpointe_label_select_tag,
                    additionalOptions
                )
            }
            else if (tomselect_removed_from_ids[i].id.search('pinpointe_forms_condition_pages_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + tomselect_removed_from_ids[i].id),
                    pinpointe_label_select_some_pages,
                    additionalOptions
                )
            }
            else if (tomselect_removed_from_ids[i].id.search('pinpointe_forms_condition_posts_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + tomselect_removed_from_ids[i].id),
                    pinpointe_label_select_some_posts,
                    additionalOptions
                )
            }
            else if (tomselect_removed_from_ids[i].id.search('pinpointe_forms_condition_categories_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + tomselect_removed_from_ids[i].id),
                    pinpointe_label_select_some_post_categories,
                    additionalOptions
                )
            }
        }

        /**
         * Fix new elements
         */
        jQuery('#pinpointe_forms_list>div:last-child').each(function() {

            // Get next id (well.. it's current already)
            var next_id = parseInt(current_id, 10) + 1;

            // Change main div id
            jQuery(this).attr('id', 'pinpointe_forms_list_' + next_id);

            // Remove name from accordion handle
            jQuery(this).find('.pinpointe_forms_title_name').html('');

            // Change id and name of form title field and clear its value
            jQuery(this).find('#pinpointe_forms_title_field_' + current_id).attr('id', 'pinpointe_forms_title_field_' + next_id);
            jQuery('#pinpointe_forms_title_field_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][title]');
            jQuery('#pinpointe_forms_title_field_' + next_id).val('');

            // Change id and name of form above text field and clear its value
            jQuery(this).find('#pinpointe_forms_above_field_' + current_id).attr('id', 'pinpointe_forms_above_field_' + next_id);
            jQuery('#pinpointe_forms_above_field_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][above]');
            jQuery('#pinpointe_forms_above_field_' + next_id).val('');

            // Change id and name of form below text field and clear its value
            jQuery(this).find('#pinpointe_forms_below_field_' + current_id).attr('id', 'pinpointe_forms_below_field_' + next_id);
            jQuery('#pinpointe_forms_below_field_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][below]');
            jQuery('#pinpointe_forms_below_field_' + next_id).val('');

            // Change id and name of button label field and clear its value
            jQuery(this).find('#pinpointe_forms_button_field_' + current_id).attr('id', 'pinpointe_forms_button_field_' + next_id);
            jQuery('#pinpointe_forms_button_field_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][button]');
            jQuery('#pinpointe_forms_button_field_' + next_id).val('');

            // Change id and name of redirect URL field and clear its value
            jQuery(this).find('#pinpointe_forms_redirect_url_' + current_id).attr('id', 'pinpointe_forms_redirect_url_' + next_id);
            jQuery('#pinpointe_forms_redirect_url_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][redirect_url]');
            jQuery('#pinpointe_forms_redirect_url_' + next_id).val('');

             //Change id and name of the following fields and clear their values, except for confirmation email which we will delete

            // 1.Send confirmation email
            jQuery(this).find('#pinpointe_forms_send_confirmation_' + current_id).attr('id', 'pinpointe_forms_send_confirmation_' + next_id);
            jQuery('#pinpointe_forms_send_confirmation_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][send_confirmation]');
            jQuery('#pinpointe_forms_send_confirmation_' + next_id).prop("checked", false);


            // 2.Override default confirmation email
            jQuery(this).find('#pinpointe_forms_overide_confirmation_' + current_id).attr('id', 'pinpointe_forms_overide_confirmation_' + next_id);
            jQuery('#pinpointe_forms_overide_confirmation_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][overide_confirmation]');
            jQuery('#pinpointe_forms_overide_confirmation_' + next_id).prop("checked", false);

            pinpointe_hide_confirmation_email_trs(jQuery('#pinpointe_forms_send_confirmation_' + next_id));
            pinpointe_hide_confirmation_email_tr(jQuery('#pinpointe_forms_overide_confirmation_' + next_id));
           
            // 3.Confirmation email

            //rename the td housing the email so that we can locate it
            jQuery(this).find('#forms_confirmation_email_td_' + current_id).attr('id', 'forms_confirmation_email_td_' + next_id);
            
            //take the content from the last form
            var content = jQuery('#pinpointe_forms_html_confirmation_email_' + current_id).val();
            
            //remove the full email well the cloned one...
            jQuery(this).find('#wp-pinpointe_forms_html_confirmation_email_' + current_id + '-wrap').remove();

            // Create a new textarea element
            var newTextarea = jQuery('<textarea>');

            newTextarea.addClass('pinpointe-field pinpointe_forms_html_confirmation_email');
            newTextarea.attr('name', 'pinpointe_options[forms][' + next_id + '][html_confirmation_email]');
            newTextarea.attr('id', 'pinpointe_forms_html_confirmation_email_' + next_id);
            newTextarea.attr('rows', '25');
            newTextarea.attr("style", "width:100%");
            newTextarea.text(content);

            // Append the new textarea element to the specified td element
            jQuery('#forms_confirmation_email_td_'+ next_id).append(newTextarea);

            activate_editors();

            //reset the selected options with the selected tags from the new form
            selected_options = [];
           
            let selectedTagsForNewForm = (typeof pinpointe_selected_tags[next_id] !== "undefined" &&
                typeof pinpointe_selected_tags[next_id].tags !== "undefined")
                ? pinpointe_selected_tags[next_id].tags
                : null;
            
            //preselect the save opts fro this 'new' form
            if (selectedTagsForNewForm && Array.isArray(selectedTagsForNewForm)) {
                for (let i = 0; i < selectedTagsForNewForm.length; i++) {
                    let currentSelectedTag = selectedTagsForNewForm[i - 1];
                    selected_options.push(currentSelectedTag);
                }
            }
           
            // Change ids and names of mailing list and groups fields
            //id="pinpointe_mailing_list_table_' + current_id + '_' + current_mailing_list_id + '"
            jQuery(this).find('#pinpointe_mailing_list_table_' + current_id).attr('id', 'pinpointe_mailing_list_table_' + next_id);
            jQuery('#pinpointe_mailing_list_table_' + next_id + ' tbody tr:not(:first-child)').remove();
            
            //pinpointe_forms_tag_field_1_1_chosen
            jQuery(this).find('.chosen-single span').text("");

            var trElement = jQuery(this).find('#pinpointe_mailing_list_table_' + next_id + ' tbody tr:first-child');
            trElement.attr('id', 'pinpointe_mailing_list_' + next_id + '_1');

            var selectElement = trElement.find('td select.pinpointe_forms_mailing_tag');

            // Destroy the tomselect instance before making changes (this must happen this way)
            if (selectElement.tomselect) {
                selectElement.tomselect.destroy();
            } 

            if (selectElement.get(0) !== undefined && selectElement.get(0).tomselect) {
                selectElement.get(0).tomselect.destroy();
            }

            // hide the select and force select the empty opt so that chosen can take over
            selectElement.find('option:selected').prop('selected', false);
            selectElement.css('display', 'none');
            selectElement.find('option[value=""]').attr('selected', true);

            // force clear the prevous opt from the old form
            trElement.find('td div#pinpointe_forms_tag_field_'+ current_id +'_1_chosen.chosen-container.chosen-container-single a.chosen-single span').text('');

            // update attrs
            selectElement.val('');
            selectElement.attr('id', 'pinpointe_forms_tag_field_' + next_id + '_1');
            selectElement.attr('name', 'pinpointe_options[forms][' + next_id + '][tag_field][]');
            selectElement.attr('data-current-mail-list', next_id + '_1');
            selectElement.attr('style', "width: 100% !important");
            selectElement.attr('required', true);

            // Reinitialize chosen after updating attributes
            const { results, selected} = transformMailingListSelectOptionsToObjectArray(selectElement.get(0));

            new TomSelect(
                selectElement,
                getMailingListTomSelectOptions(results, selected)
            );

            // Now, we can find the p elements inside the first tr and update their attributes
            var nameParagraph = trElement.find('p[id^="selected_name_"]');
            var descriptionParagraph = trElement.find('p[id^="selected_description_"]');

            // Update attributes for name paragraph & clear the text so that we can start afresh again
            nameParagraph.attr('id', 'selected_name_' + next_id + '_1');
            descriptionParagraph.attr('id', 'selected_description_' + next_id + '_1');

            nameParagraph.text('');
            descriptionParagraph.text('');

            // Update the data-row-id attribute for the button in the last td
            var removeButton = trElement.find('td button.pinpointe_remove_mailing_list');
            removeButton.attr('data-row-id', next_id + '_1');

            jQuery(this).find('#pinpointe_forms_list_field_' + current_id).attr('id', 'pinpointe_forms_list_field_' + next_id);
            jQuery('#pinpointe_forms_list_field_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][list_field]');
            jQuery(this).find('#pinpointe_forms_groups_' + current_id).attr('id', 'pinpointe_forms_groups_' + next_id);
            jQuery('#pinpointe_forms_groups_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][groups][]');

            // Remove selected options from mailing list
            jQuery('#pinpointe_forms_list_field_' + next_id).find('option:selected').prop('selected', false);

            // Remove all options from groups
            jQuery('#pinpointe_forms_groups_' + next_id).html('<option value=""></option>');

            // Change id and name of the condition key field and reset selection
            jQuery(this).find('#pinpointe_forms_group_method_' + current_id).attr('id', 'pinpointe_forms_group_method_' + next_id);
            jQuery('#pinpointe_forms_group_method_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][group_method]');
            jQuery('#pinpointe_forms_group_method_' + next_id).find('option:selected').prop('selected', false);

            // Change id of fields table
            jQuery(this).find('#pinpointe_fields_table_' + current_id).attr('id', 'pinpointe_fields_table_' + next_id);

            // Remove all field table rows except of first one
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('tr:gt(0)').remove();

            // Change id of the first fields table row
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('tr').attr('id', 'pinpointe_field_' + next_id + '_1');

            // Change id and name of first field name field and clear value
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('.pinpointe_name_input').attr('id', 'pinpointe_forms_fields_name_' + next_id + '_1');
            jQuery('#pinpointe_forms_fields_name_' + next_id + '_1').attr('name', 'pinpointe_options[forms][' + next_id + '][fields][1][name]');
            jQuery('#pinpointe_forms_fields_name_' + next_id + '_1').val('');

            // Change id and name of first field tag field and remove all options
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('.pinpointe_tag_select').attr('id', 'pinpointe_field_tag_' + next_id + '_1');
            jQuery('#pinpointe_field_tag_' + next_id + '_1').attr('name', 'pinpointe_options[forms][' + next_id + '][fields][1][tag]');
            jQuery('#pinpointe_field_tag_' + next_id + '_1').html('<option value="">&nbsp;&nbsp;</option>');

            // Change id and name of first field icon field and remove all options
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('.pinpointe_fields_icon').attr('id', 'pinpointe_field_icon_' + next_id + '_1');
            jQuery('#pinpointe_field_icon_' + next_id + '_1').attr('name', 'pinpointe_options[forms][' + next_id + '][fields][1][icon]');

            // Change id and name of first type field and clear value
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('#pinpointe_forms_fields_type_' + current_id + '_1').attr('id', 'pinpointe_forms_fields_type_' + next_id + '_1');
            jQuery('#pinpointe_forms_fields_type_' + next_id + '_1').attr('name', 'pinpointe_options[forms][' + next_id + '][fields][1][type]');
            jQuery('#pinpointe_forms_fields_type_' + next_id + '_1').val('');

            // Change id and name of first type field and clear value
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('#pinpointe_forms_fields_req_' + current_id + '_1').attr('id', 'pinpointe_forms_fields_req_' + next_id + '_1');
            jQuery('#pinpointe_forms_fields_req_' + next_id + '_1').attr('name', 'pinpointe_options[forms][' + next_id + '][fields][1][req]');
            jQuery('#pinpointe_forms_fields_req_' + next_id + '_1').val('');

            // Change id and name of first type field and clear value
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('#pinpointe_forms_fields_us_phone_' + current_id + '_1').attr('id', 'pinpointe_forms_fields_us_phone_' + next_id + '_1');
            jQuery('#pinpointe_forms_fields_us_phone_' + next_id + '_1').attr('name', 'pinpointe_options[forms][' + next_id + '][fields][1][us_phone]');
            jQuery('#pinpointe_forms_fields_us_phone_' + next_id + '_1').val('');

            // Change id and name of first type field and clear value
            jQuery('#pinpointe_fields_table_' + next_id + ' > tbody').find('#pinpointe_forms_fields_choices_' + current_id + '_1').attr('id', 'pinpointe_forms_fields_choices_' + next_id + '_1');
            jQuery('#pinpointe_forms_fields_choices_' + next_id + '_1').attr('name', 'pinpointe_options[forms][' + next_id + '][fields][1][choices]');
            jQuery('#pinpointe_forms_fields_choices_' + next_id + '_1').val('');

            // Change id and name of the condition key field and reset selection
            jQuery(this).find('#pinpointe_forms_condition_' + current_id).attr('id', 'pinpointe_forms_condition_' + next_id);
            jQuery('#pinpointe_forms_condition_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][condition]');
            jQuery('#pinpointe_forms_condition_' + next_id).find('option:selected').prop('selected', false);

            // Change id and name of the condition pages value field and reset selections
            jQuery(this).find('#pinpointe_forms_condition_pages_' + current_id).attr('id', 'pinpointe_forms_condition_pages_' + next_id);
            jQuery('#pinpointe_forms_condition_pages_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][condition_pages][]');
            jQuery('#pinpointe_forms_condition_pages_' + next_id).find('option:selected').prop('selected', false);

            // Change id and name of the condition posts value field and reset selections
            jQuery(this).find('#pinpointe_forms_condition_posts_' + current_id).attr('id', 'pinpointe_forms_condition_posts_' + next_id);
            jQuery('#pinpointe_forms_condition_posts_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][condition_posts][]');
            jQuery('#pinpointe_forms_condition_posts_' + next_id).find('option:selected').prop('selected', false);

            // Change id and name of the condition posts value field and reset selections
            jQuery(this).find('#pinpointe_forms_condition_categories_' + current_id).attr('id', 'pinpointe_forms_condition_categories_' + next_id);
            jQuery('#pinpointe_forms_condition_categories_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][condition_categories][]');
            jQuery('#pinpointe_forms_condition_categories_' + next_id).find('option:selected').prop('selected', false);

            // Change id and name of form condition url field and clear its value
            jQuery(this).find('#pinpointe_forms_condition_url_' + current_id).attr('id', 'pinpointe_forms_condition_url_' + next_id);
            jQuery('#pinpointe_forms_condition_url_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][condition_url]');
            jQuery('#pinpointe_forms_condition_url_' + next_id).val('');

            // Change id and name of the color scheme field and reset selection
            jQuery(this).find('#pinpointe_forms_color_scheme_' + current_id).attr('id', 'pinpointe_forms_color_scheme_' + next_id);
            jQuery('#pinpointe_forms_color_scheme_' + next_id).attr('name', 'pinpointe_options[forms][' + next_id + '][color_scheme]');
            jQuery('#pinpointe_forms_color_scheme_' + next_id).find('option:selected').prop('selected', false);
        });

        /**
         * Make new select fields chosen
         */
        jQuery('#pinpointe_forms_list>div:last-child').find('select').each(function() {
            var current_select_id = jQuery(this).prop('id');

            if (current_select_id.search('pinpointe_forms_list_field_') !== -1) {
                new TomSelect(
                    document.querySelector('#' + current_select_id),
                    {
                        ...tomSelectDefaultOptions,
                        maxItems: 1,
                        placeholder: pinpointe_label_select_mailing_list,
                        onChange(data) {
                            var current_field_id = current_select_id.replace('pinpointe_forms_list_field_', '');

                            pinpointe_update_groups_and_tags(current_field_id, data);
                        }
                    }
                )
                
                setTimeout(function() {
                    jQuery('#' + current_select_id).change();
                }, 100);
            }
            else if (current_select_id.search('pinpointe_forms_groups_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + current_select_id),
                    pinpointe_label_select_some_groups
                );
            }
            else if (current_select_id.search('pinpointe_field_tag_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + current_select_id),
                    pinpointe_label_select_tag
                );
            }
            else if (current_select_id.search('pinpointe_forms_condition_pages_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + current_select_id),
                    pinpointe_label_select_some_pages
                );
            }
            else if (current_select_id.search('pinpointe_forms_condition_posts_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + current_select_id),
                    pinpointe_label_select_some_posts
                );
            }
            else if (current_select_id.search('pinpointe_forms_condition_categories_') !== -1) {
                populateTomSelectEditor(
                    document.querySelector('#' + current_select_id),
                    pinpointe_label_select_some_post_categories
                );
            }

        });

        pinpointe_hide_unused_condition_fields();
        regenerate_tag_chosen(current_id);

        /**
         * Update accordion
         */
        jQuery('#pinpointe_forms_list').accordion('refresh');
        var $accordion = jQuery("#pinpointe_forms_list").accordion();
        var last_accordion_element = $accordion.find('h4').length;
        $accordion.accordion('option', 'active', (last_accordion_element - 1));
        regenerate_accordion_handle_titles();

        return false;
    });

    /**
     * Checkout - remove set
     */
    jQuery('.pinpointe_forms_remove').each(function() {
        jQuery(this).click(function() {

            // Remove set if it's not the last one
            if (jQuery(this).parent().parent().parent().children().length !== 1) {
                jQuery(this).parent().parent().remove();
            }

            /**
             * Update accordion
             */
            jQuery('#pinpointe_forms_list').accordion('refresh');
            regenerate_accordion_handle_titles();

        });
    });

    /**
     * Regenerate accordion handle titles
     */
    function regenerate_accordion_handle_titles()
    {
        var fake_id = 1;

        jQuery('#pinpointe_forms_list').children().each(function() {
            jQuery(this).find('.pinpointe_forms_title').html(pinpointe_label_signup_form_no + '' + fake_id);
            fake_id++;
        });
    }

    /**
     * Checkboxes and Sync list
     */
    jQuery('#pinpointe_checkbox_list').each(function() {
        pinpointe_load_single_list_field('checkbox');
    });
    jQuery('#pinpointe_sync_list').each(function() {
        pinpointe_load_single_list_field('sync');
    });

    function pinpointe_load_single_list_field(context)
    {
        jQuery('#pinpointe_' + context + '_list').replaceWith('<p id="pinpointe_' + context + '_list" class="pinpointe_loading"><span class="pinpointe_loading_icon"></span>' + pinpointe_label_connecting_to_pinpointe + '</p>');

        jQuery.post(
            ajaxurl,
            {
                _wpnonce: jQuery('#pinpointe_get_lists').val(),
                'action': 'pinpointe_get_lists'
            },
            function(response) {

                try {
                    var result = jQuery.parseJSON(response);
                }
                catch (err) {
                    jQuery('.pinpointe_loading').html(pinpointe_label_bad_ajax_response);
                }

                if (result && typeof result['message'] === 'object' && typeof result['message']['lists'] === 'object') {
                    var fields = '';

                    for (var prop in result['message']['lists']) {
                        fields += '<option value="' + prop + '" ' + (pinpointe_selected_list !== null && pinpointe_selected_list === prop ? 'selected="selected"' : '') + '>' + result['message']['lists'][prop] + '</option>';
                    }

                    jQuery('#pinpointe_' + context + '_list').replaceWith('<select id="pinpointe_' + context + '_list" name="pinpointe_options[pinpointe_' + context + '_list]" class="pinpointe-field">' + fields + '</select>');
                }
            }
        );
    }

    /**
     * Set up forms page hints
     */
    function pinpointe_forms_page_hints()
    {
        if (typeof pinpointe_forms_hints !== 'undefined') {
            jQuery.each(pinpointe_forms_hints, function(index, value) {
                jQuery('form').find('.' + index).each(function() {
                    jQuery(this).parent().parent().find('th').each(function() {
                        if (jQuery(this).find('.pinpointe_tip').length === 0) {
                            jQuery(this).append('<div class="pinpointe_tip" title="' + value + '"><i class="fa-solid fa-question"></i></div>');
                        }
                    });
                });
            });
        }
        jQuery.widget('ui.tooltip', jQuery.ui.tooltip, {
            options: {
                content: function() {
                    return jQuery(this).prop('title');
                }
            }
        });
        jQuery('.pinpointe_tip').tooltip();
    }

});

/**
 * Called when the form editor changes to determine whether the save button
 * should be re-enabled or not.
 */

var FormEditorChanged = function() {
    var enabled = true;
    var count = 0;

    jQuery('.pinpointe_fields_table').each(function() {
	
		// SDL:
		//enabled = jQuery(this).find('option[value="email"]:selected').length == 1;
        if(jQuery(this).find('option[value="email"]:selected').length != 1) {
            enabled = false;
        }  else {
            enabled = true;
        }

        /* SDL
        count++;
        console.log(this + " >> " + count + " >> " + enabled);
        */

    });

    if(!enabled) {
        jQuery('#submit').attr('disabled', 'disabled');
    } else {
        jQuery('#submit').removeAttr('disabled');
    }

};
