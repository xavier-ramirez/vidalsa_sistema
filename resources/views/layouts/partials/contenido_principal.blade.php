{{-- El <main> de la app: los avisos flash y el contenido del modulo. Lo usan la pagina
     completa y la respuesta corta de la navegacion SPA (ver layouts/estructura_base). --}}
    <!-- Main Content Area -->
    <main class="main-viewport transition-fade">
        @if(session('success'))
            <script>
                window.addEventListener('load', () => {
                    if (window.showToast) {
                        window.showToast(@json(session('success')), 'success');
                    }
                });
            </script>
        @endif

        {{-- Bridge Blade -> sessionStorage: si el backend redirigio via
             redirect()->back()->with('flash_toast', [...]) (ej. handler 403
             global en bootstrap/app.php), tomamos ese flash y lo movemos a
             sessionStorage para que el script siguiente lo renderice como
             toast en lugar del modal feo default. --}}
        @if(session('flash_toast'))
            @php $ft = session('flash_toast'); @endphp
            <script>
                (function () {
                    try {
                        sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                            message: @json($ft['message'] ?? ''),
                            type:    @json($ft['type'] ?? 'error'),
                        }));
                    } catch (_) {}
                })();
            </script>
        @endif

        {{-- Flash toast desde sessionStorage (post-redirect en flujos AJAX/SPA).
             Permite mostrar la notificacion en la pagina destino sin parpadeo
             cuando el form origen redirigio via JS (ej: equipos edit, catalogo). --}}
        <script>
            (function () {
                function _flushFlashToast() {
                    try {
                        var raw = sessionStorage.getItem('vidalsa_flash_toast');
                        if (!raw) return;
                        sessionStorage.removeItem('vidalsa_flash_toast');
                        var data = JSON.parse(raw);
                        if (!data || !data.message) return;
                        var tryShow = function () {
                            if (typeof window.showToast === 'function') {
                                window.showToast(data.message, data.type || 'success');
                            } else {
                                setTimeout(tryShow, 80);
                            }
                        };
                        tryShow();
                    } catch (_) { /* silencioso */ }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', _flushFlashToast);
                } else {
                    _flushFlashToast();
                }
                // En navegaciones SPA, leer el toast nuevo del destino. El flag
                // window.__vidalsaRedirecting lo libera loadPage() en su finally
                // (punto único, cubre éxito y error); no se toca aquí para no duplicar.
                // Este <script> vive dentro de <main> y la SPA lo re-ejecuta en cada
                // navegación: sin el guard sumaba un listener por módulo visitado.
                if (!window.__flashToastSpaBound) {
                    window.__flashToastSpaBound = true;
                    window.addEventListener('spa:contentLoaded', _flushFlashToast);
                }
            })();
        </script>

        @yield('content')
    </main>
