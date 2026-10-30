#include <WiFi.h>
#include <HTTPClient.h>
#include <time.h>
#include <math.h>
#include <LittleFS.h>
#include <LittleFS.h>
#include <vector>

// =================================================
// 1. WI-FI SETTINGS
// =================================================

// Wi-Fi credentials are stored in LittleFS file /networks.txt.
// Each record contains three lines: SSID, password, last successful IP.
const char *NETWORK_FILE = "/networks.txt";
const char *LOCAL_CSV_FILE = "/sensor_data.csv";  // Stored in LittleFS
const char *SYNC_URL =
  "http://192.168.1.9/datalogger7_local_RSH/sync_sdcard.php";

// ESP32 LittleFS-only sensor storage; no LittleFS is used.
bool csvStorageReady = false;
const char *CSV_HEADER =
  "id,value1,value2,value3,Date,Time,TimeStamp,timezone,device_id,CH1,CH2,CH3,CH4,CH5,CH6,CH7,CH8,CH9,CH10,CH11,CH12,CH13,CH14,CH15,CH16";
unsigned long localCsvNextId = 1;
struct WifiRecord {
  String ssid;
  String password;
  String lastIP;
};
std::vector<WifiRecord> wifiRecords;
bool littleFSReady = false;
String serialCommandBuffer = "";
bool serialPromptActive = false;

// =================================================
// 2. FIXED DEVICE ID AND AUTHENTICATION TOKEN
// =================================================

const char *deviceID = "ESP0001";

const char *apiToken = "EspSwitch52026";

// =================================================
// 3. LOCAL XAMPP PHP URL
// =================================================

const char *serverURL =
  "http://192.168.1.9/datalogger7_local_RSH/save3_csv.php";

// =================================================
// 4. AUTOMATIC GEOLOCATION SERVICE
// =================================================

const char *geoURL =
  "http://ip-api.com/json/?fields=status,timezone";

String installationTimezone = "";

// =================================================
// 5. TIMING SETTINGS
// =================================================

const unsigned long sendInterval = 5000UL;

const unsigned long timezoneRefreshInterval =
  6UL * 60UL * 60UL * 1000UL;

const unsigned long timezoneRetryInterval =
  5UL * 60UL * 1000UL;

unsigned long previousSendTime = 0;
unsigned long previousTimezoneCheck = 0;

// =================================================
// 6. CD74HC4067 MULTIPLEXER SETTINGS
// =================================================

// CD74HC4067 select pins use ESP32 GPIO25/26/27/14; LittleFS uses flash memory.
const uint8_t MUX_S0 = 25;
const uint8_t MUX_S1 = 26;
const uint8_t MUX_S2 = 27;
const uint8_t MUX_S3 = 14;
const uint8_t MUX_SIGNAL_PIN = 34; // ESP32 ADC1 input; input-only GPIO
const float ADC_INPUT_FULL_SCALE_MV = 3300.0f; // Approximate; calibrate for your board.
const int MUX_CHANNEL_COUNT = 16;
const unsigned long MUX_SETTLE_DELAY_MS = 2;

int channelMv[MUX_CHANNEL_COUNT] = {0};
int adcvalue1 = 0; // CH1 uploaded as value1
int adcvalue2 = 0; // CH2 uploaded as value2
int adcvalue3 = 0; // CH3 uploaded as value3

// =================================================
// WI-FI CREDENTIAL FILE AND SERIAL COMMANDS
// =================================================

bool loadWifiRecords() {
  wifiRecords.clear();
  if (!littleFSReady || !LittleFS.exists(NETWORK_FILE)) return true;

  File file = LittleFS.open(NETWORK_FILE, "r");
  if (!file) return false;

  while (file.available()) {
    String networkName = file.readStringUntil('\n');
    networkName.trim();
    if (networkName.length() == 0) continue;

    String networkPassword = file.readStringUntil('\n');
    networkPassword.trim();
    if (networkPassword.length() == 0) continue;

    String savedIP = file.readStringUntil('\n');
    savedIP.trim();

    WifiRecord record;
    record.ssid = networkName;
    record.password = networkPassword;
    record.lastIP = savedIP;
    wifiRecords.push_back(record);
  }
  file.close();
  return true;
}

bool saveWifiRecords() {
  if (!littleFSReady) {
    Serial.println("ERROR: LittleFS is not available.");
    return false;
  }
  File file = LittleFS.open(NETWORK_FILE, "w");
  if (!file) {
    Serial.println("ERROR: Cannot write networks.txt.");
    return false;
  }
  for (size_t i = 0; i < wifiRecords.size(); i++) {
    file.println(wifiRecords[i].ssid);
    file.println(wifiRecords[i].password);
    file.println(wifiRecords[i].lastIP);
  }
  file.close();
  return true;
}

String urlEncode(const String &input);


String csvEscapeSimple(const String &value) {
  // Current fields are numeric/date/time/timezone/device ID and contain no commas.
  return value;
}

unsigned long findLastLocalCsvId() {
  if (!csvStorageReady || !LittleFS.exists(LOCAL_CSV_FILE)) return 0;
  File f = LittleFS.open(LOCAL_CSV_FILE, FILE_READ);
  if (!f) return 0;
  String line;
  unsigned long lastId = 0;
  // Skip the header and scan the records. CSV fields used here contain no commas.
  if (f.available()) line = f.readStringUntil('\n');
  while (f.available()) {
    line = f.readStringUntil('\n');
    line.trim();
    if (!line.length()) continue;
    int comma = line.indexOf(',');
    if (comma > 0) {
      unsigned long id = (unsigned long)line.substring(0, comma).toInt();
      if (id > lastId) lastId = id;
    }
  }
  f.close();
  return lastId;
}

// Copy an existing SD file to a backup, verify its size, then remove the original.
// This avoids LittleFS.rename(), which is unavailable in some ESP32 SD libraries.
bool backupAndRemoveFile(const char *sourcePath, const char *backupPath) {
  if (!LittleFS.exists(sourcePath) || LittleFS.exists(backupPath)) return false;

  File source = LittleFS.open(sourcePath, FILE_READ);
  if (!source) return false;
  const size_t originalSize = source.size();

  File backup = LittleFS.open(backupPath, FILE_WRITE);
  if (!backup) {
    source.close();
    return false;
  }

  uint8_t buffer[128];
  bool copyOK = true;
  while (source.available()) {
    int bytesRead = source.read(buffer, sizeof(buffer));
    if (bytesRead <= 0) break;
    size_t bytesWritten = backup.write(buffer, (size_t)bytesRead);
    if (bytesWritten != (size_t)bytesRead) {
      copyOK = false;
      break;
    }
  }

  backup.flush();
  backup.close();
  source.close();

  File verify = LittleFS.open(backupPath, FILE_READ);
  if (!verify) copyOK = false;
  else {
    if (verify.size() != originalSize) copyOK = false;
    verify.close();
  }

  if (!copyOK) {
    LittleFS.remove(backupPath);
    Serial.println("ERROR: Backup copy failed verification; original CSV was kept.");
    return false;
  }

  if (!LittleFS.remove(sourcePath)) {
    Serial.println("ERROR: Backup was created, but original CSV could not be removed.");
    Serial.println("Original data and backup should both remain on the LittleFS.");
    return false;
  }

  return true;
}

bool initializeLocalCsv() {
  if (!csvStorageReady) {
    Serial.println("ERROR: LittleFS is not available.");
    return false;
  }

  // If no CSV exists, create it with the required 25-column header.
  if (!LittleFS.exists(LOCAL_CSV_FILE)) {
    File f = LittleFS.open(LOCAL_CSV_FILE, FILE_WRITE);
    if (!f) {
      Serial.println("ERROR: Cannot create /sensor_data.csv on LittleFS.");
      return false;
    }
    f.println(CSV_HEADER);
    f.close();
    Serial.println("Created new LittleFS sensor_data.csv with the 25-column header.");
  } else {
    // Check the first line before appending any readings.
    File f = LittleFS.open(LOCAL_CSV_FILE, FILE_READ);
    if (!f) {
      Serial.println("ERROR: Cannot read sensor_data.csv on LittleFS.");
      return false;
    }

    String header = f.readStringUntil('\n');
    header.trim();
    f.close();

    if (header != CSV_HEADER) {
      Serial.println("WARNING: Existing LittleFS sensor_data.csv has an unexpected header.");
      Serial.println("The old file will be renamed to a backup; its contents will be preserved.");

      // Choose an unused backup name so an earlier backup is never overwritten.
      String backupPath = "/sensor_data_backup.csv";
      unsigned int backupNumber = 1;

      while (LittleFS.exists(backupPath.c_str()) && backupNumber < 1000) {
        backupPath = "/sensor_data_backup_" + String(backupNumber) + ".csv";
        backupNumber++;
      }

      if (LittleFS.exists(backupPath.c_str())) {
        Serial.println("ERROR: Could not find an unused backup filename.");
        Serial.println("No file was renamed or overwritten.");
        return false;
      }

      if (!backupAndRemoveFile(LOCAL_CSV_FILE, backupPath.c_str())) {
        Serial.println("ERROR: Could not safely back up the old sensor_data.csv.");
        Serial.println("No new sensor_data.csv was created; check LittleFS space and write access.");
        return false;
      }

      Serial.print("Old CSV copied to backup: ");
      Serial.println(backupPath);

      // Create a fresh CSV with the expected 25 columns.
      File newFile = LittleFS.open(LOCAL_CSV_FILE, FILE_WRITE);
      if (!newFile) {
        Serial.println("ERROR: Old file was backed up, but new sensor_data.csv could not be created.");
        Serial.println("The old data remains in the backup file.");
        return false;
      }

      size_t headerBytes = newFile.println(CSV_HEADER);
      newFile.flush();
      newFile.close();

      if (headerBytes == 0) {
        Serial.println("ERROR: Could not write the new CSV header.");
        return false;
      }

      Serial.println("Created a new sensor_data.csv with the correct 25-column header.");
    }
  }

  localCsvNextId = findLastLocalCsvId() + 1;
  Serial.print("LittleFS CSV ready. Next local ID: ");
  Serial.println(localCsvNextId);
  return true;
}

String posixTimezoneForIana(const String &iana) {
  // Common timezone mappings. Add mappings as required for deployment locations.
  if (iana == "Asia/Kolkata" || iana == "Asia/Calcutta") return "IST-5:30";
  if (iana == "Asia/Kathmandu") return "NPT-5:45";
  if (iana == "Asia/Dubai") return "GST-4";
  if (iana == "Asia/Singapore") return "SGT-8";
  if (iana == "Asia/Tokyo") return "JST-9";
  if (iana == "Asia/Shanghai" || iana == "Asia/Hong_Kong") return "CST-8";
  if (iana == "UTC" || iana == "Etc/UTC" || iana == "Etc/GMT") return "UTC0";
  if (iana == "Europe/London") return "GMT0BST,M3.5.0/1,M10.5.0";
  if (iana == "Europe/Paris" || iana == "Europe/Berlin" || iana == "Europe/Rome") return "CET-1CEST,M3.5.0,M10.5.0/3";
  if (iana == "America/New_York") return "EST5EDT,M3.2.0,M11.1.0";
  if (iana == "America/Chicago") return "CST6CDT,M3.2.0,M11.1.0";
  if (iana == "America/Denver") return "MST7MDT,M3.2.0,M11.1.0";
  if (iana == "America/Los_Angeles") return "PST8PDT,M3.2.0,M11.1.0";
  if (iana == "Australia/Sydney") return "AEST-10AEDT,M10.1.0,M4.1.0/3";
  return "";
}

bool appendLocalCsvRecord(const int values[16], time_t utcTimestamp,
                          const String &timezone, const String &devId) {
  if (!csvStorageReady || !initializeLocalCsv()) return false;

  time_t raw = utcTimestamp;
  struct tm *utcTm = gmtime(&raw);
  if (!utcTm) return false;

  char stamp[24];
  snprintf(stamp, sizeof(stamp), "%04d-%02d-%02d %02d:%02d:%02d",
           utcTm->tm_year + 1900, utcTm->tm_mon + 1, utcTm->tm_mday,
           utcTm->tm_hour, utcTm->tm_min, utcTm->tm_sec);

  String posixTZ = posixTimezoneForIana(timezone);
  if (posixTZ.length() == 0) {
    Serial.println("WARNING: Unknown timezone conversion rule; using UTC for local Date/Time.");
    posixTZ = "UTC0";
  }
  setenv("TZ", posixTZ.c_str(), 1);
  tzset();
  struct tm *localTm = localtime(&raw);
  if (!localTm) return false;

  char dateBuf[12], timeBuf[10];
  snprintf(dateBuf, sizeof(dateBuf), "%04d-%02d-%02d",
           localTm->tm_year + 1900, localTm->tm_mon + 1, localTm->tm_mday);
  snprintf(timeBuf, sizeof(timeBuf), "%02d:%02d:%02d",
           localTm->tm_hour, localTm->tm_min, localTm->tm_sec);

  File f = LittleFS.open(LOCAL_CSV_FILE, FILE_APPEND);
  if (!f) {
    Serial.println("ERROR: Cannot append to LittleFS sensor_data.csv.");
    return false;
  }

  // First 9 fields are compatible with sync_sdcard.php and espdemo2.logs.
  // value1-value3 duplicate CH1-CH3 so the existing database is unchanged.
  String row = String(localCsvNextId) + "," +
               String(values[0]) + "," + String(values[1]) + "," +
               String(values[2]) + "," + dateBuf + "," + timeBuf + "," +
               stamp + "," + timezone + "," + devId;
  for (int i = 0; i < 16; i++) {
    row += ",";
    row += String(values[i]);
  }

  size_t written = f.println(row);
  f.flush();
  f.close();
  if (written == 0) return false;

  Serial.print("Saved all 16 channels in LittleFS. Local CSV ID: ");
  Serial.println(localCsvNextId);
  localCsvNextId++;
  return true;
}

void showLocalCsvStatus() {
  if (!csvStorageReady || !LittleFS.exists(LOCAL_CSV_FILE)) {
    Serial.println("LittleFS sensor_data.csv is not available.");
    return;
  }
  File f = LittleFS.open(LOCAL_CSV_FILE, FILE_READ);
  if (!f) {
    Serial.println("Cannot open local CSV file.");
    return;
  }
  unsigned long lines = 0;
  while (f.available()) {
    String line = f.readStringUntil('\n');
    if (line.length()) lines++;
  }
  f.close();
  Serial.print("LittleFS CSV file: "); Serial.println(LOCAL_CSV_FILE);
  Serial.print("Header plus records: "); Serial.println(lines);
  Serial.print("Saved data records: "); Serial.println(lines > 0 ? lines - 1 : 0);
  Serial.print("Next local ID: "); Serial.println(localCsvNextId);
}

bool postLocalCsvRecord(const String &line) {
  // Local CSV has 25 columns: 9 compatible fields + CH1..CH16.
  // Only the first 9 are sent; the existing logs table stores CH1-CH3.
  String fields[25];
  int fieldCount = 0;
  int start = 0;
  for (int i = 0; i <= (int)line.length(); i++) {
    if (i == (int)line.length() || line[i] == ',') {
      if (fieldCount >= 25) return false;
      fields[fieldCount++] = line.substring(start, i);
      start = i + 1;
    }
  }
  if (fieldCount != 25) {
    Serial.print("Skipping malformed SD CSV row; columns found: ");
    Serial.println(fieldCount);
    return false;
  }

  String form = "api_token=" + urlEncode(String(apiToken)) +
      "&csv_id=" + urlEncode(fields[0]) +
      "&value1=" + urlEncode(fields[1]) +
      "&value2=" + urlEncode(fields[2]) +
      "&value3=" + urlEncode(fields[3]) +
      "&Date=" + urlEncode(fields[4]) +
      "&Time=" + urlEncode(fields[5]) +
      "&TimeStamp=" + urlEncode(fields[6]) +
      "&timezone=" + urlEncode(fields[7]) +
      "&device_id=" + urlEncode(fields[8]);

  WiFiClient client;
  HTTPClient http;
  if (!http.begin(client, SYNC_URL)) return false;
  http.setTimeout(15000);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  int code = http.POST(form);
  String response = http.getString();
  http.end();
  Serial.print("Sync HTTP code: "); Serial.println(code);
  Serial.println(response);
  return code == HTTP_CODE_OK;
}

void uploadLocalCsv() {
  if (!csvStorageReady || !LittleFS.exists(LOCAL_CSV_FILE)) {
    Serial.println("LittleFS CSV file is missing or LittleFS is not ready.");
    return;
  }
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi is not connected. Cannot transfer records now.");
    return;
  }
  File f = LittleFS.open(LOCAL_CSV_FILE, FILE_READ);
  if (!f) {
    Serial.println("Cannot open LittleFS CSV file.");
    return;
  }
  String header = f.readStringUntil('\n');
  header.trim();
  if (header != CSV_HEADER) {
    Serial.println("Unexpected LittleFS CSV header. Upload cancelled.");
    f.close();
    return;
  }
  unsigned long attempted = 0, succeeded = 0, failed = 0;
  Serial.println("Transferring LittleFS records to XAMPP; existing server records are deduplicated.");
  while (f.available()) {
    String line = f.readStringUntil('\n');
    line.trim();
    if (!line.length()) continue;
    attempted++;
    if (postLocalCsvRecord(line)) succeeded++;
    else failed++;
    delay(100);
    yield();
  }
  f.close();
  Serial.println("------ LITTLEFS TRANSFER SUMMARY ------");
  Serial.print("Records attempted: "); Serial.println(attempted);
  Serial.print("Requests successful: "); Serial.println(succeeded);
  Serial.print("Requests failed: "); Serial.println(failed);
  Serial.println("If any failed, correct the issue and run UPLOAD LITTLEFS again.");
  Serial.println("---------------------------------------");
}

void showHelp() {
  Serial.println();
  Serial.println("=========== COMMANDS ===========");
  Serial.println("HELP          - Show commands");
  Serial.println("SHOW NETWORKS - List SSIDs and saved IPs");
  Serial.println("READ FILE     - Read networks.txt (shows passwords)");
  Serial.println("SET WIFI      - Append a WiFi credential");
  Serial.println("EDIT WIFI     - Edit a record by number");
  Serial.println("DELETE WIFI   - Delete a record by number");
  Serial.println("CLEAR WIFI    - Delete all credentials");
  Serial.println("SHOW WIFI     - Show current WiFi connection");
  Serial.println("SHOW CSV      - Show local CSV record count");
  Serial.println("UPLOAD LITTLEFS - Transfer LittleFS CSV records to XAMPP");
  Serial.println("LittleFS stores all 16 channels; logs table receives CH1-CH3.");
  Serial.println("STATUS        - Show status");
  Serial.println("RESTART       - Restart ESP32");
  Serial.println("================================");
}

void showNetworks() {
  loadWifiRecords();
  Serial.println();
  Serial.println("------ SAVED WI-FI NETWORKS ------");
  if (wifiRecords.empty()) {
    Serial.println("No saved Wi-Fi credentials.");
  } else {
    for (size_t i = 0; i < wifiRecords.size(); i++) {
      Serial.print(i + 1);
      Serial.print(". SSID: ");
      Serial.print(wifiRecords[i].ssid);
      Serial.print(" | Saved IP: ");
      Serial.println(wifiRecords[i].lastIP.length() ? wifiRecords[i].lastIP : "(not connected yet)");
    }
  }
  Serial.println("----------------------------------");
}

void readNetworkFile() {
  loadWifiRecords();
  Serial.println();
  Serial.println("------ networks.txt (passwords visible) ------");
  if (wifiRecords.empty()) {
    Serial.println("File is empty or no valid records.");
  } else {
    for (size_t i = 0; i < wifiRecords.size(); i++) {
      Serial.print("Record "); Serial.println(i + 1);
      Serial.print("SSID: "); Serial.println(wifiRecords[i].ssid);
      Serial.print("Password: "); Serial.println(wifiRecords[i].password);
      Serial.print("Last successful IP: "); Serial.println(wifiRecords[i].lastIP);
      Serial.println("---");
    }
  }
  Serial.println("----------------------------------------------");
}

bool connectToSavedNetworks() {
  if (!loadWifiRecords()) {
    Serial.println("Could not read networks.txt.");
    return false;
  }
  if (wifiRecords.empty()) {
    Serial.println("No saved Wi-Fi credentials. Use SET WIFI.");
    return false;
  }

  WiFi.mode(WIFI_STA);
  for (size_t i = 0; i < wifiRecords.size(); i++) {
    Serial.println();
    Serial.print("Trying Wi-Fi record "); Serial.print(i + 1);
    Serial.print(": "); Serial.println(wifiRecords[i].ssid);
    WiFi.disconnect();
    delay(200);
    WiFi.begin(wifiRecords[i].ssid.c_str(), wifiRecords[i].password.c_str());

    unsigned long started = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - started < 12000UL) {
      delay(250);
      Serial.print(".");
      // Still allow the user to enter a command while waiting.
      if (Serial.available()) break;
    }
    Serial.println();

    if (WiFi.status() == WL_CONNECTED) {
      Serial.print("Connected to: "); Serial.println(WiFi.SSID());
      Serial.print("ESP32 IP: "); Serial.println(WiFi.localIP());
      wifiRecords[i].lastIP = WiFi.localIP().toString();
      saveWifiRecords();
      return true;
    }
    Serial.println("This network did not connect; trying the next saved network.");
  }
  Serial.println("Could not connect to any saved Wi-Fi network.");
  return false;
}

String readSerialLine(const char *promptText) {
  Serial.println(promptText);
  String result = "";
  unsigned long started = millis();
  while (millis() - started < 120000UL) {
    while (Serial.available()) {
      char c = (char)Serial.read();
      if (c == '\r') continue;
      if (c == '\n') {
        result.trim();
        return result;
      }
      if (result.length() < 120) result += c;
    }
    delay(10);
  }
  Serial.println("Input timed out.");
  return "";
}

void setWifiRecord() {
  String newSSID = readSerialLine("Enter Wi-Fi SSID:");
  if (!newSSID.length()) { Serial.println("SSID cannot be empty."); return; }
  String newPassword = readSerialLine("Enter Wi-Fi password:");
  if (!newPassword.length()) { Serial.println("Password cannot be empty."); return; }

  loadWifiRecords();
  for (size_t i = 0; i < wifiRecords.size(); i++) {
    if (wifiRecords[i].ssid == newSSID && wifiRecords[i].password == newPassword) {
      Serial.println("This SSID/password is already saved.");
      return;
    }
  }
  WifiRecord record;
  record.ssid = newSSID;
  record.password = newPassword;
  record.lastIP = "";
  wifiRecords.push_back(record);
  if (!saveWifiRecords()) return;
  Serial.println("Wi-Fi credential appended to networks.txt.");
  Serial.println("Trying the newly added network...");
  WiFi.mode(WIFI_STA);
  WiFi.disconnect();
  WiFi.begin(newSSID.c_str(), newPassword.c_str());
  unsigned long started = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - started < 15000UL) {
    delay(250); Serial.print(".");
  }
  Serial.println();
  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("Connected. IP: "); Serial.println(WiFi.localIP());
    for (size_t i = 0; i < wifiRecords.size(); i++) {
      if (wifiRecords[i].ssid == newSSID && wifiRecords[i].password == newPassword) {
        wifiRecords[i].lastIP = WiFi.localIP().toString();
        break;
      }
    }
    saveWifiRecords();
  } else {
    Serial.println("Connection failed. Credential retained for future retries.");
  }
}

int askRecordNumber(const char *promptText) {
  String answer = readSerialLine(promptText);
  int number = answer.toInt();
  if (number < 1 || number > (int)wifiRecords.size()) return -1;
  return number - 1;
}

void editWifiRecord() {
  loadWifiRecords();
  showNetworks();
  if (wifiRecords.empty()) return;
  int index = askRecordNumber("Enter record number to edit:");
  if (index < 0) { Serial.println("Invalid record number."); return; }
  String newSSID = readSerialLine("Enter new SSID:");
  if (!newSSID.length()) { Serial.println("SSID cannot be empty."); return; }
  String newPassword = readSerialLine("Enter new password:");
  if (!newPassword.length()) { Serial.println("Password cannot be empty."); return; }
  wifiRecords[index].ssid = newSSID;
  wifiRecords[index].password = newPassword;
  wifiRecords[index].lastIP = "";
  if (saveWifiRecords()) Serial.println("Record updated.");
}

void deleteWifiRecord() {
  loadWifiRecords();
  showNetworks();
  if (wifiRecords.empty()) return;
  int index = askRecordNumber("Enter record number to delete:");
  if (index < 0) { Serial.println("Invalid record number."); return; }
  wifiRecords.erase(wifiRecords.begin() + index);
  if (saveWifiRecords()) Serial.println("Record deleted.");
}

void clearWifiRecords() {
  if (!littleFSReady) { Serial.println("LittleFS is not available."); return; }
  WiFi.disconnect(true);
  WiFi.mode(WIFI_STA);
  wifiRecords.clear();
  if (LittleFS.exists(NETWORK_FILE)) LittleFS.remove(NETWORK_FILE);
  Serial.println("All saved Wi-Fi credentials deleted.");
}

void showWifi() {
  Serial.println();
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Wi-Fi status: CONNECTED");
    Serial.print("SSID: "); Serial.println(WiFi.SSID());
    Serial.print("IP address: "); Serial.println(WiFi.localIP());
    Serial.print("Signal strength (RSSI): "); Serial.print(WiFi.RSSI()); Serial.println(" dBm");
  } else {
    Serial.println("Wi-Fi status: DISCONNECTED");
  }
}

void showStatus() {
  Serial.println();
  Serial.println("========== DEVICE STATUS ==========");
  Serial.print("Device ID: "); Serial.println(deviceID);
  Serial.println("Analog input: CD74HC4067 16-channel multiplexer");
  Serial.print("LittleFS: "); Serial.println(littleFSReady ? "READY" : "NOT AVAILABLE");
  Serial.print("Saved Wi-Fi records: "); Serial.println(wifiRecords.size());
  Serial.println("Multiplexer: configured for 16 channels");
  showLocalCsvStatus();
  showWifi();
  Serial.print("Installation timezone: ");
  Serial.println(installationTimezone.length() ? installationTimezone : "(not detected)");
  time_t now = time(nullptr);
  Serial.print("UTC Unix timestamp: "); Serial.println((unsigned long)now);
  Serial.println("===================================");
}

void handleSerialCommands() {
  while (Serial.available()) {
    char c = (char)Serial.read();
    if (c == '\r') continue;
    if (c == '\n') {
      serialCommandBuffer.trim();
      String command = serialCommandBuffer;
      serialCommandBuffer = "";
      command.toUpperCase();
      if (!command.length()) continue;

      if (command == "HELP") showHelp();
      else if (command == "SHOW NETWORKS" || command == "SHOW NETWORK") showNetworks();
      else if (command == "READ FILE") readNetworkFile();
      else if (command == "SET WIFI") setWifiRecord();
      else if (command == "EDIT WIFI") editWifiRecord();
      else if (command == "DELETE WIFI") deleteWifiRecord();
      else if (command == "CLEAR WIFI") clearWifiRecords();
      else if (command == "SHOW WIFI") showWifi();
      else if (command == "SHOW CSV") showLocalCsvStatus();
      else if (command == "UPLOAD LITTLEFS" || command == "SYNC LITTLEFS") uploadLocalCsv();
      else if (command == "STATUS") showStatus();
      else if (command == "RESTART") {
        Serial.println("Restarting ESP32...");
        delay(500);
        ESP.restart();
      } else {
        Serial.println("Unknown command. Type HELP.");
      }
    } else if (serialCommandBuffer.length() < 100) {
      serialCommandBuffer += c;
    }
  }
}


// =================================================
// 7. URL ENCODING
// =================================================

String urlEncode(const String &input) {

  String output = "";
  const char hex[] = "0123456789ABCDEF";

  for (unsigned int i = 0; i < input.length(); i++) {

    unsigned char c = input[i];

    if ((c >= 'A' && c <= 'Z') ||
        (c >= 'a' && c <= 'z') ||
        (c >= '0' && c <= '9') ||
        c == '-' || c == '_' ||
        c == '.' || c == '~') {

      output += (char)c;

    } else {

      output += '%';
      output += hex[c >> 4];
      output += hex[c & 0x0F];
    }
  }

  return output;
}

// =================================================
// 8. DETECT INSTALLATION TIMEZONE
// =================================================

bool detectTimezone() {

  Serial.println();
  Serial.println("Checking installation timezone...");

  WiFiClient client;
  HTTPClient http;

  if (!http.begin(client, geoURL)) {

    Serial.println(
      "Could not start geolocation request."
    );

    return false;
  }

  http.setTimeout(10000);

  int httpCode = http.GET();

  if (httpCode != HTTP_CODE_OK) {

    Serial.print("Geolocation HTTP error: ");
    Serial.println(httpCode);

    http.end();
    return false;
  }

  String response = http.getString();
  http.end();

  Serial.println("Geolocation response:");
  Serial.println(response);

  if (response.indexOf("\"status\":\"success\"") < 0) {

    Serial.println(
      "Geolocation request was unsuccessful."
    );

    return false;
  }

  String key = "\"timezone\":\"";
  int start = response.indexOf(key);

  if (start < 0) {

    Serial.println("Timezone field not found.");
    return false;
  }

  start += key.length();

  int finish = response.indexOf("\"", start);

  if (finish < 0) {

    Serial.println("Invalid timezone response.");
    return false;
  }

  String detected = response.substring(start, finish);
  detected.trim();

  if (detected.length() == 0 ||
      detected.indexOf('/') < 0) {

    Serial.println("Invalid timezone returned.");
    return false;
  }

  installationTimezone = detected;

  Serial.print("Detected timezone: ");
  Serial.println(installationTimezone);

  return true;
}

// =================================================
// 9. SYNCHRONIZE UTC TIME USING NTP
// =================================================

bool synchronizeTime() {

  Serial.println();
  Serial.println("Synchronizing UTC time using NTP...");

  configTime(
    0,
    0,
    "pool.ntp.org",
    "time.nist.gov"
  );

  for (int i = 0; i < 30; i++) {

    time_t now = time(nullptr);

    if (now > 1700000000) {

      Serial.println(
        "NTP synchronization successful."
      );

      Serial.print("UTC Unix timestamp: ");
      Serial.println((unsigned long)now);

      return true;
    }

    Serial.print(".");
    delay(1000);
  }

  Serial.println();
  Serial.println("NTP synchronization failed.");

  return false;
}

// =================================================
// 10. READ ALL 16 MULTIPLEXER CHANNELS
// =================================================

void selectMuxChannel(uint8_t channel) {
  digitalWrite(MUX_S0, (channel >> 0) & 0x01);
  digitalWrite(MUX_S1, (channel >> 1) & 0x01);
  digitalWrite(MUX_S2, (channel >> 2) & 0x01);
  digitalWrite(MUX_S3, (channel >> 3) & 0x01);
}

bool readMultiplexer() {
  Serial.println();
  Serial.println("----- CD74HC4067 16-CHANNEL READINGS -----");

  for (uint8_t channel = 0; channel < MUX_CHANNEL_COUNT; channel++) {
    selectMuxChannel(channel);
    delay(MUX_SETTLE_DELAY_MS);
    (void)analogRead(MUX_SIGNAL_PIN); // discard first reading after switching
    delay(1);
    int raw = analogRead(MUX_SIGNAL_PIN);
    if (raw < 0) raw = 0;
    if (raw > 4095) raw = 4095;

    channelMv[channel] = (int)round((raw * ADC_INPUT_FULL_SCALE_MV) / 4095.0f);

    Serial.print("CH");
    Serial.print(channel + 1);
    Serial.print(" (C");
    Serial.print(channel);
    Serial.print("): raw=");
    Serial.print(raw);
    Serial.print(", voltage=");
    Serial.print(channelMv[channel] / 1000.0f, 3);
    Serial.print(" V, ");
    Serial.print(channelMv[channel]);
    Serial.println(" mV");
    yield();
  }

  // Option C: CH1-CH3 are the three selected values sent to existing logs.
  adcvalue1 = channelMv[0];
  adcvalue2 = channelMv[1];
  adcvalue3 = channelMv[2];

  Serial.println("----- SELECTED VALUES FOR EXISTING DATABASE -----");
  Serial.print("value1 = CH1 = "); Serial.print(adcvalue1); Serial.println(" mV");
  Serial.print("value2 = CH2 = "); Serial.print(adcvalue2); Serial.println(" mV");
  Serial.print("value3 = CH3 = "); Serial.print(adcvalue3); Serial.println(" mV");
  return true;
}

// =================================================
// 11. SETUP
// =================================================

void setup() {

  Serial.begin(115200);
  delay(1000);

  Serial.println();
  Serial.println("==================================");
  Serial.println("ESP32 16-CHANNEL MULTIPLEXER DATA LOGGER - LITTLEFS");
  Serial.println("Fixed Device ID: ESP0001");
  Serial.println("Project: datalogger7_local");
  Serial.println("==================================");

  // Configure CD74HC4067 select pins GPIO25/26/27/14.
  pinMode(MUX_S0, OUTPUT);
  pinMode(MUX_S1, OUTPUT);
  pinMode(MUX_S2, OUTPUT);
  pinMode(MUX_S3, OUTPUT);
  digitalWrite(MUX_S0, LOW);
  digitalWrite(MUX_S1, LOW);
  digitalWrite(MUX_S2, LOW);
  digitalWrite(MUX_S3, LOW);
  pinMode(MUX_SIGNAL_PIN, INPUT);
  Serial.println("CD74HC4067 multiplexer pins initialized.");
  Serial.println("Reading channels C0-C15 through ESP32 GPIO34.");

  // Mount LittleFS and load Wi-Fi credentials.
  littleFSReady = LittleFS.begin();
  if (!littleFSReady) {
    Serial.println("ERROR: LittleFS mount failed. Wi-Fi commands cannot save credentials.");
  } else {
    Serial.println("LittleFS mounted successfully.");
    if (!LittleFS.exists(NETWORK_FILE)) {
      File newFile = LittleFS.open(NETWORK_FILE, "w");
      if (newFile) newFile.close();
    }
    loadWifiRecords();
    connectToSavedNetworks();
  }

  Serial.println();
  Serial.println("Type HELP in Serial Monitor for available commands.");
  Serial.println("Serial Monitor line ending: Newline.");

  Serial.print("Fixed device ID: ");
  Serial.println(deviceID);

  Serial.print("CSV PHP endpoint: ");
  Serial.println(serverURL);

  // LittleFS is already mounted above for Wi-Fi credentials.
  // Use a separate file for sensor records and initialize its CSV header.
  csvStorageReady = littleFSReady;
  if (csvStorageReady) {
    Serial.println("LittleFS sensor CSV storage is ready.");
    initializeLocalCsv();
  } else {
    Serial.println("ERROR: LittleFS unavailable; local sensor records cannot be saved.");
  }

  // Detect installation timezone.

  bool timezoneOK = detectTimezone();

  previousTimezoneCheck = millis();

  if (!timezoneOK) {

    Serial.println(
      "Timezone detection will be retried."
    );
  }

  // Synchronize UTC clock.

  synchronizeTime();
}

// =================================================
// 12. MAIN LOOP
// =================================================

void loop() {

  handleSerialCommands();

  bool wifiConnected = (WiFi.status() == WL_CONNECTED);
  if (!wifiConnected) {
    static unsigned long lastReconnectAttempt = 0;
    if (millis() - lastReconnectAttempt >= 15000UL) {
      lastReconnectAttempt = millis();
      Serial.println("Wi-Fi disconnected. Trying saved credentials in order...");
      connectToSavedNetworks();
      wifiConnected = (WiFi.status() == WL_CONNECTED);
    }
  }

  unsigned long nowMillis = millis();

  // -----------------------------------------------
  // Refresh timezone automatically
  // -----------------------------------------------

  unsigned long refreshInterval =
    installationTimezone.length() == 0
      ? timezoneRetryInterval
      : timezoneRefreshInterval;

  if (wifiConnected && nowMillis - previousTimezoneCheck >=
      refreshInterval) {

    previousTimezoneCheck = nowMillis;

    if (!detectTimezone()) {

      Serial.println(
        "Timezone refresh failed."
      );
    }
  }

  // -----------------------------------------------
  // Send data every 5 seconds
  // -----------------------------------------------

  if (nowMillis - previousSendTime < sendInterval) {
    return;
  }

  previousSendTime = nowMillis;

  if (installationTimezone.length() == 0) {
    if (wifiConnected) {
      Serial.println("Timezone not detected yet; will retry.");
    } else {
      Serial.println("Local save skipped: timezone is not available yet.");
    }
    return;
  }

  // Verify UTC time. Once synchronized, the clock continues while Wi-Fi is temporarily down.
  time_t utcTimestamp = time(nullptr);
  if (utcTimestamp <= 1700000000) {
    Serial.println("UTC clock is invalid; NTP synchronization requires Wi-Fi.");
    if (!wifiConnected || !synchronizeTime()) {
      Serial.println("Reading skipped until valid time is available.");
      return;
    }
    utcTimestamp = time(nullptr);
  }

  readMultiplexer();

  Serial.println();
  Serial.println("----------------------------------");
  Serial.println("Saving 16-channel multiplexer scan to LittleFS and, if online, XAMPP");
  Serial.print("Device ID: "); Serial.println(deviceID);
  Serial.print("UTC timestamp: "); Serial.println((unsigned long)utcTimestamp);
  Serial.print("Timezone: "); Serial.println(installationTimezone);

  bool localSaved = appendLocalCsvRecord(
    channelMv, utcTimestamp, installationTimezone, String(deviceID));

  if (!localSaved) {
    Serial.println("ERROR: Reading was not saved to LittleFS.");
  }

  if (!wifiConnected) {
    Serial.println("Wi-Fi offline: reading retained on LittleFS; use UPLOAD LITTLEFS when connected.");
    return;
  }

  String postData =
    "device_id=" + urlEncode(String(deviceID)) +
    "&api_token=" + urlEncode(String(apiToken)) +
    "&value1=" + String(adcvalue1) +
    "&value2=" + String(adcvalue2) +
    "&value3=" + String(adcvalue3) +
    "&timestamp=" + String((unsigned long)utcTimestamp) +
    "&timezone=" + urlEncode(installationTimezone);

  WiFiClient client;
  HTTPClient http;
  if (http.begin(client, serverURL)) {
    http.setTimeout(10000);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    int httpCode = http.POST(postData);
    Serial.print("HTTP response code: "); Serial.println(httpCode);
    if (httpCode > 0) {
      Serial.println("CSV/database endpoint response:");
      Serial.println(http.getString());
    } else {
      Serial.print("HTTP error: ");
      Serial.println(http.errorToString(httpCode));
      Serial.println("LittleFS copy is retained for later transfer.");
    }
    http.end();
  } else {
    Serial.println("Could not initialize HTTP connection. LittleFS copy is retained.");
  }
}
