<?php
// Front controller: every request goes through here.
define('BASE_PATH', __DIR__);
require BASE_PATH . '/core/bootstrap.php';

(new Core\App())->run();
