<?php
/* ==========================================================================
   Student Hub Portal - Forgot Password (student-facing)
   File: forgot_password.php

   Two simple steps, both stored SERVER-SIDE in the session:

   Step 1 (no session flag yet): the visitor types their Student ID /
           Admin ID and registered mobile number.
           process_forgot_password.php checks both against ONE row of the
           students table whose role is 'student' or 'admin' (every other
           role is rejected).
   Step 2 (session flag exists): shows "new password" + "confirm password"
           fields. The account that is allowed to change the password is
           read from the SESSION (fp_verified_id + fp_verified_role) -
           never from a hidden browser field.

   Security notes:
      - The verification result (fp_verified_id / fp_verified_role) lives
        only in the session, expires after 5 minutes, and the session ID
        is regenerated when it is set (stops session-fixation attacks).
     - Student ID / mobile never appear in any URL - everything is POST.
     - This check (student ID + mobile) is DEMO-GRADE verification only,
       it is NOT strong enough for a real production system.
   ========================================================================== */

require_once "auth.php";

// Fresh CSRF token for the form(s) on this page (same session token
// style that the admin pages and the Events page already use).
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
}
$csrfToken = (string)$_SESSION["csrf_token"];

// Step 2 is only offered while the server-side verification is valid.
$verified    = isset($_SESSION["fp_verified_id"]) && isset($_SESSION["fp_verified_role"]);
$notExpired  = isset($_SESSION["fp_expires_at"]) && time() <= (int)$_SESSION["fp_expires_at"];
$showStep2   = $verified && $notExpired;

if ($verified && !$notExpired) {
    // Too late - forget the verification and start again.
    unset($_SESSION["fp_verified_id"], $_SESSION["fp_verified_role"],
          $_SESSION["fp_expires_at"]);
    $showStep2 = false;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Student Hub Portal</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

    <header>
        <div class="header-top">
            <h1>Student Hub Portal</h1>
            <button class="theme-btn" id="theme-btn" aria-label="Switch to dark mode" title="Switch to dark mode">🌙</button>
        </div>

        <button class="hamburger-btn"
                id="hamburger-btn"
                aria-label="Open navigation menu"
                aria-expanded="false">
            &#9776;
        </button>

        <nav>
            <a href="index.html">Home</a>
            <a href="aboutpage.html">About</a>
            <a href="register.html">Register</a>
            <a href="login.php">Login</a>
            <a href="contact.html">Contact</a>
        </nav>
    </header>

    <main>

        <div class="form-container">

            <h2>Forgot Password</h2>

            <!-- Success / error message sent back by process_forgot_password.php -->
            <div id="fpMessage" class="success-msg"></div>

            <?php if ($showStep2): ?>

                <!-- -------------------------------------------------------
                     STEP 2: verification succeeded - choose a new password
                     ------------------------------------------------------- -->
                <p>Verification successful. Please choose a new password.</p>

                <form action="process_forgot_password.php" method="POST">

                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="step" value="reset">

                    <div class="form-group">
                        <label for="newpass">New Password:</label>
                        <input type="password" id="newpass" name="password" placeholder="At least 8 characters" required>
                    </div>

                    <div class="form-group">
                        <label for="confirmpass">Confirm New Password:</label>
                        <input type="password" id="confirmpass" name="confirm_password" placeholder="Type the new password again" required>
                    </div>

                    <input type="submit" value="Change Password" class="form-button">

                </form>

            <?php else: ?>

                <!-- -------------------------------------------------------
                     STEP 1: identify the account (Student ID / Admin ID
                     + mobile) - works for students and admins
                     ------------------------------------------------------- -->
                <p>
                    Enter your Student ID / Admin ID and registered mobile number to reset your password.
                    This page works for student and admin accounts.
                </p>

                <form action="process_forgot_password.php" method="POST">

                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="step" value="verify">

                    <div class="form-group">
                        <label for="sid">Student ID / Admin ID:</label>
                        <input type="text" id="sid" name="student_id" placeholder="e.g. STU0016 or STU0007" maxlength="20" required>
                    </div>

                    <div class="form-group">
                        <label for="mob">Registered Mobile Number:</label>
                        <input type="tel" id="mob" name="mobile" placeholder="10-digit mobile number" maxlength="10" required>
                    </div>

                    <input type="submit" value="Verify Details" class="form-button">

                </form>

            <?php endif; ?>

            <p>
                <a href="login.php">Back to Login</a>
            </p>

        </div>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal. All Rights Reserved.</p>
    </footer>

    <script>
        // Show the message sent back after redirect (Post/Redirect/Get)
        document.addEventListener("DOMContentLoaded", function () {
            var params = new URLSearchParams(window.location.search);
            var status = params.get("status");
            var box = document.getElementById("fpMessage");

            if (!box || !status) return;

            if (status === "error") {
                box.textContent = params.get("msg") || "Something went wrong. Please try again.";
                box.style.backgroundColor = "#f8d7da";
                box.style.color = "#721c24";
                box.style.borderColor = "#f5c6cb";
                box.style.display = "block";
                // The student ID / mobile were only ever sent by POST,
                // so there is nothing to remove from the address bar.
            } else if (status === "ok") {
                box.textContent = params.get("msg") || "Done.";
                box.style.display = "block";
            }
        });
    </script>

    <script src="components.js"></script>

</body>

</html>
