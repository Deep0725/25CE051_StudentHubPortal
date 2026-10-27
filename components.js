/* =============================================
   components.js
   Shared JavaScript for Student Hub Portal.
   Contains:
     1. Hamburger Menu
     2. Light / Dark Theme Switcher
     3. Notification Banner  (index.html only)
      4. Content Slider       (index.html only)
      5. Modal Popup          (index.html + dashboard.php)
      6. Upcoming Events      (index.html only)
    ============================================= */


/* ============================================
   1. HAMBURGER MENU
   Toggles nav open/closed on mobile screens.
   ============================================ */
function initHamburger() {
    var btn = document.getElementById("hamburger-btn");
    var nav = document.querySelector("nav");

    // If this page has no hamburger button, stop.
    if (!btn || !nav) return;

    btn.addEventListener("click", function () {
        // Toggle the nav-open class to show/hide nav
        var isOpen = nav.classList.toggle("nav-open");

        // Update aria-expanded so screen readers know the state
        btn.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });
}


/* ============================================
   2. LIGHT / DARK THEME SWITCHER
   Reads saved theme from localStorage on load.
   Saves the choice whenever the button is clicked.
   ============================================ */
function initTheme() {
    var btn = document.getElementById("theme-btn");

    // Helper to update button icon, aria-label, and tooltip (title)
    function updateButton(isDark) {
        if (!btn) return;
        if (isDark) {
            btn.textContent = "☀️";
            btn.setAttribute("aria-label", "Switch to light mode");
            btn.setAttribute("title", "Switch to light mode");
        } else {
            btn.textContent = "🌙";
            btn.setAttribute("aria-label", "Switch to dark mode");
            btn.setAttribute("title", "Switch to dark mode");
        }
    }

    // Apply saved theme when the page loads
    var savedTheme = localStorage.getItem("theme");
    if (savedTheme === "dark") {
        document.body.classList.add("dark-theme");
        updateButton(true);
    } else {
        updateButton(false);
    }

    if (!btn) return;

    btn.addEventListener("click", function () {
        // Toggle dark-theme class on body
        var isDark = document.body.classList.toggle("dark-theme");
        updateButton(isDark);
        localStorage.setItem("theme", isDark ? "dark" : "light");
    });
}


/* ============================================
   3. NOTIFICATION BANNER
   Only runs when an element with id
   "notification-banner" exists on the page.
   ============================================ */
function initBanner() {
    var banner = document.getElementById("notification-banner");
    var closeBtn = document.getElementById("banner-close-btn");

    if (!banner || !closeBtn) return;

    closeBtn.addEventListener("click", function () {
        // Hide the banner — the rest of the page is unaffected
        banner.style.display = "none";
    });
}


/* ============================================
   4. CONTENT SLIDER
   Only runs when a slider exists on the page.
   Shows one slide at a time.
   Previous / Next buttons and dot indicators.
   ============================================ */
function initSlider() {
    var slides = document.querySelectorAll(".slide");
    var dots = document.querySelectorAll(".dot");
    var prevBtn = document.getElementById("slider-prev");
    var nextBtn = document.getElementById("slider-next");

    // If no slider on this page, stop.
    if (slides.length === 0) return;

    var current = 0;   // index of the currently shown slide

    // Show slide at position 'index' and update dots
    function showSlide(index) {
        // Remove active from all slides and dots
        for (var i = 0; i < slides.length; i++) {
            slides[i].classList.remove("active");
        }
        for (var j = 0; j < dots.length; j++) {
            dots[j].classList.remove("active");
        }

        // Add active to the current slide and matching dot
        slides[index].classList.add("active");
        if (dots[index]) {
            dots[index].classList.add("active");
        }

        current = index;
    }

    // Previous button
    if (prevBtn) {
        prevBtn.addEventListener("click", function () {
            var prev = (current - 1 + slides.length) % slides.length;
            showSlide(prev);
        });
    }

    // Next button
    if (nextBtn) {
        nextBtn.addEventListener("click", function () {
            var next = (current + 1) % slides.length;
            showSlide(next);
        });
    }

    // Click on a dot to jump to that slide
    for (var k = 0; k < dots.length; k++) {
        // Use an IIFE to capture the value of k correctly
        (function (index) {
            dots[index].addEventListener("click", function () {
                showSlide(index);
            });
        })(k);
    }

    // Show the first slide to start
    showSlide(0);
}


/* ============================================
   5. MODAL POPUP
   Opens when any element with class
   "open-modal-btn" is clicked.
   Closes on X button or clicking outside the box.
   ============================================ */
function initModal() {
    var overlay = document.getElementById("modal-overlay");
    var closeBtn = document.getElementById("modal-close-btn");
    var openBtns = document.querySelectorAll(".open-modal-btn");

    // If no modal on this page, stop.
    if (!overlay) return;

    // Open the modal
    function openModal() {
        overlay.classList.add("open");
    }

    // Close the modal
    function closeModal() {
        overlay.classList.remove("open");
    }

    // All "open modal" buttons trigger openModal
    for (var i = 0; i < openBtns.length; i++) {
        openBtns[i].addEventListener("click", openModal);
    }

    // X button closes the modal
    if (closeBtn) {
        closeBtn.addEventListener("click", closeModal);
    }

    // Clicking on the dark overlay (outside the box) closes the modal
    overlay.addEventListener("click", function (event) {
        // Only close if the click was directly on the overlay,
        // not on anything inside the modal box
        if (event.target === overlay) {
            closeModal();
        }
    });

    // Press Escape key to close the modal
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
        }
    });
}


/* ============================================
   6. UPCOMING EVENTS  (index.html only)
   Loads the first few upcoming events from the
   MySQL-backed events_data.php endpoint.
   ============================================ */
function initHomeEvents() {
    var list = document.getElementById("home-events-list");

    // If this page has no events container, stop.
    if (!list) return;

    // Today in YYYY-MM-DD format (used to skip past events)
    var today = new Date().toISOString().slice(0, 10);

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

    fetch("events_data.php")
        .then(function (response) {
            if (!response.ok) {
                throw new Error("Could not load events");
            }
            return response.json();
        })
        .then(function (events) {
            // Keep upcoming events only, closest date first
            var upcoming = [];
            for (var i = 0; i < events.length; i++) {
                if (events[i].date >= today) {
                    upcoming.push(events[i]);
                }
            }
            upcoming.sort(function (a, b) {
                return a.date < b.date ? -1 : 1;
            });

            // Friendly message when nothing is coming up
            if (upcoming.length === 0) {
                list.innerHTML = '<p class="no-results">No upcoming events right now. Check back soon!</p>';
                return;
            }

            // Show only the first 3 upcoming events
            upcoming = upcoming.slice(0, 3);

            var html = "";
            for (var j = 0; j < upcoming.length; j++) {
                var ev = upcoming[j];
                html += '<div class="info-box">';
                if (ev.poster) {
                    html += '<img class="poster-thumb" src="' + escapeHTML(ev.poster) +
                        '" alt="Poster for ' + escapeHTML(ev.name) + '">';
                }
                html += "<h3>" + escapeHTML(ev.name) + "</h3>";
                html += "<p><strong>Date:</strong> " + escapeHTML(ev.date) + "</p>";
                html += "<p><strong>Category:</strong> " + escapeHTML(ev.category) + "</p>";
                html += "<p><strong>Location:</strong> " + escapeHTML(ev.location) + "</p>";
                html += "</div>";
            }
            list.innerHTML = html;
        })
        .catch(function () {
            // Friendly message when the request fails
            list.innerHTML = '<div class="error-box">Could not load events right now. Please try again later.</div>';
        });
}


/* ============================================
   RUN ALL COMPONENTS
   Called once the HTML is fully loaded.
   ============================================ */
document.addEventListener("DOMContentLoaded", function () {
    initHamburger();
    initTheme();
    initBanner();
    initSlider();
    initModal();
    initHomeEvents();
});
