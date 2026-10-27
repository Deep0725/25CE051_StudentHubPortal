// ==========================================================================
// Student Hub Portal - faq.js
// Dynamic FAQs Loading, Search, and Sorting via Fetch API
// ==========================================================================

document.addEventListener("DOMContentLoaded", function () {
    var allFaqs = [];
    var filteredFaqs = [];

    // DOM Elements
    var searchInput = document.getElementById("faqSearch");
    var sortSelect = document.getElementById("faqSort");
    var faqList = document.getElementById("faqList");
    var loadingMsg = document.getElementById("faqLoading");
    var errorBox = document.getElementById("faqError");
    var noResultsMsg = document.getElementById("faqNoResults");

    // Helper: escape special HTML characters
    function escapeHTML(str) {
        if (!str) return "";
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // 1. Fetch data from faq.json
    function fetchFaqs() {
        loadingMsg.style.display = "block";
        errorBox.style.display = "none";
        noResultsMsg.style.display = "none";
        faqList.innerHTML = "";

        fetch("faq.json")
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("HTTP error " + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                loadingMsg.style.display = "none";
                if (!Array.isArray(data) || data.length === 0) {
                    noResultsMsg.textContent = "No FAQs available at this time.";
                    noResultsMsg.style.display = "block";
                    return;
                }
                allFaqs = data;
                applyFilterAndRender();
            })
            .catch(function (err) {
                loadingMsg.style.display = "none";
                errorBox.textContent = "Failed to load FAQs. Please make sure the server is running.";
                errorBox.style.display = "block";
                console.error("Fetch error:", err);
            });
    }

    // 2. Search and Sort FAQs
    function applyFilterAndRender() {
        var query = searchInput.value.toLowerCase().trim();
        var sortValue = sortSelect.value;

        filteredFaqs = allFaqs.filter(function (item) {
            if (!query) return true;
            var q = (item.question || "").toLowerCase();
            var a = (item.answer || "").toLowerCase();
            return q.includes(query) || a.includes(query);
        });

        // Sorting
        if (sortValue === "question-asc") {
            filteredFaqs.sort(function (a, b) {
                return (a.question || "").localeCompare(b.question || "");
            });
        } else if (sortValue === "question-desc") {
            filteredFaqs.sort(function (a, b) {
                return (b.question || "").localeCompare(a.question || "");
            });
        }

        renderFaqs();
    }

    // 3. Render collapsible <details><summary> elements
    function renderFaqs() {
        faqList.innerHTML = "";

        if (filteredFaqs.length === 0) {
            noResultsMsg.textContent = "No matching questions found.";
            noResultsMsg.style.display = "block";
            return;
        }

        noResultsMsg.style.display = "none";

        for (var i = 0; i < filteredFaqs.length; i++) {
            var item = filteredFaqs[i];
            var details = document.createElement("details");
            var summary = document.createElement("summary");
            var p = document.createElement("p");

            summary.textContent = item.question;
            p.textContent = item.answer;

            details.appendChild(summary);
            details.appendChild(p);
            faqList.appendChild(details);
        }
    }

    // 4. Event Listeners
    searchInput.addEventListener("input", applyFilterAndRender);
    sortSelect.addEventListener("change", applyFilterAndRender);

    // Initial fetch
    fetchFaqs();
});
