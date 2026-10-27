<?php
/* ==========================================================================
   Student Hub Portal - Attendance Management Module
   File: manage_attendance.php
   Admin-only page with three steps:
     A. Select class  (GET  -> department, academic year, date, subject)
     B. View students (SELECT of the students of that department + year)
     C. Mark + save   (POST -> INSERT ... ON DUPLICATE KEY UPDATE)
        - a record that does not exist yet is INSERTed
        - a record that already exists for that student/date/subject is
          UPDATED (the UNIQUE key in the attendance table makes duplicates
          impossible, so saving twice never creates two rows)

   Security (server side, never trusted from the browser):
     - guests      -> login.php        (requireLogin)
     - students    -> dashboard.php    (role re-checked in the DATABASE)
     - CSRF token  -> same session token + hash_equals() style as
                      manage_events.php / events_api.php
     - prepared statements everywhere; every displayed value is escaped
     - a student id coming from the form is ONLY saved when that student
       really belongs to the selected department (course) and academic
       year (year), so attendance cannot be written for other students

   The students table stores the department in the 'course' column and the
   academic year in the 'year' column - both are reused as-is (no schema
   change). Uses the existing auth.php, db.php and style.css.
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. LOGIN CHECK - guests are sent to the login page
// ---------------------------------------------------------------------------
require_once "auth.php";
requireLogin();

// ---------------------------------------------------------------------------
// 2. ADMIN-ONLY CHECK (server side)
// The role is read fresh from the DATABASE, not only from the session,
// so a student can never reach this page (or its save action) by typing
// the URL directly.
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
// 3. CSRF TOKEN (created once per session - same style as manage_events.php)
// ---------------------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
}

// ---------------------------------------------------------------------------
// 4. ALLOWED VALUES (checked on the server for EVERY request)
//    department  = the 'course' column of the students table
//    acad. year  = the 'year'   column of the students table
//    subjects    = NOT hardcoded any more: loaded from the timetable table
//                  for the selected department + academic year
// ---------------------------------------------------------------------------
$allowedCourses = [
    "Computer Engineering",
    "Information Technology",
    "Civil Engineering",
    "Mechanical Engineering",
    "Electrical Engineering"
];
$allowedYears    = ["First Year", "Second Year", "Third Year", "Fourth Year"];
$allowedStatuses = ["Present", "Absent"];

// A date must look like 2026-10-24 AND be a real calendar date
function attendanceValidDate($dateString)
{
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $dateString)) {
        return false;
    }
    $parts = explode("-", $dateString);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

// Builds the URL that returns to a given class selection
function attendanceBackUrl($course, $year, $attendDate, $subject)
{
    return "manage_attendance.php?course=" . urlencode($course)
         . "&year="   . urlencode($year)
         . "&date="   . urlencode($attendDate)
         . "&subject=". urlencode($subject);
}

// ---------------------------------------------------------------------------
// 5. SAVE ATTENDANCE (POST) - runs BEFORE any HTML is printed
// ---------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // --- 5a. CSRF check: form field must equal the session token ---
    $sentToken     = $_POST["csrf_token"] ?? "";
    $expectedToken = (string)($_SESSION["csrf_token"] ?? "");

    if ($expectedToken === "" || !is_string($sentToken) || $sentToken === ""
        || !hash_equals($expectedToken, $sentToken)) {
        header("Location: manage_attendance.php?status=error&msg="
            . urlencode("Security check failed. Please reload the page and try again."));
        exit;
    }

    // --- 5b. The selected class (hidden fields of the marking form) ---
    $course     = trim($_POST["course"] ?? "");
    $year       = trim($_POST["year"] ?? "");
    $attendDate = trim($_POST["date"] ?? "");
    $subject    = trim($_POST["subject"] ?? "");

    $errors = [];

    if (!in_array($course, $allowedCourses, true)) {
        $errors[] = "Invalid department.";
    }
    if (!in_array($year, $allowedYears, true)) {
        $errors[] = "Invalid academic year.";
    }
    if (!attendanceValidDate($attendDate)) {
        $errors[] = "Please choose a valid date.";
    } elseif ($attendDate > date("Y-m-d")) {
        $errors[] = "Attendance cannot be recorded for a future date.";
    }
    // --- The subject must really exist in the TIMETABLE for this class ---
    //      Checked against the database (never only against the dropdown),
    //      so newly added timetable subjects are accepted and subjects of
    //      other departments / years are rejected server-side.
    if (in_array($course, $allowedCourses, true) && in_array($year, $allowedYears, true)) {
        $classSubjects = [];

        try {
            $subjStmt = $conn->prepare(
                "SELECT DISTINCT subject FROM timetable WHERE course = ? AND year = ?"
            );
            $subjStmt->bind_param("ss", $course, $year);
            $subjStmt->execute();
            $subjResult = $subjStmt->get_result();

            while ($subjRow = $subjResult->fetch_assoc()) {
                $classSubjects[] = $subjRow["subject"];
            }
            $subjStmt->close();
        } catch (mysqli_sql_exception $e) {
            $classSubjects = [];
        }

        if (!in_array($subject, $classSubjects, true)) {
            $errors[] = "Invalid subject for the selected department and academic year.";
        }
    }

    // --- 5c. The marks: status[student_id] = "Present" | "Absent" ---
    $postedStatuses = $_POST["status"] ?? [];

    if (!is_array($postedStatuses) || count($postedStatuses) === 0) {
        $errors[] = "No attendance marks were submitted.";
    }

    $requestedMarks = [];
    if (empty($errors)) {
        foreach ($postedStatuses as $postedId => $postedStatus) {
            $postedId = (int)$postedId;
            if ($postedId <= 0 || !is_string($postedStatus)
                || !in_array($postedStatus, $allowedStatuses, true)) {
                $errors[] = "Invalid attendance data was submitted.";
                break;
            }
            $requestedMarks[$postedId] = $postedStatus;
        }
    }

    if (!empty($errors)) {
        header("Location: manage_attendance.php?status=error&msg="
            . urlencode(implode(" | ", $errors)));
        exit;
    }

    // --- 5d. Only students of the SELECTED department + year may be saved ---
    //      A forged form can never mark a student of another class, and
    //      admin accounts (role <> 'student') are never marked at all.
    try {
        $allowedStmt = $conn->prepare(
            "SELECT id FROM students WHERE course = ? AND year = ? AND role = 'student'"
        );
        $allowedStmt->bind_param("ss", $course, $year);
        $allowedStmt->execute();
        $allowedResult = $allowedStmt->get_result();

        $allowedIds = [];
        while ($allowedRow = $allowedResult->fetch_assoc()) {
            $allowedIds[] = (int)$allowedRow["id"];
        }
        $allowedStmt->close();
    } catch (mysqli_sql_exception $e) {
        header("Location: manage_attendance.php?status=error&msg="
            . urlencode("Attendance could not be saved. Please try again."));
        exit;
    }

    $marksToSave = [];
    foreach ($requestedMarks as $studentId => $markStatus) {
        if (in_array($studentId, $allowedIds, true)) {
            $marksToSave[$studentId] = $markStatus;
        }
    }

    if (count($marksToSave) === 0) {
        header("Location: manage_attendance.php?status=error&msg="
            . urlencode("No students were found for the selected department and academic year."));
        exit;
    }

    // --- 5e. Save every mark ---
    //      INSERT ... ON DUPLICATE KEY UPDATE = insert when the record is
    //      new, update the status when one already exists for this
    //      student + date + subject. This is what "editing" attendance is:
    //      simply re-saving the same sheet.
    try {
        $saveStmt = $conn->prepare(
            "INSERT INTO attendance (student_id, attend_date, subject, status)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)"
        );

        foreach ($marksToSave as $studentId => $markStatus) {
            $saveStmt->bind_param("isss", $studentId, $attendDate, $subject, $markStatus);
            $saveStmt->execute();
        }
        $saveStmt->close();
    } catch (mysqli_sql_exception $e) {
        header("Location: manage_attendance.php?status=error&msg="
            . urlencode("Attendance could not be saved. Please try again."));
        exit;
    }

    // --- 5f. Post/Redirect/Get: return to the same selection ---
    $savedCount = count($marksToSave);
    header("Location: " . attendanceBackUrl($course, $year, $attendDate, $subject)
        . "&status=success&msg=" . urlencode(
            "Attendance saved for " . $savedCount . " student(s): "
            . $subject . " on " . $attendDate . "."
        ));
    exit;
}

// ---------------------------------------------------------------------------
// 6. CURRENT SELECTION FROM THE URL (GET) - feeds the "select class" form
// ---------------------------------------------------------------------------
$course     = trim($_GET["course"] ?? "");
$year       = trim($_GET["year"] ?? "");
$attendDate = trim($_GET["date"] ?? "");
$subject    = trim($_GET["subject"] ?? "");

// Subjects that really exist in the TIMETABLE for the selected class.
// The dropdown is filled from this list (prepared statement, no hardcode).
$subjectOptions = [];

if (in_array($course, $allowedCourses, true) && in_array($year, $allowedYears, true)) {
    try {
        $optStmt = $conn->prepare(
            "SELECT DISTINCT subject FROM timetable WHERE course = ? AND year = ?
             ORDER BY subject ASC"
        );
        $optStmt->bind_param("ss", $course, $year);
        $optStmt->execute();
        $optResult = $optStmt->get_result();

        while ($optRow = $optResult->fetch_assoc()) {
            $subjectOptions[] = $optRow["subject"];
        }
        $optStmt->close();
    } catch (mysqli_sql_exception $e) {
        $subjectOptions = [];
    }
}

$allSelected    = ($course !== "" && $year !== "" && $attendDate !== "" && $subject !== "");
$anySelected    = ($course !== "" || $year !== "" || $attendDate !== "" || $subject !== "");
$selectionValid = false;
$selectionError = "";

if ($allSelected) {
    $badParts = [];
    if (!in_array($course, $allowedCourses, true)) {
        $badParts[] = "department";
    }
    if (!in_array($year, $allowedYears, true)) {
        $badParts[] = "academic year";
    }
    if (!attendanceValidDate($attendDate)) {
        $badParts[] = "date";
    } elseif ($attendDate > date("Y-m-d")) {
        $badParts[] = "date (future dates are not allowed)";
    }
    if (!in_array($subject, $subjectOptions, true)) {
        $badParts[] = "subject";
    }

    if (count($badParts) > 0) {
        $selectionError = "Please choose a valid " . implode(", ", $badParts) . ".";
    } else {
        $selectionValid = true;
    }
}

// ---------------------------------------------------------------------------
// 7. LOAD THE STUDENTS + ANY EXISTING RECORDS FOR THIS SELECTION
// ---------------------------------------------------------------------------
$students       = [];
$existingStatus = [];   // student id (int) -> "Present" / "Absent"
$existingCount  = 0;
$loadError      = false;

if ($selectionValid) {
    try {
        // Students of this department + year only. Admin accounts are
        // excluded: attendance is a student record, and admins are not
        // treated as students.
        $listStmt = $conn->prepare(
            "SELECT id, student_id, name
             FROM students
             WHERE course = ? AND year = ? AND role = 'student'
             ORDER BY name ASC"
        );
        $listStmt->bind_param("ss", $course, $year);
        $listStmt->execute();
        $listResult = $listStmt->get_result();

        while ($row = $listResult->fetch_assoc()) {
            $students[] = $row;
        }
        $listStmt->close();

        // Marks already saved for this date + subject (used to pre-fill
        // the radio buttons so the admin can see and change them)
        $recStmt = $conn->prepare(
            "SELECT student_id, status FROM attendance WHERE attend_date = ? AND subject = ?"
        );
        $recStmt->bind_param("ss", $attendDate, $subject);
        $recStmt->execute();
        $recResult = $recStmt->get_result();

        while ($row = $recResult->fetch_assoc()) {
            $existingStatus[(int)$row["student_id"]] = $row["status"];
        }
        $recStmt->close();

        // How many of the DISPLAYED students already have a record
        foreach ($students as $oneStudent) {
            if (isset($existingStatus[(int)$oneStudent["id"]])) {
                $existingCount++;
            }
        }
    } catch (mysqli_sql_exception $e) {
        $students       = [];
        $existingStatus = [];
        $existingCount  = 0;
        $loadError      = true;
    }
}

// ---------------------------------------------------------------------------
// 8. FLASH MESSAGE FROM THE REDIRECT (Post/Redirect/Get)
// ---------------------------------------------------------------------------
$pageStatus  = $_GET["status"] ?? "";
$pageMessage = $_GET["msg"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - Student Hub Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Small page-specific extras - style.css itself is untouched */
        .select-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .select-form .form-group {
            flex: 1;
            min-width: 160px;
            margin-bottom: 0;
        }
        .select-actions {
            display: flex;
            gap: 8px;
        }
        .select-actions .form-button {
            width: auto;
            padding: 10px 16px;
        }
        .mark-cell {
            white-space: nowrap;
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
            <h2>Attendance Management</h2>
            <p>Select a department, academic year, date and subject, then mark each student Present or Absent.</p>

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
             A. SELECT CLASS (GET form - reloading keeps the selection)
             =========================================================== -->
        <section>
            <h3>Select Class</h3>

            <form action="manage_attendance.php" method="GET" class="select-form">

                <div class="form-group">
                    <label for="sel-course">Department:</label>
                    <select id="sel-course" name="course">
                        <option value="">-- Select Department --</option>
                        <?php foreach ($allowedCourses as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php if ($course === $option) echo "selected"; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sel-year">Academic Year:</label>
                    <select id="sel-year" name="year">
                        <option value="">-- Select Year --</option>
                        <?php foreach ($allowedYears as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php if ($year === $option) echo "selected"; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sel-date">Date:</label>
                    <input type="date" id="sel-date" name="date"
                           max="<?php echo date("Y-m-d"); ?>"
                           value="<?php echo htmlspecialchars($attendDate); ?>">
                </div>

                <div class="form-group">
                    <label for="sel-subject">Subject:</label>
                    <select id="sel-subject" name="subject"
                        <?php if (count($subjectOptions) === 0) echo "disabled"; ?>>
                        <option value="">-- Select Subject --</option>
                        <?php foreach ($subjectOptions as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php if ($subject === $option) echo "selected"; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($course !== "" && $year !== "" && count($subjectOptions) === 0): ?>
                        <p>No subjects found for this department and year. Add a subject in Manage Timetable first.</p>
                    <?php endif; ?>
                </div>

                <div class="select-actions">
                    <input type="submit" value="Show Students" class="form-button">
                </div>

            </form>
        </section>

        <!-- ===========================================================
             B + C. STUDENT LIST AND MARKING FORM
             =========================================================== -->
        <section>
            <h3>Mark Attendance</h3>

            <?php if ($loadError): ?>

                <div class="error-box">The student list could not be loaded right now. Please try again later.</div>

            <?php elseif ($selectionValid): ?>

                <?php if (count($students) === 0): ?>

                    <p>No students found for this department and academic year.</p>

                <?php else: ?>

                    <form action="manage_attendance.php" method="POST">

                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                        <input type="hidden" name="course" value="<?php echo htmlspecialchars($course); ?>">
                        <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($attendDate); ?>">
                        <input type="hidden" name="subject" value="<?php echo htmlspecialchars($subject); ?>">

                        <div class="info-container">

                            <div class="info-box">
                                <p><strong>Department:</strong> <?php echo htmlspecialchars($course); ?></p>
                                <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($year); ?></p>
                            </div>

                            <div class="info-box">
                                <p><strong>Date:</strong> <?php echo htmlspecialchars($attendDate); ?></p>
                                <p><strong>Subject:</strong> <?php echo htmlspecialchars($subject); ?></p>
                            </div>

                            <div class="info-box">
                                <p><strong>Students:</strong> <?php echo count($students); ?></p>
                                <?php if ($existingCount > 0): ?>
                                    <p><?php echo $existingCount; ?> existing record(s) will be <strong>updated</strong> when you save.</p>
                                <?php endif; ?>
                            </div>

                        </div>

                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Mark</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $oneStudent):
                                        $oneId      = (int)$oneStudent["id"];
                                        $oneMark    = $existingStatus[$oneId] ?? "Present"; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($oneStudent["student_id"]); ?></td>
                                            <td><?php echo htmlspecialchars($oneStudent["name"]); ?></td>
                                            <td class="mark-cell">
                                                <div class="radio-group">
                                                    <label>
                                                        <input type="radio" name="status[<?php echo $oneId; ?>]" value="Present"
                                                            <?php if ($oneMark === "Present") echo "checked"; ?>>
                                                        Present
                                                    </label>
                                                    <label>
                                                        <input type="radio" name="status[<?php echo $oneId; ?>]" value="Absent"
                                                            <?php if ($oneMark === "Absent") echo "checked"; ?>>
                                                        Absent
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <input type="submit" value="Save Attendance" class="form-button">

                    </form>

                <?php endif; ?>

            <?php elseif ($selectionError !== ""): ?>

                <div class="error-box"><?php echo htmlspecialchars($selectionError); ?></div>

            <?php elseif ($anySelected): ?>

                <p>Please complete all four selections (department, academic year, date and subject),
                   then click <strong>Show Students</strong>.</p>

            <?php else: ?>

                <p>Select a department, academic year, date and subject above, then click
                   <strong>Show Students</strong> to mark attendance.</p>

            <?php endif; ?>
        </section>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal</p>
    </footer>

    <script src="components.js"></script>

</body>

</html>
