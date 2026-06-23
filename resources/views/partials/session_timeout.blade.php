
    {{-- Session Timeout Modal — Layout centrado, compacto y limpio. --}}
    <div id="sessionTimeoutModal" class="modal-overlay" style="display: none; z-index: 1000002 !important;">
        <div class="modal-content" style="width: 90%; max-width: 320px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35); border: 1px solid #e2e8f0; text-align: center;">

            {{-- HEADER: icono al lado del texto, ambos centrados como grupo --}}
            <div style="background: linear-gradient(135deg,#1e293b 0%,#0f172a 100%); padding: 14px 18px; display: flex; align-items: center; justify-content: center; gap: 12px;">
                <div style="background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.4); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="material-icons" style="color: #f59e0b; font-size: 22px;">warning_amber</i>
                </div>
                <div style="text-align: left;">
                    <h3 style="margin: 0; color: #fff; font-size: 14.5px; font-weight: 700; line-height: 1.25;">Tu sesión está por expirar</h3>
                    <p style="margin: 2px 0 0 0; color: #94a3b8; font-size: 11.5px;">Inactividad detectada</p>
                </div>
            </div>

            {{-- BODY centrado: countdown + barra + boton --}}
            <div style="padding: 16px 22px 18px; background: #f8fafc;">
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
         * - Sesión: lee SESSION_LIFETIME de Laravel (local=20min, prod=25min)
         * - Throttle de 30s: actividad real no reinicia el timer en cada micro-evento
         * - Solo escucha: click y keydown (sin scroll/touchstart para evitar ruido)
         * - Ping al servidor SOLO si hubo actividad en el último ciclo de ping; si el
         *   usuario está inactivo NO se pinga y el backend deja expirar la sesión
         *   (cierre por inactividad garantizado por el servidor, no dependiente del JS)
         * - Modal de aviso aparece con 60s de antelación al cierre
         */
        (function() {
            // ── Configuración ──────────────────────────────────────────
            const SESSION_LIFETIME_MS   = {{ config('session.lifetime') ?? 20 }} * 60 * 1000;
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
                syncWithOtherTabs(); // Sincronizar antes de calcular el tiempo restante

                const msRemaining  = sessionExpirationTime - Date.now();
                const secRemaining = Math.ceil(msRemaining / 1000);

                if (secRemaining <= 0) {
                    performLogout();
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
                            performLogout();
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

            // ── Extender sesión (botón del modal) ───────────────────────
            window.extendSession = function() {
                const btn = document.getElementById('btnExtendSession');
                if (btn) {
                    btn.disabled      = true;
                    btn.style.opacity = '0.7';
                    btn.innerHTML     = '<i class="material-icons" style="font-size:18px;animation:spin 1s linear infinite;">sync</i><span>Renovando...</span>';
                }

                if (typeof window.showPreloader === 'function') window.showPreloader();

                const controller = new AbortController();
                const timeoutId  = setTimeout(() => controller.abort(), 8000);

                fetch('/refresh-csrf', { method: 'GET', cache: 'no-store', signal: controller.signal })
                    .then(async response => {
                        clearTimeout(timeoutId);
                        if (response.ok) {
                            const token = await response.text();
                            if (token && token.length > 10) {
                                const meta = document.querySelector('meta[name="csrf-token"]');
                                if (meta) meta.setAttribute('content', token);
                                if (window.axios) window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
                                if (window.jQuery) window.jQuery.ajaxSetup({ headers: { 'X-CSRF-TOKEN': token } });
                            }
                            // Usuario eligió extender → reiniciar timers
                            updateExpirationTime();
                            startServerPing();
                            hideWarning();
                        } else {
                            throw new Error('Server ' + response.status);
                        }
                    })
                    .catch(error => {
                        console.error('Error al renovar sesión:', error);
                        if (typeof showModal === 'function') {
                            showModal({
                                type: 'error',
                                title: 'Error de Sesión',
                                message: 'No se pudo renovar la sesión. Por favor recarga la página.',
                                confirmText: 'Recargar',
                                hideCancel: true,
                                onConfirm: () => window.location.reload()
                            });
                        } else {
                            window.location.reload();
                        }
                    })
                    .finally(() => {
                        if (typeof window.hidePreloader === 'function') window.hidePreloader();
                        if (btn) {
                            btn.disabled      = false;
                            btn.style.opacity = '1';
                            btn.innerHTML     = '<i class="material-icons" style="font-size:18px;">refresh</i><span>Mantener Sesión</span>';
                        }
                    });
            };

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
