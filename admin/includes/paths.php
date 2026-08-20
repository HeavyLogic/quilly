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
}