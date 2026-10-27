/* ==========================================================================
   Student Hub Portal - Student Registration Form Validation
   File: register.js
   Uses HTML5 input types, JavaScript event listeners, and Regular Expressions.
   ========================================================================== */

document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("registerForm");
    if (!form) return;

    // --- Input Elements ---
    var nameInput = document.getElementById("name");
    var emailInput = document.getElementById("email");
    var mobileInput = document.getElementById("mobile");
    var dobInput = document.getElementById("dob");
    var passInput = document.getElementById("pass");
    var confirmPassInput = document.getElementById("confirm-pass");
    var courseInput = document.getElementById("course");
    var yearInput = document.getElementById("year");
    var termsInput = document.getElementById("terms");
    var genderRadios = document.querySelectorAll('input[name="gender"]');

    // --- Error Message Spans ---
    var nameError = document.getElementById("nameError");
    var emailError = document.getElementById("emailError");
    var mobileError = document.getElementById("mobileError");
    var dobError = document.getElementById("dobError");
    var passwordError = document.getElementById("passwordError");
    var confirmPassError = document.getElementById("confirmPassError");
    var courseError = document.getElementById("courseError");
    var yearError = document.getElementById("yearError");
    var genderError = document.getElementById("genderError");
    var termsError = document.getElementById("termsError");
    var successMessage = document.getElementById("successMessage");

    // Block future dates in the date picker.
    // Build "YYYY-MM-DD" from the LOCAL date (toISOString uses UTC
    // and can be one day off near midnight in some time zones).
    var now = new Date();
    dobInput.max = now.getFullYear() + "-" +
                   String(now.getMonth() + 1).padStart(2, "0") + "-" +
                   String(now.getDate()).padStart(2, "0");

    // ==========================================================================
    // Show the message sent back by process_registration.php
    // PHP saves the record and redirects here with ?status=success
    // or ?status=error&msg=...  (Post/Redirect/Get pattern)
    // ==========================================================================
    var pageParams = new URLSearchParams(window.location.search);
    var pageStatus = pageParams.get("status");

    if (pageStatus === "success") {
        // process_registration.php sends back the new Student ID (?sid=STU0001)
        var studentId = pageParams.get("sid");
        successMessage.textContent = studentId
            ? "Registration successful! Your Student ID is " + studentId + "."
            : "Registration successful! Your account has been created.";
        successMessage.style.display = "block";
    } else if (pageStatus === "error") {
        successMessage.textContent = pageParams.get("msg") || "Registration failed. Please try again.";
        successMessage.style.backgroundColor = "#f8d7da";
        successMessage.style.color = "#721c24";
        successMessage.style.borderColor = "#f5c6cb";
        successMessage.style.display = "block";
    }

    // --- Helper Functions to Show and Clear Errors ---
    function showError(element, message) {
        if (element) {
            element.textContent = message;
        }
    }

    function clearError(element) {
        if (element) {
            element.textContent = "";
        }
    }

    // ==========================================================================
    // 1. Name Validation
    // RegEx: /^[A-Za-z ]{2,}$/
    // - Only letters and spaces allowed
    // - Minimum 2 characters
    // ==========================================================================
    function validateName() {
        var value = nameInput.value.trim();
        var nameRegex = /^[A-Za-z ]{2,}$/;

        if (value === "") {
            showError(nameError, "Name is required.");
            return false;
        } else if (value.length < 2 || !nameRegex.test(value)) {
            showError(nameError, "Name must contain only letters and spaces (at least 2 characters).");
            return false;
        } else {
            clearError(nameError);
            return true;
        }
    }

    // ==========================================================================
    // 2. Email Validation
    // RegEx: /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    // - Valid email format with @ and domain extension
    // ==========================================================================
    function validateEmail() {
        var value = emailInput.value.trim();
        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (value === "") {
            showError(emailError, "Email is required.");
            return false;
        } else if (!emailRegex.test(value)) {
            showError(emailError, "Please enter a valid email address (e.g. student@example.com).");
            return false;
        } else {
            clearError(emailError);
            return true;
        }
    }

    // ==========================================================================
    // 3. Mobile Number Validation (Indian Mobile Format)
    // RegEx: /^[6-9]\d{9}$/
    // - Exactly 10 digits
    // - Starts with 6, 7, 8, or 9
    // ==========================================================================
    function validateMobile() {
        var value = mobileInput.value.trim();
        var mobileRegex = /^[6-9]\d{9}$/;

        if (value === "") {
            showError(mobileError, "Mobile number is required.");
            return false;
        } else if (!mobileRegex.test(value)) {
            showError(mobileError, "Mobile number must be exactly 10 digits starting with 6, 7, 8, or 9.");
            return false;
        } else {
            clearError(mobileError);
            return true;
        }
    }

    // ==========================================================================
    // 4. Date of Birth Validation
    // - Required
    // - Must be a real date in YYYY-MM-DD format
    // - Cannot be in the future
    // ==========================================================================
    function validateDob() {
        // type="date" gives "" (empty) or "YYYY-MM-DD"
        var value = dobInput.value;

        if (value === "") {
            showError(dobError, "Date of birth is required.");
            return false;
        }

        // Parse at LOCAL midnight so the timezone never shifts the day
        var dobDate = new Date(value + "T00:00:00");
        if (isNaN(dobDate.getTime())) {
            showError(dobError, "Please enter a valid date of birth.");
            return false;
        }

        // Compare with TODAY at local midnight
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

        if (dobDate > today) {
            showError(dobError, "Date of birth cannot be in the future.");
            return false;
        }

        clearError(dobError);
        return true;
    }

    // ==========================================================================
    // 5. Password Validation
    // RegEx: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).{8,}$/
    // - Minimum 8 characters
    // - At least one lowercase letter
    // - At least one uppercase letter
    // - At least one number
    // - At least one special character
    // ==========================================================================
    function validatePassword() {
        var value = passInput.value;
        var passRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).{8,}$/;

        if (value === "") {
            showError(passwordError, "Password is required.");
            return false;
        } else if (!passRegex.test(value)) {
            showError(passwordError, "Password must be at least 8 characters long and include an uppercase letter, lowercase letter, number, and special character.");
            return false;
        } else {
            clearError(passwordError);
            return true;
        }
    }

    // ==========================================================================
    // 6. Confirm Password Validation
    // - Must match the password field exactly
    // ==========================================================================
    function validateConfirmPassword() {
        var passVal = passInput.value;
        var confirmVal = confirmPassInput.value;

        if (confirmVal === "") {
            showError(confirmPassError, "Please confirm your password.");
            return false;
        } else if (confirmVal !== passVal) {
            showError(confirmPassError, "Passwords do not match.");
            return false;
        } else {
            clearError(confirmPassError);
            return true;
        }
    }

    // ==========================================================================
    // 7. Course Selection Validation
    // - Must not be the default placeholder value ("")
    // ==========================================================================
    function validateCourse() {
        if (courseInput.value === "") {
            showError(courseError, "Please select a course.");
            return false;
        } else {
            clearError(courseError);
            return true;
        }
    }

    // ==========================================================================
    // 8. Year of Study Validation
    // - Must not be the default placeholder value ("")
    // ==========================================================================
    function validateYear() {
        if (yearInput.value === "") {
            showError(yearError, "Please select your year of study.");
            return false;
        } else {
            clearError(yearError);
            return true;
        }
    }

    // ==========================================================================
    // 9. Gender Validation
    // - One radio button must be selected
    // ==========================================================================
    function validateGender() {
        var selected = document.querySelector('input[name="gender"]:checked');
        if (!selected) {
            showError(genderError, "Please select your gender.");
            return false;
        } else {
            clearError(genderError);
            return true;
        }
    }

    // ==========================================================================
    // 10. Terms and Conditions Validation
    // - Checkbox must be checked
    // ==========================================================================
    function validateTerms() {
        if (!termsInput.checked) {
            showError(termsError, "You must agree to the Terms and Conditions.");
            return false;
        } else {
            clearError(termsError);
            return true;
        }
    }

    // ==========================================================================
    // Event Listeners for Real-Time Validation Feedback
    // Removes the error message as soon as the user corrects the field
    // ==========================================================================
    nameInput.addEventListener("input", function () {
        if (nameInput.value.trim().length >= 2 && /^[A-Za-z ]{2,}$/.test(nameInput.value.trim())) {
            clearError(nameError);
        }
    });
    nameInput.addEventListener("blur", validateName);

    emailInput.addEventListener("input", function () {
        if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim())) {
            clearError(emailError);
        }
    });
    emailInput.addEventListener("blur", validateEmail);

    mobileInput.addEventListener("input", function () {
        if (/^[6-9]\d{9}$/.test(mobileInput.value.trim())) {
            clearError(mobileError);
        }
    });
    mobileInput.addEventListener("blur", validateMobile);

    dobInput.addEventListener("input", function () {
        // Re-run validation while typing so the error clears once valid
        if (dobInput.value !== "") {
            validateDob();
        }
    });
    dobInput.addEventListener("blur", validateDob);

    passInput.addEventListener("input", function () {
        if (/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).{8,}$/.test(passInput.value)) {
            clearError(passwordError);
        }
        // If confirm-pass already has value, revalidate confirm password
        if (confirmPassInput.value !== "") {
            validateConfirmPassword();
        }
    });
    passInput.addEventListener("blur", validatePassword);

    confirmPassInput.addEventListener("input", function () {
        if (confirmPassInput.value === passInput.value) {
            clearError(confirmPassError);
        }
    });
    confirmPassInput.addEventListener("blur", validateConfirmPassword);

    courseInput.addEventListener("change", validateCourse);
    yearInput.addEventListener("change", validateYear);

    for (var i = 0; i < genderRadios.length; i++) {
        genderRadios[i].addEventListener("change", validateGender);
    }

    termsInput.addEventListener("change", validateTerms);

    // ==========================================================================
    // 11. Form Submission Event Listener
    // JavaScript validation is only a quick helper for the user.
    // The form is still sent to process_registration.php which
    // validates everything AGAIN on the server before saving.
    // ==========================================================================
    form.addEventListener("submit", function (event) {
        // Run all validators so every invalid field shows its error message
        var isNameValid = validateName();
        var isEmailValid = validateEmail();
        var isMobileValid = validateMobile();
        var isDobValid = validateDob();
        var isPassValid = validatePassword();
        var isConfirmValid = validateConfirmPassword();
        var isCourseValid = validateCourse();
        var isYearValid = validateYear();
        var isGenderValid = validateGender();
        var isTermsValid = validateTerms();

        var isFormValid = isNameValid && isEmailValid && isMobileValid &&
                          isDobValid && isPassValid && isConfirmValid &&
                          isCourseValid && isYearValid && isGenderValid &&
                          isTermsValid;

        if (!isFormValid) {
            // Block submission while there are client-side errors
            event.preventDefault();
            successMessage.style.display = "none";
        }
        // If all fields are valid, the browser submits the form
        // (POST) to process_registration.php for server-side processing.
    });
});
