<?php
define('QUILLY_INIT', '1');
// Поскольку я решил не использовать Composer и PSR-4
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/paths.php';
paths::init();
require_once __DIR__ . '/../modules/base.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../modules/auth.php';
