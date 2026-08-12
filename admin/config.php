<?php
// TODO: Защита от прямого запроса файла из браузера
// if (!defined('CMS_EXEC')) {
//     http_response_code(403);
//     exit('Access denied');
// }

// Суперглобальная константа конфигурации
define('CMS_CONFIG', [
    // Режим отладки (запись в debug_dir)
    'debug' => true,

    // Директория хранения файлов отладочных логов
    'debug_dir' => rtrim(__DIR__ . '/../debug', '/\\'),

    // Путь к файлу базы данных SQLite
    'db_path' => __DIR__ . '/../restricted/users.sqlite',

    // Путь к директории хранения ZIP-ревизий
    'revisions_dir' => rtrim(__DIR__ . '/../restricted/revisions', '/\\'),

    // Максимальное число хранимых ревизий для страницы (0 - отключить создание новых ревизий)
    'max_revisions' => 10,

    // Настройки обработки и конвертации изображений
    'images' => [
        'quality'    => 80,
        'keep_if_larger' => true,
        'max_width'  => 1920,
        'max_height' => 1920,
        'thumb_sizes'    => [600, 1200],
        'upload_dir' => trim('uploads', '/\\'), // Папка относительно корня сайта
    ],
]);