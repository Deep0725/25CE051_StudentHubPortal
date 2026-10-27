<?php
/* ==========================================================================
   Student Hub Portal - Event Registration Handler
   File: register_event.php
   Works together with events.html + events.js:

     POST  (from the Register form on the Events page)
           -> saves one row in the `registrations` table
           -> redirects back to events.html with a status message
              (Post/Redirect/Get - refresh never re-registers)

     GET   (the guest link "Login to Register")
           -> remembers "events.html" in the session
           -> sends the visitor to login.php first
           -> process_login.php brings them back to the Events page

   Rules:
     - The student id comes ONLY from the session (student_db_id).
       A student id posted by the browser is ignored completely.
     - Same message for "already registered" and for the database's
       UNIQUE key: "You are already registered for this event."
     - Events that do not exist are rejected with a friendly message.
   ========================================================================== */

require_once "auth.php";

// Where this script always sends the visitor back to.
$returnPage = "events.html";

// Helper: Post/Redirect/Get back to the Events page with a message.
function redirectToEvents($status, $message)
{
    global $returnPage;
    header(
        "Location: " . $returnPage .
        "?status=" . urlencode($status) .
        "&msg=" . urlencode($message)
    );
    exit;
}

// ---------------------------------------------------------------------------
// 1. VISITORS WHO ARE NOT LOGGED IN
// Ask them to log in first, and remember to bring them back afterwards.
// ---------------------------------------------------------------------------
if (!isLoggedIn()) {
    $_SESSION["redirect_after_login"] = $returnPage;
    header("Location: login.php?status=please-login");
    exit;
}

// ---------------------------------------------------------------------------
// 2. ONLY POST IS ALLOWED (a GET by a logged-in user does nothing)
// ---------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . $returnPage);
    exit;
}

// ---------------------------------------------------------------------------
// 3. CSRF CHECK (same session token style as the admin pages)
// ---------------------------------------------------------------------------
$sentToken     = (string)($_POST["csrf_token"] ?? "");
$expectedToken = (string)($_SESSION["csrf_token"] ?? "");

if ($expectedToken === "" || $sentToken === "" || !hash_equals($expectedToken, $sentToken)) {
    redirectToEvents("error", "Security check failed. Please reload the Events page and try again.");
}

// ---------------------------------------------------------------------------
// 4. VALIDATE THE EVENT ID (must be a positive whole number)
// ---------------------------------------------------------------------------
$eventId = filter_input(INPUT_POST, "event_id", FILTER_VALIDATE_INT);

if ($eventId === false || $eventId === null || $eventId < 1) {
    redirectToEvents("error", "Invalid event. Please choose an event from the list.");
}

// ---------------------------------------------------------------------------
// 5. SAVE THE REGISTRATION (identity from the session only)
// ---------------------------------------------------------------------------
require_once "db.php";

// NEVER read a student id from the form - only from the logged-in session.
$studentId = (int)$_SESSION["student_db_id"];

try {
    // 5a. Admins may BROWSE the Events page but must never register
    //     "as a student". The role is read fresh from the DATABASE
    //     (the same rule events_api.php uses) - never from the form.
    $stmt = $conn->prepare("SELECT role FROM students WHERE id = ?");
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($account !== null && (string)$account["role"] === "admin") {
        redirectToEvents("error", "Admin accounts cannot register for events.");
    }

    // 5b. The event must really exist (deleted events = unavailable).
    //     The project has no event status column and no event date rule,
    //     so availability = "the event still exists".
    $stmt = $conn->prepare("SELECT id FROM events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($event === null) {
        redirectToEvents("error", "This event is no longer available for registration.");
    }

    // 5c. Friendly duplicate check before inserting.
    $stmt = $conn->prepare(
        "SELECT id FROM registrations WHERE student_id = ? AND event_id = ?"
    );
    $stmt->bind_param("ii", $studentId, $eventId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing !== null) {
        redirectToEvents("error", "You are already registered for this event.");
    }

    // 5d. Insert. registered_at uses its DEFAULT CURRENT_TIMESTAMP.
    //     The UNIQUE key (student_id, event_id) is the final safety net
    //     if two requests arrive at the exact same moment.
    $stmt = $conn->prepare(
        "INSERT INTO registrations (student_id, event_id) VALUES (?, ?)"
    );
    $stmt->bind_param("ii", $studentId, $eventId);
    $stmt->execute();
    $stmt->close();

} catch (mysqli_sql_exception $e) {
    // MySQL error 1062 = duplicate row (UNIQUE key was hit)
    if ((int)$e->getCode() === 1062) {
        redirectToEvents("error", "You are already registered for this event.");
    }
    // Any other database problem - safe generic message, no SQL details
    redirectToEvents("error", "Could not complete your registration. Please try again.");
}

// ---------------------------------------------------------------------------
// 6. SUCCESS - back to the Events page (message shown by events.js)
// ---------------------------------------------------------------------------
redirectToEvents("success", "You have registered for this event successfully.");
