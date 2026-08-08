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

// Конвертация картинок в WebP (включая миниатюры) через Imagick или GD с полным логированием
function createWebpThumbnail(string $filePath, string $outputWebpPath, string $origExt = '', ?int $quality = null, ?int $targetWidth = null, ?int $maxHeight = null): bool {
    $quality = $quality ?? CMS_CONFIG['images']['quality'] ?? 80;
    $maxWidth = $targetWidth ?? CMS_CONFIG['images']['max_width'] ?? 1920;
    // Если передана точная ширина для миниатюры, снимаем ограничение по высоте (99999), чтобы пропорции не резались
    $maxHeight = $maxHeight ?? ($targetWidth ? 99999 : (CMS_CONFIG['images']['max_height'] ?? 1920));

    $ext = strtolower($origExt) ?: strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

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

    writeDebugLog("Старт генерации WebP: '{$filePath}' (формат: {$ext}) -> '{$outputWebpPath}' (макс. ширина: {$maxWidth})");

    if (extension_loaded('imagick')) {
        try {
            $image = new Imagick($filePath);
            $origWidth = $image->getImageWidth();
            $origHeight = $image->getImageHeight();

            // Пропорциональный ресайз
            if ($origWidth > $maxWidth || $origHeight > $maxHeight) {
                $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
                $image->resizeImage((int)($origWidth * $ratio), (int)($origHeight * $ratio), Imagick::FILTER_LANCZOS, 1);
            }

            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);
            $converted = $image->writeImage($outputWebpPath);
            $image->destroy();

            return $converted && file_exists($outputWebpPath) && filesize($outputWebpPath) > 0;
        } catch (Throwable $e) {
            writeDebugLog("Imagick Exception: " . $e->getMessage());
            return false;
        }
    } elseif (extension_loaded('gd')) {
        if (!function_exists('imagewebp')) return false;

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

        if (!$image) return false;

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
        }

        $converted = @imagewebp($image, $outputWebpPath, $quality);
        imagedestroy($image);

        return $converted && file_exists($outputWebpPath) && filesize($outputWebpPath) > 0;
    }

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

    $maxRevisions = (int)(CMS_CONFIG['max_revisions'] ?? 10);
    if ($maxRevisions <= 0) {
        writeRevisionDebugLog("makeRevision(): Создание ревизий отключено (max_revisions = {$maxRevisions})");
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

        $thumbSizes = [400, 600, 800, 1024, 1200];

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
            // Создаем ZIP-ревизию (если еще не создана)
            makeRevision($fullPath, $targetRelPath, $rootDir, $url);

            $uploadSubDir = CMS_CONFIG['images']['upload_dir'];
            $uploadsDir = $rootDir . '/' . $uploadSubDir;
            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0755, true);
            }

            $tmpFile = $_FILES['image']['tmp_name'];
            $origFullName = $_FILES['image']['name'];
            $origName = pathinfo($origFullName, PATHINFO_FILENAME);
            $origExt = strtolower(pathinfo($origFullName, PATHINFO_EXTENSION));

            // Юникод-фильтрация символов
            $cleanFilename = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $origName);
            $cleanFilename = trim(preg_replace('/_+/', '_', $cleanFilename), '_') ?: 'img';

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

            // Увеличиваем таймаут и память на случай тяжелых исходников
            @set_time_limit(120);
            @ini_set('memory_limit', '512M');

            // 1. Создаем промежуточный "Мастер-файл" в системном темпе (Качество 100, макс. 1920px)
            $masterTmpPath = sys_get_temp_dir() . '/cms_master_' . md5(uniqid()) . '.webp';
            $masterCreated = createWebpThumbnail($tmpFile, $masterTmpPath, $origExt, 100);

            if (!$masterCreated || !file_exists($masterTmpPath)) {
                writeDebugLog("Ошибка: Не удалось создать промежуточный мастер-файл из '{$origFullName}'.");
                responseError('Не удалось обработать исходное изображение');
            }
    
            // 2. Быстро создаем основной файл для uploads/ из Мастер-файла
            $targetQuality = (int)(CMS_CONFIG['images']['quality'] ?? 80);

            if ($targetQuality >= 100) {
                // Если качество 100%, мастер-файл уже идеально подходит — просто копируем его!
                $converted = @copy($masterTmpPath, $outputFullPath);
            } else {
                // Иначе сжимаем мастер-файл до нужного качества (например, 80%)
                $converted = createWebpThumbnail($masterTmpPath, $outputFullPath, 'webp', $targetQuality);
            }

            if (!$converted || !file_exists($outputFullPath)) {
                @unlink($masterTmpPath);
                writeDebugLog("Ошибка: Конвертация мастер-файла '{$origFullName}' в целевой WebP завершилась неудачей.");
                responseError('Не удалось сконвертировать изображение в WebP');
            }

            // Проверка keep_if_larger: если финальный WebP весит больше исходного файла
            if (CMS_CONFIG['images']['keep_if_larger']) {
                $origSize = filesize($tmpFile);
                $webpSize = filesize($outputFullPath);
    
                if ($webpSize > $origSize) {
                    writeDebugLog("keep_if_larger: WebP ({$webpSize} байт) больше оригинала ({$origSize} байт). Отмена WebP.");
                    @unlink($outputFullPath);
    
                    $candidateName = $cleanFilename;
                    while (file_exists($uploadsDir . '/' . $candidateName . '.' . $origExt)) {
                        $candidateName .= $chars[rand(0, strlen($chars) - 1)];
                    }
    
                    $finalFilename = $candidateName . '.' . $origExt;
                    $outputFullPath = $uploadsDir . '/' . $finalFilename;
                    $outputRelPath = $uploadSubDir . '/' . $finalFilename;
                    $htmlSrc = '/' . $outputRelPath;
    
                    if (!@move_uploaded_file($tmpFile, $outputFullPath)) {
                        @unlink($masterTmpPath);
                        responseError('Не удалось сохранить файл изображения');
                    }
                }
            }

            // 3. Быстрая генерация миниатюр ИЗ МАСТЕР-ФАЙЛА
            $thumbsDir = $uploadsDir . '/thumbs';
            if (!is_dir($thumbsDir)) {
                @mkdir($thumbsDir, 0755, true);
            }

            // Размеры смотрим уже у легкого Мастер-файла
            $masterInfo = @getimagesize($masterTmpPath);
            $masterWidth = $masterInfo[0] ?? 0;

            if ($masterWidth > 0) {
                $baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);

                foreach ($thumbSizes as $w) {
                    if ($masterWidth <= $w) {
                        continue;
                    }

                    $thumbFullPath = $thumbsDir . '/' . $baseFilename . '-' . $w . '.webp';

                    // Мгновенная нарезка из уже уменьшенного 1920px мастера
                    createWebpThumbnail($masterTmpPath, $thumbFullPath, 'webp', $targetQuality, $w);
                }
            }

            // 4. Удаляем временный Мастер-файл из системы
            @unlink($masterTmpPath);

            // Обновляем HTML в DOM и удаляем старую картинку
            $doc = Dom\HTMLDocument::createFromFile($fullPath, LIBXML_NOERROR);
            $imgElement = $doc->getElementById($targetId);

            if ($imgElement) {
                $oldSrc = trim($imgElement->getAttribute('src') ?? '');
                $oldLocalPath = resolveLocalImagePath($oldSrc, $url, $rootDir);

                // 1. Проверяем реально созданные миниатюры и собираем srcset
                $srcSetEntries = [];
                $baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);

                foreach ($thumbSizes as $w) {
                    $thumbRelPath = $uploadSubDir . '/thumbs/' . $baseFilename . '-' . $w . '.webp';
                    $thumbAbsPath = $rootDir . '/' . $thumbRelPath;

                    if (file_exists($thumbAbsPath)) {
                        $srcSetEntries[] = '/' . ltrim($thumbRelPath, '/') . " {$w}w";
                    }
                }

                // 2. Добавляем основное (загруженное) изображение в srcset как максимальную версию
                $mainImgInfo = @getimagesize($outputFullPath);
                if ($mainImgInfo && !empty($mainImgInfo[0])) {
                    $mainWidth = $mainImgInfo[0];
                    $srcSetEntries[] = $htmlSrc . " {$mainWidth}w";
                }

                // 3. Обновляем атрибуты тега <img>
                $imgElement->setAttribute('src', $htmlSrc);

                if (!empty($srcSetEntries)) {
                    $imgElement->setAttribute('srcset', implode(', ', $srcSetEntries));
                    $imgElement->setAttribute('sizes', 'auto');
                }

                $imgElement->setAttribute('loading', 'lazy');

                // 4. Если старая локальная картинка существовала — удаляем её и её миниатюры
                if ($oldLocalPath && $oldLocalPath !== $outputFullPath) {
                    @unlink($oldLocalPath);
                    writeDebugLog("Удалена заменённая старая картинка: '{$oldLocalPath}'");

                    $oldDir = dirname($oldLocalPath);
                    $oldBaseName = pathinfo($oldLocalPath, PATHINFO_FILENAME);

                    foreach ($thumbSizes as $w) {
                        $oldThumbPath = $oldDir . '/thumbs/' . $oldBaseName . '-' . $w . '.webp';
                        if (file_exists($oldThumbPath)) {
                            @unlink($oldThumbPath);
                        }
                    }
                }

                $doc->saveHtmlFile($fullPath);
                
                responseSuccess([
                    'relative_path' => $htmlSrc,
                    'srcset'        => implode(', ', $srcSetEntries)
                ]);
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