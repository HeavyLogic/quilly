// userbar.js
import { sendAjax, on } from './common.js';

(() => {
	const editedElements = new Set();
	const stagedImageFiles = new Map(); // Хранилище файлов до клика "Сохранить"
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

	// Инициализация юзербара при загрузке страницы
	document.addEventListener('DOMContentLoaded', () => {
		sendAjax({
			module: 'editor',
			method: 'render_userbar',
			filepath: getCustomFilePath(),
			url: window.location.href
		}, (data) => {
			if (!data.html) {
				return;
			}

			document.head.insertAdjacentHTML('beforeend', data.head);
			document.body.insertAdjacentHTML('beforeend', data.html);

			userbarReady();
		});
	});

	const userbarReady = () => {
		const toolbar = document.getElementById('cms-toolbar');

		// Предотвращаем потерю фокуса при клике по кнопкам тулбара (кроме инпутов)
		on('mousedown', '#cms-toolbar input', function (e) {
			e.preventDefault();
		});

		// Точечный предпросмотр изображения БЕЗ мгновенной отправки на сервер
		on('change', '#cms-toolbar #cms-img-input', function (e) {
			const file = this.files[0];
			if (!file || !currentActiveElement || currentActiveElement.tagName !== 'IMG') return;

			currentActiveElement.removeAttribute('srcset');
			currentActiveElement.removeAttribute('sizes');
			currentActiveElement.src = URL.createObjectURL(file);

			currentActiveElement.classList.add('edited');
			editedElements.add(currentActiveElement.id);
			stagedImageFiles.set(currentActiveElement.id, file);

			document.getElementById('cms-btn-save').removeAttribute('disabled');
		});

		// Обработка всех текстовых кнопок тулбара
		on('click', '#cms-toolbar .cms-tb-btn[data-cmd]', function (e) {
			const cmd = this.dataset.cmd;
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
					const parent = existingSpan.parentNode;
					while (existingSpan.firstChild) {
						parent.insertBefore(existingSpan.firstChild, existingSpan);
					}
					parent.removeChild(existingSpan);
				} else if (selection.rangeCount > 0 && !selection.isCollapsed) {
					const range = selection.getRangeAt(0);
					const span = document.createElement('span');

					span.appendChild(range.extractContents());

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

		// Динамическое обновление href у ссылок на лету
		on('input', '#cms-link-input', function (e) {
			if (currentActiveElement || currentActiveElement.tagName !== 'A') {
				return;
			}

			currentActiveElement.setAttribute('href', this.value.trim());
			currentActiveElement.classList.add('edited');
			editedElements.add(currentActiveElement.id);

			const btnSave = document.getElementById('cms-btn-save');
			if (btnSave) btnSave.removeAttribute('disabled');
		});

		on('change', '#cms-toggle-edit', function (e) {
			const isEdit = this.checked;

			if (isEdit) {
				document.body.classList.add('cms-edit-mode');
			} else {
				document.body.classList.remove('cms-edit-mode');
				document.getElementById('cms-img-modal')?.classList.remove('active');
				document.getElementById('cms-revisions-pop')?.classList.remove('active');
				document.getElementById('cms-progress-bar')?.classList.remove('active');

				setActiveElement(null);

				// cleanup Empty Br
				document.querySelectorAll('.editable').forEach(el => {
					if (el.textContent.trim() === '') {
						const html = el.innerHTML.trim().toLowerCase();
						if (html === '<br>' || html === '<br/>' || /^<br\s*\/?>$/i.test(html)) {
							el.innerHTML = '';
						}
					}
				});
			}

			document.querySelectorAll('.editable[id]').forEach(el => {
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

		// Закрываем окно ревизий при клике в любое место (независимо от режима редактирования!)
		document.addEventListener('click', (e) => {
			if (!e.target.closest('#cms-btn-revs, #cms-revisions-pop')) {
				document.getElementById('cms-revisions-pop')?.classList.remove('active');
			}
		});

		// Главный обработчик кликов для выбора элементов холста в режиме редактирования
		document.addEventListener('click', (e) => {
			if (!document.body.classList.contains('cms-edit-mode')) return;

			// Разрешаем клики внутри элементов интерфейса CMS
			if (e.target.closest('#cms-userbar-container, #cms-img-modal')) return;

			// Закрываем модальное окно выбора картинок
			document.getElementById('cms-img-modal')?.classList.remove('active');

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

				// Окно выбора из списка наложенных друг на друга картинок
				const modal = document.getElementById('cms-img-modal');
				if (!modal) return;

				const list = modal.querySelector('.cms-img-modal-list');
				list.innerHTML = '';

				hitEditableImgs.forEach(img => {
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
				document.getElementById('cms-btn-save').removeAttribute('disabled');
			}
		});

		// Нажатие кнопки "Сохранить"
		on('click', '#cms-btn-save', function (e) {
			if (editedElements.size === 0) return;

			const saveBtn = this;
			const revsPop = document.getElementById('cms-revisions-pop');

			// Активируем глобальное состояние AJAX загрузки
			saveBtn.setAttribute('disabled', 'true');
			revsPop?.classList.remove('active');

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

			// ШАГ 1: Сохранение текста
			const formData = new FormData();
			formData.append('module', 'editor');
			formData.append('method', 'save_page');
			formData.append('filepath', getCustomFilePath());
			formData.append('url', window.location.href);
			formData.append('changes', JSON.stringify(changes));

			// Принимаем result из ответа PHP
			sendAjax(formData, async (result) => {
				const progressBar = document.getElementById('cms-progress-bar');
				const progressLabel = document.querySelector('#cms-progress-label span');
				const progressFill = document.getElementById('cms-progress-fill');

				// ШАГ 2: Поочередная загрузка изображений
				if (imageTasks.length > 0) {
					progressFill.style.width = '0%';
					progressLabel.innerText = `0/${imageTasks.length}`;
					progressBar?.classList.add('active');

					const total = imageTasks.length;

					for (let i = 0; i < total; i++) {
						const task = imageTasks[i];
						const imgEl = document.getElementById(task.id);
						const currentSrc = imgEl ? (imgEl.getAttribute('src') || '') : '';

						const imgFormData = new FormData();
						imgFormData.append('module', 'upload');
						imgFormData.append('method', 'upload_single_image');
						imgFormData.append('filepath', getCustomFilePath());
						imgFormData.append('url', window.location.href);
						imgFormData.append('target_id', task.id);
						imgFormData.append('target_src', currentSrc);
						imgFormData.append('image', task.file);

						let isUploadError = false;

						await sendAjax(
							imgFormData,
							() => {
								const pct = Math.round(((i + 1) / total) * 100);
								if (progressFill) progressFill.style.width = pct + '%';
								if (progressLabel) progressLabel.innerText = `${i + 1}/${total}`;
							}, () => {
								progressBar?.classList.remove('active');
								saveBtn.removeAttribute('disabled');
								isUploadError = true;
							},
							true
						);

						if (isUploadError) {
							// Выход из цикла
							return;
						}
					}

					progressBar?.classList.remove('active');
				}

				// ШАГ 3: Завершение (выполняется строго внутри колбэка успеха)
				editedElements.clear();
				stagedImageFiles.clear();
				document.querySelectorAll('.editable.edited').forEach(el => el.classList.remove('edited'));
				saveBtn.setAttribute('disabled', 'true');
				setActiveElement(null);

				// Обновляем блоки ревизий из полученного ответа result
				if (result.revisions_list) {
					document.getElementById('cms-revisions-pop').innerHTML = result.revisions_list;
				}

				const currentBtnRevs = document.getElementById('cms-btn-revs');
				if (currentBtnRevs && result.revisions_button) {
					currentBtnRevs.outerHTML = result.revisions_button;
				}

			});
		});

		on('click', '#cms-btn-logout', function (e) {
			sendAjax({
				module: 'auth',
				method: 'logout',
			}, () => {
				location.reload();
			});
		});

		on('click', '.cms-rev-item', function (e) {
			if (confirm(`Откатить страницу к состоянию от ${this.innerText}?`)) {
				sendAjax({
					module: 'revisions',
					method: 'rollback_revision',
					revision_file: this.getAttribute('data-file'),
					filepath: getCustomFilePath(),
					url: window.location.href
				}, () => {
					location.reload();
				});
			}
		});

		// Обработка клика по кнопке открытия ревизий в юзербаре
		on('click', '#cms-btn-revs', function (e) {
			if (!document.getElementById('cms-revisions-pop')) {
				return;
			}
			e.stopPropagation();
			document.getElementById('cms-revisions-pop').classList.toggle('active');
		});
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

	const activateToolbarForElement = (target) => {
		const toolbar = document.getElementById('cms-toolbar');
		if (!toolbar) return;

		const tagName = target.tagName.toUpperCase();

		if (['VIDEO', 'CANVAS', 'AUDIO', 'INPUT', 'TEXTAREA'].includes(tagName)) {
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
})();