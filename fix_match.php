<?php
$file = 'public/js/maquinaria/consumibles_index.js';
$content = file_get_contents($file);

$oldBlock = <<<'OLDJS'
            // ── Match automático ──────────────────────────────────────
            window.ejecutarMatch = function() {
                var btn      = document.getElementById('btnMatch');
                if (!btn) return;
                var progress = document.getElementById('matchProgress');
                var bar      = document.getElementById('matchBar');
                var results  = document.getElementById('matchResults');
                var body     = document.getElementById('matchResultsBody');
                var routeMatch = appRoot.dataset.routeMatch;

                btn.disabled = true;
                btn.innerHTML = '<i class="material-icons" style="font-size:20px; animation:spin 1s linear infinite;">refresh</i> Procesando...';
                if (progress) progress.style.display = 'block';
                if (results)  results.style.display  = 'none';

                var pct = 0;
                var ticker = setInterval(function() {
                    pct = Math.min(pct + 3, 85);
                    if (bar) bar.style.width = pct + '%';
                }, 100);

                fetch(routeMatch, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': window.CSRF, 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    clearInterval(ticker);
                    if (bar) bar.style.width = '100%';
                    var p  = document.getElementById('cnt-pendientes');  if (p)  p.textContent  = 0;
                    var c  = document.getElementById('cnt-confirmados'); if (c)  c.textContent  = data.confirmados;
                    var sm = document.getElementById('cnt-sinmatch');    if (sm) sm.textContent = data.sin_match;

                    if (body) {
                        var modoLabels = {
                            'placa':          '🔵 placa exacta (doc.)',
                            'placa_parcial':  '🟣 placa parcial (doc.)',
                            'codigo_patio':   '🔷 código patio',
                            'serial_exacto':  '🟢 serial exacto',
                            'serial_parcial': '🟡 serial parcial',
                        };
                        body.innerHTML = data.detalle.map(function(r) {
                            var modoLabel = modoLabels[r.modo] || (r.modo ? r.modo : 'sin identificador');
                            return '<div class="match-result-row">' +
                                '<span class="mr-id">' + r.identificador + '</span>' +
                                (r.estado === 'CONFIRMADO'
                                    ? '<span class="mr-match">✓ ' + r.match + ' <span style="opacity:.6;font-size:11px;">(' + modoLabel + ')</span></span>'
                                    : '<span class="mr-none">✗ Sin coincidencia <span style="opacity:.6;font-size:11px;">(buscado como: ' + modoLabel + ')</span></span>'
                                ) +
                            '</div>';
                        }).join('');
                    }
                    if (results) results.style.display = 'block';
                    btn.innerHTML = '<i class="material-icons" style="font-size:18px;">check_circle</i> Listo — ' + data.confirmados + ' confirmados, ' + data.sin_match + ' sin match';
                    btn.style.background = 'linear-gradient(135deg,#059669,#047857)';
                    setTimeout(function() { location.reload(); }, 2500);
                })
                .catch(function(err) {
                    clearInterval(ticker);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="material-icons" style="font-size:20px;">bolt</i> Reintentar Match';
                    btn.style.background = 'linear-gradient(135deg,#dc2626,#b91c1c)';
                    console.error('Error match:', err);
                });
            };
OLDJS;

$newBlock = <<<'NEWJS'
            // ── Match automático ──────────────────────────────────────
            window.ejecutarMatch = function() {
                var btn        = document.getElementById('btnMatch');
                var routeMatch = appRoot.dataset.routeMatch;

                if (btn) btn.disabled = true;
                if (window.showPreloader) window.showPreloader();

                fetch(routeMatch, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': window.CSRF, 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (window.hidePreloader) window.hidePreloader();

                    var confirmados = data.confirmados || 0;
                    var sinMatch    = data.sin_match   || 0;

                    var p  = document.getElementById('cnt-pendientes');  if (p)  p.textContent = 0;
                    var c  = document.getElementById('cnt-confirmados'); if (c)  c.textContent = confirmados;
                    var sm = document.getElementById('cnt-sinmatch');    if (sm) sm.textContent = sinMatch;

                    var msg = confirmados + ' equipos confirmados';
                    if (sinMatch > 0) msg += ' · ' + sinMatch + ' sin match';
                    if (window.showToast) window.showToast(msg, 'success');

                    setTimeout(function() {
                        if (window.submitConsumiblesFilters) {
                            window.submitConsumiblesFilters();
                        } else {
                            location.reload();
                        }
                    }, 1500);
                })
                .catch(function(err) {
                    if (window.hidePreloader) window.hidePreloader();
                    if (btn) btn.disabled = false;
                    console.error('Error match:', err);
                    if (window.showToast) window.showToast('Error al ejecutar match', 'error');
                });
            };
NEWJS;

if (strpos($content, $oldBlock) !== false) {
    $content = str_replace($oldBlock, $newBlock, $content);
    file_put_contents($file, $content);
    echo "consumibles_index.js updated OK\n";
} else {
    echo "Block not found - searching line by line...\n";
    // Show lines around ejecutarMatch
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'ejecutarMatch') !== false) {
            echo "Line " . ($i+1) . ": " . $line . "\n";
        }
    }
}
