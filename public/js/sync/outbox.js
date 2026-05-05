/* global vidalsaDB */
/**
 * Outbox: cola de operaciones que el usuario hizo OFFLINE y se subirán
 * al servidor cuando vuelva la red.
 *
 * Uso desde una vista (por ejemplo al editar un equipo offline):
 *   vidalsaOutbox.enqueue({
 *     op: 'PUT',
 *     endpoint: '/admin/equipos/15',
 *     body: { ESTADO_OPERATIVO: 'INOPERATIVO' }
 *   });
 *   vidalsaOutbox.tryUpload();   // intenta subir si hay red
 *
 * El upload usa /sync/upload-outbox del backend que aplica las operaciones
 * en orden y devuelve resultado por cada una.
 */

(function () {
    'use strict';

    function nowIso() { return new Date().toISOString(); }

    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    const vidalsaOutbox = {
        async enqueue({ op, endpoint, body = null, files = null }) {
            await vidalsaDB.init();
            vidalsaDB.run(
                "INSERT INTO outbox (op, endpoint, body, files, status, tries, created_at) VALUES (?, ?, ?, ?, 'pending', 0, ?)",
                [op, endpoint, body ? JSON.stringify(body) : null, files ? JSON.stringify(files) : null, nowIso()]
            );
            await vidalsaDB.persist();
        },

        async pending() {
            await vidalsaDB.init();
            return vidalsaDB.query(
                "SELECT * FROM outbox WHERE status = 'pending' OR status = 'error' ORDER BY id ASC"
            );
        },

        async pendingCount() {
            const rows = vidalsaDB.query("SELECT COUNT(*) AS c FROM outbox WHERE status IN ('pending','error')");
            return rows.length ? rows[0].c : 0;
        },

        async markSynced(id) {
            vidalsaDB.run("UPDATE outbox SET status = 'synced', synced_at = ? WHERE id = ?", [nowIso(), id]);
        },

        async markError(id, message) {
            vidalsaDB.run(
                "UPDATE outbox SET status = 'error', tries = tries + 1, last_error = ? WHERE id = ?",
                [String(message).slice(0, 500), id]
            );
        },

        /**
         * Intenta subir todas las operaciones pendientes en lote.
         * @returns {Promise<{uploaded:number, failed:number, total:number}>}
         */
        async tryUpload() {
            if (!navigator.onLine) return { uploaded: 0, failed: 0, total: 0, skipped: 'offline' };

            const items = await this.pending();
            if (items.length === 0) return { uploaded: 0, failed: 0, total: 0 };

            // Mapear a formato del endpoint
            const operations = items.map(it => ({
                id: it.id,
                op: it.op,
                endpoint: it.endpoint,
                body: it.body ? JSON.parse(it.body) : {},
            }));

            try {
                const res = await fetch('/sync/upload-outbox', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ operations }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();

                let uploaded = 0, failed = 0;
                vidalsaDB.exec('BEGIN');
                try {
                    for (const r of (data.results || [])) {
                        if (r.success) { await this.markSynced(r.id); uploaded++; }
                        else            { await this.markError(r.id, r.error || `HTTP ${r.status}`); failed++; }
                    }
                    vidalsaDB.exec('COMMIT');
                } catch (e) {
                    vidalsaDB.exec('ROLLBACK');
                    throw e;
                }
                await vidalsaDB.persist();

                if (window.showToast && uploaded > 0) {
                    window.showToast(`${uploaded} cambio(s) sincronizado(s)`, 'success');
                }
                return { uploaded, failed, total: items.length };
            } catch (e) {
                console.warn('[outbox] upload falló:', e.message);
                return { uploaded: 0, failed: items.length, total: items.length, error: e.message };
            }
        },

        /** Limpia operaciones ya sincronizadas (útil para no acumular en SQLite). */
        async cleanup() {
            await vidalsaDB.init();
            vidalsaDB.run("DELETE FROM outbox WHERE status = 'synced' AND synced_at < datetime('now', '-7 days')");
            await vidalsaDB.persist();
        },
    };

    window.vidalsaOutbox = vidalsaOutbox;
})();
