<?php
define('CMS_EXEC', true);
require_once __DIR__ . '/config.php';

session_start();

// 1. Если кука уже есть и валидна — сразу редиректим на сайт
if (isset($_COOKIE['site_auth']) && file_exists(CMS_CONFIG['db_path'])) {
    try {
        $db = new PDO("sqlite:" . CMS_CONFIG['db_path']);
        $stmt = $db->prepare("SELECT id FROM users WHERE auth = ? AND auth != ''");
        $stmt->execute([$_COOKIE['site_auth']]);
        if ($stmt->fetch()) {
            header('Location: /');
            exit;
        }
    } catch (PDOException $e) {
        // Игнорируем ошибку и показываем форму входа
    }
}

// 2. AJAX обработчик входа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'login') {
        $user = trim($_POST['login'] ?? '');
        $pass = trim($_POST['password'] ?? '');

        if (!$user || !$pass) {
            echo json_encode(['success' => false, 'message' => 'Заполните все поля']);
            exit;
        }

        if (!file_exists(CMS_CONFIG['db_path'])) {
            echo json_encode(['success' => false, 'message' => 'База данных не найдена. Создайте пользователей через superuser.php']);
            exit;
        }

        try {
            $db = new PDO("sqlite:" . CMS_CONFIG['db_path']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $db->prepare("SELECT * FROM users WHERE user = ?");
            $stmt->execute([$user]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Проверяем существование юзера и хэш пароля
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

                echo json_encode(['success' => true, 'redirect' => '/']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Неверный логин или пароль']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка сервера при авторизации']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход в систему</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #f4f5f7; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        
        .login-card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); width: 100%; max-width: 320px; }
        h2 { margin-bottom: 20px; font-size: 20px; text-align: center; }
        
        /* Стиль для плашки ошибки */
        .alert-error { 
            display: none; 
            background: #ffebe9; 
            color: #d1242f; 
            border: 1px solid rgba(255, 129, 130, 0.4); 
            padding: 10px; 
            border-radius: 6px; 
            font-size: 13px; 
            margin-bottom: 15px; 
            text-align: center; 
        }
        
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; outline: none; margin-bottom: 15px; }
        input:focus { border-color: #0066cc; }
        
        button { background: #0066cc; color: #fff; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 14px; width: 100%; font-weight: 500; }
        button:hover { background: #0052a3; }
    </style>
</head>
<body>

<div class="login-card">
    <h2>Авторизация</h2>
    
    <!-- Красный алерт об ошибке -->
    <div id="errorAlert" class="alert-error"></div>

    <form id="loginForm" autocomplete="off">
        <input type="text" name="login" placeholder="Логин" required autocomplete="off">
        <input type="password" name="password" placeholder="Пароль" required autocomplete="current-password">
        <button type="submit">Войти</button>
    </form>
</div>

<script>
$(document).ready(function() {
    var ajaxing = false;

    $('#loginForm').on('submit', function(e) {
        e.preventDefault();
        
        if (ajaxing) return;
        ajaxing = true;

        // Скрываем старую ошибку перед новым запросом
        $('#errorAlert').hide();

        $.ajax({
            type: 'POST',
            url: 'index.php',
            dataType: 'json',
            data: {
                action: 'login',
                login: $(this).find('[name="login"]').val(),
                password: $(this).find('[name="password"]').val()
            }
        }).done(function(res) {
            if (res && res.success) {
                window.location.href = res.redirect || '/';
            } else {
                // Показываем ошибку в алерт-боксе
                var msg = (res && res.message) ? res.message : 'Ошибка входа';
                $('#errorAlert').text(msg).fadeIn();
            }
        }).fail(function(xhr) {
            $('#errorAlert').text('Ошибка сервера: ' + xhr.status).fadeIn();
        }).always(function() {
            ajaxing = false;
        });
    });
});
</script>

</body>
</html>