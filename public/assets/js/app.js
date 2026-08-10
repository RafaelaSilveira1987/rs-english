document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-progress]').forEach(el => {
        const value = Math.max(0, Math.min(100, Number(el.dataset.progress || 0)));
        el.style.width = value + '%';
    });
});
