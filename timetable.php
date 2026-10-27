<?php
/* ==========================================================================
   Student Hub Portal - Student Timetable Page
   File: timetable.php
   STUDENT-ONLY page. It shows the timetable of the logged-in student's own
   department (course) and academic year (year).

   Security:
     - guests   -> login.php (requireLogin)
     - admins   -> admin_dashboard.php (redirected - this is a student page)
     - the course and year are read from the AUTHENTICATED DATABASE RECORD
       (students.id taken from the session) - never from the URL or any
       form value - so a student can never open another class's timetable
     - there is NO add / edit / delete code on this page at all: students
       can only view their own timetable

   Uses the existing auth.php, db.php and style.css.
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. LOGIN CHECK - guests are sent to the login page
// ---------------------------------------------------------------------------
require_once "auth.php";
requireLogin();

require_once "db.php";

// ---------------------------------------------------------------------------
// 2. READ THE LOGGED-IN ACCOUNT'S role, course and year FROM THE DATABASE
//    (one query; the id comes from the session, never from the URL)
// ---------------------------------------------------------------------------
$dbError     = false;
$studentRow  = null;

try {
    $ownStmt = $conn->prepare("SELECT role, course, year FROM students WHERE id = ?");
    $ownId   = (int)($_SESSION["student_db_id"] ?? 0);
    $ownStmt->bind_param("i", $ownId);
    $ownStmt->execute();
    $studentRow = $ownStmt->get_result()->fetch_assoc();
    $ownStmt->close();
} catch (mysqli_sql_exception $e) {
    $dbError = true;   // database problem - handled below
}

// The account no longer exists -> the session is not valid any more
if ($studentRow === null && !$dbError) {
    header("Location: login.php?status=please-login");
    exit;
}

// Admins must not be treated as students on this student-only page.
// (When the database cannot be reached we fall back to the role already
//  stored in the session, so a known admin is still blocked.)
$accountRole = $studentRow["role"] ?? ($_SESSION["role"] ?? "student");
if ($accountRole === "admin") {
    header("Location: admin_dashboard.php");
    exit;
}

// ---------------------------------------------------------------------------
// 3. LOAD THE TIMETABLE FOR THIS student's course + year
// ---------------------------------------------------------------------------
$dayOrder = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
$byDay    = [];   // day name -> list of entries (filled below)
$loadError = false;

if ($dbError) {
    $loadError = true;
} else {
    try {
        $ttStmt = $conn->prepare(
            "SELECT day_of_week, subject, start_time, end_time, faculty, classroom
             FROM timetable
             WHERE course = ? AND year = ?
             ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday',
                            'Friday', 'Saturday', 'Sunday'),
                      start_time ASC"
        );
        $ttStmt->bind_param("ss", $studentRow["course"], $studentRow["year"]);
        $ttStmt->execute();
        $ttResult = $ttStmt->get_result();

        while ($row = $ttResult->fetch_assoc()) {
            $dayName = $row["day_of_week"];
            if (!isset($byDay[$dayName])) {
                $byDay[$dayName] = [];
            }
            $byDay[$dayName][] = $row;
        }
        $ttStmt->close();
    } catch (mysqli_sql_exception $e) {
        $loadError = true;
    }
}

$conn->close();

// Total number of entries (for the "no entries" message)
$entryCount = 0;
foreach ($byDay as $dayEntries) {
    $entryCount += count($dayEntries);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable - Student Hub Portal</title>
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
            <a href="dashboard.php">Dashboard</a>
            <a href="profile.php">Profile</a>
            <a href="attendance.php">Attendance</a>
            <a href="timetable.php">Timetable</a>
            <a href="events.html">Events</a>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <main>

        <section>
            <h2>My Timetable</h2>
            <?php if ($studentRow !== null): ?>
                <p><strong><?php echo htmlspecialchars($studentRow["course"]); ?></strong>
                   &mdash; <strong><?php echo htmlspecialchars($studentRow["year"]); ?></strong></p>
            <?php endif; ?>
            <p>Your class timetable, grouped by day of the week.</p>
        </section>

        <section>

            <?php if ($loadError): ?>

                <div class="error-box">Your timetable could not be loaded right now. Please try again later.</div>

            <?php elseif ($entryCount === 0): ?>

                <!-- Clear message instead of an empty page -->
                <p>No timetable entries have been created for
                   <strong><?php echo htmlspecialchars($studentRow["course"]); ?></strong>
                   &mdash; <strong><?php echo htmlspecialchars($studentRow["year"]); ?></strong> yet.
                   Please check again later.</p>

            <?php else: ?>

                <?php foreach ($dayOrder as $dayName):
                    if (empty($byDay[$dayName])) {
                        continue;   // no classes on this day - skip it
                    } ?>

                    <h3><?php echo htmlspecialchars($dayName); ?></h3>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Subject</th>
                                    <th>Faculty</th>
                                    <th>Classroom</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byDay[$dayName] as $entry): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(
                                            substr($entry["start_time"], 0, 5) . " - " . substr($entry["end_time"], 0, 5)
                                        ); ?></td>
                                        <td><?php echo htmlspecialchars($entry["subject"]); ?></td>
                                        <td><?php echo htmlspecialchars($entry["faculty"] ?? "-"); ?></td>
                                        <td><?php echo htmlspecialchars($entry["classroom"] ?? "-"); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endforeach; ?>

            <?php endif; ?>
        </section>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal</p>
    </footer>

    <script src="components.js"></script>

</body>

</html>
