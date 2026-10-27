<?php
/* ==========================================================================
   Student Hub Portal - Events JSON API
   File: events_api.php

   A simple JSON API for the existing "events" table. The Manage Events
   page (manage_events.php) calls this file with the Fetch API, so the
   list refreshes without reloading the whole page.

   HTTP methods (one file, one place for all event data operations):
     GET     -> list all events, or one event with ?id=N      (public)
     POST    -> add a new event                                (admin only)
     PUT     -> update an event with ?id=N                     (admin only)
     DELETE  -> delete an event with ?id=N                     (admin only)

   Security:
     - every database query uses a prepared statement
     - POST / PUT / DELETE require a logged-in ADMIN (role re-checked in
       the database, same style as manage_events.php) -> 401 / 403 JSON
     - POST / PUT / DELETE also require the CSRF token sent by the page
       in the X-CSRF-Token header (session token, hash_equals compared)
     - validation messages are safe to show; SQL errors are never echoed

   Poster uploads:
     - posters keep using the exact same upload rules as before
       (JPEG/PNG/WebP, 2 MB, Fileinfo check, random server filename)
     - a file can only be uploaded with POST (multipart/form-data),
       because PHP cannot read uploaded files from a real PUT body
     - so an edit that REPLACES a poster is sent as POST + a hidden
       field _method=PUT, which this API treats exactly like a PUT
     - text-only edits use a normal PUT with a JSON body

   Uses the existing db.php connection and auth.php session system.
   ========================================================================== */

require_once "db.php";
require_once "auth.php";

// ---------------------------------------------------------------------------
// 1. SMALL HELPERS
// ---------------------------------------------------------------------------

// Print any JSON response and stop the script
function sendJson($statusCode, $payload)
{
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload);
    exit;
}

// Read ONE text field from JSON body OR from a form ($_POST).
// Non-string values (arrays etc.) safely become an empty string.
function strField($data, $key)
{
    $value = $data[$key] ?? "";
    return is_string($value) ? trim($value) : "";
}

// mb_strlen() when available, byte-safe fallback otherwise
function textLength($text)
{
    return function_exists("mb_strlen") ? mb_strlen($text) : strlen($text);
}

// Same category list the public Events page offers in its filter
$allowedCategories = ["Workshop", "Competition", "Academic", "Seminar", "Cultural", "Technical"];

// Shared field validation for BOTH add and update (same rules as before).
// Returns an array of error messages (empty array = everything valid).
function validateEventFields($title, $description, $eventDate, $category, $location)
{
    global $allowedCategories;
    $errors = [];

    if ($title === "") {
        $errors[] = "Event title is required.";
    } elseif (textLength($title) > 150) {
        $errors[] = "Event title must be 150 characters or fewer.";
    }

    if ($description === "") {
        $errors[] = "Event description is required.";
    } elseif (textLength($description) > 5000) {
        $errors[] = "Event description must be 5000 characters or fewer.";
    }

    if ($eventDate === "") {
        $errors[] = "Event date is required.";
    } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $eventDate)) {
        $errors[] = "Please enter a valid event date.";
    } else {
        $parts = explode("-", $eventDate);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            $errors[] = "Please enter a valid event date.";
        }
    }

    if ($category === "") {
        $errors[] = "Please select an event category.";
    } elseif (!in_array($category, $allowedCategories, true)) {
        $errors[] = "Invalid event category selected.";
    }

    if ($location === "") {
        $errors[] = "Event venue is required.";
    } elseif (textLength($location) > 150) {
        $errors[] = "Event venue must be 150 characters or fewer.";
    }

    return $errors;
}

// Only publish a poster path that lives inside the managed upload folder
function publicPosterPath($relativePath)
{
    if ($relativePath === null || $relativePath === "") {
        return null;
    }
    if (strpos($relativePath, "uploads/event_posters/") !== 0) {
        return null;
    }
    return $relativePath;
}

// Shape ONE row for the JSON output: id becomes a real number (mysqli
// returns it as a string) and only managed poster paths are published.
function apiEventRow($row)
{
    if ($row === null) {
        return null;
    }
    $row["id"]          = (int)$row["id"];
    $row["poster_path"] = publicPosterPath($row["poster_path"]);
    return $row;
}

// Read one event row (raw poster_path - used internally before every
// update / delete so poster files can be handled after a successful query)
function fetchEventByIdRaw($conn, $id)
{
    try {
        $stmt = $conn->prepare(
            "SELECT id, title, description, event_date, category, location, poster_path
             FROM events WHERE id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    } catch (mysqli_sql_exception $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// 2. POSTER UPLOAD / DELETE HELPERS
   // (identical rules to the original manage_events.php module)
// ---------------------------------------------------------------------------

$uploadDirRelative = "uploads/event_posters/";
$uploadDirLocal     = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR
                    . "event_posters" . DIRECTORY_SEPARATOR;
$maxPosterBytes     = 2 * 1024 * 1024; // 2 MB

$allowedPosters = [
    "jpg"  => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png"  => "image/png",
    "webp" => "image/webp",
];

// Validate ONE uploaded poster and save it with a NEW server-generated name.
// Returns: ["path" => relative path or null, "error" => message or null]
function savePosterUpload($file)
{
    global $uploadDirLocal, $uploadDirRelative, $maxPosterBytes, $allowedPosters;

    // (a) Nothing selected - posters are optional, this is NOT an error
    if (!is_array($file) || !isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) {
        return ["path" => null, "error" => null];
    }

    // (b) PHP's own upload error codes
    if ($file["error"] === UPLOAD_ERR_INI_SIZE || $file["error"] === UPLOAD_ERR_FORM_SIZE) {
        return ["path" => null, "error" => "The poster is larger than the server upload limit. The maximum allowed size is 2 MB (upload_max_filesize in php.ini)."];
    }
    if ($file["error"] === UPLOAD_ERR_PARTIAL) {
        return ["path" => null, "error" => "The poster was only partially uploaded. Please try again."];
    }
    if ($file["error"] !== UPLOAD_ERR_OK) {
        return ["path" => null, "error" => "The poster upload failed. Please try again."];
    }

    // (c) Real uploaded temporary file (never trust a path from the request)
    $tmpName = $file["tmp_name"] ?? "";
    if ($tmpName === "" || !is_uploaded_file($tmpName)) {
        return ["path" => null, "error" => "The poster upload could not be verified by the server."];
    }

    // (d) Empty or oversized file
    $size = (int)($file["size"] ?? 0);
    if ($size <= 0) {
        return ["path" => null, "error" => "The poster file is empty."];
    }
    if ($size > $maxPosterBytes) {
        return ["path" => null, "error" => "The poster is too large. The maximum allowed size is 2 MB."];
    }

    // (e) Extension allow-list (the original name is only used for this check)
    $extension = strtolower(pathinfo((string)($file["name"] ?? ""), PATHINFO_EXTENSION));
    if (!isset($allowedPosters[$extension])) {
        return ["path" => null, "error" => "Only JPEG, PNG and WebP poster images are allowed (maximum 2 MB)."];
    }

    // (f) TRUE file type read from the file CONTENT with Fileinfo
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($tmpName);
    if ($realMime !== $allowedPosters[$extension]) {
        return ["path" => null, "error" => "The file content is not a valid " . strtoupper($extension) . " image. Only JPEG, PNG and WebP posters are allowed."];
    }

    // (g) Second image check - getimagesize() verifies it can be decoded
    if (@getimagesize($tmpName) === false) {
        return ["path" => null, "error" => "The uploaded file is not a readable image."];
    }

    // (h) Make sure the managed folder exists and is writable
    if (!is_dir($uploadDirLocal) && !mkdir($uploadDirLocal, 0755, true)) {
        return ["path" => null, "error" => "The poster upload folder could not be created on the server."];
    }
    if (!is_writable($uploadDirLocal)) {
        return ["path" => null, "error" => "The poster upload folder is not writable on the server."];
    }

    // (i) Brand-new server-generated filename - the original name is NEVER used
    $extension = ($extension === "jpeg") ? "jpg" : $extension;
    do {
        $newName     = bin2hex(random_bytes(16)) . "." . $extension;
        $destination = $uploadDirLocal . $newName;
    } while (file_exists($destination));

    // (j) Move the validated file into place
    if (!move_uploaded_file($tmpName, $destination)) {
        return ["path" => null, "error" => "The poster could not be saved on the server. Please try again."];
    }
    @chmod($destination, 0644);

    return ["path" => $uploadDirRelative . $newName, "error" => null];
}

// Delete a poster file, but ONLY when the path really points inside the
// managed folder. Anything else is ignored.
function deletePosterFile($relativePath)
{
    if ($relativePath === null || $relativePath === "") {
        return false;
    }

    $managedPrefix = "uploads/event_posters/";
    if (strpos($relativePath, $managedPrefix) !== 0) {
        return false;
    }
    if (strpos($relativePath, "..") !== false || strpos($relativePath, "\\") !== false) {
        return false;
    }

    $fileName = substr($relativePath, strlen($managedPrefix));
    if ($fileName === "" || strpos($fileName, "/") !== false) {
        return false;
    }

    $managedDir = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "event_posters";
    $fullPath   = $managedDir . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($fullPath)) {
        return false;
    }

    // Final safety check: the resolved path must be inside the managed folder
    $realDir  = realpath($managedDir);
    $realFile = realpath($fullPath);
    if ($realDir === false || $realFile === false) {
        return false;
    }
    if (strpos($realFile, $realDir . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    return @unlink($realFile);
}

// ---------------------------------------------------------------------------
// 3. AUTHENTICATION + CSRF CHECKS FOR STATE-CHANGING REQUESTS
// ---------------------------------------------------------------------------

// POST / PUT / DELETE may only be used by a logged-in administrator.
// The role is read fresh from the DATABASE (never trusted from the session
// alone). Errors are returned as JSON - this API must NEVER redirect.
function requireAdminApi()
{
    global $conn;

    if (!isLoggedIn()) {
        sendJson(401, ["success" => false, "message" => "You must be logged in to manage events."]);
    }

    try {
        $stmt = $conn->prepare("SELECT role FROM students WHERE id = ?");
        $studentId = (int)($_SESSION["student_db_id"] ?? 0);
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        $row = null;
    }

    if ($row === null || $row["role"] !== "admin") {
        sendJson(403, ["success" => false, "message" => "Admin access is required to manage events."]);
    }

    // Keep the session role in sync with the database
    $_SESSION["role"] = $row["role"];
}

// Every state-changing request must carry the session's CSRF token in the
// X-CSRF-Token header (manage_events.php prints it in a meta tag).
function requireCsrfToken()
{
    $sent     = (string)($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    $expected = (string)($_SESSION["csrf_token"] ?? "");

    if ($expected === "" || $sent === "" || !hash_equals($expected, $sent)) {
        sendJson(403, ["success" => false, "message" => "Security check failed (invalid CSRF token). Please reload the page and try again."]);
    }
}

// ---------------------------------------------------------------------------
// 4. READ THE REQUEST BODY
// The browser sends JSON for text-only requests and multipart/form-data
// when a poster file is attached. PHP fills $_POST / $_FILES for the
// multipart case, so both shapes are handled here.
// ---------------------------------------------------------------------------
function readInputData(&$jsonError)
{
    $contentType = (string)($_SERVER["CONTENT_TYPE"] ?? "");

    if (stripos($contentType, "application/json") !== false) {
        $raw  = file_get_contents("php://input");
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $jsonError = "The request body is not valid JSON.";
            return [];
        }
        return $data;
    }

    // multipart/form-data or urlencoded: PHP already parsed it
    return $_POST;
}

// ---------------------------------------------------------------------------
// 5. RESOLVE THE REAL HTTP METHOD
// PHP cannot read uploaded files from a real PUT body, so an edit that
// includes a NEW poster file is sent as POST + hidden field _method=PUT.
// ---------------------------------------------------------------------------
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "POST" && (strField($_POST, "_method") === "PUT")) {
    $method = "PUT";
}

// ---------------------------------------------------------------------------
// 6. GET - list all events, or one event with ?id=N  (public, read-only)
// ---------------------------------------------------------------------------
if ($method === "GET") {

    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

    try {
        if ($id > 0) {
            $row = fetchEventByIdRaw($conn, $id);
            if ($row === null) {
                sendJson(404, ["success" => false, "message" => "Event not found."]);
            }
            sendJson(200, ["success" => true, "event" => apiEventRow($row)]);
        }

        $result = $conn->query(
            "SELECT id, title, description, event_date, category, location, poster_path
             FROM events
             ORDER BY event_date ASC, id ASC"
        );
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e) {
        // Never expose SQL details in the JSON response
        sendJson(500, ["success" => false, "message" => "The event list could not be loaded right now."]);
    }

    $events = [];
    foreach ($rows as $row) {
        $events[] = apiEventRow($row);
    }

    sendJson(200, ["success" => true, "events" => $events]);
}

// ---------------------------------------------------------------------------
// 7. POST - add a new event  (admin + CSRF)
// ---------------------------------------------------------------------------
if ($method === "POST") {

    requireAdminApi();
    requireCsrfToken();

    $jsonError = "";
    $data      = readInputData($jsonError);
    if ($jsonError !== "") {
        sendJson(400, ["success" => false, "message" => $jsonError]);
    }

    $title       = strField($data, "title");
    $description = strField($data, "description");
    $eventDate   = strField($data, "event_date");
    $category    = strField($data, "category");
    $location    = strField($data, "location");

    // 1. Text fields first - no file is touched while errors exist
    $errors = validateEventFields($title, $description, $eventDate, $category, $location);
    if (!empty($errors)) {
        sendJson(400, ["success" => false, "message" => implode(" | ", $errors)]);
    }

    // 2. Poster is optional; only possible when the request carried a file
    $upload = savePosterUpload($_FILES["poster"] ?? []);
    if ($upload["error"] !== null) {
        sendJson(400, ["success" => false, "message" => $upload["error"]]);
    }

    try {
        $posterPath = $upload["path"]; // null when no file was chosen
        $insStmt = $conn->prepare(
            "INSERT INTO events (title, description, event_date, category, location, poster_path)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $insStmt->bind_param(
            "ssssss",
            $title,
            $description,
            $eventDate,
            $category,
            $location,
            $posterPath
        );
        $insStmt->execute();
        $newId = (int)$conn->insert_id;
        $insStmt->close();
    } catch (mysqli_sql_exception $e) {
        // Database failed: remove the poster we just saved, then answer safely
        if ($upload["path"] !== null) {
            deletePosterFile($upload["path"]);
        }
        sendJson(500, ["success" => false, "message" => "The event could not be added. Please check the details and try again."]);
    }

    sendJson(201, [
        "success" => true,
        "message" => "Event added successfully. Event ID: " . $newId,
        "event"   => apiEventRow(fetchEventByIdRaw($conn, $newId)),
    ]);
}

// ---------------------------------------------------------------------------
// 8. PUT - update an event with ?id=N  (admin + CSRF)
// Text-only updates use a JSON body; edits with a NEW poster file arrive
// as POST + _method=PUT (multipart), resolved in section 5 above.
// ---------------------------------------------------------------------------
if ($method === "PUT") {

    requireAdminApi();
    requireCsrfToken();

    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    if ($id <= 0) {
        sendJson(400, ["success" => false, "message" => "A valid event ID is required."]);
    }

    $jsonError = "";
    $data      = readInputData($jsonError);
    if ($jsonError !== "") {
        sendJson(400, ["success" => false, "message" => $jsonError]);
    }

    $title       = strField($data, "title");
    $description = strField($data, "description");
    $eventDate   = strField($data, "event_date");
    $category    = strField($data, "category");
    $location    = strField($data, "location");

    $errors = validateEventFields($title, $description, $eventDate, $category, $location);
    if (!empty($errors)) {
        sendJson(400, ["success" => false, "message" => implode(" | ", $errors)]);
    }

    // Poster decision sent by the edit form (JSON or form field)
    $posterAction = strField($data, "poster_action");
    if (!in_array($posterAction, ["keep", "replace", "remove"], true)) {
        $posterAction = "keep";
    }

    // Current record (source of truth for the existing poster)
    $current = fetchEventByIdRaw($conn, $id);
    if ($current === null) {
        sendJson(404, ["success" => false, "message" => "Event not found."]);
    }

    $uploadedNewPath = null;
    $newPosterPath   = $current["poster_path"];

    if ($posterAction === "replace") {
        // Validate the NEW file BEFORE touching the old poster
        $upload = savePosterUpload($_FILES["poster"] ?? []);
        if ($upload["error"] !== null) {
            sendJson(400, ["success" => false, "message" => $upload["error"]]);
        }
        if ($upload["path"] === null) {
            sendJson(400, ["success" => false, "message" => "Please choose a replacement poster file, or select Keep current poster."]);
        }
        $uploadedNewPath = $upload["path"];
        $newPosterPath   = $upload["path"];
    } elseif ($posterAction === "remove") {
        $newPosterPath = null;
    }
    // "keep" = keep $newPosterPath exactly as it is

    try {
        // Prepared UPDATE - id, title, description, date, category, venue, poster
        $updStmt = $conn->prepare(
            "UPDATE events
             SET title = ?, description = ?, event_date = ?, category = ?,
                 location = ?, poster_path = ?
             WHERE id = ?"
        );
        $updStmt->bind_param(
            "ssssssi",
            $title,
            $description,
            $eventDate,
            $category,
            $location,
            $newPosterPath,
            $id
        );
        $updStmt->execute();
        $updStmt->close();
    } catch (mysqli_sql_exception $e) {
        // Database failed: keep the OLD poster, remove the new upload (if any)
        if ($uploadedNewPath !== null) {
            deletePosterFile($uploadedNewPath);
        }
        sendJson(500, ["success" => false, "message" => "The changes could not be saved. Please try again."]);
    }

    // ---- Database update SUCCEEDED. Only now may the old file go. ----
    if ($posterAction === "replace" || $posterAction === "remove") {
        deletePosterFile($current["poster_path"]);
    }

    sendJson(200, [
        "success" => true,
        "message" => "Event updated successfully.",
        "event"   => apiEventRow(fetchEventByIdRaw($conn, $id)),
    ]);
}

// ---------------------------------------------------------------------------
// 9. DELETE - delete an event with ?id=N  (admin + CSRF)
// ---------------------------------------------------------------------------
if ($method === "DELETE") {

    requireAdminApi();
    requireCsrfToken();

    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    if ($id <= 0) {
        sendJson(400, ["success" => false, "message" => "A valid event ID is required."]);
    }

    // Remember the poster so the file can be removed after a good DELETE
    $current = fetchEventByIdRaw($conn, $id);
    if ($current === null) {
        sendJson(404, ["success" => false, "message" => "Event not found."]);
    }

    try {
        // Foreign keys stay ENABLED. The schema uses ON DELETE CASCADE for
        // registrations.event_id, so registrations for THIS event are
        // removed automatically and no unrelated record is touched.
        $delStmt = $conn->prepare("DELETE FROM events WHERE id = ?");
        $delStmt->bind_param("i", $id);
        $delStmt->execute();
        $deleted = $delStmt->affected_rows > 0;
        $delStmt->close();
    } catch (mysqli_sql_exception $e) {
        sendJson(500, ["success" => false, "message" => "This event could not be deleted because related records are linked to it. No data was changed."]);
    }

    if (!$deleted) {
        sendJson(404, ["success" => false, "message" => "Event not found."]);
    }

    // Database deletion succeeded - now remove the managed poster file
    deletePosterFile($current["poster_path"]);

    sendJson(200, ["success" => true, "message" => "Event deleted successfully."]);
}

// ---------------------------------------------------------------------------
// 10. ANY OTHER METHOD (PATCH, OPTIONS, ...) -> 405 Method Not Allowed
// ---------------------------------------------------------------------------
header("Allow: GET, POST, PUT, DELETE");
sendJson(405, ["success" => false, "message" => "Method not allowed. Use GET, POST, PUT or DELETE."]);
