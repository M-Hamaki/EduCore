(function () {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (!token || !window.fetch) return;
    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        init = init || {};
        const method = String(init.method || 'GET').toUpperCase();
        if (method === 'POST') {
            const headers = new Headers(init.headers || {});
            headers.set('X-CSRF-Token', token);
            init.headers = headers;
        }
        return originalFetch(input, init);
    };
})();
