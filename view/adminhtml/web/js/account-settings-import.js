define(['jquery', 'mage/loader'], function ($) {
    'use strict';

    /**
     * Bound via data-mage-init on the settings_button.phtml root div.
     * config keys: ajaxUrl, buttonId, apiKeyFieldId, scopeName, scopeId,
     *              successLabel, failurePrefix, failureLabel.
     *
     * The button is disabled while there is no api key to import against, and again for the duration
     * of the request: the import is the only place an invalid key surfaces, so the outcome has to be
     * reported rather than left to a spinner that simply stops.
     *
     * The page-wide overlay runs alongside that report, not instead of it. The notice appears next to
     * the button and reflows everything below, so the admin must not be working further down the page
     * when it lands. fetch() does not pass through loaderAjax, so the pair is triggered by hand; the
     * loader counts its starts, and the finally block keeps them balanced.
     */
    return function (config, element) {
        const button = document.getElementById(config.buttonId);
        const input  = document.getElementById(config.apiKeyFieldId);
        const notice = element.querySelector('[data-role="import-notice"]');

        if (!button) {
            return;
        }

        function report(className, message) {
            notice.className = 'message ' + className;
            notice.textContent = message;
            notice.hidden = false;
        }

        function messageFrom(answer, fallback) {
            return (answer && answer.message) ? config.failurePrefix + ' ' + answer.message : fallback;
        }

        // The api key field is disabled at a scope that inherits its value, and importing against an
        // inherited key from that scope would write the parent's settings.
        if (input && input.disabled) {
            button.setAttribute('disabled', 'disabled');
        } else {
            button.removeAttribute('disabled');
        }

        button.addEventListener('click', function () {
            notice.hidden = true;
            button.setAttribute('disabled', 'disabled');
            $('body').trigger('processStart');

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    scope: config.scopeName,
                    scopeId: config.scopeId,
                    form_key: window.FORM_KEY
                }).toString()
            }).then(function (response) {
                return response.json().then(function (answer) {
                    if (!response.ok || !answer || answer.success !== true) {
                        report('message-error', messageFrom(answer, config.failureLabel));

                        return;
                    }

                    report('message-success', config.successLabel);
                });
            }).catch(function () {
                // A non-JSON body or a dropped connection: the log is the only place left to look.
                report('message-error', config.failureLabel);
            }).finally(function () {
                $('body').trigger('processStop');
                button.removeAttribute('disabled');
            });
        });
    };
});
