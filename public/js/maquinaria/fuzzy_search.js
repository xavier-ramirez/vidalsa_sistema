/* ════════════════════════════════════════════════════════════════════════════
   FuzzySearch — buscador "estilo Google" REUTILIZABLE.
   Normaliza acentos/mayúsculas, tokeniza descartando stopwords, tolera errores
   de tipeo (distancia de Levenshtein con tope) y RANKEA por relevancia.

   Es la ÚNICA fuente del algoritmo: lo usan el módulo de Inventario
   (/admin/almacen) y Recepción "Nueva entrada" (/admin/almacen/recepcion/nueva).
   Se carga desde el layout base (estructura_base) con cache-busting ?v=filemtime,
   por lo que vive en `window` y SOBREVIVE a la navegación SPA (a diferencia del
   JS inline de cada vista). Expone window.FuzzySearch.
   ════════════════════════════════════════════════════════════════════════════ */
(function (w) {
    'use strict';

    // Rango de marcas diacríticas combinantes (NFD las separa de su letra base).
    var DIACRITICS = new RegExp('[\\u0300-\\u036f]', 'g');

    // Normaliza: sin acentos + minúsculas. Base de todas las comparaciones.
    function norm(s) {
        return s ? String(s).normalize('NFD').replace(DIACRITICS, '').toLowerCase() : '';
    }

    // Palabras vacías que solo meten ruido en la búsqueda.
    var STOPWORDS = { de:1, del:1, la:1, el:1, los:1, las:1, un:1, una:1,
                      unos:1, unas:1, y:1, e:1, o:1, u:1, a:1, en:1, con:1,
                      para:1, por:1 };

    // Tokeniza: normaliza, separa por espacios y descarta stopwords. Si TODO era
    // stopword, devuelve los tokens crudos (no deja la búsqueda vacía).
    function tokenize(raw) {
        var crudos = norm(raw || '').split(/\s+/).filter(Boolean);
        var sig = crudos.filter(function (t) { return !STOPWORDS[t]; });
        return sig.length ? sig : crudos;
    }

    // Distancia de Levenshtein con tope: corta temprano si la edición mínima supera
    // `max` (devuelve max+1) para no calcular distancias irrelevantes.
    function leven(a, b, max) {
        var la = a.length, lb = b.length;
        if (la === 0) return lb;
        if (lb === 0) return la;
        if (Math.abs(la - lb) > max) return max + 1;
        var prev = [], cur = [], i, j;
        for (j = 0; j <= lb; j++) prev[j] = j;
        for (i = 1; i <= la; i++) {
            cur[0] = i;
            var best = i;
            for (j = 1; j <= lb; j++) {
                var cost = a.charCodeAt(i - 1) === b.charCodeAt(j - 1) ? 0 : 1;
                cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
                if (cur[j] < best) best = cur[j];
            }
            if (best > max) return max + 1;          // fila entera supera el tope
            for (j = 0; j <= lb; j++) prev[j] = cur[j];
        }
        return prev[lb];
    }

    // Puntúa un token contra el texto: substring fuerte (con bonus por inicio de
    // palabra / palabra completa / número) o fuzzy débil (Levenshtein). Devuelve
    // { score, hit }.
    // entero: un número corto (1-2 cifras) junto a otras palabras cuenta solo ENTERO, como en
    // el nombre y el código en la tabla de Almacén (almacen_local.js · entero): «braga 42» no
    // sugiere la talla 142 ni el 2 de 1/2". Solo con rank(…, { enteros: true }) (productos);
    // la cola de un nº de parte («filtro 31» → MIS0531) aquí no cuenta: el haystack mezcla
    // código y partes, y el 42 del código 000142 no debe sumar.
    function posEntera(hay, token) {
        for (var i = hay.indexOf(token); i > -1; i = hay.indexOf(token, i + 1)) {
            var a = hay.charAt(i - 1), a2 = hay.charAt(i - 2), d = hay.charAt(i + token.length), d2 = hay.charAt(i + token.length + 1);
            var dig = function (c) { return c >= '0' && c <= '9'; };
            var sep = function (c) { return c === '.' || c === ',' || c === '/'; };
            if (dig(a) || (sep(a) && dig(a2)) || dig(d) || (sep(d) && dig(d2))) continue;
            return i;
        }
        return -1;
    }
    function scoreToken(palabras, hayFull, token, entero) {
        var esNum = /^\d+$/.test(token);
        var idx = entero && /^\d{1,2}$/.test(token) ? posEntera(hayFull, token) : hayFull.indexOf(token);
        if (idx > -1) {
            var s = 12;
            if (idx === 0) s += 12;
            else if (hayFull.charAt(idx - 1) === ' ') s += 9;
            if (esNum) s += 8;
            for (var wi = 0; wi < palabras.length; wi++) {
                if (palabras[wi] === token) { s += 10; break; }
            }
            return { score: s, hit: true };
        }
        if (esNum) return { score: 0, hit: false };
        var tol = token.length <= 2 ? 1 : (token.length <= 5 ? 2 : 3);
        var mejor = tol + 1;
        for (var i = 0; i < palabras.length; i++) {
            var word = palabras[i];
            if (!word) continue;
            var d = leven(token, word, tol);
            if (d < mejor) mejor = d;
            if (word.length > token.length) {
                var dp = leven(token, word.substr(0, token.length), tol);
                if (dp < mejor) mejor = dp;
            }
            if (mejor === 0) break;
        }
        if (mejor <= tol) return { score: 8 - mejor * 2, hit: true };
        return { score: 0, hit: false };
    }

    /**
     * Rankea `items` por relevancia contra `rawTerm`.
     *   accessor(item) → { haystack, label }
     *     haystack: texto completo donde buscar (p.ej. "CODIGO NOMBRE")
     *     label:    etiqueta principal (p.ej. NOMBRE) — usada en los bonus de frase
     *               completa y nombre corto, y como desempate alfabético.
     * Un item entra si coincide al menos la MITAD de los tokens (así un typo o una
     * palabra de más no lo descarta). Devuelve el array de items ordenado por score
     * descendente. Con término vacío devuelve los items tal cual (orden original);
     * el caller decide el límite y cualquier dedupe/filtrado adicional.
     */
    function rank(items, rawTerm, accessor, opts) {
        items = items || [];
        var tokens = tokenize(rawTerm);
        if (!tokens.length) return items.slice();

        var rawNorm = norm(rawTerm).replace(/\s+/g, ' ');
        var minTokens = Math.ceil(tokens.length / 2);
        var enteros = !!(opts && opts.enteros) && tokens.length > 1;
        var scored = [];

        for (var j = 0; j < items.length; j++) {
            var acc = accessor(items[j]) || {};
            var rawLabel = acc.label || '';
            var hayFull = norm(acc.haystack || '');
            var nom = norm(rawLabel);
            var palabras = hayFull.split(/\s+/).filter(Boolean);

            var total = 0, matched = 0;
            for (var k = 0; k < tokens.length; k++) {
                var r = scoreToken(palabras, hayFull, tokens[k], enteros);
                if (r.hit) { matched++; total += r.score; }
            }
            if (matched < minTokens) continue;

            if (matched === tokens.length) total += 35;
            if (rawNorm && nom.indexOf(rawNorm) > -1) total += 40;
            var consec = 0;
            for (var ci = 0; ci < tokens.length - 1; ci++) {
                if (hayFull.indexOf(tokens[ci] + ' ' + tokens[ci + 1]) > -1) consec++;
            }
            total += consec * 12;
            total += Math.max(0, 25 - nom.length * 0.2);

            scored.push({ p: items[j], score: total, label: rawLabel });
        }

        scored.sort(function (a, b) {
            if (b.score !== a.score) return b.score - a.score;
            return String(a.label).localeCompare(String(b.label));
        });
        return scored.map(function (x) { return x.p; });
    }

    // Devuelve el nº de parte de `partes` que COINCIDE con `rawTerm` (normalizado sin
    // espacios/guiones, en ambos sentidos), o `fallback` si ninguno coincide. Lo usan los
    // autocompletes de almacén (inventario, movimientos, recepción/bandeja, recepción/nueva)
    // para mostrar delante del nombre el nº de parte alterno por el que salió el producto —
    // antes este bloque estaba copiado en las 4 vistas (una sola fuente de verdad aquí).
    function matchedPart(rawTerm, partes, fallback) {
        fallback = fallback || '';
        if (!rawTerm || !Array.isArray(partes) || !partes.length) return fallback;
        var tN = norm(rawTerm).replace(/[\s\-]/g, '');
        if (!tN) return fallback;
        for (var i = 0; i < partes.length; i++) {
            var eN = norm(String(partes[i])).replace(/[\s\-]/g, '');
            if (eN && (eN.indexOf(tN) !== -1 || tN.indexOf(eN) !== -1)) return partes[i];
        }
        return fallback;
    }

    /**
     * ¿`texto` sirve para lo escrito? Para FILTRAR listas (uicomponents.js ·
     * filterDropdownOptions: Frente, Tipo… de todos los módulos). Cada palabra escrita tiene
     * que estar, en cualquier orden y sin importar acentos ni espacios dobles («tramo i
     * veladero» → TUBERÍA DE 30'' VELADERO TRAMO I). Más estricto que rank, que deja entrar
     * con la mitad de las palabras porque ordena: aquí no se ordena, se quita.
     *   - Una palabra de 4 letras o más tolera una letra mal (dos desde 7): «carabbo». Dos
     *     letras vecinas cambiadas de sitio cuentan como una («camoin» → CAMION).
     *     Con 3 o menos, no: «v» o «cam» con una letra de margen dejarían entrar casi todo.
     *   - Un número de 1-2 cifras junto a otras palabras cuenta entero (posEntera): «brazo 6
     *     ton» no trae el de 16 TON. Solo, es un trozo, como siempre («1» → TRAMO 1, 12'').
     * exacto: sin margen y CON las palabras cortas (de, con…). El que filtra una lista prueba
     * primero así y solo si nada sale usa el margen y las salta: «cama» trae CAMA BAJA y no
     * también los CAMION por «cami»; «camion con» trae los CAMION CON…, no todos los CAMION;
     * «camion de volteo» sigue encontrando CAMION VOLTEO.
     */
    function coincide(rawTerm, texto, exacto) {
        var tokens = exacto ? norm(rawTerm || '').split(/\s+/).filter(Boolean) : tokenize(rawTerm);
        if (!tokens.length) return true;
        var hay = norm(texto).replace(/\s+/g, ' ');
        var palabras = hay.split(/[\s\/\-.,()'"]+/).filter(Boolean);
        var enteros = tokens.length > 1;
        return tokens.every(function (t) {
            if (enteros && /^\d{1,2}$/.test(t)) return posEntera(hay, t) > -1;
            if (hay.indexOf(t) > -1) return true;
            if (exacto || t.length < 4 || /\d/.test(t)) return false;
            var tol = t.length <= 6 ? 1 : 2;
            return palabras.some(function (p) {
                var inicio = p.substr(0, t.length);
                return leven(t, p, tol) <= tol || vecinasCambiadas(t, inicio)
                    || (p.length > t.length && leven(t, inicio, tol) <= tol);
            });
        });
    }
    // «camoin» / «camion»: iguales salvo dos letras vecinas intercambiadas (Levenshtein lo
    // cuenta como dos cambios).
    function vecinasCambiadas(a, b) {
        if (a.length !== b.length) return false;
        for (var i = 0; i < a.length - 1; i++) {
            if (a.charAt(i) !== b.charAt(i)) {
                return a.charAt(i) === b.charAt(i + 1) && a.charAt(i + 1) === b.charAt(i)
                    && a.substr(i + 2) === b.substr(i + 2);
            }
        }
        return false;
    }

    w.FuzzySearch = { norm: norm, tokenize: tokenize, leven: leven, scoreToken: scoreToken, rank: rank, matchedPart: matchedPart, coincide: coincide };
})(window);
