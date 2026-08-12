<?php
class auth extends base {
	private static $auth = false;
	
	public function check_auth() {
		if (self::$auth) {
			return self::$auth;
		}

		$token = $_COOKIE['site_auth'] ?? '';
		if (empty($token) || !file_exists(CMS_CONFIG['db_path'])) {
			return false;
		}

		try {
			$db = new PDO("sqlite:" . CMS_CONFIG['db_path']);
			$stmt = $db->prepare("SELECT user FROM users WHERE auth = ? AND auth != ''");
			$stmt->execute([$token]);
			self::$auth = $stmt->fetch(PDO::FETCH_ASSOC);
			return self::$auth;
		} catch (PDOException $e) {
			return false;
		}
	}

	public function logout() {
		setcookie('site_auth', '', [
			'expires'  => time() - 3600,
			'path'     => '/',
			'httponly' => true,
			'samesite' => 'Lax'
		]);
		$this->success();
	}
    
}