<?php
class revisions extends base {

	private static $revisions_count = 0;

	// Поиск, нормализация путей в HTML (приведение к /assets/...) и сборка путей для ZIP
	private function getEditableImagesPaths(Dom\HTMLDocument $doc): array {
		$imageRelPaths = [];

		$siteDomain = parse_url(paths::$post_url, PHP_URL_HOST) ?? '';
		$siteScheme = parse_url(paths::$post_url, PHP_URL_SCHEME) ?? 'http';
		$siteBaseUrl = $siteDomain ? ($siteScheme . '://' . $siteDomain) : '';

		$images = $doc->querySelectorAll('img.editable');
		$docModified = false;

		foreach ($images as $img) {
			$src = trim($img->getAttribute('src') ?? '');
			if (!$src)
				continue;

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
			$fullImgPath = paths::$site_root_dir . '/' . $zipRelPath;

			if (file_exists($fullImgPath) && is_file($fullImgPath)) {
				$imageRelPaths[$zipRelPath] = $fullImgPath;
			}
		}

		// Сохраняем измененные корневые пути в HTML перед архивацией
		if ($docModified) {
			$doc->saveHtmlFile(paths::$file_full_path);
		}

		return $imageRelPaths;
	}

	// Создание ZIP-ревизии (HTML + все редактируемые картинки)
	public function makeRevision(): void {
		if (!file_exists(paths::$file_full_path))
			return;

		$maxRevisions = (int) (CMS_CONFIG['max_revisions'] ?? 10);
		if ($maxRevisions <= 0) {
			$this->log("makeRevision(): Создание ревизий отключено (max_revisions = {$maxRevisions})", 'revisions.txt');
			return;
		}

		// Снимок формируется строго на основе даты последнего изменения файла (filemtime)
		$lastModTime = @filemtime(paths::$file_full_path) ?: time();
		$dateStr = date('Y-m-d_H-i-s', $lastModTime);

		if (!is_dir(paths::$revisions_folder_path)) {
			@mkdir(paths::$revisions_folder_path, 2775, true);
		}

		$zipPath = paths::$revisions_folder_path . '/' . $dateStr . '.zip';

		// Если архив с такой датой уже существует — пересоздаем его
		if (file_exists($zipPath)) {
			if (!@unlink($zipPath)) {
				$this->error('Не удалось обновить имеющийся архив ревизии: ' . $dateStr . '.zip');
			}
		}

		$doc = Dom\HTMLDocument::createFromFile(paths::$file_full_path, LIBXML_NOERROR);
		$imagePaths = $this->getEditableImagesPaths($doc);

		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			$this->error('Не удалось создать ZIP-архив ревизии');
		}

		// Добавляем HTML-файл по его относительному пути
		$zip->addFile(paths::$file_full_path, ltrim(paths::$file_rel_path, '/'));

		// Добавляем все локальные картинки
		foreach ($imagePaths as $relPath => $absPath) {
			$zip->addFile($absPath, $relPath);
		}

		$zip->close();

		// Ротация бэкапов
		$zipFiles = @glob(paths::$revisions_folder_path . '/*.zip');
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
		if (!is_dir(paths::$revisions_folder_path))
			return '';

		$files = @glob(paths::$revisions_folder_path . '/*.zip');
		if (!$files)
			return '';

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
				'date' => $formattedDate
			];
		}

		self::$revisions_count = count($revisions);

		ob_start();
		?>
		<ul>
			<?php if (empty($revisions)): ?>
				<li class="cms-rev-empty">Нет ревизий</li>
			<?php else: ?>
				<?php foreach ($revisions as $rev): ?>
					<li class="cms-rev-item" data-file="<?= htmlspecialchars($rev['filename']) ?>"><?= htmlspecialchars($rev['date']) ?>
					</li>
				<?php endforeach; ?>
			<?php endif; ?>
		</ul>
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
		$revision_filename = basename($_POST['revision_file'] ?? '');
		if ($revision_filename) {
			$revision_zip_path = paths::$revisions_folder_path . '/' . $revision_filename;
		}

		if (!$revision_filename) {
			$this->error('Не указан файл ревизии');
		}

		if (!file_exists($revision_zip_path)) {
			$this->error('Файл ревизии не найден: ' . $revision_filename);
		}

		try {
			// 1. Создаем бэкап ТЕКУЩЕГО живого состояния перед откатом
			$this->makeRevision();

			// 2. Находим картинки текущей живой версии
			$currentDoc = Dom\HTMLDocument::createFromFile(paths::$file_full_path, LIBXML_NOERROR);
			$currentImages = $this->getEditableImagesPaths($currentDoc);

			// 3. Распаковываем ZIP-архив целевой ревизии в корень сайта
			$zip = new ZipArchive();
			if ($zip->open($revision_zip_path) === true) {

				// Удаляем с диска текущий живой HTML и его живые картинки
				@unlink(paths::$file_full_path);
				foreach ($currentImages as $relPath => $absPath) {
					if (file_exists($absPath)) {
						@unlink($absPath);
					}
				}

				$zip->extractTo(paths::$site_root_dir);
				$zip->close();

				// 6. Удаляем архивированный файл ревизии, так как эта версия стала живым сайтом
				@unlink($revision_zip_path);

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