<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Kolkata');

// Keep the login page visible even when this browser already has a session.
$error = (string)($_SESSION['login_error'] ?? '');
$reason = (string)($_SESSION['login_failure_reason'] ?? '');
$validFrom = (string)($_SESSION['login_valid_from'] ?? '');
$validUntil = (string)($_SESSION['login_valid_until'] ?? '');

unset(
    $_SESSION['login_error'],
    $_SESSION['login_failure_reason'],
    $_SESSION['login_valid_from'],
    $_SESSION['login_valid_until']
);

if (empty($_SESSION['consumer_login_csrf'])) {
    $_SESSION['consumer_login_csrf'] = bin2hex(random_bytes(32));
}

function loginPageDateTime(string $value): string {
    if (trim($value) === '') {
        return 'Not specified';
    }

    // Display the database value as stored, without timezone conversion.
    return trim($value);
}

$role = (string)($_SESSION['role'] ?? '');
$loggedInName = (string)($_SESSION['username'] ?? '');

$consumerSessionActive = (
    $role === 'user' &&
    !empty($_SESSION['consumer_authenticated'])
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ESP32 Data Logger - Consumer Login</title>

<style>
*{box-sizing:border-box}
body{margin:0;padding:20px;font-family:Arial,Helvetica,sans-serif;background:#edf3f8;color:#243447}
.container{max-width:520px;margin:35px auto}
.card{background:#fff;padding:28px;border-radius:14px;box-shadow:0 4px 18px #00000012}
h1{text-align:center;color:#174a7e;margin:0 0 8px}
.subtitle{text-align:center;color:#667788;margin-bottom:24px}
.notice,.error,.period{padding:12px 14px;border-radius:7px;margin:14px 0;line-height:1.5;overflow-wrap:anywhere}
.notice{background:#e7f0fb;color:#174a7e}
.error{background:#ffe7e7;color:#8c1a1a;border:1px solid #f2bcbc}
.period{background:#fff7df;color:#6e5200;border:1px solid #f0d98a}
label{display:block;font-weight:bold;margin:14px 0 6px}
input{width:100%;padding:12px;border:1px solid #b9c7d5;border-radius:7px;font-size:16px}
button,.button{display:inline-block;border:0;border-radius:7px;background:#1769aa;color:white;padding:12px 16px;font-size:16px;cursor:pointer;text-decoration:none;text-align:center}
button{width:100%;margin-top:18px}
button:hover,.button:hover{background:#104d80}
.links{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.links .button{flex:1}
.small{text-align:center;color:#667788;font-size:13px;margin-top:18px}
</style>
</head>

<body>
<main class="container">
<section class="card">

<h1>ESP32 Data Logger</h1>
<div class="subtitle">Consumer Login</div>

<?php if ($consumerSessionActive): ?>

<div class="notice">
A consumer session is already active as
<strong><?= h($loggedInName) ?></strong>.<br>

<a href="index2.php">Open your consumer dashboard</a>
or
<a href="logout.php">log out</a>
before switching accounts.
</div>

<div class="links">
<a class="button" href="index2.php">Consumer Dashboard</a>
<a class="button" href="logout.php">Logout</a>
</div>

<?php else: ?>

<?php if ($error !== ''): ?>
<div class="error">
<strong><?= h($error) ?></strong>

<?php if ($reason !== ''): ?>
<br><?= h($reason) ?>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($validFrom !== '' || $validUntil !== ''): ?>
<div class="period">
<strong>Your account validity period</strong><br>
Valid From: <?= h(loginPageDateTime($validFrom)) ?><br>
Valid Until: <?= h(loginPageDateTime($validUntil)) ?>
</div>
<?php endif; ?>

<form method="post" action="authenticate.php" autocomplete="on">

<input
    type="hidden"
    name="csrf_token"
    value="<?= h($_SESSION['consumer_login_csrf']) ?>"
>

<label for="username">Consumer Username</label>
<input
    id="username"
    name="username"
    type="text"
    maxlength="50"
    autocomplete="username"
    required
    autofocus
>

<label for="password">Password</label>
<input
    id="password"
    name="password"
    type="password"
    autocomplete="current-password"
    required
>

<button type="submit">Consumer Login</button>
</form>

<div class="small">
This page is reserved for consumer login. Please contact the system owner if you need account assistance.
</div>

<?php endif; ?>

</section>
</main>
</body>
</html>