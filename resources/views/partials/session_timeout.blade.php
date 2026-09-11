
    {{-- Session Timeout Modal — Layout centrado, compacto y limpio. --}}
    <div id="sessionTimeoutModal" class="modal-overlay" style="display: none; z-index: 1000002 !important;">
        <div class="modal-content" style="width: 90%; max-width: 320px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35); border: 1px solid #e2e8f0; text-align: center;">

            {{-- HEADER: icono al lado del texto, ambos centrados como grupo --}}
            <div style="background: linear-gradient(135deg,#1e293b 0%,#0f172a 100%); padding: 14px 18px; display: flex; align-items: center; justify-content: center; gap: 12px;">
                <div style="background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.4); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="material-icons" style="color: #f59e0b; font-size: 22px;">warning_amber</i>
                </div>
                <div style="text-align: left;">
                    <h3 id="stTitle" style="margin: 0; color: #fff; font-size: 14.5px; font-weight: 700; line-height: 1.25;">Tu sesión está por expirar</h3>
                    <p id="stSubtitle" style="margin: 2px 0 0 0; color: #94a3b8; font-size: 11.5px;">Inactividad detectada</p>
                </div>
            </div>

            {{-- BODY centrado: countdown + barra + boton --}}
            <div id="stBody" style="padding: 16px 22px 18px; background: #f8fafc;">
                <div style="margin: 0 0 12px 0; display: flex; align-items: baseline; justify-content: center; gap: 6px;">
                    <strong id="sessionCountdown" style="color: #dc2626; font-size: 32px; font-weight: 800; line-height: 1;">60</strong>
                    <span style="color:#64748b; font-size:13px; font-weight:600;">seg</span>
                </div>

                {{-- Barra de progreso --}}
                <div style="background: #e2e8f0; height: 5px; border-radius: 999px; overflow: hidden; margin-bottom: 14px;">
                    <div id="sessionCountdownBar" style="height: 100%; width: 100%; background: linear-gradient(90deg,#ef4444 0%,#f59e0b 100%); border-radius: 999px; transition: width 1s linear;"></div>
                </div>

                {{-- Boton principal --}}
                <button id="btnExtendSession" type="button" onclick="extendSession()"
                        style="width: 100%; padding: 9px 14px; font-size: 13.5px; font-weight: 700; background: linear-gradient(135deg,#0067b1 0%,#0284c7 100%); color: white; border: none; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(2,132,199,0.3); transition: transform 0.15s, box-shadow 0.15s;"
                        onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 16px rgba(2,132,199,0.45)';"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(2,132,199,0.3)';">
                    <i class="material-icons" style="font-size: 17px;">refresh</i>
                    <span>Mantener Sesión</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        /**
         * Session Timeout Manager
         *
         * REGLA: la sesión solo se renueva porque el usuario la está USANDO (clic o tecla) o
         * porque pulsa "Mantener Sesión" en el aviso; si no, se cierra, y se cierra en los DOS
         * lados a la vez: el servidor y esta pantalla.
         *
         * Para que no se desalineen, el reloj de aquí cuenta desde el ÚLTIMO CONTACTO CON EL
         * SERVIDOR, no desde el último clic. Antes contaba desde el clic y el servidor solo
         * se renovaba con un ping cada 16 min: quien volvía a trabajar después de un rato
         * quieto (sin guardar nada, p. ej. llenando un formulario) tenía la sesión ya muerta
         * en el servidor mientras aquí seguía viva, y el siguiente guardar lo sacaba con
         * "Tu sesión expiró". Ahora:
         *   - Cada clic o tecla, si hace más de PING_POR_ACTIVIDAD_MS que no se habla con el
         *     servidor, le avisa (/refresh-csrf). La respuesta confirma la hora del contacto.
         *   - El aviso sale WARNING_DURATION_SEC antes de contacto + SESSION_LIFETIME, que es
         *     cuando el servidor la cierra. "Mantener Sesión" también avisa al servidor.
         *   - Sin aviso ni actividad: POST /logout y al login. Si el servidor ya la había
         *     cerrado (ping que vuelve como invitado), lo mismo, directo al login.
         *   - Sin conexión no hay servidor al que avisar: mientras tanto se cuenta desde el
         *     último clic, como antes, para que quien trabaja sin señal en obra no sea sacado.
         *     Al volver la red, el primer aviso dirá si la sesión sigue viva.
         * Las pestañas comparten el último contacto por localStorage (misma cookie, misma
         * sesión en el servidor).
         */
        (function() {
            // ── Configuración ──────────────────────────────────────────
            // Vida de la sesión = config('session.lifetime') (SESSION_LIFETIME, en minutos): la
            // MISMA que usa el servidor para cerrarla.
            const SESSION_LIFETIME_MS   = {{ config('session.lifetime') ?? 10 }} * 60 * 1000;
            // Avisar en el último 33% del tiempo (mín 15s, máx 60s)
            const WARNING_DURATION_SEC  = Math.max(15, Math.min(60, Math.floor(SESSION_LIFETIME_MS / 1000 * 0.33)));
            // Cada cuánto como máximo se guarda la actividad en localStorage (la lee también
            // offline-sync.js para no sincronizar con el usuario ausente).
            const ACTIVITY_THROTTLE_MS  = Math.max(5000, Math.min(30000, Math.floor(SESSION_LIFETIME_MS * 0.25)));
            // Con actividad, se avisa al servidor como mucho cada 10% de la vida de la sesión
            // (mín 30 s, máx 5 min): con 20 min, cada 2 min. Una petición de ~60 bytes.
            const PING_POR_ACTIVIDAD_MS = Math.max(30000, Math.min(300000, Math.floor(SESSION_LIFETIME_MS * 0.10)));
            // Reintento de un aviso perdido (5xx o red): 5% de la vida de sesión (mín 15s, máx 60s).
            const PING_REINTENTO_MS     = Math.max(15000, Math.min(60000, Math.floor(SESSION_LIFETIME_MS * 0.05)));
            const CLAVE_CONTACTO        = 'vidalsa_ultimo_contacto';

            // ── Estado interno ──────────────────────────────────────────
            let ultimoContacto = 0;        // último momento confirmado en que el servidor renovó la sesión
            let ultimoIntento = 0;         // último aviso enviado, haya llegado o no
            let sessionExpirationTime;     // ultimoContacto + SESSION_LIFETIME_MS
            let lastActivityReset = 0;
            let sinConexion = false;       // el último aviso no llegó por falta de red
            let checkInterval;
            let pingEnVuelo = false;
            let pingReintento = null;   // timeout del reintento de ping (uno solo a la vez)
            let isModalVisible = false;
            // Mientras el usuario pulsa "Mantener Sesión" y la renovación está en vuelo, NO
            // debe dispararse el auto-logout: sin esto una renovación lenta se cruzaba con el
            // cierre por contador=0 y cerraba la sesión justo al pedir mantenerla ("no continúa").
            let isRenewing = false;
            // Cierre ya en curso (aviso "sesión cerrada" mostrado): evita que el interval de
            // 1s o un ping tardío disparen el logout dos veces mientras corre la despedida.
            let isClosing = false;

            // ── Inicialización ──────────────────────────────────────────
            function initSession() {
                // Esta página acaba de llegar del servidor: eso ya renovó la sesión.
                registrarContacto(Date.now());
                marcarActividad(Date.now());
                startCheckInterval();
                setupEventListeners();
                console.log(`✅ Session Monitor: Activo | Sesión=${SESSION_LIFETIME_MS/60000}min | Aviso=${WARNING_DURATION_SEC}s | Aviso al servidor con actividad cada ${PING_POR_ACTIVIDAD_MS/1000}s como máximo`);
            }

            // ── Reloj: desde el último contacto con el servidor ─────────
            function registrarContacto(momento) {
                if (momento <= ultimoContacto) return;
                ultimoContacto = momento;
                sessionExpirationTime = momento + SESSION_LIFETIME_MS;
                try { localStorage.setItem(CLAVE_CONTACTO, String(momento)); } catch (e) {}
            }

            function marcarActividad(momento) {
                lastActivityReset = momento;
                try { localStorage.setItem('vidalsa_last_activity', String(momento)); } catch (e) {}
            }

            function syncWithOtherTabs() {
                // Otra pestaña habló con el servidor más tarde: la sesión (una sola, la misma
                // cookie) se renovó también para esta.
                const otro = parseInt(localStorage.getItem(CLAVE_CONTACTO), 10) || 0;
                if (otro > ultimoContacto) {
                    ultimoContacto = otro;
                    sessionExpirationTime = otro + SESSION_LIFETIME_MS;
                    if (isModalVisible && !isClosing) hideWarning();
                }
            }

            function startCheckInterval() {
                if (checkInterval) clearInterval(checkInterval);
                checkInterval = setInterval(checkSessionStatus, 1000);
            }

            // ── Verificación de estado cada segundo ─────────────────────
            function checkSessionStatus() {
                if (isClosing) return; // despedida en curso: nada que recalcular ni ocultar
                syncWithOtherTabs(); // Sincronizar antes de calcular el tiempo restante

                const msRemaining  = sessionExpirationTime - Date.now();
                const secRemaining = Math.ceil(msRemaining / 1000);

                if (secRemaining <= 0) {
                    // No auto-cerrar si hay una renovación en curso ("Mantener Sesión"): dejamos
                    // que la petición decida (renovar / ir al login), evitando el logout a mitad.
                    // El caso típico aquí es volver a una pestaña dormida con el tiempo ya
                    // vencido: el countdown nunca corrió, así que avisamos antes de salir.
                    if (!isRenewing) showExpiredNotice(performLogout);
                } else if (secRemaining <= WARNING_DURATION_SEC) {
                    showWarning(secRemaining);
                } else {
                    if (isModalVisible) hideWarning();
                }
            }

            // ── Aviso al servidor (/refresh-csrf) ───────────────────────
            // Lo llaman la actividad del usuario y "Mantener Sesión", nunca un temporizador
            // solo: sin uso, el servidor no se renueva y cierra la sesión a su hora.
            // La hora que se registra es la de SALIDA de la petición, no la de llegada: así
            // el reloj de aquí nunca queda por delante del servidor, por lenta que sea la red.
            function pingServer() {
                if (pingEnVuelo || isClosing) return;
                pingEnVuelo = true;
                const salida = Date.now();
                ultimoIntento = salida;
                window.apiFetch('/refresh-csrf', { method: 'GET', cache: 'no-store' })
                    .then(response => {
                        if (response.ok) {
                            sinConexion = false;
                            // 200 con token de invitado = la sesión ya cayó en el backend
                            // (ruta pública). Reflejamos el cierre en vez de seguir como si nada.
                            if (response.headers.get('X-Auth-Status') === 'guest') {
                                console.warn('⚠️ Ping: sesión ya expirada en el backend');
                                sessionAlreadyExpired();
                                return;
                            }
                            registrarContacto(salida);
                            return response.text().then(token => {
                                if (token && token.length > 10) {
                                    const meta = document.querySelector('meta[name="csrf-token"]');
                                    if (meta) meta.setAttribute('content', token);
                                    if (window.axios) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
                                }
                            });
                        } else {
                            // Un no-OK NO significa sesión caída. /refresh-csrf es PÚBLICA: cuando
                            // la sesión muere de verdad responde 200 con X-Auth-Status: guest, y
                            // ese caso se atiende en el if de arriba. Aquí solo cabe un fallo del
                            // servidor o del proxy (502/503 en un despliegue, un 500 puntual), y
                            // cerrar por eso expulsaba al usuario con la sesión perfectamente viva
                            // ("se abrió y luego se cerró sola"). Se reintenta una vez.
                            console.warn(`⚠️ Ping no-OK (${response.status}): no es expiración, se reintenta`);
                            programarReintentoPing();
                        }
                    })
                    .catch(() => {
                        // Red caída: tampoco se cierra la sesión por esto. Mientras no haya red
                        // se cuenta desde el último clic (ver handleActivity).
                        console.warn('⚠️ Ping sin respuesta (sin conexión)');
                        sinConexion = true;
                        programarReintentoPing();
                    })
                    .finally(() => { pingEnVuelo = false; });
            }

            // Un ping perdido (5xx o red) se reintenta UNA vez. Si el reintento tampoco llega,
            // no se insiste ni se cierra nada: la próxima actividad lo volverá a intentar.
            function programarReintentoPing() {
                if (pingReintento) return;
                pingReintento = setTimeout(() => { pingReintento = null; pingServer(); }, PING_REINTENTO_MS);
            }

            // ── Modal de advertencia ────────────────────────────────────
            function showWarning(secRemaining) {
                const modal   = document.getElementById('sessionTimeoutModal');
                const counter = document.getElementById('sessionCountdown');
                const bar     = document.getElementById('sessionCountdownBar');
                if (modal && !isModalVisible) {
                    modal.style.display = 'flex';
                    modal.classList.add('active');
                    modal.style.zIndex  = '1000002';
                    isModalVisible = true;
                }
                const safeSec = Math.max(secRemaining, 0);
                if (counter) counter.innerText = safeSec;
                if (bar) bar.style.width = (Math.max(0, Math.min(100, (safeSec / WARNING_DURATION_SEC) * 100))) + '%';
            }

            function hideWarning() {
                const modal = document.getElementById('sessionTimeoutModal');
                if (modal) {
                    modal.classList.remove('active');
                    setTimeout(() => {
                        modal.style.display = 'none';
                    }, 300); // Wait for transition
                    isModalVisible = false;
                }
                const bar = document.getElementById('sessionCountdownBar');
                if (bar) bar.style.width = '100%';
                const btn = document.getElementById('btnExtendSession');
                if (btn) {
                    btn.disabled      = false;
                    btn.style.opacity = '1';
                    btn.innerHTML     = '<i class="material-icons" style="font-size:18px;">refresh</i><span>Mantener Sesión</span>';
                }
            }

            // ── Re-verificación tras un renovar sin confirmar ───────────
            // extendSession cierra el aviso y reinicia el timer AL INSTANTE (optimista). Si el
            // renovar en background NO se pudo confirmar (5xx o red caída), el frontend cree que
            // la sesión sigue viva cuando quizá ya cayó → la próxima petición real daría 419 y el
            // usuario perdería trabajo. Para no quedar en ese estado ciego, revalidamos PRONTO con
            // pingServer (no esperamos a la próxima actividad del usuario):
            //   • sesión caída  → pingServer detecta INVITADO → va al login;
            //   • 5xx persistente → pingServer hace logout limpio;
            //   • red aún caída  → pingServer no fuerza nada (offline ≠ sesión muerta) y otra
            //     recuperación posterior la reconfirmará.
            let reverifyTimeoutId;
            function reverificarSesionPronto() {
                clearTimeout(reverifyTimeoutId);
                reverifyTimeoutId = setTimeout(pingServer, 4000);
            }

            // ── Extender sesión (botón del modal) ───────────────────────
            // Al pulsar "Mantener Sesión": OCULTAMOS el aviso y reiniciamos el timer AL INSTANTE
            // (feedback inmediato — el modal nunca se queda "pegado"), y confirmamos con el
            // servidor EN BACKGROUND. Antes se esperaba a que /refresh-csrf respondiera para
            // ocultar el modal; si ese fetch tardaba o fallaba, el aviso se quedaba ahí ("presiono
            // continuar y no continúa"). Resultados del background: si el token viene de INVITADO
            // la sesión ya cayó → login; si no se pudo confirmar (5xx/red), revalidamos pronto.
            window.extendSession = function() {
                isRenewing = true;          // congela el auto-logout durante la confirmación
                // El reloj se reinicia YA (el aviso se cierra al instante); la respuesta del
                // servidor lo confirma con la hora real de salida, o manda al login si ya cayó.
                const salida = Date.now();
                sessionExpirationTime = salida + SESSION_LIFETIME_MS;
                hideWarning();              // cierra el aviso de inmediato (resetea el botón)

                const controller = new AbortController();
                const timeoutId  = setTimeout(() => controller.abort(), 8000);

                window.apiFetch('/refresh-csrf', { method: 'GET', cache: 'no-store', signal: controller.signal })
                    .then(async response => {
                        clearTimeout(timeoutId);
                        if (!response.ok) { reverificarSesionPronto(); return; }
                        // Ruta pública: 200 con token de INVITADO = la sesión ya expiró en el backend.
                        if (response.headers.get('X-Auth-Status') === 'guest') { sessionAlreadyExpired(); return; }
                        registrarContacto(salida);
                        const token = await response.text();
                        if (token && token.length > 10) {
                            const meta = document.querySelector('meta[name="csrf-token"]');
                            if (meta) meta.setAttribute('content', token);
                            if (window.axios) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
                            if (window.jQuery) window.jQuery.ajaxSetup({ headers: { 'X-CSRF-TOKEN': token } });
                        }
                        window.toast('Sesión renovada', 'success');
                    })
                    .catch(() => {
                        // Fallo/timeout de red al renovar el token: NO forzamos logout ni modal —
                        // el timer ya se reinició, pero revalidamos PRONTO (no al intervalo normal de ping) para no
                        // quedar creyendo que la sesión vive si en realidad ya cayó.
                        clearTimeout(timeoutId);
                        reverificarSesionPronto();
                    })
                    .finally(() => { isRenewing = false; });
            };

            // ── Aviso "sesión cerrada" antes de salir ───────────────────
            // Reutiliza el mismo modal en modo despedida: sin countdown ni botón, solo el
            // mensaje, y a los 3s ejecuta la salida (logout POST o ir al login). Cubre los
            // caminos que antes cerraban EN SILENCIO: volver a una pestaña dormida con el
            // tiempo vencido, y el ping que descubre la sesión ya caída en el backend.
            function showExpiredNotice(accion) {
                if (isClosing) return;
                isClosing = true;
                clearInterval(checkInterval);
                clearTimeout(pingReintento); pingReintento = null; // que no pingue tras despedirse
                const modal    = document.getElementById('sessionTimeoutModal');
                const title    = document.getElementById('stTitle');
                const subtitle = document.getElementById('stSubtitle');
                const body     = document.getElementById('stBody');
                if (!modal || !body) { accion(); return; } // DOM raro: salir igual, sin aviso
                if (title)    title.textContent    = 'Sesión cerrada';
                if (subtitle) subtitle.textContent = 'Por inactividad';
                body.innerHTML =
                    '<p style="margin:0 0 10px 0; color:#334155; font-size:13.5px; line-height:1.5;">' +
                    'Tu sesión expiró por inactividad.<br>Volviendo al inicio de sesión…</p>' +
                    '<i class="material-icons" style="font-size:26px; color:#0067b1; animation:spin 1s linear infinite;">sync</i>';
                modal.style.display = 'flex';
                modal.classList.add('active');
                modal.style.zIndex  = '1000002';
                isModalVisible = true;
                // 1200ms (antes 3000): solo lo justo para leer "Sesión cerrada" y ya redirige;
                // 3s de spinner se sentían lentos ("ya pasó el tiempo, para qué esperar más").
                setTimeout(accion, 1200);
            }

            // ── Sesión ya caída en el backend ───────────────────────────
            // La diferencia con performLogout: aquí la sesión del servidor YA no existe,
            // así que un POST /logout con el CSRF viejo daría 419. Vamos directo al login
            // por GET — outcome claro (el usuario aterriza en la pantalla de inicio de
            // sesión) y sin pantalla de error intermedia.
            function sessionAlreadyExpired() {
                showExpiredNotice(function () { window.location.href = '/'; });
            }

            // ── Logout automático al expirar ────────────────────────────
            // Cerramos la sesión del backend por POST, pero con window.apiFetch(NO un <form>.submit()):
            // así, pase lo que pase —302 OK, 419 por sesión YA caída (CSRF viejo), 5xx o red—
            // SIEMPRE aterrizamos en el login y NUNCA se renderiza una página de error.
            // Antes era form.submit(): si el backend ya había expirado (volver a una pestaña
            // dormida cuyos timers se pausaron, o GC de sesión), el POST daba 419 y el usuario
            // veía la pantalla de error en vez de salir limpio → "el backend cerró y el front no".
            // El fetch descarta ese 419 (nunca lo pinta) y navegamos nosotros al login.
            // Si la sesión SÍ vive, el 302 destruye la sesión y actualiza la cookie (el browser
            // aplica el Set-Cookie al recibir la respuesta) antes de que naveguemos. En ambos
            // casos: backend cerrado + front en login, sin estados colgados.
            function performLogout() {
                clearInterval(checkInterval);
                clearTimeout(pingReintento); pingReintento = null; // idem: nada en vuelo al salir
                const token = window.getCsrf();   // helper central (dom_helpers.js)
                window.apiFetch('/logout', { headers: { 'Accept': 'application/json' },
                    method: 'POST',
                    body: new URLSearchParams({ _token: token }),
                    redirect: 'manual',   // no seguimos el 302: navegamos nosotros abajo
                    cache: 'no-store'
                })
                .catch(function () { /* red caída: igual salimos al login abajo */ })
                .finally(function () { window.location.replace('/'); }); // replace: sin volver "atrás" a la página protegida
            }

            // ── Actividad del usuario (con throttle) ────────────────────
            function handleActivity() {
                if (isModalVisible || isClosing) return; // Modal visible → el usuario debe decidir
                const now = Date.now();
                if (now - lastActivityReset >= ACTIVITY_THROTTLE_MS) marcarActividad(now);
                // Sin red no hay a quién avisar: se cuenta desde este clic (como antes), para no
                // sacar a quien trabaja sin señal. El aviso de abajo lo intenta igual y, al
                // volver la red, confirma si la sesión sigue viva.
                if (sinConexion || navigator.onLine === false) sessionExpirationTime = now + SESSION_LIFETIME_MS;
                // Desde el último INTENTO y no solo desde el último contacto: sin red, cada clic
                // volvería a intentarlo (y a repintar el aviso "Sin conexión" del interceptor).
                if (now - Math.max(ultimoContacto, ultimoIntento) >= PING_POR_ACTIVIDAD_MS) pingServer();
            }

            function setupEventListeners() {
                // SOLO click y keydown - eliminamos scroll y touchstart (ruido)
                ['click', 'keydown'].forEach(evt => {
                    document.addEventListener(evt, handleActivity, { passive: true });
                });

                // Cuando el usuario vuelve a la pestaña, verificar inmediatamente
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) checkSessionStatus();
                });
            }

            // ── Arranque ────────────────────────────────────────────────
            initSession();
        })();
    </script>
