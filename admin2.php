<?php
/*
 * Data Logger - Administrator: Device Management and Log Viewer
 * Remote deployment: datalogger2_remote.onrender.com
 * Database: espdemo2
 *
 * Requires existing auth_check.php and its $conn / h() helper.
 * Other project files must remain unchanged.
 */

require_once __DIR__ . '/auth_check.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index2.php');
    exit;
}

if (empty($_SESSION['admin2_csrf'])) {
    $_SESSION['admin2_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['admin2_csrf'];
$message = '';
$messageType = 'success';

function admin2ValidDateTime(string $value): bool
{
    if ($value === '') return true;

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false ||
            ($errors['warning_count'] === 0 &&
             $errors['error_count'] === 0))
        && $date->format('Y-m-d\TH:i') === $value;
}

/* ---------------------------------------------------------
   PROCESS ADMINISTRATOR ACTIONS
--------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(
        $csrfToken,
        (string)($_POST['csrf_token'] ?? '')
    )) {
        $message = 'Security check failed. Please reload the page.';
        $messageType = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');

        try {
            /* Register a device */
            if ($action === 'add_device') {
                $deviceId = trim((string)($_POST['device_id'] ?? ''));
                $apiToken = trim((string)($_POST['api_token'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));

                if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $deviceId)) {
                    throw new RuntimeException(
                        'Device ID must contain 1-32 letters, numbers, underscores or hyphens.'
                    );
                }

                if (strlen($apiToken) < 8 || strlen($apiToken) > 200) {
                    throw new RuntimeException(
                        'API token is required and must be 8-200 characters.'
                    );
                }

                if (strlen($description) > 100) {
                    throw new RuntimeException(
                        'Description must be 100 characters or fewer.'
                    );
                }

                // Store only the SHA-256 hash.
                $tokenHash = hash('sha256', $apiToken);

                $stmt = $conn->prepare(
                    'INSERT INTO esp_devices
                     (device_id, token_hash, enabled, description)
                     VALUES (?, ?, 1, ?)'
                );

                $stmt->bind_param(
                    'sss',
                    $deviceId,
                    $tokenHash,
                    $description
                );

                $stmt->execute();
                $stmt->close();

                $message = 'Device ' . $deviceId .
                    ' registered successfully. The API token must match app.ino.';

            /* Enable or disable a device */
            } elseif ($action === 'toggle_device') {
                $deviceId = trim((string)($_POST['device_id'] ?? ''));
                $enabled = filter_var(
                    $_POST['enabled'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (
                    !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $deviceId) ||
                    !in_array($enabled, [0, 1], true)
                ) {
                    throw new RuntimeException(
                        'Invalid device status request.'
                    );
                }

                $stmt = $conn->prepare(
                    'UPDATE esp_devices
                     SET enabled = ?
                     WHERE device_id = ?'
                );

                $stmt->bind_param('is', $enabled, $deviceId);
                $stmt->execute();
                $stmt->close();

                $message = ($enabled === 1
                    ? 'Device enabled: '
                    : 'Device disabled: ') . $deviceId . '.';

            /* Update description */
            } elseif ($action === 'update_description') {
                $deviceId = trim((string)($_POST['device_id'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));

                if ($deviceId === '') {
                    throw new RuntimeException('Device ID is required.');
                }

                if (strlen($description) > 100) {
                    throw new RuntimeException(
                        'Description must be 100 characters or fewer.'
                    );
                }

                $stmt = $conn->prepare(
                    'UPDATE esp_devices
                     SET description = ?
                     WHERE device_id = ?'
                );

                $stmt->bind_param('ss', $description, $deviceId);
                $stmt->execute();
                $stmt->close();

                $message = 'Description updated for ' . $deviceId . '.';

            /* Delete device and its assigned consumer */
            } elseif ($action === 'delete_device') {
                $deviceId = trim((string)($_POST['device_id'] ?? ''));

                if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $deviceId)) {
                    throw new RuntimeException('Invalid Device ID.');
                }

                $conn->begin_transaction();

                try {
                    // Confirm that the device is registered.
                    $stmt = $conn->prepare(
                        'SELECT device_id
                         FROM esp_devices
                         WHERE device_id = ?
                         FOR UPDATE'
                    );

                    $stmt->bind_param('s', $deviceId);
                    $stmt->execute();

                    $deviceExists =
                        $stmt->get_result()->num_rows > 0;

                    $stmt->close();

                    if (!$deviceExists) {
                        throw new RuntimeException(
                            'Device not found. It may already have been deleted.'
                        );
                    }

                    // Delete the assigned consumer, but never an admin.
                    $stmt = $conn->prepare(
                        "DELETE FROM web_users
                         WHERE device_id = ?
                           AND (role IS NULL OR role <> 'admin')"
                    );

                    $stmt->bind_param('s', $deviceId);
                    $stmt->execute();

                    $deletedConsumers = $stmt->affected_rows;
                    $stmt->close();

                    // Delete the registered device.
                    // Historical logs are deliberately retained.
                    $stmt = $conn->prepare(
                        'DELETE FROM esp_devices WHERE device_id = ?'
                    );

                    $stmt->bind_param('s', $deviceId);
                    $stmt->execute();

                    $deletedDevices = $stmt->affected_rows;
                    $stmt->close();

                    if ($deletedDevices !== 1) {
                        throw new RuntimeException(
                            'Device registration could not be deleted.'
                        );
                    }

                    $conn->commit();

                    $message = 'Device ' . $deviceId .
                        ' deleted successfully. ' .
                        $deletedConsumers .
                        ' assigned consumer account(s) deleted. ' .
                        'Historical readings have been retained.';

                } catch (Throwable $deleteError) {
                    $conn->rollback();
                    throw $deleteError;
                }

            } else {
                throw new RuntimeException('Unknown operation.');
            }

            $messageType = 'success';

        } catch (Throwable $e) {
            error_log(
                'Remote Data Logger admin2.php error: ' .
                $e->getMessage()
            );

            if (
                $e instanceof mysqli_sql_exception &&
                (int)$e->getCode() === 1062
            ) {
                $message = 'That Device ID is already registered.';
            } elseif ($e instanceof RuntimeException) {
                $message = $e->getMessage();
            } else {
                $message =
                    'Operation failed. Check the database connection, ' .
                    'table columns and database constraints.';
            }

            $messageType = 'error';
        }
    }
}

/* ---------------------------------------------------------
   REGISTERED DEVICE LIST
--------------------------------------------------------- */

$devices = [];

$deviceResult = $conn->query(
    'SELECT device_id, enabled, description, created_at
     FROM esp_devices
     ORDER BY device_id'
);

while ($device = $deviceResult->fetch_assoc()) {
    $devices[] = $device;
}

/* ---------------------------------------------------------
   READINGS FILTERS
--------------------------------------------------------- */

$filterDevice = trim((string)($_GET['device_id'] ?? ''));
$startDateTime = trim((string)($_GET['start_datetime'] ?? ''));
$endDateTime = trim((string)($_GET['end_datetime'] ?? ''));

$logs = [];

if (
    !admin2ValidDateTime($startDateTime) ||
    !admin2ValidDateTime($endDateTime)
) {
    $message = 'Please select valid start and end date/time values.';
    $messageType = 'error';

} elseif (
    $startDateTime !== '' &&
    $endDateTime !== '' &&
    $startDateTime > $endDateTime
) {
    $message = 'Start date/time must not be later than end date/time.';
    $messageType = 'error';

} else {
    $startSql = $startDateTime === ''
        ? ''
        : str_replace('T', ' ', $startDateTime) . ':00';

    $endSql = $endDateTime === ''
        ? ''
        : str_replace('T', ' ', $endDateTime) . ':59';

    $stmt = $conn->prepare(
        "SELECT id, device_id, value1, value2, value3,
                `Date`, `Time`, `TimeStamp`, timezone
         FROM logs
         WHERE (? = '' OR device_id = ?)
           AND (? = '' OR TIMESTAMP(`Date`, `Time`) >= ?)
           AND (? = '' OR TIMESTAMP(`Date`, `Time`) <= ?)
         ORDER BY TIMESTAMP(`Date`, `Time`) DESC, id DESC
         LIMIT 200"
    );

    $stmt->bind_param(
        'ssssss',
        $filterDevice, $filterDevice,
        $startSql, $startSql,
        $endSql, $endSql
    );

    $stmt->execute();
    $logResult = $stmt->get_result();

    while ($row = $logResult->fetch_assoc()) {
        $logs[] = $row;
    }

    $stmt->close();
}

/* ---------------------------------------------------------
   CHART DATA
--------------------------------------------------------- */

$chartRows = array_reverse($logs);
$chartLabels = [];
$chartValue1 = [];
$chartValue2 = [];
$chartValue3 = [];
$allChartValues = [];

foreach ($chartRows as $chartRow) {
    $chartLabels[] =
        (string)($chartRow['Date'] ?? '') . ' ' .
        (string)($chartRow['Time'] ?? '');

    $v1 = is_numeric($chartRow['value1'] ?? null)
        ? (float)$chartRow['value1'] : null;

    $v2 = is_numeric($chartRow['value2'] ?? null)
        ? (float)$chartRow['value2'] : null;

    $v3 = is_numeric($chartRow['value3'] ?? null)
        ? (float)$chartRow['value3'] : null;

    $chartValue1[] = $v1;
    $chartValue2[] = $v2;
    $chartValue3[] = $v3;

    foreach ([$v1, $v2, $v3] as $v) {
        if ($v !== null) {
            $allChartValues[] = $v;
        }
    }
}

$sum1 = array_sum(
    array_filter($chartValue1, static fn($v) => $v !== null)
);

$sum2 = array_sum(
    array_filter($chartValue2, static fn($v) => $v !== null)
);

$sum3 = array_sum(
    array_filter($chartValue3, static fn($v) => $v !== null)
);

$histLabels = [];
$histCounts = [];

if ($allChartValues) {
    $minVal = min($allChartValues);
    $maxVal = max($allChartValues);
    $binCount = 8;

    $binWidth = ($maxVal - $minVal) / $binCount;

    if ($binWidth <= 0) {
        $binWidth = 1;
    }

    $histCounts = array_fill(0, $binCount, 0);

    for ($i = 0; $i < $binCount; $i++) {
        $lo = $minVal + $i * $binWidth;
        $hi = $lo + $binWidth;

        $histLabels[] =
            number_format($lo, 2, '.', '') . ' - ' .
            number_format($hi, 2, '.', '');
    }

    foreach ($allChartValues as $v) {
        $idx = (int)floor(($v - $minVal) / $binWidth);

        if ($idx >= $binCount) $idx = $binCount - 1;
        if ($idx < 0) $idx = 0;

        $histCounts[$idx]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Logger - Manage Devices and View Logs</title>

<style>
*{box-sizing:border-box}
body{margin:0;background:#eef3f8;color:#263445;font-family:Arial,sans-serif}
.container{max-width:1450px;margin:25px auto;padding:0 16px}
header{background:#174a78;color:#fff;padding:20px 5%;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
header h1{margin:0 0 6px;font-size:24px}
.toplinks a,button,.button{display:inline-block;border:0;border-radius:6px;padding:10px 14px;background:#1769aa;color:#fff;text-decoration:none;cursor:pointer;font-size:14px;margin:3px}
.toplinks a{background:#34495e}
.panel{background:#fff;border-radius:10px;padding:22px;margin:20px 0;box-shadow:0 2px 8px #cbd5e1}
h2{color:#174a78;margin-top:0}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
label{display:block;font-weight:bold;margin-bottom:6px}
input,select{width:100%;padding:10px;border:1px solid #b8c7d6;border-radius:5px;font-size:15px}
.success,.error{padding:12px;border-radius:6px;margin:16px 0}
.success{background:#dcfce7;color:#166534}
.error{background:#fee2e2;color:#991b1b}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px}
th,td{padding:9px;border:1px solid #d7e0e8;text-align:left;white-space:nowrap}
th{background:#e3edf7}
tr:nth-child(even){background:#f8fafc}
.on{color:#16803c;font-weight:bold}
.off{color:#b91c1c;font-weight:bold}
.muted{font-size:13px;color:#64748b}
.charts-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:20px 0}
.chart-card{min-width:0;background:#fff;border:1px solid #d7e0e8;border-radius:8px;padding:14px;box-shadow:0 1px 4px #dbe4ee}
.chart-card h3{font-size:16px;color:#174a78;margin:0 0 12px}
.chart-card canvas{width:100%!important;height:280px!important}
.green{background:#16803c}
.red{background:#b91c1c}
.print-heading{display:none}
@media(max-width:700px){
 .charts-grid{grid-template-columns:1fr}
 .panel{padding:15px}
 header{padding:16px}
 .chart-card canvas{height:240px!important}
}
@media print{
 @page{size:landscape;margin:12mm}
 body{background:#fff;color:#000;font-size:10pt}
 header{background:#fff;color:#000;padding:0 0 10px}
 header h1{font-size:18pt}
 .toplinks,.no-print,.message-area{display:none!important}
 .container{max-width:none;margin:0;padding:0}
 .panel{box-shadow:none;border:0;border-radius:0;margin:0;padding:8px 0}
 #register-panel,#devices-panel{display:none!important}
 #readings-panel{display:block!important}
 .charts-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;break-inside:avoid}
 .chart-card{box-shadow:none;padding:5px;break-inside:avoid}
 .chart-card h3{font-size:9pt}
 .chart-card canvas{height:180px!important}
 .print-heading{display:block}
 .table-wrap{overflow:visible}
 table{font-size:8pt;width:100%;margin-top:8px}
 th,td{padding:4px;white-space:normal}
 th{background:#e5e7eb!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
 tr{break-inside:avoid}
}
</style>
</head>

<body>
<header>
 <div>
  <h1>ESP8266 Data Logger</h1>
  <div>Administrator: <?= h($_SESSION['username'] ?? '') ?> — Device Management and Logs</div>
 </div>
 <div class="toplinks no-print">
  <a href="index2.php">Dashboard</a>
  <a href="users.php">Manage Consumers</a>
  <a href="logout.php">Logout</a>
 </div>
</header>

<main class="container">

<div class="message-area">
<?php if ($message !== ''): ?>
<div class="<?= $messageType === 'error' ? 'error' : 'success' ?>">
 <?= h($message) ?>
</div>
<?php endif; ?>
</div>

<section class="panel" id="register-panel">
<h2>Register a New ESP8266 Device</h2>

<p class="muted">
Enter the Device ID and the API token configured in that ESP8266 firmware.
Only a SHA-256 hash of the token is stored. The original token is never
shown in the registered-device list.
</p>

<form method="post" action="admin2.php">
<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
<input type="hidden" name="action" value="add_device">

<div class="form-grid">
 <div>
  <label for="device_id">Device ID *</label>
  <input id="device_id" name="device_id" maxlength="32"
         placeholder="Example: ESP0001" required>
 </div>

 <div>
  <label for="api_token">API token *</label>
  <input id="api_token" name="api_token" type="password"
         minlength="8" maxlength="200"
         autocomplete="new-password" required>
  <small class="muted">Must match the token in app.ino exactly.</small>
 </div>

 <div>
  <label for="description">Description</label>
  <input id="description" name="description" maxlength="100"
         placeholder="Example: Consumer A logger">
 </div>
</div>

<button type="submit" class="green">Register Device</button>
</form>
</section>

<section class="panel" id="devices-panel">
<h2>Registered Devices</h2>

<p class="muted">
Disabling a device prevents new uploads when enforced by the device
authentication endpoint. Deleting a device also deletes its assigned
non-admin consumer account, if any. Historical readings are retained.
</p>

<div class="table-wrap">
<table>
<thead>
<tr>
 <th>Device ID</th>
 <th>Status</th>
 <th>Description</th>
 <th>Registered</th>
 <th>Enable / Disable</th>
 <th>Delete Device</th>
</tr>
</thead>

<tbody>
<?php if (!$devices): ?>
<tr><td colspan="6">No devices registered yet.</td></tr>

<?php else: foreach ($devices as $device): ?>
<tr>
 <td><strong><?= h($device['device_id']) ?></strong></td>

 <td>
 <?= (int)$device['enabled'] === 1
     ? '<span class="on">ENABLED</span>'
     : '<span class="off">DISABLED</span>' ?>
 </td>

 <td><?= h($device['description'] ?? '') ?></td>
 <td><?= h($device['created_at'] ?? '') ?></td>

 <td>
  <form method="post" action="admin2.php"
        onsubmit="return confirm('Change device status?')">
   <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
   <input type="hidden" name="action" value="toggle_device">
   <input type="hidden" name="device_id" value="<?= h($device['device_id']) ?>">
   <input type="hidden" name="enabled"
          value="<?= (int)$device['enabled'] === 1 ? '0' : '1' ?>">

   <button type="submit"
     class="<?= (int)$device['enabled'] === 1 ? 'red' : 'green' ?>">
     <?= (int)$device['enabled'] === 1 ? 'Disable' : 'Enable' ?>
   </button>
  </form>
 </td>

 <td>
  <form method="post" action="admin2.php"
        onsubmit="return confirm(
          'PERMANENT DELETE: Remove device <?= h($device['device_id']) ?> and its assigned consumer account, if any? Historical readings will be retained. This cannot be undone. Continue?'
        )">
   <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
   <input type="hidden" name="action" value="delete_device">
   <input type="hidden" name="device_id" value="<?= h($device['device_id']) ?>">

   <button type="submit" class="red">Delete</button>
  </form>
 </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</section>

<section class="panel" id="readings-panel">
<h2>View ESP8266 Readings</h2>

<form method="get" action="admin2.php" class="form-grid no-print">
 <div>
  <label for="filter_device_id">Device</label>
  <select id="filter_device_id" name="device_id">
   <option value="">All devices</option>
   <?php foreach ($devices as $device): ?>
   <option value="<?= h($device['device_id']) ?>"
     <?= $filterDevice === $device['device_id'] ? 'selected' : '' ?>>
     <?= h($device['device_id']) ?>
   </option>
   <?php endforeach; ?>
  </select>
 </div>

 <div>
  <label for="start_datetime">Start date and time (installation local time)</label>
  <input type="datetime-local" id="start_datetime" name="start_datetime"
         value="<?= h($startDateTime) ?>" step="60">
 </div>

 <div>
  <label for="end_datetime">End date and time (installation local time)</label>
  <input type="datetime-local" id="end_datetime" name="end_datetime"
         value="<?= h($endDateTime) ?>" step="60">
 </div>

 <div>
  <button type="submit" name="search_readings" value="1">Search Readings</button>
  <a class="button" href="admin2.php">Clear Filters</a>
 </div>
</form>

<div class="no-print" style="margin-top:10px">
 <button type="button" onclick="window.print()">Print Searched Data</button>
</div>

<div class="print-heading">
 <h2>ESP8266 Readings Report</h2>
 <p>
 Device: <?= $filterDevice !== '' ? h($filterDevice) : 'All devices' ?> |
 Start: <?= $startDateTime !== ''
     ? h(str_replace('T', ' ', $startDateTime)) : 'Not specified' ?> |
 End: <?= $endDateTime !== ''
     ? h(str_replace('T', ' ', $endDateTime)) : 'Not specified' ?>
 </p>
 <p>Matching rows shown: <?= count($logs) ?> (maximum 200)</p>
</div>

<p class="muted no-print">
Showing at most the latest 200 matching readings. The filters use the
installation-local Date and Time values. The selected end minute includes
seconds through 59. All graphs use these same searched readings.
</p>

<div class="charts-grid no-print" id="readings-charts">
 <div class="chart-card"><h3>Line Graph — Readings Over Time</h3><canvas id="readingsLineChart"></canvas></div>
 <div class="chart-card"><h3>Bar Graph — Values by Reading</h3><canvas id="readingsBarChart"></canvas></div>
 <div class="chart-card"><h3>Pie Chart — Total of Each Value</h3><canvas id="readingsPieChart"></canvas></div>
 <div class="chart-card"><h3>Histogram — Distribution of All Values</h3><canvas id="readingsHistogram"></canvas></div>
</div>

<?php if (!$logs): ?>
<p class="muted no-print">No matching readings were found.</p>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  const labels = <?= json_encode(
      $chartLabels,
      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
  ) ?>;

  const value1 = <?= json_encode(
      $chartValue1,
      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
  ) ?>;

  const value2 = <?= json_encode(
      $chartValue2,
      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
  ) ?>;

  const value3 = <?= json_encode(
      $chartValue3,
      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
  ) ?>;

  const pieTotals = <?= json_encode([$sum1, $sum2, $sum3]) ?>;
  const histLabels = <?= json_encode(
      $histLabels,
      JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
  ) ?>;
  const histCounts = <?= json_encode($histCounts) ?>;

  if (typeof Chart === 'undefined' || labels.length === 0) return;

  const commonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {mode: 'index', intersect: false},
    plugins: {legend: {position: 'bottom'}}
  };

  new Chart(document.getElementById('readingsLineChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        {label: 'Value 1', data: value1, spanGaps: true, tension: 0.2},
        {label: 'Value 2', data: value2, spanGaps: true, tension: 0.2},
        {label: 'Value 3', data: value3, spanGaps: true, tension: 0.2}
      ]
    },
    options: {
      ...commonOptions,
      scales: {
        x: {
          title: {display: true, text: 'Date and local time'},
          ticks: {maxTicksLimit: 8}
        },
        y: {title: {display: true, text: 'Reading value'}}
      }
    }
  });

  new Chart(document.getElementById('readingsBarChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {label: 'Value 1', data: value1},
        {label: 'Value 2', data: value2},
        {label: 'Value 3', data: value3}
      ]
    },
    options: {
      ...commonOptions,
      scales: {
        x: {ticks: {maxTicksLimit: 10}},
        y: {
          beginAtZero: false,
          title: {display: true, text: 'Reading value'}
        }
      }
    }
  });

  new Chart(document.getElementById('readingsPieChart'), {
    type: 'pie',
    data: {
      labels: ['Value 1 total', 'Value 2 total', 'Value 3 total'],
      datasets: [{data: pieTotals}]
    },
    options: commonOptions
  });

  if (histLabels.length) {
    new Chart(document.getElementById('readingsHistogram'), {
      type: 'bar',
      data: {
        labels: histLabels,
        datasets: [{
          label: 'Number of values',
          data: histCounts
        }]
      },
      options: {
        ...commonOptions,
        scales: {
          x: {title: {display: true, text: 'Value ranges'}},
          y: {
            beginAtZero: true,
            ticks: {precision: 0},
            title: {display: true, text: 'Frequency'}
          }
        }
      }
    });
  }
})();
</script>

<div class="table-wrap">
<table>
<thead>
<tr>
 <th>ID</th><th>Device</th><th>Value 1</th><th>Value 2</th>
 <th>Value 3</th><th>Date</th><th>Local Time</th>
 <th>Timezone</th><th>UTC Timestamp</th>
</tr>
</thead>
<tbody>
<?php if (!$logs): ?>
<tr><td colspan="9">No readings found for these filters.</td></tr>
<?php else: foreach ($logs as $row): ?>
<tr>
 <td><?= h($row['id']) ?></td>
 <td><?= h($row['device_id']) ?></td>
 <td><?= h($row['value1']) ?></td>
 <td><?= h($row['value2']) ?></td>
 <td><?= h($row['value3']) ?></td>
 <td><?= h($row['Date']) ?></td>
 <td><?= h($row['Time']) ?></td>
 <td><?= h($row['timezone']) ?></td>
 <td><?= h($row['TimeStamp']) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

</section>
</main>
</body>
</html>


