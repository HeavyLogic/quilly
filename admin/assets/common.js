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

        // 1. Системная ошибка (PHP упал, 500/403 или вернул не JSON)
        if (!response.ok || !res) {
            if (failFunc) failFunc(res || rawText);

            const errorText = (res && res.message) 
                ? res.message 
                : (rawText ? rawText.trim() : 'Unknown error');

            modal((response.status || 0) + ': ' + errorText);
            return false;
        }

        // 2. Логика PHP
        if (res.success !== true) {
            if (failFunc) failFunc(res);
            modal(res.message || 'Ошибка выполнения');
            return false;
        }

        if (successFunc) successFunc(res);
        return true;

    } catch (err) {
        if (failFunc) failFunc(err);
        modal('0: ' + (err.message || 'Network error'));
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

export function modal(htmlContent) {
    return new Promise((resolve) => {
        // 1. Фон (оверлей)
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(3px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 999999;
            font-family: system-ui, -apple-system, sans-serif;
            box-sizing: border-box;
            padding: 20px;
        `;

        // 2. Модальное окно (ограничиваем высоту до 85% экрана)
        const modalBox = document.createElement('div');
        modalBox.style.cssText = `
            background: #ffffff;
            padding: 20px 24px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            max-width: 480px;
            width: 100%;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            color: #222222;
            font-size: 16px;
            line-height: 1.5;
            box-sizing: border-box;
        `;

        // 3. Контент (вертикальный скролл при длинном тексте)
        const content = document.createElement('div');
        content.style.cssText = `
            margin-bottom: 20px;
            word-break: break-word;
            overflow-y: auto;
            padding-right: 4px;
            flex: 1;
        `;
        content.innerHTML = htmlContent;

        // 4. Кнопка ОК (зафиксирована внизу, не сжимается при скролле)
        const btnActions = document.createElement('div');
        btnActions.style.cssText = `
            display: flex;
            justify-content: flex-end;
            flex-shrink: 0;
        `;

        const okBtn = document.createElement('button');
        okBtn.textContent = 'OK';
        okBtn.style.cssText = `
            background: #0066cc;
            color: #ffffff;
            border: none;
            padding: 8px 22px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            outline: none;
        `;

        btnActions.appendChild(okBtn);
        modalBox.appendChild(content);
        modalBox.appendChild(btnActions);
        overlay.appendChild(modalBox);

        // Функция закрытия и ПОЛНОГО уничтожения из DOM
        const destroy = () => {
            document.removeEventListener('keydown', handleKeyDown);
            overlay.remove();
            resolve();
        };

        const handleKeyDown = (e) => {
            if (e.key === 'Escape' || e.key === 'Enter') {
                destroy();
            }
        };

        // События закрытия (клик на ОК, клик на фон, клавиши Enter/Esc)
        okBtn.addEventListener('click', destroy);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) destroy();
        });
        document.addEventListener('keydown', handleKeyDown);

        // Вставляем в документ
        document.body.appendChild(overlay);
        okBtn.focus();
    });
}