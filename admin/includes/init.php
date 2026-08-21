<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('QUILLY_INIT', '1');
// Поскольку я решил не использовать Composer и PSR-4
$config = require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/paths.php';
paths::init($config);
define('CMS_CONFIG', $config);
unset($config);
require_once paths::$site_root_dir . '/admin/includes/loc.php';
loc::init();
require_once paths::$site_root_dir . '/admin/modules/base.php';
require_once paths::$site_root_dir . '/admin/includes/db.php';
require_once paths::$site_root_dir . '/admin/modules/auth.php';
auth::init_session();