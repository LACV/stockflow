window.addEventListener('DOMContentLoaded', () => {
    const ping = () => {
        fetch('/admin/keep-alive', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).catch(() => {})
    }

    setInterval(ping, 60000)

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            ping()
        }
    })

    ping()
})