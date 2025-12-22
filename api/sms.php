<?php
/**
 * This script sends SMS reminders for upcoming meetings.
 * License: GNU/GPLv3+
 * Author: BeezNest Belgium SRL, 2025
 */
// Prevent execution if not run through PHP CLI
if (php_sapi_name() !== 'cli') {
    exit("This script can only be run from the command line.\n");
}

// Define paths (script is in the api/ folder)
define('ROOT_DIR', dirname(__FILE__) . '/../');
define('INCLUDE_DIR', ROOT_DIR . 'include/');
define('LOG_FILE', __DIR__ . '/sms_cron.log'); // Log file for debugging

$logEvents = false;

$templateMessageFr = "Rappel Rendez-vous %s le %s à %s. Pour annuler %s";
$templateMessageNl = "Herinnering: Afspraak bij %s het %s tot %s. Annuleren: %s";

// Function to log messages to file (no stdout)
function log_message($msg, $logEvents = false) {
    if (!$logEvents) return;
    file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . ' - ' . $msg . PHP_EOL, FILE_APPEND);
}

// Define empty settings redefined in ost-config.php
$companyName = '';
$companyPhone = '';
$smsUser = '';
$smsPassword = '';

// Include necessary osTicket files for bootstrapping
require_once INCLUDE_DIR . 'ost-config.php'; // Config file (provides DB constants and TABLE_PREFIX)

// Set default timezone to handle CEST/CET with DST
date_default_timezone_set('Europe/Brussels');

// Log key config values for debugging
log_message("DBNAME: " . DBNAME, $logEvents);
log_message("TABLE_PREFIX: " . TABLE_PREFIX, $logEvents);
$full_table_name = TABLE_PREFIX . "sms_log";
log_message("Full table name: " . $full_table_name, $logEvents);

// Get the PDO database connection manually
$dsn = 'mysql:host=' . DBHOST . ';dbname=' . DBNAME . ';charset=utf8';
try {
    $db = new PDO($dsn, DBUSER, DBPASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    log_message("Database connection successful.", $logEvents);
} catch (PDOException $e) {
    log_message("Database connection failed: " . $e->getMessage(), $logEvents);
    exit(1); // Exit on connection failure
}

// Create the SMS log table if it doesn't exist, with checks
$create_sql = "
    CREATE TABLE IF NOT EXISTS `" . $full_table_name . "` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ticket_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `phone` VARCHAR(64) NOT NULL,
        `sent_at` DATETIME NOT NULL,
        `status` VARCHAR(32) NOT NULL,
        `message` TEXT,
        `error` TEXT,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
";
try {
    $db->exec($create_sql);
    log_message("Table creation query executed successfully.", $logEvents);
} catch (PDOException $e) {
    log_message("Table creation failed: " . $e->getMessage() . " (Error Code: " . $e->getCode() . ")", $logEvents);
}

// Check if the table now exists using information_schema
$check_sql = "
    SELECT COUNT(*) AS table_exists
    FROM information_schema.tables
    WHERE table_schema = :dbname
    AND table_name = :tablename
";
try {
    $check_stmt = $db->prepare($check_sql);
    $check_stmt->execute([
        ':dbname' => DBNAME,
        ':tablename' => $full_table_name
    ]);
    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    $exists_via_schema = $result['table_exists'] > 0;
    //log_message("Table exists via information_schema: " . ($exists_via_schema ? 'Yes' : 'No'), $logEvents);
} catch (PDOException $e) {
    log_message("information_schema check failed: " . $e->getMessage(), $logEvents);
    $exists_via_schema = false;
}

// Calculate the time range for meetings: next day's current hour (00 to 59:59)
date_default_timezone_set('UTC');
$now = time();
$now_time = date('Y-m-d H:i:s', $now);
$tomorrow_hour_start_str = date('Y-m-d H:00:00', $now + 86400);
$tomorrow_hour_end_str = date('Y-m-d H:59:59', $now + 86400);

// Query for tickets with meetings in the specified range
// Assuming 'meetdate' is a DATETIME column in ost_ticket__cdata
// Assuming user phone is stored in a custom field 'mobilephone' in ost_user__cdata
$sql = "
    SELECT t.ticket_id, t.user_id, c.meetdate, u.mobilephone, u.lang
    FROM " . TABLE_PREFIX . "ticket t
    INNER JOIN " . TABLE_PREFIX . "ticket__cdata c ON t.ticket_id = c.ticket_id
    INNER JOIN " . TABLE_PREFIX . "user__cdata u ON t.user_id = u.user_id
    WHERE STR_TO_DATE(LEFT(c.meetdate, 19), '%Y-%m-%d %H:%i:%s') BETWEEN :start AND :end
    AND u.mobilephone IS NOT NULL AND u.mobilephone != ''
";

try {
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':start', $tomorrow_hour_start_str);
    $stmt->bindValue(':end', $tomorrow_hour_end_str);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($results) > 0) {
        log_message("Query executed successfully. Found " . count($results) . " results.", $logEvents);
    } else {
        // do nothing
    }
} catch (PDOException $e) {
    log_message("Full query: " . $sql . " with param :start=" . $tomorrow_hour_start_str.", :end=" . $tomorrow_hour_end_str, $logEvents);
    log_message("Query failed: " . $e->getMessage(), $logEvents);
    exit(1);
}

foreach ($results as $row) {
    log_message("Sending reminder for ticket: " . $row['ticket_id'] . " with a meeting date of " . $row['meetdate'], $logEvents);
    $ticket_id = $row['ticket_id'];
    $user_id = $row['user_id'];
    $raw_phone = trim($row['mobilephone']);
    $meetdate = $row['meetdate'];
    $meetTime = date('H:i', strtotime($meetdate));
    $meetDate = date('d/m/Y', strtotime($meetdate));

    // Process phone number
    if (empty($raw_phone)) {
        $status = 'failed';
        $error = 'Empty phone number';
        $phone = '';
        $message = ''; // No message since not sending
    } else {
        // Take only the first phone number if multiple
        $pos = false;
        $pos_paren = strpos($raw_phone, '(');
        $pos_slash = strpos($raw_phone, '/');
        if ($pos_paren !== false) $pos = $pos_paren;
        if ($pos_slash !== false && ($pos === false || $pos_slash < $pos)) $pos = $pos_slash;
        if ($pos !== false) {
            $raw_phone = trim(substr($raw_phone, 0, $pos));
        }

        // Clean non-digits except leading +
        $cleaned = preg_replace('/[^0-9+]/', '', $raw_phone);

        if (strlen($cleaned) < 9 || !preg_match('/^(\+?\d+|\d+)$/', $cleaned)) {
            $status = 'failed';
            $error = 'Invalid phone number format: ' . $raw_phone;
            $phone = '';
            $message = '';
        } else {
            /**Phone number types:
             * +32487123456 -> remove +
             * 0032487123456 -> remove leading 00
             * 0487123456 -> remove leading 0 and add 32
             * 32487123456 -> perfect, do nothing
             * other cases
             */
            if (substr($cleaned, 0, 1) === '+') {
                $international = substr($cleaned, 1);
            } elseif (substr($cleaned, 0, 2) === '00') {
                $international = substr($cleaned, 2);
            } elseif (substr($cleaned, 0, 1) === '0') {
                $international = '32' . substr($cleaned, 1);
            } elseif (substr($cleaned, 0, 2) === '32') {
                $international = $cleaned;
            } else {
                if (ctype_digit($cleaned)) {
                    $international = '32' . $cleaned;
                } else {
                    $international = '';
                }
            }

            if (!ctype_digit($international) || strlen($international) < 10 || strlen($international) > 15) {
                $status = 'failed';
                $error = 'Invalid phone number format: ' . $raw_phone;
                $phone = '';
                $message = '';
            } else {
                $phone = $international;

                // Construct the message with placeholders (to be replaced in further iterations)
                $messageFr = sprintf($templateMessageFr, $companyName, $meetDate, $meetTime, $companyPhone);
                $messageNl = sprintf($templateMessageNl, $companyName, $meetDate, $meetTime, $companyPhone);

                if (substr($row['lang'], 0 ,2) != '25') {
                    $message = $messageFr;
                } else {
                    $message = $messageNl;
                }

                // SMS API configuration (smsgatewayapi.com)
                $url = "https://api.smsgatewayapi.com/v1/message/send";
                $client_id = $smsUser; // Your API client ID (required)
                $client_secret = $smsPassword; // Your API client secret (required)
                $data = [
                    'message' => $message, //Message (required)
                    'to' => $phone, //Receiver (required)
                    'sender' => substr($companyPhone, 1) //Sender (required)
                ];

                // Prepare curl request
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_VERBOSE, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "X-Client-Id: $client_id",
                    "X-Client-Secret: $client_secret",
                    "Content-Type: application/json",
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Determine status (basic check; adjust based on API response format if needed)
                $status = ($http_code == 200) ? 'sent' : 'failed';
                $error = ($status == 'failed') ? $response : null;
            }
        }
    }

    // Log the result to the database
    $log_sql = "
        INSERT INTO `" . $full_table_name . "` (ticket_id, user_id, phone, sent_at, status, message, error)
        VALUES (:ticket_id, :user_id, :phone, '$now_time', :status, :message, :error)
    ";
    try {
        $log_stmt = $db->prepare($log_sql);
        $log_stmt->execute([
            ':ticket_id' => $ticket_id,
            ':user_id' => $user_id,
            ':phone' => $phone,
            ':status' => $status,
            ':message' => $message,
            ':error' => $error
        ]);
        log_message("Logged SMS for ticket_id $ticket_id: status $status", $logEvents);
    } catch (PDOException $e) {
        log_message("Logging failed for ticket_id $ticket_id: " . $e->getMessage(), $logEvents);
    }
}