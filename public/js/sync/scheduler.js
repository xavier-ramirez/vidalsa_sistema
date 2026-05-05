/* global vidalsaDB, vidalsaSync, vidalsaOutbox, vidalsaNet */
/**
 * Scheduler — orquesta los 4 triggers de sincronización:
 *
 *  1. Horarios fijos: 06:00 AM y 12:00 PM (locales) → sync incremental obligatoria.
 *  2. Volver internet (window 'online') → sube outbox + sync incremental.
 *  3. Buena señal estable (vidalsaNet) → sync silencioso, throttle 1/hora.
 *  4. Botón manual → window.vidalsaScheduler.runManual().
 *
 * Lo dispara el bootstrap de sync-engine.js automáticamente.
 */

(function () {
    'use strict';

    const SCHEDULED_HOURS = [6, 12];          // 06:00 y 12:00
    const TICK_MS = 60_000;                   // chequea cada minuto
    const NET_THROTTLE_MS = 60 * 60 * 1000;   // 1 hora entre syncs por buena señal
    const META_LAST_SCHEDULED = 'last_scheduled_sync_at';
    const META_LAST_NET_SYNC  = 'last_net_quality_sync_at';

    function nowIso() { return new Date().toISOString(); }

    function getMeta(key) {
        try {
            const r = vidalsaDB.query("SELECT value FROM meta WHERE key = ?", [key]);
            return r.length ? r[0].value : null;
        } catch { return null; }
    }
    function setMeta(key, value) {
        vidalsaDB.run("INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)", [key, value]);
    }

    /** Sync incremental: usa la última fecha conocida o 24h atrás. */
    async function syncIncremental(reason = 'manual') {
        if (!navigator.onLine) {
            console.info(`[scheduler] sync (${reason}) abortada: offline`);
            return null;
        }
        await vidalsaDB.init();

        // 1. Sube outbox primero (si tiene cosas pendientes)
        const outbox = await vidalsaOutbox.tryUpload();

        // 2. Determina punto de partida del diff
        let since = getMeta('last_diff_sync_at');
        if (!since) {
            // Primera vez: usar el último dump como punto de partida
            since = getMeta('last_full_sync_at');
        }
        if (!since) {
            // Sin marcadores: hacer dump completo en su lugar
            console.info(`[scheduler] sync (${reason}): sin marcador, ejecuto dump`);
            await vidalsaSync.initialDump();
            return { reason, mode: 'dump', outbox };
        }

        try {
            const res = await fetch(`/sync/diff?since=${encodeURIComponent(since)}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            const stamp = nowIso();

            // Aplicar cambios al SQLite local
            if (Array.isArray(data.equipos) && data.equipos.length) {
                vidalsaDB.bulkUpsert('equipos', data.equipos.map(e => ({ ...e, last_synced_at: stamp })));
            }
            if (Array.isArray(data.documentacion) && data.documentacion.length) {
                vidalsaDB.bulkUpsert('documentacion', data.documentacion.map(d => ({ ...d, last_synced_at: stamp })));
            }
            if (Array.isArray(data.movilizaciones) && data.movilizaciones.length) {
                vidalsaDB.bulkUpsert('movilizacion_historial', data.movilizaciones.map(m => ({ ...m, last_synced_at: stamp })));
            }

            setMeta('last_diff_sync_at', stamp);
            await vidalsaDB.persist();

            console.info(`[scheduler] sync (${reason}) completada:`, data.meta?.counts || {});
            return { reason, mode: 'diff', counts: data.meta?.counts, outbox };
        } catch (e) {
            console.warn(`[scheduler] sync (${reason}) falló:`, e.message);
            return { reason, mode: 'diff', error: e.message };
        }
    }

    /** ¿Toca correr la sync programada de las 6 AM o 12 PM? */
    function dueScheduled() {
        const now = new Date();
        const lastIso = getMeta(META_LAST_SCHEDULED);
        const last = lastIso ? new Date(lastIso) : null;

        // Para cada hora programada, ver si ya pasó hoy y la última sync fue
        // antes de esa hora.
        for (const h of SCHEDULED_HOURS) {
            const slot = new Date(now);
            slot.setHours(h, 0, 0, 0);
            if (now >= slot && (!last || last < slot)) return true;
        }
        return false;
    }

    /** Tick principal — corre cada minuto. */
    async function tick() {
        if (!vidalsaDB.isReady()) return;

        // Trigger 1: horarios fijos
        if (dueScheduled() && navigator.onLine) {
            const result = await syncIncremental('scheduled');
            if (result && !result.error) setMeta(META_LAST_SCHEDULED, nowIso());
            await vidalsaDB.persist();
        }
    }

    /** Trigger 2: vuelve la red. */
    async function onOnline() {
        console.info('[scheduler] red recuperada → sube outbox + sync');
        await syncIncremental('online');
        await vidalsaDB.persist();
    }

    /** Trigger 3: buena señal estable (con throttle 1 hora). */
    async function onGoodNet() {
        const lastIso = getMeta(META_LAST_NET_SYNC);
        const last = lastIso ? new Date(lastIso).getTime() : 0;
        if (Date.now() - last < NET_THROTTLE_MS) return;
        console.info('[scheduler] buena señal estable → sync silenciosa');
        await syncIncremental('good-network');
        setMeta(META_LAST_NET_SYNC, nowIso());
        await vidalsaDB.persist();
    }

    const vidalsaScheduler = {
        async start() {
            await vidalsaDB.init();
            // Trigger 1: tick periódico cada minuto para detectar 6 AM / 12 PM
            setInterval(tick, TICK_MS);
            tick(); // primer chequeo al iniciar
            // Trigger 2: red recuperada
            window.addEventListener('online', onOnline);
            // Trigger 3: monitoreo de calidad de red (si el navegador lo soporta)
            if (window.vidalsaNet && navigator.connection) {
                window.vidalsaNet.startMonitoring(onGoodNet);
            }
        },

        /** Trigger 4: botón manual. */
        async runManual() {
            return syncIncremental('manual');
        },

        /** Resumen de estado para mostrar al usuario. */
        status() {
            return {
                lastFullSync:   getMeta('last_full_sync_at'),
                lastDiffSync:   getMeta('last_diff_sync_at'),
                lastScheduled:  getMeta(META_LAST_SCHEDULED),
                lastNetSync:    getMeta(META_LAST_NET_SYNC),
                pendingOutbox:  null, // se llena async desde el caller
            };
        },
    };

    window.vidalsaScheduler = vidalsaScheduler;
})();
