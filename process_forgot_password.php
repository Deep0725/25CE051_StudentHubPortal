<?php
/* ==========================================================================
   Student Hub Portal - Forgot Password Processor
   File: process_forgot_password.php

   Receives POSTs from forgot_password.php (two steps):

   step = verify : check Student ID / Admin ID + mobile against ONE students
                    row whose role is 'student' or 'admin' (any other role
                    is rejected). On success the account's DATABASE id and
                    its role are stored in the session (fp_verified_id and
                    fp_verified_role) and the session ID is regenerated.
                    On failure ONE generic message is shown, whether the ID
                    was wrong, the mobile was wrong, or the role is not
                    allowed.

   step = reset  : only works while those server-side session values exist
                    and have not expired (5 minutes). Validates the new
                    password with the SAME rule as the registration form,
                    hashes it with password_hash(), and updates the row
                    whose id AND role came from the SESSION - never from
                    the browser.

   Protections:
     - CSRF token compared with hash_equals() on every request.
     - Session-based rate limit: 5 failed verification attempts per
       10 minutes locks this session for 10 minutes (no new tables).
     - Only POST is allowed; Student ID / mobile never enter a URL.
     - Passwords are stored only as password_hash() values (never shown).
   ========================================================================== */

require_once "auth.php";

// Helper: Post/Redirect/Get back to the form with a message.
function fpError($message)
{
    header("Location: forgot_password.php?status=error&msg=" . urlencode($message));
    exit;
}

function fpOk($message)
{
    header("Location: forgot_password.php?status=ok&msg=" . urlencode($message));
    exit;
}

// Only POST requests are accepted - a direct visit just returns to the form.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: forgot_password.php");
    exit;
}

// ---------------------------------------------------------------------------
// 1. CSRF CHECK (same session token style as the admin and Events pages)
// ---------------------------------------------------------------------------
$sentToken     = (string)($_POST["csrf_token"] ?? "");
$expectedToken = (string)($_SESSION["csrf_token"] ?? "");

if ($expectedToken === "" || $sentToken === "" || !hash_equals($expectedToken, $sentToken)) {
    fpError("Security check failed. Please open the Forgot Password page again.");
}

$step = (string)($_POST["step"] ?? "");

// ---------------------------------------------------------------------------
// 2. STEP: VERIFY STUDENT ID / ADMIN ID + MOBILE NUMBER
// ---------------------------------------------------------------------------
if ($step === "verify") {

    // --- 2a. Rate limit (session based - protects against repeated guessing)
    $now = time();
    if (isset($_SESSION["fp_locked_until"]) && $now < (int)$_SESSION["fp_locked_until"]) {
        fpError("Too many attempts. Please wait a few minutes and try again.");
    }
    if (!isset($_SESSION["fp_window_start"]) ||
        ($now - (int)$_SESSION["fp_window_start"]) > 600) {
        // Start a fresh 10-minute window
        $_SESSION["fp_window_start"] = $now;
        $_SESSION["fp_attempts"]     = 0;
    }

    // --- 2b. Basic format checks (specific messages leak nothing about accounts)
    $studentId = trim($_POST["student_id"] ?? "");
    $mobile    = trim($_POST["mobile"] ?? "");

    if ($studentId === "") {
        fpError("Please enter your Student ID.");
    }
    if (strlen($studentId) > 20) {
        fpError("The details could not be verified. Please check your Student ID and registered mobile number.");
    }
    if ($mobile === "") {
        fpError("Please enter your registered mobile number.");
    }
    // Exactly the same mobile rule the registration form uses
    if (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) {
        fpError("Mobile number must be exactly 10 digits starting with 6, 7, 8, or 9.");
    }

    // --- 2c. Look up the account (prepared statement)
    //     role IN ('student', 'admin') accepts only the two valid roles that
    //     exist in this database. Anything else (unknown ID, wrong mobile,
    //     or an unsupported role) simply matches no row.
    require_once "db.php";

    $accountPk   = 0;
    $accountRole = "";

    try {
        $stmt = $conn->prepare(
            "SELECT id, role FROM students WHERE student_id = ? AND mobile = ? AND role IN ('student', 'admin')"
        );
        $stmt->bind_param("ss", $studentId, $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null) {
            $accountPk   = (int)$row["id"];
            $accountRole = (string)$row["role"];
        }
    } catch (mysqli_sql_exception $e) {
        // Database problem - same generic message, never SQL details
        fpError("The details could not be verified. Please check your Student ID and registered mobile number.");
    }

    // --- 2d. Wrong details -> generic message + count the failed attempt
    if ($accountPk === 0 ||
        !in_array($accountRole, ["student", "admin"], true)) {
        $_SESSION["fp_attempts"] = (int)($_SESSION["fp_attempts"] ?? 0) + 1;

        if ($_SESSION["fp_attempts"] >= 5) {
            $_SESSION["fp_locked_until"] = time() + 600;
            fpError("Too many attempts. Please wait a few minutes and try again.");
        }

        // ONE message for every reason (unknown ID, wrong mobile, bad role)
        fpError("The details could not be verified. Please check your Student ID and registered mobile number.");
    }

    // --- 2e. Verified! Store the result SERVER-SIDE (5-minute lifetime),
    //         regenerate the session ID (session-fixation protection),
    //         and show the new-password form.
    //         The browser never supplies the id or the role - they are
    //         copied straight from the matched database row.
    $_SESSION["fp_verified_id"]   = $accountPk;
    $_SESSION["fp_verified_role"] = $accountRole;
    $_SESSION["fp_expires_at"]    = time() + 300;
    $_SESSION["fp_attempts"]      = 0;
    unset($_SESSION["fp_window_start"], $_SESSION["fp_locked_until"]);

    session_regenerate_id(true);

    fpOk("Verification successful. Set your new password below.");
}

// ---------------------------------------------------------------------------
// 3. STEP: SAVE THE NEW PASSWORD
// ---------------------------------------------------------------------------
if ($step === "reset") {

    // The browser supplies NOTHING about identity here - the account to
    // update comes from the server-side verification made in step 2.
    if (!isset($_SESSION["fp_verified_id"]) ||
        !isset($_SESSION["fp_verified_role"]) ||
        !isset($_SESSION["fp_expires_at"]) ||
        time() > (int)$_SESSION["fp_expires_at"]) {
        unset($_SESSION["fp_verified_id"], $_SESSION["fp_verified_role"],
              $_SESSION["fp_expires_at"]);
        fpError("Your verification has expired. Please start again.");
    }

    // Defence in depth: the stored role must still be one of the roles
    // that step 1 is allowed to verify (student or admin).
    $accountRole = (string)$_SESSION["fp_verified_role"];
    if (!in_array($accountRole, ["student", "admin"], true)) {
        unset($_SESSION["fp_verified_id"], $_SESSION["fp_verified_role"],
              $_SESSION["fp_expires_at"]);
        fpError("Your verification has expired. Please start again.");
    }

    $password    = (string)($_POST["password"] ?? "");
    $confirmPass = (string)($_POST["confirm_password"] ?? "");

    // --- 3a. Validate with the SAME password rule as the registration form
    if ($password === "") {
        fpError("Please enter a new password.");
    }
    if (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{8,}$/", $password)) {
        fpError("Password must be at least 8 characters long and include an uppercase letter, lowercase letter, number, and special character.");
    }
    if ($confirmPass === "") {
        fpError("Please confirm your new password.");
    }
    if ($password !== $confirmPass) {
        fpError("Passwords do not match.");
    }

    // --- 3b. Hash and save (same hashing approach as registration/login)
    require_once "db.php";

    // The id AND the role to update both come from the SESSION (set only
    // after that exact row was verified in step 2), so an account can only
    // ever change the password of its OWN row - an admin verifies the
    // admin's own ID + mobile and therefore only touches the admin's row.
    $accountPk    = (int)$_SESSION["fp_verified_id"];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $conn->prepare("UPDATE students SET password = ? WHERE id = ? AND role = ?");
        $stmt->bind_param("sis", $passwordHash, $accountPk, $accountRole);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        fpError("Sorry, your password could not be changed. Please try again.");
    }

    // --- 3c. Done: clear the temporary session values, regenerate the
    //         session ID (password changed = new session identity, the
    //         same habit process_login.php follows), then go to Login.
    unset($_SESSION["fp_verified_id"], $_SESSION["fp_verified_role"],
          $_SESSION["fp_expires_at"], $_SESSION["fp_attempts"],
          $_SESSION["fp_window_start"], $_SESSION["fp_locked_until"]);

    session_regenerate_id(true);

    header("Location: login.php?status=reset");
    exit;
}

// Unknown step value - just return to the form.
header("Location: forgot_password.php");
exit;
