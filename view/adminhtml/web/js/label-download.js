/**
 * Fetches the label PDF for an export that has just run, and hands it to the admin.
 *
 * It is fetched rather than navigated to. Moving the page to the PDF URL would take away the export
 * messages standing next to the grid before they could be read, and would lose the selection with
 * them. With fetch, a failure is reported beside those messages instead.
 */
define(['jquery', 'MyParcelNL_Magento/js/admin-messages'], function ($, messages) {
    'use strict';

    var FALLBACK_NAME = 'myparcel-labels.pdf';

    /**
     * The name the controller already put on the PDF, so the file is named in one place rather than
     * once per caller. Every label response carries it; the fallback is for a body that somehow
     * arrives without one, which would otherwise download as the page's own URL.
     */
    function nameFrom(response) {
        var match = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') || '');

        return match ? match[1] : FALLBACK_NAME;
    }

    function save(blob, name, tab) {
        var url = window.URL.createObjectURL(blob);

        if (tab) {
            // Not revoked: the tab is still reading it, and there is no event that says when it is
            // done. The URL dies with this document anyway.
            tab.location = url;

            return;
        }

        $('<a/>', {href: url, download: name}).appendTo('body')[0].click();

        // Freed on the next tick: revoking immediately can cancel the download in some browsers.
        window.setTimeout(function () {
            window.URL.revokeObjectURL(url);
        }, 0);
    }

    /**
     * @param {Object}      labels - {url, failureLabel} as the export returned them.
     * @param {Window|null} tab    - a tab opened during the click that started this, to be filled
     *                               with the PDF. Opening one here would be blocked as a popup.
     *
     * @returns {Promise<Boolean>} whether the PDF was delivered; a failure has been reported.
     */
    return function (labels, tab) {
        function fail(text) {
            if (tab) {
                tab.close();
            }

            messages.error(text || labels.failureLabel);

            return false;
        }

        return fetch(labels.url, {credentials: 'same-origin'})
            .then(function (response) {
                if (response.ok) {
                    return response.blob().then(function (blob) {
                        save(blob, nameFrom(response), tab);

                        return true;
                    });
                }

                // The controller answers JSON on failure precisely so this can be said out loud.
                return response.json().then(function (answer) {
                    return fail(answer && answer.message);
                });
            })
            .catch(function () {
                // A dropped connection, or a body that is neither a file nor JSON.
                return fail();
            });
    };
});
