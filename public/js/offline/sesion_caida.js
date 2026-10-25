/**
 * La sesión se cerró en el servidor — window.sesionCaida().
 *
 * La llama fetch_interceptor.js con un 401 (o un 419 que no se arregló) sin motivo especial.
 * En vez de llevar al login, que tiraba lo que hubiera en pantalla, pide la clave AQUÍ MISMO:
 * lo escrito sigue en su sitio, lo que se intentó guardar por la bandeja espera en ella y, al
 * entrar, la copia vuelve a ponerse al día y la bandeja sube sola. Quien entra con huella, u
 * otra persona, usa "Ir al inicio de sesión" (eso sí sale de la pantalla).
 *
 * Devuelve una promesa que se cumple, con el token nuevo, al volver a entrar: con ella el
 * interceptor repite lo que quedó esperando (la misma para todas las peticiones).
 */
(function () {
    'use strict';
    if (window.sesionCaida) return;

    const correo = () => (document.querySelector('meta[name="usuario-correo"]') || {}).content || '';
    let caja = null;
    let entrada = null, alEntrar = null;

    function pedirToken() {
        return window.fetchSinInterceptor('/refresh-csrf', { cache: 'no-store', credentials: 'same-origin' })
            .then((r) => (r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status))))
            .then((t) => {
                t = t.trim();
                if (t) {
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    if (meta) meta.setAttribute('content', t);
                    // Los formularios de la pantalla llevan el token de la sesión vieja.
                    document.querySelectorAll('input[name="_token"]').forEach((i) => { i.value = t; });
                }
                return t;
            });
    }

    function aviso(texto) {
        const m = caja.querySelector('[data-msg]');
        m.textContent = texto;
        m.style.display = texto ? 'block' : 'none';
    }

    async function entrar(e) {
        e.preventDefault();
        const clave = caja.querySelector('[data-clave]').value;
        const boton = caja.querySelector('[data-entrar]');
        if (!clave) { aviso('Escribe tu clave.'); return; }
        boton.disabled = true;
        aviso('');
        if (window.LlaveLocal) window.LlaveLocal.preparar(correo(), clave);
        try {
            const cuerpo = new FormData();
            cuerpo.append('_token', await pedirToken());
            cuerpo.append('login_identifier', correo());
            cuerpo.append('password', clave);
            const r = await window.fetchSinInterceptor('/', {
                method: 'POST', body: cuerpo, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await r.json().catch(() => ({}));
            if (!r.ok || !data.success) {
                if (window.LlaveLocal) window.LlaveLocal.descartar();
                aviso(data.message || 'No se pudo entrar. Inténtalo de nuevo.');
                return;
            }
            if (window.LlaveLocal) await window.LlaveLocal.confirmar();
            // Toca cambiar la clave: eso es otra pantalla.
            if (data.redirect && !/\/menu$/.test(data.redirect)) { window.location.href = data.redirect; return; }
            const fresco = await pedirToken();   // la sesión nueva trae su propio token
            try {
                localStorage.setItem('vidalsa_ultimo_contacto', String(Date.now()));
                localStorage.setItem('vidalsa_last_activity', String(Date.now()));
            } catch (err) {}
            cerrar();
            alEntrar(fresco);
            if (window.CopiaLocal) window.CopiaLocal.reanudar();
            if (window.Bandeja) window.Bandeja.subir();
            if (window.toast) window.toast('Entraste de nuevo.', 'success');
        } catch (err) {
            if (window.LlaveLocal) window.LlaveLocal.descartar();
            aviso('No hay conexión con el servidor. Inténtalo de nuevo en un momento.');
        } finally {
            boton.disabled = false;
        }
    }

    function cerrar() {
        if (caja) caja.remove();
        caja = null;
    }

    window.sesionCaida = function () {
        if (caja) return entrada;
        entrada = new Promise((r) => { alEntrar = r; });
        caja = document.createElement('div');
        caja.id = 'sesionCaida';
        caja.setAttribute('role', 'dialog');
        caja.setAttribute('aria-modal', 'true');
        caja.style.cssText = 'position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;'
            + 'background:rgba(15,23,42,.55);padding:16px;font-family:Nunito,"Segoe UI",system-ui,sans-serif;';
        caja.innerHTML =
            '<form style="background:#fff;border-radius:14px;max-width:380px;width:100%;padding:22px 22px 18px;box-shadow:0 20px 40px rgba(15,23,42,.35);">'
            + '<div style="display:flex;align-items:center;gap:10px;color:#0f172a;font-weight:800;font-size:17px;">'
            + '<i class="material-icons" style="color:#0067b1;">lock_clock</i>Tu sesión se cerró</div>'
            + '<p style="margin:10px 0 14px;color:#475569;font-size:13.5px;line-height:1.45;">Entra de nuevo aquí mismo. Lo que tienes en pantalla no se pierde y lo pendiente se sube solo.</p>'
            + '<input type="email" readonly data-correo style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;color:#334155;font:inherit;font-size:14px;margin-bottom:8px;">'
            + '<input type="password" data-clave autocomplete="current-password" placeholder="Tu clave" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;font-size:14px;">'
            + '<div data-msg style="display:none;margin-top:8px;color:#dc2626;font-size:12.5px;font-weight:700;"></div>'
            + '<button type="submit" data-entrar style="width:100%;margin-top:14px;padding:11px;border:none;border-radius:8px;background:#0067b1;color:#fff;font:inherit;font-weight:800;font-size:14px;cursor:pointer;">Entrar</button>'
            + '<a href="/?aviso=sesion_expirada" data-no-spa style="display:block;text-align:center;margin-top:12px;color:#64748b;font-size:12.5px;">Ir al inicio de sesión (huella u otro usuario)</a>'
            + '</form>';
        caja.querySelector('[data-correo]').value = correo();
        caja.querySelector('form').addEventListener('submit', entrar);
        caja.querySelector('a').addEventListener('click', () => { if (window.LlaveLocal) window.LlaveLocal.cerrar(); });
        (window.raizVisible ? window.raizVisible() : document.body).appendChild(caja);
        setTimeout(() => { const c = caja && caja.querySelector('[data-clave]'); if (c) c.focus(); }, 50);
        return entrada;
    };
})();
