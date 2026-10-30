<?php
/*
 * Data Logger - Secure Reports and Graphs
 * Administrator: all devices. Consumer: assigned device only.
 * Uses the existing config.php and auth_check.php.
 */
require_once __DIR__ . '/auth_check.php';
date_default_timezone_set('Asia/Kolkata');

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$deviceId = trim((string)($_SESSION['device_id'] ?? ''));
$zone = new DateTimeZone('Asia/Kolkata');
$today = (new DateTimeImmutable('today', $zone))->format('Y-m-d');
// datetime-local lets the administrator/consumer filter by both date and time.
$fromDateTime = trim((string)($_GET['from_datetime'] ?? ($today . 'T00:00')));
$toDateTime = trim((string)($_GET['to_datetime'] ?? ($today . 'T23:59')));
$error = '';
$rows = [];

function outputValidDateTime(string $value): bool {
    if ($value === '') return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, new DateTimeZone('Asia/Kolkata'));
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d\\TH:i') === $value;
}

if (!outputValidDateTime($fromDateTime) || !outputValidDateTime($toDateTime)) {
    $error = 'Please select valid start and end date/time values.';
} elseif ($fromDateTime > $toDateTime) {
    $error = 'From date/time must not be later than To date/time.';
} else {
    // Include the full selected end minute (through second 59).
    $fromSql = str_replace('T', ' ', $fromDateTime) . ':00';
    $toSql = str_replace('T', ' ', $toDateTime) . ':59';
    try {
        if ($isAdmin) {
            $stmt = $conn->prepare(
                "SELECT id, device_id, value1, value2, value3, `Date`, `Time`, timezone
                 FROM logs
                 WHERE TIMESTAMP(`Date`, `Time`) BETWEEN ? AND ?
                 ORDER BY `Date` DESC, `Time` DESC, id DESC
                 LIMIT 1000"
            );
            $stmt->bind_param('ss', $fromSql, $toSql);
        } else {
            // The consumer's device ID is taken only from the authenticated session.
            $stmt = $conn->prepare(
                "SELECT id, device_id, value1, value2, value3, `Date`, `Time`, timezone
                 FROM logs
                 WHERE device_id = ? AND TIMESTAMP(`Date`, `Time`) BETWEEN ? AND ?
                 ORDER BY `Date` DESC, `Time` DESC, id DESC
                 LIMIT 1000"
            );
            $stmt->bind_param('sss', $deviceId, $fromSql, $toSql);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        // Results were queried newest-first; reverse so chart points run in time order.
        $rows = array_reverse($rows);
    } catch (Throwable $e) {
        error_log('Data Logger output.php query error: ' . $e->getMessage());
        $error = 'Unable to retrieve report data. Please check the PHP error log.';
    }
}

$chartRows = [];
$sum1 = 0.0; $sum2 = 0.0; $sum3 = 0.0;
foreach ($rows as $row) {
    $v1 = is_numeric($row['value1'] ?? null) ? (float)$row['value1'] : null;
    $v2 = is_numeric($row['value2'] ?? null) ? (float)$row['value2'] : null;
    $v3 = is_numeric($row['value3'] ?? null) ? (float)$row['value3'] : null;
    if ($v1 !== null) $sum1 += $v1;
    if ($v2 !== null) $sum2 += $v2;
    if ($v3 !== null) $sum3 += $v3;
    $chartRows[] = [
        'label' => trim((string)($row['Date'] ?? '') . ' ' . (string)($row['Time'] ?? '')),
        'value1' => $v1,
        'value2' => $v2,
        'value3' => $v3
    ];
}
$chartJson = json_encode($chartRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($chartJson === false) $chartJson = '[]';
$displayScope = $isAdmin ? 'All devices (administrator access)' : 'Assigned device: ' . $deviceId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Logger Reports and Graphs</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:18px;font-family:Arial,Helvetica,sans-serif;background:#eef3f8;color:#253447}.container{max-width:1250px;margin:16px auto}.panel{background:#fff;border-radius:12px;padding:20px;margin:0 0 18px;box-shadow:0 3px 12px #0000000d}h1,h2{color:#174a7e;margin-top:0}.filters{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.field{min-width:180px;flex:1}label{display:block;font-weight:bold;margin-bottom:6px}input{width:100%;padding:10px;border:1px solid #bbc8d6;border-radius:6px;font-size:15px}button,.button{display:inline-block;border:0;border-radius:6px;background:#1769aa;color:#fff;padding:11px 15px;font-size:15px;text-decoration:none;cursor:pointer}.button.gray{background:#536273}.muted{color:#637487;font-size:13px}.error{background:#ffe7e7;color:#8c1a1a;padding:12px;border-radius:7px}.summary{display:flex;gap:12px;flex-wrap:wrap}.summary div{background:#f5f9fd;border:1px solid #d7e3ef;border-radius:8px;padding:12px;flex:1;min-width:150px}.summary strong{display:block;font-size:22px;color:#1769aa;margin-top:6px}.chart-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:16px}.chart-box{min-width:0;border:1px solid #d8e2ed;border-radius:9px;padding:12px;min-height:320px}.chart-box h3{text-align:center;font-size:16px;color:#33465a}.chart-box canvas{width:100%!important;max-height:280px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border:1px solid #d7e0e8;padding:8px;text-align:left;white-space:nowrap}th{background:#174a7e;color:#fff}tr:nth-child(even){background:#f7f9fb}.toplinks{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}@media(max-width:600px){body{padding:8px}.panel{padding:14px}.chart-grid{grid-template-columns:1fr}}
</style>
</head>
<body><main class="container">
<section class="panel">
<h1>ESP8266 Data Logger — Reports and Graphs</h1>
<p><strong><?= h($displayScope) ?></strong></p>
<p class="muted">The report is limited to 1,000 readings for the selected date/time range. You can filter by both date and time. Consumer accounts are restricted to their assigned device.</p>
<form method="get" action="output.php" class="filters">
<div class="field"><label for="from_datetime">From Date and Time</label><input id="from_datetime" name="from_datetime" type="datetime-local" value="<?= h($fromDateTime) ?>" step="60" required></div>
<div class="field"><label for="to_datetime">To Date and Time</label><input id="to_datetime" name="to_datetime" type="datetime-local" value="<?= h($toDateTime) ?>" step="60" required></div>
<button type="submit">Search Report</button>
</form>
<div class="toplinks"><a class="button" href="index2.php">Back to Dashboard</a><?php if ($isAdmin): ?><a class="button" href="admin2.php">Manage Devices and View Logs</a><a class="button" href="users.php">Manage Consumers</a><?php endif; ?><a class="button gray" href="logout.php">Logout</a></div>
<?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
</section>
<?php if ($error === ''): ?>
<section class="panel"><h2>Report Summary</h2><div class="summary"><div>Readings returned<strong><?= count($rows) ?></strong></div><div>Value 1 total<strong><?= h(number_format($sum1, 2)) ?></strong></div><div>Value 2 total<strong><?= h(number_format($sum2, 2)) ?></strong></div><div>Value 3 total<strong><?= h(number_format($sum3, 2)) ?></strong></div></div>
<?php if (!$rows): ?><p>No readings found for this date range and account.</p><?php else: ?>
<p class="muted">Graphs use numeric values only. If a saved value is text rather than a number, that point is omitted from the corresponding numerical graph.</p>
<div class="chart-grid">
<div class="chart-box"><h3>Line Graph — Readings Over Time</h3><canvas id="lineChart"></canvas></div>
<div class="chart-box"><h3>Bar Graph — Total by Value</h3><canvas id="barChart"></canvas></div>
<div class="chart-box"><h3>Pie Chart — Share of Totals</h3><canvas id="pieChart"></canvas></div>
<div class="chart-box"><h3>Histogram — Value 1 Distribution</h3><canvas id="histChart"></canvas></div>
</div>
<p class="muted">Charts use Chart.js from a public CDN, so an internet connection is required to display them.</p>
<?php endif; ?></section>
<section class="panel"><h2>Readings in Selected Date/Time Range</h2><div class="table-wrap"><table><thead><tr><th>ID</th><th>Device ID</th><th>Date</th><th>Time</th><th>Value 1</th><th>Value 2</th><th>Value 3</th><th>Timezone</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8">No readings found.</td></tr><?php else: foreach ($rows as $row): ?><tr><td><?= h($row['id']) ?></td><td><?= h($row['device_id']) ?></td><td><?= h($row['Date']) ?></td><td><?= h($row['Time']) ?></td><td><?= h($row['value1']) ?></td><td><?= h($row['value2']) ?></td><td><?= h($row['value3']) ?></td><td><?= h($row['timezone']) ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div></section>
<?php endif; ?>
</main>
<?php if ($error === '' && count($rows) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const rows = <?= $chartJson ?>;
const labels = rows.map(r => r.label);
const v1 = rows.map(r => r.value1);
const v2 = rows.map(r => r.value2);
const v3 = rows.map(r => r.value3);
const sums = [
  rows.reduce((s,r) => s + (typeof r.value1 === 'number' ? r.value1 : 0), 0),
  rows.reduce((s,r) => s + (typeof r.value2 === 'number' ? r.value2 : 0), 0),
  rows.reduce((s,r) => s + (typeof r.value3 === 'number' ? r.value3 : 0), 0)
];
if (typeof Chart !== 'undefined') {
  new Chart(document.getElementById('lineChart'), {type:'line',data:{labels:labels,datasets:[{label:'Value 1',data:v1,spanGaps:false},{label:'Value 2',data:v2,spanGaps:false},{label:'Value 3',data:v3,spanGaps:false}]},options:{responsive:true,maintainAspectRatio:true,scales:{x:{ticks:{maxTicksLimit:8,maxRotation:45,minRotation:0}}}}});
  new Chart(document.getElementById('barChart'), {type:'bar',data:{labels:['Value 1','Value 2','Value 3'],datasets:[{label:'Total',data:sums}]},options:{responsive:true,plugins:{legend:{display:false}}}});
  new Chart(document.getElementById('pieChart'), {type:'pie',data:{labels:['Value 1 total','Value 2 total','Value 3 total'],datasets:[{data:sums}]},options:{responsive:true}});
  const nums = rows.map(r=>r.value1).filter(v=>typeof v==='number' && Number.isFinite(v));
  if (nums.length) {
    let min=Math.min(...nums), max=Math.max(...nums);
    if (min===max) { min-=0.5; max+=0.5; }
    const binCount=Math.min(10,Math.max(1,Math.ceil(Math.sqrt(nums.length))));
    const width=(max-min)/binCount;
    const counts=new Array(binCount).fill(0), binLabels=[];
    for(let i=0;i<binCount;i++) binLabels.push((min+i*width).toFixed(2)+' – '+(min+(i+1)*width).toFixed(2));
    nums.forEach(v=>{let i=Math.floor((v-min)/width);if(i<0)i=0;if(i>=binCount)i=binCount-1;counts[i]++;});
    new Chart(document.getElementById('histChart'),{type:'bar',data:{labels:binLabels,datasets:[{label:'Frequency of Value 1',data:counts}]},options:{responsive:true,plugins:{legend:{display:false}}}});
  } else {
    document.getElementById('histChart').insertAdjacentHTML('afterend','<p>No numeric Value 1 values available for histogram.</p>');
  }
} else {
  document.querySelector('.chart-grid').insertAdjacentHTML('beforebegin','<p class="error">Charts did not load. Please check your internet connection and refresh this page.</p>');
}
</script>
<?php endif; ?>
</body></html>
