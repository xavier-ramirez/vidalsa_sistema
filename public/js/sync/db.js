/* global initSqlJs */
/**
 * Wrapper de SQLite (sql.js) para la PWA Vidalsa.
 *
 * - Carga sql.js desde CDN (no requiere headers Cross-Origin-* del servidor).
 * - Persiste la base completa en IndexedDB (clave 'vidalsa-sqlite-blob').
 * - Crea/actualiza esquema espejo de las tablas Laravel.
 * - Expone window.vidalsaDB con métodos query/run/save/etc.
 *
 * Uso:
 *   await window.vidalsaDB.init();
 *   const equipos = window.vidalsaDB.query('SELECT * FROM equipos WHERE ID_FRENTE_ACTUAL = ?', [3]);
 *   window.vidalsaDB.run('INSERT INTO outbox (op, endpoint, body, created_at) VALUES (?, ?, ?, ?)', [...]);
 *   await window.vidalsaDB.persist();   // Guarda la BD a IndexedDB
 */

(function () {
    'use strict';

    // ─── Constantes ──────────────────────────────────────────────────────
    const SQL_JS_VERSION = '1.10.3';
    const SQL_JS_CDN     = `https://cdnjs.cloudflare.com/ajax/libs/sql.js/${SQL_JS_VERSION}`;
    const IDB_NAME       = 'vidalsa-sqlite';
    const IDB_STORE      = 'kv';
    const IDB_KEY        = 'vidalsa-sqlite-blob';
    const SCHEMA_VERSION = 1;

    // ─── Esquema (espejo de tablas Laravel + locales) ─────────────────────
    const SCHEMA_SQL = `
        CREATE TABLE IF NOT EXISTS meta (
            key TEXT PRIMARY KEY,
            value TEXT
        );

        CREATE TABLE IF NOT EXISTS user_session (
            id INTEGER PRIMARY KEY,
            correo TEXT NOT NULL,
            nombre TEXT,
            nivel INTEGER,
            permisos TEXT,
            frentes TEXT,
            password_hash TEXT,
            puede_editar INTEGER DEFAULT 0,
            puede_movilizar INTEGER DEFAULT 0,
            puede_estado INTEGER DEFAULT 0,
            es_super_admin INTEGER DEFAULT 0,
            last_login_at TEXT,
            last_synced_at TEXT
        );

        CREATE TABLE IF NOT EXISTS equipos (
            ID_EQUIPO INTEGER PRIMARY KEY,
            CODIGO_PATIO TEXT,
            TIPO_EQUIPO TEXT,
            CATEGORIA_FLOTA TEXT,
            MARCA TEXT,
            MODELO TEXT,
            ANIO INTEGER,
            SERIAL_CHASIS TEXT,
            SERIAL_DE_MOTOR TEXT,
            NUMERO_ETIQUETA TEXT,
            ESTADO_OPERATIVO TEXT,
            ID_FRENTE_ACTUAL INTEGER,
            DETALLE_UBICACION_ACTUAL TEXT,
            ID_ESPEC INTEGER,
            LINK_GPS TEXT,
            ID_ANCLAJE INTEGER,
            FOTO_EQUIPO TEXT,
            id_tipo_equipo INTEGER,
            tipo_nombre TEXT,
            frente_nombre TEXT,
            foto_referencial TEXT,
            created_at TEXT,
            updated_at TEXT,
            last_synced_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_equipos_frente ON equipos(ID_FRENTE_ACTUAL);
        CREATE INDEX IF NOT EXISTS idx_equipos_tipo   ON equipos(id_tipo_equipo);
        CREATE INDEX IF NOT EXISTS idx_equipos_estado ON equipos(ESTADO_OPERATIVO);

        CREATE TABLE IF NOT EXISTS documentacion (
            ID_EQUIPO INTEGER PRIMARY KEY,
            NRO_DE_DOCUMENTO TEXT,
            NOMBRE_DEL_TITULAR TEXT,
            PLACA TEXT,
            LINK_DOC_PROPIEDAD TEXT,
            LINK_POLIZA_SEGURO TEXT,
            FECHA_VENC_POLIZA TEXT,
            LINK_ROTC TEXT,
            FECHA_ROTC TEXT,
            LINK_RACDA TEXT,
            FECHA_RACDA TEXT,
            ID_SEGURO INTEGER,
            last_synced_at TEXT
        );

        CREATE TABLE IF NOT EXISTS frentes_trabajo (
            ID_FRENTE INTEGER PRIMARY KEY,
            NOMBRE_FRENTE TEXT,
            TIPO_FRENTE TEXT,
            UBICACION TEXT,
            ESTATUS_FRENTE TEXT,
            last_synced_at TEXT
        );

        CREATE TABLE IF NOT EXISTS tipos_equipo (
            id INTEGER PRIMARY KEY,
            nombre TEXT,
            ROL_ANCLAJE TEXT,
            last_synced_at TEXT
        );

        CREATE TABLE IF NOT EXISTS caracteristicas_modelo (
            ID_ESPEC INTEGER PRIMARY KEY,
            MODELO TEXT,
            ANIO_ESPEC INTEGER,
            MOTOR TEXT,
            COMBUSTIBLE TEXT,
            CONSUMO_PROMEDIO TEXT,
            ACEITE_MOTOR TEXT,
            ACEITE_CAJA TEXT,
            LIGA_FRENO TEXT,
            REFRIGERANTE TEXT,
            TIPO_BATERIA TEXT,
            FOTO_REFERENCIAL TEXT,
            last_synced_at TEXT
        );

        CREATE TABLE IF NOT EXISTS movilizacion_historial (
            ID_MOVILIZACION INTEGER PRIMARY KEY,
            CODIGO_CONTROL TEXT,
            ID_EQUIPO INTEGER,
            ID_FRENTE_ORIGEN INTEGER,
            NOMBRE_ORIGEN TEXT,
            ID_FRENTE_DESTINO INTEGER,
            NOMBRE_DESTINO TEXT,
            FECHA_DESPACHO TEXT,
            TIPO_MOVIMIENTO TEXT,
            DETALLE_UBICACION TEXT,
            USUARIO_REGISTRO TEXT,
            last_synced_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_mov_equipo ON movilizacion_historial(ID_EQUIPO);
        CREATE INDEX IF NOT EXISTS idx_mov_fecha  ON movilizacion_historial(FECHA_DESPACHO);

        CREATE TABLE IF NOT EXISTS responsables (
            ID_ASIGNACION INTEGER PRIMARY KEY,
            ID_EQUIPO INTEGER,
            PERSONA_ASIGNADA TEXT,
            CEDULA_RESPONSABLE TEXT,
            FECHA_ASIGNACION TEXT,
            last_synced_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_resp_equipo ON responsables(ID_EQUIPO);

        CREATE TABLE IF NOT EXISTS outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            op TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            body TEXT,
            files TEXT,
            status TEXT DEFAULT 'pending',
            tries INTEGER DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL,
            synced_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_outbox_status ON outbox(status);
    `;

    // ─── IndexedDB helpers (para persistir el blob de SQLite) ─────────────
    function openIDB() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(IDB_NAME, 1);
            req.onupgradeneeded = () => {
                req.result.createObjectStore(IDB_STORE);
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    }
    async function idbGet(key) {
        const db = await openIDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(IDB_STORE, 'readonly');
            const req = tx.objectStore(IDB_STORE).get(key);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror   = () => reject(req.error);
        });
    }
    async function idbSet(key, value) {
        const db = await openIDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(IDB_STORE, 'readwrite');
            tx.objectStore(IDB_STORE).put(value, key);
            tx.oncomplete = () => resolve();
            tx.onerror    = () => reject(tx.error);
        });
    }

    // ─── Estado del wrapper ───────────────────────────────────────────────
    let _SQL = null;   // El módulo sql.js cargado
    let _db = null;    // Instancia de la BD
    let _initPromise = null;

    async function loadSqlJs() {
        if (window.initSqlJs) return; // Ya cargado
        await new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = `${SQL_JS_CDN}/sql-wasm.js`;
            s.async = true;
            s.onload  = resolve;
            s.onerror = () => reject(new Error('Falló carga de sql.js desde CDN'));
            document.head.appendChild(s);
        });
    }

    async function initInternal() {
        await loadSqlJs();
        _SQL = await initSqlJs({
            locateFile: file => `${SQL_JS_CDN}/${file}`
        });

        // Intentar cargar BD existente desde IndexedDB
        const existing = await idbGet(IDB_KEY);
        if (existing) {
            try {
                _db = new _SQL.Database(new Uint8Array(existing));
            } catch (e) {
                console.warn('[vidalsaDB] BD persistida corrupta, recreando:', e);
                _db = new _SQL.Database();
            }
        } else {
            _db = new _SQL.Database();
        }

        // Asegurar esquema (idempotente)
        _db.exec(SCHEMA_SQL);

        // Marcar versión
        _db.exec(`INSERT OR REPLACE INTO meta (key, value) VALUES ('schema_version', '${SCHEMA_VERSION}');`);
    }

    // ─── API pública ──────────────────────────────────────────────────────
    const vidalsaDB = {
        /** Inicializa la BD (idempotente — la 1ra carga descarga sql.js). */
        async init() {
            if (_initPromise) return _initPromise;
            _initPromise = initInternal();
            return _initPromise;
        },

        /** ¿La BD está inicializada y lista para usar? */
        isReady() { return _db !== null; },

        /**
         * Ejecuta una consulta SELECT y devuelve un array de objetos.
         * @param {string} sql
         * @param {Array} params
         */
        query(sql, params = []) {
            if (!_db) throw new Error('vidalsaDB no inicializada');
            const stmt = _db.prepare(sql);
            stmt.bind(params);
            const rows = [];
            while (stmt.step()) rows.push(stmt.getAsObject());
            stmt.free();
            return rows;
        },

        /**
         * Ejecuta una consulta de escritura (INSERT/UPDATE/DELETE).
         * @param {string} sql
         * @param {Array} params
         */
        run(sql, params = []) {
            if (!_db) throw new Error('vidalsaDB no inicializada');
            const stmt = _db.prepare(sql);
            stmt.bind(params);
            stmt.step();
            stmt.free();
        },

        /** Ejecuta múltiples sentencias SQL juntas. */
        exec(sql) {
            if (!_db) throw new Error('vidalsaDB no inicializada');
            return _db.exec(sql);
        },

        /** Persiste la BD entera a IndexedDB. Llama tras lotes de cambios. */
        async persist() {
            if (!_db) return;
            const data = _db.export();
            await idbSet(IDB_KEY, data.buffer);
        },

        /** Borra TODO (usar con cuidado). */
        async wipe() {
            if (_db) { _db.close(); _db = null; }
            await idbSet(IDB_KEY, null);
            _initPromise = null;
        },

        /** Helper para insertar/reemplazar una fila */
        upsert(table, row) {
            const cols = Object.keys(row);
            const placeholders = cols.map(() => '?').join(',');
            const sql = `INSERT OR REPLACE INTO ${table} (${cols.join(',')}) VALUES (${placeholders})`;
            this.run(sql, cols.map(c => row[c]));
        },

        /** Bulk upsert dentro de transacción. */
        bulkUpsert(table, rows) {
            if (!rows || !rows.length) return;
            this.exec('BEGIN');
            try {
                rows.forEach(r => this.upsert(table, r));
                this.exec('COMMIT');
            } catch (e) {
                this.exec('ROLLBACK');
                throw e;
            }
        },
    };

    window.vidalsaDB = vidalsaDB;
})();
