(() => {
    const match = location.pathname.match(/^\/portal\/(vocabulary|corrections|diagnostic|onboarding)\.php$/);
    if (!match) return;

    const page = match[1];
    const key = 'rs-study-heartbeat-id';
    let heartbeatId = sessionStorage.getItem(key);
    if (!heartbeatId) {
        heartbeatId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        sessionStorage.setItem(key, heartbeatId);
    }

    let sequence = 0;
    let activeSeconds = 0;
    let lastTick = Date.now();
    let interacted = false;

    const markInteraction = () => { interacted = true; };
    ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(event => {
        window.addEventListener(event, markInteraction, {passive: true});
    });

    const send = (seconds, useBeacon = false) => {
        if (seconds < 15) return;
        sequence += 1;
        const body = JSON.stringify({page, seconds: Math.min(120, Math.round(seconds)), heartbeat_id: heartbeatId, sequence});
        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('/api/web/study-heartbeat.php', new Blob([body], {type: 'application/json'}));
            return;
        }
        fetch('/api/web/study-heartbeat.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body, keepalive: true
        }).catch(() => {});
    };

    setInterval(() => {
        const now = Date.now();
        const elapsed = Math.min(10, (now - lastTick) / 1000);
        lastTick = now;
        if (!document.hidden && document.hasFocus() && interacted) activeSeconds += elapsed;
    }, 5000);

    setInterval(() => {
        if (activeSeconds >= 15) {
            const value = activeSeconds;
            activeSeconds = 0;
            interacted = false;
            send(value);
        }
    }, 60000);

    addEventListener('beforeunload', () => send(activeSeconds, true));
})();
