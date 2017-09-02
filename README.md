# Neofactura
Implementación simple para interactuar con WebService de AFIP y realizar Factura Electrónica Argentina en PHP

Pasos:

1. Clonar repositorio de github
2. Crear una carpeta dentro del mismo con el [cuit] de la persona autorizada en AFIP 
3. Dentro de dicha carpeta crear dos carpetas más: ./[cuit]/temp y ./[cuit]/token
4. En ./key/homologacion y ./key/produccion colocar los certificados generados en afip junto con las claves privadas.

Test:

1. Editar el archivo test.php y modificar el valor de la variable $CUIT por el de la persona autorizada en AFIP.
2. Probar la libreria desde consola con "php test.php" -> Debería imprimir OK.
