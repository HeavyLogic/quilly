<?php
class paths {
	public static string $site_root_dir;
	public static string $debug_dir;
	public static string $db_full_path;
	public static string $db_full_dir;
	public static string $revisions_dir;
	public static string $upload_dir;

	public static string $file_rel_path;
	public static string $file_full_path;
	public static string $file_full_dir;
	public static string $revisions_folder_path;
	public static string $post_url;

	/**
	 * Инициализация путей и вычищалка путей из конфига
	 */
	public static function init(array &$config): void {
		// Корень сайта (абсолютный путь)
		self::$site_root_dir = self::normalize_path(dirname(__DIR__, 2));

		// Превращаем относительные пути из конфига в абсолютные
		self::$debug_dir = self::$site_root_dir . '/' . self::normalize_path($config['debug_dir'], true);
		self::$db_full_path = self::$site_root_dir . '/' . self::normalize_path($config['db_path'], true);
		self::$db_full_dir = dirname(self::$db_full_path);
		self::$revisions_dir = self::$site_root_dir . '/' . self::normalize_path($config['revisions_dir'], true);

		$uploadRelPath = $config['images']['upload_dir'] ?? 'uploads';
		self::$upload_dir = self::$site_root_dir . '/' . self::normalize_path($uploadRelPath, true);

		// Удаляем пути из массива конфига, чтобы их не было в CMS_CONFIG
		unset(
			$config['debug_dir'],
			$config['db_path'],
			$config['revisions_dir'],
			$config['images']['upload_dir']
		);

		// Обработка путей текущего запроса/файла
		self::$post_url = $_POST['url'] ?? '';
		self::$file_rel_path = self::get_html_rel_path();
		self::$file_full_path = self::$site_root_dir . '/' . self::$file_rel_path;
		self::$file_full_dir = self::normalize_path(dirname(self::$file_full_path));

		self::$revisions_folder_path = self::$revisions_dir . '/' . self::$file_rel_path;
	}

	/**
	 * Нормализация путей
	 * 
	 * @param string $path Путь для обработки
	 * @param bool $relative Если true — обрезает слеши с обеих сторон, если false — только справа
	 */
	private static function normalize_path(string $path, bool $relative = false): string {
		$path = str_replace('\\', '/', $path);
		$path = preg_replace('#/+#', '/', $path);

		return $relative ? trim($path, '/') : rtrim($path, '/');
	}

	/**
	 * Универсальный резолвер относительного пути к HTML-файлу
	 */
	private static function get_html_rel_path(): string {
		$customFilePath = trim($_POST['filepath'] ?? '');

		if (!empty($customFilePath)) {
			return self::normalize_path($customFilePath, true);
		}

		$urlPath = parse_url(self::$post_url, PHP_URL_PATH) ?? '';
		$urlPath = self::normalize_path($urlPath, true);

		if ($urlPath === '' || $urlPath === '/') {
			return 'index.html';
		}

		if (pathinfo($urlPath, PATHINFO_EXTENSION) === 'html') {
			return $urlPath;
		}

		$cleanPath = $urlPath;
		if (file_exists(self::$site_root_dir . '/' . $cleanPath . '.html')) {
			return $cleanPath . '.html';
		} elseif (file_exists(self::$site_root_dir . '/' . $cleanPath . '/index.html')) {
			return $cleanPath . '/index.html';
		}

		return $cleanPath;
	}
	
	/**
	 * Резолвит src и srcset переданных <img>-элементов в реально существующие
	 * локальные файлы на диске (включая миниатюры из srcset). Заодно приводит
	 * абсолютные URL текущего домена в src/srcset к относительным.
	 *
	 * ВАЖНО: метод меняет DOM только в памяти, saveHtmlFile() — забота вызывающего кода.
	 *
	 * @param iterable $imgElements Например, результат $doc->querySelectorAll(...)
	 * @param bool|null &$modified  Сюда запишется true, если DOM был изменён
	 * @return array<string,string> [$relPath (без ведущего слэша) => $absPath]
	 */
	public static function resolve_local_images(iterable $imgElements, ?bool &$modified = null): array {
		$modified = false;
		$result = [];

		foreach ($imgElements as $el) {
			foreach (['src', 'srcset'] as $attr) {
				$value = trim($el->getAttribute($attr) ?? '');
				if ($value === '')
					continue;

				$isSrcset = $attr === 'srcset';
				$entries = $isSrcset ? explode(',', $value) : [$value];
				$newEntries = [];
				$changed = false;

				foreach ($entries as $entry) {
					$parts = preg_split('/\s+/', trim($entry));
					$url = $parts[0] ?? '';
					$descriptor = $parts[1] ?? '';
					if ($url === '')
						continue;

					$resolved = self::resolve_image_url($url);
					if (!$resolved) {
						// Чужой домен (Unsplash и т.п.) - не трогаем
						$newEntries[] = trim($entry);
						continue;
					}

					if (file_exists($resolved['abs']) && is_file($resolved['abs'])) {
						$result[$resolved['rel']] = $resolved['abs'];
					}

					if ($url !== $resolved['web']) {
						$changed = true;
					}
					$newEntries[] = $isSrcset ? trim($resolved['web'] . ' ' . $descriptor) : $resolved['web'];
				}

				if ($changed) {
					$el->setAttribute($attr, $isSrcset ? implode(', ', $newEntries) : $newEntries[0]);
					$modified = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Резолвит один URL картинки в локальные пути. null - если чужой домен.
	 *
	 * @return array{rel:string, web:string, abs:string}|null
	 */
	private static function resolve_image_url(string $url): ?array {
		$targetHost = parse_url($url, PHP_URL_HOST);

		if ($targetHost !== null) {
			$currentHost = parse_url(self::$post_url, PHP_URL_HOST);
			if (!$currentHost || strcasecmp($targetHost, $currentHost) !== 0) {
				return null;
			}
		}

		$path = parse_url($url, PHP_URL_PATH);
		if (!$path)
			return null;

		$relPath = ltrim(str_replace('\\', '/', $path), '/');
		if ($relPath === '')
			return null;

		return [
			'rel' => $relPath,
			'web' => '/' . $relPath,
			'abs' => self::$site_root_dir . '/' . $relPath,
		];
	}

	/**
	 * Гарантированное рекурсивное создание директории с ТОЧНО заданными правами,
	 * независимо от umask php-fpm пула на конкретном сервере.
	 * Setgid (0 2000) нужен, чтобы новые файлы/папки внутри наследовали
	 * группу родительской папки (www-data), а не группу процесса php-fpm.
	 */
	public static function make_dir(string $path, int $mode = 02775): bool {
		if (is_dir($path)) {
			return true;
		}

		$oldUmask = umask(0);
		$result = @mkdir($path, $mode, true);
		umask($oldUmask);

		return $result;
	}
}