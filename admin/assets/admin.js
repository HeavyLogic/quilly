import { sendAjax } from './common.js';
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

	// 6. Редактирование ячеек
	document.addEventListener('dblclick', (e) => {
		const cell = e.target.closest('.editable');
		if (!cell || cell.querySelector('input, select')) return;

		const field = cell.dataset.field;
		const userRow = cell.closest('.user-row');
		if (!userRow) return;

		const id = userRow.dataset.id;
		const currentVal = (field === 'password') ? '' : cell.textContent.trim();

		if (field === 'role') {
			const select = document.createElement('select');
			select.innerHTML = '<option value="editor">editor</option><option value="admin">admin</option>';
			select.value = currentVal || 'editor';

			cell.innerHTML = '';
			cell.appendChild(select);
			select.focus();

			let isSaved = false;
			const saveRole = () => {
				if (isSaved) return;
				isSaved = true;

				sendAjax({
					module: 'superuser',
					method: 'update_field',
					id: id,
					field: field,
					value: select.value
				}, (result) => {
					updateUsersList(result.html);
				});
			};

			select.addEventListener('change', saveRole);
			select.addEventListener('blur', saveRole);

		} else {
			const input = document.createElement('input');
			input.type = 'text';
			input.value = currentVal;

			cell.innerHTML = '';
			cell.appendChild(input);
			input.focus();

			let isSaved = false;
			const save = () => {
				if (isSaved) return;
				isSaved = true;
				const newVal = input.value.trim();

				if (field === 'password' && newVal === '') {
					cell.textContent = '**********';
					return;
				}

				sendAjax({
					module: 'superuser',
					method: 'update_field',
					id: id,
					field: field,
					value: newVal
				}, (result) => {
					updateUsersList(result.html);
				});
			};

			input.addEventListener('blur', save);
			input.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					input.removeEventListener('blur', save);
					save();
				}
			});
		}
	});
});