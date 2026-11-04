(() => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (!csrfMeta) return;

    const heartbeat = () => {
        const body = new URLSearchParams({ csrf_token: csrfMeta.content });
        fetch('user_presence.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: true,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body
        }).catch(() => {});
    };

    heartbeat();
    window.setInterval(heartbeat, 20000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') heartbeat();
    });
})();
