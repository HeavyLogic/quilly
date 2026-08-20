<?php
class base {
	protected function success(array $data = []): void {
		if (!$_POST['method']) {
			return;
		}

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array_merge(['success' => true], $data));
		die;
	}

	protected function error(string $message): void {
		if ($_POST['method']) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(['success' => false, 'message' => $message]);
		} else {
			echo '<div style="background:#ffebe9;color:#d1242f;padding:15px;border:1px solid #ff8182;border-radius:6px;font-family:sans-serif;margin:10px;">';
			echo '<b>Ошибка:</b> ' . $message;
			echo '</div>';
		}
		die;
	}

	protected function log(string $message, $filename): void {
		if (!CMS_CONFIG['debug'])
			return;

		if (!is_dir(paths::$debug_dir)) {
			@mkdir(paths::$debug_dir, 2775, true);
		}

		$logFile = paths::$debug_dir . '/' . $filename;
		$timestamp = date('Y-m-d H:i:s');
		$formattedMessage = "[{$timestamp}] {$message}\n";

		@file_put_contents($logFile, $formattedMessage, FILE_APPEND);
	}
}