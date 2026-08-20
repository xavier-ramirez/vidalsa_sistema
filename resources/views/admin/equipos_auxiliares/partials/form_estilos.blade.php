{{-- Estilos del formulario de equipo auxiliar. HOY LOS USA UNA SOLA VISTA:
     edit.blade.php. Estaban copiados byte a byte en create y edit; create.blade.php se
     borró después por no tener quien lo pintara, así que este archivo queda con un solo
     llamador y existe para que el <style> no viva dentro de la vista. --}}
<style>
    @media (max-width: 768px) {
        body:has(#formEquipoAuxiliarCard) .page-title-card {
            margin-bottom: 6px !important;
            padding: 4px 0 !important;
        }
        body:has(#formEquipoAuxiliarCard) .main-viewport {
            width: 100% !important;
            max-width: 100% !important;
            padding-left: 8px !important;
            padding-right: 8px !important;
            box-sizing: border-box !important;
        }
        body:has(#formEquipoAuxiliarCard) .admin-card {
            width: 100% !important;
            max-width: 100% !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
    }
</style>
