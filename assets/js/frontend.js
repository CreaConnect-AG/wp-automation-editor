document.addEventListener('DOMContentLoaded', function () {
    const categorySelectField = document.querySelector('.wpa-categories-select');
    const tagSelectField = document.querySelector('.wpa-tags-select');
    const editForm = document.querySelector('.wpa-edit-form');

    if (categorySelectField && typeof TomSelect !== 'undefined') {
        new TomSelect(categorySelectField, {
            plugins: {
                remove_button: {
                    title: 'Entfernen'
                }
            },
            hidePlaceholder: true,
            closeAfterSelect: false,
            placeholder: (window.wpaAutomationEditor && window.wpaAutomationEditor.categoryPlaceholder) || 'Kategorien suchen oder auswählen'
        });
    }

    if (tagSelectField && typeof TomSelect !== 'undefined') {
        new TomSelect(tagSelectField, {
            plugins: {
                remove_button: {
                    title: 'Entfernen'
                }
            },
            create: true,
            createOnBlur: true,
            persist: false,
            hidePlaceholder: true,
            closeAfterSelect: false,
            placeholder: (window.wpaAutomationEditor && window.wpaAutomationEditor.tagPlaceholder) || 'Schlagwörter suchen oder neu eingeben'
        });
    }

    if (!editForm || !window.wpaAutomationEditor || !window.wpaAutomationEditor.currentEditPostId) {
        return;
    }

    let lockRefreshTimer = null;

    function showLockMessage(messageText) {
        let messageElement = document.querySelector('.wpa-lock-message');

        if (!messageElement) {
            messageElement = document.createElement('div');
            messageElement.className = 'wpa-notice wpa-notice-error wpa-lock-message';
            editForm.parentNode.insertBefore(messageElement, editForm);
        }

        messageElement.textContent = messageText;
    }

    function disableEditForm() {
        editForm.classList.add('is-locked');

        const formFields = editForm.querySelectorAll('input, select, textarea, button');
        formFields.forEach(function (fieldElement) {
            fieldElement.disabled = true;
        });
    }

    function refreshPostLock() {
        const requestBody = new URLSearchParams();
        requestBody.append('action', 'wpa_refresh_post_lock');
        requestBody.append('nonce', window.wpaAutomationEditor.lockRefreshNonce);
        requestBody.append('post_id', String(window.wpaAutomationEditor.currentEditPostId));

        fetch(window.wpaAutomationEditor.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: requestBody.toString()
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (responseData) {
                if (!responseData || responseData.success !== true) {
                    const messageText =
                        responseData &&
                        responseData.data &&
                        responseData.data.message
                            ? responseData.data.message
                            : window.wpaAutomationEditor.lockLostMessage;

                    if (lockRefreshTimer) {
                        clearInterval(lockRefreshTimer);
                    }

                    showLockMessage(messageText);
                    disableEditForm();
                }
            })
            .catch(function () {
                if (lockRefreshTimer) {
                    clearInterval(lockRefreshTimer);
                }

                showLockMessage(window.wpaAutomationEditor.lockLostMessage);
                disableEditForm();
            });
    }

    refreshPostLock();

    lockRefreshTimer = window.setInterval(function () {
        refreshPostLock();
    }, parseInt(window.wpaAutomationEditor.lockRefreshInterval, 10) || 60000);

    window.addEventListener('pagehide', function () {
        if (lockRefreshTimer) {
            clearInterval(lockRefreshTimer);
        }
    });
});