<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['admin_login_csrf'])) {
    $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
}
$error = (string)($_SESSION['admin_login_error'] ?? '');
$reason = (string)($_SESSION['admin_login_failure_reason'] ?? '');
$validFrom = (string)($_SESSION['admin_login_valid_from'] ?? '');
$validUntil = (string)($_SESSION['admin_login_valid_until'] ?? '');
unset($_SESSION['admin_login_error'], $_SESSION['admin_login_failure_reason'], $_SESSION['admin_login_valid_from'], $_SESSION['admin_login_valid_until']);

function ownerLoginFail(string $message, string $reason = '', ?string $validFrom = null, ?string $validUntil = null): void {
    unset(
        $_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['device_id'],
        $_SESSION['consumer_authenticated'], $_SESSION['admin_authenticated'],
        $_SESSION['esp_current_datetime'], $_SESSION['esp_timezone']
    );
    $_SESSION['admin_login_error'] = $message;
    $_SESSION['admin_login_failure_reason'] = $reason;
    $_SESSION['admin_login_valid_from'] = $validFrom ?? '';
    $_SESSION['admin_login_valid_until'] = $validUntil ?? '';
    $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
    header('Location: owner.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['admin_login_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        ownerLoginFail('Security check failed. Please reload the Administrator Login page and try again.');
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        ownerLoginFail('Please enter the administrator username and password.');
    }

    try {
        $stmt = $conn->prepare(
            'SELECT id, username, password_hash, role, enabled, valid_from, valid_until
             FROM web_users WHERE username = ? LIMIT 1'
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($id, $savedUsername, $passwordHash, $role, $enabled, $dbValidFrom, $dbValidUntil);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || !password_verify($password, (string)$passwordHash)) {
            ownerLoginFail('Administrator login failed. Check your username and password.');
        }
        if ((string)$role !== 'admin') {
            ownerLoginFail('This account is not an administrator account.', 'Consumer accounts must use Consumer Login.');
        }
        if ((int)$enabled !== 1) {
            ownerLoginFail('Administrator login denied: this account is disabled.', 'Please contact the system owner.', $dbValidFrom, $dbValidUntil);
        }

        $zone = new DateTimeZone('Asia/Kolkata');
        $now = new DateTimeImmutable('now', $zone);
        if ($dbValidFrom !== null && trim((string)$dbValidFrom) !== '') {
            $start = new DateTimeImmutable((string)$dbValidFrom, $zone);
            if ($now < $start) ownerLoginFail('Administrator login denied: the validity period has not started.', 'The validity period is shown below.', $dbValidFrom, $dbValidUntil);
        }
        if ($dbValidUntil !== null && trim((string)$dbValidUntil) !== '') {
            $end = new DateTimeImmutable((string)$dbValidUntil, $zone);
            if ($now > $end) ownerLoginFail('Administrator login denied: the validity period has expired.', 'The validity period is shown below.', $dbValidFrom, $dbValidUntil);
        }

        session_regenerate_id(true);
        unset($_SESSION['consumer_authenticated']);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['user_id'] = (int)$id;
        $_SESSION['username'] = (string)$savedUsername;
        $_SESSION['role'] = 'admin';
        $_SESSION['device_id'] = null;
        $_SESSION['login_valid_from'] = $dbValidFrom ?? '';
        $_SESSION['login_valid_until'] = $dbValidUntil ?? '';
        $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['consumer_login_csrf'] = bin2hex(random_bytes(32));
        header('Location: index2.php');
        exit;

    } catch (Throwable $e) {
        error_log('Administrator login error: ' . $e->getMessage());
        ownerLoginFail('Administrator login could not be completed.', 'A database or date-time error occurred. Please check the PHP error log.');
    }
}

$adminSessionActive = (($_SESSION['role'] ?? '') === 'admin' && !empty($_SESSION['admin_authenticated']));
function ownerDateText(string $v): string { return trim($v) === '' ? 'Not specified' : trim($v); }
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Data Logger - Administrator Login</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:20px;font-family:Arial,Helvetica,sans-serif;background:#edf3f8;color:#243447}.box{max-width:520px;margin:45px auto;background:#fff;padding:28px;border-radius:14px;box-shadow:0 4px 18px #00000012}h1{text-align:center;color:#174a7e;margin:0 0 8px}.subtitle{text-align:center;color:#667788;margin-bottom:24px}.warning,.error,.period{padding:12px 14px;border-radius:7px;margin:14px 0;line-height:1.5;overflow-wrap:anywhere}.warning{background:#fff7df;color:#6e5200;border:1px solid #f0d98a}.error{background:#ffe7e7;color:#8c1a1a;border:1px solid #f2bcbc}.period{background:#fff7df;color:#6e5200;border:1px solid #f0d98a}label{display:block;font-weight:bold;margin:14px 0 6px}input{width:100%;padding:12px;border:1px solid #b9c7d5;border-radius:7px;font-size:16px}button,.button{display:inline-block;border:0;border-radius:7px;background:#1769aa;color:white;padding:12px 16px;font-size:16px;cursor:pointer;text-decoration:none;text-align:center}button{width:100%;margin-top:18px}button:hover,.button:hover{background:#104d80}.links{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.links .button{flex:1}.small{text-align:center;color:#667788;font-size:13px;margin-top:18px}
</style></head><body><main class="box"><h1>ESP8266 Data Logger</h1><div class="subtitle">Administrator Login</div>
<?php if ($adminSessionActive): ?>
<div class="warning">Administrator session active: <strong><?= h((string)$_SESSION['username']) ?></strong></div><div class="links"><a class="button" href="index2.php">Administrator Dashboard</a><a class="button" href="admin2.php">Manage Devices and View Logs</a><a class="button" href="users.php">Manage Consumers</a><a class="button" href="logout.php">Logout</a></div>
<?php else: ?>
<?php if ($error !== ''): ?><div class="error"><strong><?= h($error) ?></strong><?php if ($reason !== ''): ?><br><?= h($reason) ?><?php endif; ?></div><?php endif; ?>
<?php if ($validFrom !== '' || $validUntil !== ''): ?><div class="period"><strong>Account validity period</strong><br>Valid From: <?= h(ownerDateText($validFrom)) ?><br>Valid Until: <?= h(ownerDateText($validUntil)) ?></div><?php endif; ?>
<div class="warning">Administrator access is separate from Consumer Login. Consumer accounts cannot open administrator tools.</div>
<form method="post" action="owner.php" autocomplete="on"><input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['admin_login_csrf']) ?>"><label for="username">Administrator Username</label><input id="username" name="username" type="text" maxlength="50" autocomplete="username" required autofocus><label for="password">Administrator Password</label><input id="password" name="password" type="password" autocomplete="current-password" required><button type="submit">Administrator Login</button></form>
<?php endif; ?>
<div class="small"><a href="index.php">Return to Consumer Login</a></div></main></body></html>
