(() => {
    const editedElements = new Set();
    const stagedImageFiles = new Map(); // Хранилище файлов до клика "Сохранить"
    const blacklistedTags = ['VIDEO', 'CANVAS', 'AUDIO', 'INPUT', 'TEXTAREA'];
    let currentActiveElement = null;

    const getCustomFilePath = () => {
        const el = document.querySelector('[data-filepath]');
        return el ? el.getAttribute('data-filepath').trim() : '';
    };

    // Установка активного целевого элемента с визуальной подсветкой
    const setActiveElement = (el) => {
        if (currentActiveElement && currentActiveElement !== el) {
            currentActiveElement.classList.remove('cms-active-target');
        }
        currentActiveElement = el;
        if (currentActiveElement) {
            currentActiveElement.classList.add('cms-active-target');
        }
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

    // Динамическое обновление списка ревизий без перезагрузки страницы
    const updateRevisionsUI = (revisions) => {
        const revsList = document.querySelector('#cms-revisions-pop ul');
        const revsBadge = document.getElementById('cms-revs-badge');

        if (revsBadge) revsBadge.innerText = revisions.length;

        if (revsList) {
            let revItemsHtml = '';
            if (revisions.length === 0) {
                revItemsHtml = '<li class="cms-rev-empty">Нет ревизий</li>';
            } else {
                revisions.forEach(rev => {
                    revItemsHtml += `<li class="cms-rev-item" data-file="${rev.filename}">${rev.date}</li>`;
                });
            }
            revsList.innerHTML = revItemsHtml;
        }
    };

    // Запрос свежего списка ревизий с сервера
    const refreshRevisionsList = () => {
        const customPath = getCustomFilePath();
        fetch(`/admin/userapi.php?action=init_bar&filepath=${encodeURIComponent(customPath)}&url=${encodeURIComponent(window.location.href)}`, {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success && data.revisions) {
                updateRevisionsUI(data.revisions);
            }
        });
    };

    // Блокировка кликов по кнопкам и ссылкам страницы в режиме редактирования
    document.addEventListener('click', (e) => {
        if (!document.body.classList.contains('cms-edit-mode')) return;

        // Разрешаем клики внутри элементов интерфейса CMS
        if (e.target.closest('#cms-userbar-container, #cms-img-modal')) return;

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
        .then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('CMS Init Auth Error Response:', text);
                throw new Error('Ошибка авторизации сервера: ' + text.substring(0, 150));
            }
        })
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

        // Формируем блок загрузки картинки (или вывод ошибки, если нет Imagick/GD)
        const imageGroupHtml = data.img_library_valid
            ? `<input type="file" id="cms-img-input" class="cms-tb-file" accept="image/*">`
            : `<span style="color: #ff4d4f; font-size: 11px; font-weight: 600;">Ошибка: Требуется расширение Imagick или GD</span>`;

        // Прозрачный родительский контейнер для юзербара и его выплывающих блоков
        const userbarContainer = document.createElement('div');
        userbarContainer.id = 'cms-userbar-container';

        if (!data.php_valid) {
            userbarContainer.innerHTML = `
                <div id="cms-userbar" class="cms-glass-card">
                    <span style="color: #ff4d4f; font-weight: 600;">
                        Ошибка: Требуется PHP 8.4+ (Ваша версия: ${data.php_version})
                    </span>
                    <button class="cms-logout-btn" id="cms-btn-logout">Выйти</button>
                </div>
            `;
            document.body.appendChild(userbarContainer);
            initLogoutEvent();
            return;
        }

        // Ревизии
        const revisions = data.revisions || [];
        let revItemsHtml = '';
        if (revisions.length === 0) {
            revItemsHtml = '<li class="cms-rev-empty">Нет ревизий</li>';
        } else {
            revisions.forEach(rev => {
                revItemsHtml += `<li class="cms-rev-item" data-file="${rev.filename}">${rev.date}</li>`;
            });
        }

        userbarContainer.innerHTML = `
            <!-- 1.1 Выплывающий тулбар форматирования -->
            <div id="cms-toolbar" class="cms-glass-card" data-mode="text">
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

                <div class="cms-tb-group" data-group="link">
                    <label for="cms-link-input" class="cms-tb-label">Ссылка:</label>
                    <input type="text" id="cms-link-input" class="cms-tb-input" placeholder="https://example.com">
                </div>

                <div class="cms-tb-group" data-group="image">
                    ${imageGroupHtml}
                </div>
            </div>

            <!-- 1.2 Выплывающий блок прогресса загрузки -->
            <div id="cms-progress-bar" class="cms-glass-card">
                <span class="cms-progress-label" id="cms-progress-label">Загрузка изображений (0/0)</span>
                <div class="cms-progress-track">
                    <div class="cms-progress-fill" id="cms-progress-fill"></div>
                </div>
            </div>

            <!-- 1.3 Всплывающий список ревизий -->
            <div id="cms-revisions-pop" class="cms-glass-card">
                <div class="revisions-pop-header">История ревизий</div>
                <ul>${revItemsHtml}</ul>
            </div>

            <!-- 1.4 Главный юзербар -->
            <div id="cms-userbar" class="cms-glass-card">
                <label class="cms-switch-label" title="Включить/выключить режим редактирования">
                    <input type="checkbox" class="cms-switch-input" id="cms-toggle-edit">
                    <span class="cms-switch-slider"></span>
                    <span>Редактировать</span>
                </label>

                <div class="cms-rev-btn" id="cms-btn-revs" title="История ревизий">
                    <span class="tabler-icon tabler--history"></span>
                    <span class="cms-rev-text">Ревизии</span>
                    <span class="cms-badge" id="cms-revs-badge">${revisions.length}</span>
                </div>

                <button class="cms-btn-save" id="cms-btn-save" disabled>
                    <span class="tabler-icon tabler--device-floppy" style="vertical-align: middle; margin-right: 4px;"></span>
                    <span class="tabler-icon tabler--loader-2" style="vertical-align: middle; margin-right: 4px;"></span>
                    Сохранить
                </button>
                <button class="cms-logout-btn" id="cms-btn-logout" title="Выйти ('${data.user}')">
                    <span class="cms-logout-text">Выйти</span>
                    <span class="tabler-icon tabler--logout"></span>
                </button>
            </div>
        `;
        document.body.appendChild(userbarContainer);

        // 2. Модальное окно выбора из нескольких наложенных картинок
        const imgModal = document.createElement('div');
        imgModal.id = 'cms-img-modal';
        imgModal.className = 'cms-glass-card';
        imgModal.innerHTML = `
            <div class="cms-img-modal-header">Выберите изображение:</div>
            <div class="cms-img-modal-list"></div>
        `;
        document.body.appendChild(imgModal);

        const toolbar = document.getElementById('cms-toolbar');

        // Предотвращаем потерю фокуса при клике по кнопкам тулбара (кроме инпутов)
        toolbar.addEventListener('mousedown', (e) => {
            if (!e.target.closest('input')) {
                e.preventDefault();
            }
        });

        // Точечный предпросмотр изображения БЕЗ мгновенной отправки на сервер
        const inputImg = toolbar.querySelector('#cms-img-input');
        if (inputImg) {
            inputImg.addEventListener('change', () => {
                const file = inputImg.files[0];
                if (!file || !currentActiveElement || currentActiveElement.tagName !== 'IMG') return;

                // Локальный мгновенный превью прямо на странице
                currentActiveElement.src = URL.createObjectURL(file);

                // Сохраняем файл в память для будущей отправки на "Сохранить"
                currentActiveElement.classList.add('edited');
                editedElements.add(currentActiveElement.id);
                stagedImageFiles.set(currentActiveElement.id, file);

                document.getElementById('cms-btn-save').removeAttribute('disabled');
            });
        }

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

        // Обработка клика по кнопке открытия ревизий в юзербаре
        const btnRevs = document.getElementById('cms-btn-revs');
        const revsPop = document.getElementById('cms-revisions-pop');
        btnRevs.addEventListener('click', (e) => {
            e.stopPropagation();
            revsPop.classList.toggle('active');
        });

        initEditorEvents();
        initLogoutEvent();
        initRollbackEvents();
    };

    // Проверка активности тегов под кареткой
    const updateToolbarButtonStates = () => {
        const toolbar = document.getElementById('cms-toolbar');
        if (!toolbar) return;

        toolbar.querySelectorAll('.cms-tb-btn[data-cmd]').forEach(btn => {
            const cmd = btn.getAttribute('data-cmd');

            if (cmd === 'bold') {
                btn.classList.toggle('is-active', !!(getAncestorTag('b') || getAncestorTag('strong')));
            } else if (cmd === 'italic') {
                btn.classList.toggle('is-active', !!(getAncestorTag('i') || getAncestorTag('em')));
            } else if (cmd === 'underline') {
                btn.classList.toggle('is-active', !!getAncestorTag('u'));
            } else if (cmd === 'strikeThrough') {
                btn.classList.toggle('is-active', !!(getAncestorTag('s') || getAncestorTag('strike')));
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
                .then(async r => {
                    const text = await r.text();
                    try { return JSON.parse(text); } catch (e) { return {}; }
                })
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
                    .then(async r => {
                        const text = await r.text();
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('CMS Rollback Raw Error:', text);
                            throw new Error('Cсырой ответ сервера: ' + text.substring(0, 200));
                        }
                    })
                    .then(res => {
                        if (res && res.success) {
                            location.reload();
                        } else {
                            alert('Ошибка отката: ' + (res.message || 'Неизвестная ошибка'));
                        }
                    })
                    .catch((err) => {
                        alert('Ошибка сети или сервера: ' + err.message);
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
            setActiveElement(null);
            return;
        }

        setActiveElement(target);

        if (tagName === 'IMG') {
            toolbar.setAttribute('data-mode', 'image');
            const inputImg = toolbar.querySelector('#cms-img-input');
            if (inputImg) {
                if (stagedImageFiles.has(target.id)) {
                    const stagedFile = stagedImageFiles.get(target.id);
                    const dt = new DataTransfer();
                    dt.items.add(stagedFile);
                    inputImg.files = dt.files;
                } else {
                    inputImg.value = '';
                }
            }
        } else if (tagName === 'A') {
            toolbar.setAttribute('data-mode', 'link');
            const inputLink = toolbar.querySelector('#cms-link-input');
            if (inputLink) {
                inputLink.value = target.getAttribute('href') || '';
            }
        } else {
            toolbar.setAttribute('data-mode', 'text');
        }

        updateToolbarButtonStates();
    };

    // Окно выбора из списка наложенных друг на друга картинок
    const showImagePickerModal = (imgs) => {
        const modal = document.getElementById('cms-img-modal');
        if (!modal) return;

        const list = modal.querySelector('.cms-img-modal-list');
        list.innerHTML = '';

        imgs.forEach(img => {
            const titleText = img.getAttribute('alt') || img.src.split('/').pop() || 'Изображение';

            const item = document.createElement('div');
            item.className = 'cms-img-choice';
            item.title = titleText;
            item.innerHTML = `<img src="${img.src}" alt="${titleText}">`;

            item.addEventListener('click', () => {
                modal.classList.remove('active');
                activateToolbarForElement(img);
            });

            list.appendChild(item);
        });

        modal.classList.add('active');
    };

    const initEditorEvents = () => {
        const toggle = document.getElementById('cms-toggle-edit');
        const btnSave = document.getElementById('cms-btn-save');
        const imgModal = document.getElementById('cms-img-modal');
        const revsPop = document.getElementById('cms-revisions-pop');
        const progressBar = document.getElementById('cms-progress-bar');
        const progressLabel = document.getElementById('cms-progress-label');
        const progressFill = document.getElementById('cms-progress-fill');

        toggle.addEventListener('change', function() {
            const isEdit = this.checked;
            
            if (isEdit) {
                document.body.classList.add('cms-edit-mode');
            } else {
                document.body.classList.remove('cms-edit-mode');
                if (imgModal) imgModal.classList.remove('active');
                if (revsPop) revsPop.classList.remove('active');
                if (progressBar) progressBar.classList.remove('active');
                setActiveElement(null);
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

        // Главный обработчик кликов для выбора элементов
        document.addEventListener('click', (e) => {
            if (!document.body.classList.contains('cms-edit-mode')) return;

            // Разрешаем клики внутри элементов интерфейса CMS
            if (e.target.closest('#cms-userbar-container, #cms-img-modal')) return;

            // Закрываем модальное окно выбора картинок и окно ревизий
            if (imgModal) imgModal.classList.remove('active');
            if (revsPop) revsPop.classList.remove('active');

            // Страховка от закрытия тулбара при протяжке выделения мышкой за границы элемента
            const sel = window.getSelection();
            if (sel && !sel.isCollapsed && currentActiveElement && currentActiveElement.contains(sel.anchorNode)) {
                return;
            }

            // 1. Проверяем слои в точке клика на наличие нескольких IMG.editable
            const hitElements = document.elementsFromPoint(e.clientX, e.clientY);
            const hitEditableImgs = hitElements.filter(el => 
                el.tagName === 'IMG' && 
                el.classList.contains('editable') && 
                el.id
            );

            if (hitEditableImgs.length > 1) {
                setActiveElement(null);
                showImagePickerModal(hitEditableImgs);
                return;
            } else if (hitEditableImgs.length === 1) {
                activateToolbarForElement(hitEditableImgs[0]);
                return;
            }

            // 2. Стандартный клик по единичному элементу
            const target = e.target.closest('.editable[id]');
            if (target) {
                activateToolbarForElement(target);
            } else {
                setActiveElement(null);
            }
        });

        // Показ тулбара по фокусу (для навигации с клавиатуры)
        document.addEventListener('focusin', (e) => {
            if (!document.body.classList.contains('cms-edit-mode')) return;

            const target = e.target.closest('.editable[id]');
            if (target && target !== currentActiveElement) {
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

        // Нажатие кнопки "Сохранить" (БЕЗ ПЕРЕЗАГРУЗКИ СТРАНИЦЫ)
        btnSave.addEventListener('click', async () => {
            if (editedElements.size === 0) return;

            // Активируем глобальное состояние AJAX загрузки
            document.body.classList.add('cms-ajax-loading');
            btnSave.setAttribute('disabled', 'true');
            if (revsPop) revsPop.classList.remove('active');

            const changes = {};
            const imageTasks = [];

            editedElements.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    if (el.tagName === 'IMG') {
                        if (stagedImageFiles.has(id)) {
                            imageTasks.push({ id: id, file: stagedImageFiles.get(id) });
                        }
                    } else {
                        changes[id] = { html: el.innerHTML };
                    }
                }
            });

            // ШАГ 1: Первичное сохранение текстовых изменений (если они есть)
            if (Object.keys(changes).length > 0) {
                const formData = new FormData();
                formData.append('filepath', getCustomFilePath());
                formData.append('url', window.location.href);
                formData.append('changes', JSON.stringify(changes));

                try {
                    const r = await fetch('/admin/userapi.php?action=save_page', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    });
                    const text = await r.text();
                    const res = JSON.parse(text);
                    if (!res || !res.success) {
                        throw new Error(res.message || 'Ошибка сохранения текста');
                    }
                } catch (err) {
                    alert('Ошибка при сохранении текста: ' + err.message);
                    document.body.classList.remove('cms-ajax-loading');
                    btnSave.removeAttribute('disabled');
                    return;
                }
            }

            // ШАГ 2: Поочередная загрузка изображений
            if (imageTasks.length > 0) {
                progressFill.style.width = '0%';
                progressLabel.innerText = `Загрузка изображений (0/${imageTasks.length})`;
                progressBar.classList.add('active');

                const total = imageTasks.length;

                for (let i = 0; i < total; i++) {
                    const task = imageTasks[i];
                    const imgEl = document.getElementById(task.id);
                    const currentSrc = imgEl ? (imgEl.getAttribute('src') || '') : '';

                    const formData = new FormData();
                    formData.append('filepath', getCustomFilePath());
                    formData.append('url', window.location.href);
                    formData.append('target_id', task.id);
                    formData.append('target_src', currentSrc);
                    formData.append('image', task.file);

                    try {
                        const r = await fetch('/admin/userapi.php?action=upload_single_image', {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: formData
                        });
                        const text = await r.text();
                        const res = JSON.parse(text);

                        if (!res || !res.success) {
                            throw new Error(res.message || `Ошибка загрузки файла ${i + 1}`);
                        }

                        // Обновляем src картинки в DOM
                        if (imgEl && res.relative_path) {
                            imgEl.setAttribute('src', res.relative_path);
                        }

                        // Обновляем индикатор загрузки
                        const pct = Math.round(((i + 1) / total) * 100);
                        progressFill.style.width = pct + '%';
                        progressLabel.innerText = `Загрузка изображений (${i + 1}/${total})`;

                    } catch (err) {
                        alert(`Ошибка при загрузке изображения ${i + 1} из ${total}: ` + err.message);
                        progressBar.classList.remove('active');
                        document.body.classList.remove('cms-ajax-loading');
                        btnSave.removeAttribute('disabled');
                        return;
                    }
                }

                progressBar.classList.remove('active');
            }

            // ШАГ 3: Мягкое финализирование состояния на клиенте БЕЗ перезагрузки страницы
            document.body.classList.remove('cms-ajax-loading');
            editedElements.clear();
            stagedImageFiles.clear();
            document.querySelectorAll('.editable.edited').forEach(el => el.classList.remove('edited'));
            btnSave.setAttribute('disabled', 'true');
            setActiveElement(null);

            // Бесшовно обновляем список ревизий и счетчик
            refreshRevisionsList();
        });
    };
})();