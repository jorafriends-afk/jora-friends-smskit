<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$secretFile = __DIR__ . '/deploy.secret.php';
if (!is_file($secretFile)) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'deploy.secret.php fehlt'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$secret = require $secretFile;
if (!is_string($secret) || strlen($secret) < 24) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Deployment-Secret ungueltig']);
    exit;
}

$provided = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? ($_POST['token'] ?? '');
if (!is_string($provided) || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nicht autorisiert']);
    exit;
}

$base = 'https://raw.githubusercontent.com/jorafriends-afk/jora-friends-smskit/main/';
$files = [
    '.htaccess',
    'api/v1/_auth.php',
    'api/v1/poll.php',
    'api/v1/receive.php',
    'api/v1/report.php',
    'api/v1/schedule.php',
    'api/v1/send.php',
    'api/v1/statistics.php',
    'api/v1/status.php',
    'api/v1/validate.php',
    'config/.htaccess',
    'dashboard/index.php',
    'index.php',
    'setup.php',
];

$written = [];
$errors = [];

foreach ($files as $path) {
    $url = $base . str_replace('%2F', '/', rawurlencode($path));
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'user_agent' => 'Jora-Friends-SMSKIT-Deploy/1.0'
        ]
    ]);

    $content = @file_get_contents($url, false, $ctx);
    if ($content === false) {
        $errors[] = $path . ': download fehlgeschlagen';
        continue;
    }

    $target = __DIR__ . '/' . $path;
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $errors[] = $path . ': Verzeichnis konnte nicht erstellt werden';
        continue;
    }

    $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        $errors[] = $path . ': temporaeres Schreiben fehlgeschlagen';
        continue;
    }

    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        $errors[] = $path . ': Aktivierung fehlgeschlagen';
        continue;
    }

    $written[] = $path;
}

$ok = count($errors) === 0;
http_response_code($ok ? 200 : 500);
echo json_encode([
    'ok' => $ok,
    'written' => $written,
    'errors' => $errors
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
