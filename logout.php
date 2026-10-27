<?php
/* ==========================================================================
   Student Hub Portal - Logout
   File: logout.php
   1. Removes every session variable
   2. Expires the session cookie in the browser
   3. Destroys the session on the server
   4. Redirects to the login page with a friendly message
   After this, the old session ID is useless - protected pages
   will redirect the user to the login page again.
   ========================================================================== */

// Start the same secure session that auth.php uses
require_once "auth.php";

// Step 1: Empty the session array (remove all session variables)
$_SESSION = [];

// Step 2: Make the browser forget the session cookie immediately
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(session_name(), "", [
        "expires"  => time() - 42000,      // cookie expired 12 hours ago
        "path"     => $params["path"],
        "domain"   => $params["domain"],
        "secure"   => $params["secure"],
        "httponly" => $params["httponly"],
        "samesite" => $params["samesite"]
    ]);
}

// Step 3: Destroy the session record on the server
session_destroy();

// Step 4: Send the user back to the login page
header("Location: login.php?status=loggedout");
exit;
