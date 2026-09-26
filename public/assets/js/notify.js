/**
 * Live notifications — "Notify me" on a match sends a browser notification
 * when the score or status changes, while this tab/window stays open.
 *
 * This is NOT push notifications (those need a service worker + a push
 * server and work even with the tab closed — a bigger piece of
 * infrastructure). This is the achievable version: poll our own already-
 * synced DB every 30s via api/match_status.php and notify on change.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'matchday_watched';
    var POLL_INTERVAL_MS = 30000;

    function getWatched() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function saveWatched(obj) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(obj));
        } catch (e) { /* ignore */ }
    }

    function isWatching(matchId) {
        return !!getWatched()[matchId];
    }

    function notify(title, body) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            new Notification(title, { body: body, icon: '/favicon.ico' });
        } catch (e) { /* ignore */ }
    }

    function startWatching(btn) {
        var matchId = btn.getAttribute('data-match-id');
        var homeName = btn.getAttribute('data-home-name') || 'Home';
        var awayName = btn.getAttribute('data-away-name') || 'Away';
        var homeScore = btn.getAttribute('data-home-score');
        var awayScore = btn.getAttribute('data-away-score');
        var status = btn.getAttribute('data-status');

        var watched = getWatched();
        watched[matchId] = {
            homeName: homeName,
            awayName: awayName,
            homeScore: homeScore === '' ? null : parseInt(homeScore, 10),
            awayScore: awayScore === '' ? null : parseInt(awayScore, 10),
            status: status
        };
        saveWatched(watched);
        btn.classList.add('is-active');
        btn.textContent = '🔔 Watching';
    }

    function stopWatching(btn) {
        var matchId = btn.getAttribute('data-match-id');
        var watched = getWatched();
        delete watched[matchId];
        saveWatched(watched);
        btn.classList.remove('is-active');
        btn.textContent = '🔔 Notify me';
    }

    function initNotifyButtons() {
        document.querySelectorAll('.notify-btn[data-match-id]').forEach(function (btn) {
            var matchId = btn.getAttribute('data-match-id');
            if (isWatching(matchId)) {
                btn.classList.add('is-active');
                btn.textContent = '🔔 Watching';
            }

            btn.addEventListener('click', function () {
                if (btn.classList.contains('is-active')) {
                    stopWatching(btn);
                    return;
                }
                if (!('Notification' in window)) {
                    alert("This browser doesn't support notifications.");
                    return;
                }
                if (Notification.permission === 'granted') {
                    startWatching(btn);
                } else {
                    Notification.requestPermission().then(function (perm) {
                        if (perm === 'granted') startWatching(btn);
                    });
                }
            });
        });
    }

    function pollWatchedMatches() {
        var watched = getWatched();
        var ids = Object.keys(watched);
        if (ids.length === 0) return;

        fetch('/api/match_status.php?ids=' + ids.join(','))
            .then(function (res) { return res.ok ? res.json() : []; })
            .then(function (matches) {
                var changed = false;
                matches.forEach(function (m) {
                    var prev = watched[m.id];
                    if (!prev) return;

                    var scoreChanged = prev.homeScore !== m.home_score || prev.awayScore !== m.away_score;
                    var justFinished = prev.status !== 'FINISHED' && m.status === 'FINISHED';
                    var justStarted = (prev.status === 'SCHEDULED' || prev.status === 'TIMED') && m.status === 'IN_PLAY';

                    if (justStarted) {
                        notify('Kicked off', prev.homeName + ' vs ' + prev.awayName + ' is underway.');
                    } else if (scoreChanged && m.status !== 'FINISHED') {
                        notify('Goal!', prev.homeName + ' ' + (m.home_score ?? 0) + ' - ' + (m.away_score ?? 0) + ' ' + prev.awayName);
                    } else if (justFinished) {
                        notify('Full time', prev.homeName + ' ' + (m.home_score ?? 0) + ' - ' + (m.away_score ?? 0) + ' ' + prev.awayName);
                    }

                    if (justFinished) {
                        // Nothing more to notify about — stop watching automatically.
                        delete watched[m.id];
                    } else if (scoreChanged || m.status !== prev.status) {
                        watched[m.id].homeScore = m.home_score;
                        watched[m.id].awayScore = m.away_score;
                        watched[m.id].status = m.status;
                    }
                    changed = true;
                });
                if (changed) saveWatched(watched);
            })
            .catch(function () { /* transient network error — try again next poll */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initNotifyButtons();
        pollWatchedMatches();
        setInterval(pollWatchedMatches, POLL_INTERVAL_MS);
    });
})();
