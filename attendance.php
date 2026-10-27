<?php
/* Attendance page - converted from attendance.html so the session check can run.
   This page is STUDENT-ONLY: it shows the logged-in student their own records.
   The role is re-read from the DATABASE on every load (same style as
   manage_students.php / admin_dashboard.php), so hiding a link is never the
   only protection. Admin accounts are redirected to admin_dashboard.php -
   admins must not be treated as students here. A future admin-only attendance
   management page will be a SEPARATE page, not this one. */
require_once "auth.php";
requireLogin();

require_once "db.php";

try {
    $roleStmt = $conn->prepare("SELECT role FROM students WHERE id = ?");
    $attendanceCheckId = (int)($_SESSION["student_db_id"] ?? 0);
    $roleStmt->bind_param("i", $attendanceCheckId);
    $roleStmt->execute();
    $roleRow = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();
} catch (mysqli_sql_exception $e) {
    $roleRow = null;
}

/* Load the logged-in student's REAL attendance records.
   The student id comes from the session, so a student can only ever
   fetch their own rows - never another student's. */
$attendanceRecords   = [];
$attendanceLoadError = false;

try {
    $attStmt = $conn->prepare(
        "SELECT subject, status FROM attendance WHERE student_id = ?
         ORDER BY subject ASC, attend_date ASC"
    );
    $attStudentId = (int)($_SESSION["student_db_id"] ?? 0);
    $attStmt->bind_param("i", $attStudentId);
    $attStmt->execute();
    $attResult = $attStmt->get_result();

    while ($attRow = $attResult->fetch_assoc()) {
        $attendanceRecords[] = $attRow;
    }
    $attStmt->close();
} catch (mysqli_sql_exception $e) {
    $attendanceLoadError = true;
}

$conn->close();

/* Keep the session role in sync with the database when it is available */
if ($roleRow !== null && isset($roleRow["role"])) {
    $_SESSION["role"] = $roleRow["role"];
}

/* Admins are NOT students: send them to the admin dashboard.
   (If the database cannot be reached, fall back to the role stored in the
   session so a known admin is still blocked from the student view.) */
if (($_SESSION["role"] ?? "") === "admin") {
    header("Location: admin_dashboard.php");
    exit;
}

/* Percentage per subject, calculated ONLY from the Present / Absent rows
   that really exist in the database. Missing records are never counted
   as Present and no sample data is invented. */
$attendanceBySubject = [];

foreach ($attendanceRecords as $oneRecord) {
    $subjectName = $oneRecord["subject"];

    if (!isset($attendanceBySubject[$subjectName])) {
        $attendanceBySubject[$subjectName] = ["present" => 0, "total" => 0];
    }

    $attendanceBySubject[$subjectName]["total"]++;

    if ($oneRecord["status"] === "Present") {
        $attendanceBySubject[$subjectName]["present"]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance</title>
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
            <h2>My Attendance</h2>

            <?php if ($attendanceLoadError): ?>

                <div class="error-box">Your attendance records could not be loaded right now.</div>

            <?php elseif (count($attendanceBySubject) === 0): ?>

                <p>No attendance records available yet.</p>

            <?php else: ?>

                <!-- CSS Grid info cards (real records from the database) -->
                <div class="info-container">

                    <?php foreach ($attendanceBySubject as $subjectName => $counts): ?>
                        <div class="info-box">
                            <h3><?php echo htmlspecialchars($subjectName); ?></h3>
                            <p>Present: <?php echo (int)$counts["present"]; ?></p>
                            <p>Total: <?php echo (int)$counts["total"]; ?></p>
                            <p>Attendance: <?php echo (int)round($counts["present"] * 100 / $counts["total"]); ?>%</p>
                        </div>
                    <?php endforeach; ?>

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
