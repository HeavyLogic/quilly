<?php
class auth extends base {
	/** @var array|false|null Кеш данных авторизованного пользователя на время выполнения текущего скрипта */
	private static $auth = null;

	/**
	 * Старт стандартной сессии PHP
	 */
	public static function init_session() {
		if (session_status() === PHP_SESSION_NONE) {
			session_set_cookie_params([
				'lifetime' => 0, // До закрытия браузера
				'path' => '/',
				'httponly' => true,
				'samesite' => 'Lax'
			]);
			session_start();
		}
	}

	/**
	 * Проверка авторизации текущего пользователя
	 * @return array|false Данные юзера или false
	 */
	public static function check_auth() {
		// Если уже проверяли в рамках этого HTTP-запроса — отдаем из статичной памяти
		if (self::$auth !== null) {
			return self::$auth;
		}

		self::init_session();

		// 1. Ищем токен: сначала в сессии, если нет — в куке
		if (!empty($_SESSION['site_auth'])) {
			$token = $_SESSION['site_auth'];
		} else {
			$token = $_COOKIE['site_auth'] ?? '';
			// Если токен был найден в куке (а в сессии его не было), восстанавливаем его в сессию
			$_SESSION['site_auth'] = $token;
		}

		if (empty($token)) {
			self::clear_auth_data();
			return false;
		}

		$db = new db();
		$userData = $db->fetch_one("SELECT user, role FROM users WHERE auth = ? AND auth != ''", [$token]);

		if ($userData) {
			self::$auth = $userData;
			return self::$auth;
		}

		// 3. Если токена в базе нет (пользователя удалили / сбросили пароль) — сбрасываем всё
		self::clear_auth_data();
		return false;
	}

	/**
	 * Проверяет, является ли текущий пользователь админом
	 */
	public static function is_admin() {
		$user = self::check_auth();
		return $user && ($user['role'] ?? '') === 'admin';
	}

	/**
	 * Action: Вход в систему
	 */
	public function login() {
		self::init_session();

		$login = trim($_POST['login'] ?? '');
		$pass = trim($_POST['password'] ?? '');

		if (!$login || !$pass) {
			$this->error('Заполните логин и пароль');
		}

		$db = new db();
		$userData = $db->fetch_one("SELECT * FROM users WHERE user = ?", [$login]);

		if ($userData && password_verify($pass, $userData['password'])) {
			$token = $userData['auth'];

			if (empty($token)) {
				$token = bin2hex(random_bytes(16));
				$db->query("UPDATE users SET auth = ? WHERE id = ?", [$token, $userData['id']]);
			}

			// Дублируем токен в Куку ("Запомнить меня") и в Сессию
			setcookie('site_auth', $token, [
				'expires' => time() + 604800, // 7 дней
				'path' => '/',
				'httponly' => true,
				'samesite' => 'Lax'
			]);

			$_SESSION['site_auth'] = $token;

			self::$auth = [
				'user' => $userData['user'],
				'role' => $userData['role']
			];

			$redirect = ($userData['role'] === 'admin') ? '/admin/' : '/';
			$this->success(['redirect' => $redirect]);
		} else {
			$this->error('Неверный логин или пароль');
		}
	}

	/**
	 * Action: Выход из системы
	 */
	public function logout() {
		self::init_session();
		self::clear_auth_data();
		$this->success();
	}

	/**
	 * Полная очистка сессий и кук авторизации
	 */
	private static function clear_auth_data() {
		self::$auth = false;

		// Очищаем сессию PHP
		$_SESSION = [];
		if (ini_get("session.use_cookies")) {
			$params = session_get_cookie_params();
			setcookie(
				session_name(),
				'',
				time() - 42000,
				$params["path"],
				$params["domain"],
				$params["secure"],
				$params["httponly"]
			);
		}
		session_destroy();

		// Удаляем куку авторизации
		setcookie('site_auth', '', [
			'expires' => time() - 3600,
			'path' => '/',
			'httponly' => true,
			'samesite' => 'Lax'
		]);
	}
}