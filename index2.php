<?php
require_once __DIR__ . '/auth_check.php';

$role = (string)$_SESSION['role'];
$isAdmin = $role === 'admin';
$username = (string)$_SESSION['username'];
$deviceId = (string)($_SESSION['device_id'] ?? '');
$validFrom = (string)($_SESSION['login_valid_from'] ?? '');
$validUntil = (string)($_SESSION['login_valid_until'] ?? '');

function dashboardDateTime(
    string $date,
    string $time,
    string $timezone
): string {
    if ($date === '' || $time === '') {
        return 'Not available';
    }

    try {
        $zone = new DateTimeZone(
            $timezone !== '' ? $timezone : 'Asia/Kolkata'
        );

        return (new DateTimeImmutable(
            $date . ' ' . $time,
            $zone
        ))->format('d-M-Y h:i:s A');

    } catch (Throwable $e) {
        return trim($date . ' ' . $time);
    }
}

function dashboardDisplayPeriod(string $value): string {
    return trim($value) === '' ? 'Not specified' : trim($value);
}

function dashboardSearchValidDateTime(string $value): bool {
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $value
    );

    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && (
            $errors === false ||
            (
                $errors['warning_count'] === 0 &&
                $errors['error_count'] === 0
            )
        )
        && $date->format('Y-m-d\TH:i') === $value;
}

/*
 * Get the installation timezone from the most recent reading
 * for this consumer's assigned device.
 */
$installationTimezone = 'Asia/Kolkata';

if (!$isAdmin && $deviceId !== '') {
    $tzStmt = $conn->prepare(
        'SELECT timezone
         FROM logs
         WHERE device_id = ? AND timezone <> ""
         ORDER BY id DESC
         LIMIT 1'
    );

    $tzStmt->bind_param('s', $deviceId);
    $tzStmt->execute();
    $tzStmt->bind_result($storedTimezone);

    if (
        $tzStmt->fetch() &&
        is_string($storedTimezone) &&
        $storedTimezone !== ''
    ) {
        try {
            new DateTimeZone($storedTimezone);
            $installationTimezone = $storedTimezone;
        } catch (Throwable $e) {
            // Keep the fallback timezone if the stored value is invalid.
        }
    }

    $tzStmt->close();
}

try {
    $zone = new DateTimeZone($installationTimezone);
} catch (Throwable $e) {
    $zone = new DateTimeZone('Asia/Kolkata');
    $installationTimezone = 'Asia/Kolkata';
}

$now = new DateTimeImmutable('now', $zone);
$accountStatus = 'ACTIVE';
$statusColor = '#16803c';

try {
    if ($validFrom !== '') {
        $start = new DateTimeImmutable($validFrom, $zone);

        if ($now < $start) {
            $accountStatus = 'NOT YET VALID';
            $statusColor = '#b45309';
        }
    }

    if ($validUntil !== '') {
        $end = new DateTimeImmutable($validUntil, $zone);

        if ($now > $end) {
            $accountStatus = 'EXPIRED';
            $statusColor = '#b91c1c';
        }
    }
} catch (Throwable $e) {
    $accountStatus = 'CHECK VALIDITY DATES';
    $statusColor = '#b45309';
}

/* Latest reading for the assigned device. */
$latestLocalDateTime = 'No ESP32 reading available';
$latestTimezone = 'Not available';
$latestValues = ['—', '—', '—'];

if ($deviceId !== '') {
    $stmt = $conn->prepare(
        'SELECT value1, value2, value3, `Date`, `Time`, timezone
         FROM logs
         WHERE device_id = ?
         ORDER BY id DESC
         LIMIT 1'
    );

    $stmt->bind_param('s', $deviceId);
    $stmt->execute();

    $stmt->bind_result(
        $v1,
        $v2,
        $v3,
        $latestDate,
        $latestTime,
        $latestZone
    );

    if ($stmt->fetch()) {
        $latestValues = [
            (string)$v1,
            (string)$v2,
            (string)$v3
        ];

        $latestTimezone = (string)($latestZone ?? 'Not available');

        $latestLocalDateTime = dashboardDateTime(
            (string)$latestDate,
            (string)$latestTime,
            $latestTimezone
        );
    }

    $stmt->close();
}

/* Dashboard totals and recent readings. */
if ($isAdmin) {
    $totalLogs = (int)$conn
        ->query('SELECT COUNT(*) FROM logs')
        ->fetch_row()[0];

    $totalDevices = (int)$conn
        ->query('SELECT COUNT(*) FROM esp_devices')
        ->fetch_row()[0];

    $enabledDevices = (int)$conn
        ->query('SELECT COUNT(*) FROM esp_devices WHERE enabled = 1')
        ->fetch_row()[0];

    $dashboardLogs = $conn->query(
        'SELECT id, device_id, value1, value2, value3,
                `Date`, `Time`, timezone
         FROM logs
         ORDER BY id DESC
         LIMIT 20'
    );

} else {
    $countStmt = $conn->prepare(
        'SELECT COUNT(*) FROM logs WHERE device_id = ?'
    );

    $countStmt->bind_param('s', $deviceId);
    $countStmt->execute();
    $countStmt->bind_result($totalLogs);
    $countStmt->fetch();
    $countStmt->close();

    $totalLogs = (int)$totalLogs;
    $totalDevices = $deviceId !== '' ? 1 : 0;
    $enabledDevices = $deviceId !== '' ? 1 : 0;

    $stmt = $conn->prepare(
        'SELECT id, device_id, value1, value2, value3,
                `Date`, `Time`, timezone
         FROM logs
         WHERE device_id = ?
         ORDER BY id DESC
         LIMIT 20'
    );

    $stmt->bind_param('s', $deviceId);
    $stmt->execute();
    $dashboardLogs = $stmt->get_result();
}

/* Consumer report: always restrict data to the assigned device. */
$reportStart = trim((string)($_GET['start_datetime'] ?? ''));
$reportEnd = trim((string)($_GET['end_datetime'] ?? ''));
$reportError = '';
$reportRows = [];

if (!$isAdmin) {
    if (
        !dashboardSearchValidDateTime($reportStart) ||
        !dashboardSearchValidDateTime($reportEnd)
    ) {
        $reportError = 'Please select valid start and end date/time values.';

    } elseif (
        $reportStart !== '' &&
        $reportEnd !== '' &&
        $reportStart > $reportEnd
    ) {
        $reportError = 'Start date/time must not be later than end date/time.';

    } else {
        $reportStartSql = $reportStart === ''
            ? ''
            : str_replace('T', ' ', $reportStart) . ':00';

        $reportEndSql = $reportEnd === ''
            ? ''
            : str_replace('T', ' ', $reportEnd) . ':59';

        $reportStmt = $conn->prepare(
            "SELECT id, device_id, value1, value2, value3,
                    `Date`, `Time`, `TimeStamp`, timezone
             FROM logs
             WHERE device_id = ?
               AND (? = '' OR TIMESTAMP(`Date`, `Time`) >= ?)
               AND (? = '' OR TIMESTAMP(`Date`, `Time`) <= ?)
             ORDER BY TIMESTAMP(`Date`, `Time`) DESC, id DESC
             LIMIT 200"
        );

        $reportStmt->bind_param(
            'sssss',
            $deviceId,
            $reportStartSql,
            $reportStartSql,
            $reportEndSql,
            $reportEndSql
        );

        $reportStmt->execute();
        $reportResult = $reportStmt->get_result();

        while ($reportRow = $reportResult->fetch_assoc()) {
            $reportRows[] = $reportRow;
        }

        $reportStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="15">
<title>Data Logger Dashboard</title>

<style>
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0;color:#263445}
header{background:#174a78;color:white;padding:20px 5%;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
main{max-width:1200px;margin:25px auto;padding:0 15px}
.panel,.card{background:white;padding:22px;border-radius:10px;box-shadow:0 2px 8px #d8e0e8;margin-bottom:20px}
.period-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.period-item{background:#f8fafc;border:1px solid #d8e0e8;border-radius:8px;padding:15px;overflow-wrap:anywhere}
.period-value{margin-top:8px;font-weight:bold}
.status{color:<?= h($statusColor) ?>;font-size:18px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px}
.number{font-size:30px;color:#1769aa;font-weight:bold;margin-top:10px}
nav{margin:18px 0}
nav a,.button{display:inline-block;background:#1769aa;color:white;padding:10px 15px;text-decoration:none;border-radius:5px;margin:4px;border:0;cursor:pointer}
nav a:hover,.button:hover{background:#104d80}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;background:white;font-size:14px}
th,td{padding:10px;border:1px solid #d8e0e8;text-align:left;white-space:nowrap}
th{background:#e3edf7}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.form-grid label{display:block;font-weight:bold;margin-bottom:6px}
.form-grid input,.form-grid select{width:100%;padding:10px;border:1px solid #b8c7d6;border-radius:5px;font-size:15px}
.charts-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:20px 0}
.chart-card{min-width:0;background:#fff;border:1px solid #d7e0e8;border-radius:8px;padding:14px;box-shadow:0 1px 4px #dbe4ee}
.chart-card h3{font-size:16px;color:#174a78;margin:0 0 12px}
.chart-card canvas{width:100%!important;height:280px!important}
.muted{color:#667788;font-size:13px}
@media(max-width:700px){
 .charts-grid{grid-template-columns:1fr}
 .panel{padding:15px}
 .chart-card canvas{height:240px!important}
}
.tag{display:inline-block;background:#e7f0fb;color:#174a7e;padding:5px 9px;border-radius:20px;font-weight:bold}
.print-heading{display:none}
@media print{
 @page{size:landscape;margin:12mm}
 body{background:#fff;color:#000;font-size:10pt}
 header{background:#fff;color:#000;padding:0 0 10px}
 header h2{font-size:18pt}
 main{max-width:none;margin:0;padding:0}
 .panel{box-shadow:none;border:0;border-radius:0;margin:0;padding:8px 0}
 body>header,main>section:not(#readings-panel),main>.cards,main>nav{display:none!important}
 #readings-panel{display:block!important}
 .no-print{display:none!important}
 .charts-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;break-inside:avoid}
 .chart-card{box-shadow:none;padding:5px;break-inside:avoid}
 .chart-card h3{font-size:9pt}
 .chart-card canvas{height:180px!important}
 .print-heading{display:block}
 .table-wrap{overflow:visible}
 table{font-size:8pt;width:100%;margin-top:8px}
}
</style>
</head>

<body>
<header>
<div>
<h2 style="margin:0">ESP32 Data Logger</h2>
<p style="margin:8px 0 0">
Welcome, <?= h($username) ?> ·
<?= $isAdmin ? 'Administrator Dashboard' : 'Consumer Dashboard' ?>
</p>
</div>
<a class="button" href="logout.php">Logout</a>
</header>

<main>

<section class="panel">
<h2><?= $isAdmin ? 'Administrator Overview' : 'Your Consumer Account' ?></h2>

<div class="period-grid">
<div class="period-item">
Username
<div class="period-value"><?= h($username) ?></div>
</div>

<div class="period-item">
Account Valid From
<div class="period-value"><?= h(dashboardDisplayPeriod($validFrom)) ?></div>
</div>

<div class="period-item">
Account Valid Until
<div class="period-value"><?= h(dashboardDisplayPeriod($validUntil)) ?></div>
</div>

<div class="period-item">
Account Status
<div class="period-value status"><?= h($accountStatus) ?></div>
</div>

<?php if (!$isAdmin): ?>
<div class="period-item">
Assigned ESP32 Device
<div class="period-value"><span class="tag"><?= h($deviceId) ?></span></div>
</div>

<div class="period-item">
Latest Device Date and Time
<div class="period-value"><?= h($latestLocalDateTime) ?></div>
<small>Timezone: <?= h($latestTimezone) ?></small>
</div>

<div class="period-item">
Latest Values
<div class="period-value">
Value 1: <?= h($latestValues[0]) ?><br>
Value 2: <?= h($latestValues[1]) ?><br>
Value 3: <?= h($latestValues[2]) ?>
</div>
</div>
<?php endif; ?>
</div>
</section>

<div class="cards">
<div class="card">
<?= $isAdmin ? 'Total readings in all devices' : 'Readings for your device' ?>
<div class="number"><?= (int)$totalLogs ?></div>
</div>

<div class="card">
<?= $isAdmin ? 'Registered devices' : 'Your assigned devices' ?>
<div class="number"><?= (int)$totalDevices ?></div>
</div>

<div class="card">
<?= $isAdmin ? 'Enabled devices' : 'Device status' ?>
<div class="number">
<?= $isAdmin ? (int)$enabledDevices : ($deviceId !== '' ? 'Assigned' : 'Not assigned') ?>
</div>
</div>
</div>

<nav>
<a href="index2.php">Refresh Dashboard</a>
<a href="output.php">Reports and Graphs</a>

<?php if ($isAdmin): ?>
<a href="admin2.php">Manage Devices and View Logs</a>
<a href="users.php">Manage Consumers</a>
<?php endif; ?>
</nav>

<?php if (!$isAdmin): ?>
<section class="panel" id="readings-panel">

<h2>View ESP32 Readings</h2>

<form method="get" action="index2.php" class="form-grid no-print">
<div>
<label for="filter_device_id">Device</label>
<select id="filter_device_id" aria-label="Assigned device" disabled>
<option selected><?= h($deviceId !== '' ? $deviceId : 'No device assigned') ?></option>
</select>
</div>

<div>
<label for="start_datetime">Start date and time (installation local time)</label>
<input
    type="datetime-local"
    id="start_datetime"
    name="start_datetime"
    value="<?= h($reportStart) ?>"
    step="60"
>
</div>

<div>
<label for="end_datetime">End date and time (installation local time)</label>
<input
    type="datetime-local"
    id="end_datetime"
    name="end_datetime"
    value="<?= h($reportEnd) ?>"
    step="60"
>
</div>

<div style="align-self:end">
<button type="submit" name="search_readings" value="1" class="button">Search Readings</button>
<a class="button" href="index2.php#readings-panel">Clear Filters</a>
</div>
</form>

<div class="no-print" style="margin-top:10px">
<button type="button" class="button" onclick="window.print()">Print Searched Data</button>
</div>

<div class="print-heading">
<h2>ESP32 Readings Report</h2>
<p>
Device: <?= h($deviceId !== '' ? $deviceId : 'No device assigned') ?> |
Start: <?= $reportStart !== '' ? h(str_replace('T', ' ', $reportStart)) : 'Not specified' ?> |
End: <?= $reportEnd !== '' ? h(str_replace('T', ' ', $reportEnd)) : 'Not specified' ?>
</p>
<p>Matching rows shown: <?= count($reportRows) ?> (maximum 200)</p>
</div>

<?php if ($reportError !== ''): ?>
<p class="no-print" style="background:#fee2e2;color:#991b1b;padding:12px;border-radius:6px">
<?= h($reportError) ?>
</p>
<?php endif; ?>

<p class="muted no-print">
Showing at most the latest 200 matching readings. Filters use the installation-local Date and Time values stored by the ESP32. The selected end minute is included through second 59. All graphs and the table use these same searched readings.
</p>

<?php
$reportChartRows = array_reverse($reportRows);
$reportLabels = [];
$reportV1 = [];
$reportV2 = [];
$reportV3 = [];
$reportAllValues = [];

foreach ($reportChartRows as $cr) {
    $reportLabels[] = (string)$cr['Date'] . ' ' . (string)$cr['Time'];

    $a = is_numeric($cr['value1'] ?? null) ? (float)$cr['value1'] : null;
    $b = is_numeric($cr['value2'] ?? null) ? (float)$cr['value2'] : null;
    $c = is_numeric($cr['value3'] ?? null) ? (float)$cr['value3'] : null;

    $reportV1[] = $a;
    $reportV2[] = $b;
    $reportV3[] = $c;

    foreach ([$a, $b, $c] as $vv) {
        if ($vv !== null) {
            $reportAllValues[] = $vv;
        }
    }
}

$reportSums = [
    array_sum(array_filter($reportV1, static fn($v) => $v !== null)),
    array_sum(array_filter($reportV2, static fn($v) => $v !== null)),
    array_sum(array_filter($reportV3, static fn($v) => $v !== null))
];

$reportHistLabels = [];
$reportHistCounts = [];

if ($reportAllValues) {
    $lo = min($reportAllValues);
    $hi = max($reportAllValues);
    $bins = 8;
    $width = ($hi - $lo) / $bins;

    if ($width <= 0) {
        $width = 1;
    }

    $reportHistCounts = array_fill(0, $bins, 0);

    for ($i = 0; $i < $bins; $i++) {
        $reportHistLabels[] =
            number_format($lo + $i * $width, 2, '.', '') .
            ' – ' .
            number_format($lo + ($i + 1) * $width, 2, '.', '');
    }

    foreach ($reportAllValues as $vv) {
        $idx = (int)floor(($vv - $lo) / $width);

        if ($idx >= $bins) {
            $idx = $bins - 1;
        }

        if ($idx < 0) {
            $idx = 0;
        }

        $reportHistCounts[$idx]++;
    }
}
?>

<div class="charts-grid no-print" id="readings-charts">
<div class="chart-card">
<h3>Line Graph — Readings Over Time</h3>
<canvas id="consumerLineChart"></canvas>
</div>

<div class="chart-card">
<h3>Bar Graph — Values by Reading</h3>
<canvas id="consumerBarChart"></canvas>
</div>

<div class="chart-card">
<h3>Pie Chart — Total of Each Value</h3>
<canvas id="consumerPieChart"></canvas>
</div>

<div class="chart-card">
<h3>Histogram — Distribution of All Values</h3>
<canvas id="consumerHistogram"></canvas>
</div>
</div>

<?php if (!$reportRows): ?>
<p class="muted no-print">Graphs will appear after matching readings are found.</p>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
(function () {
    const labels = <?= json_encode($reportLabels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
    const v1 = <?= json_encode($reportV1) ?>;
    const v2 = <?= json_encode($reportV2) ?>;
    const v3 = <?= json_encode($reportV3) ?>;
    const sums = <?= json_encode($reportSums) ?>;
    const histLabels = <?= json_encode($reportHistLabels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
    const histCounts = <?= json_encode($reportHistCounts) ?>;

    if (typeof Chart === 'undefined' || !labels.length) {
        return;
    }

    const common = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    };

    new Chart(document.getElementById('consumerLineChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Value 1', data: v1, spanGaps: true, tension: .2 },
                { label: 'Value 2', data: v2, spanGaps: true, tension: .2 },
                { label: 'Value 3', data: v3, spanGaps: true, tension: .2 }
            ]
        },
        options: {
            ...common,
            scales: {
                x: {
                    title: { display: true, text: 'Date and local time' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    title: { display: true, text: 'Reading value' }
                }
            }
        }
    });

    new Chart(document.getElementById('consumerBarChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Value 1', data: v1 },
                { label: 'Value 2', data: v2 },
                { label: 'Value 3', data: v3 }
            ]
        },
        options: {
            ...common,
            scales: {
                x: { ticks: { maxTicksLimit: 10 } },
                y: {
                    title: { display: true, text: 'Reading value' }
                }
            }
        }
    });

    new Chart(document.getElementById('consumerPieChart'), {
        type: 'pie',
        data: {
            labels: ['Value 1 total', 'Value 2 total', 'Value 3 total'],
            datasets: [{ data: sums }]
        },
        options: common
    });

    if (histLabels.length) {
        new Chart(document.getElementById('consumerHistogram'), {
            type: 'bar',
            data: {
                labels: histLabels,
                datasets: [{
                    label: 'Number of values',
                    data: histCounts
                }]
            },
            options: {
                ...common,
                scales: {
                    x: {
                        title: { display: true, text: 'Value ranges' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        title: { display: true, text: 'Frequency' }
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
<th>ID</th>
<th>Device</th>
<th>Value 1</th>
<th>Value 2</th>
<th>Value 3</th>
<th>Date</th>
<th>Local Time</th>
<th>Timezone</th>
<th>UTC Timestamp</th>
</tr>
</thead>

<tbody>
<?php if (!$reportRows): ?>
<tr><td colspan="9">No readings found for these filters.</td></tr>

<?php else: foreach ($reportRows as $rr): ?>
<tr>
<td><?= h($rr['id']) ?></td>
<td><?= h($rr['device_id']) ?></td>
<td><?= h($rr['value1']) ?></td>
<td><?= h($rr['value2']) ?></td>
<td><?= h($rr['value3']) ?></td>
<td><?= h($rr['Date']) ?></td>
<td><?= h($rr['Time']) ?></td>
<td><?= h($rr['timezone']) ?></td>
<td><?= h($rr['TimeStamp']) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

</section>
<?php endif; ?>

<section class="panel">
<h2>
<?= $isAdmin
    ? 'Latest 20 Readings — All Devices'
    : 'Latest 20 Readings — ' . h($deviceId) ?>
</h2>

<p>
<small>
This dashboard refreshes every 15 seconds. Consumer accounts can see readings and totals for their assigned device only.
</small>
</p>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>ID</th>
<th>Device</th>
<th>Value 1</th>
<th>Value 2</th>
<th>Value 3</th>
<th>Date</th>
<th>Time</th>
<th>Timezone</th>
</tr>
</thead>

<tbody>
<?php
$hasRows = false;

while ($row = $dashboardLogs->fetch_assoc()):
    $hasRows = true;
?>
<tr>
<td><?= h($row['id']) ?></td>
<td><?= h($row['device_id']) ?></td>
<td><?= h($row['value1']) ?></td>
<td><?= h($row['value2']) ?></td>
<td><?= h($row['value3']) ?></td>
<td><?= h($row['Date']) ?></td>
<td><?= h($row['Time']) ?></td>
<td><?= h($row['timezone']) ?></td>
</tr>
<?php endwhile; ?>

<?php if (!$hasRows): ?>
<tr>
<td colspan="8">No readings found for the selected account/device.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

</main>
</body>
</html>