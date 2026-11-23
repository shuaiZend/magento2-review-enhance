define(['jquery'], function ($) {
    'use strict';

    var GROUPS = ['chat', 'avatar', 'form', 'moderation', 'notify'];

    /**
     * Magento renders configuration groups collapsed and applies the collapse
     * state after DOM ready, so we expand them once the page has fully loaded.
     */
    function expandGroups() {
        $.each(GROUPS, function (index, group) {
            var id = 'giztop_review_' + group,
                fieldset = document.getElementById(id);

            if (!fieldset) {
                return;
            }

            var state = document.getElementById(id + '-state'),
                head = document.getElementById(id + '-head');

            if (state) {
                state.value = '1';
            }

            if (head) {
                head.classList.add('open');
            }

            fieldset.style.display = 'block';

            var section = fieldset.closest ? fieldset.closest('.section-config') : null;
            if (section) {
                section.classList.add('active');
            }
        });
    }

    return function () {
        if (document.readyState === 'complete') {
            window.setTimeout(expandGroups, 300);
        } else {
            window.addEventListener('load', function () {
                window.setTimeout(expandGroups, 300);
            });
        }
    };
});
