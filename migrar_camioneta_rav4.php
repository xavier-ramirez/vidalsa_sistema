<?php
/**
 * Script: migrar_camioneta_rav4.php
 * 
 * Objetivo: Reasignar todos los equipos del tipo "CAMIONETA RAV4" (id=61)
 * al tipo "CAMIONETA" (id=1) y eliminar el tipo obsoleto.
 *
 * ANTES de ejecutar: Asegúrate de tener un backup de la BD.
 */

$host = '127.0.0.1';
$port = '3306';
$db   = 'cd';
$user = 'root';
$pass = '';

$ID_CAMIONETA_RAV4 = 61;
$ID_CAMIONETA      = 1;

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- VERIFICACIÓN PREVIA ---
    echo "=== VERIFICACIÓN PREVIA ===\n";

    $rav4 = $pdo->query("SELECT id, nombre FROM tipo_equipos WHERE id = $ID_CAMIONETA_RAV4")->fetch(PDO::FETCH_ASSOC);
    $cam  = $pdo->query("SELECT id, nombre FROM tipo_equipos WHERE id = $ID_CAMIONETA")->fetch(PDO::FETCH_ASSOC);

    if (!$rav4) {
        die("ERROR: No existe tipo con ID=$ID_CAMIONETA_RAV4 en tipo_equipos.\n");
    }
    if (!$cam) {
        die("ERROR: No existe tipo con ID=$ID_CAMIONETA en tipo_equipos.\n");
    }

    $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM equipos WHERE id_tipo_equipo = $ID_CAMIONETA_RAV4")->fetchColumn();

    echo "  Tipo ORIGEN  : ID={$rav4['id']} | {$rav4['nombre']} → {$countBefore} equipos\n";
    echo "  Tipo DESTINO : ID={$cam['id']} | {$cam['nombre']}\n\n";

    if ($countBefore === 0) {
        echo "No hay equipos que migrar. El tipo ya está vacío.\n";
    } else {
        // --- MIGRACIÓN ---
        $pdo->beginTransaction();

        $updated = $pdo->exec(
            "UPDATE equipos 
             SET id_tipo_equipo = $ID_CAMIONETA 
             WHERE id_tipo_equipo = $ID_CAMIONETA_RAV4"
        );

        echo "=== MIGRACIÓN ===\n";
        echo "  Equipos actualizados: {$updated}\n";

        $pdo->commit();
    }

    // --- VERIFICACIÓN POST ---
    $countAfterRav4 = (int) $pdo->query("SELECT COUNT(*) FROM equipos WHERE id_tipo_equipo = $ID_CAMIONETA_RAV4")->fetchColumn();
    $countAfterCam  = (int) $pdo->query("SELECT COUNT(*) FROM equipos WHERE id_tipo_equipo = $ID_CAMIONETA")->fetchColumn();

    echo "\n=== VERIFICACIÓN POST ===\n";
    echo "  Equipos restantes en CAMIONETA RAV4 (id=$ID_CAMIONETA_RAV4): {$countAfterRav4}\n";
    echo "  Equipos totales en CAMIONETA        (id=$ID_CAMIONETA)     : {$countAfterCam}\n";

    // --- ELIMINAR TIPO VACÍO ---
    if ($countAfterRav4 === 0) {
        $pdo->exec("DELETE FROM tipo_equipos WHERE id = $ID_CAMIONETA_RAV4");
        echo "\n  ✓ Tipo 'CAMIONETA RAV4' (id=$ID_CAMIONETA_RAV4) eliminado de tipo_equipos.\n";
    } else {
        echo "\n  ⚠ El tipo CAMIONETA RAV4 aún tiene equipos. NO se eliminó.\n";
    }

    echo "\n=== COMPLETADO EXITOSAMENTE ===\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        echo "Rollback ejecutado.\n";
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
