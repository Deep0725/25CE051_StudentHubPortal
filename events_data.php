<?php
/* ==========================================================================
   Student Hub Portal - Public Events JSON feed
   File: events_data.php
   The public Events page (events.html + events.js) fetches this file
   instead of the old static events.json, so the page always shows the
   events that really exist in the database (deleted events disappear
   immediately, new admin events appear immediately).

   Output: a JSON array of events - no passwords, no internal paths that
   are outside the managed poster folder.
   ========================================================================== */

require_once "db.php";

header("Content-Type: application/json; charset=utf-8");

try {
    // No user input goes into this query, so no placeholder is needed.
    $result = $conn->query(
        "SELECT id, title, description, event_date, category, location, poster_path
         FROM events
         ORDER BY event_date ASC, id ASC"
    );
    $rows = $result->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    // Safe empty response - never expose SQL details
    echo "[]";
    exit;
}

$events = [];

foreach ($rows as $row) {
    $poster = $row["poster_path"];

    // Only a poster inside the managed upload folder is published
    if ($poster !== null && strpos($poster, "uploads/event_posters/") === 0) {
        $posterUrl = $poster;
    } else {
        $posterUrl = null;
    }

    $events[] = [
        "id"          => (int)$row["id"],
        "name"        => (string)$row["title"],
        "date"        => (string)$row["event_date"],
        "category"    => (string)$row["category"],
        "location"    => (string)$row["location"],
        "description" => (string)$row["description"],
        "poster"      => $posterUrl
    ];
}

echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
