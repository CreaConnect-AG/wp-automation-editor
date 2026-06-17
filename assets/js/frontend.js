document.addEventListener('DOMContentLoaded', function () {
    const categorySelectField = document.querySelector('.wpa-categories-select');
    const tagSelectField = document.querySelector('.wpa-tags-select');
    const editForm = document.querySelector('.wpa-edit-form');

    function initRemotePublishSlots() {
        const remotePublishDateField = document.querySelector('#wpa_remote_publish_date');
        const remotePublishTimeField = document.querySelector('#wpa_remote_publish_time');

        if (!editForm || !remotePublishDateField || !remotePublishTimeField || !window.wpaAutomationEditor) {
            return;
        }

        let currentRequestId = 0;

        function getTimeOptions() {
            return Array.from(remotePublishTimeField.querySelectorAll('option')).filter(function (optionElement) {
                return optionElement.value !== '';
            });
        }

        function resetTimeOptions() {
            getTimeOptions().forEach(function (optionElement) {
                optionElement.disabled = false;
                optionElement.hidden = false;
                optionElement.classList.remove('wpa-remote-publish-time-option-occupied');
                optionElement.removeAttribute('aria-disabled');
                optionElement.removeAttribute('data-occupied-title');

                if (optionElement.dataset.originalLabel) {
                    optionElement.textContent = optionElement.dataset.originalLabel;
                }
            });
        }

        function removeSlotMessage() {
            const messageElement = document.querySelector('.wpa-remote-publish-slot-message');

            if (messageElement) {
                messageElement.remove();
            }
        }

        function buildOccupiedSlotsByTime(occupiedSlots, occupiedTimes) {
            const occupiedSlotsByTime = {};

            if (Array.isArray(occupiedSlots)) {
                occupiedSlots.forEach(function (occupiedSlot) {
                    if (!occupiedSlot || !occupiedSlot.time) {
                        return;
                    }

                    occupiedSlotsByTime[occupiedSlot.time] = {
                        time: occupiedSlot.time,
                        title: occupiedSlot.title || ''
                    };
                });
            }

            if (Array.isArray(occupiedTimes)) {
                occupiedTimes.forEach(function (occupiedTime) {
                    if (!occupiedSlotsByTime[occupiedTime]) {
                        occupiedSlotsByTime[occupiedTime] = {
                            time: occupiedTime,
                            title: ''
                        };
                    }
                });
            }

            return occupiedSlotsByTime;
        }

        function applyOccupiedSlots(occupiedSlots, occupiedTimes) {
            const occupiedSlotsByTime = buildOccupiedSlotsByTime(occupiedSlots, occupiedTimes);
            let availableTimesCount = 0;

            getTimeOptions().forEach(function (optionElement) {
                if (!optionElement.dataset.originalLabel) {
                    optionElement.dataset.originalLabel = optionElement.textContent
                        .replace(' – ' + window.wpaAutomationEditor.remotePublishSlotTakenText, '')
                        .replace(/ – Belegt: .+$/, '');
                }

                const occupiedSlot = occupiedSlotsByTime[optionElement.value] || null;
                const isOccupied = occupiedSlot !== null;
                const occupiedPostTitle = isOccupied && occupiedSlot.title ? occupiedSlot.title : '';

                optionElement.disabled = isOccupied;
                optionElement.hidden = false;
                optionElement.classList.toggle('wpa-remote-publish-time-option-occupied', isOccupied);

                if (isOccupied) {
                    optionElement.setAttribute('aria-disabled', 'true');
                    optionElement.dataset.occupiedTitle = occupiedPostTitle;

                    if (occupiedPostTitle) {
                        optionElement.textContent = optionElement.dataset.originalLabel + ' – Belegt: ' + occupiedPostTitle;
                    } else {
                        optionElement.textContent = optionElement.dataset.originalLabel + ' – ' + window.wpaAutomationEditor.remotePublishSlotTakenText;
                    }
                } else {
                    optionElement.removeAttribute('aria-disabled');
                    optionElement.removeAttribute('data-occupied-title');
                    optionElement.textContent = optionElement.dataset.originalLabel;
                    availableTimesCount++;
                }
            });

            if (remotePublishTimeField.selectedOptions.length && remotePublishTimeField.selectedOptions[0].disabled) {
                remotePublishTimeField.value = '';
            }

            removeSlotMessage();

            if (availableTimesCount === 0 && remotePublishDateField.value !== '') {
                const messageElement = document.createElement('p');
                messageElement.className = 'wpa-help-text wpa-remote-publish-slot-message';
                messageElement.textContent = window.wpaAutomationEditor.remotePublishAllSlotsTakenText;
                remotePublishTimeField.parentNode.appendChild(messageElement);
            }
        }

        function fetchOccupiedTimes() {
            const remotePublishDate = remotePublishDateField.value;

            resetTimeOptions();
            removeSlotMessage();

            if (!remotePublishDate) {
                return;
            }

            currentRequestId++;

            const requestId = currentRequestId;
            const requestBody = new URLSearchParams();

            requestBody.append('action', 'wpa_get_remote_publish_occupied_times');
            requestBody.append('nonce', window.wpaAutomationEditor.remotePublishSlotsNonce);
            requestBody.append('post_id', String(window.wpaAutomationEditor.currentEditPostId || 0));
            requestBody.append('remote_publish_date', remotePublishDate);

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
                    if (requestId !== currentRequestId) {
                        return;
                    }

                    if (!responseData || responseData.success !== true || !responseData.data) {
                        return;
                    }

                    applyOccupiedSlots(responseData.data.occupiedSlots || [], responseData.data.occupiedTimes || []);
                })
                .catch(function () {
                    resetTimeOptions();
                    removeSlotMessage();
                });
        }

        remotePublishDateField.addEventListener('change', fetchOccupiedTimes);

        fetchOccupiedTimes();
    }

    initRemotePublishSlots();

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