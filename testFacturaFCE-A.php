<?php

include_once (__DIR__ . '/wsfev1.php');
include_once (__DIR__ . '/wsaa.php');

/**
* Este script sirve para probar el webservice WSFEV1 con Factura FCE
* Hay que indicar el CUIT con el cual vamos a realizar las pruebas
* Hay que indicar el número de comprobante correcto
* Hay que indicar un CUIT válido para el receptor del comprobante
* Recordar tener todos los servicios de homologación habilitados en AFIP
* Ejecutar desde consola con "php testFacturaA.php"
*/

$CUIT = "xxxxxxxxx"; // CUIT del emisor
$MODO = Wsaa::MODO_HOMOLOGACION;

echo "----------Script de prueba de AFIP WSFEV1----------\n";
echo "-------------------- FACTURAS FCE -------------------\n";

$afip = new Wsfev1($CUIT,$MODO);

// Consulto el ultimo comprobante autorizado, se le pasa (punton de venta - tipo comprobante)
$ultimocomp = $afip->consultarUltimoComprobanteAutorizado(2,201);

// le sumo uno, para que quede el siguiente comprobante a autorizar
++$ultimocomp;

// consulto la cotizacion del dolar Aduana
$dolar = $afip->consultarCotizacionMoneda('DOL');

// obtengo la fecha actual
$fecha = date('Ymd');

$tiposFCE = $afip->consultarCamposAuxiliares();



$voucher = Array(
    "idVoucher" => 1,
    "numeroComprobante" => $ultimocomp,
    "numeroPuntoVenta" => 2,
    "cae" => 0,
    "letra" => "A",
    "fechaVencimientoCAE" => "",
    "tipoResponsable" => "IVA Responsable Inscripto",
    "nombreCliente" =>  "MARITIMA HEINLEIN S.A.",
    "domicilioCliente" => "PERU 359",
    "fechaComprobante" => $fecha,
    "codigoTipoComprobante" => 201,
    "TipoComprobante" => "Factura",
    "codigoConcepto" => 2,
    "codigoMoneda" => "DOL",
    "cotizacionMoneda" => $dolar,
    "fechaDesde" => $fecha,
    "fechaHasta" => $fecha,
    "fechaVtoPago" => $fecha,
    "codigoTipoDocumento" => 80,
    "TipoDocumento" => "CUIT",
   // "numeroDocumento" => "30693184947", // MEDITERRANEAN SHIPPING COMPANY S A - Act.Princ. 523020
   // "numeroDocumento" => "30590162279", // MARITIMA HEINLEIN - Act.Princ. 523020
    "numeroDocumento" => "30709710857", // MARITIMA MERIDIAN S.A. - Act.Princ. 523020
    "importeTotal" => 1000000.000,
    "importeOtrosTributos" => 0.000,
    "importeGravado" => 0.000,
    "importeNoGravado" => 0.000,
    "importeExento" => 1000000.000,
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
                    "precioUnitario" => 1000000.000,
                    "importeItem" => 1000000.000,
                )
        ),
    "Tributos" => Array(),
    "CbtesAsoc" => Array(),

    //meto array de opcionales
    "Opcionales" => Array
        (
            0 => Array
                (
                    "id" => 2101,
                    "valor" => '0720332721000000510391'
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
    echo "_____________________________________________________________\n";
    print_r($ultimocomp);
    echo "\n";
} catch (Exception $e) {
    echo 'Falló la ejecución: ' . $e->getMessage();
}

echo "--------------Ejecución WSFEV1 finalizada-----------------\n";