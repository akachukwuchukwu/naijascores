/**
 * Favorites — "follow" a team without an account. Stored in localStorage
 * only, so it's per-browser, not synced across devices. Powers:
 *  - the star button on team pages
 *  - the "Following" list in the sidebar
 *  - the "My Teams" filter toggle on the homepage
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'matchday_favorites';

    function getFavorites() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function saveFavorites(list) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
        } catch (e) {
            // Storage unavailable (private browsing etc.) — favorites just won't persist.
        }
    }

    function isFavorited(teamId) {
        return getFavorites().some(function (t) { return t.id === teamId; });
    }

    function toggleFavorite(teamId, teamName) {
        var list = getFavorites();
        var idx = list.findIndex(function (t) { return t.id === teamId; });
        if (idx >= 0) {
            list.splice(idx, 1);
        } else {
            list.push({ id: teamId, name: teamName });
        }
        saveFavorites(list);
        renderSidebarList();
        syncStarButtons();
    }

    function syncStarButtons() {
        document.querySelectorAll('.star-btn[data-team-id]').forEach(function (btn) {
            var teamId = parseInt(btn.getAttribute('data-team-id'), 10);
            btn.classList.toggle('is-favorited', isFavorited(teamId));
        });
    }

    function renderSidebarList() {
        var container = document.getElementById('favorites-list');
        if (!container) return;

        var favorites = getFavorites();
        if (favorites.length === 0) {
            container.innerHTML = '<li class="empty-favorites" style="padding:8px 10px; font-size:0.78rem; color:var(--text-faint);">No teams followed yet — star a team to add it here.</li>';
            return;
        }

        container.innerHTML = favorites.map(function (t) {
            return '<li><a href="/team.php?id=' + t.id + '">' +
                '<span class="sidebar__badge">' + escapeHtml(t.name.slice(0, 2).toUpperCase()) + '</span>' +
                '<span class="sidebar__label">' + escapeHtml(t.name) + '</span>' +
                '</a></li>';
        }).join('');
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // My Teams filter on the homepage fixture list.
    function initMyTeamsToggle() {
        var toggle = document.getElementById('myteams-toggle-btn');
        if (!toggle) return;

        toggle.addEventListener('click', function () {
            var favorites = getFavorites().map(function (t) { return t.id; });
            var active = toggle.classList.toggle('is-active');
            toggle.textContent = active ? 'Showing my teams' : 'My Teams';

            document.querySelectorAll('.fixture[data-home-id]').forEach(function (row) {
                var homeId = parseInt(row.getAttribute('data-home-id'), 10);
                var awayId = parseInt(row.getAttribute('data-away-id'), 10);
                var match = favorites.indexOf(homeId) !== -1 || favorites.indexOf(awayId) !== -1;
                row.style.display = (!active || match) ? '' : 'none';
            });

            // Hide/show whole league blocks if every fixture in them got filtered out.
            document.querySelectorAll('.league-block').forEach(function (block) {
                var rows = block.querySelectorAll('.fixture');
                if (rows.length === 0) return;
                var anyVisible = Array.prototype.some.call(rows, function (r) { return r.style.display !== 'none'; });
                block.style.display = anyVisible ? '' : 'none';
            });
        });
    }

    // Wire up any star buttons on the page (team profile page).
    function initStarButtons() {
        document.querySelectorAll('.star-btn[data-team-id]').forEach(function (btn) {
            var teamId = parseInt(btn.getAttribute('data-team-id'), 10);
            var teamName = btn.getAttribute('data-team-name') || '';
            btn.addEventListener('click', function () {
                toggleFavorite(teamId, teamName);
            });
        });
        syncStarButtons();
    }

    document.addEventListener('DOMContentLoaded', function () {
        renderSidebarList();
        initStarButtons();
        initMyTeamsToggle();
    });
})();
