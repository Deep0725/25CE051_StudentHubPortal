// ==========================================================================
// Student Hub Portal - events.js
// Dynamic Events Loading, Search, Filter, Sort, and Pagination via Fetch API
// ==========================================================================

document.addEventListener("DOMContentLoaded", function () {
    var allEvents = [];
    var filteredEvents = [];
    var currentPage = 1;
    var recordsPerPage = 5;

    // Registration state for the logged-in visitor (from my_registrations.php).
    // Guests keep the default values below: not logged in, no token, no events.
    var regState = {
        logged_in: false,
        role: "",
        csrf_token: "",
        event_ids: []
    };

    // DOM Elements
    var searchInput = document.getElementById("eventSearch");
    var categoryFilter = document.getElementById("eventCategoryFilter");
    var sortSelect = document.getElementById("eventSort");
    var tableBody = document.getElementById("eventsTableBody");
    var tableWrapper = document.getElementById("eventsTableWrapper");
    var loadingMsg = document.getElementById("eventsLoading");
    var errorBox = document.getElementById("eventsError");
    var noResultsMsg = document.getElementById("eventsNoResults");
    var pagination = document.getElementById("eventsPagination");
    var prevBtn = document.getElementById("eventsPrevBtn");
    var nextBtn = document.getElementById("eventsNextBtn");
    var pageInfo = document.getElementById("eventsPageInfo");

    // Helper: format YYYY-MM-DD into readable date
    function formatDate(dateStr) {
        if (!dateStr) return "";
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
    }

    // Helper: escape special HTML characters to prevent XSS
    function escapeHTML(str) {
        if (!str) return "";
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // 1. Load this visitor's registration state (login flag, CSRF token,
    //    and the event IDs they already registered for).
    function fetchMyRegistrations() {
        return fetch("my_registrations.php")
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("HTTP error " + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                regState.logged_in = !!data.logged_in;
                regState.role = data.role || "";
                regState.csrf_token = data.csrf_token || "";
                regState.event_ids = Array.isArray(data.event_ids)
                    ? data.event_ids.map(Number)
                    : [];
            })
            .catch(function () {
                // If this helper fails, the page still works for guests
                // (everyone sees "Login to Register").
                regState.logged_in = false;
                regState.role = "";
                regState.csrf_token = "";
                regState.event_ids = [];
            });
    }

    // 2. Fetch data from the database via events_data.php
    //    (the old static events.json is no longer used, so events that are
    //     deleted by an admin disappear from this page immediately)
    function fetchEvents() {
        loadingMsg.style.display = "block";
        errorBox.style.display = "none";
        noResultsMsg.style.display = "none";
        tableWrapper.style.display = "none";
        pagination.style.display = "none";

        fetch("events_data.php")
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("HTTP error " + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                loadingMsg.style.display = "none";
                if (!Array.isArray(data) || data.length === 0) {
                    noResultsMsg.textContent = "No events available at this time.";
                    noResultsMsg.style.display = "block";
                    return;
                }
                allEvents = data;
                applyFilterAndRender();
            })
            .catch(function (err) {
                loadingMsg.style.display = "none";
                errorBox.textContent = "Failed to load events data. Please make sure the server is running.";
                errorBox.style.display = "block";
                console.error("Fetch error:", err);
            });
    }

    // 3. Search, Category Filter, and Sorting
    function applyFilterAndRender() {
        var query = searchInput.value.toLowerCase().trim();
        var selectedCat = categoryFilter.value;
        var sortValue = sortSelect.value;

        filteredEvents = allEvents.filter(function (event) {
            var matchesSearch =
                !query ||
                (event.name && event.name.toLowerCase().includes(query)) ||
                (event.category && event.category.toLowerCase().includes(query)) ||
                (event.location && event.location.toLowerCase().includes(query));

            var matchesCategory =
                !selectedCat ||
                (event.category && event.category.toLowerCase() === selectedCat.toLowerCase());

            return matchesSearch && matchesCategory;
        });

        // Sorting: uses actual Date timestamps for dates
        if (sortValue === "date-asc") {
            filteredEvents.sort(function (a, b) {
                return new Date(a.date) - new Date(b.date);
            });
        } else if (sortValue === "date-desc") {
            filteredEvents.sort(function (a, b) {
                return new Date(b.date) - new Date(a.date);
            });
        } else if (sortValue === "name-asc") {
            filteredEvents.sort(function (a, b) {
                return (a.name || "").localeCompare(b.name || "");
            });
        } else if (sortValue === "name-desc") {
            filteredEvents.sort(function (a, b) {
                return (b.name || "").localeCompare(a.name || "");
            });
        }

        // Return to page 1 whenever search, filter, or sorting changes
        currentPage = 1;
        renderPage();
    }

    // 4. Build the Action cell for one event row:
    //      - guest            -> "Login to Register" link (register_event.php
    //                            sends them to login and back to this page)
    //      - admin            -> no register action (students only)
    //      - already joined   -> green "Registered" badge (no button)
    //      - logged in, free  -> Register form posting to register_event.php
    function buildActionCell(item) {
        if (!regState.logged_in) {
            return '<a class="login-to-register" href="register_event.php?event_id=' +
                escapeHTML(item.id) + '">Login to Register</a>';
        }

        if (regState.role === "admin") {
            return '<span class="no-poster">Students only</span>';
        }

        if (regState.event_ids.indexOf(Number(item.id)) !== -1) {
            return '<span class="registered-badge">Registered &#10003;</span>';
        }

        return '<form class="reg-form" method="POST" action="register_event.php">' +
            '<input type="hidden" name="event_id" value="' + escapeHTML(item.id) + '">' +
            '<input type="hidden" name="csrf_token" value="' + escapeHTML(regState.csrf_token) + '">' +
            '<button type="submit" class="reg-btn">Register</button>' +
            '</form>';
    }

    // 5. Render table rows for the current page
    function renderPage() {
        tableBody.innerHTML = "";

        if (filteredEvents.length === 0) {
            tableWrapper.style.display = "none";
            pagination.style.display = "none";
            noResultsMsg.textContent = "No matching events found.";
            noResultsMsg.style.display = "block";
            return;
        }

        noResultsMsg.style.display = "none";
        tableWrapper.style.display = "block";
        pagination.style.display = "flex";

        var totalPages = Math.ceil(filteredEvents.length / recordsPerPage);
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        var startIndex = (currentPage - 1) * recordsPerPage;
        var endIndex = Math.min(startIndex + recordsPerPage, filteredEvents.length);
        var pageItems = filteredEvents.slice(startIndex, endIndex);

        for (var i = 0; i < pageItems.length; i++) {
            var item = pageItems[i];
            var srNo = startIndex + i + 1;
            var tr = document.createElement("tr");

            // Poster: only a safe managed path is used; events without a
            // poster show a friendly "No poster" label instead.
            var posterCell;
            if (item.poster) {
                posterCell =
                    '<img class="poster-thumb" src="' + escapeHTML(item.poster) +
                    '" alt="Poster for ' + escapeHTML(item.name) + '">';
            } else {
                posterCell = '<span class="no-poster">No poster</span>';
            }

            tr.innerHTML =
                "<td>" + srNo + "</td>" +
                "<td><strong>" + escapeHTML(item.name) + "</strong></td>" +
                "<td>" + escapeHTML(formatDate(item.date)) + "</td>" +
                "<td>" + escapeHTML(item.category) + "</td>" +
                "<td>" + escapeHTML(item.location) + "</td>" +
                "<td>" + posterCell + "</td>" +
                "<td>" + escapeHTML(item.description) + "</td>" +
                "<td>" + buildActionCell(item) + "</td>";

            tableBody.appendChild(tr);
        }

        // Update pagination buttons and text
        pageInfo.textContent = "Page " + currentPage + " of " + totalPages;
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
    }

    // 6. Event Listeners
    searchInput.addEventListener("input", applyFilterAndRender);
    categoryFilter.addEventListener("change", applyFilterAndRender);
    sortSelect.addEventListener("change", applyFilterAndRender);

    prevBtn.addEventListener("click", function () {
        if (currentPage > 1) {
            currentPage--;
            renderPage();
        }
    });

    nextBtn.addEventListener("click", function () {
        var totalPages = Math.ceil(filteredEvents.length / recordsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            renderPage();
        }
    });

    // 7. Show the message sent back by register_event.php (Post/Redirect/Get),
    //    then clean it out of the address bar so it never shows twice.
    function showFlashMessage() {
        var flash = document.getElementById("eventsFlash");
        if (!flash) return;

        var params = new URLSearchParams(window.location.search);
        var message = params.get("msg");
        var status = params.get("status");

        if (message) {
            flash.textContent = message;

            if (status === "error") {
                flash.style.backgroundColor = "#f8d7da";
                flash.style.color = "#721c24";
                flash.style.borderColor = "#f5c6cb";
            }

            flash.style.display = "block";
            history.replaceState(null, "", window.location.pathname);
        }
    }

    // Initial load: registration state first (buttons depend on it),
    // then the events list itself.
    showFlashMessage();
    fetchMyRegistrations().then(fetchEvents);
});
