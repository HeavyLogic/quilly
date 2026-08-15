<?php
// Этот файл - точка входа для админки
require_once __DIR__ . '/includes/init.php';
$output_mode = null;

// Проверяем есть ли вообще пользователи в базе. Если нет - первоначальная настройка

$db = new db();
if ($db->is_empty()) {
    // первоначальная настройка
    $output_mode = 'setup';
} else {
    if (auth::check_auth()) {
        if (auth::is_admin()) {
            // UI админки
            $output_mode = 'dashboard';
            require_once __DIR__ . '/modules/superuser.php';
        } else {
            // Редирект
            header('Location: /');
            exit;
        }
    } else {
        // форма авторизации
        $output_mode = 'login';
    }
}
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

<div class="container <?php echo $output_mode; ?>-mode">
    <div id="errorAlert" class="alert-error <?= $pageError ? 'visible' : '' ?>">
        <?= htmlspecialchars($pageError ?? '') ?>
    </div>

    <?php if ($output_mode == 'setup'): ?>
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

    <?php elseif ($output_mode == 'dashboard'): ?>
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
            <?php echo new superuser()->getUsersHtml(); ?>
        </div>

        <div class="controls">
            <button id="btnOpenAddModal">+ Добавить пользователя</button>
        </div>

    <?php elseif ($output_mode == 'login'): ?>
        <!-- 2. ФОРМА ВХОДА (БАЗА ЕСТЬ, НЕ ЗАЛОГИНЕН) -->
        <div class="login-box">
            <h2>Авторизация</h2>
            <form id="loginForm" autocomplete="off">
                <input type="text" name="login" placeholder="Логин" required autocomplete="off">
                <input type="password" name="password" placeholder="Пароль" required autocomplete="current-password">
                <button type="submit" style="width: 100%;">Войти</button>
            </form>
        </div>

    <?php endif; ?>
</div>


<?php if ($output_mode == 'dashboard') : ?>
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
<?php endif; ?>
</body>
</html>