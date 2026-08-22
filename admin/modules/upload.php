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

		// Проверяем ограничение по минимальной высоте из атрибута data-height
		$minReqH = 0;
		if ($imgElement->hasAttribute('data-height')) {
			$minReqH = (int) preg_replace('/[^\d]/', '', $imgElement->getAttribute('data-height'));
		}

		try {
			paths::make_dir(paths::$upload_dir);

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
			// 1. Старые локальные файлы (src + все картинки из srcset) - собираем ДО замены атрибутов
			$oldFilesToDelete = array_values(paths::resolve_local_images([$imgElement]));

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

		paths::make_dir($thumbsDir);

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

			paths::make_dir($thumbsDir);

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
}