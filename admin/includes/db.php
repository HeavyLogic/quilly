<?php
/**
 * Класс для работы с SQLite через PDO
 * Class db
 */
class db extends base {
    /** @var PDO|null Хранит подключение к SQLite, чтобы подключаться всего один раз */
    private static $db_link = null;

	public function __construct() {
        if (!defined('QUILLY_INIT')) {
            http_response_code(403);
            exit('Access denied');
        }

		if (self::$db_link === null) {
            $this->connect_db();
        }
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
        // Создаем папку, если её нет
        $dirPath = dirname(CMS_CONFIG['db_path']);
        if (!file_exists($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        // Авто-защита базы для Apache
        $htaccessPath = $dirPath . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Require all denied\n");
        }

        // Подключаемся к SQLite через PDO
        try {
            $pdo = new PDO("sqlite:" . CMS_CONFIG['db_path']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            self::$db_link = $pdo;

            // Создаём таблицу пользователей, если база пустая
            self::$db_link->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user TEXT UNIQUE,
                password TEXT,
                auth TEXT,
                role TEXT DEFAULT 'editor'
            )");

        } catch (PDOException $e) {
            $this->error("Не удалось подключиться к базе SQLite" . $e->getMessage());
        }
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
            $this->error("Ошибка базы данных.");
            // Чувствительные данные:
            $this->log($e->getMessage() . " [SQL: {$sql}]", 'db.txt');
            return false;
        }
    }

    /**
     * Возвращает массив всех найденных записей
     * @return array
     */
    public function fetch_all($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Возвращает одну строку (или null, если ничего не найдено)
     * @return array|null
     */
    public function fetch_one($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt ? ($stmt->fetch() ?: null) : null;
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
}