<?php
/**
 * Web endpoint:
 *   label.php?text=Propylene%20Glycol&qty=3ml&icon=drop&font-size=40
 *
 * Only LabelMaker::USER_OPTIONS are read from the query; icons by name come from ./icons/.
 */

declare(strict_types=1);

use LabelMaker\LabelMaker;

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/../src/LabelMaker.php';
}

try {
    $png = (new LabelMaker(__DIR__ . '/icons'))->png($_GET, true);
    header('Content-Type: image/png');
    header('Content-Disposition: inline; filename="label.png"');
    echo $png;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $e->getMessage();
}
