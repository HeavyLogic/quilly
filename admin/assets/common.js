let ajaxing = false;

export async function sendAjax(data, successFunc, failFunc) {
    if (!data || ajaxing) return false;
    ajaxing = true;

    const isFormData = data instanceof FormData;
    const body = isFormData ? data : new URLSearchParams(data);

    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    if (!isFormData) {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }

    try {
        const response = await fetch('/admin/ajax.php', {
            method: 'POST',
            headers: headers,
            body: body
        });

        const rawText = await response.text();
        let res = null;

        try {
            res = JSON.parse(rawText);
        } catch (e) {
            res = null;
        }

        // 1. Системная ошибка
        if (!response.ok || !res) {
            if (failFunc) failFunc(res || rawText);

            const errorText = (res && res.message) 
                ? res.message 
                : (rawText ? rawText.trim() : 'Unknown error');

            alert((response.status || 0) + ': ' + errorText);
            return false;
        }

        // 2. Логика PHP
        if (res.success !== true) {
            if (failFunc) failFunc(res);
            return false;
        }

        if (successFunc) successFunc(res);
        return true;

    } catch (err) {
        if (failFunc) failFunc(err);
        alert('0: ' + (err.message || 'Network error'));
        return false;
    } finally {
        ajaxing = false;
    }
}

export function on(event, selector, handler) {
    document.addEventListener(event, (e) => {
        const target = e.target.closest(selector);
        if (target) {
            handler.call(target, e); // handler.call передает target в переменнную 'this'
        }
    });
}