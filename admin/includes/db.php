<?php
/**
 * Класс для работы с SQLite через PDO
 * Class db
 */
class db {
    /** @var PDO|null Хранит подключение к SQLite, чтобы подключаться всего один раз */
    private static $db_link = null;

	public function __construct() {
		if (self::$db_link === null) {
            $this->connect_db();
        }

		$this->create_db();
	}

    /**
     * Проверяет, пуста ли база данных (есть ли хотя бы один пользователь)
     * @return bool true - если пользователей НЕТ (база пуста), false - если есть
     */
    public function is_empty() {
        // Выполняем быстрый запрос
        $row = $this->fetch_one("SELECT 1 FROM users LIMIT 1");
        
        // Если запрос ничего не вернул — база пуста
        return empty($row);
    }

    /**
     * Коннектимся к SQLite
     */
    private function connect_db() {
        // Подключаемся к SQLite через PDO
        try {
            $pdo = new PDO("sqlite:" . CMS_CONFIG['db_path']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            self::$db_link = $pdo;
        } catch (PDOException $e) {
            $this->handle_error("Не удалось подключиться к базе SQLite: " . $e->getMessage());
        }
    }

	private function create_db() {
        $dirPath = dirname(CMS_CONFIG['db_path']);

        // Создаем папку и .htaccess если их нет
        if (!file_exists($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $htaccessPath = $dirPath . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Require all denied\n");
        }

		self::$db_link->exec("CREATE TABLE IF NOT EXISTS users (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			user TEXT UNIQUE,
			password TEXT,
			auth TEXT,
			role TEXT DEFAULT 'editor'
		)");
	}

    /**
     * Выполнение SQL-запроса с безопасной передачей параметров
     * @param string $sql SQL запрос (например: "SELECT * FROM users WHERE user = ?")
     * @param array $params Массив параметров для вставки вместо ?
     * @return PDOStatement|false
     */
    public function query($sql, $params = []) {
        try {
            $stmt = self::$db_link->prepare($sql);
            $stmt->execute((array)$params);
            return $stmt;
        } catch (PDOException $e) {
            $this->handle_error($e->getMessage() . " [SQL: {$sql}]");
            return false;
        }
    }

    /**
     * Удобный хелпер: сразу возвращает массив всех найденных записей
     * @return array
     */
    public function fetch_all($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Удобный хелпер: возвращает одну строку (или null, если ничего не найдено)
     * @return array|null
     */
    public function fetch_one($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? ($stmt->fetch() ?: null) : null;
    }

    /**
     * Подготавливает строку для записи (trim + очистка HTML-тегов)
     * Защита от SQL-инъекций делается автоматически через передачи параметров в query()
     */
    private function prepare_str($string, $html = false) {
        if (!is_string($string)) {
            return $string;
        }

        $string = trim($string);

        if (!$html) {
            $string = strip_tags($string);
        }

        return $string;
    }

    /**
     * Возвращает id только что вставленной записи
     * @return string|int
     */
    public function get_insert_id() {
        if (!self::$db_link) {
            return 0;
        }
        return self::$db_link->lastInsertId();
    }

    /**
     * Вывод ошибки выполнения запроса
     */
    private function handle_error($error) {
		// Если запрос пришел через AJAX
		if ($_POST['method']) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode([
				'success' => false,
				'message' => $error
			]);
		} else {
			echo '<div style="background:#ffebe9;color:#d1242f;padding:15px;border:1px solid #ff8182;border-radius:6px;font-family:sans-serif;margin:10px;">';
			echo '<b>Ошибка SQLite:</b> ' . htmlspecialchars($error);
			echo '</div>';
		}
		exit;
    }
}