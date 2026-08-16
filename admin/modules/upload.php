<?php
class upload extends base {

    private $allowed_exts = ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'svg', 'webp', 'avif'];
    
    // Конвертация картинок в WebP (включая миниатюры) через Imagick или GD с полным логированием
    private function createWebpThumbnail(string $filePath, string $outputWebpPath, string $origExt = '', ?int $quality = null, ?int $targetWidth = null, ?int $maxHeight = null): bool {
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

        $this->log("Старт генерации WebP: '{$filePath}' (формат: {$ext}) -> '{$outputWebpPath}' (макс. ширина: {$maxWidth})", 'uploads.txt');

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
                $this->log("Imagick Exception: " . $e->getMessage(), 'uploads.txt');
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
            } elseif ($ext === 'bmp' && function_exists('imagecreatefrombmp')) {
                $image = @imagecreatefrombmp($filePath);
            } elseif ($ext === 'avif' && function_exists('imagecreatefromavif')) {
                $image = @imagecreatefromavif($filePath);
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

    public function upload_single_image() {
        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            $this->error('Сервер не поддерживает Imagick или GD');
        }

        if (empty($_FILES['image']['tmp_name'])) {
            $this->error('Файл изображения не получен');
        }

        $thumbSizes = CMS_CONFIG['images']['thumb_sizes'] ?? [600, 1200];
        $targetId = trim($_POST['target_id'] ?? '');

        if (!$targetId) $this->error('Не указан ID элемента изображения');

        if (!file_exists(paths::$file_full_path)) {
            $this->error('Файл страницы не найден: ' . paths::$file_rel_path);
        }

        try {
            if (!is_dir(paths::$upload_dir)) {
                @mkdir(paths::$upload_dir, 0755, true);
            }

            $tmpFile = $_FILES['image']['tmp_name'];
            $origFullName = $_FILES['image']['name'];
            $origName = pathinfo($origFullName, PATHINFO_FILENAME);
            $origExt = strtolower(pathinfo($origFullName, PATHINFO_EXTENSION));

            if (!in_array($origExt, $this->allowed_exts, true)) {
                $this->error('Неподдерживаемый формат файла: ' . $origExt);
            }

            // Юникод-фильтрация символов
            $cleanFilename = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $origName);
            $cleanFilename = trim(preg_replace('/_+/', '_', $cleanFilename), '_') ?: 'img';

            $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';

            // ОПРЕДЕЛЯЕМ РЕЖИМ: SVG и GIF заливаем напрямую (passthrough), остальное конвертируем в WebP
            $isPassthrough = in_array($origExt, ['svg', 'gif'], true);
            $format = $isPassthrough ? $origExt : 'webp';

            $candidateName = $cleanFilename;
            while (file_exists(paths::$upload_dir . '/' . $candidateName . '.' . $format)) {
                $candidateName .= $chars[rand(0, strlen($chars) - 1)];
            }

            $finalFilename = $candidateName . '.' . $format;
            $outputFullPath = paths::$upload_dir . '/' . $finalFilename;
            
            // Веб-путь относительного корня сайта для вставки в HTML src
            $htmlSrc = '/' . ltrim(str_replace(paths::$site_root_dir, '', $outputFullPath), '/\\');

            $srcSetEntries = [];

            if ($isPassthrough) {
                // ПРЯМАЯ ЗАГРУЗКА ДЛЯ GIF И SVG
                if (!@move_uploaded_file($tmpFile, $outputFullPath)) {
                    $this->error('Не удалось сохранить файл ' . $origExt);
                }
            } else {
                // РАСТРОВАЯ КОНВЕРТАЦИЯ В WEBP + МИНИАТЮРЫ
                @set_time_limit(120);
                @ini_set('memory_limit', '512M');

                $masterTmpPath = sys_get_temp_dir() . '/cms_master_' . md5(uniqid()) . '.webp';
                $masterCreated = $this->createWebpThumbnail($tmpFile, $masterTmpPath, $origExt, 100);

                if (!$masterCreated || !file_exists($masterTmpPath)) {
                    $this->log("Ошибка: Не удалось создать промежуточный мастер-файл из '{$origFullName}'.", 'uploads.txt');
                    $this->error('Не удалось обработать исходное изображение');
                }

                $targetQuality = (int)(CMS_CONFIG['images']['quality'] ?? 80);

                if ($targetQuality >= 100) {
                    $converted = @copy($masterTmpPath, $outputFullPath);
                } else {
                    $converted = $this->createWebpThumbnail($masterTmpPath, $outputFullPath, 'webp', $targetQuality);
                }

                if (!$converted || !file_exists($outputFullPath)) {
                    @unlink($masterTmpPath);
                    $this->error('Не удалось сконвертировать изображение в WebP');
                }

                // Проверка keep_if_larger
                if (CMS_CONFIG['images']['keep_if_larger']) {
                    $origSize = filesize($tmpFile);
                    $webpSize = filesize($outputFullPath);
        
                    if ($webpSize > $origSize) {
                        @unlink($outputFullPath);
                        $candidateName = $cleanFilename;
                        while (file_exists(paths::$upload_dir . '/' . $candidateName . '.' . $origExt)) {
                            $candidateName .= $chars[rand(0, strlen($chars) - 1)];
                        }
                        $finalFilename = $candidateName . '.' . $origExt;
                        $outputFullPath = paths::$upload_dir . '/' . $finalFilename;
                        $htmlSrc = '/' . ltrim(str_replace(paths::$site_root_dir, '', $outputFullPath), '/\\');
        
                        if (!@move_uploaded_file($tmpFile, $outputFullPath)) {
                            @unlink($masterTmpPath);
                            $this->error('Не удалось сохранить оригинальный файл изображения');
                        }
                    }
                }

                // Читаем ограничения тега <img>
                $docTemp = Dom\HTMLDocument::createFromFile(paths::$file_full_path, LIBXML_NOERROR);
                $imgElTemp = $docTemp->getElementById($targetId);

                $parseDim = function(?string $val): int {
                    if ($val === null) return 0;
                    $clean = strtolower(trim($val));
                    if ($clean === '' || $clean === 'auto' || $clean === '0') return 0;
                    return (int)$clean;
                };

                $minReqH = $imgElTemp ? $parseDim($imgElTemp->getAttribute('data-height')) : 0;

                // Нарезка миниатюр
                $thumbsDir = paths::$upload_dir . '/thumbs';
                if (!is_dir($thumbsDir)) {
                    @mkdir($thumbsDir, 0755, true);
                }

                $masterInfo = @getimagesize($masterTmpPath);
                $masterWidth = $masterInfo[0] ?? 0;
                $masterHeight = $masterInfo[1] ?? 0;

                if ($masterWidth > 0 && $masterHeight > 0) {
                    $baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);
                    $aspectRatio = $masterHeight / $masterWidth;

                    foreach ($thumbSizes as $w) {
                        if ($masterWidth <= $w) continue;

                        $calculatedH = (int)round($w * $aspectRatio);

                        if ($minReqH > 0 && $calculatedH < $minReqH) continue;

                        $thumbFullPath = $thumbsDir . '/' . $baseFilename . '-' . $w . '.webp';
                        $this->createWebpThumbnail($masterTmpPath, $thumbFullPath, 'webp', $targetQuality, $w);
                    }
                }

                @unlink($masterTmpPath);
            }

            // ОБНОВЛЕНИЕ DOM В HTML
            $doc = Dom\HTMLDocument::createFromFile(paths::$file_full_path, LIBXML_NOERROR);
            $imgElement = $doc->getElementById($targetId);

            if ($imgElement) {
                // 1. Извлекаем старые пути ДО изменения атрибутов для последующего точечного удаления
                $oldSrc = trim($imgElement->getAttribute('src') ?? '');
                $oldSrcSet = trim($imgElement->getAttribute('srcset') ?? '');
                $oldFilesToDelete = [];

                if ($oldSrc) {
                    $p = $this->resolve_local_image_path($oldSrc);
                    if ($p) $oldFilesToDelete[] = $p;
                }

                if ($oldSrcSet) {
                    $srcSetEntries = explode(',', $oldSrcSet);
                    foreach ($srcSetEntries as $entry) {
                        $parts = preg_split('/\s+/', trim($entry));
                        if (!empty($parts[0])) {
                            $p = $this->resolve_local_image_path($parts[0]);
                            if ($p) $oldFilesToDelete[] = $p;
                        }
                    }
                }
                $oldFilesToDelete = array_unique($oldFilesToDelete);

                // 2. Устанавливаем новый src
                $imgElement->setAttribute('src', $htmlSrc);

                // 3. Собираем новый srcset ТОЛЬКО если это не SVG/GIF
                $newSrcSetEntries = [];
                if (!$isPassthrough) {
                    $parseDim = function(?string $val): int {
                        if ($val === null) return 0;
                        $clean = strtolower(trim($val));
                        if ($clean === '' || $clean === 'auto' || $clean === '0') return 0;
                        return (int)$clean;
                    };

                    $minReqW = $parseDim($imgElement->getAttribute('data-width'));
                    $minReqH = $parseDim($imgElement->getAttribute('data-height'));

                    $baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);
                    $aspectRatio = (isset($masterWidth) && $masterWidth > 0) ? ($masterHeight / $masterWidth) : 0;
                    $thumbsDir = paths::$upload_dir . '/thumbs';

                    foreach ($thumbSizes as $w) {
                        $thumbFullPath = $thumbsDir . '/' . $baseFilename . '-' . $w . '.webp';

                        if (file_exists($thumbFullPath)) {
                            $calculatedH = (int)round($w * $aspectRatio);

                            if ($minReqW > 0 && $w < $minReqW) continue;
                            if ($minReqH > 0 && $calculatedH < $minReqH) continue;

                            $thumbWebUrl = '/' . ltrim(str_replace(paths::$site_root_dir, '', $thumbFullPath), '/\\');
                            $newSrcSetEntries[] = $thumbWebUrl . " {$w}w";
                        }
                    }

                    $mainImgInfo = @getimagesize($outputFullPath);
                    if ($mainImgInfo && !empty($mainImgInfo[0])) {
                        $mainWidth = $mainImgInfo[0];
                        $newSrcSetEntries[] = $htmlSrc . " {$mainWidth}w";
                    }

                    if (!empty($newSrcSetEntries)) {
                        $imgElement->setAttribute('srcset', implode(', ', $newSrcSetEntries));
                        $imgElement->setAttribute('sizes', 'auto');
                    }
                    $imgElement->setAttribute('loading', 'lazy');
                } else {
                    // Для SVG и GIF очищаем srcset и sizes
                    $imgElement->removeAttribute('srcset');
                    $imgElement->removeAttribute('sizes');
                }

                // 4. Безопасное удаление старых файлов (удаляем строго то, что было прописано в теге до загрузки)
                foreach ($oldFilesToDelete as $oldFilePath) {
                    if ($oldFilePath && $oldFilePath !== $outputFullPath && file_exists($oldFilePath)) {
                        @unlink($oldFilePath);
                        $this->log("Удален старый заменённый файл из src/srcset: '{$oldFilePath}'", 'uploads.txt');
                    }
                }

                $doc->saveHtmlFile(paths::$file_full_path);
                
                $this->success([
                    'relative_path' => $htmlSrc,
                    'srcset'        => implode(', ', $newSrcSetEntries)
                ]);
            } else {
                $this->error('Элемент #' . $targetId . ' не найден в HTML');
            }

        } catch (Throwable $e) {
            $this->log("PHP Exception при upload_single_image: " . $e->getMessage(), 'uploads.txt');
            $this->error('Ошибка загрузки: ' . $e->getMessage());
        }
    }

    // Проверка и резолв локального физического пути картинки по её src (для последующего удаления с диска)
    private function resolve_local_image_path(string $src): ?string {
        $src = trim($src);
        if (!$src) return null;

        $url = $_POST['url'] ?? '';
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

        $cleanRelPath = ltrim(parse_url($src, PHP_URL_PATH) ?? $src, '/\\');
        $image_full_path = paths::$site_root_dir . '/' . $cleanRelPath;

        if (file_exists($image_full_path) && is_file($image_full_path)) {
            return $image_full_path;
        }

        return null;
    }
}