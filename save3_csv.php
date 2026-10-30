<?php
/*
 * save3_csv.php
 *
 * Project: datalogger3_remote
 * Hosting: Render
 * Database: TiDB Cloud
 * Database name: espdemo2
 *
 * Live ESP8266 endpoint:
 * https://datalogger3-remote.onrender.com/save3_csv.php
 *
 * POST fields:
 * device_id, api_token, value1, value2, value3, timestamp, timezone
 *
 * Required CSV header:
 * id,value1,value2,value3,Date,Time,TimeStamp,timezone,device_id
 *
 * IMPORTANT:
 * 1. config.php must establish the TiDB Cloud connection as $conn.
 * 2. Set ESP_API_TOKEN in the Render environment.
 * 3. The ESP8266 must send the same API token.
 */

header('Content-Type: application/json; charset=utf-8');

mysqli_report(MYSQLI_REPORT_OFF);

const CSV_HEADER = [
    'id',
    'value1',
    'value2',
    'value3',
    'Date',
    'Time',
    'TimeStamp',
    'timezone',
    'device_id'
];

/*
 * JSON response helper.
 */
function respond(int $code, array $body): void
{
    http_response_code($code);

    echo json_encode(
        $body,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
 * Write one CSV record.
 */
function writeCsvRow($fp, array $row): bool
{
    return fputcsv($fp, $row) !== false;
}

/*
 * Normalise a CSV header for comparison.
 */
function normalizedHeader(array $row): array
{
    return array_map(
        static function ($value) {
            return strtolower(trim((string)$value));
        },
        $row
    );
}

/*
 * Add a record to the CSV file.
 *
 * The CSV ID is the MySQL logs.id value, so a successful database
 * insert and its CSV record share the same identifier.
 *
 * Returns:
 *   ['ok' => true,  'message' => '...']
 *   ['ok' => false, 'message' => '...']
 */
function appendCsvRecord(
    string $csvFile,
    int $id,
    string $value1,
    string $value2,
    string $value3,
    string $date,
    string $time,
    string $timestampUtc,
    string $timezone,
    string $deviceId
): array {

    $fp = @fopen($csvFile, 'c+');

    if ($fp === false) {
        return [
            'ok' => false,
            'message' => 'Cannot open CSV file. Check the configured storage path and permissions.'
        ];
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return [
            'ok' => false,
            'message' => 'Could not lock CSV file.'
        ];
    }

    rewind($fp);

    $firstRow = fgetcsv($fp);

    $isEmpty = (
        $firstRow === false ||
        (
            count($firstRow) === 1 &&
            trim((string)$firstRow[0]) === ''
        )
    );

    if ($isEmpty) {

        /*
         * Create the canonical header in a new empty CSV file.
         */
        ftruncate($fp, 0);
        rewind($fp);

        if (!writeCsvRow($fp, CSV_HEADER)) {
            flock($fp, LOCK_UN);
            fclose($fp);

            return [
                'ok' => false,
                'message' => 'Could not write CSV header.'
            ];
        }

    } else {

        /*
         * Do not silently overwrite or destroy an existing file
         * whose header does not match the required format.
         */
        if (
            normalizedHeader($firstRow) !==
            normalizedHeader(CSV_HEADER)
        ) {
            flock($fp, LOCK_UN);
            fclose($fp);

            return [
                'ok' => false,
                'message' =>
                    'CSV header is not recognised. Back up the existing file before correcting it.'
            ];
        }

        /*
         * Avoid appending the same database ID twice if a previous
         * request wrote the CSV but its response was lost.
         */
        while (($row = fgetcsv($fp)) !== false) {

            if (
                isset($row[0]) &&
                ctype_digit(trim((string)$row[0])) &&
                (int)$row[0] === $id
            ) {
                flock($fp, LOCK_UN);
                fclose($fp);

                return [
                    'ok' => true,
                    'message' => 'CSV record already exists.',
                    'already_present' => true
                ];
            }
        }
    }

    /*
     * Append the record in the required column order.
     */
    fseek($fp, 0, SEEK_END);

    $written = writeCsvRow($fp, [
        $id,
        $value1,
        $value2,
        $value3,
        $date,
        $time,
        $timestampUtc,
        $timezone,
        $deviceId
    ]);

    if ($written) {
        $written = fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    if (!$written) {
        return [
            'ok' => false,
            'message' => 'Failed to write or flush the CSV record.'
        ];
    }

    return [
        'ok' => true,
        'message' => 'CSV record saved.',
        'already_present' => false
    ];
}


/* =========================================================
 * 1. Validate the HTTP request
 * =========================================================
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, [
        'status' => 'error',
        'message' => 'POST request required.'
    ]);
}


/* =========================================================
 * 2. Validate the API token
 * =========================================================
 */

$expectedToken = (string)getenv('ESP_API_TOKEN');

if ($expectedToken === '') {
    respond(500, [
        'status' => 'error',
        'message' => 'ESP_API_TOKEN is not configured on the server.'
    ]);
}

$providedToken = (string)($_POST['api_token'] ?? '');

if (!hash_equals($expectedToken, $providedToken)) {
    respond(403, [
        'status' => 'error',
        'message' => 'Invalid API token.'
    ]);
}


/* =========================================================
 * 3. Read and validate the sensor data
 * =========================================================
 */

$deviceId = trim((string)($_POST['device_id'] ?? ''));

$value1 = trim((string)($_POST['value1'] ?? ''));
$value2 = trim((string)($_POST['value2'] ?? ''));
$value3 = trim((string)($_POST['value3'] ?? ''));

$timestampRaw = trim((string)($_POST['timestamp'] ?? ''));
$timezone = trim((string)($_POST['timezone'] ?? ''));

if (
    $deviceId === '' ||
    $timestampRaw === '' ||
    $timezone === ''
) {
    respond(400, [
        'status' => 'error',
        'message' => 'device_id, timestamp and timezone are required.'
    ]);
}

if (
    strlen($deviceId) > 32 ||
    strlen($value1) > 30 ||
    strlen($value2) > 30 ||
    strlen($value3) > 50 ||
    strlen($timezone) > 64
) {
    respond(400, [
        'status' => 'error',
        'message' => 'One or more fields exceed the permitted length.'
    ]);
}

/*
 * Require a positive integer Unix timestamp.
 */
if (
    !preg_match('/^[0-9]{1,12}$/', $timestampRaw) ||
    (float)$timestampRaw <= 0
) {
    respond(400, [
        'status' => 'error',
        'message' => 'Invalid Unix timestamp.'
    ]);
}

$unixTimestamp = (int)$timestampRaw;

/*
 * Validate the installation timezone.
 * Example: Asia/Kolkata
 */
try {
    $tz = new DateTimeZone($timezone);
} catch (Throwable $e) {
    respond(400, [
        'status' => 'error',
        'message' => 'Invalid timezone.'
    ]);
}

/*
 * Convert UTC Unix time to the installation-local date/time.
 */
$localDateTime = (
    new DateTimeImmutable('@' . $unixTimestamp)
)->setTimezone($tz);

$date = $localDateTime->format('Y-m-d');
$time = $localDateTime->format('H:i:s');

/*
 * Store the database timestamp in UTC.
 */
$timestampUtc = gmdate(
    'Y-m-d H:i:s',
    $unixTimestamp
);


/* =========================================================
 * 4. Connect to TiDB Cloud using the existing config.php
 * =========================================================
 */

$configFile = __DIR__ . '/config.php';

if (!is_file($configFile)) {
    respond(500, [
        'status' => 'error',
        'message' => 'config.php was not found.'
    ]);
}

require_once $configFile;

/*
 * This endpoint expects config.php to provide a connected
 * MySQLi object named $conn.
 */
if (
    !isset($conn) ||
    !($conn instanceof mysqli) ||
    $conn->connect_errno
) {
    respond(500, [
        'status' => 'error',
        'message' => 'TiDB database connection is unavailable.'
    ]);
}

if (!$conn->set_charset('utf8mb4')) {
    respond(500, [
        'status' => 'error',
        'message' => 'Could not set the database character set.'
    ]);
}

/*
 * Ensure TIMESTAMP input and output use UTC for this connection.
 */
if (!$conn->query("SET time_zone = '+00:00'")) {
    respond(500, [
        'status' => 'error',
        'message' => 'Could not set the database session timezone to UTC.'
    ]);
}


/* =========================================================
 * 5. Check for an existing reading before inserting
 * =========================================================
 *
 * This helps avoid duplicates when the ESP8266 retries the
 * same reading with the same device ID and UTC timestamp.
 *
 * It assumes one reading per device per second. If your
 * hardware can legitimately send multiple readings for the
 * same device within one second, use a unique reading ID
 * generated by the firmware instead.
 */

$existingId = null;

$check = $conn->prepare(
    'SELECT id
       FROM logs
      WHERE device_id = ?
        AND TimeStamp = ?
      ORDER BY id ASC
      LIMIT 1'
);

if (!$check) {
    respond(500, [
        'status' => 'error',
        'message' => 'Could not prepare the duplicate-reading check.'
    ]);
}

$check->bind_param(
    'ss',
    $deviceId,
    $timestampUtc
);

if (!$check->execute()) {
    $check->close();

    respond(500, [
        'status' => 'error',
        'message' => 'Could not check for an existing reading.'
    ]);
}

$check->bind_result($foundId);

if ($check->fetch()) {
    $existingId = (int)$foundId;
}

$check->close();

$databaseId = null;
$databaseAlreadyHadRecord = false;


/* =========================================================
 * 6. Insert into espdemo2.logs if this reading is new
 * =========================================================
 */

if ($existingId !== null) {

    $databaseId = $existingId;
    $databaseAlreadyHadRecord = true;

} else {

    $stmt = $conn->prepare(
        'INSERT INTO logs
            (value1, value2, value3, Date, Time,
             TimeStamp, timezone, device_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        respond(500, [
            'status' => 'error',
            'message' => 'Could not prepare the logs insert.'
        ]);
    }

    $stmt->bind_param(
        'ssssssss',
        $value1,
        $value2,
        $value3,
        $date,
        $time,
        $timestampUtc,
        $timezone,
        $deviceId
    );

    if (!$stmt->execute()) {
        $stmt->close();

        respond(500, [
            'status' => 'error',
            'message' => 'Failed to insert the reading into espdemo2.logs.'
        ]);
    }

    $databaseId = (int)$stmt->insert_id;

    $stmt->close();
}


/* =========================================================
 * 7. Save the same reading to the CSV file
 * =========================================================
 *
 * By default, the file is stored beside this PHP file.
 *
 * To use a persistent Render disk, set CSV_FILE_PATH to a
 * file path on that disk in the Render environment.
 */

$configuredCsvPath = trim((string)getenv('CSV_FILE_PATH'));

if ($configuredCsvPath !== '') {
    $csvFile = $configuredCsvPath;
} else {
    $csvFile = __DIR__ . '/sensor_data.csv';
}

$csvDirectory = dirname($csvFile);

if (!is_dir($csvDirectory) || !is_writable($csvDirectory)) {

    respond(500, [
        'status' => 'partial_success',
        'message' => 'Database saved, but the CSV directory is unavailable.',
        'database_saved' => true,
        'csv_saved' => false,
        'mysql_id' => $databaseId,
        'device_id' => $deviceId
    ]);
}

$csvResult = appendCsvRecord(
    $csvFile,
    $databaseId,
    $value1,
    $value2,
    $value3,
    $date,
    $time,
    $timestampUtc,
    $timezone,
    $deviceId
);


/* =========================================================
 * 8. Return the result to the ESP8266
 * =========================================================
 */

if (!$csvResult['ok']) {

    respond(500, [
        'status' => 'partial_success',
        'message' => 'Database saved, but the CSV operation failed.',
        'database_saved' => true,
        'csv_saved' => false,
        'mysql_id' => $databaseId,
        'device_id' => $deviceId,
        'csv_error' => $csvResult['message'],
        'hint' => 'Check CSV_FILE_PATH, directory permissions and the existing CSV header.'
    ]);
}

respond(200, [
    'status' => 'success',
    'message' => $databaseAlreadyHadRecord
        ? 'Reading already existed in the database; CSV checked.'
        : 'Reading saved to the database and CSV.',
    'mysql_id' => $databaseId,
    'csv_id' => $databaseId,
    'database_saved' => true,
    'csv_saved' => true,
    'duplicate_database_record' => $databaseAlreadyHadRecord,
    'csv_record_already_present' => $csvResult['already_present'] ?? false,
    'device_id' => $deviceId,
    'value1' => $value1,
    'value2' => $value2,
    'value3' => $value3,
    'Date' => $date,
    'Time' => $time,
    'TimeStamp' => $timestampUtc,
    'timezone' => $timezone
]);
?>

