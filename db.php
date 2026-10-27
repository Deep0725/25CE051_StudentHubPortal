<?php
/* ==========================================================================
   StudentHub - Database Connection
   File: db.php
   This is the ONE place where the database settings live.
   Any page that needs the database simply writes:  require_once "db.php";
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. DATABASE SETTINGS
// These are the default local WAMP / XAMPP settings.
// Change them here if your MySQL username or password is different.
// ---------------------------------------------------------------------------
$dbHost = "localhost";   // MySQL server address (local machine)
$dbPort = 3306;          // Default MySQL port (WAMP MySQL = 3306)
$dbUser = "root";        // Default MySQL username
$dbPass = "";            // Default WAMP password is EMPTY (XAMPP is also empty)
$dbName = "studenthub";  // Database created by studenthub.sql

// ---------------------------------------------------------------------------
// 2. CREATE THE CONNECTION (mysqli - object style)
// The @ operator hides the raw warning so our own clear message is shown
// ---------------------------------------------------------------------------
$conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

// ---------------------------------------------------------------------------
// 3. CHECK THE CONNECTION
// connect_errno is not 0 when the connection failed
// ---------------------------------------------------------------------------
if ($conn->connect_errno) {
    // Development error message - useful while practicing.
    // On a real live website you would LOG the error instead of printing it,
    // so that database details are never shown to visitors.
    die("Database connection failed: " . htmlspecialchars($conn->connect_error));
}

// ---------------------------------------------------------------------------
// 4. SET THE CHARACTER SET
// utf8mb4 correctly stores every language AND emoji
// ---------------------------------------------------------------------------
$conn->set_charset("utf8mb4");

// $conn is now ready to be used in any page that includes this file.
