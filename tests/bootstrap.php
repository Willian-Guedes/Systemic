<?php
declare(strict_types=1);

$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'oficina_db_test';
$_ENV['DB_USER'] = 'test';
$_ENV['DB_PASS'] = 'test';

$frontend = __DIR__ . '/../frontend';

require_once $frontend . '/libs/router.php';
require_once $frontend . '/libs/AccessControl.php';
require_once $frontend . '/database.php';
require_once $frontend . '/auth_controller.php';
require_once $frontend . '/cadastro_controller.php';
require_once $frontend . '/ProdutoController.php';
