<?php
class upload extends base {

	private $allowed_exts = ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'svg', 'webp', 'avif'];

	public function upload_single_image() {
		if (!extension_loaded('imagick') && !extension_loaded('gd')) {
			$this->error('Сервер не поддерживает Imagick или GD');
		}

		if (empty($_FILES['image']['tmp_name'])) {
			$this->error('Файл изображения не получен');
		}

		$targetId = trim($_POST['target_id'] ?? '');

		if (!$targetId)
			$this->error('Не указан ID элемента изображения');

		if (!file_exists(paths::$file_full_path)) {
			$this->error('Файл страницы не найден: ' . paths::$file_rel_path);
		}

		// Читаем HTML и находим элемент в самом начале
		$doc = Dom\HTMLDocument::createFromFile(paths::$file_full_path, LIBXML_NOERROR);
		$imgElement = $doc ? $doc->getElementById($targetId) : null;

		if (!$imgElement) {
			$this->error('Элемент #' . $targetId . ' не найден в HTML');
		}

		// Проверяем ограничение по минимальной высоте из атрибута data-min-height
		$minReqH = 0;
		if ($imgElement->hasAttribute('data-min-height')) {
			$minReqH = (int) preg_replace('/[^\d]/', '', $imgElement->getAttribute('data-min-height'));
		}

		try {
			if (!is_dir(paths::$upload_dir)) {
				@mkdir(paths::$upload_dir, 2755, true);
			}

			$tmpFile = $_FILES['image']['tmp_name'];
			$origFullName = $_FILES['image']['name'];
			$origName = pathinfo($origFullName, PATHINFO_FILENAME);
			$origExt = strtolower(pathinfo($origFullName, PATHINFO_EXTENSION));

			if (!in_array($origExt, $this->allowed_exts, true)) {
				$this->error('Неподдерживаемый формат файла: ' . $origExt);
			}

			// Юникод-фильтрация символов для имени файла
			$cleanFilename = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $origName);
			$cleanFilename = trim(preg_replace('/_+/', '_', $cleanFilename), '_') ?: 'img';

			$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';

			// ОПРЕДЕЛЯЕМ РЕЖИМ: SVG и GIF заливаем напрямую, остальное обрабатываем в WebP
			$isPassthrough = in_array($origExt, ['svg', 'gif'], true);
			$format = $isPassthrough ? $origExt : 'webp';

			$candidateName = $cleanFilename;
			while (file_exists(paths::$upload_dir . '/' . $candidateName . '.' . $format)) {
				$candidateName .= $chars[rand(0, strlen($chars) - 1)];
			}

			$finalFilename = $candidateName . '.' . $format;
			$outputFullPath = paths::$upload_dir . '/' . $finalFilename;
			// Веб-ссылка для вставки в HTML src
			$htmlSrc = $this->abs_path_to_web_url($outputFullPath);

			if ($isPassthrough) {
				// ПРЯМАЯ ЗАГРУЗКА ДЛЯ GIF И SVG
				if (!@move_uploaded_file($tmpFile, $outputFullPath)) {
					$this->error('Не удалось сохранить файл ' . $origExt);
				}
			} else {
				// РАСТРОВАЯ ОБРАБОТКА В ОДИН ПРОХОД В ПАМЯТИ
				@set_time_limit(120);

				if (extension_loaded('imagick')) {
					$this->process_imagick($tmpFile, $outputFullPath, $finalFilename, $minReqH);
				} elseif (extension_loaded('gd')) {
					$this->process_gd($tmpFile, $outputFullPath, $origExt, $finalFilename, $minReqH);
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
						$htmlSrc = $this->abs_path_to_web_url($outputFullPath);

						if (!@move_uploaded_file($tmpFile, $outputFullPath)) {
							$this->error('Не удалось сохранить оригинальный файл изображения');
						}
					}
				}
			}

			// ОБНОВЛЕНИЕ DOM В HTML
			// 1. Извлекаем старые пути ДО изменения для последующего удаления с диска
			$oldSrc = trim($imgElement->getAttribute('src') ?? '');
			$oldSrcSet = trim($imgElement->getAttribute('srcset') ?? '');
			$oldFilesToDelete = [];

			if ($oldSrc) {
				$p = $this->resolve_local_image_path($oldSrc);
				if ($p)
					$oldFilesToDelete[] = $p;
			}

			if ($oldSrcSet) {
				$srcSetEntries = explode(',', $oldSrcSet);
				foreach ($srcSetEntries as $entry) {
					$parts = preg_split('/\s+/', trim($entry));
					if (!empty($parts[0])) {
						$p = $this->resolve_local_image_path($parts[0]);
						if ($p)
							$oldFilesToDelete[] = $p;
					}
				}
			}
			$oldFilesToDelete = array_unique($oldFilesToDelete);

			// 2. Устанавливаем новый src
			$imgElement->setAttribute('src', $htmlSrc);

			// 3. Собираем новый srcset ТОЛЬКО если это не SVG/GIF
			$newSrcSetEntries = [];
			if (!$isPassthrough) {
				$baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);
				$thumbsDir = paths::$upload_dir . '/thumbs';

				foreach ((CMS_CONFIG['images']['thumb_sizes'] ?? [600, 1200]) as $w) {
					$thumbFullPath = $thumbsDir . '/' . $baseFilename . '-' . $w . '.webp';

					if (file_exists($thumbFullPath)) {
						$thumbWebUrl = $this->abs_path_to_web_url($thumbFullPath);
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
				$imgElement->removeAttribute('srcset');
				$imgElement->removeAttribute('sizes');
			}

			// 4. Безопасное удаление старых замененных файлов
			foreach ($oldFilesToDelete as $oldFilePath) {
				if ($oldFilePath && $oldFilePath !== $outputFullPath && file_exists($oldFilePath)) {
					@unlink($oldFilePath);
					$this->log("Удален старый заменённый файл из src/srcset: '{$oldFilePath}'", 'uploads.txt');
				}
			}

			// Сохраняем обновленный HTML файл
			$doc->saveHtmlFile(paths::$file_full_path);

			$this->success([
				'relative_path' => $htmlSrc,
				'srcset' => implode(', ', $newSrcSetEntries)
			]);

		} catch (Throwable $e) {
			$this->log("PHP Exception при upload_single_image: " . $e->getMessage(), 'uploads.txt');
			$this->error('Ошибка загрузки: ' . $e->getMessage());
		}
	}

	// Обработка через GD
	private function process_gd(string $sourcePath, string $mainOutputPath, string $origExt, string $finalFilename, int $minReqH = 0): void {

		$maxWidth = (int) (CMS_CONFIG['images']['max_width'] ?? 1920);
		$maxHeight = (int) (CMS_CONFIG['images']['max_height'] ?? 1920);

		$srcImage = match ($origExt) {
			'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
			'png' => @imagecreatefrompng($sourcePath),
			'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : null,
			'bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($sourcePath) : null,
			'avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : null,
			default => null
		};

		if (!$srcImage) {
			$this->error('Неразрешённый формат файла: *.' . $origExt);
		}

		$origW = imagesx($srcImage);
		$origH = imagesy($srcImage);

		// 1. Уменьшаем до лимитов max_width / max_height
		if ($origW > $maxWidth || $origH > $maxHeight) {
			$ratio = min($maxWidth / $origW, $maxHeight / $origH);
			$newW = (int) round($origW * $ratio);
			$newH = (int) round($origH * $ratio);

			$masterImage = imagecreatetruecolor($newW, $newH);
			if (in_array($origExt, ['png', 'webp'], true)) {
				imagealphablending($masterImage, false);
				imagesavealpha($masterImage, true);
			}
			imagecopyresampled($masterImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

			unset($srcImage);
		} else {
			$masterImage = $srcImage;
		}

		// 2. Сохраняем основной WebP файл
		$saved = @imagewebp($masterImage, $mainOutputPath, (int) (CMS_CONFIG['images']['quality'] ?? 80));
		if (!$saved) {
			unset($masterImage);
			gc_collect_cycles();
			$this->error('Ошибка при сохранении WebP-файла.');
		}

		// 3. Нарезаем миниатюры прямо из $masterImage
		$masterW = imagesx($masterImage);
		$masterH = imagesy($masterImage);
		$baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);
		$thumbsDir = paths::$upload_dir . '/thumbs';

		if (!is_dir($thumbsDir)) {
			@mkdir($thumbsDir, 2755, true);
		}

		foreach ((CMS_CONFIG['images']['thumb_sizes'] ?? [600, 1200]) as $tw) {
			if ($masterW <= $tw)
				continue;

			$ratio = $tw / $masterW;
			$thH = (int) round($masterH * $ratio);

			// Проверка минимальной высоты для тега <img>
			if ($minReqH > 0 && $thH < $minReqH)
				continue;

			$thumbImage = imagecreatetruecolor($tw, $thH);
			imagealphablending($thumbImage, false);
			imagesavealpha($thumbImage, true);
			imagecopyresampled($thumbImage, $masterImage, 0, 0, 0, 0, $tw, $thH, $masterW, $masterH);

			$thumbPath = $thumbsDir . '/' . $baseFilename . '-' . $tw . '.webp';
			@imagewebp($thumbImage, $thumbPath, (int) (CMS_CONFIG['images']['quality'] ?? 80));

			unset($thumbImage);
		}

		unset($masterImage);
		gc_collect_cycles();
	}

	// Обработка через Imagick
	private function process_imagick(string $sourcePath, string $mainOutputPath, string $finalFilename, int $minReqH = 0): void {
		try {
			$maxWidth = (int) (CMS_CONFIG['images']['max_width'] ?? 1920);
			$maxHeight = (int) (CMS_CONFIG['images']['max_height'] ?? 1920);

			$image = new Imagick($sourcePath);
			$origW = $image->getImageWidth();
			$origH = $image->getImageHeight();

			if ($origW > $maxWidth || $origH > $maxHeight) {
				$ratio = min($maxWidth / $origW, $maxHeight / $origH);
				$image->resizeImage((int) round($origW * $ratio), (int) round($origH * $ratio), Imagick::FILTER_LANCZOS, 1);
			}

			$image->setImageFormat('webp');
			$image->setImageCompressionQuality((int) (CMS_CONFIG['images']['quality'] ?? 80));
			$image->writeImage($mainOutputPath);

			// Нарезка миниатюр
			$masterW = $image->getImageWidth();
			$masterH = $image->getImageHeight();
			$baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);
			$thumbsDir = paths::$upload_dir . '/thumbs';

			if (!is_dir($thumbsDir)) {
				@mkdir($thumbsDir, 2755, true);
			}

			foreach ((CMS_CONFIG['images']['thumb_sizes'] ?? [600, 1200]) as $tw) {
				if ($masterW <= $tw)
					continue;

				$ratio = $tw / $masterW;
				$thH = (int) round($masterH * $ratio);

				// Проверка минимальной высоты для тега <img>
				if ($minReqH > 0 && $thH < $minReqH)
					continue;

				$thumb = clone $image;
				$thumb->resizeImage($tw, $thH, Imagick::FILTER_LANCZOS, 1);

				$thumbPath = $thumbsDir . '/' . $baseFilename . '-' . $tw . '.webp';
				$thumb->writeImage($thumbPath);

				$thumb->clear();
				$thumb->destroy();
				unset($thumb);
			}

			$image->clear();
			$image->destroy();
			unset($image);

			if (!file_exists($mainOutputPath)) {
				$this->error('Файл не загружен: ' . $mainOutputPath);
			}
		} catch (Throwable $e) {
			$this->error("Imagick Exception: " . $e->getMessage());
		}
	}

	// ХЕЛПЕР 1: Превращает абсолютный системный путь в веб-URL
	private function abs_path_to_web_url(string $absPath): string {
		$relPath = ltrim(str_replace(paths::$site_root_dir, '', $absPath), '/\\');
		return '/' . str_replace('\\', '/', $relPath);
	}

	// ХЕЛПЕР 2: Переводит любой URL в локальный относительный путь файла от корня
	private function url_to_local_rel_path(string $url): ?string {
		$url = trim($url);
		if (!$url)
			return null;

		$targetHost = parse_url($url, PHP_URL_HOST);

		// Если в URL указан домен (например, http://site.com/img.jpg или //site.com/img.jpg)
		if ($targetHost !== null) {
			$currentHost = parse_url(paths::$post_url, PHP_URL_HOST);

			// Если хосты не совпадают без учета регистра и протокола — это сторонний сайт (Unsplash и т.д.)
			if ($currentHost && strcasecmp($targetHost, $currentHost) !== 0) {
				return null;
			}
		}

		// Извлекаем путь из URL (без хоста, схемы и GET-параметров ?v=123)
		$path = parse_url($url, PHP_URL_PATH);
		if (!$path)
			return null;

		return ltrim($path, '/\\');
	}

	// ХЕЛПЕР 3: Проверка и резолв локального физического пути картинки по её src
	private function resolve_local_image_path(string $src): ?string {
		$cleanRelPath = $this->url_to_local_rel_path($src);
		if (!$cleanRelPath)
			return null;

		$fullPath = paths::$site_root_dir . '/' . $cleanRelPath;

		if (file_exists($fullPath) && is_file($fullPath)) {
			return $fullPath;
		}

		return null;
	}
}