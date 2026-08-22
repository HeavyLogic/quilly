<?php
// Этот файл - точка входа для админки
require_once __DIR__ . '/includes/init.php';
$output_mode = null;

loc::section('dashboard');

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
	<title><?php echo loc::_('control_panel'); ?></title>
	<link rel="stylesheet" href="assets/admin.css?ver=1">
	<script type="module" src="assets/admin.js"></script>
</head>

<body>

	<div class="container <?php echo $output_mode; ?>-mode">
		<div class="adm-card">
			<div id="errorAlert" class="alert-error <?= $pageError ? 'visible' : '' ?>">
				<?= htmlspecialchars($pageError ?? '') ?>
			</div>

			<?php if ($output_mode == 'setup'): ?>
				<!-- 1. ФОРМА ПЕРВИЧНОЙ НАСТРОЙКИ (НЕТ БАЗЫ) -->
				<div class="login-box">
					<header>
						<h2><?php echo loc::_('initial_setup'); ?></h2>
					</header>
					<form id="setupForm" autocomplete="off">
						<input type="text" name="login" placeholder="Логин админа" required autocomplete="off">
						<input type="password" name="password" placeholder="Пароль" required autocomplete="new-password">
						<input type="password" name="password_confirm" placeholder="Повторите пароль" required
							autocomplete="new-password">
						<button type="submit" style="width: 100%;"><?php echo loc::_('create_user'); ?></button>
					</form>
				</div>

			<?php elseif ($output_mode == 'dashboard'): ?>
				<!-- 3. ИНТЕРФЕЙС АДМИНА (УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ) -->
				<header>
					<h2><?php echo loc::_('users_control'); ?></h2>
					<button class="btn-logout" id="btnLogout"><?php echo loc::_('global', 'exit'); ?></button>
				</header>

				<div class="table-header">
					<div><?php echo loc::_('login'); ?></div>
					<div><?php echo loc::_('password'); ?></div>
					<div><?php echo loc::_('role'); ?></div>
					<div><?php echo loc::_('action'); ?></div>
				</div>

				<div id="usersList">
					<?php echo new superuser()->getUsersHtml(); loc::section('dashboard'); ?>
				</div>

				<div class="controls">
					<button id="btnOpenAddModal">+ <?php echo loc::_('create_user'); ?></button>
				</div>

			<?php elseif ($output_mode == 'login'): ?>
				<!-- 2. ФОРМА ВХОДА (БАЗА ЕСТЬ, НЕ ЗАЛОГИНЕН) -->
				<div class="login-box">
					<header>
						<h2><?php echo loc::_('auth'); ?></h2>
					</header>
					<form id="loginForm" autocomplete="off">
						<input type="text" name="login" placeholder="Логин" required autocomplete="off">
						<input type="password" name="password" placeholder="Пароль" required
							autocomplete="current-password">
						<button type="submit"><?php echo loc::_('enter'); ?></button>
					</form>
				</div>

			<?php endif; ?>
		</div>

		<a class="go-back" href="/">← <?php echo loc::_('back_to_site'); ?></a>
	</div>
</body>

</html>