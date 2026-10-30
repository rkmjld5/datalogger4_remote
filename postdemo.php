<?php
/*
 * Data Logger ESP8266 API endpoint.
 * Expected POST fields: device_id, api_token, value1, value2, value3,
 * timestamp (UTC Unix seconds), timezone (IANA timezone name).
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/config.php';

function postDemoRespond(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        postDemoRespond(405, [
            'status' => 'error',
            'message' => 'POST request required.'
        ]);
    }

    $deviceID = trim((string)($_POST['device_id'] ?? ''));
    $apiToken = trim((string)($_POST['api_token'] ?? ''));

    if ($deviceID === '' || $apiToken === '') {
        postDemoRespond(401, [
            'status' => 'error',
            'message' => 'Device credentials missing.'
        ]);
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $deviceID)) {
        postDemoRespond(401, [
            'status' => 'error',
            'message' => 'Invalid device credentials.'
        ]);
    }

    // admin2.php stores only the SHA-256 hash of the device token.
    $stmt = $conn->prepare(
        'SELECT token_hash, enabled FROM esp_devices WHERE device_id = ? LIMIT 1'
    );
    $stmt->bind_param('s', $deviceID);
    $stmt->execute();
    $result = $stmt->get_result();
    $device = $result->fetch_assoc();
    $stmt->close();

    $savedHash = is_array($device) ? (string)($device['token_hash'] ?? '') : '';
    $submittedHash = hash('sha256', $apiToken);

    if (
        !$device ||
        (int)($device['enabled'] ?? 0) !== 1 ||
        $savedHash === '' ||
        !hash_equals($savedHash, $submittedHash)
    ) {
        postDemoRespond(403, [
            'status' => 'error',
            'message' => 'Device is not authorized.'
        ]);
    }

    $value1 = trim((string)($_POST['value1'] ?? ''));
    $value2 = trim((string)($_POST['value2'] ?? ''));
    $value3 = trim((string)($_POST['value3'] ?? ''));
    $timestamp = trim((string)($_POST['timestamp'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? ''));

    if ($value1 === '' || $value2 === '' || $value3 === '') {
        postDemoRespond(400, [
            'status' => 'error',
            'message' => 'One or more sensor values are missing.'
        ]);
    }

    $validTimezones = DateTimeZone::listIdentifiers();
    if (!in_array($timezone, $validTimezones, true)) {
        postDemoRespond(400, [
            'status' => 'error',
            'message' => 'Invalid timezone.'
        ]);
    }

    if (!ctype_digit($timestamp) || (float)$timestamp < 1700000000) {
        postDemoRespond(400, [
            'status' => 'error',
            'message' => 'Invalid UTC timestamp.'
        ]);
    }

    // Convert the supplied UTC timestamp to the installation's local wall-clock time.
    $utcDateTime = new DateTimeImmutable('@' . $timestamp);
    $localDateTime = $utcDateTime->setTimezone(new DateTimeZone($timezone));
    $date = $localDateTime->format('Y-m-d');
    $time = $localDateTime->format('H:i:s');
    $utcTimestamp = $utcDateTime
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    // Keep the same column names as the supplied working XAMPP project.
    $stmt = $conn->prepare(
        'INSERT INTO logs
            (device_id, value1, value2, value3, `Date`, `Time`, `TimeStamp`, timezone)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'ssssssss',
        $deviceID,
        $value1,
        $value2,
        $value3,
        $date,
        $time,
        $utcTimestamp,
        $timezone
    );
    $stmt->execute();
    $recordID = $conn->insert_id;
    $stmt->close();

    postDemoRespond(200, [
        'status' => 'success',
        'message' => 'Authorized data saved.',
        'id' => $recordID,
        'device_id' => $deviceID,
        'value1' => $value1,
        'value2' => $value2,
        'value3' => $value3,
        'Date' => $date,
        'Time' => $time,
        'TimeStamp_UTC' => $utcTimestamp,
        'timezone' => $timezone
    ]);
} catch (Throwable $e) {
    error_log('Data Logger postdemo.php error: ' . $e->getMessage());
    postDemoRespond(500, [
        'status' => 'error',
        'message' => 'Server/database error. Check the Render application logs.'
    ]);
}
