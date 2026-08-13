<?php
class paths {
    public static $site_root_dir;
    public static $file_rel_path;
    public static $file_full_path;
    public static $file_full_dir;
    public static $post_url;
    public static $revision_filename;
    public static $revision_folder_path;
    public static $revision_zip_path;
    public static $upload_dir;

    /**
     * Инициализация всех путей
     */
    public static function init() {
        self::$site_root_dir  = realpath(__DIR__ . '/../../');
        self::$file_rel_path  = self::get_html_rel_path();
        self::$file_full_path = self::$site_root_dir . '/' . self::$file_rel_path;
        self::$file_full_dir  = realpath(dirname(self::$file_full_path));
        self::$post_url  = $_POST['url'] ?? '';

		self::$revision_filename = basename($_POST['revision_file'] ?? '');
		if (self::$revision_filename) {
			self::$revision_folder_path = CMS_CONFIG['revisions_dir'] . '/' . self::$file_rel_path;
			self::$revision_zip_path = self::$revision_folder_path . '/' . self::$revision_filename;
		}

        self::$upload_dir = self::$site_root_dir . '/' . CMS_CONFIG['images']['upload_dir'];
    }

	// Универсальный резолвер пути к файлу
    private static function get_html_rel_path() {
		$rootDir = realpath(__DIR__ . '/../../');
        $customFilePath = trim($_POST['filepath'] ?? '');

        if (!empty($customFilePath)) {
            return ltrim($customFilePath, '/');
        }
        $urlPath = parse_url(($_POST['url'] ?? ''), PHP_URL_PATH) ?? '';
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
    public static function resolve_local_image_path(string $src): ?string {
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

        $cleanRelPath = ltrim(parse_url($src, PHP_URL_PATH) ?? $src, '/');
        paths::$file_full_path = paths::$site_root_dir . '/' . $cleanRelPath;

        if (file_exists(paths::$file_full_path) && is_file(paths::$file_full_path)) {
            return paths::$file_full_path;
        }

        return null;
    }
}