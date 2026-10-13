<?php

declare(strict_types=1);

// Nova Admin front controller (05 §6, D11). The application checks the host
// (.ir only), the session, CSRF and roles. This file only locates the code:
// the repository's nova/ in development; step 15 points it at the deployed
// application version (~/app-current.php).

$appRoot = dirname(__DIR__, 2);
require $appRoot . '/vendor/autoload.php';

Nova\Admin\AdminKernel::run($appRoot);
