# Neofactura

Web oficial para consultas o sugerencias: http://neofactura.neocomplexx.com/

Implementación simple para interactuar con WebService de AFIP y realizar Factura Electrónica Argentina en PHP.
Luego de emitir la factura, podés generar un pdf con nuestra segunda librería: https://github.com/neocomplexx/neopdf

Permite realizar Facturas, Notas de Crédito y Débito: A, B y C con webservice wsfev1.

Versión 1.1: Agrega Factura de exportación E con webservice wsfexv1

Versión 1.2: Agrega Consulta de personas (Padrón A5) con webservice WsSrPadronA5

Pasos:

1. Clonar repositorio de github
2. Crear una carpeta dentro del mismo con el [cuit] de la persona autorizada en AFIP 
3. Crear tres carpetas dentro de la anterior: ./[cuit]/wsfe , ./[cuit]/wsfex y ./[cuit]/ws_sr_padron_a5
4. Dentro de dichas carpetas crear dos carpetas más: ./[cuit]./[serviceName]/tmp y ./[cuit]./[serviceName]/token
5. Crear las carpetas "./key/homologacion" y "./key/produccion"
6. En ./key/homologacion y ./key/produccion colocar los certificados generados en afip junto con las claves privadas.

Test:

1. Editar el archivo test.php y modificar el valor de la variable $CUIT por el de la persona autorizada en AFIP.
2. Probar la libreria desde consola con "php test.php" -> Debería imprimir OK por cada web service.
3. Opcional: Podrás probar los distintos tipos de comprobante desde los ejemplos nombrados como testFacturaX.php

¿Más info? -> https://github.com/neocomplexx/neofactura/wiki
