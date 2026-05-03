<?php
// Script de análisis - NO modifica nada
$host = '127.0.0.1';
$port = '3306';
$db   = 'cd';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=== TABLA tipo_equipos ===\n";
    $stmt = $pdo->query("SELECT te.id, te.nombre, COUNT(e.ID_EQUIPO) as total_equipos
        FROM tipo_equipos te
        LEFT JOIN equipos e ON e.id_tipo_equipo = te.id
        GROUP BY te.id, te.nombre
        ORDER BY te.id");
    
    $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    printf("%-6s %-30s %-15s\n", "ID", "NOMBRE", "EQUIPOS");
    echo str_repeat("-", 55) . "\n";
    foreach ($tipos as $t) {
        printf("%-6s %-30s %-15s\n", $t['id'], $t['nombre'], $t['total_equipos']);
    }

    // Buscar específicamente CAMIONETA RAV4 y CAMIONETA
    echo "\n=== DETALLE: Buscar 'CAMIONETA' ===\n";
    $stmt2 = $pdo->query("SELECT * FROM tipo_equipos WHERE nombre LIKE '%CAMIONETA%' OR nombre LIKE '%camioneta%'");
    $camionetas = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($camionetas as $c) {
        echo "  ID={$c['id']} | nombre={$c['nombre']}\n";
    }

    echo "\n=== Equipos con tipo CAMIONETA RAV4 ===\n";
    $stmt3 = $pdo->query("
        SELECT e.ID_EQUIPO, e.CODIGO_PATIO, e.MARCA, e.MODELO, e.id_tipo_equipo, te.nombre as TIPO
        FROM equipos e
        JOIN tipo_equipos te ON te.id = e.id_tipo_equipo
        WHERE te.nombre LIKE '%RAV4%'
        ORDER BY e.ID_EQUIPO
    ");
    $equipos_rav4 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    if (empty($equipos_rav4)) {
        echo "  (Ninguno encontrado)\n";
    } else {
        printf("  %-10s %-20s %-20s %-20s\n", "ID_EQUIPO", "CODIGO_PATIO", "MARCA", "MODELO");
        echo "  " . str_repeat("-", 72) . "\n";
        foreach ($equipos_rav4 as $eq) {
            printf("  %-10s %-20s %-20s %-20s\n", $eq['ID_EQUIPO'], $eq['CODIGO_PATIO'], $eq['MARCA'], $eq['MODELO']);
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
