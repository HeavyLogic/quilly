(() => {
    const editedElements = new Set();
    const blacklistedTags = ['IMG', 'VIDEO', 'CANVAS', 'AUDIO', 'INPUT', 'TEXTAREA'];

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
        // Подключаем стили
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/admin/assets/userbar.css';
        document.head.appendChild(link);

        // 1. Выплывающий тулбар форматирования
        const toolbar = document.createElement('div');
        toolbar.id = 'cms-toolbar';
        toolbar.className = 'cms-glass-card';
        toolbar.innerHTML = `
            <button class="cms-tb-btn" data-cmd="bold" title="Жирный">
                <span class="tabler-icon tabler--bold"></span>
            </button>
            <button class="cms-tb-btn" data-cmd="italic" title="Курсив">
                <span class="tabler-icon tabler--italic"></span>
            </button>
            <button class="cms-tb-btn" data-cmd="underline" title="Подчеркнутый">
                <span class="tabler-icon tabler--underline"></span>
            </button>
            <button class="cms-tb-btn" data-cmd="strikeThrough" title="Зачеркнутый">
                <span class="tabler-icon tabler--strikethrough"></span>
            </button>
            
            <div class="cms-tb-divider"></div>
            
            <button class="cms-tb-btn" data-cmd="createLink" title="Ссылка">
                <span class="tabler-icon tabler--link"></span>
            </button>
            <button class="cms-tb-btn" data-cmd="span" title="Обернуть в span">
                <span class="cms-tb-text">span</span>
            </button>
            <button class="cms-tb-btn" data-cmd="removeFormat" title="Очистить форматирование">
                <span class="tabler-icon tabler--clear-formatting"></span>
            </button>
            
            <button class="cms-tb-btn" data-show-for="list" data-cmd="insertLi" title="Добавить элемент списка (li)">
                <span class="tabler-icon tabler--list-item"></span>
            </button>
        `;
        document.body.appendChild(toolbar);

        // Предотвращаем потерю фокуса при клике по кнопкам тулбара
        toolbar.querySelectorAll('.cms-tb-btn').forEach(btn => {
            btn.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const cmd = btn.getAttribute('data-cmd');
                if (!cmd) return;

                if (cmd === 'createLink') {
                    const url = prompt('Введите URL ссылки:');
                    if (url) document.execCommand('createLink', false, url);
                } else if (cmd === 'removeFormat') {
                    document.execCommand('removeFormat', false, null);
                    document.execCommand('unlink', false, null);
                } else if (cmd === 'span') {
                    const selection = window.getSelection();
                    if (selection.rangeCount > 0 && !selection.isCollapsed) {
                        const range = selection.getRangeAt(0);
                        const span = document.createElement('span');
                        range.surroundContents(span);
                    }
                } else {
                    document.execCommand(cmd, false, null);
                }

                // Пересчитываем подсветку кнопок сразу после команды
                updateToolbarButtonStates();
            });
        });

        // 2. Бар управления
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

        // 3. Карточка ревизий
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

    // Проверка активности тегов под кареткой (жирный, курсив, ссылка и т.д.)
    const updateToolbarButtonStates = () => {
        const toolbar = document.getElementById('cms-toolbar');
        if (!toolbar || !toolbar.classList.contains('active')) return;

        const commandMap = {
            'bold': 'bold',
            'italic': 'italic',
            'underline': 'underline',
            'strikeThrough': 'strikeThrough',
            'createLink': 'createLink'
        };

        toolbar.querySelectorAll('.cms-tb-btn[data-cmd]').forEach(btn => {
            const cmd = btn.getAttribute('data-cmd');
            if (commandMap[cmd]) {
                try {
                    const isActive = document.queryCommandState(commandMap[cmd]);
                    if (isActive) {
                        btn.classList.add('is-active');
                    } else {
                        btn.classList.remove('is-active');
                    }
                } catch (e) {
                    btn.classList.remove('is-active');
                }
            }
        });
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
        const toolbar = document.getElementById('cms-toolbar');

        toggle.addEventListener('change', function() {
            const isEdit = this.checked;
            
            if (isEdit) {
                document.body.classList.add('cms-edit-mode');
            } else {
                document.body.classList.remove('cms-edit-mode');
                toolbar.classList.remove('active');
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

        // Отслеживаем перемещение курсора/каретки для обновления подсветок кнопок
        document.addEventListener('selectionchange', () => {
            if (document.body.classList.contains('cms-edit-mode')) {
                updateToolbarButtonStates();
            }
        });

        // Показ / скрытие тулбара с учётом контекста элементов
        document.addEventListener('focusin', (e) => {
            const target = e.target;
            if (!document.body.classList.contains('cms-edit-mode')) return;

            if (target.classList.contains('editable') && target.id) {
                const tagName = target.tagName.toUpperCase();

                // 1. Проверяем чёрный список
                if (blacklistedTags.includes(tagName)) {
                    toolbar.classList.remove('active');
                    return;
                }

                // 2. Записываем имя тега для CSS-фильтрации кнопок
                toolbar.setAttribute('data-tag', tagName.toLowerCase());
                toolbar.classList.add('active');
                updateToolbarButtonStates();
            }
        });

        document.addEventListener('focusout', () => {
            setTimeout(() => {
                const activeEl = document.activeElement;
                if (!activeEl || !activeEl.classList.contains('editable') || !activeEl.id) {
                    toolbar.classList.remove('active');
                }
            }, 100);
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
                    changes[id] = { html: el.innerHTML };
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