(() => {
    const editedElements = new Set();
    const blacklistedTags = ['VIDEO', 'CANVAS', 'AUDIO', 'INPUT', 'TEXTAREA'];
    let currentActiveElement = null;

    const getCustomFilePath = () => {
        const el = document.querySelector('[data-filepath]');
        return el ? el.getAttribute('data-filepath').trim() : '';
    };

    // Хелпер: ищет родительский тег заданного типа от текущего выделения до границы .editable
    const getAncestorTag = (tagName) => {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        let node = sel.getRangeAt(0).commonAncestorContainer;
        if (node.nodeType === Node.TEXT_NODE) node = node.parentNode;
        
        while (node && node !== document.body && !node.classList?.contains('editable')) {
            if (node.tagName && node.tagName.toLowerCase() === tagName.toLowerCase()) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    };

    // Чистка пустых спанов и склейка смежных </span><span>
    const cleanupSpans = (editableEl) => {
        if (!editableEl) return;
        // 1. Стираем пустые <span></span>
        editableEl.innerHTML = editableEl.innerHTML.replace(/<span[^>]*>\s*<\/span>/gi, '');
        // 2. Склеиваем стоящие рядом </span><span>
        editableEl.innerHTML = editableEl.innerHTML.replace(/<\/span>(\s*)<span[^>]*>/gi, '$1');
    };

    // Блокировка кликов по кнопкам и ссылкам страницы в режиме редактирования
    document.addEventListener('click', (e) => {
        if (!document.body.classList.contains('cms-edit-mode')) return;

        // Разрешаем клики внутри элементов интерфейса CMS
        if (e.target.closest('#cms-userbar, #cms-revisions, #cms-toolbar')) return;

        // Перехватываем ссылки, кнопки и сабмиты страниц
        const clickable = e.target.closest('a, button, input[type="submit"], input[type="button"]');
        if (clickable) {
            e.preventDefault();
        }
    }, true);

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
        toolbar.setAttribute('data-mode', 'text');
        toolbar.innerHTML = `
            <!-- Группа текстового форматирования -->
            <div class="cms-tb-group" data-group="text">
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
                <button class="cms-tb-btn" data-cmd="span" title="Span">
                    <span class="cms-tb-text">span</span>
                </button>
                <button class="cms-tb-btn" data-cmd="removeFormat" title="Очистить форматирование">
                    <span class="tabler-icon tabler--clear-formatting"></span>
                </button>
            </div>

            <!-- Группа редактирования ссылки (A) -->
            <div class="cms-tb-group" data-group="link">
                <input type="text" id="cms-link-input" class="cms-tb-input" placeholder="https://example.com">
            </div>

            <!-- Группа загрузки изображения (IMG) -->
            <div class="cms-tb-group" data-group="image">
                <input type="file" id="cms-img-input" class="cms-tb-file" accept="image/*">
            </div>
        `;
        document.body.appendChild(toolbar);

        // Предотвращаем потерю фокуса при клике по кнопкам тулбара (кроме инпутов)
        toolbar.addEventListener('mousedown', (e) => {
            if (!e.target.closest('input')) {
                e.preventDefault();
            }
        });

        // Обработка текстовых кнопок
        toolbar.querySelectorAll('.cms-tb-btn[data-cmd]').forEach(btn => {
            btn.addEventListener('click', () => {
                const cmd = btn.getAttribute('data-cmd');
                if (!cmd) return;

                const activeEditable = currentActiveElement || document.activeElement.closest('.editable');

                if (cmd === 'createLink') {
                    const existingLink = getAncestorTag('a');
                    if (existingLink) {
                        document.execCommand('unlink', false, null);
                    } else {
                        const url = prompt('Введите URL ссылки:');
                        if (url) document.execCommand('createLink', false, url);
                    }
                } else if (cmd === 'span') {
                    const existingSpan = getAncestorTag('span');
                    const selection = window.getSelection();

                    if (existingSpan) {
                        // Снимаем span (unwrap)
                        const parent = existingSpan.parentNode;
                        while (existingSpan.firstChild) {
                            parent.insertBefore(existingSpan.firstChild, existingSpan);
                        }
                        parent.removeChild(existingSpan);
                    } else if (selection.rangeCount > 0 && !selection.isCollapsed) {
                        // Оборачиваем в span
                        const range = selection.getRangeAt(0);
                        const span = document.createElement('span');
                        
                        span.appendChild(range.extractContents());

                        // Страховка от вложенности: вынимаем внутренние span
                        span.querySelectorAll('span').forEach(innerSpan => {
                            const parent = innerSpan.parentNode;
                            while (innerSpan.firstChild) {
                                parent.insertBefore(innerSpan.firstChild, innerSpan);
                            }
                            parent.removeChild(innerSpan);
                        });

                        range.insertNode(span);
                    }

                    if (activeEditable) cleanupSpans(activeEditable);
                } else if (cmd === 'removeFormat') {
                    document.execCommand('removeFormat', false, null);
                    document.execCommand('unlink', false, null);
                    if (activeEditable) cleanupSpans(activeEditable);
                } else {
                    document.execCommand(cmd, false, null);
                }

                updateToolbarButtonStates();
            });
        });

        // Динамическое обновление href у ссылок на лету
        const inputLink = toolbar.querySelector('#cms-link-input');
        inputLink.addEventListener('input', () => {
            if (currentActiveElement && currentActiveElement.tagName === 'A') {
                currentActiveElement.setAttribute('href', inputLink.value.trim());
                currentActiveElement.classList.add('edited');
                editedElements.add(currentActiveElement.id);
                document.getElementById('cms-btn-save').removeAttribute('disabled');
            }
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

            <button class="cms-btn-save" id="cms-btn-save" disabled>
                <span class="tabler-icon tabler--device-floppy" style="vertical-align: middle; margin-right: 4px;"></span>
                Сохранить
            </button>
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

    // Проверка активности тегов под кареткой
    const updateToolbarButtonStates = () => {
        const toolbar = document.getElementById('cms-toolbar');
        if (!toolbar || !toolbar.classList.contains('active')) return;

        const nativeCommands = {
            'bold': 'bold',
            'italic': 'italic',
            'underline': 'underline',
            'strikeThrough': 'strikeThrough'
        };

        toolbar.querySelectorAll('.cms-tb-btn[data-cmd]').forEach(btn => {
            const cmd = btn.getAttribute('data-cmd');

            if (nativeCommands[cmd]) {
                try {
                    const isActive = document.queryCommandState(nativeCommands[cmd]);
                    btn.classList.toggle('is-active', isActive);
                } catch (e) {
                    btn.classList.remove('is-active');
                }
            } else if (cmd === 'createLink') {
                btn.classList.toggle('is-active', !!getAncestorTag('a'));
            } else if (cmd === 'span') {
                btn.classList.toggle('is-active', !!getAncestorTag('span'));
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

    const activateToolbarForElement = (target) => {
        const toolbar = document.getElementById('cms-toolbar');
        if (!toolbar) return;

        const tagName = target.tagName.toUpperCase();

        if (blacklistedTags.includes(tagName)) {
            toolbar.classList.remove('active');
            return;
        }

        currentActiveElement = target;

        if (tagName === 'IMG') {
            toolbar.setAttribute('data-mode', 'image');
        } else if (tagName === 'A') {
            toolbar.setAttribute('data-mode', 'link');
            const inputLink = toolbar.querySelector('#cms-link-input');
            if (inputLink) {
                inputLink.value = target.getAttribute('href') || '';
            }
        } else {
            toolbar.setAttribute('data-mode', 'text');
        }

        toolbar.classList.add('active');
        updateToolbarButtonStates();
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

        // Показ тулбара по клику (работает для любых элементов, включая IMG)
        document.addEventListener('click', (e) => {
            if (!document.body.classList.contains('cms-edit-mode')) return;

            const target = e.target.closest('.editable[id]');
            if (target) {
                activateToolbarForElement(target);
            } else if (!e.target.closest('#cms-toolbar, #cms-userbar, #cms-revisions')) {
                toolbar.classList.remove('active');
                currentActiveElement = null;
            }
        });

        // Показ тулбара по фокусу (для таба с клавиатуры и т.д.)
        document.addEventListener('focusin', (e) => {
            if (!document.body.classList.contains('cms-edit-mode')) return;

            const target = e.target.closest('.editable[id]');
            if (target) {
                activateToolbarForElement(target);
            }
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