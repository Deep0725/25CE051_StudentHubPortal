<?php
/* Profile page - converted from profile.html so the session check can run. */
require_once "auth.php";
requireLogin();

/* -------------------------------------------------------------------------
   Fetch this student's CURRENT details from MySQL using the logged-in
   session's database id - so we always show our own, up-to-date record.
   ------------------------------------------------------------------------- */
require_once "db.php";

$student = null;

try {
    // Prepared statement: the id comes from the session (never from the URL)
    // role is included so admins are never shown student-only details below
    $stmt = $conn->prepare(
        "SELECT date_of_birth, mobile, gender, role FROM students WHERE id = ?"
    );
    $stmt->bind_param("i", $_SESSION["student_db_id"]);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    // If the lookup fails we simply show "Not provided" values below
    $student = null;
}

/* -------------------------------------------------------------------------
   Load this student's REAL attendance records from the attendance table,
   using the same authenticated database id taken from the session.
   ------------------------------------------------------------------------- */
$attendanceRecords  = [];
$attendanceLoadError = false;

try {
    // Prepared statement: the id comes from the session (never from the URL)
    $attStmt = $conn->prepare(
        "SELECT subject, status FROM attendance WHERE student_id = ?
         ORDER BY subject ASC, attend_date ASC"
    );
    $attStmt->bind_param("i", $_SESSION["student_db_id"]);
    $attStmt->execute();
    $attResult = $attStmt->get_result();

    while ($attRow = $attResult->fetch_assoc()) {
        $attendanceRecords[] = $attRow;
    }
    $attStmt->close();
} catch (mysqli_sql_exception $e) {
    // Shown as a friendly message in the attendance section below
    $attendanceLoadError = true;
}

$conn->close();

/* -------------------------------------------------------------------------
   Decide which profile to render. The role comes from the database row
   loaded above; if that lookup failed we fall back to the role already
   stored in the session (same pattern as dashboard.php).
   Admin accounts must NOT be shown as student accounts: no fabricated
   enrollment number, course or year of study appears for them.
   ------------------------------------------------------------------------- */
$profileRole    = $student["role"] ?? ($_SESSION["role"] ?? "student");
$isAdminProfile = ($profileRole === "admin");

// Keep the session role in sync with the database
if ($student !== null && isset($student["role"])) {
    $_SESSION["role"] = $student["role"];
}

/* -------------------------------------------------------------------------
   Format the stored DOB ("YYYY-MM-DD") as "05 January 2006".
   Everything is done with plain string parts - the date is NEVER passed
   through DateTime/timezone conversion, so the day can never shift.
   Returns "Not provided" for students who registered before the DOB field
   existed (their column value is NULL).
   ------------------------------------------------------------------------- */
function formatDob($dateString)
{
    if ($dateString === null || $dateString === "") {
        return "Not provided";
    }

    $parts = explode("-", $dateString);
    if (count($parts) !== 3) {
        return "Not provided";
    }

    $monthNames = [
        1 => "January", "February", "March", "April",
        "May", "June", "July", "August",
        "September", "October", "November", "December"
    ];

    $day   = $parts[2];                       // keep "05" as two digits
    $month = (int)$parts[1];
    $year  = $parts[0];

    if (!isset($monthNames[$month])) {
        return "Not provided";
    }

    return $day . " " . $monthNames[$month] . " " . $year;
}

// Values used in the page below (escaped just before printing)
$dobText   = formatDob($student["date_of_birth"] ?? null);
$mobileVal = trim($student["mobile"] ?? "");
$phoneText = $mobileVal !== "" ? $mobileVal : "Not provided";
$genderVal = trim($student["gender"] ?? "");
$genderText = $genderVal !== "" ? $genderVal : "Not provided";

/* -------------------------------------------------------------------------
   Real attendance totals: one overall percentage plus one percentage per
   subject, calculated ONLY from the Present / Absent rows in the database.
   Missing records are never counted as Present and no data is invented.
   ------------------------------------------------------------------------- */
$attendanceOverall  = ["present" => 0, "total" => 0];
$attendanceBySubject = [];

foreach ($attendanceRecords as $oneRecord) {
    $subjectName = $oneRecord["subject"];

    if (!isset($attendanceBySubject[$subjectName])) {
        $attendanceBySubject[$subjectName] = ["present" => 0, "total" => 0];
    }

    $attendanceBySubject[$subjectName]["total"]++;
    $attendanceOverall["total"]++;

    if ($oneRecord["status"] === "Present") {
        $attendanceBySubject[$subjectName]["present"]++;
        $attendanceOverall["present"]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
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
            <?php if (!$isAdminProfile): ?>
                <a href="attendance.php">Attendance</a>
                <a href="timetable.php">Timetable</a>
            <?php endif; ?>
            <a href="events.html">Events</a>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <main>

        <section>
            <h2>My Profile</h2>
            <?php if ($isAdminProfile): ?>
                <p>View your account information.</p>
            <?php else: ?>
                <p>View your personal and academic information.</p>
            <?php endif; ?>
        </section>

        <?php if ($isAdminProfile): ?>

            <!-- ===========================================================
                 ADMIN PROFILE - account information only.
                 Admins are NOT students: no enrollment number, course or
                 year of study is ever shown for an admin account.
                 =========================================================== -->
            <section>
                <h3>Account Information</h3>

                <p><strong>Full Name:</strong> <?php echo htmlspecialchars($_SESSION["student_name"]); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION["student_email"]); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($phoneText); ?></p>
                <p><strong>Role:</strong> Admin</p>
            </section>

        <?php else: ?>

            <section>
                <h3>Personal Information</h3>

                <p><strong>Full Name:</strong> <?php echo htmlspecialchars($_SESSION["student_name"]); ?></p>
                <p><strong>Date of Birth:</strong> <?php echo htmlspecialchars($dobText); ?></p>
                <p><strong>Gender:</strong> <?php echo htmlspecialchars($genderText); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION["student_email"]); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($phoneText); ?></p>
            </section>

            <section>
                <h3>Academic Information</h3>

                <p><strong>Enrollment No.:</strong> <?php echo htmlspecialchars($_SESSION["student_id"]); ?></p>
                <p><strong>Course:</strong> <?php echo htmlspecialchars($_SESSION["course"]); ?></p>
                <p><strong>Year of Study:</strong> <?php echo htmlspecialchars($_SESSION["year"]); ?></p>
                <p><strong>Academic Year:</strong> 2026-27</p>
            </section>

            <section>
                <h3>Attendance Information</h3>

                <?php if ($attendanceLoadError): ?>

                    <div class="error-box">Your attendance records could not be loaded right now.</div>

                <?php elseif ($attendanceOverall["total"] === 0): ?>

                    <p>No attendance records available yet.</p>

                <?php else: ?>

                    <div class="info-container">

                        <div class="info-box">
                            <h3>Overall</h3>
                            <p>Present: <?php echo (int)$attendanceOverall["present"]; ?></p>
                            <p>Total: <?php echo (int)$attendanceOverall["total"]; ?></p>
                            <p>Attendance: <?php echo (int)round($attendanceOverall["present"] * 100 / $attendanceOverall["total"]); ?>%</p>
                        </div>

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

        <?php endif; ?>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal</p>
    </footer>

    <script src="components.js"></script>

</body>

</html>
