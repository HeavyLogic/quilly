<?php
header('Content-Type: application/json');

$dbPath = __DIR__ . '/../restricted/users.sqlite';
$action = $_REQUEST['action'] ?? '';

// --- ХЕЛПЕРЫ ОТВЕТОВ ---

function responseSuccess(array $data = []): void {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function responseError(string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
    exit;
}

// --- ХЕЛПЕРЫ ДЛЯ РАБОТЫ С ФАЙЛАМИ И БАЗОЙ ---

function getAuthUser(string $dbPath) {
    $token = $_COOKIE['site_auth'] ?? '';
    if (empty($token) || !file_exists($dbPath)) return false;
    try {
        $db = new PDO("sqlite:" . $dbPath);
        $stmt = $db->prepare("SELECT user FROM users WHERE auth = ? AND auth != ''");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

// Универсальный резолвер пути к файлу
function resolveTargetRelPath(string $customFilePath, string $url, string $rootDir): string {
    if (!empty($customFilePath)) {
        return ltrim($customFilePath, '/');
    }
    $urlPath = parse_url($url, PHP_URL_PATH) ?? '';
    $urlPath = ltrim($urlPath, '/');

    if ($urlPath === '' || $urlPath === '/') {
        return 'index.html';
    }

    if (pathinfo($urlPath, PATHINFO_EXTENSION) === 'html') {
        return $urlPath;
    }

    $cleanPath = rtrim($urlPath, '/');
    if (file_exists($rootDir . '/' . $cleanPath . '.html')) {
        return $cleanPath . '.html';
    } elseif (file_exists($rootDir . '/' . $cleanPath . '/index.html')) {
        return $cleanPath . '/index.html';
    }

    return $cleanPath;
}

// Поиск и нормализация путей всех img.editable
function getEditableImagesPaths(Dom\HTMLDocument $doc, string $url, string $rootDir): array {
    $imageRelPaths = [];

    $siteDomain = parse_url($url, PHP_URL_HOST) ?? '';
    $siteScheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
    $siteBaseUrl = $siteDomain ? ($siteScheme . '://' . $siteDomain) : '';

    $images = $doc->querySelectorAll('img.editable');
    $docModified = false;

    foreach ($images as $img) {
        $src = trim($img->getAttribute('src') ?? '');
        if (!$src) continue;

        // Если в src зашит абсолютный URL текущего сайта — убираем домен, делаем относительным
        if ($siteBaseUrl && strpos($src, $siteBaseUrl) === 0) {
            $src = substr($src, strlen($siteBaseUrl));
            $src = ltrim($src, '/');
            $img->setAttribute('src', $src);
            $docModified = true;
        }

        // Пропускаем внешние абсолютные ссылки (http://, https://, //)
        if (preg_match('#^(https?:)?//#i', $src)) {
            continue;
        }

        $cleanRelPath = ltrim($src, '/');
        // Очищаем от возможных GET-параметров (?v=123)
        $cleanRelPath = parse_url($cleanRelPath, PHP_URL_PATH) ?? $cleanRelPath;

        $fullImgPath = $rootDir . '/' . $cleanRelPath;
        if (file_exists($fullImgPath) && is_file($fullImgPath)) {
            $imageRelPaths[$cleanRelPath] = $fullImgPath;
        }
    }

    // Если пришлось исправить абсолютные ссылки на относительные — сохраняем HTML
    if ($docModified) {
        $doc->saveHtmlFile($doc->uri);
    }

    return $imageRelPaths;
}

// Создание ZIP-ревизии (HTML + все редактируемые картинки)
function makeRevision(string $fullPath, string $targetRelPath, string $rootDir, string $url = ''): void {
    if (!file_exists($fullPath)) return;

    $lastModTime = @filemtime($fullPath) ?: time();
    $dateStr = date('Y-m-d_H-i-s', $lastModTime);

    $revParentDir = $rootDir . '/restricted/revisions/' . $targetRelPath;
    if (!is_dir($revParentDir)) {
        @mkdir($revParentDir, 0755, true);
    }

    $zipPath = $revParentDir . '/' . $dateStr . '.zip';
    if (file_exists($zipPath)) return;

    // Парсим HTML и собираем пути к редактируемым картинкам
    $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
    $imagePaths = getEditableImagesPaths($doc, $url, $rootDir);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return;
    }

    // 1. Пакуем сам HTML-файл
    $zip->addFile($fullPath, $targetRelPath);

    // 2. Пакуем все существующие img.editable с сохранением относительных путей
    foreach ($imagePaths as $relPath => $absPath) {
        $zip->addFile($absPath, $relPath);
    }

    $zip->close();

    // Ротация бэкапов (не более 10 zip-архивов)
    $zipFiles = @glob($revParentDir . '/*.zip');
    if (is_array($zipFiles) && count($zipFiles) > 10) {
        sort($zipFiles);
        while (count($zipFiles) > 10) {
            $oldestZip = array_shift($zipFiles);
            if (file_exists($oldestZip)) {
                @unlink($oldestZip);
            }
        }
    }
}

// Сканирование списка ZIP-ревизий
function getRevisionsList(string $targetRelPath, string $rootDir): array {
    $parentDir = $rootDir . '/restricted/revisions/' . $targetRelPath;
    if (!is_dir($parentDir)) return [];

    $files = @glob($parentDir . '/*.zip');
    if (!$files) return [];

    // Сортируем от новых к старым
    rsort($files);

    $list = [];
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $time = @filemtime($filePath) ?: time();

        $list[] = [
            'filename' => $filename,
            'date'     => date('d.m.Y H:i:s', $time)
        ];
    }
    return $list;
}

// --- МАРШРУТИЗАЦИЯ ---

switch ($action) {

    // 1. Инициализация бара + получение списка ревизий
    case 'init_bar':
    case 'check_auth':
        $user = getAuthUser($dbPath);
        if ($user) {
            $currentVersion = phpversion();
            $phpValid = version_compare($currentVersion, '8.4.0', '>=');
            
            $rootDir = realpath(__DIR__ . '/../');
            $customFilePath = trim($_REQUEST['filepath'] ?? '');
            $url = $_REQUEST['url'] ?? '';
            
            $targetRelPath = resolveTargetRelPath($customFilePath, $url, $rootDir);
            $revisions = getRevisionsList($targetRelPath, $rootDir);

            responseSuccess([
                'authorized'  => true,
                'user'        => $user['user'],
                'php_valid'   => $phpValid,
                'php_version' => $currentVersion,
                'revisions'   => $revisions
            ]);
        } else {
            responseSuccess(['authorized' => false]);
        }

    // 2. Выход
    case 'logout':
        setcookie('site_auth', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        responseSuccess();

    // 3. Сохранение изменений в HTML
    case 'save_page':
        $user = getAuthUser($dbPath);
        if (!$user) responseError('Доступ запрещен');

        if (version_compare(phpversion(), '8.4.0', '<')) {
            responseError('Требуется PHP 8.4+');
        }

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data) responseError('Неверный формат JSON');

        $rootDir = realpath(__DIR__ . '/../');
        $customFilePath = trim($data['filepath'] ?? '');
        $url = $data['url'] ?? '';

        $targetRelPath = resolveTargetRelPath($customFilePath, $url, $rootDir);
        $fullPath = $rootDir . '/' . $targetRelPath;
        $realFileDir = realpath(dirname($fullPath));

        if ($realFileDir === false || strpos($realFileDir, $rootDir) !== 0) {
            responseError('Попытка выхода за пределы корня');
        }

        if (!file_exists($fullPath)) {
            responseError('Файл не найден: ' . $targetRelPath);
        }

        $changes = $data['changes'] ?? [];
        if (empty($changes)) responseSuccess(['message' => 'Нет изменений']);

        try {
            // 1. Создаем ZIP-ревизию (HTML + изображения)
            makeRevision($fullPath, $targetRelPath, $rootDir, $url);

            // 2. Обновляем HTML-файл
            $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);

            foreach ($changes as $id => $payload) {
                $element = $doc->getElementById($id);
                if ($element) {
                    if (isset($payload['html'])) {
                        // Очищаем старые дочерние элементы
                        while ($element->firstChild) {
                            $element->removeChild($element->firstChild);
                        }

                        // Парсим новый HTML-фрагмент
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
                    } elseif (isset($payload['text'])) {
                        $element->textContent = $payload['text'];
                    }
                }
            }

            $doc->saveHtmlFile($fullPath);
            responseSuccess(['saved_file' => $targetRelPath]);

        } catch (Throwable $e) {
            responseError('Ошибка сохранения PHP: ' . $e->getMessage());
        }

    // 4. Откат к выбранной ZIP-ревизии
    case 'rollback_revision':
        $user = getAuthUser($dbPath);
        if (!$user) responseError('Доступ запрещен');

        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        $revisionFilename = basename($data['revision_file'] ?? '');
        $customFilePath = trim($data['filepath'] ?? '');
        $url = $data['url'] ?? '';

        if (!$revisionFilename) responseError('Не указан файл ревизии');

        $rootDir = realpath(__DIR__ . '/../');
        $targetRelPath = resolveTargetRelPath($customFilePath, $url, $rootDir);
        $fullPath = $rootDir . '/' . $targetRelPath;

        $revZipPath = $rootDir . '/restricted/revisions/' . $targetRelPath . '/' . $revisionFilename;

        if (!file_exists($revZipPath)) {
            responseError('Файл ревизии не найден: ' . $revisionFilename);
        }

        try {
            // 1. Создаем бэкап ТЕКУЩЕГО живого состояния перед откатом
            makeRevision($fullPath, $targetRelPath, $rootDir, $url);

            // 2. Находим картинки текущей живой версии
            $currentDoc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
            $currentImages = getEditableImagesPaths($currentDoc, $url, $rootDir);

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
                responseSuccess(['message' => 'Откат успешно выполнен']);
            } else {
                responseError('Не удалось открыть ZIP-архив ревизии');
            }
        } catch (Throwable $e) {
            responseError('Ошибка отката: ' . $e->getMessage());
        }

    default:
        responseError('Неизвестное действие');
}