<?php
class auth extends base {
    /** @var array|false|null Кеш данных авторизованного пользователя */
    private static $auth = null;

    /**
     * Проверка авторизации текущего пользователя по куке
     * @return array|false Данные ['user' => ..., 'role' => ...] или false
     */
    public function check_auth() {
        if (self::$auth !== null) {
            return self::$auth;
        }

        $token = $_COOKIE['site_auth'] ?? '';
        if (empty($token)) {
            self::$auth = false;
            return false;
        }

        $db = new db();
        $userData = $db->fetch_one("SELECT user, role FROM users WHERE auth = ? AND auth != ''", [$token]);

        if ($userData) {
            self::$auth = $userData;
            return self::$auth;
        }

        self::$auth = false;
        return false;
    }

    /**
     * Проверяет, является ли текущий пользователь админом
     * @return bool
     */
    public function is_admin() {
        $user = $this->check_auth();
        return $user && ($user['role'] ?? '') === 'admin';
    }

    /**
     * Action: Вход в систему (для всех пользователей)
     */
    public function login() {
        $login = trim($_POST['login'] ?? '');
        $pass  = trim($_POST['password'] ?? '');

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

            setcookie('site_auth', $token, [
                'expires'  => time() + 604800,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            // Сохраняем информацию об авторизованном юзере
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
        setcookie('site_auth', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        self::$auth = false;
        $this->success();
    }
}