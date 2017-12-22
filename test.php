<?php

include_once (__DIR__ . '/wsfev1.php');
include_once (__DIR__ . '/wsfexv1.php');
include_once (__DIR__ . '/wsaa.php');

/**
* Este script sirve para probar el webservice
* Hay que indicar el CUIT con el cual vamos a realizar las pruebas
* Recordar tener todos los servicios de homologación habilitados en AFIP
* Ejecutar desde consola con "php script_prueba.php"
*/
$CUIT = 20333692628;
$MODO = Wsaa::MODO_HOMOLOGACION;

echo "----------Script de prueba de AFIP WSFEV1----------\n";
$afip = new Wsfev1($CUIT,$MODO);
$result = $afip->init();
if ($result["code"] === Wsfev1::RESULT_OK) {
    $result = $afip->dummy();
    if ($result["code"] === Wsfev1::RESULT_OK) {
        $datos = print_r($result["msg"], TRUE);
        echo "Resultado: " . $datos . "\n";
    } else {
        echo $result["msg"] . "\n";
    }
} else {
    echo $result["msg"] . "\n";
}
echo "--------------Ejecución WSFEV1 finalizada-----------------\n";
echo "----------Script de prueba de AFIP WSFEXV1----------\n";
$afip = new Wsfexv1($CUIT,$MODO);
$result = $afip->init();
if ($result["code"] === Wsfexv1::RESULT_OK) {
    $result = $afip->dummy();
    if ($result["code"] === Wsfexv1::RESULT_OK) {
        $datos = print_r($result["msg"], TRUE);
        echo "Resultado: " . $datos . "\n";
    } else {
        echo $result["msg"] . "\n";
    }
} else {
    echo $result["msg"] . "\n";
}
echo "--------------Ejecución WSFEXV1 finalizada-----------------\n";