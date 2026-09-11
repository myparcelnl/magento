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

    // PrintMyParcelLabels::HEADER_INCOMPLETE. Set when a batch spanning several accounts lost one
    // of them and merged the rest anyway.
    var HEADER_INCOMPLETE = 'X-MyParcel-Label-Warnings';

    /**
     * The name the controller already put on the PDF, so the file is named in one place rather than
     * once per caller. Every label response carries it; the fallback is for a body that somehow
     * arrives without one, which would otherwise download as the page's own URL.
     */
    function nameFrom(response) {
        var match = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') || '');

        return match ? match[1] : FALLBACK_NAME;
    }

    /**
     * Says which accounts the PDF is missing labels for. A warning, not an error: the document is
     * real and the caller must still count the download as delivered.
     */
    function warnIfIncomplete(response) {
        var warning = response.headers.get(HEADER_INCOMPLETE);

        if (!warning) {
            return;
        }

        messages.render([{type: 'warning', text: decodeURIComponent(warning)}]);
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
     * The request for the PDF.
     *
     * POSTed when the caller supplies params, because a bulk export's shipment id list is long
     * enough to push an admin URL past what a web server will accept. A per-row "Download label"
     * names one order and passes a ready-made href instead, which stays a GET.
     */
    function request(labels) {
        if (!labels.params) {
            return fetch(labels.url, {credentials: 'same-origin'});
        }

        var body = new URLSearchParams(labels.params);

        // Admin POSTs are rejected without it.
        body.set('form_key', window.FORM_KEY);

        return fetch(labels.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        });
    }

    /**
     * @param {Object}      labels - {url, params, failureLabel} as the export returned them.
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

        return request(labels)
            .then(function (response) {
                if (response.ok) {
                    warnIfIncomplete(response);

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
