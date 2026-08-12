<?php
class base {
	protected function success(array $data = []): void {
		echo json_encode(array_merge(['success' => true], $data));
		die;
	}
	
	protected function error(string $message, array $extra = []): void {
		echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
		die;
	}

	protected function writeDebugLog(string $message, $filename): void {
		if (!defined('CMS_CONFIG') || !CMS_CONFIG['debug']) return;

		$debugDir = CMS_CONFIG['debug_dir'];
		if (!is_dir($debugDir)) {
			@mkdir($debugDir, 0755, true);
		}

		$logFile = $debugDir . '/'.$filename;
		$timestamp = date('Y-m-d H:i:s');
		$formattedMessage = "[{$timestamp}] {$message}\n";

		@file_put_contents($logFile, $formattedMessage, FILE_APPEND);
	}

	// Универсальный резолвер пути к файлу
    protected function resolveTargetRelPath() {
		$rootDir = realpath(__DIR__ . '/../../');
        $customFilePath = trim($_POST['filepath'] ?? '');
        $url = $_POST['url'] ?? '';

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
    
}