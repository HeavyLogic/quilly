<?php
class revisions extends base {

    private static $revisions_count = 0;
    
    // Поиск, нормализация путей в HTML (приведение к /assets/...) и сборка путей для ZIP
    private function getEditableImagesPaths(Dom\HTMLDocument $doc, string $url, string $rootDir, string $fullPath): array {
        $imageRelPaths = [];

        $siteDomain = parse_url($url, PHP_URL_HOST) ?? '';
        $siteScheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
        $siteBaseUrl = $siteDomain ? ($siteScheme . '://' . $siteDomain) : '';

        $images = $doc->querySelectorAll('img.editable');
        $docModified = false;

        foreach ($images as $img) {
            $src = trim($img->getAttribute('src') ?? '');
            if (!$src) continue;

            // 1. Если в src прописан абсолютный URL текущего сайта — срезаем домен
            if ($siteBaseUrl && strpos($src, $siteBaseUrl) === 0) {
                $src = substr($src, strlen($siteBaseUrl));
            }

            // 2. Пропускаем внешние ссылки на чужие домены
            if (preg_match('#^(https?:)?//#i', $src)) {
                continue;
            }

            // 3. Гарантируем ведущий слэш для HTML атрибута src (например, /assets/img.webp)
            $cleanPath = parse_url($src, PHP_URL_PATH) ?? $src;
            $htmlSrc = '/' . ltrim($cleanPath, '/');

            if ($img->getAttribute('src') !== $htmlSrc) {
                $img->setAttribute('src', $htmlSrc);
                $docModified = true;
            }

            // 4. Формируем путь для ZIP (без ведущего слэша) и путь на диске
            $zipRelPath = ltrim($cleanPath, '/');
            $fullImgPath = $rootDir . '/' . $zipRelPath;

            if (file_exists($fullImgPath) && is_file($fullImgPath)) {
                $imageRelPaths[$zipRelPath] = $fullImgPath;
            }
        }

        // Сохраняем измененные корневые пути в HTML перед архивацией
        if ($docModified) {
            $doc->saveHtmlFile($fullPath);
        }

        return $imageRelPaths;
    }
   
    // Создание ZIP-ревизии (HTML + все редактируемые картинки)
    public function makeRevision(string $fullPath, string $targetRelPath, string $rootDir, string $url = ''): void {
        if (!file_exists($fullPath)) return;

        $maxRevisions = (int)(CMS_CONFIG['max_revisions'] ?? 10);
        if ($maxRevisions <= 0) {
            $this->log("makeRevision(): Создание ревизий отключено (max_revisions = {$maxRevisions})", 'revisions.txt');
            return;
        }

        // Снимок формируется строго на основе даты последнего изменения файла (filemtime)
        $lastModTime = @filemtime($fullPath) ?: time();
        $dateStr = date('Y-m-d_H-i-s', $lastModTime);

        $revParentDir = CMS_CONFIG['revisions_dir'] . '/' . $targetRelPath;
        if (!is_dir($revParentDir)) {
            @mkdir($revParentDir, 0755, true);
        }

        $zipPath = $revParentDir . '/' . $dateStr . '.zip';

        // Если архив с такой датой уже существует — пересоздаем его
        if (file_exists($zipPath)) {
            if (!@unlink($zipPath)) {
                $this->error('Не удалось обновить имеющийся архив ревизии: ' . $dateStr . '.zip');
            }
        }

        $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
        $imagePaths = $this->getEditableImagesPaths($doc, $url, $rootDir, $fullPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Не удалось создать ZIP-архив ревизии');
        }

        // Добавляем HTML-файл по его относительному пути
        $zip->addFile($fullPath, ltrim($targetRelPath, '/'));

        // Добавляем все локальные картинки
        foreach ($imagePaths as $relPath => $absPath) {
            $zip->addFile($absPath, $relPath);
        }

        $zip->close();

        // Ротация бэкапов
        $zipFiles = @glob($revParentDir . '/*.zip');
        if (is_array($zipFiles) && count($zipFiles) > $maxRevisions) {
            sort($zipFiles);
            while (count($zipFiles) > $maxRevisions) {
                $oldestZip = array_shift($zipFiles);
                if (file_exists($oldestZip)) {
                    @unlink($oldestZip);
                }
            }
        }
    }

    // Сканирование списка ZIP-ревизий
    public function get_revisions_list() {
        $parentDir = CMS_CONFIG['revisions_dir'] . '/' . $this->resolveTargetRelPath();
        if (!is_dir($parentDir)) return [];

        $files = @glob($parentDir . '/*.zip');
        if (!$files) return [];

        rsort($files);

        $revisions = [];
        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $dateStr = pathinfo($filename, PATHINFO_FILENAME);

            // Истинную дату берем строго из ИМЕНИ файла, а не из файловой системы ОС!
            $dt = DateTime::createFromFormat('Y-m-d_H-i-s', $dateStr);
            if ($dt) {
                $formattedDate = $dt->format('d.m.Y H:i:s');
            } else {
                $time = @filemtime($filePath) ?: time();
                $formattedDate = date('d.m.Y H:i:s', $time);
            }

            $revisions[] = [
                'filename' => $filename,
                'date'     => $formattedDate
            ];
        }

        self::$revisions_count = count($revisions);

        ob_start();
        ?>
        <div id="cms-revisions-pop" class="cms-glass-card" style="opacity: 0; pointer-events: none;">
            <ul>
                <?php if (empty($revisions)): ?>
                    <li class="cms-rev-empty">Нет ревизий</li>
                <?php else: ?>
                    <?php foreach ($revisions as $rev): ?>
                        <li class="cms-rev-item" data-file="<?= htmlspecialchars($rev['filename']) ?>"><?= htmlspecialchars($rev['date']) ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    public function get_revisions_button() {
        ob_start();
        ?>
        <div class="cms-rev-btn" id="cms-btn-revs" data-count="<?= self::$revisions_count ?>" title="История ревизий">
            <span class="tabler-icon tabler--history"></span>
            <span class="cms-rev-text">Ревизии</span>
            <span class="cms-badge" id="cms-revs-badge"><?= self::$revisions_count ?></span>
        </div>
        <?php
        
        return ob_get_clean();
    }

    public function rollback_revision() {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        $revisionFilename = basename($data['revision_file'] ?? '');
        $url = $data['url'] ?? '';

        if (!$revisionFilename) $this->error('Не указан файл ревизии');

        $rootDir = realpath(__DIR__ . '/../');
        $targetRelPath = $this->resolveTargetRelPath();
        $fullPath = $rootDir . '/' . $targetRelPath;

        $revZipPath = CMS_CONFIG['revisions_dir'] . '/' . $targetRelPath . '/' . $revisionFilename;

        if (!file_exists($revZipPath)) {
            $this->error('Файл ревизии не найден: ' . $revisionFilename);
        }

        try {
            // 1. Создаем бэкап ТЕКУЩЕГО живого состояния перед откатом
            $this->makeRevision($fullPath, $targetRelPath, $rootDir, $url);

            // 2. Находим картинки текущей живой версии
            $currentDoc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
            $currentImages = $this->getEditableImagesPaths($currentDoc, $url, $rootDir, $fullPath);

            // 3. Безопасно удаляем с диска текущий живой HTML и его живые картинки
            @unlink($fullPath);
            foreach ($currentImages as $relPath => $absPath) {
                if (file_exists($absPath)) {
                    @unlink($absPath);
                }
            }

            // 4. Распаковываем ZIP-архив целевой ревизии в корень сайта
            $zip = new ZipArchive();
            if ($zip->open($revZipPath) === true) {
                $zip->extractTo($rootDir);
                $zip->close();

                // 5. Вытягиваем оригинальную дату из имени ZIP и явно возвращаем её распакованному HTML
                $dateStr = pathinfo($revisionFilename, PATHINFO_FILENAME);
                $dt = DateTime::createFromFormat('Y-m-d_H-i-s', $dateStr);
                if ($dt) {
                    @touch($fullPath, $dt->getTimestamp());
                }

                // 6. Удаляем архивированный файл ревизии, так как эта версия стала живым сайтом
                @unlink($revZipPath);

                $this->success(['message' => 'Откат успешно выполнен']);
            } else {
                $this->error('Не удалось открыть ZIP-архив ревизии');
            }
        } catch (Throwable $e) {
            $this->log("PHP Exception при откате ревизии: " . $e->getMessage(), 'revisions.txt');
            $this->error('Ошибка отката: ' . $e->getMessage());
        }
    }

}