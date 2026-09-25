<?php

namespace App\Exceptions;

/**
 * El ARCHIVO no se puede aceptar: esta cortado a medias, llego vacio o no es un PDF.
 *
 * Existe para poder distinguirlo de "Google Drive no responde", que es lo contrario:
 *   · Drive caido        -> 503 "reintente en un momento": el archivo esta bien, falla la red.
 *   · Archivo no valido  -> 422 con SU mensaje: reintentar no arregla nada, hay que volver a
 *                           escanear el documento.
 *
 * Antes se miraba el tipo RuntimeException, y eso no valia: Drive tambien lanza
 * RuntimeException cuando se cae, asi que una caida de red se le contaba al usuario como un
 * PDF roto (lo cazaron las pruebas de "si drive falla", 24-09-2026).
 *
 * Lo lanza GoogleDriveService::comprobarPdfCompleto; lo miran los controladores que suben
 * documentos y la carga masiva.
 */
class PdfNoValido extends \RuntimeException
{
}
