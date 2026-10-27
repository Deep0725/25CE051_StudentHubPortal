<?php
/* ==========================================================================
   Student Hub Portal - Student Management Module
   File: manage_students.php
   Admin-only page with five features:
     A. Add student        (POST -> prepared INSERT)    
     B. View students      (SELECT table, no password hashes shown)
     C. Edit student       (POST -> prepared UPDATE)
     D. Delete student     (POST -> prepared DELETE, self-delete blocked)
     E. Search + F. Filter (GET  -> prepared SELECT with dynamic WHERE)

   Uses the existing auth.php session system and db.php connection.
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. LOGIN CHECK - guests are sent to the login page
// ---------------------------------------------------------------------------
require_once "auth.php";
requireLogin();

// ---------------------------------------------------------------------------
// 2. ADMIN-ONLY CHECK (server side)
// The role is read fresh from the DATABASE, not only from the session,
// so a role change takes effect immediately and cannot be faked by the
// browser. Ordinary student accounts are redirected to their dashboard.
// ---------------------------------------------------------------------------
require_once "db.php";

try {
    $roleStmt = $conn->prepare("SELECT role FROM students WHERE id = ?");
    $roleStmt->bind_param("i", $_SESSION["student_db_id"]);
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
// 3. VALIDATION LISTS (same allowed values as the registration form)
// ---------------------------------------------------------------------------
$allowedCourses = [
    "Computer Engineering",
    "Information Technology",
    "Civil Engineering",
    "Mechanical Engineering",
    "Electrical Engineering"
];
$allowedYears   = ["First Year", "Second Year", "Third Year", "Fourth Year"];
$allowedGenders = ["Male", "Female", "Other"];

// Shared field validation used by BOTH add and edit.
// Returns an array of error messages (empty array = everything valid).
function validateStudentFields($name, $email, $mobile, $dob, $gender, $course, $year)
{
    global $allowedCourses, $allowedYears, $allowedGenders;
    $errors = [];

    // Name: letters and spaces only, minimum 2 characters
    if ($name === "") {
        $errors[] = "Name is required.";
    } elseif (!preg_match("/^[A-Za-z ]{2,}$/", $name)) {
        $errors[] = "Name must contain only letters and spaces (at least 2 characters).";
    }

    // Email: valid format (duplicate check happens separately)
    if ($email === "") {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Mobile: 10 digits starting with 6-9 (kept as a string)
    if ($mobile === "") {
        $errors[] = "Mobile number is required.";
    } elseif (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) {
        $errors[] = "Mobile number must be exactly 10 digits starting with 6, 7, 8, or 9.";
    }

    // Date of birth: optional for admin entry, but if given it must be
    // a real, non-future date (stored as NULL when left blank)
    if ($dob !== "") {
        if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $dob)) {
            $errors[] = "Please enter a valid date of birth.";
        } else {
            $parts = explode("-", $dob);
            if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                $errors[] = "Please enter a valid date of birth.";
            } elseif ($dob > date("Y-m-d")) {
                $errors[] = "Date of birth cannot be in the future.";
            }
        }
    }

    // Dropdowns / radios: must be one of the allowed values
    if ($gender === "") {
        $errors[] = "Please select a gender.";
    } elseif (!in_array($gender, $allowedGenders, true)) {
        $errors[] = "Invalid gender value.";
    }

    if ($course === "") {
        $errors[] = "Please select a course.";
    } elseif (!in_array($course, $allowedCourses, true)) {
        $errors[] = "Invalid course selected.";
    }

    if ($year === "") {
        $errors[] = "Please select a year of study.";
    } elseif (!in_array($year, $allowedYears, true)) {
        $errors[] = "Invalid year of study selected.";
    }

    return $errors;
}

// ---------------------------------------------------------------------------
// 4. HANDLE POST ACTIONS (before any HTML is printed)
// Add / Edit / Delete all use POST, never GET.
// ---------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    // -------------------------------------------------------------------
    // A. ADD STUDENT
    // -------------------------------------------------------------------
    if ($action === "add") {

        $name     = trim($_POST["name"] ?? "");
        $email    = trim($_POST["email"] ?? "");
        $mobile   = trim($_POST["mobile"] ?? "");
        $dob      = trim($_POST["date_of_birth"] ?? "");
        $gender   = $_POST["gender"] ?? "";
        $course   = $_POST["course"] ?? "";
        $year     = $_POST["year"] ?? "";
        $password = $_POST["password"] ?? "";

        // Server-side validation of every field
        $errors = validateStudentFields($name, $email, $mobile, $dob, $gender, $course, $year);

        if ($password === "") {
            $errors[] = "Password is required.";
        } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{8,}$/", $password)) {
            $errors[] = "Password must be at least 8 characters with an uppercase letter, lowercase letter, number, and special character.";
        }

        if (!empty($errors)) {
            header("Location: manage_students.php?status=error&msg=" . urlencode(implode(" | ", $errors)));
            exit;
        }

        try {
            // Duplicate email check (prepared statement)
            $dupStmt = $conn->prepare("SELECT COUNT(*) AS total FROM students WHERE email = ?");
            $dupStmt->bind_param("s", $email);
            $dupStmt->execute();
            $dupRow = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();

            if ($dupRow["total"] > 0) {
                header("Location: manage_students.php?status=error&msg=" . urlencode("That email address is already used by another student."));
                exit;
            }

            // Auto-generate the next student_id (STU0001, STU0002, ...)
            $maxStmt = $conn->query("SELECT MAX(id) AS max_id FROM students");
            $maxRow  = $maxStmt->fetch_assoc();
            $nextNumber = (int)$maxRow["max_id"] + 1;

            do {
                $studentId = "STU" . str_pad($nextNumber, 4, "0", STR_PAD_LEFT);
                $nextNumber++;

                $idStmt = $conn->prepare("SELECT COUNT(*) AS total FROM students WHERE student_id = ?");
                $idStmt->bind_param("s", $studentId);
                $idStmt->execute();
                $idRow = $idStmt->get_result()->fetch_assoc();
                $idStmt->close();
            } while ($idRow["total"] > 0);

            // Store only a secure hash - the plain password is never saved.
            // The role column is NOT part of this INSERT, so the database
            // default 'student' always applies.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $dobValue = ($dob === "") ? null : $dob;

            $insStmt = $conn->prepare(
                "INSERT INTO students (student_id, name, email, mobile, date_of_birth, password, course, year, gender)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insStmt->bind_param(
                "sssssssss",
                $studentId,
                $name,
                $email,
                $mobile,
                $dobValue,
                $passwordHash,
                $course,
                $year,
                $gender
            );
            $insStmt->execute();
            $insStmt->close();

        } catch (mysqli_sql_exception $e) {
            // 1062 = duplicate email raced in at the same second (UNIQUE key).
            // Anything else: a safe generic message - never SQL details.
            if ($e->getCode() == 1062) {
                header("Location: manage_students.php?status=error&msg=" . urlencode("That email address is already used by another student."));
            } else {
                header("Location: manage_students.php?status=error&msg=" . urlencode("The student could not be added. Please check the details and try again."));
            }
            exit;
        }

        header("Location: manage_students.php?status=success&msg=" . urlencode("Student added successfully. Student ID: " . $studentId));
        exit;
    }

    // -------------------------------------------------------------------
    // C. EDIT STUDENT  (password, role, student_id and id are NOT editable)
    // -------------------------------------------------------------------
    if ($action === "edit") {

        $id       = (int)($_POST["id"] ?? 0);
        $name     = trim($_POST["name"] ?? "");
        $email    = trim($_POST["email"] ?? "");
        $mobile   = trim($_POST["mobile"] ?? "");
        $dob      = trim($_POST["date_of_birth"] ?? "");
        $gender   = $_POST["gender"] ?? "";
        $course   = $_POST["course"] ?? "";
        $year     = $_POST["year"] ?? "";

        if ($id <= 0) {
            header("Location: manage_students.php?status=error&msg=" . urlencode("Student not found."));
            exit;
        }

        $errors = validateStudentFields($name, $email, $mobile, $dob, $gender, $course, $year);

        if (!empty($errors)) {
            header("Location: manage_students.php?status=error&msg=" . urlencode(implode(" | ", $errors)));
            exit;
        }

        try {
            // Duplicate email check - exclude the student being edited
            $dupStmt = $conn->prepare("SELECT COUNT(*) AS total FROM students WHERE email = ? AND id <> ?");
            $dupStmt->bind_param("si", $email, $id);
            $dupStmt->execute();
            $dupRow = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();

            if ($dupRow["total"] > 0) {
                header("Location: manage_students.php?status=error&msg=" . urlencode("That email address is already used by another student."));
                exit;
            }

            // Only safe columns are updated. The password hash, role,
            // student_id and internal id are deliberately NOT in this query.
            $updStmt = $conn->prepare(
                "UPDATE students
                 SET name = ?, email = ?, mobile = ?, date_of_birth = ?,
                     gender = ?, course = ?, year = ?
                 WHERE id = ?"
            );
            $dobValue = ($dob === "") ? null : $dob;
            $updStmt->bind_param(
                "sssssssi",
                $name,
                $email,
                $mobile,
                $dobValue,
                $gender,
                $course,
                $year,
                $id
            );
            $updStmt->execute();
            $found = $updStmt->affected_rows >= 0; // 0 = values unchanged, still success
            $updStmt->close();

            if (!$found) {
                header("Location: manage_students.php?status=error&msg=" . urlencode("Student not found."));
                exit;
            }

        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) {
                header("Location: manage_students.php?status=error&msg=" . urlencode("That email address is already used by another student."));
            } else {
                header("Location: manage_students.php?status=error&msg=" . urlencode("The changes could not be saved. Please try again."));
            }
            exit;
        }

        header("Location: manage_students.php?status=success&msg=" . urlencode("Student updated successfully."));
        exit;
    }

    // -------------------------------------------------------------------
    // D. DELETE STUDENT (POST only - never a GET link)
    // -------------------------------------------------------------------
    if ($action === "delete") {

        $id = (int)($_POST["id"] ?? 0);

        // Protect the currently logged-in account from self-deletion
        if ($id <= 0 || $id === (int)$_SESSION["student_db_id"]) {
            header("Location: manage_students.php?status=error&msg=" . urlencode("You cannot delete your own account."));
            exit;
        }

        try {
            // Foreign keys stay ENABLED. The schema uses
            // ON DELETE CASCADE for registrations.student_id, so the
            // student's event registrations are removed automatically
            // with them - no FK checks are ever switched off.
            $delStmt = $conn->prepare("DELETE FROM students WHERE id = ?");
            $delStmt->bind_param("i", $id);
            $delStmt->execute();
            $deleted = $delStmt->affected_rows > 0;
            $delStmt->close();
        } catch (mysqli_sql_exception $e) {
            // A related record that blocks deletion would land here.
            // We show a safe generic message and change nothing.
            header("Location: manage_students.php?status=error&msg=" . urlencode("This student could not be deleted because related records are linked to the account."));
            exit;
        }

        if ($deleted) {
            header("Location: manage_students.php?status=success&msg=" . urlencode("Student deleted successfully."));
        } else {
            header("Location: manage_students.php?status=error&msg=" . urlencode("Student not found."));
        }
        exit;
    }

    // Unknown action: go back to the list
    header("Location: manage_students.php");
    exit;
}

// ---------------------------------------------------------------------------
// 5. LOAD ONE STUDENT FOR EDITING (?edit_id=N) - view only, saving is POST
// ---------------------------------------------------------------------------
$editStudent = null;

if (isset($_GET["edit_id"]) && (int)$_GET["edit_id"] > 0) {
    $editId = (int)$_GET["edit_id"];

    try {
        $editStmt = $conn->prepare(
            "SELECT id, student_id, name, email, mobile, date_of_birth, gender, course, year
             FROM students WHERE id = ?"
        );
        $editStmt->bind_param("i", $editId);
        $editStmt->execute();
        $editStudent = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
    } catch (mysqli_sql_exception $e) {
        $editStudent = null;
    }

    if ($editStudent === null) {
        // fall through - the message banner below shows the error
        $_GET["status"] = "error";
        $_GET["msg"]    = "Student not found.";
    }
}

// ---------------------------------------------------------------------------
// 6. SEARCH + FILTER VALUES (GET form)
// ---------------------------------------------------------------------------
$searchQuery = trim($_GET["q"] ?? "");
$filterCourse = trim($_GET["course"] ?? "");
$filterYear   = trim($_GET["year"] ?? "");
$filterGender = trim($_GET["gender"] ?? "");

$hasFilters = ($searchQuery !== "" || $filterCourse !== "" || $filterYear !== "" || $filterGender !== "");

// ---------------------------------------------------------------------------
// 7. LOAD THE STUDENT LIST (prepared statement with dynamic WHERE)
// ---------------------------------------------------------------------------
$sql    = "SELECT id, student_id, name, email, mobile, date_of_birth, course, year, gender, role
           FROM students
           WHERE 1=1";
$types  = "";
$params = [];

// Search across student_id, name, email and mobile
if ($searchQuery !== "") {
    $sql .= " AND (student_id LIKE ? OR name LIKE ? OR email LIKE ? OR mobile LIKE ?)";
    $like = "%" . $searchQuery . "%";
    $types .= "ssss";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Filters (each one is bound as a value, never merged into the SQL text)
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
if ($filterGender !== "") {
    $sql .= " AND gender = ?";
    $types .= "s";
    $params[] = $filterGender;
}

$sql .= " ORDER BY id ASC";

$students = [];

try {
    $listStmt = $conn->prepare($sql);
    if ($types !== "") {
        $listStmt->bind_param($types, ...$params);
    }
    $listStmt->execute();
    $listResult = $listStmt->get_result();

    while ($row = $listResult->fetch_assoc()) {
        $students[] = $row;
    }
    $listStmt->close();
} catch (mysqli_sql_exception $e) {
    // Database error: show an empty list plus a safe message below
    $students = [];
    $_GET["status"] = "error";
    $_GET["msg"]    = "The student list could not be loaded right now.";
}

// ---------------------------------------------------------------------------
// 8. FLASH MESSAGE FROM THE REDIRECT (Post/Redirect/Get)
// ---------------------------------------------------------------------------
$pageStatus  = $_GET["status"] ?? "";
$pageMessage = $_GET["msg"] ?? "";

// Helper: print a table cell safely
function cell($value, $fallback = "")
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
    <title>Student Management - Student Hub Portal</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Small page-specific extras - style.css itself is untouched */
        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .search-form .form-group {
            flex: 1;
            min-width: 160px;
            margin-bottom: 0;
        }
        .search-actions {
            display: flex;
            gap: 8px;
        }
        .search-actions .form-button,
        .search-actions .clear-btn {
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
            <h2>Student Management</h2>
            <p>Add, view, edit, delete, search and filter student records.</p>

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
             E. + F. SEARCH AND FILTER (GET form, works with the list)
             =========================================================== -->
        <section>
            <h3>Search &amp; Filter</h3>

            <form action="manage_students.php" method="GET" class="search-form">

                <div class="form-group">
                    <label for="q">Search:</label>
                    <input type="text" id="q" name="q"
                           value="<?php echo htmlspecialchars($searchQuery); ?>"
                           placeholder="ID, name, email or mobile">
                </div>

                <div class="form-group">
                    <label for="filter-course">Course:</label>
                    <select id="filter-course" name="course">
                        <option value="">All courses</option>
                        <?php foreach ($allowedCourses as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php if ($filterCourse === $option) echo "selected"; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="filter-year">Year:</label>
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

                <div class="form-group">
                    <label for="filter-gender">Gender:</label>
                    <select id="filter-gender" name="gender">
                        <option value="">All</option>
                        <?php foreach ($allowedGenders as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"
                                <?php if ($filterGender === $option) echo "selected"; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="search-actions">
                    <input type="submit" value="Search" class="form-button">
                    <?php if ($hasFilters): ?>
                        <a href="manage_students.php" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </div>

            </form>
        </section>

        <!-- ===========================================================
             A. ADD  /  C. EDIT  - one form, switches mode
             =========================================================== -->
        <section>
            <div class="form-container">

                <?php if ($editStudent !== null): ?>

                    <h3>Edit Student <span class="edit-note">(Student ID and password cannot be changed here)</span></h3>

                    <form action="manage_students.php" method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?php echo (int)$editStudent["id"]; ?>">

                        <div class="form-group">
                            <label>Student ID (auto-generated):</label>
                            <input type="text" value="<?php echo htmlspecialchars($editStudent["student_id"]); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label for="edit-name">Full Name:</label>
                            <input type="text" id="edit-name" name="name"
                                   value="<?php echo htmlspecialchars($editStudent["name"]); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit-email">Email Address:</label>
                            <input type="email" id="edit-email" name="email"
                                   value="<?php echo htmlspecialchars($editStudent["email"]); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit-mobile">Mobile Number:</label>
                            <input type="tel" id="edit-mobile" name="mobile" maxlength="10"
                                   value="<?php echo htmlspecialchars($editStudent["mobile"]); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="edit-dob">Date of Birth (leave blank if unknown):</label>
                            <input type="date" id="edit-dob" name="date_of_birth"
                                   value="<?php echo htmlspecialchars($editStudent["date_of_birth"] ?? ""); ?>">
                        </div>

                        <div class="form-group">
                            <label for="edit-gender">Gender:</label>
                            <select id="edit-gender" name="gender" required>
                                <option value="">-- Select Gender --</option>
                                <?php foreach ($allowedGenders as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php if ($editStudent["gender"] === $option) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit-course">Course:</label>
                            <select id="edit-course" name="course" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach ($allowedCourses as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php if ($editStudent["course"] === $option) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit-year">Year of Study:</label>
                            <select id="edit-year" name="year" required>
                                <option value="">-- Select Year of Study --</option>
                                <?php foreach ($allowedYears as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                        <?php if ($editStudent["year"] === $option) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <input type="submit" value="Save Changes" class="form-button">
                        <p><a href="manage_students.php">Cancel and go back</a></p>
                    </form>

                <?php else: ?>

                    <h3>Add Student</h3>

                    <form action="manage_students.php" method="POST">
                        <input type="hidden" name="action" value="add">

                        <div class="form-group">
                            <label for="add-name">Full Name:</label>
                            <input type="text" id="add-name" name="name" placeholder="Enter full name" required>
                        </div>

                        <div class="form-group">
                            <label for="add-email">Email Address:</label>
                            <input type="email" id="add-email" name="email" placeholder="e.g. student@example.com" required>
                        </div>

                        <div class="form-group">
                            <label for="add-mobile">Mobile Number:</label>
                            <input type="tel" id="add-mobile" name="mobile" maxlength="10" placeholder="10-digit mobile number" required>
                        </div>

                        <div class="form-group">
                            <label for="add-dob">Date of Birth (leave blank if unknown):</label>
                            <input type="date" id="add-dob" name="date_of_birth">
                        </div>

                        <div class="form-group">
                            <label for="add-gender">Gender:</label>
                            <select id="add-gender" name="gender" required>
                                <option value="">-- Select Gender --</option>
                                <?php foreach ($allowedGenders as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>">
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add-course">Course:</label>
                            <select id="add-course" name="course" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach ($allowedCourses as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>">
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add-year">Year of Study:</label>
                            <select id="add-year" name="year" required>
                                <option value="">-- Select Year of Study --</option>
                                <?php foreach ($allowedYears as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>">
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="add-password">Password:</label>
                            <input type="password" id="add-password" name="password"
                                   placeholder="At least 8 chars (upper, lower, number, special)" required>
                        </div>

                        <input type="submit" value="Add Student" class="form-button">
                    </form>

                <?php endif; ?>

            </div>
        </section>

        <!-- ===========================================================
             B. VIEW STUDENTS - table (never shows password hashes)
             =========================================================== -->
        <section>
            <h3>Students (<?php echo count($students); ?>)</h3>

            <?php if (count($students) === 0): ?>

                <?php if ($hasFilters): ?>
                    <p>No students match your search and filters. Adjust them or
                       <a href="manage_students.php">clear the search</a>.</p>
                <?php else: ?>
                    <p>No student records found. Add a student using the form above.</p>
                <?php endif; ?>

            <?php else: ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>DB ID</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Mobile</th>
                                <th>Date of Birth</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Gender</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $row): ?>
                                <tr>
                                    <td><?php echo (int)$row["id"]; ?></td>
                                    <td><?php cell($row["student_id"]); ?></td>
                                    <td><?php cell($row["name"]); ?></td>
                                    <td><?php cell($row["email"]); ?></td>
                                    <td><?php cell($row["mobile"]); ?></td>
                                    <td><?php cell($row["date_of_birth"], "Not provided"); ?></td>
                                    <td><?php cell($row["course"]); ?></td>
                                    <td><?php cell($row["year"]); ?></td>
                                    <td><?php cell($row["gender"]); ?></td>
                                    <td><?php cell($row["role"]); ?></td>
                                    <td class="row-actions">
                                        <a class="small-btn edit-btn"
                                           href="manage_students.php?edit_id=<?php echo (int)$row["id"]; ?>">Edit</a>

                                        <form action="manage_students.php" method="POST"
                                              onsubmit="return confirm('Delete this student? Their event registrations will also be removed.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$row["id"]; ?>">
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
