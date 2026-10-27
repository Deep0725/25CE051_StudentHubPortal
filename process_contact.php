<?php
/* ==========================================================================
   Student Hub Portal - Contact Form Processor
   File: process_contact.php
   1. Receives POST data from contact.html
   2. Validates required fields and email on the SERVER side
   3. Sanitizes the text (removes unsafe HTML characters)
   4. Appends the message to data/contacts.json (old messages are preserved)
   5. Redirects back to contact.html with a success or error message
   ========================================================================== */

// Only allow POST requests. Direct page visits are sent back to the form.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: contact.html");
    exit;
}

// ---------------------------------------------------------------------------
// 1. COLLECT INPUT DATA
// ---------------------------------------------------------------------------
$name    = trim($_POST["name"] ?? "");
$email   = trim($_POST["email"] ?? "");
$subject = trim($_POST["subject"] ?? "");
$message = trim($_POST["message"] ?? "");

$errors = [];

// ---------------------------------------------------------------------------
// 2. VALIDATE EACH FIELD ON THE SERVER
// ---------------------------------------------------------------------------

// Full Name: required, only letters and spaces, minimum 2 characters
if ($name === "") {
    $errors[] = "Name is required.";
} elseif (!preg_match("/^[A-Za-z ]{2,}$/", $name)) {
    $errors[] = "Name must contain only letters and spaces (at least 2 characters).";
}

// Email: required, must be a valid email format
if ($email === "") {
    $errors[] = "Email is required.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Please enter a valid email address.";
}

// Subject: required
if ($subject === "") {
    $errors[] = "Subject is required.";
}

// Message: required, minimum 10 characters
if ($message === "") {
    $errors[] = "Message is required.";
} elseif (strlen($message) < 10) {
    $errors[] = "Message must be at least 10 characters long.";
}

// ---------------------------------------------------------------------------
// 3. IF THERE ARE ANY ERRORS, GO BACK TO THE FORM WITH THE MESSAGE
// ---------------------------------------------------------------------------
if (!empty($errors)) {
    header("Location: contact.html?status=error&msg=" . urlencode(implode(" | ", $errors)));
    exit;
}

// ---------------------------------------------------------------------------
// 4. SANITIZE TEXT and BUILD THE RECORD
// htmlspecialchars() converts <, >, &, " and ' into safe HTML entities
// so the stored text can never run scripts when displayed later
// ---------------------------------------------------------------------------
function sanitizeText($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, "UTF-8");
    return $data;
}

$record = [
    "name"         => sanitizeText($name),
    "email"        => $email,
    "subject"      => sanitizeText($subject),
    "message"      => sanitizeText($message),
    "submitted_at" => date("Y-m-d H:i:s")
];

// ---------------------------------------------------------------------------
// 5. LOAD EXISTING MESSAGES FROM data/contacts.json
// ---------------------------------------------------------------------------
$dataFile = "data/contacts.json";
$records  = [];

// Create the data folder if it does not exist
if (!is_dir("data")) {
    mkdir("data", 0755, true);
}

if (file_exists($dataFile)) {
    $content = trim(file_get_contents($dataFile));

    if ($content !== "") {
        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // File is valid JSON - keep all old messages
            $records = $decoded;
        } else {
            // Malformed JSON: keep a backup copy, then start a fresh list
            copy($dataFile, $dataFile . ".bak");
            $records = [];
        }
    }
}

// Append the new message (old messages are NOT overwritten)
$records[] = $record;

// ---------------------------------------------------------------------------
// 6. SAVE THE UPDATED LIST BACK TO THE FILE
// ---------------------------------------------------------------------------
$json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if (file_put_contents($dataFile, $json, LOCK_EX) === false) {
    header("Location: contact.html?status=error&msg=" . urlencode("Sorry, your message could not be saved. Please try again."));
    exit;
}

// ---------------------------------------------------------------------------
// 7. SUCCESS - redirect back to the contact page with a message
// ---------------------------------------------------------------------------
header("Location: contact.html?status=success");
exit;
