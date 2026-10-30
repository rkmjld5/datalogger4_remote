<?php
/*
 * Shared protection for dashboard/report/admin pages.
 * This file checks an existing session. It never handles a login form.
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Kolkata');

function authCheckDeny(string $message, string $reason = '', ?string $validFrom = null, ?string $validUntil = null, ?string $forRole = null): void {
    $role = $forRole ?? (string)($_SESSION['role'] ?? '');
    $requestedPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $adminOnlyPages = ['users.php', 'admin2.php'];
    $sendToAdmin = (
        $role === 'admin'
        || !empty($_SESSION['admin_authenticated'])
        || ($role === '' && in_array($requestedPage, $adminOnlyPages, true))
    );
    unset(
        $_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['device_id'],
        $_SESSION['consumer_authenticated'], $_SESSION['admin_authenticated'],
        $_SESSION['esp_current_datetime'], $_SESSION['esp_timezone']
    );
    if ($sendToAdmin) {
        $_SESSION['admin_login_error'] = $message;
        $_SESSION['admin_login_failure_reason'] = $reason;
        $_SESSION['admin_login_valid_from'] = $validFrom ?? '';
        $_SESSION['admin_login_valid_until'] = $validUntil ?? '';
        header('Location: owner.php');
    } else {
        $_SESSION['login_error'] = $message;
        $_SESSION['login_failure_reason'] = $reason;
        $_SESSION['login_valid_from'] = $validFrom ?? '';
        $_SESSION['login_valid_until'] = $validUntil ?? '';
        header('Location: index.php');
    }
    exit;
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sessionRole = (string)($_SESSION['role'] ?? '');
if (!$userId || !in_array($sessionRole, ['admin', 'user'], true)) {
    authCheckDeny('Please log in to continue.', 'No valid authenticated session was found.');
}
if ($sessionRole === 'admin' && empty($_SESSION['admin_authenticated'])) {
    authCheckDeny('Please sign in through Administrator Login.', 'The administrator session marker is missing.', null, null, 'admin');
}
if ($sessionRole === 'user' && empty($_SESSION['consumer_authenticated'])) {
    authCheckDeny('Please sign in through Consumer Login.', 'The consumer session marker is missing.', null, null, 'user');
}

try {
    $stmt = $conn->prepare('SELECT id, username, role, enabled, valid_from, valid_until, device_id FROM web_users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($dbId, $dbUsername, $dbRole, $dbEnabled, $dbValidFrom, $dbValidUntil, $dbDeviceId);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        authCheckDeny('Your account no longer exists. Please log in again.', 'The user ID in the session was not found.');
    }
    if (!in_array((string)$dbRole, ['admin', 'user'], true) || (string)$dbRole !== $sessionRole) {
        authCheckDeny('Your account role has changed. Please log in again.', 'The session role does not match the current database role.', $dbValidFrom, $dbValidUntil, (string)$dbRole);
    }
    if ((int)$dbEnabled !== 1) {
        authCheckDeny('Access denied: your account is disabled.', 'The administrator has disabled this account.', $dbValidFrom, $dbValidUntil, (string)$dbRole);
    }

    $zone = new DateTimeZone('Asia/Kolkata');
    $now = new DateTimeImmutable('now', $zone);
    if ($dbValidFrom !== null && trim((string)$dbValidFrom) !== '') {
        $start = new DateTimeImmutable((string)$dbValidFrom, $zone);
        if ($now < $start) authCheckDeny('Access denied: your working period has not started.', 'Your account validity period is shown on the login page.', $dbValidFrom, $dbValidUntil, (string)$dbRole);
    }
    if ($dbValidUntil !== null && trim((string)$dbValidUntil) !== '') {
        $end = new DateTimeImmutable((string)$dbValidUntil, $zone);
        if ($now > $end) authCheckDeny('Access denied: your working period has expired.', 'Your account validity period is shown on the login page.', $dbValidFrom, $dbValidUntil, (string)$dbRole);
    }

    $deviceId = trim((string)($dbDeviceId ?? ''));
    if ($dbRole === 'user') {
        if ($deviceId === '') {
            authCheckDeny('No ESP8266 device is assigned to your account. Please contact the administrator.', 'No device ID is assigned.', $dbValidFrom, $dbValidUntil, 'user');
        }
        $deviceStmt = $conn->prepare('SELECT enabled FROM esp_devices WHERE device_id = ? LIMIT 1');
        $deviceStmt->bind_param('s', $deviceId);
        $deviceStmt->execute();
        $deviceStmt->bind_result($deviceEnabled);
        $deviceFound = $deviceStmt->fetch();
        $deviceStmt->close();
        if (!$deviceFound || (int)$deviceEnabled !== 1) {
            authCheckDeny('Your assigned ESP8266 device is missing or disabled. Please contact the administrator.', 'The assigned device must be registered and enabled.', $dbValidFrom, $dbValidUntil, 'user');
        }
    }

    $_SESSION['user_id'] = (int)$dbId;
    $_SESSION['username'] = (string)$dbUsername;
    $_SESSION['role'] = (string)$dbRole;
    $_SESSION['device_id'] = $dbRole === 'user' ? $deviceId : null;
    $_SESSION['login_valid_from'] = $dbValidFrom ?? '';
    $_SESSION['login_valid_until'] = $dbValidUntil ?? '';

} catch (Throwable $e) {
    error_log('Data Logger authorization check error: ' . $e->getMessage());
    authCheckDeny('Your access could not be verified. Please log in again.', 'A database or date-time error occurred during authorization.', null, null, $sessionRole);
}
