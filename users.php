<?php
/*
 * Data Logger - Administrator: Consumer Account Management
 * Database: espdemo2; primary key: web_users.id
 * Device IDs in web_users are unique: one consumer per device.
 */
require_once __DIR__ . '/auth_check.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index2.php');
    exit;
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['users_csrf'];
$error = '';
$message = (string)($_SESSION['users_message'] ?? '');
unset($_SESSION['users_message']);

function usersDateTimeOrNull(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $zone = new DateTimeZone('Asia/Kolkata');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\\TH:i') !== $value) {
        throw new RuntimeException('Please enter a valid date and time.');
    }
    // Save the exact IST wall-clock value supplied by datetime-local; do not convert it to UTC.
    return str_replace('T', ' ', $value) . ':00';
}

function usersDateTimeInput($value): string {
    $value = trim((string)($value ?? ''));
    if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})[ T](\\d{2}:\\d{2})/', $value, $m)) return $m[1] . 'T' . $m[2];
    return '';
}

function usersRedirect(): void {
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Security check failed. Please reload the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'add') {
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $deviceId = trim((string)($_POST['device_id'] ?? ''));
                $validFrom = usersDateTimeOrNull((string)($_POST['valid_from'] ?? ''));
                $validUntil = usersDateTimeOrNull((string)($_POST['valid_until'] ?? ''));
                $enabled = isset($_POST['enabled']) ? 1 : 0;

                if ($username === '' || strlen($username) > 50) throw new RuntimeException('Username is required and must be no longer than 50 characters.');
                if (strlen($password) < 8) throw new RuntimeException('Password must contain at least 8 characters.');
                if ($deviceId === '') throw new RuntimeException('Please assign one ESP8266 device to this consumer.');
                if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) throw new RuntimeException('Valid Until must be later than or equal to Valid From.');

                $stmt = $conn->prepare('SELECT COUNT(*) FROM web_users WHERE username = ?');
                $stmt->bind_param('s', $username); $stmt->execute(); $stmt->bind_result($userCount); $stmt->fetch(); $stmt->close();
                if ((int)$userCount > 0) throw new RuntimeException('That username already exists. Please choose another username.');

                $stmt = $conn->prepare('SELECT enabled FROM esp_devices WHERE device_id = ? LIMIT 1');
                $stmt->bind_param('s', $deviceId); $stmt->execute(); $stmt->bind_result($deviceEnabled); $deviceFound = $stmt->fetch(); $stmt->close();
                if (!$deviceFound) throw new RuntimeException('That ESP8266 device is not registered. Register it on Manage Devices first.');
                if ((int)$deviceEnabled !== 1) throw new RuntimeException('That ESP8266 device is disabled. Enable it before assigning it.');

                $stmt = $conn->prepare('SELECT COUNT(*) FROM web_users WHERE device_id = ?');
                $stmt->bind_param('s', $deviceId); $stmt->execute(); $stmt->bind_result($assignedCount); $stmt->fetch(); $stmt->close();
                if ((int)$assignedCount > 0) throw new RuntimeException('This device is already assigned to another account. Each device can be assigned to only one consumer.');

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $role = 'user';
                $stmt = $conn->prepare('INSERT INTO web_users (username, password_hash, role, enabled, device_id, valid_from, valid_until) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssisss', $username, $passwordHash, $role, $enabled, $deviceId, $validFrom, $validUntil);
                $stmt->execute(); $stmt->close();
                $_SESSION['users_message'] = 'Consumer ' . $username . ' added successfully.';
                usersRedirect();

            } elseif ($action === 'update') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $deviceId = trim((string)($_POST['device_id'] ?? ''));
                $validFrom = usersDateTimeOrNull((string)($_POST['valid_from'] ?? ''));
                $validUntil = usersDateTimeOrNull((string)($_POST['valid_until'] ?? ''));
                $enabled = isset($_POST['enabled']) ? 1 : 0;
                if (!$id) throw new RuntimeException('Invalid consumer ID.');
                if ($username === '' || strlen($username) > 50) throw new RuntimeException('Username is required and must be no longer than 50 characters.');
                if ($password !== '' && strlen($password) < 8) throw new RuntimeException('New password must contain at least 8 characters.');
                if ($deviceId === '') throw new RuntimeException('Please assign one ESP8266 device to this consumer.');
                if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) throw new RuntimeException('Valid Until must be later than or equal to Valid From.');

                $stmt = $conn->prepare('SELECT COUNT(*) FROM web_users WHERE username = ? AND id <> ?');
                $stmt->bind_param('si', $username, $id); $stmt->execute(); $stmt->bind_result($userCount); $stmt->fetch(); $stmt->close();
                if ((int)$userCount > 0) throw new RuntimeException('That username already exists.');

                $stmt = $conn->prepare('SELECT enabled FROM esp_devices WHERE device_id = ? LIMIT 1');
                $stmt->bind_param('s', $deviceId); $stmt->execute(); $stmt->bind_result($deviceEnabled); $deviceFound = $stmt->fetch(); $stmt->close();
                if (!$deviceFound) throw new RuntimeException('That ESP8266 device is not registered.');
                if ((int)$deviceEnabled !== 1) throw new RuntimeException('That ESP8266 device is disabled. Enable it before assigning it.');

                $stmt = $conn->prepare('SELECT COUNT(*) FROM web_users WHERE device_id = ? AND id <> ?');
                $stmt->bind_param('si', $deviceId, $id); $stmt->execute(); $stmt->bind_result($assignedCount); $stmt->fetch(); $stmt->close();
                if ((int)$assignedCount > 0) throw new RuntimeException('This device is already assigned to another account.');

                if ($password !== '') {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE web_users SET username = ?, password_hash = ?, enabled = ?, device_id = ?, valid_from = ?, valid_until = ? WHERE id = ? AND role = 'user'");
                    $stmt->bind_param('ssisssi', $username, $passwordHash, $enabled, $deviceId, $validFrom, $validUntil, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE web_users SET username = ?, enabled = ?, device_id = ?, valid_from = ?, valid_until = ? WHERE id = ? AND role = 'user'");
                    $stmt->bind_param('sisssi', $username, $enabled, $deviceId, $validFrom, $validUntil, $id);
                }
                $stmt->execute(); $changed = $stmt->affected_rows; $stmt->close();
                if ($changed < 0) throw new RuntimeException('Consumer update failed.');
                $_SESSION['users_message'] = 'Consumer ' . $username . ' updated successfully.';
                usersRedirect();

            } elseif ($action === 'toggle') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $enabled = filter_var($_POST['enabled'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || !in_array($enabled, [0, 1], true)) throw new RuntimeException('Invalid consumer status request.');
                $stmt = $conn->prepare("UPDATE web_users SET enabled = ? WHERE id = ? AND role = 'user'");
                $stmt->bind_param('ii', $enabled, $id); $stmt->execute(); $found = $stmt->affected_rows; $stmt->close();
                // affected_rows can be zero if the account was already in this state; confirm that it exists.
                $stmt = $conn->prepare("SELECT COUNT(*) FROM web_users WHERE id = ? AND role = 'user'");
                $stmt->bind_param('i', $id); $stmt->execute(); $stmt->bind_result($exists); $stmt->fetch(); $stmt->close();
                if ((int)$exists !== 1) throw new RuntimeException('Consumer not found.');
                $_SESSION['users_message'] = $enabled === 1 ? 'Consumer account enabled.' : 'Consumer account disabled.';
                usersRedirect();

            } elseif ($action === 'delete') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) throw new RuntimeException('Invalid consumer ID.');
                $stmt = $conn->prepare("SELECT username FROM web_users WHERE id = ? AND role = 'user' LIMIT 1");
                $stmt->bind_param('i', $id); $stmt->execute(); $stmt->bind_result($deleteUsername); $found = $stmt->fetch(); $stmt->close();
                if (!$found) throw new RuntimeException('Consumer not found. Administrator accounts cannot be deleted here.');
                $stmt = $conn->prepare("DELETE FROM web_users WHERE id = ? AND role = 'user'");
                $stmt->bind_param('i', $id); $stmt->execute(); $deleted = $stmt->affected_rows; $stmt->close();
                if ($deleted !== 1) throw new RuntimeException('Consumer could not be deleted.');
                $_SESSION['users_message'] = 'Consumer ' . $deleteUsername . ' deleted. Historical device readings have not been deleted.';
                usersRedirect();
            } else {
                throw new RuntimeException('Unknown operation.');
            }
        } catch (Throwable $e) {
            error_log('Data Logger consumer management error: ' . $e->getMessage());
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
                $error = 'A duplicate username or device assignment conflicts with a database UNIQUE rule. Please use a different username or device.';
            } elseif ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                $error = 'The operation failed. Please check the PHP error log and database column names.';
            }
        }
    }
}

$devices = [];
$deviceResult = $conn->query('SELECT device_id, enabled, description FROM esp_devices ORDER BY device_id');
while ($device = $deviceResult->fetch_assoc()) $devices[] = $device;

$editUser = null;
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editId) {
        $stmt = $conn->prepare("SELECT id, username, enabled, device_id, valid_from, valid_until FROM web_users WHERE id = ? AND role = 'user' LIMIT 1");
        $stmt->bind_param('i', $editId); $stmt->execute();
        $result = $stmt->get_result(); $editUser = $result->fetch_assoc() ?: null; $stmt->close();
    }
}

$users = $conn->query("SELECT id, username, enabled, device_id, valid_from, valid_until, created_at FROM web_users WHERE role = 'user' ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Data Logger Consumers</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#263445;font-family:Arial,sans-serif}header{background:#174a78;color:white;padding:20px 5%;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}header h1{margin:0 0 6px;font-size:24px}header a,button,.button{display:inline-block;background:#1769aa;color:white;text-decoration:none;border:0;border-radius:5px;padding:10px 13px;font-size:14px;cursor:pointer;margin:3px}.gray{background:#475569}.green{background:#16803c}.orange{background:#b45309}.red{background:#b91c1c}main{max-width:1350px;margin:25px auto;padding:0 15px}.panel{background:white;padding:22px;border-radius:10px;box-shadow:0 2px 8px #d8e0e8;margin-bottom:22px}h2{color:#174a78;margin-top:0}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}label{display:block;font-weight:bold;margin-bottom:6px}input,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:5px;font-size:14px}input[type=checkbox]{width:auto}.message{padding:12px;border-radius:6px;margin-bottom:15px}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}.muted{color:#64748b;font-size:13px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px;border:1px solid #d8e0e8;text-align:left;white-space:nowrap}th{background:#e3edf7}.on{color:#16803c;font-weight:bold}.off{color:#b91c1c;font-weight:bold}.inline{display:inline} @media(max-width:600px){.panel{padding:15px}}
</style></head><body>
<header><div><h1>Consumer Management</h1><div>Administrator: <?= h($_SESSION['username'] ?? '') ?></div></div><div><a href="index2.php">Dashboard</a><a href="admin2.php">Manage Devices and View Logs</a><a class="gray" href="logout.php">Logout</a></div></header>
<main>
<?php if ($message !== ''): ?><div class="message success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="message error"><?= h($error) ?></div><?php endif; ?>
<section class="panel"><h2><?= $editUser ? 'Edit Consumer' : 'Add New Consumer' ?></h2>
<p class="muted">Each consumer must have one enabled ESP8266 device assigned. The Valid From and Valid Until values are stored as entered in India local time. Leave both date/time fields blank if you do not want a time restriction.</p>
<form method="post" action="users.php">
<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
<input type="hidden" name="action" value="<?= $editUser ? 'update' : 'add' ?>">
<?php if ($editUser): ?><input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>
<div class="form-grid">
<div><label for="username">Consumer username *</label><input id="username" name="username" maxlength="50" required value="<?= h($editUser['username'] ?? '') ?>"></div>
<div><label for="password"><?= $editUser ? 'New password (leave blank to keep current)' : 'Password *' ?></label><input id="password" type="password" name="password" <?= $editUser ? '' : 'required' ?> minlength="8" autocomplete="new-password"></div>
<div><label for="device_id">Assigned ESP8266 device *</label><select id="device_id" name="device_id" required><option value="">-- Select device --</option><?php foreach ($devices as $device): ?><option value="<?= h($device['device_id']) ?>" <?= (($editUser['device_id'] ?? '') === $device['device_id']) ? 'selected' : '' ?> <?= (int)$device['enabled'] !== 1 && (($editUser['device_id'] ?? '') !== $device['device_id']) ? 'disabled' : '' ?>><?= h($device['device_id']) ?><?= !empty($device['description']) ? ' — ' . h($device['description']) : '' ?><?= (int)$device['enabled'] !== 1 ? ' (DISABLED)' : '' ?></option><?php endforeach; ?></select></div>
<div><label for="valid_from">Valid From (India time)</label><input id="valid_from" name="valid_from" type="datetime-local" value="<?= h(usersDateTimeInput($editUser['valid_from'] ?? '')) ?>"></div>
<div><label for="valid_until">Valid Until (India time)</label><input id="valid_until" name="valid_until" type="datetime-local" value="<?= h(usersDateTimeInput($editUser['valid_until'] ?? '')) ?>"></div>
</div>
<p><label><input type="checkbox" name="enabled" value="1" <?= !$editUser || (int)$editUser['enabled'] === 1 ? 'checked' : '' ?>> Consumer account enabled</label></p>
<button class="green" type="submit"><?= $editUser ? 'Save Consumer Changes' : 'Add Consumer' ?></button>
<?php if ($editUser): ?><a class="button gray" href="users.php">Cancel Edit</a><?php endif; ?>
</form></section>
<section class="panel"><h2>Existing Consumer Accounts</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>Username</th><th>Assigned Device</th><th>Valid From</th><th>Valid Until</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
<?php if ($users->num_rows === 0): ?><tr><td colspan="8">No consumer accounts found.</td></tr><?php else: while ($user = $users->fetch_assoc()): ?>
<tr><td><?= h($user['id']) ?></td><td><?= h($user['username']) ?></td><td><?= h($user['device_id'] ?? '') ?></td><td><?= h($user['valid_from'] ?? 'Not specified') ?></td><td><?= h($user['valid_until'] ?? 'Not specified') ?></td><td><?= (int)$user['enabled'] === 1 ? '<span class="on">ENABLED</span>' : '<span class="off">DISABLED</span>' ?></td><td><?= h($user['created_at'] ?? '') ?></td><td>
<a class="button" href="users.php?edit=<?= (int)$user['id'] ?>">Edit</a>
<form class="inline" method="post" action="users.php"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="enabled" value="<?= (int)$user['enabled'] === 1 ? '0' : '1' ?>"><button class="<?= (int)$user['enabled'] === 1 ? 'orange' : 'green' ?>" type="submit"><?= (int)$user['enabled'] === 1 ? 'Disable' : 'Enable' ?></button></form>
<form class="inline" method="post" action="users.php" onsubmit="return confirm('Permanently delete consumer <?= h($user['username']) ?>? Existing readings will remain in logs.');"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$user['id'] ?>"><button class="red" type="submit">Delete</button></form>
</td></tr>
<?php endwhile; endif; ?>
</tbody></table></div></section>
</main></body></html>
