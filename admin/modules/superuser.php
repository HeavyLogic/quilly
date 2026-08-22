<?php
class superuser extends base {

	public function __construct() {
		$method = $_POST['method'] ?? '';
		loc::section('superuser');

		// Проверяем права админа для всех действий, КРОМЕ первичной установки
		if ($method !== 'setup') {
			$auth = new auth();
			if (!$auth->is_admin()) {
				$this->error(loc::_('no_access'));
			}
		}
	}

	// Action: Первичная установка админа
	public function setup() {
		$db = new db();

		// Проверяем, действительно ли база пуста
		if (!$db->is_empty()) {
			$this->error(loc::_('db_exists'));
		}

		$login = trim($_POST['login'] ?? '');
		$pass = trim($_POST['password'] ?? '');
		$passConfirm = trim($_POST['password_confirm'] ?? '');

		if (!$login || !$pass || !$passConfirm) {
			$this->error(loc::_('fill_all_fields'));
		}

		if ($pass !== $passConfirm) {
			$this->error(loc::_('pass2_doesnt_match'));
		}

		$token = bin2hex(random_bytes(16));

		$db->query("INSERT INTO users (user, password, auth, role) VALUES (?, ?, ?, 'admin')", [
			$login,
			password_hash($pass, PASSWORD_DEFAULT),
			$token
		]);

		setcookie('site_auth', $token, [
			'expires' => time() + 604800,
			'path' => '/',
			'httponly' => true,
			'samesite' => 'Lax'
		]);

		$this->success();
	}

	// Добавление пользователя
	public function add_user() {
		$user = trim($_POST['user'] ?? '');
		$pass = trim($_POST['password'] ?? '');
		$role = trim($_POST['role'] ?? 'editor');

		if (!in_array($role, ['admin', 'editor'])) {
			$role = 'editor';
		}

		if (!$user || !$pass) {
			$this->error(loc::_('fill_all_fields'));
		}

		$db = new db();

		// Проверяем, занят ли логин
		$exists = $db->fetch_one("SELECT id FROM users WHERE user = ?", [$user]);
		if ($exists) {
			$this->error(loc::_('user_exists'));
		}

		$db->query("INSERT INTO users (user, password, auth, role) VALUES (?, ?, '', ?)", [
			$user,
			password_hash($pass, PASSWORD_DEFAULT),
			$role
		]);

		$this->success([
			'html' => $this->getUsersHtml()
		]);
	}

	// Редактирование поля
	public function update_field() {
		$id = (int) ($_POST['id'] ?? 0);
		$field = $_POST['field'] ?? '';
		$value = trim($_POST['value'] ?? '');

		if (!in_array($field, ['user', 'password', 'role']) || !$id) {
			$this->error(loc::_('bad_data'));
		}

		if ($field === 'role' && !in_array($value, ['admin', 'editor'])) {
			$value = 'editor';
		}

		if ($field === 'password') {
			$value = password_hash($value, PASSWORD_DEFAULT);
		}

		$db = new db();

		// Если меняем имя пользователя — проверяем, чтобы не было совпадений
		if ($field === 'user') {
			$exists = $db->fetch_one("SELECT id FROM users WHERE user = ? AND id != ?", [$value, $id]);
			if ($exists) {
				$this->error(loc::_('user_exists'));
			}
		}

		$db->query("UPDATE users SET {$field} = ? WHERE id = ?", [$value, $id]);

		$this->success([
			'html' => $this->getUsersHtml()
		]);
	}

	// Удаление пользователя
	public function delete_user() {
		$id = (int) ($_POST['id'] ?? 0);

		if (!$id) {
			$this->error(loc::_('no_user_id'));
		}

		$db = new db();
		$db->query("DELETE FROM users WHERE id = ?", [$id]);

		$this->success([
			'html' => $this->getUsersHtml()
		]);
	}

	// Генерация HTML списка пользователей
	public function getUsersHtml() {
		$db = new db();
		$users = $db->fetch_all("SELECT * FROM users ORDER BY id DESC");

		ob_start();
		if (empty($users)): ?>
			<div class="empty-msg"><?php echo loc::_('db_is_empty'); ?></div>
		<?php else: ?>
			<?php foreach ($users as $u): ?>
				<div class="user-row" data-id="<?= $u['id'] ?>">
					<div class="cell editable" data-field="user" data-value="<?= htmlspecialchars($u['user']) ?>" title="<?php echo loc::_('dbl_click_to_edit'); ?>"><?= htmlspecialchars($u['user']) ?>
					</div>
					<div class="cell editable" data-field="password" title="<?php echo loc::_('dbl_click_to_edit'); ?>">**********</div>
					<div class="cell editable" data-field="role" data-value="<?= htmlspecialchars($u['role']) ?>" title="<?php echo loc::_('dbl_click_to_edit'); ?>">
						<?php echo loc::_('dashboard', $u['role']); ?>
					</div>
					<div class="cell action-cell">
						<button class="btn-delete"><?php echo loc::_('delete'); ?></button>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif;

		return ob_get_clean();
	}
	public function new_user_modal() {
		ob_start();
		?> 
		<!-- Модальное окно добавления юзера -->
		<div id="modalErrorAlert" class="alert-error"></div>
		<form id="addUserForm" autocomplete="off">
			<input type="text" name="user" placeholder="<?php echo loc::_('login'); ?>" autocomplete="off">
			<input type="password" name="password" placeholder="<?php echo loc::_('password'); ?>" autocomplete="new-password">
			<select name="role">
				<option value="editor"><?php echo loc::_('editor'); ?></option>
				<option value="admin"><?php echo loc::_('admin'); ?></option>
			</select>
			<div class="modal-actions">
				<button type="button" class="btn-cancel" id="btnCloseModal"><?php echo loc::_('cancel'); ?></button>
				<button type="submit"><?php echo loc::_('create'); ?></button>
			</div>
		</form>
		<?php
		
		$this->success([
			'html' => ob_get_clean(),
			'title' => loc::_('new_user')
		]);
	}

	public function get_edit_field() {
		$input = '';
		$field = htmlspecialchars(trim($_POST['field'] ?? ''));
		switch ($field) {
			case 'user':
				$value = htmlspecialchars(trim($_POST['value'] ?? ''));
				$input = '<input name="'.$field.'" type="text" value="'.$value.'">';
				break;
			case 'password':
				$input = '<input name="'.$field.'" type="password" value="" autocomplete="new-password">';
				break;
			case 'role':
				$value = $_POST['value'] ?? 'editor';
				if (!in_array($value, ['admin', 'editor'])) {
					$value = 'editor';
				}

				$input = '<select name="'.$field.'">
					<option value="editor" '.(($value == 'editor') ? 'selected' : '').'>'.loc::_('dashboard', 'editor').'</option>
					<option value="admin" '.(($value == 'admin') ? 'selected' : '').'>'.loc::_('dashboard', 'admin').'</option>
					</select>';
				break;
			default:
				$this->error(loc::_('bad_data'));
				break;
		}

		$this->success(['html' => $input]);
	}
}