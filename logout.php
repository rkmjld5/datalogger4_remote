<?php
require_once __DIR__ . '/config.php';

// Remember which login page to show after destroying the session.
$wasAdmin = (($_SESSION['role'] ?? '') === 'admin' && !empty($_SESSION['admin_authenticated']));

// End both types of login session so this browser can safely switch accounts.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool)$params['secure'],
        (bool)$params['httponly']
    );
}
session_destroy();
header('Location: ' . ($wasAdmin ? 'owner.php' : 'index.php'));
exit;
