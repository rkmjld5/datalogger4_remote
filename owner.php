<?php
/*
 * ESP32 Data Logger 4 Remote
 *
 * Endpoint: /sync_littleFS.php
 *
 * Receives one 9-column record from ESP32 LittleFS sensor_data.csv
 * and inserts it into TiDB Cloud database espdemo2.logs.
 *
 * POST fields:
 * api_token, csv_id, value1, value2, value3,
 * Date, Time, TimeStamp, timezone, device_id
 *
 * Time convention:
 * Date and Time = installation-local time.
 * TimeStamp = UTC, in YYYY-MM-DD HH:MM:SS format.
 *
 * Duplicate protection:
 * device_id + TimeStamp.
 *
 * IMPORTANT:
 * Save this file as UTF-8 WITHOUT BOM.
 * The opening <?php must be the first bytes in the file.
 */

// Load the same Render/TiDB connection used by the working sync_sdcard.php.
// config.php must provide $conn and connect to espdemo2.
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function littleFsRespond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    exit;
}

try {

    // 1. POST only.

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');

        littleFsRespond(405, [
            'status' => 'error',
            'message' => 'POST request required.'
        ]);
    }

    // 2. Read credentials.

    $deviceID = trim((string)($_POST['device_id'] ?? ''));
    $apiToken = trim((string)($_POST['api_token'] ?? ''));

    if (
        $deviceID === '' ||
        $apiToken === '' ||
        !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $deviceID)
    ) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Missing or invalid device credentials.'
        ]);
    }

    // 3. Authenticate against esp_devices, exactly like sync_sdcard.php.

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
        littleFsRespond(403, [
            'status' => 'error',
            'message' => 'Device is not authorized.'
        ]);
    }

    // 4. Read the local LittleFS CSV row.

    $csvID = trim((string)($_POST['csv_id'] ?? ''));

    $value1 = trim((string)($_POST['value1'] ?? ''));
    $value2 = trim((string)($_POST['value2'] ?? ''));
    $value3 = trim((string)($_POST['value3'] ?? ''));

    $date = trim((string)($_POST['Date'] ?? ''));
    $time = trim((string)($_POST['Time'] ?? ''));
    $timestamp = trim((string)($_POST['TimeStamp'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? ''));

    // 5. Validate CSV ID.

    if ($csvID === '' || !ctype_digit($csvID)) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Invalid csv_id.'
        ]);
    }

    // 6. Validate values and database field lengths.

    if (
        $value1 === '' ||
        $value2 === '' ||
        $value3 === '' ||
        strlen($value1) > 30 ||
        strlen($value2) > 30 ||
        strlen($value3) > 50 ||
        strlen($deviceID) > 32
    ) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Missing sensor values or field length exceeded.'
        ]);
    }

    // 7. Validate local Date.

    $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    if (
        !$validDate ||
        $validDate->format('Y-m-d') !== $date
    ) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Invalid Date. Expected YYYY-MM-DD.'
        ]);
    }

    // 8. Validate local Time.

    if (
        !preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/',
            $time
        )
    ) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Invalid Time. Expected HH:MM:SS.'
        ]);
    }

    // 9. Validate UTC TimeStamp.

    $validTimestamp = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $timestamp,
        new DateTimeZone('UTC')
    );

    if (
        !$validTimestamp ||
        $validTimestamp->format('Y-m-d H:i:s') !== $timestamp
    ) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Invalid TimeStamp. Expected UTC YYYY-MM-DD HH:MM:SS.'
        ]);
    }

    // 10. Validate installation timezone.

    if (
        $timezone === '' ||
        strlen($timezone) > 64 ||
        !in_array(
            $timezone,
            DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
            true
        )
    ) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Invalid installation timezone.'
        ]);
    }

    // 11. Verify that Date and Time match the UTC timestamp converted
    //     into the installation timezone. Do not alter the supplied values.

    $installationTZ = new DateTimeZone($timezone);

    $expectedLocal = $validTimestamp->setTimezone($installationTZ);

    if (
        $expectedLocal->format('Y-m-d') !== $date ||
        $expectedLocal->format('H:i:s') !== $time
    ) {
        littleFsRespond(400, [
            'status' => 'error',
            'message' => 'Date/Time do not match the UTC TimeStamp and installation timezone.',
            'received_Date' => $date,
            'received_Time' => $time,
            'expected_Date' => $expectedLocal->format('Y-m-d'),
            'expected_Time' => $expectedLocal->format('H:i:s'),
            'timezone' => $timezone
        ]);
    }

    // 12. Use UTC for this database session.
    // This is important if logs.TimeStamp is a TIMESTAMP column.
    // If it is DATETIME, the supplied UTC text is stored unchanged.

    if (!$conn->query("SET time_zone = '+00:00'")) {
        throw new RuntimeException(
            'Could not set database session timezone to UTC.'
        );
    }

    // 13. Check for an existing record.
    // Local CSV IDs can restart or differ between devices, so use
    // device_id + UTC TimeStamp as the duplicate key.

    $check = $conn->prepare(
        'SELECT id
         FROM logs
         WHERE device_id = ?
           AND `TimeStamp` = ?
         LIMIT 1'
    );

    if (!$check) {
        throw new RuntimeException(
            'Could not prepare duplicate check.'
        );
    }

    $check->bind_param('ss', $deviceID, $timestamp);

    if (!$check->execute()) {
        $check->close();

        throw new RuntimeException(
            'Duplicate check failed.'
        );
    }

    $checkResult = $check->get_result();
    $existing = $checkResult->fetch_assoc();

    $check->close();

    if ($existing) {
        littleFsRespond(200, [
            'status' => 'success',
            'message' => 'Record already exists in espdemo2.logs; duplicate skipped.',
            'csv_id' => $csvID,
            'database_id' => (int)$existing['id'],
            'device_id' => $deviceID,
            'Date' => $date,
            'Time' => $time,
            'TimeStamp' => $timestamp,
            'timezone' => $timezone,
            'database_inserted' => false,
            'already_in_database' => true,
            'database_saved' => true
        ]);
    }

    // 14. Insert the new record.
    // The database generates its own id.
    // csv_id remains the local LittleFS CSV ID only.

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

        error_log('LittleFS TiDB insert failed: ' . $error);

        littleFsRespond(500, [
            'status' => 'error',
            'message' => 'Database insertion failed.',
            'csv_id' => $csvID,
            'device_id' => $deviceID
        ]);
    }

    $databaseID = $stmt->insert_id;
    $stmt->close();

    // 15. Success.

    littleFsRespond(200, [
        'status' => 'success',
        'message' => 'LittleFS record inserted into espdemo2.logs.',
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
        'database_inserted' => true,
        'already_in_database' => false,
        'database_saved' => true
    ]);

} catch (Throwable $e) {

    error_log(
        'datalogger4_remote sync_littleFS.php: ' .
        $e->getMessage()
    );

    littleFsRespond(500, [
        'status' => 'error',
        'message' => 'Server or database error. Check Render logs.'
    ]);
}
