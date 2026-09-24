/*
 * catalogo_vista.js
 *
 * Este codigo vivia dentro del HTML de la vista y viajaba entero en CADA apertura del
 * modulo. Aqui se baja una sola vez y el navegador lo reutiliza.
 *
 * Es una FUNCION de arranque, no un bloque suelto, porque el modulo necesita volver a
 * correr en cada apertura para engancharse a la tabla nueva: la SPA no re-ejecuta un
 * <script src> ya cargado, asi que es el Blade quien llama a catalogoArrancar(CFG)
 * cada vez que se monta la pantalla. Lo que cambia entre una apertura y otra —rutas,
 * permisos y catalogos— llega en ese CFG.
 */
window.catalogoArrancar = function (CAT_CFG) {
    CAT_CFG = CAT_CFG || {};
    function catSubmit() {
        if (typeof window.loadCatalogo === 'function') {
            window.loadCatalogo();
        } else {
            document.getElementById('catalogoFilters').submit();
        }
    }
    function _catCap(p) {
        if (p === 'modelo') return 'Modelo';
        if (p === 'marca')  return 'Marca';
        if (p === 'anio')   return 'Anio';
        if (p === 'tipo')   return 'Tipo';
        return p;
    }
    function catOpenList(p) {
        var l = document.getElementById('catList' + _catCap(p));
        if (!l) return;
        l.style.display = 'block';
        l.querySelectorAll('.cat-opt').forEach(function (o) { o.style.display = ''; });
    }
    function catCloseList(p) {
        var l = document.getElementById('catList' + _catCap(p));
        if (l) l.style.display = 'none';
    }
    function catFilterList(p, q) {
        var list = document.getElementById('catList' + _catCap(p));
        if (!list) return;
        list.style.display = 'block';
        var qu = (q || '').toUpperCase().trim();
        list.querySelectorAll('.cat-opt').forEach(function (opt) {
            if (opt.classList.contains('placeholder')) return;
            var lbl = (opt.dataset.label || '').toUpperCase();
            opt.style.display = (!qu || lbl.indexOf(qu) !== -1) ? '' : 'none';
        });
    }
    // Placeholder por defecto de cada filtro — se restaura al limpiar / elegir "TODOS".
    var CAT_PH_DEFAULT = { modelo: 'Filtrar Modelo...', marca: 'Filtrar Marca...', tipo: 'Filtrar Tipo...', anio: 'Filtrar Año...' };
    // Pinta un filtro con su valor, sin recargar (catSelect recarga).
    function catPintar(p, value, label) {
        var cap = _catCap(p);
        var hidden = document.getElementById('catVal' + cap);
        var txt    = document.getElementById('catTxt' + cap);
        if (hidden) hidden.value = value || '';
        // Igual que /admin/equipos: la opcion elegida queda como PLACEHOLDER
        // (texto de fondo) y el input se vacia — asi se escribe la siguiente
        // busqueda sin tener que borrar lo anterior.
        if (txt) {
            txt.value = '';
            txt.placeholder = value ? label : (CAT_PH_DEFAULT[p] || 'Filtrar...');
        }
        // El formulario de filtros no se re-renderiza en la recarga AJAX
        // (loadCatalogo solo reemplaza la tabla), asi que togglear aqui la
        // "x" de limpiar y el resaltado azul del filtro activo.
        var wrapper = hidden ? hidden.closest('.cat-filter') : null;
        if (wrapper) {
            wrapper.classList.toggle('active', !!value);
            var clearIcon = wrapper.querySelector('.filter-clear');
            if (clearIcon) clearIcon.style.display = value ? 'flex' : 'none';
        }
        // El Año vive en el panel de filtros avanzados: su botón va en rojo si está puesto.
        if (p === 'anio') {
            var adv = document.getElementById('catAdvBtn');
            if (adv) adv.classList.toggle('activo', !!value);
        }
        catCloseList(p);
    }
    function catSelect(p, value, label) {
        catPintar(p, value, label);
        // Otro Tipo: Modelo, Marca y Año ofrecen solo lo de ese tipo, y el que ya estaba
        // puesto se suelta si no es de él (si no, la búsqueda daría vacía sin decir por qué).
        if (p === 'tipo') catSyncTipo(true);
        catSubmit();
    }
    // Opción de una lista (data-value / data-label): así un valor con comillas no rompe nada.
    function catElegir(p, opt) {
        catSelect(p, opt.dataset.value, opt.dataset.label);
    }
    // Esconde en Modelo, Marca y Año las opciones que no son del Tipo elegido (data-tipos,
    // lo arma CaracteristicaModeloController::opcionesFiltro), y los grupos que quedan vacíos.
    // soltar = quitar el valor puesto que ya no está entre las opciones.
    function catSyncTipo(soltar) {
        var tipo = (document.getElementById('catValTipo') || {}).value || '';
        ['modelo', 'marca', 'anio'].forEach(function (p) {
            var list = document.getElementById('catList' + _catCap(p));
            if (!list) return;
            var puesto = (document.getElementById('catVal' + _catCap(p)) || {}).value || '', sigue = false;
            list.querySelectorAll('.cat-opt').forEach(function (o) {
                var fuera = !!tipo && (' ' + o.dataset.tipos + ' ').indexOf(' ' + tipo + ' ') === -1;
                o.classList.toggle('cat-opt-fuera', fuera);
                if (!fuera && o.dataset.value === puesto) sigue = true;
            });
            list.querySelectorAll('.cat-grupo').forEach(function (g) {
                g.hidden = !g.querySelector('.cat-opt:not(.cat-opt-fuera)');
            });
            if (soltar && puesto && !sigue) catPintar(p, '', '');
        });
    }
    catSyncTipo(false);

    // Panel de filtros avanzados. Sin stopPropagation: el clic sigue hasta document, donde se
    // cierran los demás desplegables (Tipo) — un desplegable a la vez.
    function catToggleAvanzado() {
        var p = document.getElementById('catAdvPanel');
        if (p) p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    }
    // Se cierra con un clic fuera o cuando el foco sale de él (Tab / "siguiente" del teclado
    // del teléfono). Listeners de document: una sola vez aunque la vista se vuelva a montar.
    if (!window.__catAdvCierreBound) {
        window.__catAdvCierreBound = true;
        var catCerrarAvanzadoSiFuera = function (e) {
            var p = document.getElementById('catAdvPanel'), t = e.target;
            if (p && p.style.display === 'block' && t && t.closest && !t.closest('#catAdvPanel') && !t.closest('#catAdvBtn')) {
                p.style.display = 'none';
            }
        };
        document.addEventListener('click', catCerrarAvanzadoSiFuera);
        document.addEventListener('focusin', catCerrarAvanzadoSiFuera);
    }

    // ── Helpers de subida (post-recorte) ──
    function _pickFileAndCrop(onCropped) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/jpg,image/png,image/webp';
        input.style.display = 'none';
        input.addEventListener('change', function () {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            if (file.size > 10 * 1024 * 1024) {
                window.toast('La foto supera los 10 MB.', 'error');
                return;
            }
            window._openCropModal(file, onCropped);
        });
        document.body.appendChild(input);
        input.click();
        setTimeout(function () { if (input.parentNode) document.body.removeChild(input); }, 1000);
    }

    function _uploadBlob(url, fd, onSuccess, onError) {
        var csrf = window.getCsrf();   // helper central (dom_helpers.js)
        if (typeof window.showPreloader === 'function') window.showPreloader();
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            method: 'POST',
            body: fd})
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) { onSuccess(res.body); }
            else { onError((res.body && res.body.message) || 'No se pudo subir la foto.'); }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            onError('Error de red al subir la foto.');
        });
    }

    // ── Subida AUXILIAR ──
    window.auxCatUploadPhoto = function (photoEl) {
        var tipo = photoEl.dataset.tipo || '', marca = photoEl.dataset.marca || '',
            modelo = photoEl.dataset.modelo || '', anio = photoEl.dataset.anio || '';
        if (!tipo || !marca || !modelo) {
            window.toast('Este modelo no tiene marca registrada; no se puede asociar la foto.', 'error');
            return;
        }
        _pickFileAndCrop(function (croppedFile) {
            var fd = new FormData();
            fd.append('foto', croppedFile); fd.append('tipo', tipo); fd.append('marca', marca); fd.append('modelo', modelo);
            if (anio) fd.append('anio', anio);
            _uploadBlob(CAT_CFG.rutaEquiposAuxiliaresCatalogoUploadPhoto, fd,
                function (body) {
                    window.toast(body.message || 'Foto actualizada.', 'success');
                    if (body.foto) {
                        var img = photoEl.querySelector('img');
                        if (img) { img.src = body.foto; }
                        else {
                            var ph = photoEl.querySelector('.placeholder'); if (ph) ph.remove();
                            var n = document.createElement('img'); n.src = body.foto; n.alt = (marca + ' ' + modelo).trim();
                            n.onerror = function () { this.outerHTML = '<i class="material-icons placeholder">image_not_supported</i>'; };
                            photoEl.insertBefore(n, photoEl.firstChild);
                        }
                    }
                },
                function (msg) { window.toast(msg, 'error'); }
            );
        });
    };

    // ── Colores de un VEHÍCULO ──
    // La tarjeta muestra la foto del modelo o la del color elegido en sus mini-tarjetas;
    // subir y borrar actúan sobre lo que se esté viendo (data-color de .cat-photo: '' = el modelo).
    function _catPintarFoto(photoEl, url) {
        var color = photoEl.dataset.color || '';
        var actual = photoEl.querySelector('img, .placeholder');
        if (actual) actual.remove();
        var nodo;
        if (url) {
            nodo = document.createElement('img');
            nodo.src = url; nodo.alt = '';
            nodo.style.cssText = 'opacity:1; width:100%; height:100%; object-fit:contain; background:#f8fafc;';
            nodo.onerror = function () { this.outerHTML = '<i class="material-icons placeholder">image_not_supported</i>'; };
        } else {
            nodo = document.createElement('i');
            nodo.className = 'material-icons placeholder';
            nodo.textContent = 'precision_manufacturing';
        }
        photoEl.insertBefore(nodo, photoEl.firstChild);
        var txt = photoEl.querySelector('.cat-photo-overlay-txt');
        if (txt) txt.textContent = color ? (url ? 'Cambiar foto ' + color : 'Subir foto ' + color) : 'Cambiar foto';
        var del = photoEl.querySelector('.cat-del-photo');
        if (del) del.hidden = !url;
    }
    // La mini-tarjeta de lo que se está viendo ('' = Modelo) guarda su foto: tras subir o
    // borrar se actualiza ahí (dato y miniatura) para que volver a ella muestre lo correcto
    // sin recargar.
    function _catChipActivo(photoEl) {
        var color = photoEl.dataset.color || '';
        return photoEl.closest('.cat-card').querySelector('.cat-color[data-color="' + color + '"]');
    }
    function _catGuardarFotoVista(photoEl, url) {
        var chip = _catChipActivo(photoEl);
        if (photoEl.dataset.color) {
            if (chip) chip.dataset.foto = url || '';
        } else {
            photoEl.dataset.fotoModelo = url || '';
        }
        // Solo se cambia la imagen (o el ícono tachado) de la mini-tarjeta: el número de
        // unidades que va encima se queda.
        var caja = chip && chip.querySelector('.cat-color-foto');
        if (caja) {
            var previa = caja.querySelector('img, .cat-color-sinfoto');
            if (previa) previa.remove();
            var nodo;
            if (url) {
                nodo = document.createElement('img');
                nodo.src = url; nodo.alt = '';
            } else {
                nodo = document.createElement('i');
                nodo.className = 'material-icons cat-color-sinfoto';
                nodo.textContent = 'no_photography';
            }
            caja.insertBefore(nodo, caja.firstChild);
        }
        _catPintarFoto(photoEl, url);
    }
    // Tocar otra vez el color elegido vuelve a la foto del modelo: es la única forma de volver
    // a ella cuando la tarjeta no lleva la mini-tarjeta "Modelo" (todas sus unidades tienen color).
    window.catElegirColor = function (btn) {
        var card = btn.closest('.cat-card');
        var photoEl = card.querySelector('.cat-photo');
        if (btn.dataset.color && btn.classList.contains('activo')) {
            btn = card.querySelector('.cat-color[data-color=""]');   // "Modelo", si la tarjeta la lleva
        }
        card.querySelectorAll('.cat-color').forEach(function (b) { b.classList.toggle('activo', b === btn); });
        photoEl.dataset.color = btn ? (btn.dataset.color || '') : '';
        _catPintarFoto(photoEl, photoEl.dataset.color ? (btn.dataset.foto || '') : (photoEl.dataset.fotoModelo || ''));
    };

    // Fila de colores: las flechas se ven solo si no caben todas, y se apagan en cada extremo.
    // Si caben se mide contra el ancho ENTERO (car), no el de la fila: con las flechas puestas
    // la fila es más angosta y, al ensancharse la pantalla, nunca se quitaban.
    window.catColoresFlechas = function (car) {
        var fila = car.querySelector('.cat-colores'), f = car.querySelectorAll('.cat-col-flecha');
        car.classList.toggle('con-flechas', fila.scrollWidth > car.clientWidth + 1);
        var max = fila.scrollWidth - fila.clientWidth;
        f[0].disabled = fila.scrollLeft <= 1;
        f[1].disabled = fila.scrollLeft >= max - 1;
    };
    // Pasa de a una "página": las mini-tarjetas que caben a la vista.
    window.catColoresMover = function (flecha, dir) {
        var fila = flecha.parentNode.querySelector('.cat-colores');
        var chip = fila.querySelector('.cat-color');
        if (!chip) return;
        var paso = chip.offsetWidth + 5;   // + gap de .cat-colores
        fila.scrollBy({ left: dir * paso * Math.max(1, Math.floor((fila.clientWidth + 5) / paso)) });
    };
    // Las tarjetas llegan por AJAX (filtros y scroll infinito, catalogo_vista.js): cada lote
    // nuevo se mide al entrar en la grilla. Cuando cambia el ANCHO de la grilla (al abrir,
    // ventana, giro del teléfono, aparece la barra de scroll), todas. Los dos observadores
    // son de esta grilla: al salir del módulo se van con ella.
    (function () {
        var grilla = document.getElementById('catalogoTableBody');
        if (!grilla) return;
        var medir = function (raiz) { raiz.querySelectorAll('.cat-colores-carrusel').forEach(function (c) { window.catColoresFlechas(c); }); };
        new MutationObserver(function (cambios) {
            cambios.forEach(function (c) {
                c.addedNodes.forEach(function (n) { if (n.querySelectorAll) medir(n); });
            });
        }).observe(grilla, { childList: true });
        var ancho = -1;
        new ResizeObserver(function (e) {
            var w = e[0].contentRect.width;
            if (w === ancho) return;   // al sumar tarjetas cambia solo el alto: nada que medir
            ancho = w;
            medir(grilla);
        }).observe(grilla);
    })();

    // Ficha de un modelo que solo tenía equipos (tarjeta SIN FICHA): la crea —o encuentra la
    // que ya hay— y le enlaza sus unidades. Resuelve con el id de la ficha.
    function _catAsegurarFicha(photoEl) {
        return window.apiPostForm(CAT_CFG.rutaCatalogoAsegurarFicha,
            { modelo: photoEl.dataset.modelo, anio: photoEl.dataset.anio, tipo: photoEl.dataset.tipo },
            'No se pudo crear la ficha.');
    }
    window.catCrearFicha = function (btn) {
        var photoEl = btn.closest('.cat-card').querySelector('.cat-photo');
        if (!photoEl.dataset.anio) {
            window.toast('Estos equipos no tienen año registrado; complétalo en Equipos para poder crear su ficha.', 'error');
            return;
        }
        btn.disabled = true;
        _catAsegurarFicha(photoEl)
            .then(function (body) {
                window.toast(body.message, 'success');
                // Recién creada: se abre para completar lo técnico. Si ya existía, basta con
                // recargar: sus unidades ya cuentan en su tarjeta.
                var editar = CAT_CFG.urlCatalogo + '/' + body.id + '/edit';
                if (body.creada && window.navigateTo) window.navigateTo(editar);
                else catSubmit();
            })
            .catch(function (e) { btn.disabled = false; window.toast(e.message, 'error'); });
    };

    // ── Borrar foto VEHÍCULO (solo super.admin): la del modelo o la del color que se ve ──
    window.catDeletePhoto = function (photoEl) {
        var id = photoEl.dataset.id, color = photoEl.dataset.color || '';
        if (!confirm(color ? '¿Eliminar la foto del color ' + color + '?' : '¿Eliminar la foto de este modelo?')) return;
        if (typeof window.showPreloader === 'function') window.showPreloader();
        var url = CAT_CFG.urlCatalogo + '/' + id + '/photo' + (color ? '?color=' + encodeURIComponent(color) : '');
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            method: 'DELETE'})
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) {
                window.toast(res.body.message || 'Foto eliminada.', 'success');
                _catGuardarFotoVista(photoEl, '');
            } else {
                window.toast((res.body && res.body.message) || 'No se pudo eliminar la foto.', 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            window.toast('Error de red al eliminar la foto.', 'error');
        });
    };

    // ── Borrar foto AUXILIAR (solo super.admin) ──
    window.auxCatDeletePhoto = function (photoEl) {
        if (!confirm('¿Eliminar la foto de este modelo auxiliar?')) return;
        var tipo = photoEl.dataset.tipo || '', marca = photoEl.dataset.marca || '',
            modelo = photoEl.dataset.modelo || '', anio = photoEl.dataset.anio || '';
        if (!tipo || !marca || !modelo) {
            window.toast('No se pudo identificar el modelo.', 'error');
            return;
        }
        var csrf = window.getCsrf();   // helper central (dom_helpers.js)
        var fd = new FormData();
        fd.append('_method', 'DELETE');
        fd.append('tipo', tipo); fd.append('marca', marca); fd.append('modelo', modelo);
        if (anio) fd.append('anio', anio);
        if (typeof window.showPreloader === 'function') window.showPreloader();
        window.apiFetch(CAT_CFG.rutaEquiposAuxiliaresCatalogoDeletePhoto, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            method: 'POST',
            body: fd})
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) {
                window.toast(res.body.message || 'Foto eliminada.', 'success');
                if (photoEl) {
                    var img = photoEl.querySelector('img');
                    if (img) img.outerHTML = '<i class="material-icons placeholder">construction</i>';
                    var delBtn = photoEl.querySelector('.cat-del-photo');
                    if (delBtn) delBtn.remove();
                }
            } else {
                window.toast((res.body && res.body.message) || 'No se pudo eliminar la foto.', 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            window.toast('Error de red al eliminar la foto.', 'error');
        });
    };

    // ── Subida VEHÍCULO: la foto del modelo o la del color que se está viendo ──
    // En una tarjeta SIN FICHA primero se asegura la ficha (y se enlazan sus unidades); al
    // terminar se recarga la lista, porque la tarjeta pasa a ser la de una ficha.
    window.catUploadPhoto = function (photoEl) {
        var sinFicha = photoEl.dataset.sinFicha === '1';
        if (sinFicha && !photoEl.dataset.anio) {
            window.toast('Estos equipos no tienen año registrado; complétalo en Equipos para poder crear su ficha.', 'error');
            return;
        }
        _pickFileAndCrop(function (croppedFile) {
            var subir = function (id) {
                var fd = new FormData();
                fd.append('foto', croppedFile);
                if (photoEl.dataset.color) fd.append('color', photoEl.dataset.color);
                _uploadBlob(CAT_CFG.urlCatalogo + '/' + id + '/photo', fd,
                    function (body) {
                        window.toast(body.message || 'Foto actualizada correctamente.', 'success');
                        if (sinFicha) { catSubmit(); return; }
                        // body.foto es la ruta guardada; la tarjeta usa la miniatura.
                        var idDrive = String(body.foto || '').replace(/^.*\/storage\/google\//, '').split('?')[0];
                        _catGuardarFotoVista(photoEl, idDrive ? '/storage/google/' + idDrive + '?sz=w300' : '');
                    },
                    function (msg) { window.toast(msg, 'error'); }
                );
            };
            if (!sinFicha) { subir(photoEl.dataset.id); return; }
            _catAsegurarFicha(photoEl).then(function (body) { subir(body.id); })
                .catch(function (e) { window.toast(e.message, 'error'); });
        });
    };

    // Estas funciones vivian sueltas en el <script> del HTML, asi que el navegador
    // las dejaba en window y los onclick= de la pagina las llaman por su nombre.
    // Aqui, dentro del arranque, serian privadas: se vuelven a publicar.
    window.catSubmit = catSubmit;
    window._catCap = _catCap;
    window.catOpenList = catOpenList;
    window.catCloseList = catCloseList;
    window.catFilterList = catFilterList;
    window.catPintar = catPintar;
    window.catSelect = catSelect;
    window.catElegir = catElegir;
    window.catSyncTipo = catSyncTipo;
    window.catToggleAvanzado = catToggleAvanzado;
    window._pickFileAndCrop = _pickFileAndCrop;
    window._uploadBlob = _uploadBlob;
    window._catPintarFoto = _catPintarFoto;
    window._catChipActivo = _catChipActivo;
    window._catGuardarFotoVista = _catGuardarFotoVista;
    window._catAsegurarFicha = _catAsegurarFicha;
};
