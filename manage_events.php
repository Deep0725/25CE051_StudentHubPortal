<?php
/* ==========================================================================
   Student Hub Portal - Event Management Module (AJAX version)
   File: manage_events.php
   Admin-only page. All add / update / delete operations now run through
   the JSON API (events_api.php) with the Fetch API (manage_events.js),
   so the event list refreshes WITHOUT reloading the whole page.

   Poster uploads still work exactly as before (JPEG/PNG/WebP, 2 MB,
   Fileinfo check, random server filename) - the browser simply sends the
   file as multipart/form-data to events_api.php with fetch().

   Security:
     - guests are sent to the login page (requireLogin)
     - non-admins are redirected to the dashboard (role re-checked in DB)
     - a CSRF token is created per session and printed in a meta tag;
       manage_events.js sends it in the X-CSRF-Token header on every
       state-changing API request

   Uses the existing auth.php session system and db.php connection.
   ========================================================================== */

// ---------------------------------------------------------------------------
// 1. LOGIN CHECK - guests are sent to the login page
// ---------------------------------------------------------------------------
require_once "auth.php";
requireLogin();

// ---------------------------------------------------------------------------
// 2. ADMIN-ONLY CHECK (server side)
// The role is read fresh from the DATABASE, so hiding a link is never the
// only protection. Ordinary student accounts are redirected to dashboard.
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
// 3. CSRF TOKEN FOR THE API
// Created once per session. The JS reads it from the meta tag below and
// sends it in the X-CSRF-Token header on POST / PUT / DELETE requests.
// events_api.php compares it with hash_equals().
// ---------------------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
}

// Same category list the API validates against
$allowedCategories = ["Workshop", "Competition", "Academic", "Seminar", "Cultural", "Technical"];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management - Student Hub Portal</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
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
            min-width: 180px;
            margin-bottom: 0;
        }
        .row-actions {
            white-space: nowrap;
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
        .poster-thumb {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #ccc;
            display: block;
        }
        .poster-current {
            margin: 6px 0;
        }
        .poster-current img {
            width: 110px;
            height: 110px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #ccc;
            display: block;
            margin-bottom: 6px;
        }
        .poster-options label {
            display: block;
            margin: 4px 0;
            font-weight: normal;
        }
        .no-poster {
            color: #777;
            font-size: 0.9rem;
        }
        .upload-hint {
            font-size: 0.85rem;
            color: #555;
            margin-top: 4px;
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
            <h2>Event Management</h2>
            <p>Add, view, edit and delete college events with optional poster images.</p>
            <p class="edit-note">The list below is loaded with the Fetch API from
               <code>events_api.php</code> - adding, editing and deleting events
               updates the table without reloading the page.</p>

            <!-- Success / error messages are filled in by manage_events.js -->
            <div id="api-message" class="success-msg" style="display:none;"></div>
        </section>

        <!-- ===========================================================
             SEARCH AND FILTER (client side, no page reload)
             =========================================================== -->
        <section>
            <h3>Search &amp; Filter</h3>

            <form class="search-form" onsubmit="return false;">

                <div class="form-group">
                    <label for="q">Search:</label>
                    <input type="text" id="q"
                           placeholder="Title, description, venue or category">
                </div>

                <div class="form-group">
                    <label for="filter-category">Category:</label>
                    <select id="filter-category">
                        <option value="">All categories</option>
                        <?php foreach ($allowedCategories as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>">
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </form>
        </section>

        <!-- ===========================================================
             ADD  /  EDIT  - one form, switched by manage_events.js
             =========================================================== -->
        <section>
            <div class="form-container">

                <h3 id="form-heading">Add Event</h3>

                <form id="event-form" onsubmit="return false;" enctype="multipart/form-data">
                    <input type="hidden" id="event-id" value="">

                    <div class="form-group">
                        <label for="event-title">Event Title:</label>
                        <input type="text" id="event-title" maxlength="150"
                               placeholder="e.g. Web Development Workshop" required>
                    </div>

                    <div class="form-group">
                        <label for="event-description">Description:</label>
                        <textarea id="event-description" rows="4" maxlength="5000"
                                  placeholder="What is this event about?" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="event-date">Event Date:</label>
                        <input type="date" id="event-date" required>
                    </div>

                    <div class="form-group">
                        <label for="event-category">Category:</label>
                        <select id="event-category" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($allowedCategories as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>">
                                    <?php echo htmlspecialchars($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="event-location">Venue:</label>
                        <input type="text" id="event-location" maxlength="150"
                               placeholder="e.g. Seminar Hall" required>
                    </div>

                    <div class="form-group">
                        <label>Poster:</label>

                        <!-- Shown only when the edited event already has a poster -->
                        <div id="poster-current-wrap" class="poster-current" style="display:none;">
                            <img id="poster-current-img" src="" alt="Current poster">
                        </div>

                        <!-- Shown only when the edited event already has a poster -->
                        <div id="poster-options" class="poster-options" style="display:none;">
                            <label>
                                <input type="radio" name="poster_action" value="keep" checked>
                                Keep current poster
                            </label>
                            <label>
                                <input type="radio" name="poster_action" value="replace">
                                Replace with a new poster
                            </label>
                            <label>
                                <input type="radio" name="poster_action" value="remove">
                                Remove poster
                            </label>
                        </div>

                        <div id="poster-file-wrap">
                            <input type="file" id="poster-file"
                                   accept="image/jpeg,image/png,image/webp">
                        </div>
                        <p class="upload-hint">JPEG, PNG or WebP only - maximum 2 MB.</p>
                    </div>

                    <input type="submit" id="form-submit-btn" value="Add Event" class="form-button">

                    <p id="cancel-edit-wrap" style="display:none;">
                        <a href="#" id="cancel-edit-btn">Cancel and go back</a>
                    </p>
                </form>

            </div>
        </section>

        <!-- ===========================================================
             VIEW EVENTS - table, filled by manage_events.js
             =========================================================== -->
        <section>
            <h3>Events (<span id="events-count">0</span>)</h3>

            <div id="events-loading" class="loading-msg">Loading events data...</div>
            <div id="events-empty" class="no-results" style="display:none;">No events found.</div>

            <div class="table-wrapper" id="events-table-wrapper" style="display:none;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Poster</th>
                            <th>Title</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Venue</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="events-tbody">
                        <!-- Filled dynamically by manage_events.js (fetch) -->
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <footer>
        <p>&copy; 2026 Student Hub Portal</p>
    </footer>

    <script src="components.js"></script>
    <script src="manage_events.js"></script>

</body>

</html>
