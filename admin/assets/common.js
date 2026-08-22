// common.js
export async function sendAjax(data, successFunc, failFunc, force = false) {
	if (!data) {
		return false;
	}

	const ajaxing = document.body.classList.contains('cms-ajax-loading');
	if (!force && ajaxing) {
		return false;
	}
	document.body.classList.add('cms-ajax-loading');

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

		// 2. Бизнес-логика PHP
		if (res.success !== true) {
			if (failFunc) {
				failFunc(res);
			} else {
				// Выбрасываем модалку только если не задана "красивая механика" ошибки
				modal(res.message || 'Run error');
			}
			return false;
		}

		if (successFunc) successFunc(res);
		return true;

	} catch (err) {
		if (failFunc) failFunc(err);
		modal('0: ' + (err.message || 'Network error'));
		return false;
	} finally {
		document.body.classList.remove('cms-ajax-loading');
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

export function modal(content, title = 'Ошибка') {
	// 1. Фон (оверлей)
	const overlay = document.createElement('div');
	overlay.className = 'cms-modal-overlay';
	overlay.style.cssText = `
		position: fixed;
		top: 0;
		left: 0;
		width: 100vw;
		height: 100vh;
		background: rgba(0, 0, 0, 0.8);
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
		overflow: hidden;
	`;

	// 3. Заголовок: текст + кнопка закрытия
	const header = document.createElement('div');
	header.style.cssText = `
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		padding: 16px 16px 16px 24px;
		border-bottom: 1px solid #eeeeee;
		flex-shrink: 0;
	`;

	const titleEl = document.createElement('div');
	titleEl.textContent = title;
	titleEl.style.cssText = `
		font-size: 17px;
		font-weight: 600;
	`;

	const closeBtn = document.createElement('button');
	closeBtn.textContent = '×';
	closeBtn.setAttribute('aria-label', 'Закрыть');
	closeBtn.setAttribute('id', 'closeModal');
	closeBtn.style.cssText = `
		background: transparent;
		border: none;
		cursor: pointer;
		font-size: 28px;
		line-height: 1;
		color: #888888;
		padding: 0 4px;
		outline: none;
	`;

	header.appendChild(titleEl);
	header.appendChild(closeBtn);

	// 4. Контент (вертикальный скролл при длинном содержимом)
	const body = document.createElement('div');
	body.style.cssText = `
		padding: 20px 24px;
		overflow-y: auto;
		word-break: break-word;
	`;
	body.innerHTML = content;

	modalBox.appendChild(header);
	modalBox.appendChild(body);
	overlay.appendChild(modalBox);

	// Функция закрытия и полного уничтожения из DOM
	const destroy = () => {
		document.removeEventListener('keydown', handleKeyDown);
		overlay.remove();
	};

	const handleKeyDown = (e) => {
		if (e.key === 'Escape') {
			destroy();
		}
	};

	// События закрытия (клик на ×, клик на фон, клавиша Esc)
	closeBtn.addEventListener('click', destroy);
	overlay.addEventListener('click', (e) => {
		if (e.target === overlay) destroy();
	});
	document.addEventListener('keydown', handleKeyDown);

	document.body.appendChild(overlay);
	closeBtn.focus();

	// Возвращаем функцию закрытия, чтобы можно было закрыть модалку
	// программно — например, из колбэка успешного ajax-запроса формы внутри неё
	return destroy;
}

export function close_modal() {
	document.getElementById('closeModal').click();
}