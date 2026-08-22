<?php
class revisions extends base {

	private static $revisions_count = 0;

	// Поиск, нормализация путей в HTML (приведение к /assets/...) и сборка путей для ZIP
	private function getEditableImagesPaths(Dom\HTMLDocument $doc): array {
		$images = $doc->querySelectorAll('img.editable');
	
		$imageRelPaths = paths::resolve_local_images($images, $modified);
	
		if ($modified) {
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
			$this->log("makeRevision(): Revisions disabled (max_revisions = {$maxRevisions})", 'revisions.txt');
			return;
		}

		loc::section('revisions');

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
				$this->error(loc::_('cant_delete_zip').': ' . $dateStr . '.zip');
			}
		}

		$doc = Dom\HTMLDocument::createFromFile(paths::$file_full_path, LIBXML_NOERROR);
		$imagePaths = $this->getEditableImagesPaths($doc);

		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			$this->error(loc::_('cant_create_zip'));
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

		loc::section('revisions');

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
				<li class="cms-rev-empty"><?php echo loc::_('no_revisions'); ?></li>
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
		loc::section('revisions');
		ob_start();
		?>
		<div class="cms-rev-btn" id="cms-btn-revs" data-count="<?= self::$revisions_count ?>" title="<?php echo loc::_('rev_history'); ?>">
			<span class="tabler-icon tabler--history"></span>
			<span class="cms-rev-text"><?php echo loc::_('revisions'); ?></span>
			<span class="cms-badge" id="cms-revs-badge"><?= self::$revisions_count ?></span>
		</div>
		<?php

		return ob_get_clean();
	}

	public function rollback_revision() {
		loc::section('revisions');
		$revision_filename = basename($_POST['revision_file'] ?? '');
		if ($revision_filename) {
			$revision_zip_path = paths::$revisions_folder_path . '/' . $revision_filename;
		}

		if (!$revision_filename) {
			$this->error(loc::_('file_not_recieved'));
		}

		if (!file_exists($revision_zip_path)) {
			$this->error(loc::_('file_not_found').': ' . $revision_filename);
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

				$this->success();
			} else {
				$this->error(loc::_('cant_open_zip'));
			}
		} catch (Throwable $e) {
			$this->log("PHP Exception in rollback: " . $e->getMessage(), 'revisions.txt');
			$this->error(loc::_('rollback_error').': ' . $e->getMessage());
		}
	}

}