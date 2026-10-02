(function () {
    var RELOAD_COOLDOWN_MS = 10000

    function cooldownActive() {
        var last = parseInt(sessionStorage.getItem('lw_reload_ts') || '0', 10)
        return Date.now() - last < RELOAD_COOLDOWN_MS
    }

    function markReload() {
        sessionStorage.setItem('lw_reload_ts', String(Date.now()))
    }

    function killOverlay() {
        var dialog = document.getElementById('livewire-error')
        if (dialog) {
            dialog.close()
            dialog.remove()
            document.body.style.overflow = ''
        }
    }

    /* ── Guard: intercept errors and dump ────────────────── */

    document.addEventListener('livewire:init', function () {
        Livewire.interceptRequest(function (ref) {
            ref.onError(function (ctx) {
                var status = ctx.response && ctx.response.status

                /* 419 → sesión expirada: recargar con cooldown */
                if (status === 419) {
                    if (typeof ctx.preventDefault === 'function') ctx.preventDefault()
                    if (!cooldownActive()) {
                        markReload()
                        window.location.reload()
                    }
                    return
                }

                /* otros errores (500, etc.): NO preventDefault —
                   Filament muestra su notificación de error.
                   Cerrar el overlay después de un breve delay
                   para que el usuario alcance a ver la notificación. */
                if (status && status >= 500) {
                    setTimeout(killOverlay, 3000)
                }
            })

            /* dump siempre suprimido — no debería ocurrir en producción */
            ref.onDump(function (ctx) {
                if (typeof ctx.preventDefault === 'function') ctx.preventDefault()
            })
        })
    })

    /* ── Belt-and-suspenders: kill any stale #livewire-error ── */

    document.addEventListener('DOMContentLoaded', function () {
        killOverlay()
        new MutationObserver(killOverlay).observe(document.body, {
            childList: true,
            subtree: true,
        })
    })
})()
