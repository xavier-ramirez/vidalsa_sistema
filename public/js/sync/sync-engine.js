/* global vidalsaDB */
/**
 * Sync engine PWA <-> Laravel.
 *
 * Triggers (los implementaremos en fases siguientes):
 *  1. Sync inicial (1ª vez tras login web exitoso) → /sync/dump → llena SQLite.
 *  2. Login local-init: guarda hash + perfil para login offline.
 *  3. Sync 6 AM y 12 PM (programados).
 *  4. Sync inmediato al volver internet (sube outbox).
 *  5. Sync silencioso por buena señal estable (4G + ≥1.5 Mbps + ≤300ms).
 *  6. Sync manual (botón).
 *
 * Esta primera fase implementa solo:
 *   • bootstrap()       → arranca DB + login-local-init + dump si hace falta.
 *   • initialDump()     → fetch /sync/dump y llena SQLite local.
 *   • lastSyncedAt()    → cuándo fue la última sync exitosa.
 *   • isStale(hours)    → ¿la sync expiró? (default 24h)
 *
 * Acciones offline (outbox), sync incremental y triggers programados se
 * implementan en siguientes turnos.
 */

(function () {
    'use strict';

    const META_LAST_SYNC = 'last_full_sync_at';

    function nowIso() { return new Date().toISOString(); }

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    /**
     * Llama a /sync/login-local-init tras login web exitoso para guardar
     * el hash + perfil del usuario en SQLite local. Esto habilita login
     * offline futuro (bcryptjs.compare contra el hash guardado).
     */
    async function loginLocalInit() {
        const res = await fetch('/sync/login-local-init', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!res.ok) throw new Error(`login-local-init falló: HTTP ${res.status}`);
        const data = await res.json();

        // Guardar perfil en user_session
        await vidalsaDB.init();
        const u = data.user;
        vidalsaDB.upsert('user_session', {
            id: u.id,
            correo: u.correo,
            nombre: u.nombre || '',
            nivel: u.nivel,
            permisos: JSON.stringify(u.permisos || []),
            frentes: JSON.stringify(u.frentes || []),
            password_hash: u.password_hash_for_offline || '',
            puede_editar:    u.puede_editar    ? 1 : 0,
            puede_movilizar: u.puede_movilizar ? 1 : 0,
            puede_estado:    u.puede_estado    ? 1 : 0,
            es_super_admin:  u.es_super_admin  ? 1 : 0,
            last_login_at:   nowIso(),
            last_synced_at:  nowIso(),
        });
        await vidalsaDB.persist();
        return u;
    }

    /**
     * Descarga snapshot completo desde /sync/dump y llena SQLite local.
     * @returns {Promise<object>} resumen con cuentas
     */
    async function initialDump() {
        const res = await fetch('/sync/dump', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error(`/sync/dump falló: HTTP ${res.status}`);
        const data = await res.json();

        await vidalsaDB.init();
        const stamp = nowIso();

        // Equipos
        if (Array.isArray(data.equipos)) {
            const rows = data.equipos.map(e => ({ ...e, last_synced_at: stamp }));
            // Vaciar tabla y llenar — sync inicial es destructivo (fuente: server)
            vidalsaDB.exec('DELETE FROM equipos');
            vidalsaDB.bulkUpsert('equipos', rows);
        }
        // Documentación
        if (Array.isArray(data.documentacion)) {
            vidalsaDB.exec('DELETE FROM documentacion');
            vidalsaDB.bulkUpsert('documentacion', data.documentacion.map(d => ({ ...d, last_synced_at: stamp })));
        }
        // Frentes
        if (Array.isArray(data.frentes)) {
            vidalsaDB.exec('DELETE FROM frentes_trabajo');
            vidalsaDB.bulkUpsert('frentes_trabajo', data.frentes.map(f => ({ ...f, last_synced_at: stamp })));
        }
        // Tipos
        if (Array.isArray(data.tipos)) {
            vidalsaDB.exec('DELETE FROM tipos_equipo');
            vidalsaDB.bulkUpsert('tipos_equipo', data.tipos.map(t => ({ ...t, last_synced_at: stamp })));
        }
        // Catálogos
        if (Array.isArray(data.catalogos)) {
            vidalsaDB.exec('DELETE FROM caracteristicas_modelo');
            vidalsaDB.bulkUpsert('caracteristicas_modelo', data.catalogos.map(c => ({ ...c, last_synced_at: stamp })));
        }
        // Movilizaciones
        if (Array.isArray(data.movilizaciones)) {
            vidalsaDB.exec('DELETE FROM movilizacion_historial');
            vidalsaDB.bulkUpsert('movilizacion_historial', data.movilizaciones.map(m => ({ ...m, last_synced_at: stamp })));
        }
        // Responsables
        if (Array.isArray(data.responsables)) {
            vidalsaDB.exec('DELETE FROM responsables');
            vidalsaDB.bulkUpsert('responsables', data.responsables.map(r => ({ ...r, last_synced_at: stamp })));
        }

        // Marcar sync exitosa
        vidalsaDB.run(
            "INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)",
            [META_LAST_SYNC, stamp]
        );
        await vidalsaDB.persist();

        return data.meta?.counts || {};
    }

    function lastSyncedAt() {
        try {
            const r = vidalsaDB.query("SELECT value FROM meta WHERE key = ?", [META_LAST_SYNC]);
            return r.length ? r[0].value : null;
        } catch { return null; }
    }

    function isStale(hours = 24) {
        const last = lastSyncedAt();
        if (!last) return true;
        const ageHours = (Date.now() - new Date(last).getTime()) / (1000 * 60 * 60);
        return ageHours > hours;
    }

    /**
     * Bootstrap: llamar al cargar páginas autenticadas. Inicializa SQLite,
     * guarda perfil para login offline y, si no hay datos locales o están
     * stale, hace sync inicial.
     *
     * Devuelve un resumen del estado de la sync.
     */
    async function bootstrap({ forceFullSync = false } = {}) {
        await vidalsaDB.init();

        // 1. Asegurar perfil local fresco (no falla si el usuario es global y no
        //    cambió nada; el endpoint es ligero).
        try { await loginLocalInit(); } catch (e) {
            console.warn('[sync] login-local-init falló:', e.message);
        }

        // 2. Sync completa si nunca se hizo, está stale, o se fuerza
        const needsDump = forceFullSync || isStale(24);
        let counts = null;
        if (needsDump && navigator.onLine) {
            try {
                counts = await initialDump();
                console.info('[sync] Dump completado:', counts);
            } catch (e) {
                console.warn('[sync] Dump falló:', e.message);
            }
        }

        return {
            lastSync: lastSyncedAt(),
            stale:    isStale(24),
            counts,
        };
    }

    window.vidalsaSync = {
        bootstrap,
        loginLocalInit,
        initialDump,
        lastSyncedAt,
        isStale,
    };
})();
