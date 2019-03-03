<?php

include_once (__DIR__ . '/wsfev1.php');
include_once (__DIR__ . '/wsfexv1.php');
include_once (__DIR__ . '/wssrpadrona5.php');
include_once (__DIR__ . '/wsaa.php');

/**
* Este script sirve para probar el webservice
* Hay que indicar el CUIT con el cual vamos a realizar las pruebas
* Recordar tener todos los servicios de homologación habilitados en AFIP
* Ejecutar desde consola con "php test.php"
*/
$CUIT = "XXXXXXXXXXX"; // CUIT del emisor
$MODO = Wsaa::MODO_HOMOLOGACION;

echo "----------Script de prueba de AFIP WSFEV1----------\n";
try {
    $afip = new Wsfev1($CUIT,$MODO);
    $result = $afip->dummy();
    print_r($result);
} catch (Exception $e) {
    echo 'Falló la ejecución: ' . $e->getMessage();
}

echo "--------------Ejecución WSFEV1 finalizada-----------------\n";
echo "----------Script de prueba de AFIP WSFEXV1----------\n";

try {
    $afip = new Wsfexv1($CUIT,$MODO);
    $result = $afip->dummy();
    print_r($result);
} catch (Exception $e) {
    echo 'Falló la ejecución: ' . $e->getMessage();
}
echo "--------------Ejecución WSFEXV1 finalizada-----------------\n";
echo "----------Script de prueba de AFIP WsSrPadronA5----------\n";
try {
    $afip = new WsSrPadronA5($CUIT,$MODO);
    $result = $afip->dummy();
    print_r($result);
} catch (Exception $e) {
    echo 'Falló la ejecución: ' . $e->getMessage();
}
echo "--------------Ejecución WsSrPadronA5 finalizada-----------------\n";