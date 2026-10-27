/* ==========================================================================
   manage_events.js
   AJAX event management for the Manage Events page.
   Talks to the JSON endpoint events_api.php with the Fetch API:
     - list    : fetch GET     when the page opens (and after every change)
     - add     : fetch POST    (multipart FormData, poster file optional)
     - update  : fetch PUT     (JSON, or POST + _method=PUT when a new
                                poster file is attached - PHP cannot read
                                files from a real PUT body)
     - delete  : fetch DELETE  (after a confirm() dialog)

   No full page reload happens for any of these operations.
   Every value that is written into the table with innerHTML is first
   passed through escapeHTML() to prevent XSS.
   ========================================================================== */
document.addEventListener("DOMContentLoaded", function () {

    // The CSRF token is printed into a meta tag by manage_events.php
    var csrfMeta   = document.querySelector('meta[name="csrf-token"]');
    var csrfToken  = csrfMeta ? csrfMeta.getAttribute("content") : "";

    var events    = [];   // last list loaded from the API
    var editingId = null; // null = Add mode, otherwise the event ID being edited
    var editingHasPoster = false;

    // --- page elements -----------------------------------------------------
    var form          = document.getElementById("event-form");
    var formHeading   = document.getElementById("form-heading");
    var formSubmitBtn = document.getElementById("form-submit-btn");
    var cancelWrap    = document.getElementById("cancel-edit-wrap");
    var messageBox    = document.getElementById("api-message");
    var tbody         = document.getElementById("events-tbody");
    var tableWrapper  = document.getElementById("events-table-wrapper");
    var emptyMsg      = document.getElementById("events-empty");
    var loadingMsg    = document.getElementById("events-loading");
    var countSpan     = document.getElementById("events-count");
    var searchInput   = document.getElementById("q");
    var categoryFilter = document.getElementById("filter-category");

    var eventIdField    = document.getElementById("event-id");
    var titleField      = document.getElementById("event-title");
    var descField       = document.getElementById("event-description");
    var dateField       = document.getElementById("event-date");
    var categoryField   = document.getElementById("event-category");
    var locationField   = document.getElementById("event-location");

    var posterCurrentWrap = document.getElementById("poster-current-wrap");
    var posterCurrentImg  = document.getElementById("poster-current-img");
    var posterOptions     = document.getElementById("poster-options");
    var posterFileWrap    = document.getElementById("poster-file-wrap");
    var posterFile        = document.getElementById("poster-file");

    var POSTER_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
    var POSTER_TYPES     = ["image/jpeg", "image/png", "image/webp"];

    // ------------------------------------------------------------------
    // SMALL HELPERS
    // ------------------------------------------------------------------

    // Escape special HTML characters so event text can never inject
    // HTML or scripts into the table (XSS protection).
    function escapeHTML(value) {
        if (value === null || value === undefined) return "";
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Shorten the description for the table column
    function truncate(text, max) {
        text = String(text || "");
        return text.length > max ? text.substring(0, max) + "..." : text;
    }

    // Show the success / error message box under the page heading
    function showMessage(text, isError) {
        messageBox.textContent = text;
        messageBox.style.display = "block";
        messageBox.style.backgroundColor = isError ? "#f8d7da" : "#d4edda";
        messageBox.style.color           = isError ? "#721c24" : "#155724";
        messageBox.style.borderColor     = isError ? "#f5c6cb" : "#c3e6cb";
    }

    // Turn ANY server response into data, or a friendly Error.
    // Also catches invalid JSON (for example an unexpected HTML page).
    function handleResponse(response) {
        return response.text().then(function (text) {
            var data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error("The server sent an unexpected response. Please try again.");
            }
            if (!response.ok || !data.success) {
                throw new Error(data.message || "The request failed. Please try again.");
            }
            return data;
        });
    }

    // ------------------------------------------------------------------
    // LIST + RENDER (loaded with fetch when the page opens)
    // ------------------------------------------------------------------
    function loadEvents() {
        return fetch("events_api.php")
            .then(handleResponse)
            .then(function (data) {
                events = data.events || [];
                if (loadingMsg) loadingMsg.style.display = "none";
                renderTable();
            })
            .catch(function (err) {
                if (loadingMsg) loadingMsg.style.display = "none";
                showMessage(err.message, true);
            });
    }

    // Client-side search + category filter (same idea as events.js)
    function getFilteredEvents() {
        var query = searchInput.value.toLowerCase().trim();
        var selectedCategory = categoryFilter.value;

        return events.filter(function (ev) {
            var matchesCategory = (selectedCategory === "" || ev.category === selectedCategory);
            var matchesQuery = (query === "" ||
                String(ev.title).toLowerCase().indexOf(query) !== -1 ||
                String(ev.description).toLowerCase().indexOf(query) !== -1 ||
                String(ev.location).toLowerCase().indexOf(query) !== -1 ||
                String(ev.category).toLowerCase().indexOf(query) !== -1);
            return matchesCategory && matchesQuery;
        });
    }

    function renderTable() {
        var list = getFilteredEvents();
        countSpan.textContent = list.length;

        if (list.length === 0) {
            tableWrapper.style.display = "none";
            emptyMsg.style.display = "block";
            return;
        }
        emptyMsg.style.display = "none";
        tableWrapper.style.display = "block";

        var html = "";
        for (var i = 0; i < list.length; i++) {
            var ev = list[i];
            var posterCell;
            if (ev.poster_path) {
                posterCell = '<img class="poster-thumb" src="' + escapeHTML(ev.poster_path) +
                    '" alt="Poster for ' + escapeHTML(ev.title) + '">';
            } else {
                posterCell = '<span class="no-poster">No poster</span>';
            }

            html += "<tr>" +
                "<td>" + escapeHTML(ev.id) + "</td>" +
                "<td>" + posterCell + "</td>" +
                "<td>" + escapeHTML(ev.title) + "</td>" +
                "<td>" + escapeHTML(ev.event_date) + "</td>" +
                "<td>" + escapeHTML(ev.category) + "</td>" +
                "<td>" + escapeHTML(ev.location) + "</td>" +
                "<td>" + escapeHTML(truncate(ev.description, 60)) + "</td>" +
                '<td class="row-actions">' +
                    '<button type="button" class="small-btn edit-btn" data-id="' + escapeHTML(ev.id) + '">Edit</button> ' +
                    '<button type="button" class="small-btn delete-btn" data-id="' + escapeHTML(ev.id) + '">Delete</button>' +
                "</td>" +
                "</tr>";
        }
        tbody.innerHTML = html;
    }

    // ------------------------------------------------------------------
    // FORM MODES (Add  <->  Edit)
    // ------------------------------------------------------------------
    function startAddMode() {
        editingId = null;
        editingHasPoster = false;
        form.reset();
        eventIdField.value = "";
        formHeading.textContent = "Add Event";
        formSubmitBtn.value = "Add Event";
        cancelWrap.style.display = "none";
        posterCurrentWrap.style.display = "none";
        posterOptions.style.display = "none";
        posterCurrentImg.removeAttribute("src");
        updatePosterVisibility();
    }

    function startEditMode(ev) {
        editingId = ev.id;
        editingHasPoster = !!ev.poster_path;

        eventIdField.value    = ev.id;
        titleField.value      = ev.title;
        descField.value       = ev.description;
        dateField.value       = ev.event_date;
        categoryField.value   = ev.category;
        locationField.value   = ev.location;

        formHeading.textContent = "Edit Event (ID #" + ev.id + ")";
        formSubmitBtn.value = "Save Changes";
        cancelWrap.style.display = "block";

        // Poster UI depends on whether this event already has one
        var keepRadio = posterOptions.querySelector('input[value="keep"]');
        if (keepRadio) keepRadio.checked = true;

        if (editingHasPoster) {
            posterCurrentImg.src = ev.poster_path;
            posterCurrentWrap.style.display = "block";
            posterOptions.style.display = "block";
        } else {
            posterCurrentImg.removeAttribute("src");
            posterCurrentWrap.style.display = "none";
            posterOptions.style.display = "none";
        }
        posterFile.value = "";
        updatePosterVisibility();

        titleField.focus();
    }

    // Show / hide the poster file box depending on the mode and radio choice
    function updatePosterVisibility() {
        // Add mode, or editing an event WITHOUT a poster: file box always visible
        if (editingId === null || !editingHasPoster) {
            posterFileWrap.style.display = "block";
            return;
        }
        // Editing an event WITH a poster: file box only for "Replace"
        var selected = "keep";
        var radios = posterOptions.querySelectorAll('input[name="poster_action"]');
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked) selected = radios[i].value;
        }
        posterFileWrap.style.display = (selected === "replace") ? "block" : "none";
        if (selected !== "replace") posterFile.value = "";
    }

    // Selected poster decision in edit mode: keep / replace / remove
    function getPosterAction() {
        var selected = "keep";
        var radios = posterOptions.querySelectorAll('input[name="poster_action"]');
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked) selected = radios[i].value;
        }
        return selected;
    }

    // ------------------------------------------------------------------
    // CLIENT-SIDE POSTER CHECK (the server repeats EVERY check)
    // ------------------------------------------------------------------
    function posterFileIsAcceptable(file) {
        if (POSTER_TYPES.indexOf(file.type) === -1) {
            showMessage("Only JPEG, PNG and WebP poster images are allowed.", true);
            return false;
        }
        if (file.size > POSTER_MAX_BYTES) {
            showMessage("Poster is too large. The maximum size is 2 MB.", true);
            return false;
        }
        if (file.size === 0) {
            showMessage("The selected poster file is empty.", true);
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------------
    // ADD  (fetch POST with FormData - the poster file is optional)
    // ------------------------------------------------------------------
    function addEvent(title, description, date, category, location, file) {
        var formData = new FormData();
        formData.append("title", title);
        formData.append("description", description);
        formData.append("event_date", date);
        formData.append("category", category);
        formData.append("location", location);
        if (file) formData.append("poster", file);

        return fetch("events_api.php", {
            method: "POST",
            headers: { "X-CSRF-Token": csrfToken },  // no Content-Type: the
            body: formData                           // browser sets it (boundary)
        })
            .then(handleResponse)
            .then(function (data) {
                showMessage(data.message, false);
                startAddMode();
                return loadEvents();
            });
    }

    // ------------------------------------------------------------------
    // UPDATE
    //   - text only            -> fetch PUT with a JSON body
    //   - new poster attached  -> fetch POST + _method=PUT (multipart),
    //     because PHP cannot read uploaded files from a real PUT body
    // ------------------------------------------------------------------
    function updateEvent(id, title, description, date, category, location, file) {
        var posterAction = (editingId !== null && editingHasPoster) ? getPosterAction() : "keep";

        // "Replace" must always come with a new file
        if (posterAction === "replace" && !file) {
            return Promise.reject(new Error("Please choose a replacement poster file, or select Keep current poster."));
        }

        if (!file) {
            // ---- text-only update: normal PUT with a JSON body ----
            var body = JSON.stringify({
                title: title,
                description: description,
                event_date: date,
                category: category,
                location: location,
                poster_action: posterAction   // "keep" or "remove"
            });

            return fetch("events_api.php?id=" + encodeURIComponent(id), {
                method: "PUT",
                headers: {
                    "X-CSRF-Token": csrfToken,
                    "Content-Type": "application/json"
                },
                body: body
            })
                .then(handleResponse)
                .then(function (data) {
                    showMessage(data.message, false);
                    startAddMode();
                    return loadEvents();
                });
        }

        // ---- update with a NEW poster file: POST + _method=PUT ----
        var formData = new FormData();
        formData.append("_method", "PUT");
        formData.append("title", title);
        formData.append("description", description);
        formData.append("event_date", date);
        formData.append("category", category);
        formData.append("location", location);
        formData.append("poster_action", posterAction === "remove" ? "remove" : "replace");
        formData.append("poster", file);

        return fetch("events_api.php?id=" + encodeURIComponent(id), {
            method: "POST",
            headers: { "X-CSRF-Token": csrfToken },
            body: formData
        })
            .then(handleResponse)
            .then(function (data) {
                showMessage(data.message, false);
                startAddMode();
                return loadEvents();
            });
    }

    // ------------------------------------------------------------------
    // DELETE  (fetch DELETE after a confirm() dialog)
    // ------------------------------------------------------------------
    function deleteEvent(id) {
        var sure = confirm(
            "Delete this event? Its student registrations will also be removed and the poster file will be deleted."
        );
        if (!sure) return;

        fetch("events_api.php?id=" + encodeURIComponent(id), {
            method: "DELETE",
            headers: { "X-CSRF-Token": csrfToken }
        })
            .then(handleResponse)
            .then(function (data) {
                showMessage(data.message, false);
                // If the deleted event was open in the form, go back to Add mode
                if (String(editingId) === String(id)) startAddMode();
                return loadEvents();
            })
            .catch(function (err) {
                showMessage(err.message, true);
            });
    }

    // ------------------------------------------------------------------
    // EVENT WIRING
    // ------------------------------------------------------------------

    // Add / Save Changes button
    form.addEventListener("submit", function (event) {
        event.preventDefault();   // never submit a normal HTML form post

        var title     = titleField.value.trim();
        var description = descField.value.trim();
        var date      = dateField.value.trim();
        var category  = categoryField.value;
        var location  = locationField.value.trim();
        var file      = (posterFile.files && posterFile.files.length) ? posterFile.files[0] : null;

        // Simple checks before anything is sent (the server repeats them)
        if (title === "" || description === "" || date === "" || category === "" || location === "") {
            showMessage("Please fill in every required field.", true);
            return;
        }
        if (file && !posterFileIsAcceptable(file)) {
            posterFile.value = "";
            return;
        }

        var action;
        if (editingId === null) {
            action = addEvent(title, description, date, category, location, file);
        } else {
            action = updateEvent(editingId, title, description, date, category, location, file);
        }

        action.catch(function (err) {
            showMessage(err.message, true);
        });
    });

    // Edit / Delete buttons inside the table (one listener on the tbody)
    tbody.addEventListener("click", function (event) {
        var button = event.target;
        if (!button.classList.contains("edit-btn") && !button.classList.contains("delete-btn")) return;

        var id = parseInt(button.getAttribute("data-id"), 10);

        if (button.classList.contains("delete-btn")) {
            deleteEvent(id);
            return;
        }

        // Edit: prefill the form from the list already loaded in memory
        for (var i = 0; i < events.length; i++) {
            if (events[i].id === id) {
                startEditMode(events[i]);
                break;
            }
        }
    });

    // Cancel link in edit mode
    document.getElementById("cancel-edit-btn").addEventListener("click", function (event) {
        event.preventDefault();
        startAddMode();
    });

    // Live search + category filter (no submit, no reload)
    searchInput.addEventListener("input", renderTable);
    categoryFilter.addEventListener("change", renderTable);

    // Poster radio buttons in edit mode
    var radios = posterOptions.querySelectorAll('input[name="poster_action"]');
    for (var r = 0; r < radios.length; r++) {
        radios[r].addEventListener("change", updatePosterVisibility);
    }

    // Quick client-side check when a poster file is chosen
    posterFile.addEventListener("change", function () {
        if (posterFile.files && posterFile.files.length) {
            if (!posterFileIsAcceptable(posterFile.files[0])) {
                posterFile.value = "";
            }
        }
    });

    // ------------------------------------------------------------------
    // START: load the list from the API when the page opens
    // ------------------------------------------------------------------
    startAddMode();
    loadEvents();
});
