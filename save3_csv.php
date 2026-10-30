<?php
// ESP8266 ADS1115 CSV endpoint.
// Install as C:\\xampp\\htdocs\\datalogger7_local\\save_csv.php
// Canonical CSV order exactly follows the MySQL logs table:
// id,value1,value2,value3,Date,Time,TimeStamp,timezone,device_id

header('Content-Type: application/json; charset=utf-8');
const API_TOKEN = 'EspSwitch52026';
$csvFile = __DIR__ . DIRECTORY_SEPARATOR . 'sensor_data.csv';
$header = ['id', 'value1', 'value2', 'value3', 'Date', 'Time', 'TimeStamp', 'timezone', 'device_id'];

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizedHeader(array $row): array {
    return array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $row);
}

function writeCsvRow($fp, array $row): bool {
    return fputcsv($fp, $row) !== false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['status' => 'error', 'message' => 'POST request required.']);
}
$token = (string)($_POST['api_token'] ?? '');
if (!hash_equals(API_TOKEN, $token)) {
    respond(403, ['status' => 'error', 'message' => 'Invalid API token.']);
}

$deviceId = trim((string)($_POST['device_id'] ?? ''));
$value1 = trim((string)($_POST['value1'] ?? ''));
$value2 = trim((string)($_POST['value2'] ?? ''));
$value3 = trim((string)($_POST['value3'] ?? ''));
$timestampRaw = trim((string)($_POST['timestamp'] ?? ''));
$timezone = trim((string)($_POST['timezone'] ?? ''));
if ($deviceId === '' || $timestampRaw === '' || !ctype_digit($timestampRaw) || $timezone === '') {
    respond(400, ['status' => 'error', 'message' => 'Missing or invalid device_id, timestamp, or timezone.']);
}
if (strlen($deviceId) > 32 || strlen($value1) > 30 || strlen($value2) > 30 || strlen($value3) > 50 || strlen($timezone) > 64) {
    respond(400, ['status' => 'error', 'message' => 'One or more fields exceed the logs-table field length.']);
}
try {
    $tz = new DateTimeZone($timezone);
} catch (Throwable $e) {
    respond(400, ['status' => 'error', 'message' => 'Invalid timezone.']);
}
$unix = (int)$timestampRaw;
if ($unix <= 0) respond(400, ['status' => 'error', 'message' => 'Timestamp must be positive.']);
$local = (new DateTimeImmutable('@' . $unix))->setTimezone($tz);
$date = $local->format('Y-m-d');
$time = $local->format('H:i:s');
$timestampUtc = gmdate('Y-m-d H:i:s', $unix);

// Open and lock the CSV so simultaneous requests cannot allocate the same ID.
$fp = @fopen($csvFile, 'c+');
if ($fp === false) respond(500, ['status' => 'error', 'message' => 'Cannot create/open sensor_data.csv. Check folder permissions.']);
if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    respond(500, ['status' => 'error', 'message' => 'Could not lock CSV file.']);
}

rewind($fp);
$first = fgetcsv($fp);
$isEmpty = ($first === false || (count($first) === 1 && trim((string)$first[0]) === ''));
$lastId = 0;
$migrated = false;

if ($isEmpty) {
    // New file: write the exact required header.
    ftruncate($fp, 0);
    rewind($fp);
    writeCsvRow($fp, $header);
} else {
    $actual = normalizedHeader($first);
    $expected = normalizedHeader($header);
    $oldHeader = ['deviceid', 'date', 'time', 'timestamputc', 'timezone', 'value1', 'value2', 'value3'];

    if ($actual === $expected) {
        // Already in correct format; find the highest existing ID.
        while (($row = fgetcsv($fp)) !== false) {
            if (isset($row[0]) && is_numeric($row[0])) $lastId = max($lastId, (int)$row[0]);
        }
    } elseif ($actual === $oldHeader) {
        // Migrate the previously used format into the requested logs-table order.
        $oldRows = [];
        while (($row = fgetcsv($fp)) !== false) {
            if (count($row) < 8) continue;
            $oldRows[] = $row;
        }
        // Rewrite the same file with the canonical header and sequential IDs.
        ftruncate($fp, 0);
        rewind($fp);
        writeCsvRow($fp, $header);
        $newId = 0;
        foreach ($oldRows as $row) {
            $newId++;
            // Old order: DeviceID, Date, Time, TimestampUTC, Timezone, Value1, Value2, Value3
            $newRow = [$newId, $row[5], $row[6], $row[7], $row[1], $row[2], $row[3], $row[4], $row[0]];
            if (!writeCsvRow($fp, $newRow)) {
                flock($fp, LOCK_UN); fclose($fp);
                respond(500, ['status' => 'error', 'message' => 'Failed while converting existing CSV records.']);
            }
        }
        $lastId = $newId;
        $migrated = true;
    } else {
        flock($fp, LOCK_UN);
        fclose($fp);
        respond(409, [
            'status' => 'error',
            'message' => 'CSV header is not recognised. Back up sensor_data.csv before changing it.',
            'found_header' => $first,
            'required_header' => $header
        ]);
    }
}

$newId = $lastId + 1;
fseek($fp, 0, SEEK_END);
$written = writeCsvRow($fp, [$newId, $value1, $value2, $value3, $date, $time, $timestampUtc, $timezone, $deviceId]);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
if (!$written) {
    respond(500, ['status' => 'error', 'message' => 'Could not write the CSV record.']);
}

// Also insert the same reading into the local MySQL database espdemo2.logs.
// Standard XAMPP defaults are username=root and an empty password.
// If your MySQL root account has a password, update MYSQL_PASSWORD below.
const MYSQL_HOST = 'localhost';
const MYSQL_USER = 'root';
const MYSQL_PASSWORD = '';
const MYSQL_DATABASE = 'espdemo2';

$db = @new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE);
$dbOk = false;
$dbId = null;
$dbError = '';

if ($db->connect_errno) {
    $dbError = 'MySQL connection failed: ' . $db->connect_error;
} else {
    $db->set_charset('utf8mb4');
    // Ensure the TIMESTAMP value supplied below is interpreted as UTC.
    if (!$db->query("SET time_zone = '+00:00'")) {
        $dbError = 'Could not set MySQL session timezone to UTC: ' . $db->error;
    } else {
        $stmt = $db->prepare(
            'INSERT INTO `logs` (`value1`, `value2`, `value3`, `Date`, `Time`, `TimeStamp`, `timezone`, `device_id`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            $dbError = 'Could not prepare logs insert: ' . $db->error;
        } else {
            $stmt->bind_param('ssssssss', $value1, $value2, $value3, $date, $time, $timestampUtc, $timezone, $deviceId);
            if ($stmt->execute()) {
                $dbOk = true;
                $dbId = $stmt->insert_id;
            } else {
                $dbError = 'Could not insert into espdemo2.logs: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
    $db->close();
}

if ($dbOk) {
    respond(200, [
        'status' => 'success',
        'message' => 'Record saved to sensor_data.csv and espdemo2.logs.',
        'csv_id' => $newId,
        'mysql_id' => $dbId,
        'csv_saved' => true,
        'database_saved' => true,
        'device_id' => $deviceId,
        'Date' => $date,
        'Time' => $time,
        'TimeStamp' => $timestampUtc,
        'timezone' => $timezone,
        'existing_file_migrated' => $migrated
    ]);
}

// CSV has already been saved, but MySQL failed. Make this explicit in the response.
respond(500, [
    'status' => 'partial_success',
    'message' => 'CSV record was saved, but insertion into espdemo2.logs failed.',
    'csv_saved' => true,
    'database_saved' => false,
    'csv_id' => $newId,
    'device_id' => $deviceId,
    'database_error' => $dbError,
    'hint' => 'Check that MySQL is running, database espdemo2 and table logs exist, and MYSQL_PASSWORD matches your XAMPP MySQL account.'
]);
?>
