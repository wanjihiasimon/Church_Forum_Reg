<?php
// submit.php
header('Content-Type: application/json');

// ====================
// CONFIG
// ====================
define('CSV_FILE', __DIR__ . '/churchReg.csv');
define('ADMIN_EMAIL', 'info@ndakalaadvisory.co.ke');
define('FROM_EMAIL', 'info@ndakalaadvisory.co.ke'); // must exist in cPanel
define('BACKUP_DIR', __DIR__ . '/backups/');
define('RATE_DIR', BACKUP_DIR . 'ratelimit/');

// Rate limits (adjust as needed)
define('MAX_PER_HOUR', 3);   // max submissions per email per rolling hour
define('MAX_PER_DAY', 10);   // max submissions per email per rolling 24 hours

// Ensure backup directories exist
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
if (!is_dir(RATE_DIR)) mkdir(RATE_DIR, 0755, true);

// ====================
// EMAIL HELPER
// ====================
function send_email($to, $subject, $message, $from) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $from\r\n";
    $headers .= "Reply-To: $from\r\n";
    return @mail($to, $subject, $message, $headers, "-f$from");
}

// ====================
// SIMPLE RATE LIMIT (per email) - file-based
// ====================
function rate_file_for_email($email) {
    $hash = hash('sha256', strtolower($email));
    return RATE_DIR . $hash . '.json';
}

/**
 * Register a submission timestamp and check limits.
 * Returns [allowed (bool), reason (string|null), retry_after_seconds (int|null)]
 */
function check_and_record_rate($email) {
    $now = time();
    $hourWindow = 3600;
    $dayWindow = 86400;
    $file = rate_file_for_email($email);

    // Initialize structure
    $data = ['timestamps' => []];

    // Read existing
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['timestamps']) && is_array($decoded['timestamps'])) {
                $data = $decoded;
            }
        }
    }

    // Purge timestamps older than 24 hours (we only need last 24h)
    $data['timestamps'] = array_filter($data['timestamps'], function($ts) use ($now, $dayWindow) {
        return ($ts >= $now - $dayWindow);
    });

    // Count in windows
    $countLastDay = 0;
    $countLastHour = 0;
    foreach ($data['timestamps'] as $t) {
        if ($t >= $now - $dayWindow) $countLastDay++;
        if ($t >= $now - $hourWindow) $countLastHour++;
    }

    // Check limits
    if ($countLastHour >= MAX_PER_HOUR) {
        // determine earliest timestamp within hour window to compute retry-after
        $earliestInHour = min(array_filter($data['timestamps'], function($t) use ($now, $hourWindow){ return $t >= $now - $hourWindow; }));
        $retryAfter = ($earliestInHour + $hourWindow) - $now;
        return [false, 'Hourly limit exceeded', max(1, $retryAfter)];
    }
    if ($countLastDay >= MAX_PER_DAY) {
        $earliestInDay = min(array_filter($data['timestamps'], function($t) use ($now, $dayWindow){ return $t >= $now - $dayWindow; }));
        $retryAfter = ($earliestInDay + $dayWindow) - $now;
        return [false, 'Daily limit exceeded', max(1, $retryAfter)];
    }

    // Allowed -> append timestamp and persist
    $data['timestamps'][] = $now;
    // keep only last 500 for safety
    if (count($data['timestamps']) > 500) {
        $data['timestamps'] = array_slice($data['timestamps'], -500);
    }
    // atomic write
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, json_encode($data), LOCK_EX) !== false) {
        rename($tmp, $file);
    }
    return [true, null, null];
}

// ====================
// VALIDATION
// ====================
$required = ['fullName', 'email', 'phone', 'attendees', 'paymentMethod'];
$missing = [];
foreach ($required as $f) {
    if (empty($_POST[$f])) $missing[] = $f;
}
if ($missing) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Missing fields: '.implode(', ', $missing)]);
    exit;
}

// Sanitize input
$fullName = trim($_POST['fullName']);
$email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
$phone = preg_replace('/[^0-9+]/', '', $_POST['phone']);
$organization = !empty($_POST['organization']) ? trim($_POST['organization']) : 'Not specified';
$attendees = max(1, min(20, intval($_POST['attendees'])));
$paymentMethod = trim($_POST['paymentMethod']);
$totalAmount = $attendees * 20000; // fixed seminar price

if (!$email) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Invalid email address']);
    exit;
}

// Per-email rate limit check BEFORE persisting/writing
list($allowed, $reason, $retryAfter) = check_and_record_rate($email);
if (!$allowed) {
    http_response_code(429);
    $msg = $reason ?: 'Rate limit exceeded';
    // friendly textual retry time
    $retryText = $retryAfter ? ('Try again in ' . gmdate('H:i:s', $retryAfter) . ' (HH:MM:SS)') : '';
    echo json_encode(['success'=>false,'message'=>$msg, 'retry_after_seconds'=>$retryAfter, 'retry_text'=>$retryText]);
    exit;
}

// ====================
// CSV FILE HANDLING
// ====================
$isNew = !file_exists(CSV_FILE);
$fp = fopen(CSV_FILE, 'a');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Unable to open CSV file']);
    exit;
}
if ($isNew) {
    fputcsv($fp, [
        'Timestamp','Full Name','Email','Phone','Organization',
        'Attendees','Payment Method','Total Amount','IP Address'
    ]);
}
fputcsv($fp, [
    date('Y-m-d H:i:s'),
    $fullName,
    $email,
    $phone,
    $organization,
    $attendees,
    $paymentMethod,
    $totalAmount,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
fclose($fp);

// Optional backup copy per day
$backupFile = BACKUP_DIR . 'churchReg_' . date('Y-m-d') . '.csv';
if (!file_exists($backupFile)) copy(CSV_FILE, $backupFile);

// ====================
// EMAILS
// ====================
$subjectUser = "✅ Registration Confirmation – Ndakala Advisory Seminar";
$subjectAdmin = "New Registration – Governance & Compliance Seminar";

$messageUser = "
<html><body style='font-family:Arial,sans-serif;line-height:1.6;'>
<h2 style='color:#0056A6;'>Thank you for registering!</h2>
<p>Dear <strong>".htmlspecialchars($fullName)."</strong>,</p>
<p>Your registration for the <strong>Governance & Compliance for Religious Organizations</strong> seminar has been received.</p>
<p><strong>Details:</strong></p>
<ul>
  <li>Date: Thursday, 6th November 2025</li>
  <li>Venue: The Glee Hotel, Nairobi</li>
  <li>Fee: KES 20,000 per person</li>
  <li>Payment Method: ".htmlspecialchars($paymentMethod)."</li>
  <li>Total Amount: KES " . number_format($totalAmount) . "</li>
</ul>
<p>For M-Pesa payments use Paybill <strong>488488</strong>, Account <strong>8964610016</strong>.</p>
<p>Contact us at <strong>info@ndakalaadvisory.co.ke</strong> or <strong>+254 705 874715</strong> for assistance.</p>
<p>– Ndakala Advisory LLP</p>
</body></html>
";

$messageAdmin = "
<html><body style='font-family:Arial,sans-serif;line-height:1.6;'>
<h2 style='color:#0056A6;'>New Seminar Registration</h2>
<p><strong>Name:</strong> ".htmlspecialchars($fullName)."<br>
<strong>Email:</strong> ".htmlspecialchars($email)."<br>
<strong>Phone:</strong> ".htmlspecialchars($phone)."<br>
<strong>Organization:</strong> ".htmlspecialchars($organization)."<br>
<strong>Attendees:</strong> ".intval($attendees)."<br>
<strong>Payment Method:</strong> ".htmlspecialchars($paymentMethod)."<br>
<strong>Total:</strong> KES " . number_format($totalAmount) . "<br>
<strong>IP:</strong> " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "</p>
</body></html>
";

send_email($email, $subjectUser, $messageUser, FROM_EMAIL);
send_email(ADMIN_EMAIL, $subjectAdmin, $messageAdmin, FROM_EMAIL);

// ====================
// SUCCESS RESPONSE
// ====================
echo json_encode([
    'success' => true,
    'message' => 'Registration saved successfully!',
    'data' => [
        'name' => $fullName,
        'email' => $email,
        'attendees' => $attendees,
        'paymentMethod' => $paymentMethod,
        'totalAmount' => $totalAmount
    ]
]);
