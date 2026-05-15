document.addEventListener('DOMContentLoaded', function () {
    const importWrapper = document.querySelector('[data-wpa-image-import]');

    if (!importWrapper || typeof window.wpaAutomationImageImport === 'undefined') {
        return;
    }

    const checkAllField = importWrapper.querySelector('[data-wpa-image-import-check-all]');
    const selectedImportButton = importWrapper.querySelector('[data-wpa-image-import-selected]');
    const progressField = importWrapper.querySelector('[data-wpa-image-import-progress]');
    const delayMs = Number(window.wpaAutomationImageImport.delayMs || 0);

    function setProgress(message) {
        if (progressField) {
            progressField.textContent = message || '';
        }
    }

    function wait(milliseconds) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, milliseconds);
        });
    }

    function getRow(postId) {
        return importWrapper.querySelector('[data-wpa-image-import-row="' + postId + '"]');
    }

    function setRowStatus(row, message, isError, remoteUrl) {
        const statusField = row ? row.querySelector('[data-wpa-image-import-status]') : null;

        if (!statusField) {
            return;
        }

        statusField.classList.toggle('wpa-import-error', !!isError);
        statusField.classList.toggle('wpa-import-success', !isError);

        if (remoteUrl) {
            statusField.innerHTML = '<a href="' + remoteUrl + '" target="_blank" rel="noopener">' + remoteUrl + '</a><div class="wpa-post-meta-line">' + message + '</div>';
            return;
        }

        statusField.textContent = message;
    }

    function removeImportedRow(row) {
        if (!row) {
            return;
        }

        row.remove();

        if (!importWrapper.querySelector('[data-wpa-image-import-row]')) {
            setProgress('Alle sichtbaren Beitragsbilder wurden importiert.');
        }
    }

    function setRowLoading(row, isLoading) {
        if (!row) {
            return;
        }

        const button = row.querySelector('[data-wpa-image-import-one]');
        const checkbox = row.querySelector('[data-wpa-image-import-checkbox]');

        if (button) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent;
            }

            button.disabled = isLoading;
            button.textContent = isLoading ? window.wpaAutomationImageImport.importingMessage : button.dataset.originalText;
        }

        if (checkbox) {
            checkbox.disabled = isLoading;
        }
    }

    async function readJsonResponse(response) {
        const responseText = await response.text();

        if (!responseText) {
            return null;
        }

        try {
            return JSON.parse(responseText);
        } catch (error) {
            return null;
        }
    }

    async function importImage(postId) {
        const row = getRow(postId);
        setRowLoading(row, true);
        setRowStatus(row, window.wpaAutomationImageImport.importingMessage, false, '');

        const requestData = new FormData();
        requestData.append('action', 'wpa_import_featured_image_to_remote');
        requestData.append('nonce', window.wpaAutomationImageImport.nonce);
        requestData.append('post_id', postId);

        try {
            const response = await fetch(window.wpaAutomationImageImport.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: requestData
            });

            const responseData = await readJsonResponse(response);

            if (!response.ok || !responseData || !responseData.success) {
                const message = responseData && responseData.data && responseData.data.message ? responseData.data.message : window.wpaAutomationImageImport.errorMessage + ' HTTP-Code: ' + response.status;
                const stopQueue = !!(responseData && responseData.data && responseData.data.stop_queue);
                setRowStatus(row, message, true, '');

                return {
                    success: false,
                    stopQueue: stopQueue,
                    message: message
                };
            }

            setRowStatus(row, responseData.data.message, false, responseData.data.remote_url);

            return {
                success: true,
                stopQueue: false,
                message: responseData.data.message
            };
        } catch (error) {
            const message = error.message || window.wpaAutomationImageImport.errorMessage;
            setRowStatus(row, message, true, '');

            return {
                success: false,
                stopQueue: true,
                message: message
            };
        } finally {
            setRowLoading(row, false);
        }
    }

    importWrapper.addEventListener('click', async function (event) {
        const singleButton = event.target.closest('[data-wpa-image-import-one]');

        if (!singleButton) {
            return;
        }

        event.preventDefault();
        const postId = singleButton.getAttribute('data-wpa-image-import-one');
        const row = getRow(postId);
        const importResult = await importImage(postId);

        if (importResult.success) {
            removeImportedRow(row);
        }
    });

    if (checkAllField) {
        checkAllField.addEventListener('change', function () {
            importWrapper.querySelectorAll('[data-wpa-image-import-checkbox]:not(:disabled)').forEach(function (checkbox) {
                checkbox.checked = checkAllField.checked;
            });
        });
    }

    if (selectedImportButton) {
        selectedImportButton.addEventListener('click', async function () {
            const selectedCheckboxes = Array.from(importWrapper.querySelectorAll('[data-wpa-image-import-checkbox]:checked:not(:disabled)'));

            if (!selectedCheckboxes.length) {
                setProgress('Bitte zuerst mindestens ein Beitragsbild auswählen.');
                return;
            }

            if (!window.confirm(window.wpaAutomationImageImport.confirmMessage)) {
                return;
            }

            selectedImportButton.disabled = true;

            let successfulImports = 0;
            let stoppedEarly = false;

            for (let index = 0; index < selectedCheckboxes.length; index++) {
                const postId = selectedCheckboxes[index].value;
                setProgress((index + 1) + ' / ' + selectedCheckboxes.length + ' wird importiert...');

                const importResult = await importImage(postId);
                if (importResult.success) {
                    successfulImports++;
                    selectedCheckboxes[index].checked = false;
                    removeImportedRow(getRow(postId));
                }

                if (importResult.stopQueue) {
                    stoppedEarly = true;
                    setProgress(window.wpaAutomationImageImport.stoppedMessage + ' Erfolgreich: ' + successfulImports + ' / ' + selectedCheckboxes.length + '.');
                    break;
                }

                if (delayMs > 0 && index < selectedCheckboxes.length - 1) {
                    setProgress('Kurze Pause zum Schutz der Zielseite...');
                    await wait(delayMs);
                }
            }

            selectedImportButton.disabled = false;

            if (!stoppedEarly) {
                setProgress(window.wpaAutomationImageImport.finishedMessage + ' Erfolgreich: ' + successfulImports + ' / ' + selectedCheckboxes.length + '.');
            }
        });
    }
});
