<?php
define('CMS_EXEC', true);
require_once __DIR__ . '/config.php';

session_start();

$dbPath = CMS_CONFIG['db_path'];
$dirPath = dirname($dbPath);

// 1. Создаем папку и .htaccess если их нет
if (!file_exists($dirPath)) {
    mkdir($dirPath, 0755, true);
}
$htaccessPath = $dirPath . '/.htaccess';
if (!file_exists($htaccessPath)) {
    file_put_contents($htaccessPath, "Require all denied\n");
}

// 2. Проверяем наличие базы и текущего авторизованного пользователя
$isSetupNeeded = !file_exists($dbPath);
$db = null;
$currentUser = null;
$pageError = null;

if (!$isSetupNeeded) {
    try {
        $db = new PDO("sqlite:" . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Авторизация по куке site_auth
        if (!empty($_COOKIE['site_auth'])) {
            $stmt = $db->prepare("SELECT * FROM users WHERE auth = ? AND auth != ''");
            $stmt->execute([$_COOKIE['site_auth']]);
            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentUser) {
                setcookie('site_auth', '', time() - 3600, '/');
            }
        }
    } catch (PDOException $e) {
        $pageError = 'Ошибка БД: ' . (CMS_CONFIG['debug'] ? $e->getMessage() : 'Ошибка доступа к базе данных');
    }
}

// 3. Если залогинен обычный редактор (не admin) — редиректим его на главную сайта
if ($currentUser && ($currentUser['role'] ?? 'editor') !== 'admin') {
    header('Location: /');
    exit;
}

// Вспомогательная функция: генерация HTML списка пользователей
function getUsersHtml($db) {
    if (!$db) return '';
    ob_start();
    $stmt = $db->query("SELECT * FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo '<div class="empty-msg">База пользователей пуста</div>';
    } else {
        foreach ($users as $u) {
            echo '<div class="user-row" data-id="' . $u['id'] . '">';
            echo '  <div class="cell editable" data-field="user" title="Двойной клик для правки">' . htmlspecialchars($u['user']) . '</div>';
            echo '  <div class="cell editable" data-field="password" title="Двойной клик для смены пароля">**********</div>';
            echo '  <div class="cell editable" data-field="role" title="Двойной клик для правки">' . htmlspecialchars($u['role'] ?? 'editor') . '</div>';
            echo '  <div class="cell action-cell"><button class="btn-delete">Удалить</button></div>';
            echo '</div>';
        }
    }
    return ob_get_clean();
}

// --- AJAX ОБРАБОТЧИК ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Action: Первичная установка админа
    if ($action === 'setup') {
        if (!$isSetupNeeded) {
            echo json_encode(['success' => false, 'message' => 'База данных уже существует']);
            exit;
        }

        $login       = trim($_POST['login'] ?? '');
        $pass        = trim($_POST['password'] ?? '');
        $passConfirm = trim($_POST['password_confirm'] ?? '');

        if (!$login || !$pass || !$passConfirm) {
            echo json_encode(['success' => false, 'message' => 'Заполните все поля']);
            exit;
        }

        if ($pass !== $passConfirm) {
            echo json_encode(['success' => false, 'message' => 'Пароли не совпадают']);
            exit;
        }

        try {
            $db = new PDO("sqlite:" . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user TEXT UNIQUE,
                password TEXT,
                auth TEXT,
                role TEXT DEFAULT 'editor'
            )");

            $token = bin2hex(random_bytes(16));
            $stmt = $db->prepare("INSERT INTO users (user, password, auth, role) VALUES (?, ?, ?, 'admin')");
            $stmt->execute([$login, password_hash($pass, PASSWORD_DEFAULT), $token]);

            setcookie('site_auth', $token, [
                'expires'  => time() + 604800,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка создания базы: ' . $e->getMessage()]);
        }
        exit;
    }

    // Action: Вход (для всех пользователей)
    if ($action === 'login') {
        $login = trim($_POST['login'] ?? '');
        $pass  = trim($_POST['password'] ?? '');

        if (!$login || !$pass) {
            echo json_encode(['success' => false, 'message' => 'Заполните логин и пароль']);
            exit;
        }

        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'База данных недоступна']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE user = ?");
            $stmt->execute([$login]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userData && password_verify($pass, $userData['password'])) {
                $token = $userData['auth'];

                if (empty($token)) {
                    $token = bin2hex(random_bytes(16));
                    $updateStmt = $db->prepare("UPDATE users SET auth = ? WHERE id = ?");
                    $updateStmt->execute([$token, $userData['id']]);
                }

                setcookie('site_auth', $token, [
                    'expires'  => time() + 604800,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);

                // Редирект в зависимости от роли
                $redirect = ($userData['role'] === 'admin') ? '/admin/' : '/';
                echo json_encode(['success' => true, 'redirect' => $redirect]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Неверный логин или пароль']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка сервера при авторизации']);
        }
        exit;
    }

    // Action: Выход
    if ($action === 'logout') {
        setcookie('site_auth', '', time() - 3600, '/');
        echo json_encode(['success' => true]);
        exit;
    }

    // --- CRUD только для админа ---
    if (!$currentUser || ($currentUser['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit;
    }

    // Добавление пользователя
    if ($action === 'add_user') {
        $user = trim($_POST['user'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? 'editor');

        if (!in_array($role, ['admin', 'editor'])) {
            $role = 'editor';
        }

        if (!$user || !$pass) {
            echo json_encode(['success' => false, 'message' => 'Заполните поля']);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO users (user, password, auth, role) VALUES (?, ?, '', ?)");
            $stmt->execute([$user, password_hash($pass, PASSWORD_DEFAULT), $role]);
            
            echo json_encode(['success' => true, 'html' => getUsersHtml($db)]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Пользователь с таким логином уже существует']);
        }
        exit;
    }

    // Редактирование поля
    if ($action === 'update_field') {
        $id    = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');

        if (!in_array($field, ['user', 'password', 'role']) || !$id) {
            echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
            exit;
        }

        if ($field === 'role' && !in_array($value, ['admin', 'editor'])) {
            $value = 'editor';
        }

        if ($field === 'password') {
            $value = password_hash($value, PASSWORD_DEFAULT);
        }

        try {
            $stmt = $db->prepare("UPDATE users SET {$field} = ? WHERE id = ?");
            $stmt->execute([$value, $id]);
            
            echo json_encode(['success' => true, 'html' => getUsersHtml($db)]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка обновления']);
        }
        exit;
    }

    // Удаление пользователя
    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'html' => getUsersHtml($db)]);
        exit;
    }
}

$isAdmin = $currentUser && ($currentUser['role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель управления</title>
    <link rel="stylesheet" href="assets/admin.css?ver=1">
    <script type="module" src="assets/admin.js"></script>
</head>
<body>

<div class="container <?= (!$isAdmin) ? 'login-mode' : '' ?>">
    <div id="errorAlert" class="alert-error <?= $pageError ? 'visible' : '' ?>">
        <?= htmlspecialchars($pageError ?? '') ?>
    </div>

    <?php if ($isSetupNeeded): ?>
        <!-- 1. ФОРМА ПЕРВИЧНОЙ НАСТРОЙКИ (НЕТ БАЗЫ) -->
        <div class="login-box">
            <h2>Первичная настройка</h2>
            <form id="setupForm" autocomplete="off">
                <input type="text" name="login" placeholder="Логин админа" required autocomplete="off">
                <input type="password" name="password" placeholder="Пароль" required autocomplete="new-password">
                <input type="password" name="password_confirm" placeholder="Повторите пароль" required autocomplete="new-password">
                <button type="submit" style="width: 100%;">Создать админа</button>
            </form>
        </div>

    <?php elseif (!$currentUser): ?>
        <!-- 2. ФОРМА ВХОДА (БАЗА ЕСТЬ, НЕ ЗАЛОГИНЕН) -->
        <div class="login-box">
            <h2>Авторизация</h2>
            <form id="loginForm" autocomplete="off">
                <input type="text" name="login" placeholder="Логин" required autocomplete="off">
                <input type="password" name="password" placeholder="Пароль" required autocomplete="current-password">
                <button type="submit" style="width: 100%;">Войти</button>
            </form>
        </div>

    <?php else: ?>
        <!-- 3. ИНТЕРФЕЙС АДМИНА (УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ) -->
        <h2>
            Управление пользователями
            <button class="btn-logout" id="btnLogout">Выйти</button>
        </h2>

        <div class="table-header">
            <div>User</div>
            <div>Password</div>
            <div>Role</div>
            <div>Действие</div>
        </div>

        <div id="usersList">
            <?php echo getUsersHtml($db); ?>
        </div>

        <div class="controls">
            <button id="btnOpenAddModal">+ Добавить пользователя</button>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно добавления юзера -->
<div class="modal-bg" id="addModal">
    <div class="modal">
        <h3>Новый пользователь</h3>
        <div id="modalErrorAlert" class="alert-error"></div>
        <form id="addUserForm" autocomplete="off">
            <input type="text" name="user" placeholder="Логин" required autocomplete="off">
            <input type="password" name="password" placeholder="Пароль" required autocomplete="new-password">
            <select name="role">
                <option value="editor">editor</option>
                <option value="admin">admin</option>
            </select>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" id="btnCloseModal">Отмена</button>
                <button type="submit">Создать</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>