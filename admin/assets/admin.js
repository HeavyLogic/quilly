import { sendAjax, on } from './common.js';
// TODO: Заюзать больше из common.js

document.addEventListener('DOMContentLoaded', () => {

	const errorAlert = document.getElementById('errorAlert');
	const modalErrorAlert = document.getElementById('modalErrorAlert');

	function updateUsersList(html) {
		const usersList = document.getElementById('usersList');
		if (!usersList) {
			return;
		}

		usersList.innerHTML = html || '';
	}

	const showError = (element, message) => {
		if (!element) return;
		element.textContent = message;
		element.classList.add('visible');
		element.style.display = 'block';
	};

	const hideError = (element) => {
		if (!element) return;
		element.classList.remove('visible');
		element.style.display = 'none';
	};

	// 1. Первичная настройка
	const setupForm = document.getElementById('setupForm');
	if (setupForm) {
		setupForm.addEventListener('submit', (e) => {
			e.preventDefault();
			hideError(errorAlert);

			const pass = setupForm.querySelector('[name="password"]').value;
			const passConfirm = setupForm.querySelector('[name="password_confirm"]').value;

			if (pass !== passConfirm) {
				showError(errorAlert, 'Пароли не совпадают');
				return;
			}

			sendAjax({
				module: 'superuser',
				method: 'setup',
				login: setupForm.querySelector('[name="login"]').value,
				password: pass,
				password_confirm: passConfirm
			}, () => {
				location.reload();
			});
		});
	}

	// 2. Авторизация
	const loginForm = document.getElementById('loginForm');
	if (loginForm) {
		loginForm.addEventListener('submit', (e) => {
			e.preventDefault();
			hideError(errorAlert);

			sendAjax({
				module: 'auth',
				method: 'login',
				login: loginForm.querySelector('[name="login"]').value,
				password: loginForm.querySelector('[name="password"]').value
			}, (res) => {
				if (res && res.redirect) {
					window.location.href = res.redirect;
				} else {
					window.location.reload;
				}
			});
		});
	}

	// 3. Выход
	const btnLogout = document.getElementById('btnLogout');
	if (btnLogout) {
		btnLogout.addEventListener('click', () => {
			sendAjax(
				{
					module: 'auth',
					method: 'logout',
				},
				() => location.reload()
			);
		});
	}

	// 4. Модальное окно
	const addModal = document.getElementById('addModal');
	const addUserForm = document.getElementById('addUserForm');
	const btnOpenAddModal = document.getElementById('btnOpenAddModal');
	const btnCloseModal = document.getElementById('btnCloseModal');

	const closeModal = () => {
		if (addModal) addModal.style.display = 'none';
		if (addUserForm) addUserForm.reset();
		hideError(modalErrorAlert);
	};

	if (btnOpenAddModal) {
		btnOpenAddModal.addEventListener('click', () => {
			hideError(modalErrorAlert);
			if (addModal) addModal.style.display = 'flex';
		});
	}

	if (btnCloseModal) btnCloseModal.addEventListener('click', closeModal);
	if (addModal) {
		addModal.addEventListener('click', (e) => {
			if (e.target === addModal) closeModal();
		});
	}

	if (addUserForm) {
		addUserForm.addEventListener('submit', (e) => {
			e.preventDefault();
			hideError(modalErrorAlert);

			sendAjax({
				module: 'superuser',
				method: 'add_user',
				user: addUserForm.querySelector('[name="user"]').value,
				password: addUserForm.querySelector('[name="password"]').value,
				role: addUserForm.querySelector('[name="role"]').value
			}, (result) => {
				closeModal();
				updateUsersList(result.html);
			});
		});
	}

	// 5. Удаление пользователя
	document.addEventListener('click', (e) => {
		const deleteBtn = e.target.closest('.btn-delete');
		if (deleteBtn) {
			const userRow = deleteBtn.closest('.user-row');
			if (userRow && confirm('Удалить пользователя?')) {
				sendAjax({
					module: 'superuser',
					method: 'delete_user',
					id: userRow.dataset.id
				}, (result) => {
					updateUsersList(result.html);
				});
			}
		}
	});

	// Helper для отправки сохраненного значения
	function saveCell(fieldElement) {
		const cell = fieldElement.closest('.editable');
		const userRow = fieldElement.closest('.user-row');
		
		if (!cell || !userRow) return;
	
		// Защита от повторной отправки (например, если Enter вызвал blur)
		if (fieldElement.dataset.saving) return;
		fieldElement.dataset.saving = 'true';
	
		sendAjax({
			module: 'superuser',
			method: 'update_field',
			id: userRow.dataset.id,
			field: cell.dataset.field,
			value: fieldElement.value.trim()
		}, (result) => {
			updateUsersList(result.html);
		});
	}
	
	// 1. Двойной клик — запрашиваем HTML инпута/селекта с сервера
	on('dblclick', '.editable', function() {
		// Если уже редактируется — игнорируем
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
			
			// Автофокус на вставленный элемент
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