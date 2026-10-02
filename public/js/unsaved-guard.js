window.setUpUnsavedDataChangesAlert = ({ body, livewireComponent, $wire }) => {
    let allowLeave = false

    const hasUnsavedChanges = () => {
        if ($wire?.__instance?.effects?.redirect) {
            return false
        }

        return window.jsMd5(JSON.stringify($wire.data).replace(/\\/g, '')) !== $wire.savedDataHash
    }

    window.addEventListener('beforeunload', (event) => {
        if (allowLeave || !hasUnsavedChanges()) {
            return
        }

        event.preventDefault()
        event.returnValue = true
    })

    let modal = null
    let modalResolve = null

    const closeModal = () => {
        if (modal) {
            modal.remove()
            modal = null
        }
        modalResolve = null
    }

    const resolveModal = (ok) => {
        const resolve = modalResolve
        closeModal()
        if (resolve) {
            resolve(ok)
        }
    }

    const ask = () => new Promise((resolve) => {
        modalResolve = resolve

        const overlay = document.createElement('div')
        overlay.style.cssText = [
            'position:fixed',
            'inset:0',
            'z-index:9999',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'background:rgba(0,0,0,.5)',
            'padding:16px',
        ].join(';')
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                resolveModal(false)
            }
        })

        const panel = document.createElement('div')
        panel.style.cssText = [
            'width:min(460px,100%)',
            'background:#fff',
            'border-radius:12px',
            'padding:24px',
            'box-shadow:0 20px 50px rgba(0,0,0,.3)',
        ].join(';')
        panel.innerHTML = [
            '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">',
            '  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="24" height="24" style="color:#f59e0b;flex:none;">',
            '    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>',
            '  </svg>',
            '  <span style="font-size:15px;font-weight:600;color:#111827;">Tienes cambios sin guardar</span>',
            '</div>',
            '<div style="font-size:14px;color:#475569;margin-bottom:22px;line-height:1.5;">',
            '  Si sales de esta página, no se guardarán los datos ingresados. ¿Deseas continuar?',
            '</div>',
            '<div style="display:flex;justify-content:flex-end;gap:10px;">',
            '  <button type="button" data-leave="cancel" style="padding:9px 16px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-size:14px;font-weight:500;cursor:pointer;">Cancelar</button>',
            '  <button type="button" data-leave="continue" style="padding:9px 16px;border:none;border-radius:8px;background:#2563eb;color:#fff;font-size:14px;font-weight:500;cursor:pointer;">Continuar</button>',
            '</div>',
        ].join('')

        panel.querySelector('[data-leave="cancel"]').addEventListener('click', () => resolveModal(false))
        panel.querySelector('[data-leave="continue"]').addEventListener('click', () => resolveModal(true))

        overlay.appendChild(panel)
        document.body.appendChild(overlay)
        modal = overlay
    })

    document.addEventListener('click', (e) => {
        if (!hasUnsavedChanges()) {
            return
        }

        const anchor = e.target.closest('a[href]')
        if (!anchor || anchor.target === '_blank') {
            return
        }

        const href = anchor.getAttribute('href') || ''
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
            return
        }

        try {
            const url = new URL(anchor.href, window.location.origin)
            if (url.origin !== window.location.origin) {
                return
            }
        } catch (err) {
            return
        }

        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()

        ask().then((ok) => {
            if (ok) {
                allowLeave = true
                window.location.href = anchor.href
            }
        })
    }, true)
}