
    <!-- Session Timeout Modal (Modern & Compact) -->
    <div id="sessionTimeoutModal" class="modal-overlay" style="display: none; z-index: 1000002 !important;">
        <div class="modal-card" style="padding: 20px !important; max-width: 320px !important; border-radius: 12px; text-align: center;">
            <i class="material-icons modal-icon" style="color: #f59e0b; font-size: 40px !important; margin-bottom: 10px !important;">warning</i>
            <h3 class="modal-title" style="font-size: 1.05rem !important; margin-bottom: 5px !important; color: #1e293b; font-weight: 700;">Tu sesión está por expirar</h3>
            <p class="modal-message" style="font-size: 0.9rem !important; margin-bottom: 15px !important; color: #64748b; line-height: 1.5;">Se cerrará en <strong id="sessionCountdown" style="color: #dc2626;">60</strong> segundos por inactividad.</p>
            
            <div class="modal-footer" style="display: flex; gap: 8px; justify-content: center; width: 100%;">
                <button id="btnExtendSession" onclick="extendSession()" class="modal-btn modal-btn-confirm" style="width: 100%; padding: 8px 16px !important; font-size: 0.85rem !important; background-color: var(--maquinaria-blue, #1e293b); color: white; border: none; border-radius: 6px; cursor: pointer;">
                    Mantener Sesión
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
         * - Ping al servidor SOLO si el usuario estuvo activo en los últimos 2 min
         * - Modal de aviso aparece con 60s de antelación al cierre
         */
        (function() {
            // ── Configuración ──────────────────────────────────────────
            const SESSION_LIFETIME_MS   = {{ config('session.lifetime') ?? 20 }} * 60 * 1000;
            // Avisar en el último 33% del tiempo (mín 15s, máx 60s)
            const WARNING_DURATION_SEC  = Math.max(15, Math.min(60, Math.floor(SESSION_LIFETIME_MS / 1000 * 0.33)));
            // Throttle = 25% del tiempo de sesión (mín 5s, máx 30s)
            const ACTIVITY_THROTTLE_MS  = Math.max(5000, Math.min(30000, Math.floor(SESSION_LIFETIME_MS * 0.25)));
            // "Activo" = último 50% del tiempo de sesión (mín 30s, máx 2min)
            const RECENT_ACTIVITY_MS    = Math.max(30000, Math.min(120000, Math.floor(SESSION_LIFETIME_MS * 0.50)));
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
                updateExpirationTime();
                startCheckInterval();
                startServerPing();
                setupEventListeners();
                console.log(`✅ Session Monitor: Activo | Sesión=${SESSION_LIFETIME_MS/60000}min | Aviso=${WARNING_DURATION_SEC}s | Ping cada ${SERVER_PING_MS/60000}min`);
            }

            // ── Timer Frontend ──────────────────────────────────────────
            function updateExpirationTime() {
                sessionExpirationTime = Date.now() + SESSION_LIFETIME_MS;
                lastActivityReset = Date.now();
            }

            function startCheckInterval() {
                if (checkInterval) clearInterval(checkInterval);
                checkInterval = setInterval(checkSessionStatus, 1000);
            }

            // ── Verificación de estado cada segundo ─────────────────────
            function checkSessionStatus() {
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

            // ── Ping al servidor ────────────────────────────────────────
            // REGLA CLAVE: el ping SOLO toca el servidor (y renueva la sesión backend)
            // si el usuario estuvo activo en los últimos 2 minutos.
            // Si está inactivo → el ping se salta → el servidor deja expirar la sesión
            // → el timer del frontend llega a 0 → performLogout() se ejecuta.
            function startServerPing() {
                if (serverPingInterval) clearInterval(serverPingInterval);
                serverPingInterval = setInterval(pingServer, SERVER_PING_MS);
            }

            function pingServer() {
                if (isModalVisible) return; // El usuario debe decidir, no renovar

                const inactiveSinceMs = Date.now() - lastActivityReset;
                if (inactiveSinceMs >= RECENT_ACTIVITY_MS) {
                    // Inactivo: no tocar el servidor para que la sesión expire naturalmente
                    console.log('💤 Ping omitido: ' + Math.round(inactiveSinceMs/60000) + 'min sin actividad');
                    return;
                }

                // Usuario activo: renovar CSRF y mantener sesión backend viva
                fetch('/refresh-csrf', { method: 'GET' })
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
                if (modal && !isModalVisible) {
                    modal.style.display = 'flex';
                    modal.classList.add('active');
                    modal.style.zIndex  = '1000002';
                    isModalVisible = true;
                }
                if (counter) counter.innerText = Math.max(secRemaining, 0);
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
                const btn = document.getElementById('btnExtendSession');
                if (btn) {
                    btn.disabled      = false;
                    btn.style.opacity = '1';
                    btn.innerHTML     = 'Mantener Sesión';
                }
            }

            // ── Extender sesión (botón del modal) ───────────────────────
            window.extendSession = function() {
                const btn = document.getElementById('btnExtendSession');
                if (btn) {
                    btn.disabled      = true;
                    btn.style.opacity = '0.7';
                    btn.innerHTML     = 'Renovando...';
                }

                if (typeof window.showPreloader === 'function') window.showPreloader();

                const controller = new AbortController();
                const timeoutId  = setTimeout(() => controller.abort(), 8000);

                fetch('/refresh-csrf', { method: 'GET', signal: controller.signal })
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
                            btn.innerHTML     = 'Mantener Sesión';
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
