<?php
header('Content-Type: application/json');

// --- НАСТРОЙКИ И ДЕБАГ ---
define('CMS_EXEC', true);
require_once __DIR__ . '/config.php';

$action = $_REQUEST['action'] ?? '';

// Логгер для отладки загрузки и конвертации изображений
function writeDebugLog(string $message): void {
    if (!defined('CMS_CONFIG') || !CMS_CONFIG['debug']) return;

    $debugDir = CMS_CONFIG['debug_dir'];
    if (!is_dir($debugDir)) {
        @mkdir($debugDir, 0755, true);
    }

    $logFile = $debugDir . '/uploads.txt';
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[{$timestamp}] {$message}\n";

    @file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

// Логгер для отладки создания и отката ревизий
function writeRevisionDebugLog(string $message): void {
    if (!defined('CMS_CONFIG') || !CMS_CONFIG['debug']) return;

    $debugDir = CMS_CONFIG['debug_dir'];
    if (!is_dir($debugDir)) {
        @mkdir($debugDir, 0755, true);
    }

    $logFile = $debugDir . '/revisions.txt';
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[{$timestamp}] {$message}\n";

    @file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

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

// Проверка и резолв локального физического пути картинки по её src
function resolveLocalImagePath(string $src, string $url, string $rootDir): ?string {
    $src = trim($src);
    if (!$src) return null;

    $siteDomain = parse_url($url, PHP_URL_HOST) ?? '';
    $siteScheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
    $siteBaseUrl = $siteDomain ? ($siteScheme . '://' . $siteDomain) : '';

    // Если в src зашит абсолютный URL текущего сайта — срезаем домен
    if ($siteBaseUrl && strpos($src, $siteBaseUrl) === 0) {
        $src = substr($src, strlen($siteBaseUrl));
    }

    // Если ссылка на сторонний ресурс — игнорируем
    if (preg_match('#^(https?:)?//#i', $src)) {
        return null;
    }

    $cleanRelPath = ltrim(parse_url($src, PHP_URL_PATH) ?? $src, '/');
    $fullPath = $rootDir . '/' . $cleanRelPath;

    if (file_exists($fullPath) && is_file($fullPath)) {
        return $fullPath;
    }

    return null;
}

// Конвертация картинок (png, jpg, jpeg) в WebP через Imagick или GD с полным логированием
function convertImageToWebp(string $filePath, string $outputWebpPath, string $origExt = '', ?int $quality = null, ?int $maxWidth = null, ?int $maxHeight = null): bool {
    @ini_set('memory_limit', '512M');

    $quality = $quality ?? CMS_CONFIG['images']['quality'] ?? 80;
    $maxWidth = $maxWidth ?? CMS_CONFIG['images']['max_width'] ?? 1920;
    $maxHeight = $maxHeight ?? CMS_CONFIG['images']['max_height'] ?? 1920;

    $ext = strtolower($origExt);
    if (!$ext || $ext === 'tmp') {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    }

    if (!$ext || $ext === 'tmp') {
        $imgInfo = @getimagesize($filePath);
        if ($imgInfo && isset($imgInfo['mime'])) {
            $mimeMap = [
                'image/jpeg' => 'jpg',
                'image/jpg'  => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];
            $ext = $mimeMap[$imgInfo['mime']] ?? '';
        }
    }

    $origSize = file_exists($filePath) ? filesize($filePath) : 0;
    writeDebugLog("Старт конвертации: '{$filePath}' (определён формат: {$ext}, размер: {$origSize} байт) -> '{$outputWebpPath}'");

    $converted = false;

    if (extension_loaded('imagick')) {
        writeDebugLog("Попытка обработки через расширение Imagick");
        try {
            $image = new Imagick($filePath);
            $origWidth = $image->getImageWidth();
            $origHeight = $image->getImageHeight();

            // Пропорциональный ресайз
            if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
                $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
                $newWidth = (int)($origWidth * $ratio);
                $newHeight = (int)($origHeight * $ratio);
                $image->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
                writeDebugLog("Imagick ресайз с {$origWidth}x{$origHeight} до {$newWidth}x{$newHeight}");
            }

            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);
            $converted = $image->writeImage($outputWebpPath);
            $image->destroy();

            writeDebugLog("Результат записи Imagick writeImage: " . ($converted ? "УСПЕШНО" : "ОШИБКА"));
        } catch (Throwable $e) {
            writeDebugLog("Imagick Exception: " . $e->getMessage());
            $converted = false;
        }
    } elseif (extension_loaded('gd')) {
        writeDebugLog("Попытка обработки через расширение GD");

        if (!function_exists('imagewebp')) {
            writeDebugLog("Ошибка GD: функция imagewebp() отсутствует в текущей сборке PHP GD");
            return false;
        }

        $image = null;
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $image = @imagecreatefromjpeg($filePath);
        } elseif ($ext === 'png') {
            $image = @imagecreatefrompng($filePath);
            if ($image && function_exists('imagepalettetotruecolor') && !imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }
        } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($filePath);
        }

        if (!$image) {
            writeDebugLog("Ошибка GD: Не удалось создать ресурс изображения из файла '{$filePath}' (формат: {$ext})");
            return false;
        }

        $origWidth = imagesx($image);
        $origHeight = imagesy($image);

        // Пропорциональный ресайз
        if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
            $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
            $newWidth = (int)($origWidth * $ratio);
            $newHeight = (int)($origHeight * $ratio);

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            if ($ext === 'png' || $ext === 'webp') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
            }
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($image);
            $image = $resizedImage;
            writeDebugLog("GD ресайз с {$origWidth}x{$origHeight} до {$newWidth}x{$newHeight}");
        }

        $converted = @imagewebp($image, $outputWebpPath, $quality);
        imagedestroy($image);
        writeDebugLog("Результат записи GD imagewebp: " . ($converted ? "УСПЕШНО" : "ОШИБКА"));
    } else {
        writeDebugLog("Ошибка: Ни Imagick, ни GD расширения не загружены на сервере");
        return false;
    }

    if ($converted && file_exists($outputWebpPath)) {
        $webpSize = filesize($outputWebpPath);

        if ($webpSize === 0) {
            writeDebugLog("Ошибка: Созданный файл WebP имеет размер 0 байт. Удаление файла.");
            @unlink($outputWebpPath);
            return false;
        }

        // Проверка keep_if_larger
        if ($webpSize > $origSize) {
            writeDebugLog("Условие keep_if_larger: Сконвертированный WebP ({$webpSize} байт) ВЕСИТ БОЛЬШЕ оригинала ({$origSize} байт). Отмена конвертации, возвращаем оригинал.");
            @unlink($outputWebpPath);
            return false;
        }

        writeDebugLog("Конвертация успешно завершена! Файл saved: '{$outputWebpPath}' (WebP: {$webpSize} байт vs Оригинал: {$origSize} байт)");
        return true;
    }

    writeDebugLog("Ошибка: Файл WebP '{$outputWebpPath}' не существует на диске после конвертации");
    return false;
}

// Поиск, нормализация путей в HTML (приведение к /assets/...) и сборка путей для ZIP
function getEditableImagesPaths(Dom\HTMLDocument $doc, string $url, string $rootDir, string $fullPath): array {
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
function makeRevision(string $fullPath, string $targetRelPath, string $rootDir, string $url = ''): void {
    if (!file_exists($fullPath)) return;

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
            responseError('Не удалось обновить имеющийся архив ревизии: ' . $dateStr . '.zip');
        }
    }

    $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
    $imagePaths = getEditableImagesPaths($doc, $url, $rootDir, $fullPath);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        responseError('Не удалось создать ZIP-архив ревизии');
    }

    // Добавляем HTML-файл по его относительному пути
    $zip->addFile($fullPath, ltrim($targetRelPath, '/'));

    // Добавляем все локальные картинки
    foreach ($imagePaths as $relPath => $absPath) {
        $zip->addFile($absPath, $relPath);
    }

    $zip->close();

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
    $parentDir = CMS_CONFIG['revisions_dir'] . '/' . $targetRelPath;
    if (!is_dir($parentDir)) return [];

    $files = @glob($parentDir . '/*.zip');
    if (!$files) return [];

    rsort($files);

    $list = [];
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

        $list[] = [
            'filename' => $filename,
            'date'     => $formattedDate
        ];
    }
    return $list;
}

// --- МАРШРУТИЗАЦИЯ ---

switch ($action) {

    // 1. Инициализация бара + проверка графических библиотек (Imagick/GD)
    case 'init_bar':
    case 'check_auth':
        $user = getAuthUser(CMS_CONFIG['db_path']);
        if ($user) {
            $currentVersion = phpversion();
            $phpValid = version_compare($currentVersion, '8.4.0', '>=');
            $imgLibraryValid = extension_loaded('imagick') || extension_loaded('gd');
            
            $rootDir = realpath(__DIR__ . '/../');
            $customFilePath = trim($_REQUEST['filepath'] ?? '');
            $url = $_REQUEST['url'] ?? '';
            
            $targetRelPath = resolveTargetRelPath($customFilePath, $url, $rootDir);
            $revisions = getRevisionsList($targetRelPath, $rootDir);

            responseSuccess([
                'authorized'        => true,
                'user'              => $user['user'],
                'php_valid'         => $phpValid,
                'php_version'       => $currentVersion,
                'img_library_valid' => $imgLibraryValid,
                'revisions'         => $revisions
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

    // 3. Загрузка ровно ОДНОГО изображения (Мгновенная запись в HTML каждого файла)
    case 'upload_single_image':
        $user = getAuthUser(CMS_CONFIG['db_path']);
        if (!$user) responseError('Доступ запрещен');

        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            responseError('Сервер не поддерживает Imagick или GD');
        }

        if (empty($_FILES['image']['tmp_name'])) {
            responseError('Файл изображения не получен');
        }

        $rootDir = realpath(__DIR__ . '/../');
        $customFilePath = trim($_POST['filepath'] ?? '');
        $url = $_POST['url'] ?? '';
        $targetId = trim($_POST['target_id'] ?? '');

        if (!$targetId) responseError('Не указан ID элемента изображения');

        $targetRelPath = resolveTargetRelPath($customFilePath, $url, $rootDir);
        $fullPath = $rootDir . '/' . $targetRelPath;

        if (!file_exists($fullPath)) {
            responseError('Файл страницы не найден: ' . $targetRelPath);
        }

        try {
            $uploadSubDir = CMS_CONFIG['images']['upload_dir'];
            $uploadsDir = $rootDir . '/' . $uploadSubDir;
            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0755, true);
            }

            $tmpFile = $_FILES['image']['tmp_name'];
            $origFullName = $_FILES['image']['name'];
            $origName = pathinfo($origFullName, PATHINFO_FILENAME);
            $origExt = strtolower(pathinfo($origFullName, PATHINFO_EXTENSION));
            $cleanFilename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $origName) ?: 'img';

            writeDebugLog("upload_single_image: Обработка '{$origFullName}' для элемента '#{$targetId}'");

            $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $format = 'webp';
            $candidateName = $cleanFilename;
            while (file_exists($uploadsDir . '/' . $candidateName . '.' . $format)) {
                $candidateName .= $chars[rand(0, strlen($chars) - 1)];
            }

            $finalFilename = $candidateName . '.' . $format;
            $outputFullPath = $uploadsDir . '/' . $finalFilename;
            $outputRelPath = $uploadSubDir . '/' . $finalFilename;
            $htmlSrc = '/' . $outputRelPath;

            $converted = convertImageToWebp($tmpFile, $outputFullPath, $origExt);

            if (!$converted) {
                $candidateName = $cleanFilename;
                while (file_exists($uploadsDir . '/' . $candidateName . '.' . $origExt)) {
                    $candidateName .= $chars[rand(0, strlen($chars) - 1)];
                }
                $finalFilename = $candidateName . '.' . $origExt;
                $outputFullPath = $uploadsDir . '/' . $finalFilename;
                $outputRelPath = $uploadSubDir . '/' . $finalFilename;
                $htmlSrc = '/' . $outputRelPath;
                @move_uploaded_file($tmpFile, $outputFullPath);
            }

            // Мгновенно обновляем HTML-файл на диске для этого конкретного изображения
            $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
            $imgElement = $doc->getElementById($targetId);

            if ($imgElement) {
                $oldSrc = trim($imgElement->getAttribute('src') ?? '');
                $oldLocalPath = resolveLocalImagePath($oldSrc, $url, $rootDir);

                $imgElement->setAttribute('src', $htmlSrc);

                // Если старая локальная картинка отличалась — удаляем её
                if ($oldLocalPath && realpath($oldLocalPath) !== realpath($outputFullPath)) {
                    if (@unlink($oldLocalPath)) {
                        writeDebugLog("Удалена старая заменённая картинка: '{$oldLocalPath}'");
                    }
                }

                $doc->saveHtmlFile($fullPath);
                responseSuccess(['relative_path' => $htmlSrc]);
            } else {
                responseError('Элемент #' . $targetId . ' не найден в HTML');
            }

        } catch (Throwable $e) {
            writeDebugLog("PHP Exception при upload_single_image: " . $e->getMessage());
            responseError('Ошибка загрузки: ' . $e->getMessage());
        }

    // 4. Сохранение текстовых изменений в HTML + Создание 1 РЕВИЗИИ
    case 'save_page':
        $user = getAuthUser(CMS_CONFIG['db_path']);
        if (!$user) responseError('Доступ запрещен');

        if (version_compare(phpversion(), '8.4.0', '<')) {
            responseError('Требуется PHP 8.4+');
        }

        $rootDir = realpath(__DIR__ . '/../');
        $customFilePath = trim($_POST['filepath'] ?? '');
        $url = $_POST['url'] ?? '';

        $targetRelPath = resolveTargetRelPath($customFilePath, $url, $rootDir);
        $fullPath = $rootDir . '/' . $targetRelPath;
        $realFileDir = realpath(dirname($fullPath));

        if ($realFileDir === false || strpos($realFileDir, $rootDir) !== 0) {
            responseError('Попытка выхода за пределы корня');
        }

        if (!file_exists($fullPath)) {
            responseError('Файл не найден: ' . $targetRelPath);
        }

        $changes = json_decode($_POST['changes'] ?? '{}', true) ?? [];

        try {
            writeRevisionDebugLog("save_page(): Клиент вызвал сохранение для '{$targetRelPath}'");

            // ШАГ 1: Создаем ровно 1 ZIP-ревизию ТЕКУЩЕГО живого состояния (HTML + старые картинки)
            makeRevision($fullPath, $targetRelPath, $rootDir, $url);

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

            responseSuccess(['saved_file' => $targetRelPath]);

        } catch (Throwable $e) {
            writeDebugLog("PHP Exception при сохранении save_page: " . $e->getMessage());
            responseError('Ошибка сохранения PHP: ' . $e->getMessage());
        }

    // 5. Откат к выбранной ZIP-ревизии
    case 'rollback_revision':
        $user = getAuthUser(CMS_CONFIG['db_path']);
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

        $revZipPath = CMS_CONFIG['revisions_dir'] . '/' . $targetRelPath . '/' . $revisionFilename;

        if (!file_exists($revZipPath)) {
            responseError('Файл ревизии не найден: ' . $revisionFilename);
        }

        try {
            // 1. Создаем бэкап ТЕКУЩЕГО живого состояния перед откатом
            makeRevision($fullPath, $targetRelPath, $rootDir, $url);

            // 2. Находим картинки текущей живой версии
            $currentDoc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
            $currentImages = getEditableImagesPaths($currentDoc, $url, $rootDir, $fullPath);

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

                responseSuccess(['message' => 'Откат успешно выполнен']);
            } else {
                responseError('Не удалось открыть ZIP-архив ревизии');
            }
        } catch (Throwable $e) {
            writeRevisionDebugLog("PHP Exception при откате ревизии: " . $e->getMessage());
            responseError('Ошибка отката: ' . $e->getMessage());
        }

    default:
        responseError('Неизвестное действие');
}