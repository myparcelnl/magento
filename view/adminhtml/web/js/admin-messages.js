/**
 * Puts messages on the admin page from JavaScript.
 *
 * The export does not navigate, so Magento never renders the messages it produced. They come back
 * as JSON and are placed here, in Magento's own markup, next to anything already on the page.
 */
define(['jquery'], function ($) {
    'use strict';

    var TYPES = ['error', 'warning', 'success', 'notice'];
    var STORAGE_KEY = 'myparcel-messages';

    /** What render() put on the page since the last clear(), for keepForReload(). */
    var shown = [];

    /**
     * The admin's own message area: div#messages inside .page-content, per the page layout. It is
     * not .page-main: that is frontend markup, and prependTo() on a selector that matches nothing
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
         */
        render: function (messages) {
            var $container;

            if (!messages || !messages.length) {
                return;
            }

            $container = container();
            shown = shown.concat(messages);

            $.each(messages, function (index, message) {
                var type = -1 === $.inArray(message.type, TYPES) ? 'notice' : message.type;

                // Three classes, as Magento's own renderer emits: the admin styles key off the
                // bare type for the icon and colour. .text(), never .html(): these carry API
                // error strings.
                $container.append(
                    $('<div class="message message-' + type + ' ' + type + '"/>')
                        .append($('<div/>').text(message.text))
                );
            });
        },

        error: function (text) {
            this.render([{type: 'error', text: text}]);
        },

        /** Only ours: the previous export's messages are stale, Magento's are not. */
        clear: function () {
            shown = [];
            container().empty();
        },

        /** Keep the shown messages for the next page load. Lost when storage is blocked. */
        keepForReload: function () {
            try {
                window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(shown));
            } catch (e) {
                // Private window or blocked storage: the reload then loses the messages, as before.
            }
        },

        /** Show the messages that keepForReload() kept, once. */
        restore: function () {
            var kept = null;

            try {
                kept = JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) || 'null');
                window.sessionStorage.removeItem(STORAGE_KEY);
            } catch (e) {
                kept = null;
            }

            if ($.isArray(kept) && kept.length) {
                this.render(kept);
            }
        }
    };
});
