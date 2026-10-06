<?php

declare(strict_types=1);

/*
 * Development-only diagnostics for the local stack (D25). Served at /_dev/
 * from outside the docroot and never deployed. Reports whether PHP, the
 * database and the mail catcher are reachable; shows no secrets.
 */

if (($_GET['check'] ?? '') === 'rewrite') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'rewrite ok';

    exit;
}

function env(string $name): string
{
    $value = getenv($name);

    return \is_string($value) ? $value : '';
}

/** @return array{string, string} status and detail */
function checkDatabase(): array
{
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('NOVA_DB_HOST'), env('NOVA_DB_NAME')),
            env('NOVA_DB_USER'),
            env('NOVA_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $statement = $pdo->query('SELECT VERSION(), @@collation_database');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_NUM);
        if (!\is_array($row)) {
            return ['error', 'no result'];
        }

        return ['ok', implode(', ', array_filter($row, is_string(...)))];
    } catch (PDOException $e) {
        return ['error', $e->getMessage()];
    }
}

/** @return array{string, string} status and detail */
function checkMail(): array
{
    $socket = @fsockopen(env('NOVA_SMTP_HOST'), (int) env('NOVA_SMTP_PORT'), $errno, $errstr, 3.0);
    if ($socket === false) {
        return ['error', $errstr];
    }
    $banner = (string) fgets($socket);
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return str_starts_with($banner, '220') ? ['ok', trim($banner)] : ['error', trim($banner)];
}

[$dbStatus, $dbDetail] = checkDatabase();
[$mailStatus, $mailDetail] = checkMail();

$rows = [
    ['php', 'PHP', 'ok', PHP_VERSION . ' (' . PHP_SAPI . ')'],
    ['db', 'MariaDB', $dbStatus, $dbDetail],
    ['mail', 'Mail catcher (SMTP)', $mailStatus, $mailDetail],
];

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_map(
        static fn(array $r): array => ['check' => $r[0], 'status' => $r[2], 'detail' => $r[3]],
        $rows,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    exit;
}

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Nova local environment</title>
<style>
  body { font: 16px/1.5 system-ui, sans-serif; margin: 2rem auto; max-width: 48rem; padding: 0 1rem; color: #1a1a1a; background: #fff; }
  table { border-collapse: collapse; width: 100%; }
  th, td { text-align: left; padding: .5rem; border-bottom: 1px solid #ccc; vertical-align: top; }
  .ok { color: #0b6b2b; font-weight: 600; }
  .error { color: #a40e0e; font-weight: 600; }
</style>
</head>
<body>
<main>
  <h1>Nova local environment</h1>
  <p>Development-only diagnostics (not part of the published site).</p>
  <table>
    <thead><tr><th scope="col">Check</th><th scope="col">Status</th><th scope="col">Detail</th></tr></thead>
    <tbody>
<?php foreach ($rows as [$id, $label, $status, $detail]) { ?>
      <tr><th scope="row"><?= $h($label) ?></th><td class="<?= $h($status) ?>" data-testid="<?= $h($id) ?>-status"><?= $h($status) ?></td><td><?= $h($detail) ?></td></tr>
<?php } ?>
    </tbody>
  </table>
</main>
</body>
</html>
