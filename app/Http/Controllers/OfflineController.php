<?php

namespace App\Http\Controllers;

use App\Services\KitAlmacenService;
use App\Support\OfflineVersion;
use Illuminate\Http\Request;

/**
 * Snapshot OFFLINE — copia de solo lectura de los KITS del almacén, para consultarlos sin
 * internet. El inventario, la bitácora y las notas van en la copia local del almacén
 * (SincronizacionController, /sync/almacen); equipos, auxiliares y movilizaciones, en la de
 * /sync/equipos.
 *
 * NO toca nada del flujo online: solo hace SELECT (jamás INSERT/UPDATE/DELETE).
 *
 * ── Dos modos de respuesta ─────────────────────────────────────────────────
 *   · SIN `?c=` → carga completa con la forma HISTÓRICA (clave `kits` en la raíz). Es lo
 *     que sigue pidiendo un cliente con el JS viejo en caché.
 *   · CON `?c=<cursor>` → `modos` dice qué hacer con cada dominio (`sin_cambios` | `full`)
 *     y `datos` trae las filas. Si nada cambió la respuesta pesa medio KB.
 *
 * Endpoints:
 *   · version():  barato, 0 consultas a MySQL (la huella va cacheada en disco).
 *   · snapshot(): los datos.
 *
 * @see \App\Support\OfflineVersion  huella del dominio
 */
class OfflineController extends Controller
{
    /**
     * Huella COMBINADA de los dominios que el usuario puede ver.
     *
     * Se conserva por COMPATIBILIDAD: un cliente con el offline-sync.js viejo en caché
     * sigue leyendo `version`. El cliente nuevo usa el mapa por dominio de `v`.
     */
    private function calcularVersion(Request $request): string
    {
        return substr(md5(implode('|', $this->versionesVisibles($request->user()))), 0, 12);
    }

    /**
     * Huellas SOLO de los dominios que este usuario realmente cachea: sin el módulo de
     * almacén no viaja nada, ni siquiera la huella (le haría bajar el snapshot cada vez que
     * alguien toca un kit... para recibir listas vacías).
     *
     * @return array<string,string>
     */
    private function versionesVisibles($user): array
    {
        return $this->puedeAlmacen($user) ? ['catalogos' => OfflineVersion::todas()['catalogos']] : [];
    }

    /**
     * ¿Viajan los kits a este teléfono? Requiere alguna clave almacen.* o super.admin.
     */
    private function puedeAlmacen($user): bool
    {
        return $user->can('almacen.productos') || $user->can('almacen.movimiento')
            || $user->can('almacen.nota.eliminar') || $user->can('almacen.nota.corregir')
            || $user->can('super.admin');
    }

    public function version(Request $request)
    {
        return response()->json([
            'version' => $this->calcularVersion($request),   // compatibilidad cliente viejo
            'e'       => OfflineVersion::ESQUEMA,
            'u'       => $request->user()?->getAuthIdentifier(),
            'v'       => $this->versionesVisibles($request->user()),
        ]);
    }

    public function snapshot(Request $request)
    {
        $user = $request->user();
        $puedeAlmacen = $this->puedeAlmacen($user);
        $cursor = $this->leerCursor($request);

        // Sin cursor → cliente viejo (o primera carga): forma histórica.
        if ($cursor === null) {
            return response()->json([
                'version'  => $this->calcularVersion($request),
                'generado' => now()->toIso8601String(),
                'kits'     => $this->consultaKits($puedeAlmacen),
            ]);
        }

        $visibles = $this->versionesVisibles($user);
        $modos = ['catalogos' => $this->modo($cursor, $visibles, $user?->getAuthIdentifier(), $puedeAlmacen)];

        // `catalogos` pesa pocos KB: cuando cambia viaja ENTERO. Eso lo libra de tener que
        // detectar borrados.
        $datos = $modos['catalogos'] === 'full' ? ['kits' => $this->consultaKits($puedeAlmacen)] : [];

        return response()->json([
            'version'  => $this->calcularVersion($request),
            'e'        => OfflineVersion::ESQUEMA,
            'u'        => $user?->getAuthIdentifier(),
            'generado' => now()->toIso8601String(),
            'v'        => $visibles,
            'modos'    => $modos,
            'datos'    => $datos,
            'borrados' => [],
            'c'        => [
                'e' => OfflineVersion::ESQUEMA,
                'u' => $user?->getAuthIdentifier(),
                'a' => (int) $puedeAlmacen,
                'v' => $visibles,
            ],
        ]);
    }

    /**
     * Lee y valida el cursor del query string. Devuelve null (→ carga completa con la
     * forma histórica) si no viene o si no es un objeto JSON.
     */
    private function leerCursor(Request $request): ?array
    {
        $crudo = $request->query('c');
        if (! is_string($crudo) || $crudo === '') {
            return null;
        }
        $c = json_decode($crudo, true);

        return is_array($c) ? $c : null;
    }

    /**
     * `sin_cambios` o `full`. Fuera de su alcance queda en `sin_cambios` (ni se consulta);
     * con otro esquema, otro usuario, otro acceso al módulo o una huella distinta, `full`.
     */
    private function modo(array $cursor, array $visibles, $userId, bool $puedeAlmacen): string
    {
        if (! isset($visibles['catalogos'])) {
            return 'sin_cambios';
        }
        $vale = ((int) ($cursor['e'] ?? 0)) === OfflineVersion::ESQUEMA
            && (string) ($cursor['u'] ?? '') === (string) $userId
            && (int) ($cursor['a'] ?? -1) === (int) $puedeAlmacen
            && ($cursor['v']['catalogos'] ?? null) === $visibles['catalogos'];

        return $vale ? 'sin_cambios' : 'full';
    }

    /**
     * KITS del almacén: EXACTAMENTE lo que la pantalla recibe online (KitAlmacenService::catalogo),
     * para que sin conexión se vean iguales. Sin su existencia: la pantalla la toma de la copia
     * local del almacén. Solo con el módulo.
     */
    private function consultaKits(bool $puedeAlmacen)
    {
        return $puedeAlmacen ? app(KitAlmacenService::class)->catalogo() : collect();
    }
}
