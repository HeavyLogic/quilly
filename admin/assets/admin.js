import { sendAjax, on, modal, close_modal } from './common.js';

document.addEventListener('DOMContentLoaded', () => {
	// 1. Первичная настройка
	on('submit', '#setupForm', function(e) {
		e.preventDefault();

		sendAjax({
			module: 'superuser',
			method: 'setup',
			login: this.querySelector('[name="login"]').value,
			password: this.querySelector('[name="password"]').value,
			password_confirm: this.querySelector('[name="password_confirm"]').value
		}, () => {
			location.reload();
		});
	});

	// 2. Авторизация
	on('submit', '#loginForm', function(e) {
		e.preventDefault();

		sendAjax({
			module: 'auth',
			method: 'login',
			login: this.querySelector('[name="login"]').value,
			password: this.querySelector('[name="password"]').value
		}, (res) => {
			if (res && res.redirect) {
				window.location.href = res.redirect;
			} else {
				window.location.reload();
			}
		});
	});

	// 3. Выход
	on('click', '#btnLogout', () => {
		sendAjax(
			{ module: 'auth', method: 'logout' },
			() => location.reload()
		);
	});

	// Модалка нового пользователя
	on('click', '#btnOpenAddModal', function(e) {
		e.preventDefault();

		sendAjax({
			module: 'superuser',
			method: 'new_user_modal'
		}, (result) => {
			modal(result['html'], result['title']);
		});
	});

	on('click', '#btnCloseModal', function(e) {
		close_modal();
	});

	// Обработчик модалки нового пользователя
	on('submit', '#addUserForm', function(e) {
		e.preventDefault();

		sendAjax({
			module: 'superuser',
			method: 'add_user',
			user: this.querySelector('[name="user"]').value,
			password: this.querySelector('[name="password"]').value,
			role: this.querySelector('[name="role"]').value
		}, (result) => {
			close_modal();
			document.getElementById('usersList').innerHTML = result.html;
		}, (result) => {
			// красивая ошибка
			const errorContainer = document.getElementById('modalErrorAlert');
			if (errorContainer) {
				errorContainer.innerHTML = result['message'];
				errorContainer.classList.add('visible');
			} else {
				modal(result['message']);
			}
		});
	});

	// 5. Удаление пользователя
	on('click', '.btn-delete', function() {
		const userRow = this.closest('.user-row');
		if (userRow && confirm('Удалить пользователя?')) {
			sendAjax({
				module: 'superuser',
				method: 'delete_user',
				id: userRow.dataset.id
			}, (result) => {
				document.getElementById('usersList').innerHTML = result.html;
			});
		}
	});

	// Helper для отправки сохраненного значения
	function saveCell(fieldElement) {
		const cell = fieldElement.closest('.editable');
		const userRow = fieldElement.closest('.user-row');

		if (!cell || !userRow) return;

		if (fieldElement.dataset.saving) return;
		fieldElement.dataset.saving = 'true';

		sendAjax({
			module: 'superuser',
			method: 'update_field',
			id: userRow.dataset.id,
			field: cell.dataset.field,
			value: fieldElement.value.trim()
		}, (result) => {
			document.getElementById('usersList').innerHTML = result.html;
		});
	}

	// 1. Двойной клик — запрашиваем HTML инпута/селекта с сервера
	on('dblclick', '.editable', function() {
		if (this.querySelector('input, select')) return;

		const userRow = this.closest('.user-row');
		if (!userRow) return;

		sendAjax({
			module: 'superuser',
			method: 'get_edit_field',
			id: userRow.dataset.id,
			field: this.dataset.field,
			value: this.dataset.value || ''
		}, (result) => {
			this.innerHTML = result.html;

			const input = this.querySelector('input, select');
			if (input) input.focus();
		});
	});

	// 2. Сохранение при потере фокуса (для input и select)
	on('focusout', '.editable input, .editable select', function() {
		saveCell(this);
	});

	// 3. Сохранение при изменении значения (для select)
	on('change', '.editable select', function() {
		saveCell(this);
	});

	// 4. Сохранение по нажатию Enter (для input)
	on('keydown', '.editable input', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			saveCell(this);
		}
	});
});