<?php
/* ==========================================================================
   Student Hub Portal - My Event Registrations (JSON helper)
   File: my_registrations.php
   The public Events page (events.html + events.js) fetches this file to
   decide what each row's Action column should show:

     - not logged in -> "Login to Register" link (no token needed)
     - logged in     -> Register form (needs the session CSRF token)
     - already in the list -> "Registered" badge instead of a button

   Output (JSON object):
     {
       "logged_in":  true / false,
       "role":       "student" / "admin" / "" (guest),
       "csrf_token": "...." or null,
       "event_ids":  [3, 7, 10]   // events THIS session's student joined
     }

   Security notes:
     - The student is identified ONLY from the session (student_db_id).
       Nothing that the browser sends is trusted here.
     - The CSRF token is only handed out to logged-in visitors.
     - The JSON never contains other students' data.
   ========================================================================== */

require_once "auth.php";
require_once "db.php";

header("Content-Type: application/json; charset=utf-8");

$out = [
    "logged_in"  => isLoggedIn(),
    "role"       => (string)($_SESSION["role"] ?? ""),
    "csrf_token" => null,
    "event_ids"  => []
];

if (isLoggedIn()) {

    // Reuse the exact same session token style as the admin pages
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
    }
    $out["csrf_token"] = (string)$_SESSION["csrf_token"];

    // Which events has THIS logged-in student registered for?
    $studentId = (int)$_SESSION["student_db_id"];

    try {
        $stmt = $conn->prepare(
            "SELECT event_id FROM registrations WHERE student_id = ?"
        );
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $out["event_ids"][] = (int)$row["event_id"];
        }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        // Database problem: show an empty list rather than a broken page.
        // (The Events list itself is loaded from events_data.php.)
        $out["event_ids"] = [];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
