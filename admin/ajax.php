<?php
require_once __DIR__ . '/modules/base.php';
require_once __DIR__ . '/includes/db.php';

class ajax extends base {
	public function __construct() {
        header('Content-Type: application/json');

        if (!($_POST['module'] ?? '')) {
            $this->error('Не получен ключ module.');
        }
        
        if (!($_POST['method'] ?? '')) {
            $this->error('Не получен ключ method.');
        }
        
        require_once __DIR__ . '/config.php';

        // проверка авторизации
        require_once __DIR__ . '/modules/auth.php';
        $auth = new auth()->check_auth();
        if (!$auth) {
            if (!in_array($_POST['module'].'::'.$_POST['method'], array(
                    'auth::check_auth',
                    'auth::login',
                ))) {
                    $this->error('У вас нет прав на выполнение этого действия.');
            }
        }

        // подключаем модуль, куда ссылается ajax-запрос
        $module_file = __DIR__ .'/modules/'.$_POST['module'].'.php';
        if (!file_exists($module_file)) {
            $this->error('Файл модуля не существует: '.$module_file);
        }
        require_once $module_file;

        // запускаем модуль
        $module = new $_POST['module']();
        if (!method_exists($module, $_POST['method'])) {
            $this->error('Метод не существует: '.$_POST['method']);
        }

        // запускаем метод
        $result_array = $module->{$_POST['method']}();
        echo json_encode($result_array);
    }
}

new ajax();








