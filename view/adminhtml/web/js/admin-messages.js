/**
 * Puts messages on the admin page from JavaScript.
 *
 * The export no longer navigates, so Magento never gets a render in which to show the messages it
 * produced. They come back as JSON and are placed here instead, in Magento's own markup so they sit
 * with anything already on the page.
 */
define(['jquery'], function ($) {
    'use strict';

    var TYPES = ['error', 'warning', 'success', 'notice'];

    /**
     * The admin's own message area: div#messages inside .page-content, per the page layout. It is
     * not .page-main — that is frontend markup, and prependTo() on a selector that matches nothing
     * builds the element and inserts it nowhere, losing every message in silence.
     *
     * The wrapper is absent on a page Magento rendered no messages into, so it may need creating.
     */
    function container() {
        var $own = $('#myparcel-messages');
        var $wrapper;

        // Our own div inside the admin's message area, so clearing never touches Magento's.
        if ($own.length) {
            return $own;
        }

        $wrapper = $('#messages');

        if (!$wrapper.length) {
            $wrapper = $('<div id="messages"/>');

            if ($('.page-main-actions').length) {
                $wrapper.insertAfter($('.page-main-actions').first());
            } else {
                $wrapper.prependTo($('.page-content').first().length ? $('.page-content').first() : 'body');
            }
        }

        return $('<div class="messages" id="myparcel-messages"/>').appendTo($wrapper);
    }

    return {
        /**
         * @param {Array} messages - {type, text} as the export controller returns them. An
         *                           unrecognised type shows as a notice rather than being dropped.
         *
         * @returns {Boolean} whether any of them was an error, which decides whether the caller may
         *                    refresh the page out from under them.
         */
        render: function (messages) {
            var $container;
            var failed = false;

            if (!messages || !messages.length) {
                return false;
            }

            $container = container();

            $.each(messages, function (index, message) {
                var type = -1 === $.inArray(message.type, TYPES) ? 'notice' : message.type;

                failed = failed || 'error' === type;

                // Three classes, as Magento's own renderer emits: the admin styles key off the
                // bare type for the icon and colour. .text(), never .html(): these carry API
                // error strings.
                $container.append(
                    $('<div class="message message-' + type + ' ' + type + '"/>')
                        .append($('<div/>').text(message.text))
                );
            });

            return failed;
        },

        error: function (text) {
            return this.render([{type: 'error', text: text}]);
        },

        /** Only ours: the previous export's messages are stale, Magento's are not. */
        clear: function () {
            container().empty();
        }
    };
});
