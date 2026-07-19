
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
         * - Sesión: lee SESSION_LIFETIME de Laravel (config/session.php, en minutos)
         * - Throttle de 30s: actividad real no reinicia el timer en cada micro-evento
         * - Solo escucha: click y keydown (sin scroll/touchstart para evitar ruido)
         * - Ping al servidor SOLO si hubo actividad en el último ciclo de ping; si el
         *   usuario está inactivo NO se pinga y el backend deja expirar la sesión
         *   (cierre por inactividad garantizado por el servidor, no dependiente del JS)
         * - Modal de aviso aparece con 60s de antelación al cierre
         * - Si la expiración se detecta DE GOLPE (volver a una pestaña dormida, ping que
         *   encuentra la sesión ya caída), el mismo modal se muestra en modo "sesión
         *   cerrada" unos segundos antes de salir — nunca se cierra sin avisar.
         */
        (function() {
            // ── Configuración ──────────────────────────────────────────
            // Vida de la sesión = config('session.lifetime') (SESSION_LIFETIME en .env, en minutos).
            // El BACKEND garantiza el cierre por inactividad con esa misma config; el JS solo avisa
            // (WARNING_DURATION_SEC antes) y renueva al pulsar "Mantener Sesión".
            const SESSION_LIFETIME_MS   = {{ config('session.lifetime') ?? 10 }} * 60 * 1000; // minutos desde config/session.php (SESSION_LIFETIME)
            // Avisar en el último 33% del tiempo (mín 15s, máx 60s)
            const WARNING_DURATION_SEC  = Math.max(15, Math.min(60, Math.floor(SESSION_LIFETIME_MS / 1000 * 0.33)));
            // Throttle = 25% del tiempo de sesión (mín 5s, máx 30s)
            const ACTIVITY_THROTTLE_MS  = Math.max(5000, Math.min(30000, Math.floor(SESSION_LIFETIME_MS * 0.25)));
            // Ping cada 80% del tiempo de sesión
            const SERVER_PING_MS        = Math.floor(SESSION_LIFETIME_MS * 0.80);

            // ── Estado interno ──────────────────────────────────────────
            let sessionExpirationTime;
            let lastActivityReset = 0;
            let checkInterval;
            let serverPingInterval;
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
                // Sincronizar actividad inicial a localStorage para múltiples pestañas
                localStorage.setItem('vidalsa_last_activity', Date.now());
                updateExpirationTime();
                startCheckInterval();
                startServerPing();
                setupEventListeners();
                console.log(`✅ Session Monitor: Activo | Sesión=${SESSION_LIFETIME_MS/60000}min | Aviso=${WARNING_DURATION_SEC}s | Ping cada ${SERVER_PING_MS/60000}min`);
            }

            // ── Timer Frontend ──────────────────────────────────────────
            function updateExpirationTime() {
                const now = Date.now();
                lastActivityReset = now;
                localStorage.setItem('vidalsa_last_activity', now);
                sessionExpirationTime = now + SESSION_LIFETIME_MS;
            }

            function syncWithOtherTabs() {
                // Leer la última actividad registrada por CUALQUIER pestaña
                const globalLastActivity = parseInt(localStorage.getItem('vidalsa_last_activity')) || 0;
                // Si otra pestaña registró actividad más reciente, actualizar la nuestra
                if (globalLastActivity > lastActivityReset) {
                    lastActivityReset = globalLastActivity;
                    sessionExpirationTime = globalLastActivity + SESSION_LIFETIME_MS;
                    // Si el modal estaba visible por error, ocultarlo
                    if (isModalVisible) hideWarning();
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

            // ── Ping al servidor (CONDICIONAL a actividad reciente) ─────
            // El ping renueva la sesión del BACKEND, pero SOLO si hubo actividad real en el
            // último ciclo de ping. Si el usuario está inactivo NO se pinga → la sesión del
            // servidor expira sola a los SESSION_LIFETIME desde la última actividad, así el
            // cierre por inactividad queda GARANTIZADO por el backend aunque el JS se
            // deshabilite o falle. El timer del frontend + el modal son la capa de UX/aviso;
            // el backend es la fuente de verdad. Durante el aviso (modal) tampoco se pinga.
            function startServerPing() {
                if (serverPingInterval) clearInterval(serverPingInterval);
                serverPingInterval = setInterval(pingServer, SERVER_PING_MS);
            }

            function pingServer() {
                if (isModalVisible) return; // El usuario debe decidir, no renovar

                // Sin actividad real en el último ciclo de ping → NO renovamos: dejamos que
                // la sesión del backend expire sola (cierre por inactividad garantizado por
                // el servidor, no dependiente del JS). El umbral es el PROPIO intervalo de
                // ping (no un valor arbitrario): renueva a cualquiera que haya estado activo
                // dentro del ciclo —incluido el caso "activo hace 5 min sin pedir nada al
                // server"— y solo omite al usuario realmente inactivo.
                if (Date.now() - lastActivityReset >= SERVER_PING_MS) return;

                // Usuario activo: renovar CSRF y mantener viva la sesión del backend.
                fetch('/refresh-csrf', { method: 'GET', cache: 'no-store' })
                    .then(response => {
                        if (response.ok) {
                            // 200 con token de invitado = la sesión ya cayó en el backend
                            // (ruta pública). Reflejamos el cierre en vez de seguir como si nada.
                            if (response.headers.get('X-Auth-Status') === 'guest') {
                                console.warn('⚠️ Ping: sesión ya expirada en el backend');
                                sessionAlreadyExpired();
                                return;
                            }
                            return response.text().then(token => {
                                if (token && token.length > 10) {
                                    const meta = document.querySelector('meta[name="csrf-token"]');
                                    if (meta) meta.setAttribute('content', token);
                                    if (window.axios) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
                                }
                                console.log('🔄 Ping OK: sesión backend activa (usuario activo)');
                            });
                        } else {
                            console.warn('⚠️ Ping fallido: sesión expirada en servidor');
                            showExpiredNotice(performLogout);
                        }
                    })
                    .catch(() => {
                        console.warn('⚠️ Ping sin respuesta (sin conexión)');
                    });
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
            // pingServer (no esperamos al intervalo normal = SERVER_PING_MS, 80% de la vida de sesión):
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
                updateExpirationTime();     // reinicia el timer del frontend YA
                hideWarning();              // cierra el aviso de inmediato (resetea el botón)

                const controller = new AbortController();
                const timeoutId  = setTimeout(() => controller.abort(), 8000);

                fetch('/refresh-csrf', { method: 'GET', cache: 'no-store', signal: controller.signal })
                    .then(async response => {
                        clearTimeout(timeoutId);
                        if (!response.ok) { reverificarSesionPronto(); return; }
                        // Ruta pública: 200 con token de INVITADO = la sesión ya expiró en el backend.
                        if (response.headers.get('X-Auth-Status') === 'guest') { sessionAlreadyExpired(); return; }
                        const token = await response.text();
                        if (token && token.length > 10) {
                            const meta = document.querySelector('meta[name="csrf-token"]');
                            if (meta) meta.setAttribute('content', token);
                            if (window.axios) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
                            if (window.jQuery) window.jQuery.ajaxSetup({ headers: { 'X-CSRF-TOKEN': token } });
                        }
                        startServerPing();
                        if (window.showToast) window.showToast('Sesión renovada', 'success');
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
                clearInterval(serverPingInterval);
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
                setTimeout(accion, 3000);
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
            function performLogout() {
                clearInterval(checkInterval);
                clearInterval(serverPingInterval);
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/logout';
                const csrf = document.querySelector('meta[name="csrf-token"]');
                if (csrf) {
                    const input = document.createElement('input');
                    input.type  = 'hidden';
                    input.name  = '_token';
                    input.value = csrf.getAttribute('content');
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }

            // ── Actividad del usuario (con throttle) ────────────────────
            function handleActivity() {
                if (isModalVisible) return; // Modal visible → el usuario debe decidir
                const now = Date.now();
                if (now - lastActivityReset >= ACTIVITY_THROTTLE_MS) {
                    updateExpirationTime();
                }
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
