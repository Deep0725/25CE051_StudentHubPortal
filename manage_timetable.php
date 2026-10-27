<?php
/* ==========================================================================
   Student Hub Portal - Timetable Management Module
   File: manage_timetable.php
   Admin-only page with the usual CRUD features:
     A. Add timetable entry     (POST -> prepared INSERT)
     B. View timetable entries  (SELECT table, optional course/year filter)
     C. Edit timetable entry    (POST -> prepared UPDATE)
     D. Delete timetable entry  (POST -> prepared DELETE)
     E. Load one entry for edit (?edit_id=N - view only, saving is POST)

   Every entry MUST specify its department (the 'course' column) and its
   academic year (the 'year' column) - exactly the same values the students
   table uses - so each class has its own timetable.

   Security (server side, never trusted from the browser):
     - guests      -> login.php        (requireLogin)
     - students    -> dashboard.php    (role re-checked in the DATABASE)
     - CSRF token  -> same session token + hash_equals() style as
                      manage_attendance.php / manage_events.php
     - prepared statements everywhere; every displayed value is escaped
     - server-side validation of EVERY field, including
       end_time must be later than start_time

   Uses the existing auth.php, db.php and style.css. No schema change.
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. LOGIN CHECK - guests are sent to the login page
// ---------------------------------------------------------------------------
require_once "auth.php";
requireLogin();

// ---------------------------------------------------------------------------
// 2. ADMIN-ONLY CHECK (server side)
// The role is read fresh from the DATABASE, so a student can never reach
// this page (or any of its add / edit / delete actions) by typing the URL
// or forging a form.
// ---------------------------------------------------------------------------
require_once "db.php";

try {
    $roleStmt = $conn->prepare("SELECT role FROM students WHERE id = ?");
    $adminCheckId = (int)($_SESSION["student_db_id"] ?? 0);
    $roleStmt->bind_param("i", $adminCheckId);
    $roleStmt->execute();
    $roleRow = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();
} catch (mysqli_sql_exception $e) {
    $roleRow = null;
}

if ($roleRow === null || $roleRow["role"] !== "admin") {
    header("Location: dashboard.php");
    exit;
}

// Keep the session role in sync with the database
$_SESSION["role"] = $roleRow["role"];

// ---------------------------------------------------------------------------
// 3. CSRF TOKEN (created once per session - same style as the other pages)
// ---------------------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
}

// ---------------------------------------------------------------------------
// 4. ALLOWED VALUES (checked on the server for EVERY request)
//    department = the 'course' column of the students table
//    acad. year = the 'year'   column of the students table
//    subject    = free text (any subject name, up to 50 characters)
// ---------------------------------------------------------------------------
$allowedCourses = [
    "Computer Engineering",
    "Information Technology",
    "Civil Engineering",
    "Mechanical Engineering",
    "Electrical Engineering"
];
$allowedYears    = ["First Year", "Second Year", "Third Year", "Fourth Year"];
$allowedDays     = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

// Maximum subject name length - matches the subject VARCHAR(50) column
const TIMETABLE_MAX_SUBJECT_LENGTH = 50;

// A time must look like 09:00 (24-hour, zero-padded) and be a real time
function timetableValidTime($timeString)
{
    if (!preg_match("/^([01][0-9]|2[0-3]):[0-5][0-9]$/", $timeString)) {
        return false;
    }
    return true;
}

// Converts "09:30" to minutes since midnight (makes comparing times easy)
function timetableMinutes($timeString)
{
    return ((int)substr($timeString, 0, 2)) * 60 + (int)substr($timeString, 3, 2);
}

// Shared validation for BOTH add and edit.
// Returns an array of error messages (empty array = everything valid).
function timetableValidateFields($course, $year, $day, $subject, $startTime, $endTime, $faculty, $classroom)
{
    global $allowedCourses, $allowedYears, $allowedDays;
    $errors = [];

    if (!in_array($course, $allowedCourses, true)) {
        $errors[] = "Please select a valid department.";
    }
    if (!in_array($year, $allowedYears, true)) {
        $errors[] = "Please select a valid academic year.";
    }
    if (!in_array($day, $allowedDays, true)) {
        $errors[] = "Please select a valid day of the week.";
    }
    // Subject is free text: required, not blank, and short enough for the DB
    if ($subject === "") {
        $errors[] = "Please enter a subject name.";
    } elseif (mb_strlen($subject) > TIMETABLE_MAX_SUBJECT_LENGTH) {
        $errors[] = "Subject name must be " . TIMETABLE_MAX_SUBJECT_LENGTH . " characters or fewer.";
    }

    if (!timetableValidTime($startTime)) {
        $errors[] = "Please enter a valid start time (for example 09:00).";
    }
    if (!timetableValidTime($endTime)) {
        $errors[] = "Please enter a valid end time (for example 10:00).";
    }
    if (timetableValidTime($startTime) && timetableValidTime($endTime)) {
        // The requirement: the end time must be LATER than the start time
        if (timetableMinutes($endTime) <= timetableMinutes($startTime)) {
            $errors[] = "The end time must be later than the start time.";
        }
    }

    // Faculty and classroom are OPTIONAL - but if given they must be short enough
    if (mb_strlen($faculty) > 100) {
        $errors[] = "Faculty name must be 100 characters or fewer.";
    }
    if (mb_strlen($classroom) > 100) {
        $errors[] = "Classroom must be 100 characters or fewer.";
    }

    return $errors;
}

// Builds the URL that returns to a filtered timetable list
function timetableBackUrl($course = "", $year = "")
{
    $url = "manage_timetable.php";
    $params = [];
    if ($course !== "") {
        $params[] = "course=" . urlencode($course);
    }
    if ($year !== "") {
        $params[] = "year=" . urlencode($year);
    }
    if (count($params) > 0) {
        $url .= "?" . implode("&", $params);
    }
    return $url;
}

// ---------------------------------------------------------------------------
// 5. HANDLE POST ACTIONS (before any HTML is printed)
// Add / Edit / Delete all use POST, never GET - same as manage_students.
// ---------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // --- 5a. CSRF check: form field must equal the session token ---
    $sentToken     = $_POST["csrf_token"] ?? "";
    $expectedToken = (string)($_SESSION["csrf_token"] ?? "");

    if ($expectedToken === "" || !is_string($sentToken) || $sentToken === ""
        || !hash_equals($expectedToken, $sentToken)) {
        header("Location: manage_timetable.php?status=error&msg="
            . urlencode("Security check failed. Please reload the page and try again."));
        exit;
    }

    $action = $_POST["action"] ?? "";

    // -------------------------------------------------------------------
    // A. ADD TIMETABLE ENTRY
    // -------------------------------------------------------------------
    if ($action === "add" || $action === "edit") {

        $course     = trim($_POST["course"] ?? "");
        $year       = trim($_POST["year"] ?? "");
        $day        = $_POST["day"] ?? "";
        $subject    = trim($_POST["subject"] ?? "");
        $startTime  = trim($_POST["start_time"] ?? "");
        $endTime    = trim($_POST["end_time"] ?? "");
        $faculty    = trim($_POST["faculty"] ?? "");
        $classroom  = trim($_POST["classroom"] ?? "");
        $entryId    = (int)($_POST["id"] ?? 0);

        // Optional fields: an empty string is stored as NULL
        $facultyValue   = ($faculty === "") ? null : $faculty;
        $classroomValue = ($classroom === "") ? null : $classroom;

        // Server-side validation of every field
        $errors = timetableValidateFields(
            $course, $year, $day, $subject, $startTime, $endTime, $faculty, $classroom
        );

        if ($action === "edit" && $entryId <= 0) {
            $errors[] = "Timetable entry not found.";
        }

        if (!empty($errors)) {
            header("Location: manage_timetable.php?status=error&msg="
                . urlencode(implode(" | ", $errors)));
            exit;
        }

        try {
            if ($action === "add") {
                $insStmt = $conn->prepare(
                    "INSERT INTO timetable
                         (course, year, day_of_week, subject, start_time, end_time, faculty, classroom)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $insStmt->bind_param(
                    "ssssssss",
                    $course,
                    $year,
                    $day,
                    $subject,
                    $startTime,
                    $endTime,
                    $facultyValue,
                    $classroomValue
                );
                $insStmt->execute();
                $insStmt->close();
            } else {
                // Check that this row really exists BEFORE updating it, so
                // editing a deleted/unknown entry shows a real error
                $chkStmt = $conn->prepare("SELECT id FROM timetable WHERE id = ?");
                $chkStmt->bind_param("i", $entryId);
                $chkStmt->execute();
                $entryExists = ($chkStmt->get_result()->fetch_assoc() !== null);
                $chkStmt->close();

                if (!$entryExists) {
                    header("Location: manage_timetable.php?status=error&msg="
                        . urlencode("Timetable entry not found."));
                    exit;
                }

                // Only THIS row is updated (matched by its id)
                $updStmt = $conn->prepare(
                    "UPDATE timetable
                     SET course = ?, year = ?, day_of_week = ?, subject = ?,
                         start_time = ?, end_time = ?, faculty = ?, classroom = ?
                     WHERE id = ?"
                );
                $updStmt->bind_param(
                    "ssssssssi",
                    $course,
                    $year,
                    $day,
                    $subject,
                    $startTime,
                    $endTime,
                    $facultyValue,
                    $classroomValue,
                    $entryId
                );
                $updStmt->execute();
                $updStmt->close();
            }
        } catch (mysqli_sql_exception $e) {
            // 1062 = this lecture slot already exists (UNIQUE key)
            if ($e->getCode() == 1062) {
                $saveMessage = "That lecture slot already exists for this department, year and day.";
            } else {
                $saveMessage = "The timetable entry could not be saved. Please check the details and try again.";
            }
            header("Location: manage_timetable.php?status=error&msg=" . urlencode($saveMessage));
            exit;
        }

        if ($action === "add") {
            $doneMessage = "Timetable entry added successfully.";
        } else {
            $doneMessage = "Timetable entry updated successfully.";
        }
        header("Location: manage_timetable.php?status=success&msg=" . urlencode($doneMessage));
        exit;
    }

    // -------------------------------------------------------------------
    // D. DELETE TIMETABLE ENTRY (POST only - never a GET link)
    // -------------------------------------------------------------------
    if ($action === "delete") {

        $entryId = (int)($_POST["id"] ?? 0);

        if ($entryId <= 0) {
            header("Location: manage_timetable.php?status=error&msg="
                . urlencode("Timetable entry not found."));
            exit;
        }

        try {
            $delStmt = $conn->prepare("DELETE FROM timetable WHERE id = ?");
            $delStmt->bind_param("i", $entryId);
            $delStmt->execute();
            $deleted = $delStmt->affected_rows > 0;
            $delStmt->close();
        } catch (mysqli_sql_exception $e) {
            header("Location: manage_timetable.php?status=error&msg="
                . urlencode("The timetable entry could not be deleted. Please try again."));
            exit;
        }

        if ($deleted) {
            header("Location: manage_timetable.php?status=success&msg="
                . urlencode("Timetable entry deleted successfully."));
        } else {
            header("Location: manage_timetable.php?status=error&msg="
                . urlencode("Timetable entry not found."));
        }
        exit;
    }

    // Unknown action: go back to the list
    header("Location: manage_timetable.php");
    exit;
}

// ---------------------------------------------------------------------------
// 6. LOAD ONE ENTRY FOR EDITING (?edit_id=N) - view only, saving is POST
// ---------------------------------------------------------------------------
$editEntry = null;

if (isset($_GET["edit_id"]) && (int)$_GET["edit_id"] > 0) {
    $editId = (int)$_GET["edit_id"];

    try {
        $editStmt = $conn->prepare(
            "SELECT id, course, year, day_of_week, subject, start_time, end_time, faculty, classroom
             FROM timetable WHERE id = ?"
        );
        $editStmt->bind_param("i", $editId);
        $editStmt->execute();
        $editEntry = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    } catch (mysqli_sql_exception $e) {
        $editEntry = null;
    }

    if ($editEntry === null) {
        // fall through - the message banner below shows the error
        $_GET["status"] = "error";
        $_GET["msg"]    = "Timetable entry not found.";
    }
}

// ---------------------------------------------------------------------------
// 7. FILTER VALUES (GET form) - an invalid filter simply shows everything
// ---------------------------------------------------------------------------
$filterCourse = trim($_GET["course"] ?? "");
$filterYear   = trim($_GET["year"] ?? "");

if ($filterCourse !== "" && !in_array($filterCourse, $allowedCourses, true)) {
    $filterCourse = "";
}
if ($filterYear !== "" && !in_array($filterYear, $allowedYears, true)) {
    $filterYear = "";
}

$hasFilter = ($filterCourse !== "" || $filterYear !== "");

// ---------------------------------------------------------------------------
// 8. LOAD THE TIMETABLE LIST (prepared statement with optional WHERE)
//    Days are ordered Monday -> Sunday with FIELD(); times ascending.
// ---------------------------------------------------------------------------
$sql    = "SELECT id, course, year, day_of_week, subject, start_time, end_time, faculty, classroom
           FROM timetable
           WHERE 1=1";
$types  = "";
$params = [];

if ($filterCourse !== "") {
    $sql .= " AND course = ?";
    $types .= "s";
    $params[] = $filterCourse;
}
if ($filterYear !== "") {
    $sql .= " AND year = ?";
    $types .= "s";
    $params[] = $filterYear;
}

$sql .= " ORDER BY course, year,
           FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday',
                 'Friday', 'Saturday', 'Sunday'),
           start_time ASC";

$entries = [];

try {
    $listStmt = $conn->prepare($sql);
    if ($types !== "") {
        $listStmt->bind_param($types, ...$params);
    }
    $listStmt->execute();
    $listResult = $listStmt->get_result();

    while ($row = $listResult->fetch_assoc()) {
        $entries[] = $row;
    }
    $listStmt->close();
} catch (mysqli_sql_exception $e) {
    $entries = [];
    $_GET["status"] = "error";
    $_GET["msg"]    = "The timetable could not be loaded right now.";
}

// ---------------------------------------------------------------------------
// 9. FLASH MESSAGE FROM THE REDIRECT (Post/Redirect/Get)
// ---------------------------------------------------------------------------
$pageStatus  = $_GET["status"] ?? "";
$pageMessage = $_GET["msg"] ?? "";

// Small helper: print a table cell safely ('-' when empty)
function timetableCell($value, $fallback = "-")
{
    if ($value === null || $value === "") {
        $value = $fallback;
    }
    echo htmlspecialchars((string)$value);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Management - Student Hub Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Small page-specific extras - style.css itself is untouched */
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .filter-form .form-group {
            flex: 1;
            min-width: 160px;
            margin-bottom: 0;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
        }
        .filter-actions .form-button,
        .filter-actions .clear-btn {
            width: auto;
            padding: 10px 16px;
        }
        .clear-btn {
            display: inline-block;
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            padding: 10px 16px;
        }
        .row-actions {
            white-space: nowrap;
        }
        .row-actions form {
            display: inline;
        }
        .small-btn {
            padding: 6px 10px;
            font-size: 13px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .edit-btn { background-color: #1e3a5f; color: white; }
        .delete-btn { background-color: #d93025; color: white; }
        .edit-note {
            font-weight: normal;
            font-size: 0.9rem;
            color: #555;
        }
    </style>
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
            <a href="admin_dashboard.php">Admin Dashboard</a>
            <a href="profile.php">Profile</a>
            <a href="manage_students.php">Manage Students</a>
            <a href="manage_events.php">Manage Events</a>
            <a href="manage_attendance.php">Manage Attendance</a>
            <a href="manage_timetable.php">Manage Timetable</a>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <main>

        <section>
            <h2>Timetable Management</h2>
            <p>Add, view, edit and delete timetable entries for every department and academic year.</p>

            <?php if (($pageStatus === "success" || $pageStatus === "error") && $pageMessage !== ""): ?>
                <?php if ($pageStatus === "success"): ?>
                    <div class="success-msg" style="display:block;">
                        <?php echo htmlspecialchars($pageMessage); ?>
                    </div>
                <?php else: ?>
                    <div class="success-msg" style="display:block; background-color:#f8d7da; color:#721c24; border-color:#f5c6cb;">
                        <?php echo htmlspecialchars($pageMessage); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- ===========================================================
             E. FILTER (GET form, works with the list below)
             =========================================================== -->
        <section>
            <h3>Filter</h3>

            <form action="manage_timetable.php" method="GET" class="filter-form">

                <div class="form-group">
                    <label for="filter-course">Department:</label>
                    <select id="filter-course" name="course">
                        <option value="">All departments</option>
                        <?php foreach ($allowedCourses as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php if ($filterCourse === $option) echo "selected"; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="filter-year">Academic Year:</label>
                    <select id="filter-year" name="year">
                        <option value="">All years</option>
                        <?php foreach ($allowedYears as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php if ($filterYear === $option) echo "selected"; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <input type="submit" value="Filter" class="form-button">
                    <?php if ($hasFilter): ?>
                        <a href="manage_timetable.php" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </div>

            </form>
        </section>

        <!-- ===========================================================
             A. ADD  /  C. EDIT  - one form, switches mode
             =========================================================== -->
        <section>
            <div class="form-container">

                <?php if ($editEntry !== null): ?>

                    <h3>Edit Timetable Entry <span class="edit-note">(department, year, day, subject and times)</span></h3>

                    <form action="manage_timetable.php" method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo (int)$editEntry["id"]; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                        <div class="form-group">
                            <label for="edit-course">Department:</label>
                            <select id="edit-course" name="course" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($allowedCourses as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php if ($editEntry["course"] === $option) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit-year">Academic Year:</label>
                            <select id="edit-year" name="year" required>
                                <option value="">-- Select Academic Year --</option>
                                <?php foreach ($allowedYears as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php if ($editEntry["year"] === $option) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit-day">Day of Week:</label>
                            <select id="edit-day" name="day" required>
                                <option value="">-- Select Day --</option>
                                <?php foreach ($allowedDays as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php if ($editEntry["day_of_week"] === $option) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit-subject">Subject:</label>
                            <input type="text" id="edit-subject" name="subject"
                                   maxlength="<?php echo TIMETABLE_MAX_SUBJECT_LENGTH; ?>"
                                   placeholder="Enter subject name"
                                   value="<?php echo htmlspecialchars($editEntry["subject"]); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit-start">Start Time:</label>
                            <input type="time" id="edit-start" name="start_time"
                                   value="<?php echo htmlspecialchars(substr($editEntry["start_time"], 0, 5)); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit-end">End Time:</label>
                            <input type="time" id="edit-end" name="end_time"
                                   value="<?php echo htmlspecialchars(substr($editEntry["end_time"], 0, 5)); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit-faculty">Faculty (optional):</label>
                            <input type="text" id="edit-faculty" name="faculty" maxlength="100"
                                   placeholder="Leave blank if not decided"
                                   value="<?php echo htmlspecialchars($editEntry["faculty"] ?? ""); ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit-classroom">Classroom (optional):</label>
                            <input type="text" id="edit-classroom" name="classroom" maxlength="100"
                                   placeholder="Leave blank if not decided"
                                   value="<?php echo htmlspecialchars($editEntry["classroom"] ?? ""); ?>">
                        </div>

                        <input type="submit" value="Save Changes" class="form-button">
                        <p><a href="manage_timetable.php">Cancel and go back</a></p>
                    </form>

                <?php else: ?>

                    <h3>Add Timetable Entry</h3>

                    <form action="manage_timetable.php" method="POST">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                        <div class="form-group">
                            <label for="add-course">Department:</label>
                            <select id="add-course" name="course" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($allowedCourses as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>">
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add-year">Academic Year:</label>
                            <select id="add-year" name="year" required>
                                <option value="">-- Select Academic Year --</option>
                                <?php foreach ($allowedYears as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>">
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add-day">Day of Week:</label>
                            <select id="add-day" name="day" required>
                                <option value="">-- Select Day --</option>
                                <?php foreach ($allowedDays as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>">
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add-subject">Subject:</label>
                            <input type="text" id="add-subject" name="subject"
                                   maxlength="<?php echo TIMETABLE_MAX_SUBJECT_LENGTH; ?>"
                                   placeholder="Enter subject name" required>
                        </div>

                        <div class="form-group">
                            <label for="add-start">Start Time:</label>
                            <input type="time" id="add-start" name="start_time" required>
                        </div>

                        <div class="form-group">
                            <label for="add-end">End Time:</label>
                            <input type="time" id="add-end" name="end_time" required>
                        </div>

                        <div class="form-group">
                            <label for="add-faculty">Faculty (optional):</label>
                            <input type="text" id="add-faculty" name="faculty" maxlength="100"
                                   placeholder="Leave blank if not decided">
                        </div>

                        <div class="form-group">
                            <label for="add-classroom">Classroom (optional):</label>
                            <input type="text" id="add-classroom" name="classroom" maxlength="100"
                                   placeholder="Leave blank if not decided">
                        </div>

                        <input type="submit" value="Add Entry" class="form-button">
                    </form>

                <?php endif; ?>

            </div>
        </section>

        <!-- ===========================================================
             B. VIEW TIMETABLE - table with edit / delete actions
             =========================================================== -->
        <section>
            <h3>Timetable Entries (<?php echo count($entries); ?>)</h3>

            <?php if (count($entries) === 0): ?>

                <?php if ($hasFilter): ?>
                    <p>No timetable entries match this filter. Adjust it or
                       <a href="manage_timetable.php">clear the filter</a>.</p>
                <?php else: ?>
                    <p>No timetable entries yet. Add one using the form above.</p>
                <?php endif; ?>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Day</th>
                                <th>Time</th>
                                <th>Subject</th>
                                <th>Faculty</th>
                                <th>Classroom</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $row): ?>
                                <tr>
                                    <td><?php timetableCell($row["course"]); ?></td>
                                    <td><?php timetableCell($row["year"]); ?></td>
                                    <td><?php timetableCell($row["day_of_week"]); ?></td>
                                    <td><?php echo htmlspecialchars(substr($row["start_time"], 0, 5) . " - " . substr($row["end_time"], 0, 5)); ?></td>
                                    <td><?php timetableCell($row["subject"]); ?></td>
                                    <td><?php timetableCell($row["faculty"]); ?></td>
                                    <td><?php timetableCell($row["classroom"]); ?></td>
                                    <td class="row-actions">
                                        <a class="small-btn edit-btn"
                                           href="manage_timetable.php?edit_id=<?php echo (int)$row["id"]; ?>">Edit</a>

                                        <form action="manage_timetable.php" method="POST"
                                              onsubmit="return confirm('Delete this timetable entry?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$row["id"]; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                            <button type="submit" class="small-btn delete-btn">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </section>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal</p>
    </footer>

    <script src="components.js"></script>

</body>

</html>
