<?php
// Защита от прямого запроса файла из браузера
if (!defined('QUILLY_INIT')) {
    http_response_code(403);
    exit('Access denied');
}

return [
    // Режим отладки
    'debug' => false,

    // Директория хранения файлов отладочных логов (относительно корня сайта)
    'debug_dir' => '/debug',

    // Путь к файлу базы данных SQLite
    'db_path' => '/restricted/users.sqlite',

    // Директория хранения ZIP-ревизий
    'revisions_dir' => '/restricted/revisions',

    // Максимальное число хранимых ревизий для страницы (0 - отключить)
    'max_revisions' => 10,

    // Настройки обработки и конвертации изображений
    'images' => [
        'quality'        => 80,
        'keep_if_larger' => true,
        'max_width'      => 1920,
        'max_height'     => 1920,
        'thumb_sizes'    => [600, 1200],
        'upload_dir'     => '/uploads', // Папка относительно корня сайта
    ],
];