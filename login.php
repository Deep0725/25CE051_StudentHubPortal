<?php
/* Login page - converted from login.html so PHP can:
   - redirect students who are ALREADY logged in to the dashboard
   - display login success / error messages */
require_once "auth.php";

// Already logged in? No need to show the login form again.
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login</title>
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
            <a href="index.html">Home</a>
            <a href="aboutpage.html">About</a>
            <a href="register.html">Register</a>
            <a href="login.php">Login</a>
            <a href="contact.html">Contact</a>
            <a href="faq.html">FAQ</a>
        </nav>
    </header>

    <main>

        <div class="form-container">

            <h2>Student Login</h2>

            <!-- Success / error message sent back by process_login.php -->
            <div id="loginMessage" class="success-msg"></div>

            <form action="process_login.php" method="POST">

                <div class="form-group">
                    <label for="email">Email Address:</label>
                    <input type="email" id="email" name="email" placeholder="e.g. student@example.com" required>
                </div>

                <div class="form-group">
                    <label for="pass">Password:</label>
                    <input type="password" id="pass" name="password" placeholder="Enter your password" required>
                </div>

                <input type="submit" value="Login" class="form-button">

            </form>

            <p>
                <a href="forgot_password.php">Forgot Password? Click Here</a>
            </p>

        </div>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal. All Rights Reserved.</p>
    </footer>

    <script>
        // Show the message sent back after redirect (Post/Redirect/Get)
        document.addEventListener("DOMContentLoaded", function () {
            var params = new URLSearchParams(window.location.search);
            var status = params.get("status");
            var box = document.getElementById("loginMessage");

            if (!box || !status) return;

            if (status === "error") {
                box.textContent = params.get("msg") || "Login failed. Please try again.";
                box.style.backgroundColor = "#f8d7da";
                box.style.color = "#721c24";
                box.style.borderColor = "#f5c6cb";
                box.style.display = "block";
            } else if (status === "timeout") {
                box.textContent = "Your session timed out after 15 minutes of inactivity. Please login again.";
                box.style.display = "block";
            } else if (status === "loggedout") {
                box.textContent = "You have been logged out successfully.";
                box.style.display = "block";
            } else if (status === "please-login") {
                box.textContent = "Please login to access that page.";
                box.style.backgroundColor = "#fff3cd";
                box.style.color = "#856404";
                box.style.borderColor = "#ffeeba";
                box.style.display = "block";
            } else if (status === "reset") {
                box.textContent = "Password changed successfully. Please log in with your new password.";
                box.style.display = "block";
            }
        });
    </script>

    <script src="components.js"></script>

</body>

</html>
