<?php
/* ==========================================================================
   Student Hub Portal - Admin Dashboard
   File: admin_dashboard.php

   Shows live statistics from MySQL:
     - student accounts, admin accounts, total user accounts
     - total events
     - total event registrations
     - the latest 5 registrations (student + event details)

   Access rules (server side, never trusted from the browser):
     1. Guests      -> redirected to login.php   (requireLogin)
     2. Students    -> redirected to dashboard.php
     3. Admins only -> this page is shown

   The role is re-read from the DATABASE on every load (same style as
   manage_students.php and manage_events.php), so a stale session or a
   faked URL/form value can never unlock this page.

   Uses the existing auth.php session system and db.php connection.
   The registrations table already has a registered_at timestamp column,
   so no schema change was needed for the "registration date".
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. LOGIN CHECK - guests are sent to the login page
// ---------------------------------------------------------------------------
require_once "auth.php";
requireLogin();

// ---------------------------------------------------------------------------
// 2. ADMIN-ONLY CHECK (role verified in the database)
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
    // Logged in but NOT an admin: no statistics are printed at all
    header("Location: dashboard.php");
    exit;
}

// Keep the session role in sync with the database
$_SESSION["role"] = $roleRow["role"];

// ---------------------------------------------------------------------------
// 3. STATISTICS (live COUNT queries)
// Each query is wrapped in try/catch so a database problem shows a friendly
// message instead of PHP error details.
// ---------------------------------------------------------------------------
$totalStudents = 0;
$totalAdmins   = 0;
$totalUsers    = 0;
$totalEvents   = 0;
$totalRegs     = 0;
$statsError    = false;

// 3a. User statistics in ONE efficient query.
//     COUNT(*) counts every account; the CASE expressions count each role.
//     This way admins are NEVER counted inside the student number.
try {
    $userStmt = $conn->query(
        "SELECT COUNT(*) AS total_users,
                SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS student_count,
                SUM(CASE WHEN role = 'admin'   THEN 1 ELSE 0 END) AS admin_count
         FROM students"
    );
    $userStats       = $userStmt->fetch_assoc();
    $totalUsers      = (int)$userStats["total_users"];
    $totalStudents   = (int)$userStats["student_count"];
    $totalAdmins     = (int)$userStats["admin_count"];
} catch (mysqli_sql_exception $e) {
    $statsError = true;
}

try {
    $eventStmt = $conn->query("SELECT COUNT(*) AS total FROM events");
    $totalEvents = (int)$eventStmt->fetch_assoc()["total"];
} catch (mysqli_sql_exception $e) {
    $statsError = true;
}

try {
    $regStmt = $conn->query("SELECT COUNT(*) AS total FROM registrations");
    $totalRegs = (int)$regStmt->fetch_assoc()["total"];
} catch (mysqli_sql_exception $e) {
    $statsError = true;
}

// ---------------------------------------------------------------------------
// 4. LATEST 5 REGISTRATIONS (JOIN across the three tables)
//     registrations r  -> students s  (who registered)
//     registrations r  -> events  e   (for which event)
//     registered_at is the real timestamp column of the registrations table.
// ---------------------------------------------------------------------------
$latestRegistrations = [];

try {
    $latestStmt = $conn->prepare(
        "SELECT s.name AS student_name,
                s.student_id AS enrollment_no,
                e.title AS event_title,
                r.registered_at
         FROM registrations r
         JOIN students s ON s.id = r.student_id
         JOIN events e   ON e.id = r.event_id
         ORDER BY r.registered_at DESC, r.id DESC
         LIMIT 5"
    );
    $latestStmt->execute();
    $latestResult = $latestStmt->get_result();

    while ($row = $latestResult->fetch_assoc()) {
        $latestRegistrations[] = $row;
    }
    $latestStmt->close();
} catch (mysqli_sql_exception $e) {
    $statsError = true;
}

// Small helper: print a table cell safely
function adminCell($value, $fallback = "-")
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
    <title>Admin Dashboard - Student Hub Portal</title>
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

        <!-- Dynamic menu: these admin links exist only on admin-only pages -->
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
            <h2>Admin Dashboard</h2>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION["student_name"]); ?>!
               Here is a live overview of the portal.</p>

            <?php if ($statsError): ?>
                <div class="error-box">Some statistics could not be loaded right now. Please try again later.</div>
            <?php endif; ?>
        </section>

        <!-- ===========================================================
             STATISTICS - live COUNT values from MySQL
             =========================================================== -->
        <section>
            <h2>Statistics</h2>

            <div class="info-container">

                <div class="info-box">
                    <p><strong>Student Accounts:</strong> <?php echo $totalStudents; ?></p>
                    <p><strong>Admin Accounts:</strong> <?php echo $totalAdmins; ?></p>
                </div>

                <div class="info-box">
                    <p><strong>Total User Accounts:</strong> <?php echo $totalUsers; ?></p>
                    <p><strong>Total Events:</strong> <?php echo $totalEvents; ?></p>
                </div>

                <div class="info-box">
                    <p><strong>Event Registrations:</strong> <?php echo $totalRegs; ?></p>
                </div>

            </div>
        </section>

        <!-- ===========================================================
             LATEST 5 REGISTRATIONS - JOIN of registrations/students/events
             =========================================================== -->
        <section>
            <h2>Latest Registrations</h2>

            <?php if (count($latestRegistrations) === 0): ?>

                <p>No event registrations yet. When students register for
                   events, the latest 5 will appear here.</p>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Enrollment No.</th>
                                <th>Event</th>
                                <th>Registration Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($latestRegistrations as $reg): ?>
                                <tr>
                                    <td><?php adminCell($reg["student_name"]); ?></td>
                                    <td><?php adminCell($reg["enrollment_no"]); ?></td>
                                    <td><?php adminCell($reg["event_title"]); ?></td>
                                    <td><?php adminCell($reg["registered_at"]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </section>

        <!-- ===========================================================
             QUICK LINKS to the admin tools
             =========================================================== -->
        <section>
            <h2>Quick Links</h2>
            <p>
                <a href="manage_students.php" class="button">Manage Students</a>
                <a href="manage_events.php" class="button">Manage Events</a>
                <a href="events.html" class="button">Public Events Page</a>
            </p>
        </section>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal</p>
    </footer>

    <script src="components.js"></script>

</body>

</html>
