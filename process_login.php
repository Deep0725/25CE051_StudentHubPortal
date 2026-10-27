<?php
/* ==========================================================================
   Student Hub Portal - Login Processor
   File: process_login.php
   1. Receives POST data from login.php (email + password)
   2. Looks up the student with a PREPARED statement (no SQL injection)
   3. Verifies the password against the stored hash with password_verify()
   4. Shows ONE generic error message for "wrong email" AND "wrong password"
      so attackers never learn which email addresses exist
   5. On success: regenerates the session ID, stores safe session values,
      and redirects the student to the dashboard
   ========================================================================== */

// Start the secure session (from auth.php) - needed for session_regenerate_id()
require_once "auth.php";

// Only allow POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

// ---------------------------------------------------------------------------
// 1. COLLECT INPUT
// ---------------------------------------------------------------------------
$email    = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if ($email === "" || $password === "") {
    header("Location: login.php?status=error&msg=" . urlencode("Please enter your email and password."));
    exit;
}

// ---------------------------------------------------------------------------
// 2. FIND THE STUDENT BY EMAIL (prepared statement)
// ---------------------------------------------------------------------------
require_once "db.php";

try {
    // The ? placeholder is filled in by bind_param - the email text
    // is NEVER part of the SQL command, so SQL injection cannot work.
    $stmt = $conn->prepare(
        "SELECT id, student_id, name, email, password, course, year, role
         FROM students
         WHERE email = ?"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result  = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    // Database problem - show a safe generic message, never SQL details
    header("Location: login.php?status=error&msg=" . urlencode("Login is temporarily unavailable. Please try again later."));
    exit;
}

$conn->close();

// ---------------------------------------------------------------------------
// 3. CHECK EMAIL AND PASSWORD
// The SAME message is used for "email not found" and "wrong password".
// password_verify() safely compares the plain password with the bcrypt hash.
// ---------------------------------------------------------------------------
$genericError = "Incorrect email or password. Please try again.";

if ($student === null || !password_verify($password, $student["password"])) {
    header("Location: login.php?status=error&msg=" . urlencode($genericError));
    exit;
}

// ---------------------------------------------------------------------------
// 4. LOGIN SUCCESSFUL
// ---------------------------------------------------------------------------

// Create a BRAND NEW session ID - this stops "session fixation" attacks,
// where an attacker tries to force a known session ID onto a victim.
session_regenerate_id(true);

// Store only safe values in the session.
// The password (or its hash) is NEVER stored in the session.
$_SESSION["student_db_id"] = (int)$student["id"];
$_SESSION["student_id"]    = $student["student_id"];
$_SESSION["student_name"]  = $student["name"];
$_SESSION["student_email"] = $student["email"];
$_SESSION["course"]        = $student["course"];
$_SESSION["year"]          = $student["year"];
$_SESSION["role"]          = $student["role"];
$_SESSION["logged_in"]     = true;
$_SESSION["last_activity"] = time();   // start the 15-minute idle timer

// ---------------------------------------------------------------------------
// 5. OPTIONAL RETURN PAGE (e.g. the Events page)
// Some pages ask a visitor to log in FIRST and then come back
// (register_event.php stores the page name in redirect_after_login).
// Only a simple local page name is accepted (letters/digits/-/_ plus
// .html or .php) - no "http://", no folders, no ".." - so this can never
// be used as an open redirect to another website.
// If the value is missing, nothing changes: the normal role-based
// redirection in the next section runs exactly as before.
// ---------------------------------------------------------------------------
$afterLogin = (string)($_SESSION["redirect_after_login"] ?? "");
unset($_SESSION["redirect_after_login"]);

if ($afterLogin !== "" && preg_match('/^[A-Za-z0-9_\-]+\.(html|php)$/', $afterLogin)) {
    header("Location: " . $afterLogin);
    exit;
}

// ---------------------------------------------------------------------------
// 6. ROLE-BASED REDIRECTION
// Only 'student' can be assigned through the public registration form
// (the SQL insert never includes the role column, so the database default
//  'student' always applies).
//
// Admins go straight to the admin-only dashboard so they never land on the
// student dashboard (which shows student-specific details such as the
// enrollment number, course and year). Students go to their own dashboard.
// ---------------------------------------------------------------------------
if (($_SESSION["role"] ?? "") === "admin") {
    header("Location: admin_dashboard.php");
} else {
    header("Location: dashboard.php");
}
exit;
