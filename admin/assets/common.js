let ajaxing = false;

export async function sendAjax(url, data, successFunc, failFunc) {
    if (!data || !data.action || ajaxing) return;
    ajaxing = true;

    const params = new URLSearchParams(data);

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: params
        });

        const rawText = await response.text();
        let res = null;

        try {
            res = JSON.parse(rawText);
        } catch (e) {
            res = null;
        }

        // 1. Системная ошибка (PHP упал, 500/403 или вернул не JSON)
        if (!response.ok || !res) {
            failFunc(res || rawText);

            const errorText = (res && res.message) 
                ? res.message 
                : (rawText ? rawText.trim() : 'Unknown error');

            alert((response.status || 0) + ': ' + errorText);
            return;
        }

        // 2. Логика PHP (success: true / false)
        if (res.success !== true) {
            failFunc(res);
            return;
        }

        successFunc(res);

    } catch (err) {
        failFunc(err);

        alert('0: ' + (err.message || 'Network error'));
    } finally {
        ajaxing = false;
    }
}