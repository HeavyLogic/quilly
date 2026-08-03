<?php
session_start();

// Конфигурация суперпользователя
define('SUPER_USER', 'admin');
define('SUPER_PASS', '123');

// Пути к папке и базе
$dirPath = __DIR__ . '/../restricted';
$dbPath = $dirPath . '/users.sqlite';

// 1. Создаем папку если нет
if (!file_exists($dirPath)) {
    mkdir($dirPath, 0755, true);
}

// 2. БД SQLite
try {
    $db = new PDO("sqlite:" . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user TEXT UNIQUE,
        password TEXT,
        auth TEXT
    )");
} catch (PDOException $e) {
    die("Ошибка БД: " . $e->getMessage());
}

// Вспомогательная функция: генерация HTML списка пользователей
function getUsersHtml($db) {
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
            echo '  <div class="cell editable" data-field="auth" title="Двойной клик для правки">' . htmlspecialchars($u['auth']) . '</div>';
            echo '  <div class="cell action-cell"><button class="btn-delete">Удалить</button></div>';
            echo '</div>';
        }
    }
    return ob_get_clean();
}

// --- УНИВЕРСАЛЬНЫЙ СИНХРОННЫЙ AJAX POST-ОБРАБОТЧИК ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Вход
    if ($action === 'login') {
        if (($_POST['login'] ?? '') === SUPER_USER && ($_POST['password'] ?? '') === SUPER_PASS) {
            $_SESSION['superuser_auth'] = true;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Неверный логин или пароль']);
        }
        exit;
    }

    // Выход
    if ($action === 'logout') {
        unset($_SESSION['superuser_auth']);
        echo json_encode(['success' => true]);
        exit;
    }

    // Защита для всех CRUD операций
    if (empty($_SESSION['superuser_auth'])) {
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit;
    }

    // Добавление пользователя
    if ($action === 'add_user') {
        $user = trim($_POST['user'] ?? '');
        $pass = trim($_POST['password'] ?? '');

        if (!$user || !$pass) {
            echo json_encode(['success' => false, 'message' => 'Заполните поля']);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO users (user, password, auth) VALUES (?, ?, ?)");
            $stmt->execute([$user, password_hash($pass, PASSWORD_DEFAULT), '']);
            
            echo json_encode(['success' => true, 'html' => getUsersHtml($db)]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Пользователь уже существует']);
        }
        exit;
    }

    // Редактирование поля
    if ($action === 'update_field') {
        $id    = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');

        if (!in_array($field, ['user', 'password', 'auth']) || !$id) {
            echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
            exit;
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

$isAuth = !empty($_SESSION['superuser_auth']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Superuser Manager</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #f4f5f7; color: #333; display: flex; justify-content: center; padding-top: 50px; }
        
        .container { background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); width: 100%; max-width: 800px; }
        .container.login-mode { width: max-content; min-width: 320px; }
        
        h2 { margin-bottom: 20px; font-size: 20px; display: flex; justify-content: space-between; align-items: center;}
        
        input[type="text"], input[type="password"] { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; outline: none; }
        input:focus { border-color: #0066cc; }
        
        button { background: #0066cc; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { background: #0052a3; }
        .btn-delete { background: #e63946; padding: 5px 10px; font-size: 12px; }
        .btn-delete:hover { background: #c1121f; }
        .btn-logout { background: #6c757d; font-size: 12px; }
        
        .table-header, .user-row { display: grid; grid-template-columns: 1fr 1fr 1.5fr 90px; gap: 10px; align-items: center; }
        .table-header { font-weight: bold; background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 5px; font-size: 13px; color: #666; }
        .user-row { padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 14px; min-height: 45px; }
        .user-row:hover { background: #fafafa; }
        
        .cell { word-break: break-all; }
        .editable { cursor: pointer; padding: 4px 6px; border-radius: 4px; border: 1px transparent dashed; min-height: 28px; }
        .editable:hover { border-color: #ccc; background: #fffbe6; }
        .cell input { margin: 0; }

        .empty-msg { text-align: center; color: #888; padding: 20px; }
        .controls { margin-top: 20px; }

        .modal-bg { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); justify-content: center; align-items: center; }
        .modal { background: #fff; padding: 20px; border-radius: 8px; width: 300px; }
        .modal h3 { margin-bottom: 15px; font-size: 16px; }
        .modal input { margin-bottom: 12px; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
        .btn-cancel { background: #ccc; color: #333; }
        .login-box { min-width: 280px; }
        .login-box input { margin-bottom: 12px; }
    </style>
</head>
<body>

<div class="container <?= !$isAuth ? 'login-mode' : '' ?>">
    <?php if (!$isAuth): ?>
        <div class="login-box">
            <h2 style="margin-bottom:15px;">Авторизация</h2>
            <form id="loginForm" autocomplete="off">
                <input type="text" name="login" placeholder="Логин" required autocomplete="off">
                <input type="password" name="password" placeholder="Пароль" required autocomplete="off">
                <button type="submit" style="width: 100%;">Войти</button>
            </form>
        </div>
    <?php else: ?>
        <h2>
            Суперпользователь
            <button class="btn-logout" id="btnLogout">Выйти</button>
        </h2>

        <div class="table-header">
            <div>User</div>
            <div>Password</div>
            <div>Auth Token</div>
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

<div class="modal-bg" id="addModal">
    <div class="modal">
        <h3>Новый пользователь</h3>
        <form id="addUserForm" autocomplete="off">
            <input type="text" name="user" placeholder="Логин" required autocomplete="off">
            <input type="password" name="password" placeholder="Пароль" required autocomplete="new-password">
            <div class="modal-actions">
                <button type="button" class="btn-cancel" id="btnCloseModal">Отмена</button>
                <button type="submit">Создать</button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    var ajaxing = {};

    // УНИВЕРСАЛЬНАЯ ФУНКЦИЯ AJAX
    function sendAjax(data, successFunc) {
        if (!data || !data.action) {
            console.error('Не указано действие (action)');
            return;
        }
        
        var action = data.action;

        if (ajaxing[action]) return;
        ajaxing[action] = true;

        $.ajax({
            type: 'POST',
            url: 'superuser.php',
            dataType: 'json',
            data: data
        }).done(function(result) {
            if (result && result.success) {
                if (result.html !== undefined) {
                    $('#usersList').html(result.html);
                }
                if (typeof successFunc === 'function') {
                    successFunc(result);
                }
            } else {
                alert(result && result.message ? result.message : 'Ошибка выполнения');
            }
        }).fail(function(xhr) {
            alert('Ошибка сервера: ' + xhr.status + ' ' + xhr.statusText);
        }).always(function() {
            delete ajaxing[action];
        });
    }

    // 1. Авторизация
    $('#loginForm').on('submit', function(e) {
        e.preventDefault();
        
        sendAjax({
            action: 'login',
            login: $(this).find('[name="login"]').val(),
            password: $(this).find('[name="password"]').val()
        }, function() {
            location.reload();
        });
    });

    // 2. Выход
    $('#btnLogout').on('click', function() {
        sendAjax({ action: 'logout' }, function() {
            location.reload();
        });
    });

    // 3. Модалка (Открытие/Закрытие + Клики по фону)
    $('#btnOpenAddModal').on('click', function() { $('#addModal').css('display', 'flex'); });
    $('#btnCloseModal').on('click', function() { $('#addModal').hide(); });

    $('#addModal').on('click', function(e) {
        if (e.target !== this) return; // Проверка, что кликнули именно по оверлею, а не по модалке
        $(this).hide();
    });

    // 4. Добавление юзера
    $('#addUserForm').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        sendAjax({
            action: 'add_user',
            user: form.find('[name="user"]').val(),
            password: form.find('[name="password"]').val()
        }, function() {
            $('#addModal').hide();
            form[0].reset();
        });
    });

    // 5. Удаление юзера
    $(document).on('click', '.btn-delete', function() {
        var id = $(this).closest('.user-row').data('id');
        if (confirm('Удалить пользователя?')) {
            sendAjax({ action: 'delete_user', id: id });
        }
    });

    // 6. Inline Editing по дабл-клику
    $(document).on('dblclick', '.editable', function() {
        var cell = $(this);
        if (cell.find('input').length > 0) return;

        var field = cell.data('field');
        var id = cell.closest('.user-row').data('id');
        var currentVal = (field === 'password') ? '' : cell.text().trim();

        var input = $('<input type="text">').val(currentVal);
        cell.html(input);
        input.focus();

        function save() {
            var newVal = input.val().trim();
            
            if (field === 'password' && newVal === '') {
                cell.html('**********');
                return;
            }

            sendAjax({
                action: 'update_field',
                id: id,
                field: field,
                value: newVal
            });
        }

        input.on('blur', save);
        input.on('keydown', function(e) {
            if (e.key === 'Enter') {
                input.off('blur');
                save();
            }
        });
    });

});
</script>

</body>
</html>