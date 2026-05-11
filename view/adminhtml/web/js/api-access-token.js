define(['jquery'], function ($) {
    'use strict';

    /**
     * Bound via data-mage-init on the api_access_token_button.phtml root div.
     * config keys: fieldId, ajaxUrl, revokeUrl, scopeName, scopeId,
     *              generateLabel, regenerateLabel, unexpectedLabel,
     *              failureLabel, revokeFailLabel, revokeConfirm.
     */
    return function (config, element) {
        const $el         = $(element);
        const generateBtn = $el.find('#' + config.fieldId + '_generate')[0];
        const revokeBtn   = $el.find('#' + config.fieldId + '_revoke')[0];
        const current     = $el.find('#' + config.fieldId + '_current')[0];
        const plaintext   = $el.find('#' + config.fieldId + '_plaintext')[0];
        const input       = $el.find('#' + config.fieldId + '_plaintext_input')[0];
        const revoked     = $el.find('#' + config.fieldId + '_revoked')[0];
        const error       = $el.find('#' + config.fieldId + '_error')[0];
        const label       = generateBtn.querySelector('span');

        function showError(message) {
            error.textContent = message;
            error.hidden = false;
        }

        function clearError() {
            error.textContent = '';
            error.hidden = true;
        }

        function post(url, onSuccess, onFailure) {
            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: {
                    scope:    config.scopeName,
                    scopeId:  config.scopeId,
                    form_key: window.FORM_KEY
                }
            }).done(onSuccess).fail(function (jqXHR) {
                onFailure(jqXHR.responseJSON || null);
            });
        }

        $(generateBtn).on('click', function () {
            clearError();
            post(config.ajaxUrl, function (response) {
                if (response && response.success && response.token) {
                    revoked.hidden    = true;
                    current.hidden    = true;
                    input.value       = response.token;
                    plaintext.hidden  = false;
                    label.textContent = config.regenerateLabel;
                    revokeBtn.hidden  = false;
                    input.focus();
                    input.select();
                } else {
                    showError((response && response.message) ? response.message : config.unexpectedLabel);
                }
            }, function (response) {
                showError((response && response.message) ? response.message : config.failureLabel);
            });
        });

        $(revokeBtn).on('click', function () {
            clearError();
            if (!window.confirm(config.revokeConfirm)) {
                return;
            }
            post(config.revokeUrl, function (response) {
                if (response && response.success) {
                    current.hidden    = true;
                    plaintext.hidden  = true;
                    revoked.hidden    = false;
                    revokeBtn.hidden  = true;
                    label.textContent = config.generateLabel;
                } else {
                    showError((response && response.message) ? response.message : config.unexpectedLabel);
                }
            }, function (response) {
                showError((response && response.message) ? response.message : config.revokeFailLabel);
            });
        });
    };
});
