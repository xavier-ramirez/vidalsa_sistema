/* global vidalsaDB, dcodeIO */
/**
 * Autenticación OFFLINE.
 *
 * Compara el password ingresado contra el hash bcrypt guardado en SQLite
 * (capturado durante el último login web exitoso via /sync/login-local-init).
 *
 * Requiere bcryptjs cargado (lo carga este mismo archivo desde CDN).
 *
 * Uso:
 *   const ok = await vidalsaAuthOffline.verify('correo@ejemplo.com', 'pass123');
 *   if (ok) { ... entrar a app offline ... }
 */

(function () {
    'use strict';

    const BCRYPTJS_CDN = 'https://cdn.jsdelivr.net/npm/bcryptjs@2.4.3/dist/bcrypt.min.js';
    let _bcryptLoaded = null;

    function loadBcrypt() {
        if (_bcryptLoaded) return _bcryptLoaded;
        _bcryptLoaded = new Promise((resolve, reject) => {
            if (window.dcodeIO && window.dcodeIO.bcrypt) return resolve(window.dcodeIO.bcrypt);
            const s = document.createElement('script');
            s.src = BCRYPTJS_CDN;
            s.async = true;
            s.onload  = () => resolve(window.dcodeIO?.bcrypt || window.bcrypt);
            s.onerror = () => reject(new Error('Falló carga de bcryptjs'));
            document.head.appendChild(s);
        });
        return _bcryptLoaded;
    }

    /**
     * Convierte un hash bcrypt de Laravel ($2y$...) al formato compatible con
     * bcryptjs ($2a$...). bcryptjs no entiende $2y$ pero internamente $2y$ y
     * $2a$ son intercambiables porque son alias del mismo algoritmo.
     */
    function normalizeHash(hash) {
        if (!hash) return '';
        return hash.replace(/^\$2y\$/, '$2a$');
    }

    const vidalsaAuthOffline = {
        /** ¿Hay credenciales locales guardadas para login offline? */
        async hasLocalCredentials() {
            await vidalsaDB.init();
            const r = vidalsaDB.query("SELECT id FROM user_session LIMIT 1");
            return r.length > 0;
        },

        /** Devuelve el correo del usuario cacheado o null. */
        async cachedEmail() {
            await vidalsaDB.init();
            const r = vidalsaDB.query("SELECT correo FROM user_session LIMIT 1");
            return r.length ? r[0].correo : null;
        },

        /**
         * Verifica correo + password contra el cache local.
         * @returns {Promise<{ok:boolean, user?:object, error?:string}>}
         */
        async verify(correo, password) {
            try {
                await vidalsaDB.init();
                const rows = vidalsaDB.query(
                    "SELECT * FROM user_session WHERE correo = ? LIMIT 1",
                    [String(correo).trim()]
                );
                if (!rows.length) {
                    return { ok: false, error: 'No hay credenciales locales para ese correo. Necesitas conectarte a Internet la primera vez.' };
                }
                const user = rows[0];
                const hash = normalizeHash(user.password_hash || '');
                if (!hash) {
                    return { ok: false, error: 'Las credenciales locales están incompletas. Conéctate a Internet.' };
                }

                const bcrypt = await loadBcrypt();
                const match = await new Promise((resolve, reject) => {
                    bcrypt.compare(password, hash, (err, ok) => err ? reject(err) : resolve(ok));
                });

                if (!match) return { ok: false, error: 'Correo o contraseña incorrecta.' };

                // Actualizar last_login_at local
                vidalsaDB.run("UPDATE user_session SET last_login_at = ? WHERE id = ?", [new Date().toISOString(), user.id]);
                await vidalsaDB.persist();

                return {
                    ok: true,
                    user: {
                        id: user.id,
                        correo: user.correo,
                        nombre: user.nombre,
                        nivel: user.nivel,
                        permisos: JSON.parse(user.permisos || '[]'),
                        frentes: JSON.parse(user.frentes || '[]'),
                        puede_editar:    !!user.puede_editar,
                        puede_movilizar: !!user.puede_movilizar,
                        puede_estado:    !!user.puede_estado,
                        es_super_admin:  !!user.es_super_admin,
                    },
                };
            } catch (e) {
                return { ok: false, error: e.message };
            }
        },

        /** Borra credenciales locales (cierre de sesión offline). */
        async logout() {
            await vidalsaDB.init();
            vidalsaDB.exec("DELETE FROM user_session");
            await vidalsaDB.persist();
        },
    };

    window.vidalsaAuthOffline = vidalsaAuthOffline;
})();
