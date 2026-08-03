(() => {
    const editedElements = new Set();

    const getCustomFilePath = () => {
        const el = document.querySelector('[data-filepath]');
        return el ? el.getAttribute('data-filepath').trim() : '';
    };

    document.addEventListener('DOMContentLoaded', () => {
        const customPath = getCustomFilePath();
        
        fetch(`/admin/userapi.php?action=init_bar&filepath=${encodeURIComponent(customPath)}&url=${encodeURIComponent(window.location.href)}`, {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success && data.authorized) {
                renderUserbar(data);
            }
        })
        .catch(err => {
            console.error('CMS Auth Error:', err);
        });
    });

    const renderUserbar = (data) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/admin/assets/userbar.css';
        document.head.appendChild(link);

        // 1. Бар управления
        const bar = document.createElement('div');
        bar.id = 'cms-userbar';
        bar.className = 'cms-glass-card';

        if (!data.php_valid) {
            bar.innerHTML = `
                <span style="color: #ff4d4f; font-weight: 600;">
                    Ошибка: Требуется PHP 8.4+ (Ваша версия: ${data.php_version})
                </span>
                <button class="cms-logout-btn" id="cms-btn-logout">Выйти</button>
            `;
            document.body.appendChild(bar);
            initLogoutEvent();
            return;
        }

        bar.innerHTML = `
            <label class="cms-switch-label" title="Включить/выключить режим редактирования">
                <input type="checkbox" class="cms-switch-input" id="cms-toggle-edit">
                <span class="cms-switch-slider"></span>
                <span>Редактировать</span>
            </label>

            <button class="cms-btn-save" id="cms-btn-save" disabled>Сохранить</button>
            <button class="cms-logout-btn" id="cms-btn-logout" title="Выйти ('${data.user}')">Выйти</button>
        `;
        document.body.appendChild(bar);

        // 2. Карточка ревизий
        const revisions = data.revisions || [];
        let revItemsHtml = '';
        
        if (revisions.length === 0) {
            revItemsHtml = '<li class="cms-rev-empty">Нет ревизий</li>';
        } else {
            revisions.forEach(rev => {
                revItemsHtml += `<li class="cms-rev-item" data-file="${rev.filename}">${rev.date}</li>`;
            });
        }

        const revsBox = document.createElement('div');
        revsBox.id = 'cms-revisions';
        revsBox.className = 'cms-glass-card';
        revsBox.innerHTML = `
            <div class="revisions-header">
                <span>Ревизии</span>
                <span class="cms-badge">${revisions.length}</span>
            </div>
            <div class="revisions-wrapper">
                <ul>
                    ${revItemsHtml}
                </ul>
            </div>
        `;
        document.body.appendChild(revsBox);

        initEditorEvents();
        initLogoutEvent();
        initRollbackEvents();
    };

    const cleanupEmptyBr = () => {
        const editables = document.querySelectorAll('.editable');
        editables.forEach(el => {
            if (el.textContent.trim() === '') {
                const html = el.innerHTML.trim().toLowerCase();
                if (html === '<br>' || html === '<br/>' || /^<br\s*\/?>$/i.test(html)) {
                    el.innerHTML = '';
                }
            }
        });
    };

    const initLogoutEvent = () => {
        const btnLogout = document.getElementById('cms-btn-logout');
        if (btnLogout) {
            btnLogout.addEventListener('click', () => {
                fetch('/admin/userapi.php?action=logout', {
                    method: 'POST',
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) location.reload();
                });
            });
        }
    };

    const initRollbackEvents = () => {
        document.addEventListener('click', (e) => {
            const item = e.target.closest('.cms-rev-item');
            if (item) {
                const fileName = item.getAttribute('data-file');
                const dateText = item.innerText;

                if (confirm(`Откатить страницу к состоянию от ${dateText}?`)) {
                    fetch('/admin/userapi.php?action=rollback_revision', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            revision_file: fileName,
                            filepath: getCustomFilePath(),
                            url: window.location.href
                        })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res && res.success) {
                            location.reload();
                        } else {
                            alert('Ошибка отката: ' + (res.message || 'Неизвестная ошибка'));
                        }
                    })
                    .catch(() => {
                        alert('Ошибка сети при откате');
                    });
                }
            }
        });
    };

    const initEditorEvents = () => {
        const toggle = document.getElementById('cms-toggle-edit');
        const btnSave = document.getElementById('cms-btn-save');

        toggle.addEventListener('change', function() {
            const isEdit = this.checked;
            
            if (isEdit) {
                document.body.classList.add('cms-edit-mode');
            } else {
                document.body.classList.remove('cms-edit-mode');
                cleanupEmptyBr();
            }

            const editables = document.querySelectorAll('.editable[id]');
            editables.forEach(el => {
                if (isEdit) {
                    el.setAttribute('contenteditable', 'true');
                } else {
                    el.removeAttribute('contenteditable');
                }
            });
        });

        document.addEventListener('input', (e) => {
            const target = e.target;
            if (target.classList.contains('editable') && target.id) {
                target.classList.add('edited');
                editedElements.add(target.id);
                btnSave.removeAttribute('disabled');
            }
        });

        btnSave.addEventListener('click', () => {
            if (editedElements.size === 0) return;

            btnSave.setAttribute('disabled', 'true');
            btnSave.innerText = 'Сохранение...';

            const changes = {};
            editedElements.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    changes[id] = { text: el.textContent.trim() };
                }
            });

            const payload = {
                filepath: getCustomFilePath(),
                url: window.location.href,
                changes: changes
            };

            fetch('/admin/userapi.php?action=save_page', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                if (res && res.success) {
                    location.reload();
                } else {
                    alert('Ошибка: ' + (res.message || 'Неизвестная ошибка'));
                    btnSave.removeAttribute('disabled');
                    btnSave.innerText = 'Сохранить';
                }
            })
            .catch(err => {
                alert('Ошибка: ' + err.message);
                btnSave.removeAttribute('disabled');
                btnSave.innerText = 'Сохранить';
            });
        });
    };
})();