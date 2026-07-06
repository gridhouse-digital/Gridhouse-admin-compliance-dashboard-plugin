jQuery(document).ready(function($) {
    var $tbody = $('#ghca-audit-mapping-tbody');
    
    if ($tbody.length) {
        $tbody.sortable({
            handle: '.ghca-drag-handle',
            placeholder: 'ghca-sortable-placeholder',
            axis: 'y',
            update: function(event, ui) {
                // Update the hidden sort order fields
                $tbody.find('.ghca-mapping-row').each(function(index) {
                    $(this).find('.ghca-sort-order').val(index);
                });
            }
        });
    }
});
