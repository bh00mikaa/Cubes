<?php
/**
 * send_inquiry.php
 * Handles inquiry form submissions and sends emails
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate required fields
$required_fields = ['name', 'email', 'phone', 'organization', 'role', 'city'];
$missing_fields = [];

foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        $missing_fields[] = $field;
    }
}

if (!empty($missing_fields)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Missing required fields: ' . implode(', ', $missing_fields)
    ]);
    exit();
}

// Sanitize inputs
$name = htmlspecialchars(strip_tags($data['name']));
$email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$phone = htmlspecialchars(strip_tags($data['phone']));
$organization = htmlspecialchars(strip_tags($data['organization']));
$role = htmlspecialchars(strip_tags($data['role']));
$city = htmlspecialchars(strip_tags($data['city']));
$units = !empty($data['units']) ? htmlspecialchars(strip_tags($data['units'])) : 'Not specified';
$message = !empty($data['message']) ? htmlspecialchars(strip_tags($data['message'])) : 'No additional message';
$demo_date = !empty($data['demo_date']) ? htmlspecialchars(strip_tags($data['demo_date'])) : 'Not specified';

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit();
}

// Email configuration
$to = "Keylesscubes@vistech.co.in";
$subject = "New Demo Request from $name - $organization";

// Create email body
$email_body = "
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #FDB913; padding: 20px; text-align: center; }
        .header h2 { margin: 0; color: #2d2d2d; }
        .content { background: #f9f9f9; padding: 20px; }
        .field { margin-bottom: 15px; }
        .label { font-weight: bold; color: #2d2d2d; }
        .value { color: #555; }
        .footer { text-align: center; padding: 20px; color: #777; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2>New Demo Request</h2>
        </div>
        <div class='content'>
            <div class='field'>
                <span class='label'>Name:</span>
                <span class='value'>$name</span>
            </div>
            <div class='field'>
                <span class='label'>Email:</span>
                <span class='value'>$email</span>
            </div>
            <div class='field'>
                <span class='label'>Phone:</span>
                <span class='value'>$phone</span>
            </div>
            <div class='field'>
                <span class='label'>Organization/Society:</span>
                <span class='value'>$organization</span>
            </div>
            <div class='field'>
                <span class='label'>Role:</span>
                <span class='value'>$role</span>
            </div>
            <div class='field'>
                <span class='label'>City:</span>
                <span class='value'>$city</span>
            </div>
            <div class='field'>
                <span class='label'>Number of Units:</span>
                <span class='value'>$units</span>
            </div>
            <div class='field'>
                <span class='label'>Preferred Demo Date:</span>
                <span class='value'>$demo_date</span>
            </div>
            <div class='field'>
                <span class='label'>Message:</span>
                <div class='value' style='background: white; padding: 10px; border-radius: 5px;'>
                    $message
                </div>
            </div>
        </div>
        <div class='footer'>
            <p>This inquiry was submitted through the Keyless Cubes website</p>
            <p>Submitted on: " . date('F j, Y, g:i a') . "</p>
        </div>
    </div>
</body>
</html>
";

// Email headers
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: noreply@keylesscubes.com" . "\r\n";
$headers .= "Reply-To: $email" . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Send email
$mail_sent = mail($to, $subject, $email_body, $headers);

// Log the inquiry (optional - store in database or file)
$log_entry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'organization' => $organization,
    'city' => $city,
    'mail_sent' => $mail_sent
];

// Create logs directory if it doesn't exist
$logs_dir = dirname(__DIR__) . '/logs';
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}

// Log to file
file_put_contents(
    $logs_dir . '/inquiries.log',
    json_encode($log_entry) . "\n",
    FILE_APPEND
);

// Return response
if ($mail_sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Inquiry sent successfully'
    ]);
} else {
    // Even if mail() fails, log the inquiry so you don't lose it
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send email. Please try again or contact us directly.'
    ]);
}
?>