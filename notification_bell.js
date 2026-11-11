(function () {
    const bell = document.querySelector('[data-notification-bell]');
    if (!bell) return;

    const dot = bell.querySelector('.notif-dot');

    function refreshUnreadIndicator() {
        fetch('notification_status.php', { cache: 'no-store' })
            .then(response => response.ok ? response.json() : Promise.reject())
            .then(data => {
                const unreadCount = Math.max(0, Number(data.unreadCount) || 0);
                if (dot) dot.hidden = unreadCount === 0;
                bell.setAttribute('aria-label', unreadCount > 0
                    ? 'Notifications, ' + unreadCount + ' unread'
                    : 'Notifications');

                const countTargets = [
                    ['badgeAll', data.totalCount],
                    ['badgeUnread', data.unreadCount],
                    ['badgeAlerts', data.alertCount],
                    ['badgeInfo', data.infoCount],
                    ['statTotal', data.totalCount],
                    ['statUnread', data.unreadCount],
                    ['statAlerts', data.alertCount]
                ];
                countTargets.forEach(([id, value]) => {
                    const target = document.getElementById(id);
                    if (target && Number.isFinite(Number(value))) target.textContent = Number(value);
                });
            })
            .catch(() => {});
    }

    refreshUnreadIndicator();
    window.setInterval(refreshUnreadIndicator, 5000);
})();
