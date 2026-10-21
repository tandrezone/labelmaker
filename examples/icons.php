<?php
/**
 * For the icon picker in index.html:
 *   icons.php                    JSON {"builtin": [names], "emoji": {name: hex}, "material": {name: hex}}
 *   icons.php?font=emoji         an icon font (emoji or material)
 *   icons.php?icon=pumpkin&size=48   one icon as a PNG (used for the built-in icons)
 */

declare(strict_types=1);

use LabelMaker\LabelMaker;

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/../src/LabelMaker.php';
}

try {
    if (isset($_GET['font'])) {
        $path = LabelMaker::glyphFont((string)$_GET['font']);
        header('Content-Type: font/ttf');
        header('Cache-Control: no-cache');
        readfile($path);
    } elseif (isset($_GET['icon'])) {
        $png = (new LabelMaker(__DIR__ . '/icons'))->iconPng((string)$_GET['icon'], (int)($_GET['size'] ?? 48));
        header('Content-Type: image/png');
        header('Cache-Control: no-cache');
        echo $png;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['builtin' => LabelMaker::BUILTIN_ICONS] + LabelMaker::glyphIcons(), JSON_FORCE_OBJECT);
    }
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $e->getMessage();
}
