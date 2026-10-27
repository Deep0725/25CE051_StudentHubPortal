<?php
/* ==========================================================================
   Student Hub Portal - Shared Session / Security File
   File: auth.php
   Every protected page starts with:  require_once "auth.php";
   It does three jobs:
     1. Configures secure session cookies, then starts the session
     2. Checks the 15-minute inactivity timeout (server side)
     3. Provides the helper functions isLoggedIn() and requireLogin()
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. SESSION COOKIE SECURITY (must run BEFORE session_start)
// ---------------------------------------------------------------------------
// HTTPS check: on local WAMP (http://localhost) this stays FALSE,
// so local testing keeps working. On a real HTTPS site it becomes TRUE.
$isHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");

session_set_cookie_params([
    "lifetime" => 0,        // Cookie ends when the browser closes
    "path"     => "/",
    "domain"   => "",
    "secure"   => $isHttps, // Only send cookie over HTTPS
    "httponly" => true,     // JavaScript cannot read the session cookie
    "samesite" => "Lax"     // Basic CSRF protection
]);

// Strict mode: PHP rejects session IDs that it did not generate itself
ini_set("session.use_strict_mode", "1");

// Start the session (creates $_SESSION and the session cookie)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// 2. INACTIVITY TIMEOUT - 15 minutes
// This check runs on EVERY request that includes this file.
// ---------------------------------------------------------------------------
$sessionTimeout = 15 * 60; // 15 minutes expressed in seconds

if (isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true) {

    // Did the student stay idle for more than 15 minutes?
    if (isset($_SESSION["last_activity"]) &&
        (time() - $_SESSION["last_activity"]) > $sessionTimeout) {

        // Yes: destroy the session and send them back to the login page
        $_SESSION = [];
        session_destroy();

        header("Location: login.php?status=timeout");
        exit;
    }

    // No: refresh the activity timer for the next request
    $_SESSION["last_activity"] = time();
}

// ---------------------------------------------------------------------------
// 3. HELPER FUNCTIONS
// ---------------------------------------------------------------------------

// Returns true when a valid login session exists
function isLoggedIn()
{
    return isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true;
}

// Protected pages call this as their first line of real code.
// If nobody is logged in, the visitor is redirected to the login page.
function requireLogin()
{
    if (!isLoggedIn()) {
        header("Location: login.php?status=please-login");
        exit;
    }
}
