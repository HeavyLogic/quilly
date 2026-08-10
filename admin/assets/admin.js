import { sendAjax } from './common.js';

document.addEventListener('DOMContentLoaded', () => {

    // Вспомогательная функция вывода ошибок
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
            const pass = setupForm.querySelector('[name="password"]').value;
            const passConfirm = setupForm.querySelector('[name="password_confirm"]').value;

            if (pass !== passConfirm) {
                showError(document.getElementById('errorAlert'), 'Пароли не совпадают');
                return;
            }

            sendAjax('index.php', {
                action: 'setup',
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
            sendAjax('index.php', {
                action: 'login',
                login: loginForm.querySelector('[name="login"]').value,
                password: loginForm.querySelector('[name="password"]').value
            }, (res) => {
                window.location.href = res.redirect || '/admin/';
            });
        });
    }

    // 3. Выход
    const btnLogout = document.getElementById('btnLogout');
    if (btnLogout) {
        btnLogout.addEventListener('click', () => {
            sendAjax('index.php', { action: 'logout' }, () => {
                location.reload();
            });
        });
    }

    // 4. Управление модальным окном
    const addModal = document.getElementById('addModal');
    const modalErrorAlert = document.getElementById('modalErrorAlert');
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

    if (btnCloseModal) {
        btnCloseModal.addEventListener('click', closeModal);
    }

    if (addModal) {
        addModal.addEventListener('click', (e) => {
            if (e.target === addModal) closeModal();
        });
    }

    if (addUserForm) {
        addUserForm.addEventListener('submit', (e) => {
            e.preventDefault();
            hideError(modalErrorAlert);

            sendAjax('index.php', {
                action: 'add_user',
                user: addUserForm.querySelector('[name="user"]').value,
                password: addUserForm.querySelector('[name="password"]').value,
                role: addUserForm.querySelector('[name="role"]').value
            }, () => {
                closeModal();
            }, (errorMsg) => {
                showError(modalErrorAlert, errorMsg);
            });
        });
    }

    // 5. Делегирование событий для таблицы пользователей (Удаление)
    document.addEventListener('click', (e) => {
        const deleteBtn = e.target.closest('.btn-delete');
        if (deleteBtn) {
            const userRow = deleteBtn.closest('.user-row');
            if (userRow && confirm('Удалить пользователя?')) {
                sendAjax('index.php', { action: 'delete_user', id: userRow.dataset.id });
            }
        }
    });

    // 6. Делегирование событий (Inline Editing по двойному клику)
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
            
            const optEditor = document.createElement('option');
            optEditor.value = 'editor';
            optEditor.textContent = 'editor';
            select.appendChild(optEditor);

            const optAdmin = document.createElement('option');
            optAdmin.value = 'admin';
            optAdmin.textContent = 'admin';
            select.appendChild(optAdmin);

            select.value = currentVal || 'editor';
            cell.innerHTML = '';
            cell.appendChild(select);
            select.focus();

            let isSaved = false;
            const saveRole = () => {
                if (isSaved) return;
                isSaved = true;
                sendAjax('index.php', {
                    action: 'update_field',
                    id: id,
                    field: field,
                    value: select.value
                }, (res) => {
                    document.getElementById('usersList').innerHTML = res.html;
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

                sendAjax('index.php', {
                    action: 'update_field',
                    id: id,
                    field: field,
                    value: newVal
                }, (res) => {
                    document.getElementById('usersList').innerHTML = res.html;
                });
            };

            input.addEventListener('blur', save);
            input.addEventListener('keydown', (evt) => {
                if (evt.key === 'Enter') {
                    input.removeEventListener('blur', save);
                    save();
                }
            });
        }
    });
});