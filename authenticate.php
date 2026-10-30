<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!hash_equals((string)($_SESSION['consumer_login_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['login_error'] = 'Security check failed. Please reload the Consumer Login page and try again.';
    header('Location: index.php');
    exit;
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

function consumerLoginFailed(string $message, string $reason = '', ?string $validFrom = null, ?string $validUntil = null): void {
    // A failed login never leaves a prior user's/admin's authenticated session active.
    unset(
        $_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['device_id'],
        $_SESSION['consumer_authenticated'], $_SESSION['admin_authenticated'],
        $_SESSION['esp_current_datetime'], $_SESSION['esp_timezone']
    );
    $_SESSION['login_error'] = $message;
    $_SESSION['login_failure_reason'] = $reason;
    $_SESSION['login_valid_from'] = $validFrom ?? '';
    $_SESSION['login_valid_until'] = $validUntil ?? '';
    $_SESSION['consumer_login_csrf'] = bin2hex(random_bytes(32));
    header('Location: index.php');
    exit;
}

if ($username === '' || $password === '') {
    consumerLoginFailed('Please enter your consumer username and password.');
}

try {
    $stmt = $conn->prepare(
        'SELECT id, username, password_hash, role, enabled, valid_from, valid_until, device_id
         FROM web_users WHERE username = ? LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($id, $savedUsername, $passwordHash, $role, $enabled, $validFrom, $validUntil, $deviceId);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || !password_verify($password, (string)$passwordHash)) {
        consumerLoginFailed('Login failed. Check your username and password.');
    }

    if ((string)$role !== 'user') {
        if ((string)$role === 'admin') {
            consumerLoginFailed('This is the Consumer Login page. Administrator accounts must use the separate Administrator Login.', 'Please use the Administrator Login link on this page.');
        }
        consumerLoginFailed('This account is not authorized for consumer login.');
    }

    if ((int)$enabled !== 1) {
        consumerLoginFailed('Login denied: your account is disabled.', 'Please contact the administrator.', $validFrom, $validUntil);
    }

    $indiaZone = new DateTimeZone('Asia/Kolkata');
    $now = new DateTimeImmutable('now', $indiaZone);

    if ($validFrom !== null && trim((string)$validFrom) !== '') {
        $start = new DateTimeImmutable((string)$validFrom, $indiaZone);
        if ($now < $start) {
            consumerLoginFailed('Login denied: your working period has not started.', 'Your account validity period is shown below.', $validFrom, $validUntil);
        }
    }

    if ($validUntil !== null && trim((string)$validUntil) !== '') {
        $end = new DateTimeImmutable((string)$validUntil, $indiaZone);
        if ($now > $end) {
            consumerLoginFailed('Login denied: your working period has expired.', 'Your account validity period is shown below.', $validFrom, $validUntil);
        }
    }

    $deviceId = trim((string)($deviceId ?? ''));
    if ($deviceId === '') {
        consumerLoginFailed('Login denied: no ESP8266 device is assigned to this account.', 'Please contact the administrator.', $validFrom, $validUntil);
    }

    $deviceStmt = $conn->prepare('SELECT enabled FROM esp_devices WHERE device_id = ? LIMIT 1');
    $deviceStmt->bind_param('s', $deviceId);
    $deviceStmt->execute();
    $deviceStmt->bind_result($deviceEnabled);
    $deviceFound = $deviceStmt->fetch();
    $deviceStmt->close();

    if (!$deviceFound || (int)$deviceEnabled !== 1) {
        consumerLoginFailed('Login denied: your assigned ESP8266 device is missing or disabled.', 'Please contact the administrator.', $validFrom, $validUntil);
    }

    session_regenerate_id(true);
    unset($_SESSION['admin_authenticated']);
    $_SESSION['consumer_authenticated'] = true;
    $_SESSION['user_id'] = (int)$id;
    $_SESSION['username'] = (string)$savedUsername;
    $_SESSION['role'] = 'user';
    $_SESSION['device_id'] = $deviceId;
    $_SESSION['login_valid_from'] = $validFrom ?? '';
    $_SESSION['login_valid_until'] = $validUntil ?? '';
    $_SESSION['consumer_login_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
    header('Location: index2.php');
    exit;

} catch (Throwable $e) {
    error_log('Consumer login error: ' . $e->getMessage());
    consumerLoginFailed('Login could not be completed.', 'A database or date-time error occurred. Please check the PHP error log.');
}
