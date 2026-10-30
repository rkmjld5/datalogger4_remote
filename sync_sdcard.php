<?php
/*
 * ESP32 Data Logger 4 Remote
 *
 * Endpoint: /sync_sdcard.php
 *
 * Receives records from the ESP32 SD CSV file.
 *
 * POST fields:
 * api_token, csv_id, value1, value2, value3,
 * Date, Time, TimeStamp, timezone, device_id
 *
 * Database: espdemo2
 * Table: logs
 *
 * Database columns:
 * id, value1, value2, value3, Date, Time,
 * TimeStamp, timezone, device_id
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/config.php';

function syncRespond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

try {

    // 1. Accept POST requests only.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');

        syncRespond(405, [
            'status' => 'error',
            'message' => 'POST request required.'
        ]);
    }

    // 2. Read and validate device credentials.
    $deviceID = trim((string)($_POST['device_id'] ?? ''));
    $apiToken = trim((string)($_POST['api_token'] ?? ''));

    if (
        $deviceID === '' ||
        $apiToken === '' ||
        !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $deviceID)
    ) {
        syncRespond(400, [
            'status' => 'error',
            'message' => 'Missing or invalid device credentials.'
        ]);
    }

    // 3. Verify the registered ESP device.
    $stmt = $conn->prepare(
        'SELECT token_hash, enabled
         FROM esp_devices
         WHERE device_id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Could not prepare device authentication query.'
        );
    }

    $stmt->bind_param('s', $deviceID);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Device authentication query failed.'
        );
    }

    $result = $stmt->get_result();
    $device = $result->fetch_assoc();
    $stmt->close();

    $storedHash = is_array($device)
        ? (string)($device['token_hash'] ?? '')
        : '';

    $submittedHash = hash('sha256', $apiToken);

    if (
        !$device ||
        (int)($device['enabled'] ?? 0) !== 1 ||
        $storedHash === '' ||
        !hash_equals($storedHash, $submittedHash)
    ) {
        syncRespond(403, [
            'status' => 'error',
            'message' => 'Device is not authorized.'
        ]);
    }

    // 4. Read the original SD CSV record.
    $csvID = trim((string)($_POST['csv_id'] ?? ''));

    $value1 = trim((string)($_POST['value1'] ?? ''));
    $value2 = trim((string)($_POST['value2'] ?? ''));
    $value3 = trim((string)($_POST['value3'] ?? ''));

    $date = trim((string)($_POST['Date'] ?? ''));
    $time = trim((string)($_POST['Time'] ?? ''));
    $timestamp = trim((string)($_POST['TimeStamp'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? ''));

    // 5. Validate the CSV ID.
    if ($csvID === '' || !ctype_digit($csvID)) {
        syncRespond(400, [
            'status' => 'error',
            'message' => 'Invalid csv_id.'
        ]);
    }

    // 6. Validate sensor values and field lengths.
    if (
        $value1 === '' ||
        $value2 === '' ||
        $value3 === '' ||
        strlen($value1) > 30 ||
        strlen($value2) > 30 ||
        strlen($value3) > 50
    ) {
        syncRespond(400, [
            'status' => 'error',
            'message' => 'Missing sensor values or field length exceeded.'
        ]);
    }

    // 7. Validate Date, Time and TimeStamp formats.
    $validDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date
    );

    if (
        !$validDate ||
        $validDate->format('Y-m-d') !== $date
    ) {
        syncRespond(400, [
            'status' => 'error',
            'message' => 'Invalid Date. Expected YYYY-MM-DD.'
        ]);
    }

    if (
        !preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/',
            $time
        )
    ) {
        syncRespond(400, [
            'status' => 'error',
            'message' => 'Invalid Time. Expected HH:MM:SS.'
        ]);
    }

    $validTimestamp = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $timestamp
    );

    if (
        !$validTimestamp ||
        $validTimestamp->format('Y-m-d H:i:s') !== $timestamp
    ) {
        syncRespond(400, [
            'status' => 'error',
            'message' => 'Invalid TimeStamp. Expected YYYY-MM-DD HH:MM:SS.'
        ]);
    }

    // 8. Validate the installation timezone.
    if (
        $timezone === '' ||
        strlen($timezone) > 64 ||
        !in_array(
            $timezone,
            DateTimeZone::listIdentifiers(),
            true
        )
    ) {
        syncRespond(400, [
            'status' => 'error',
            'message' => 'Invalid installation timezone.'
        ]);
    }

    // 9. Insert the record into the remote TiDB Cloud database.
    //
    // The database generates its own id.
    // csv_id is the local SD CSV ID, not the database primary key.
    //
    // This assumes config.php connects to the correct TiDB Cloud
    // database and the logs.id column is AUTO_INCREMENT.

    $stmt = $conn->prepare(
        'INSERT INTO logs
            (value1, value2, value3, `Date`, `Time`,
             `TimeStamp`, timezone, device_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Could not prepare logs insertion.'
        );
    }

    $stmt->bind_param(
        'ssssssss',
        $value1,
        $value2,
        $value3,
        $date,
        $time,
        $timestamp,
        $timezone,
        $deviceID
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        error_log(
            'SD sync database insert failed: ' . $error
        );

        syncRespond(500, [
            'status' => 'error',
            'message' => 'Database insertion failed.',
            'csv_id' => $csvID,
            'device_id' => $deviceID
        ]);
    }

    $databaseID = $stmt->insert_id;
    $stmt->close();

    // 10. Return success only after a successful insertion.
    syncRespond(200, [
        'status' => 'success',
        'message' => 'SD record inserted into espdemo2.logs.',
        'csv_id' => $csvID,
        'database_id' => $databaseID,
        'device_id' => $deviceID,
        'value1' => $value1,
        'value2' => $value2,
        'value3' => $value3,
        'Date' => $date,
        'Time' => $time,
        'TimeStamp' => $timestamp,
        'timezone' => $timezone,
        'database_saved' => true
    ]);

} catch (Throwable $e) {

    error_log(
        'datalogger4_remote sync_sdcard.php: ' .
        $e->getMessage()
    );

    syncRespond(500, [
        'status' => 'error',
        'message' => 'Server or database error. Check Render logs.'
    ]);
}
