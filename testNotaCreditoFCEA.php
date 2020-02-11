<?php

include_once (__DIR__ . '/wsfev1.php');
include_once (__DIR__ . '/wsaa.php');

/**
* Este script sirve para probar el webservice WSFEV1 con Factura A
* Hay que indicar el CUIT con el cual vamos a realizar las pruebas
* Hay que indicar el número de comprobante correcto
* Hay que indicar un CUIT válido para el receptor del comprobante
* Recordar tener todos los servicios de homologación habilitados en AFIP
* Ejecutar desde consola con "php testFacturaA.php"
*/
$CUIT = "xxxxxxxxx"; // CUIT del emisor
$MODO = Wsaa::MODO_HOMOLOGACION;

echo "----------Script de prueba de AFIP WSFEV1----------\n";
echo "-------------------- NOTA DE CREDITO FCE A -------------------\n";

$afip = new Wsfev1($CUIT,$MODO);

// Consulto el ultimo comprobante autorizado
$ultimocomp = $afip->consultarUltimoComprobanteAutorizado(2,203);

// le sumo uno, para que quede el siguiente comprobante a autorizar
++$ultimocomp;

// consulto la cotizacion del dolar Aduana
$dolar = $afip->consultarCotizacionMoneda('DOL');

// obtengo la fecha actual
$fecha = date('Ymd');

$voucher = Array(
    "idVoucher" => 1,
    "numeroComprobante" => $ultimocomp,
    "numeroPuntoVenta" => 2,
    "cae" => 0,
    "letra" => "A",
    "fechaVencimientoCAE" => "",
    "tipoResponsable" => "IVA Responsable Inscripto",
    "nombreCliente" =>  "JMARITIMA HEINLEIN S.A.",
    "domicilioCliente" => "PERU 359",
    "fechaComprobante" => $fecha,
    "codigoTipoComprobante" => 203,
    "TipoComprobante" => "Factura",
    "codigoConcepto" => 2,
    "codigoMoneda" => "PES",
    "cotizacionMoneda" => 1.000,
    "fechaDesde" => $fecha,
    "fechaHasta" => $fecha,
    // "fechaVtoPago" => $fecha,
    "codigoTipoDocumento" => 80,
    "TipoDocumento" => "CUIT",
    "numeroDocumento" => "30590162279", // MARITIMA HEINLEIN 30590162279
    "importeTotal" => 1000.000,
    "importeOtrosTributos" => 0.000,
    "importeGravado" => 0.000,
    "importeNoGravado" => 0.000,
    "importeExento" => 1000.000,
    "importeIVA" => 0.000,
    "codigoPais" => 200,
    "idiomaComprobante" => 1,
    "NroRemito" => 0,
    "CondicionVenta" => "Efectivo",
    "items" => Array
        (
            0 => Array
            (
                "codigo" => 8086,
                "scanner" => 8086,
                "descripcion" => "Producto de prueba",
                "UnidadMedida" => "UN",
                "cantidad" => "1",
                "precioUnitario" => 1000.000,
                "importeItem" => 1000.000,
                // "precioUnitario" => 1.000, // con precio inferior rechaza comprobante
                // "importeItem" => 1.000,    // con precio inferior rechaza comprobante
            )
        ),
    "Tributos" => Array(),
    "CbtesAsoc" => Array 
                    (
                     0 => Array(                   
                    "Tipo" => 201,
                    "PtoVta" => 2,
                    "Nro" => 9,
                    "Cuit" => "20304318954",
                    "CbteFch" => $fecha)
                     ),

    //meto array de opcionales
    "Opcionales" => Array
        (
            0 => Array
                (
                     "id" => 22,
                    "valor" => 'S'
                )
        ),
);

try {
    $afip = new Wsfev1($CUIT,$MODO);
    $result = $afip->emitirComprobante($voucher);
    print_r($result);
    echo "\n";
    echo "_____________________________________________________________\n";
    print_r("Ultimo Comprobante Autorizado: ". $ultimocomp); // Ultimo Comprobante Autorizado
    echo "\n";
    echo "_____________________________________________________________\n";
    print_r("Fecha Comprobante: ". $fecha = date('d/m/Y')); // Fecha de Emision
    echo "\n";
    echo "_____________________________________________________________\n";
    print_r("Cotizacion Dolar: ". $dolar); // citizacion Dolar Aduana
    echo "\n";
} catch (Exception $e) {
    echo 'Falló la ejecución: ' . $e->getMessage();
}

echo "--------------Ejecución WSFEV1 finalizada-----------------\n";