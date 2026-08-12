<?php
require_once __DIR__ . '/revisions.php';
require_once __DIR__ . '/auth.php';

class editor extends base {
    public function render_userbar() {
        $revisions = new revisions();

        ob_start();
        ?>
        <div id="cms-toolbar">
            <div id="cms-userbar-container">
                <?php if (!version_compare(phpversion(), '8.4.0', '>=')): ?>
                    <div id="cms-userbar" class="cms-glass-card">
                        <span style="color: #ff4d4f; font-weight: 600;">
                            Ошибка: Требуется PHP 8.4+
                        </span>
                        <button class="cms-logout-btn" id="cms-btn-logout">Выйти</button>
                    </div>
                <?php else: ?>
                    <!-- 1.1 Тулбар форматирования -->
                    <div id="cms-toolbar" class="cms-glass-card" data-mode="text" style="opacity: 0; pointer-events: none;">
                        <div class="cms-tb-group" data-group="text">
                            <button class="cms-tb-btn" data-cmd="bold" title="Жирный"><span class="tabler-icon tabler--bold"></span></button>
                            <button class="cms-tb-btn" data-cmd="italic" title="Курсив"><span class="tabler-icon tabler--italic"></span></button>
                            <button class="cms-tb-btn" data-cmd="underline" title="Подчеркнутый"><span class="tabler-icon tabler--underline"></span></button>
                            <button class="cms-tb-btn" data-cmd="strikeThrough" title="Зачеркнутый"><span class="tabler-icon tabler--strikethrough"></span></button>
                            <div class="cms-tb-divider"></div>
                            <button class="cms-tb-btn" data-cmd="createLink" title="Ссылка"><span class="tabler-icon tabler--link"></span></button>
                            <button class="cms-tb-btn" data-cmd="span" title="Span"><span class="cms-tb-text">span</span></button>
                            <button class="cms-tb-btn" data-cmd="removeFormat" title="Очистить форматирование"><span class="tabler-icon tabler--clear-formatting"></span></button>
                        </div>
        
                        <div class="cms-tb-group" data-group="link">
                            <label for="cms-link-input" class="cms-tb-label">Ссылка:</label>
                            <input type="text" id="cms-link-input" class="cms-tb-input" placeholder="https://example.com">
                        </div>
        
                        <div class="cms-tb-group" data-group="image">
                            <?php if (extension_loaded('imagick') || extension_loaded('gd')): ?>
                                <input type="file" id="cms-img-input" class="cms-tb-file" accept=".jpg,.jpeg,.png,.bmp,.gif,.svg,.webp,.avif,image/jpeg,image/png,image/bmp,image/gif,image/svg+xml,image/webp,image/avif">
                            <?php else: ?>
                                <span style="color: #ff4d4f; font-size: 11px; font-weight: 600;">Ошибка: Требуется расширение Imagick или GD</span>
                            <?php endif; ?>
                        </div>
                    </div>
        
                    <!-- 1.2 Прогресс-бар -->
                    <div id="cms-progress-bar" class="cms-glass-card" style="opacity: 0; pointer-events: none;">
                        <span class="cms-progress-label" id="cms-progress-label">Загружено (0/0)</span>
                        <div class="cms-progress-track">
                            <div class="cms-progress-fill" id="cms-progress-fill"></div>
                        </div>
                    </div>
        
                    <!-- 1.3 Список ревизий -->
                    <?php echo $revisions->get_revisions_list(); ?>
        
                    <!-- 1.4 Главный юзербар -->
                    <div id="cms-userbar" class="cms-glass-card">
                        <label class="cms-switch-label" title="Включить/выключить режим редактирования">
                            <input type="checkbox" class="cms-switch-input" id="cms-toggle-edit">
                            <span class="cms-switch-slider"></span>
                            <span>Редактировать</span>
                        </label>

                        <?php echo $revisions->get_revisions_button(); ?>
        
                        <button class="cms-btn-save" id="cms-btn-save" disabled>
                            <span class="tabler-icon tabler--device-floppy" style="vertical-align: middle; margin-right: 4px;"></span>
                            <span class="tabler-icon tabler--loader-2" style="vertical-align: middle; margin-right: 4px;"></span>
                            Сохранить
                        </button>
                        <button class="cms-logout-btn" id="cms-btn-logout">
                            <span class="cms-logout-text">Выйти</span>
                            <span class="tabler-icon tabler--logout"></span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 2. Модальное окно выбора из нескольких картинок -->
        <div id="cms-img-modal" class="cms-glass-card">
            <div class="cms-img-modal-header">Выберите изображение:</div>
            <div class="cms-img-modal-list"></div>
        </div>
        
        <?php
        $html = ob_get_clean();

        $this->success([
            'head' => '<link rel="stylesheet" href="/admin/assets/userbar.css">',
            'html' => $html,
        ]);
    }

    public function save_page() {
        if (version_compare(phpversion(), '8.4.0', '<')) {
            $this->error('Требуется PHP 8.4+');
        }

        $rootDir = realpath(__DIR__ . '/../');
        $url = $_POST['url'] ?? '';

        $targetRelPath = $this->resolveTargetRelPath();
        $fullPath = $rootDir . '/' . $targetRelPath;
        $realFileDir = realpath(dirname($fullPath));

        if ($realFileDir === false || strpos($realFileDir, $rootDir) !== 0) {
            $this->error('Попытка выхода за пределы корня');
        }

        if (!file_exists($fullPath)) {
            $this->error('Файл не найден: ' . $targetRelPath);
        }

        $changes = json_decode($_POST['changes'] ?? '{}', true) ?? [];

        try {
            $this->writeDebugLog("save_page(): Клиент вызвал сохранение для '{$targetRelPath}'", 'revisions.txt');

            // ШАГ 1: Создаем ровно 1 ZIP-ревизию ТЕКУЩЕГО живого состояния (HTML + старые картинки)
            new revisions()->makeRevision($fullPath, $targetRelPath, $rootDir, $url);

            // ШАГ 2: Сохраняем текстовые изменения
            if (!empty($changes)) {
                $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);

                foreach ($changes as $id => $payload) {
                    $element = $doc->getElementById($id);
                    if ($element && isset($payload['html'])) {
                        while ($element->firstChild) {
                            $element->removeChild($element->firstChild);
                        }

                        $fragDoc = Dom\HTMLDocument::createFromString(
                            '<!DOCTYPE html><html><body><div id="cms-temp-fragment-wrapper">' . $payload['html'] . '</div></body></html>',
                            LIBXML_NOERROR
                        );

                        $wrapper = $fragDoc->getElementById('cms-temp-fragment-wrapper');
                        if ($wrapper) {
                            foreach ($wrapper->childNodes as $childNode) {
                                $importedNode = $doc->importNode($childNode, true);
                                $element->appendChild($importedNode);
                            }
                        }
                    }
                }

                // Фиксируем текст на диске
                $doc->saveHtmlFile($fullPath);
            }

            $revisions = new revisions();
            
            $this->success([
                'saved_file' => $targetRelPath,
                'revisions_list' => $revisions->get_revisions_list(),
                'revisions_button' => $revisions->get_revisions_button(),
            ]);

        } catch (Throwable $e) {
            $this->writeDebugLog("PHP Exception при сохранении save_page: " . $e->getMessage(), 'revisions.txt');
            $this->error('Ошибка сохранения PHP: ' . $e->getMessage());
        }
    }
}