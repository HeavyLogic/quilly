// /admin/assets/common.js

let ajaxing = false;

export async function sendAjax(url, data, successFunc, errorFunc) {
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

        if (!response.ok) {
            throw new Error(`Ошибка сервера: ${response.status}`);
        }

        const res = await response.json();

        if (res && res.success) {
            if (typeof successFunc === 'function') successFunc(res);
        } else {
            const msg = (res && res.message) ? res.message : 'Ошибка выполнения';
            if (typeof errorFunc === 'function') {
                errorFunc(msg);
            } else {
                alert(msg);
            }
        }
    } catch (err) {
        const msg = err.message || 'Ошибка соединения';
        if (typeof errorFunc === 'function') {
            errorFunc(msg);
        } else {
            alert(msg);
        }
    } finally {
        ajaxing = false;
    }
}