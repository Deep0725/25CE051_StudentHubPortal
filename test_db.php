<?php
/* ==========================================================================
   StudentHub - Database Connection Test Page
   File: test_db.php
   Open this page in the browser to check that db.php works
   and that the three tables exist.
   DEVELOPMENT ONLY - delete this file before submitting / deploying.
   ========================================================================== */

// Connect to the database using the shared connection file
require_once "db.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>StudentHub - Database Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; }
        table { border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #888; padding: 8px 12px; text-align: left; }
        th { background-color: #1e3a5f; color: white; }
        .ok { color: green; font-weight: bold; }
    </style>
</head>
<body>

    <h1>StudentHub Database Connection Test</h1>

    <!-- 1. Connection result -->
    <p>Connection status: <span class="ok">Connected successfully!</span></p>
    <p>MySQL version: <?php echo htmlspecialchars($conn->server_info); ?></p>
    <p>Character set: <?php echo htmlspecialchars($conn->character_set_name()); ?></p>

    <!-- 2. Check that the three tables exist and count their rows -->
    <h2>Tables in '<?php echo htmlspecialchars($dbName); ?>'</h2>
    <table>
        <tr><th>Table</th><th>Rows</th></tr>
        <?php
        $tables = ["students", "events", "registrations"];

        foreach ($tables as $table) {
            // SELECT COUNT(*) is a simple MySQL statement to count rows
            $result = $conn->query("SELECT COUNT(*) AS total FROM `$table`");
            $row    = $result->fetch_assoc();
            echo "<tr><td>" . htmlspecialchars($table) . "</td><td>" . (int)$row["total"] . "</td></tr>";
        }
        ?>
    </table>

    <!-- 3. Show the sample events with a SELECT query -->
    <h2>Sample Events (SELECT query)</h2>
    <table>
        <tr><th>ID</th><th>Title</th><th>Date</th><th>Location</th></tr>
        <?php
        $result = $conn->query("SELECT id, title, event_date, location FROM events ORDER BY event_date");

        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . (int)$row["id"] . "</td>";
            echo "<td>" . htmlspecialchars($row["title"]) . "</td>";
            echo "<td>" . htmlspecialchars($row["event_date"]) . "</td>";
            echo "<td>" . htmlspecialchars($row["location"]) . "</td>";
            echo "</tr>";
        }
        ?>
    </table>

    <?php
    // Close the connection - good habit at the end of a script
    $conn->close();
    ?>

    <p><em>This page is for development testing only. Delete test_db.php before deploying the project.</em></p>

</body>
</html>
