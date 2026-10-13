<?php

declare(strict_types=1);

// Worker for AdminAuthConcurrencyTest: waits until a shared start time, then
// makes sign-in attempts on its own database connection (the test database
// named by NOVA_DB_TEST_NAME) with its own session.
// Arguments: <start microtime> <email> <password> <attempts> <client address>

use Nova\Security\AdminAuth;
use Nova\Security\ArraySessionStore;
use Nova\Tests\Integration\TestDatabase;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $start, $email, $password, $attempts, $address] = $argv;
$pdo = TestDatabase::connect();
while (microtime(true) < (float) $start) {
    usleep(1000);
}
for ($i = 0; $i < (int) $attempts; ++$i) {
    (new AdminAuth($pdo, new ArraySessionStore()))->attemptPassword($email, $password, $address);
}
