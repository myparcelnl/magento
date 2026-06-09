define([], function () {
    'use strict';

    /**
     * Bound via data-mage-init on the api_access_token_button.phtml root div.
     * config keys: fieldId, ajaxUrl, revokeUrl, scopeName, scopeId,
     *              generateLabel, regenerateLabel, unexpectedLabel,
     *              failureLabel, revokeFailLabel, revokeConfirm.
     */
    return function (config, element) {
        const fieldId     = config.fieldId;
        const generateBtn = document.getElementById(`${fieldId}_generate`);
        const revokeBtn   = document.getElementById(`${fieldId}_revoke`);
        const current     = document.getElementById(`${fieldId}_current`);
        const plaintext   = document.getElementById(`${fieldId}_plaintext`);
        const input       = document.getElementById(`${fieldId}_plaintext_input`);
        const revoked     = document.getElementById(`${fieldId}_revoked`);
        const error       = document.getElementById(`${fieldId}_error`);
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
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    scope:    config.scopeName,
                    scopeId:  config.scopeId,
                    form_key: window.FORM_KEY
                }).toString()
            }).then(function (response) {
                return response.json().then(function (json) {
                    return response.ok ? onSuccess(json) : onFailure(json);
                });
            }).catch(function () {
                onFailure(null);
            });
        }

        generateBtn.addEventListener('click', function () {
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

        revokeBtn.addEventListener('click', function () {
            clearError();
            if (!window.confirm(config.revokeConfirm)) {
                return;
            }
            post(config.revokeUrl, function (response) {
                if (response && response.success) {
                    current.hidden    = true;
                    plaintext.hidden  = true;
                    input.value       = '';
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
