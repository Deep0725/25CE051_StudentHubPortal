<?php
/* ==========================================================================
   Student Hub Portal - Registration Form Processor
   File: process_registration.php
   1. Receives POST data from register.html
   2. Validates every field on the SERVER side (never trust the browser)
   3. Checks that the email is not already registered (prepared statement)
   4. Hashes the password with password_hash() (plain text is NEVER stored)
   5. Inserts the student into the MySQL 'students' table (prepared statement)
   6. Redirects back to register.html with a success or error message
   NOTE: registrations are now stored in MySQL, not in data/registrations.json
   ========================================================================== */

// Only allow POST requests. Direct page visits are sent back to the form.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: register.html");
    exit;
}

// ---------------------------------------------------------------------------
// 1. COLLECT INPUT DATA
// trim() removes spaces from both ends of the value
// ---------------------------------------------------------------------------
$name        = trim($_POST["name"] ?? "");
$email       = trim($_POST["email"] ?? "");
$mobile      = trim($_POST["mobile"] ?? "");
$dob         = trim($_POST["date_of_birth"] ?? "");
$password    = $_POST["password"] ?? "";
$confirmPass = $_POST["confirm-pass"] ?? "";
$course      = trim($_POST["course"] ?? "");
$year        = trim($_POST["year"] ?? "");
$gender      = trim($_POST["gender"] ?? "");
$terms       = $_POST["terms"] ?? "";

// Array to collect every error so the user can see all problems at once
$errors = [];

// ---------------------------------------------------------------------------
// 2. VALIDATE EACH FIELD (same rules as register.js, but enforced on server)
// ---------------------------------------------------------------------------

// Full Name: required, only letters and spaces, minimum 2 characters
if ($name === "") {
    $errors[] = "Name is required.";
} elseif (!preg_match("/^[A-Za-z ]{2,}$/", $name)) {
    $errors[] = "Name must contain only letters and spaces (at least 2 characters).";
}

// Email: required, must be a valid email format
if ($email === "") {
    $errors[] = "Email is required.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Please enter a valid email address.";
}

// Mobile: required, exactly 10 digits starting with 6, 7, 8 or 9
if ($mobile === "") {
    $errors[] = "Mobile number is required.";
} elseif (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) {
    $errors[] = "Mobile number must be exactly 10 digits starting with 6, 7, 8, or 9.";
}

// Date of Birth: required, real calendar date, never in the future
if ($dob === "") {
    $errors[] = "Date of birth is required.";
} elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $dob)) {
    $errors[] = "Please enter a valid date of birth.";
} else {
    // Split "YYYY-MM-DD" and check it is a real date
    // (rejects impossible dates like 2005-02-30)
    $parts = explode("-", $dob);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        $errors[] = "Please enter a valid date of birth.";
    } elseif ($dob > date("Y-m-d")) {
        // ISO "YYYY-MM-DD" strings compare correctly as plain text
        $errors[] = "Date of birth cannot be in the future.";
    }
}

// Password: required, strong password rule
if ($password === "") {
    $errors[] = "Password is required.";
} elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{8,}$/", $password)) {
    $errors[] = "Password must be at least 8 characters long and include an uppercase letter, lowercase letter, number, and special character.";
}

// Confirm Password: required, must match the password exactly
if ($confirmPass === "") {
    $errors[] = "Please confirm your password.";
} elseif ($password !== $confirmPass) {
    $errors[] = "Passwords do not match.";
}

// Course: must be one of the allowed options (value from browser is never trusted)
$allowedCourses = [
    "Computer Engineering",
    "Information Technology",
    "Civil Engineering",
    "Mechanical Engineering",
    "Electrical Engineering"
];
if ($course === "") {
    $errors[] = "Please select a course.";
} elseif (!in_array($course, $allowedCourses, true)) {
    $errors[] = "Invalid course selected.";
}

// Year of Study: must be one of the allowed options
$allowedYears = ["First Year", "Second Year", "Third Year", "Fourth Year"];
if ($year === "") {
    $errors[] = "Please select your year of study.";
} elseif (!in_array($year, $allowedYears, true)) {
    $errors[] = "Invalid year of study selected.";
}

// Gender: one of Male, Female or Other must be selected
$allowedGenders = ["Male", "Female", "Other"];
if ($gender === "") {
    $errors[] = "Please select your gender.";
} elseif (!in_array($gender, $allowedGenders, true)) {
    $errors[] = "Invalid gender value.";
}

// Terms and Conditions: checkbox must be ticked (checkbox sends "on" when checked)
if ($terms !== "on") {
    $errors[] = "You must accept the Terms and Conditions.";
}

// ---------------------------------------------------------------------------
// 3. IF THERE ARE ANY ERRORS, GO BACK TO THE FORM WITH THE MESSAGE
// (Post/Redirect/Get pattern - stops the browser from resubmitting on refresh)
// Nothing is inserted into MySQL when validation fails.
// ---------------------------------------------------------------------------
if (!empty($errors)) {
    header("Location: register.html?status=error&msg=" . urlencode(implode(" | ", $errors)));
    exit;
}

// ---------------------------------------------------------------------------
// 4. SANITIZE TEXT and HASH THE PASSWORD
// ---------------------------------------------------------------------------
function sanitizeText($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, "UTF-8");
    return $data;
}

$name = sanitizeText($name);

// password_hash() creates a one-way secure bcrypt hash.
// The original password can NEVER be read back from this value.
// NEVER use MD5 or SHA for passwords - they are not safe.
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// ---------------------------------------------------------------------------
// 5. SAVE THE STUDENT TO MySQL
// On PHP 8.1+ mysqli throws an exception on database errors,
// so the database work is wrapped in try / catch.
// ---------------------------------------------------------------------------
require_once "db.php";

try {
    // --- 5a. Auto-generate a unique student_id like STU0001, STU0002 ... ---
    // The form has no student_id field, so the portal creates one.
    $result = $conn->query("SELECT MAX(id) AS max_id FROM students");
    $row    = $result->fetch_assoc();
    $nextNumber = (int)$row["max_id"] + 1;

    do {
        $studentId = "STU" . str_pad($nextNumber, 4, "0", STR_PAD_LEFT);
        $nextNumber++;

        // Prepared statement: make sure this student_id is not already used
        $checkStmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM students WHERE student_id = ?"
        );
        $checkStmt->bind_param("s", $studentId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkRow    = $checkResult->fetch_assoc();
        $checkStmt->close();
    } while ($checkRow["total"] > 0);

    // --- 5b. Duplicate email check (prepared statement - no string merging) ---
    $emailStmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM students WHERE email = ?"
    );
    $emailStmt->bind_param("s", $email);
    $emailStmt->execute();
    $emailResult = $emailStmt->get_result();
    $emailRow    = $emailResult->fetch_assoc();
    $emailStmt->close();

    if ($emailRow["total"] > 0) {
        header("Location: register.html?status=error&msg=" . urlencode("This email address is already registered. Please use a different email."));
        exit;
    }

    // --- 5c. INSERT the new student (prepared statement) ---
    // 9 question marks (?) are filled in by bind_param below.
    // This stops SQL injection - the user input is never part of the SQL text.
    $insertStmt = $conn->prepare(
        "INSERT INTO students (student_id, name, email, mobile, date_of_birth, password, course, year, gender)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    // "sssssssss" = all 9 values are treated as strings.
    // The DOB stays a plain "YYYY-MM-DD" string, so no timezone
    // conversion can shift the date.
    $insertStmt->bind_param(
        "sssssssss",
        $studentId,
        $name,
        $email,
        $mobile,
        $dob,
        $passwordHash,
        $course,
        $year,
        $gender
    );

    $insertStmt->execute();
    $insertStmt->close();

} catch (mysqli_sql_exception $e) {
    // Error code 1062 = UNIQUE constraint (duplicate email or student_id).
    // This can still happen if two people register with the same email
    // at the exact same second, so the database UNIQUE key is our safety net.
    if ($e->getCode() == 1062) {
        header("Location: register.html?status=error&msg=" . urlencode("This email address is already registered. Please use a different email."));
    } else {
        // Show a safe generic message - NEVER the raw SQL error
        header("Location: register.html?status=error&msg=" . urlencode("Sorry, your account could not be created. Please try again."));
    }
    exit;
}

$conn->close();

// ---------------------------------------------------------------------------
// 6. SUCCESS - redirect back to the registration page with the new Student ID
// (the password hash is NEVER sent to the browser)
// ---------------------------------------------------------------------------
header("Location: register.html?status=success&sid=" . urlencode($studentId));
exit;
