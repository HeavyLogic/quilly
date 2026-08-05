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

// Бэкап файла перед изменением
function makeRevision(string $fullPath, string $targetRelPath, string $rootDir): void {
    if (!file_exists($fullPath)) return;

    $lastModTime = @filemtime($fullPath);
    if (!$lastModTime) $lastModTime = time();

    $dateStr = date('Y-m-d_H-i-s', $lastModTime);
    $revDir = $rootDir . '/restricted/revisions/' . $targetRelPath;

    if (!is_dir($revDir)) {
        @mkdir($revDir, 0755, true);
    }

    $ext = pathinfo($targetRelPath, PATHINFO_EXTENSION);
    if (!$ext) $ext = 'html';

    $backupFilePath = $revDir . '/' . $dateStr . '.' . $ext;

    if (!file_exists($backupFilePath)) {
        @copy($fullPath, $backupFilePath);
    }

    $files = @glob($revDir . '/*.' . $ext);
    if (is_array($files) && count($files) > 10) {
        sort($files);
        while (count($files) > 10) {
            $oldestFile = array_shift($files);
            if (file_exists($oldestFile)) {
                @unlink($oldestFile);
            }
        }
    }
}

// Сканирование списка ревизий
function getRevisionsList(string $targetRelPath, string $rootDir): array {
    $revDir = $rootDir . '/restricted/revisions/' . $targetRelPath;
    if (!is_dir($revDir)) return [];

    $ext = pathinfo($targetRelPath, PATHINFO_EXTENSION) ?: 'html';
    $files = @glob($revDir . '/*.' . $ext);
    if (!$files) return [];

    // Сортируем от новых к старым
    rsort($files);

    $list = [];
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $time = @filemtime($filePath);
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
            makeRevision($fullPath, $targetRelPath, $rootDir);

            $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);

            foreach ($changes as $id => $payload) {
                $element = $doc->getElementById($id);
                if ($element) {
                    if (isset($payload['html'])) {
                        // 1. Очищаем старые дочерние элементы
                        while ($element->firstChild) {
                            $element->removeChild($element->firstChild);
                        }

                        // 2. Парсим новый HTML-фрагмент
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

    // 4. Откат к выбранной ревизии
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

        $revFilePath = $rootDir . '/restricted/revisions/' . $targetRelPath . '/' . $revisionFilename;

        if (!file_exists($revFilePath)) {
            responseError('Файл ревизии не найден');
        }

        try {
            // 1. Бэкапим текущее состояние перед откатом
            makeRevision($fullPath, $targetRelPath, $rootDir);

            // 2. Переносим (rename) ревизию на место текущего живого файла
            if (!rename($revFilePath, $fullPath)) {
                responseError('Не удалось применить файл ревизии');
            }

            responseSuccess(['message' => 'Откат успешно выполнен']);
        } catch (Throwable $e) {
            responseError('Ошибка отката: ' . $e->getMessage());
        }

    default:
        responseError('Неизвестное действие');
}