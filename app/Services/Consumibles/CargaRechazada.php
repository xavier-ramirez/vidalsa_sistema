<?php

namespace App\Services\Consumibles;

/**
 * Una carga de consumibles que no se puede hacer por un motivo que el usuario debe leer (el Excel
 * no trae despachos, la carga ya se guardó, otra se está guardando...): se muestra tal cual con
 * un 422. Propia y no \RuntimeException: los errores de la base también lo son y esos no se
 * muestran crudos (van a MensajeError y al log).
 */
class CargaRechazada extends \RuntimeException
{
}
