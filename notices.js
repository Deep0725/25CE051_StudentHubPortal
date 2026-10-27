// ==========================================================================
// Student Hub Portal - notices.js
// Dynamic Notices Loading, Search, Filter, Sort, and Pagination via Fetch API
// ==========================================================================

document.addEventListener("DOMContentLoaded", function () {
    var allNotices = [];
    var filteredNotices = [];
    var currentPage = 1;
    var recordsPerPage = 5;

    // DOM Elements
    var searchInput = document.getElementById("noticeSearch");
    var categoryFilter = document.getElementById("noticeCategoryFilter");
    var sortSelect = document.getElementById("noticeSort");
    var tableBody = document.getElementById("noticesTableBody");
    var tableWrapper = document.getElementById("noticesTableWrapper");
    var loadingMsg = document.getElementById("noticesLoading");
    var errorBox = document.getElementById("noticesError");
    var noResultsMsg = document.getElementById("noticesNoResults");
    var pagination = document.getElementById("noticesPagination");
    var prevBtn = document.getElementById("noticesPrevBtn");
    var nextBtn = document.getElementById("noticesNextBtn");
    var pageInfo = document.getElementById("noticesPageInfo");

    // Helper: format YYYY-MM-DD into readable date
    function formatDate(dateStr) {
        if (!dateStr) return "";
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
    }

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

    // 1. Fetch data from notices.json
    function fetchNotices() {
        loadingMsg.style.display = "block";
        errorBox.style.display = "none";
        noResultsMsg.style.display = "none";
        tableWrapper.style.display = "none";
        pagination.style.display = "none";

        fetch("notices.json")
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("HTTP error " + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                loadingMsg.style.display = "none";
                if (!Array.isArray(data) || data.length === 0) {
                    noResultsMsg.textContent = "No notices available at this time.";
                    noResultsMsg.style.display = "block";
                    return;
                }
                allNotices = data;
                applyFilterAndRender();
            })
            .catch(function (err) {
                loadingMsg.style.display = "none";
                errorBox.textContent = "Failed to load notices data. Please make sure the server is running.";
                errorBox.style.display = "block";
                console.error("Fetch error:", err);
            });
    }

    // 2. Search, Category Filter, and Sorting
    function applyFilterAndRender() {
        var query = searchInput.value.toLowerCase().trim();
        var selectedCat = categoryFilter.value;
        var sortValue = sortSelect.value;

        filteredNotices = allNotices.filter(function (notice) {
            var matchesSearch =
                !query ||
                (notice.title && notice.title.toLowerCase().includes(query)) ||
                (notice.category && notice.category.toLowerCase().includes(query)) ||
                (notice.description && notice.description.toLowerCase().includes(query));

            var matchesCategory =
                !selectedCat ||
                (notice.category && notice.category.toLowerCase() === selectedCat.toLowerCase());

            return matchesSearch && matchesCategory;
        });

        // Sorting: Date or Title
        if (sortValue === "date-desc") {
            filteredNotices.sort(function (a, b) {
                return new Date(b.date) - new Date(a.date);
            });
        } else if (sortValue === "date-asc") {
            filteredNotices.sort(function (a, b) {
                return new Date(a.date) - new Date(b.date);
            });
        } else if (sortValue === "title-asc") {
            filteredNotices.sort(function (a, b) {
                return (a.title || "").localeCompare(b.title || "");
            });
        } else if (sortValue === "title-desc") {
            filteredNotices.sort(function (a, b) {
                return (b.title || "").localeCompare(a.title || "");
            });
        }

        // Return to page 1 on filter/search/sort change
        currentPage = 1;
        renderPage();
    }

    // 3. Render table rows for current page
    function renderPage() {
        tableBody.innerHTML = "";

        if (filteredNotices.length === 0) {
            tableWrapper.style.display = "none";
            pagination.style.display = "none";
            noResultsMsg.textContent = "No matching notices found.";
            noResultsMsg.style.display = "block";
            return;
        }

        noResultsMsg.style.display = "none";
        tableWrapper.style.display = "block";
        pagination.style.display = "flex";

        var totalPages = Math.ceil(filteredNotices.length / recordsPerPage);
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        var startIndex = (currentPage - 1) * recordsPerPage;
        var endIndex = Math.min(startIndex + recordsPerPage, filteredNotices.length);
        var pageItems = filteredNotices.slice(startIndex, endIndex);

        for (var i = 0; i < pageItems.length; i++) {
            var item = pageItems[i];
            var srNo = startIndex + i + 1;
            var tr = document.createElement("tr");

            tr.innerHTML =
                "<td>" + srNo + "</td>" +
                "<td><strong>" + escapeHTML(item.title) + "</strong></td>" +
                "<td>" + escapeHTML(formatDate(item.date)) + "</td>" +
                "<td>" + escapeHTML(item.category) + "</td>" +
                "<td>" + escapeHTML(item.description) + "</td>";

            tableBody.appendChild(tr);
        }

        // Update pagination buttons and text
        pageInfo.textContent = "Page " + currentPage + " of " + totalPages;
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
    }

    // 4. Event Listeners
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
        var totalPages = Math.ceil(filteredNotices.length / recordsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            renderPage();
        }
    });

    // Initial fetch
    fetchNotices();
});
