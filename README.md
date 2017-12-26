# Neofactura
Implementación simple para interactuar con WebService de AFIP y realizar Factura Electrónica Argentina en PHP.

Permite realizar Facturas, Notas de Crédito y Débito: A, B y C con webservice wsfev1.

Ultima incorporación: Factura de exportación E con webservice wsfexv1

Pasos:

1. Clonar repositorio de github
2. Crear una carpeta dentro del mismo con el [cuit] de la persona autorizada en AFIP 
3. Crear dos carpetas dentro de la anterior: ./[cuit]/wsfe y ./[cuit]/wsfex
3. Dentro de dichas carpetas crear dos carpetas más: ./[cuit]./[serviceName]/temp y ./[cuit]./[serviceName]/token
4. En ./key/homologacion y ./key/produccion colocar los certificados generados en afip junto con las claves privadas.

Test:

1. Editar el archivo test.php y modificar el valor de la variable $CUIT por el de la persona autorizada en AFIP.
2. Probar la libreria desde consola con "php test.php" -> Debería imprimir OK por cada web service.

¿Más info? -> https://github.com/neocomplexx/neofactura/wiki
