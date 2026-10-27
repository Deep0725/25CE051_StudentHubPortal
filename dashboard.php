<?php
/* Student Dashboard page - converted from dashboard.html.
   requireLogin() sends anyone who is not logged in to login.php.
   This page is STUDENT-ONLY: it shows the logged-in student their own
   information (enrollment number, course, year of study). Admin accounts
   are redirected to admin_dashboard.php so student details are never
   displayed as if they belonged to an admin. */
require_once "auth.php";
requireLogin();

/* -------------------------------------------------------------------------
   Refresh the role from the database on every dashboard load.
   process_login.php stores $_SESSION["role"] once, at LOGIN time. If the
   role column is changed afterwards (for example an account is promoted
   to 'admin' in phpMyAdmin), an already-open session keeps the OLD value
   and this page would still render for the new admin. Re-reading the role
   here keeps the dashboard in sync with the database. The comparison below
   is the exact value stored in the database: lowercase 'admin'.
   ------------------------------------------------------------------------- */
require_once "db.php";
try {
    $roleStmt = $conn->prepare("SELECT role FROM students WHERE id = ?");
    $dashboardStudentId = (int)($_SESSION["student_db_id"] ?? 0);
    $roleStmt->bind_param("i", $dashboardStudentId);
    $roleStmt->execute();
    $roleRow = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();

    if ($roleRow !== null && isset($roleRow["role"])) {
        $_SESSION["role"] = $roleRow["role"];
    }
} catch (mysqli_sql_exception $e) {
    /* Database unavailable: keep the role already stored in the session. */
}

/* -------------------------------------------------------------------------
   Admins must never see the student dashboard: it shows student-specific
   details (enrollment number, course, year of study). Admin accounts are
   sent to the admin-only dashboard instead - the same server-side pattern
   used by attendance.php and the admin pages.
   ------------------------------------------------------------------------- */
if (isset($_SESSION["role"]) && $_SESSION["role"] === "admin") {
    header("Location: admin_dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Hub Portal - Dashboard</title>
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
            <!-- htmlspecialchars() makes the name safe to print in HTML -->
            <h2>Welcome, <?php echo htmlspecialchars($_SESSION["student_name"]); ?>!</h2>
            <p>Welcome to your Student Hub dashboard.</p>
        </section>

        <section>
            <h2>Student Information</h2>

            <!-- CSS Grid info cards -->
            <div class="info-container">

                <div class="info-box">
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($_SESSION["student_name"]); ?></p>
                    <p><strong>Enrollment No.:</strong> <?php echo htmlspecialchars($_SESSION["student_id"]); ?></p>
                </div>

                <div class="info-box">
                    <p><strong>Course:</strong> <?php echo htmlspecialchars($_SESSION["course"]); ?></p>
                    <p><strong>Year of Study:</strong> <?php echo htmlspecialchars($_SESSION["year"]); ?></p>
                </div>

                <div class="info-box">
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION["student_email"]); ?></p>
                </div>

            </div>
        </section>

        <section>
            <h2>Quick Links</h2>
            <p>
                <a href="profile.php" class="button">My Profile</a>
                <a href="attendance.php" class="button">Attendance</a>
                <a href="events.html" class="button">Events</a>
                <!-- View Details opens the modal -->
                <button class="button open-modal-btn">View Details</button>
            </p>
        </section>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal</p>
    </footer>

    <!-- MODAL POPUP — shows student account info -->
    <div class="modal-overlay" id="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-box">

            <button class="modal-close-btn" id="modal-close-btn" aria-label="Close">&times;</button>

            <h2 id="modal-title">Account Details</h2>

            <p><strong>Full Name:</strong> <?php echo htmlspecialchars($_SESSION["student_name"]); ?></p>
            <p><strong>Enrollment No.:</strong> <?php echo htmlspecialchars($_SESSION["student_id"]); ?></p>
            <p><strong>Course:</strong> <?php echo htmlspecialchars($_SESSION["course"]); ?></p>
            <p><strong>Year of Study:</strong> <?php echo htmlspecialchars($_SESSION["year"]); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION["student_email"]); ?></p>
            <p><strong>Role:</strong> <?php echo htmlspecialchars($_SESSION["role"]); ?></p>

        </div>
    </div>

    <script src="components.js"></script>

</body>

</html>
