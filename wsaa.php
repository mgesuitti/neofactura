<?php

/**
 * Clase para autenticarse contra AFIP 
 * Hace uso del web-service WSAA que permite obtener el token de conexión
 * 
 * Se encuentra basado en el ejemplo de aplicaciones clientes del WSAA publicado en la web de AFIP
 * http://www.afip.gob.ar/ws/paso4.asp?noalert=1
 *
 * @author NeoComplexx Group S.A.
 */
class Wsaa {

    //************* CONSTANTES *****************************
    const MODO_HOMOLOGACION = 0;
    const MODO_PRODUCCION = 1;
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wsaa.wsdl"; # WSDL del web service WSAA
    const URL_HOMOLOGACION = "https://wsaahomo.afip.gov.ar/ws/services/LoginCms";
    const CERT_HOMOLOGACION = "/key/homologacion/certificado.pem"; # Certificado X.509 otorgado por AFIP
    const PRIVATEKEY_HOMOLOGACION = "/key/homologacion/privada"; # Clave privada de la PC
    const WSDL_PRODUCCION = "/wsdl/produccion/wsaa.wsdl";
    const URL_PRODUCCION = "https://wsaa.afip.gov.ar/ws/services/LoginCms"; 
    const CERT_PRODUCCION = "/key/produccion/certificado.pem";
    const PRIVATEKEY_PRODUCCION = "/key/produccion/privada";

    //************* VARIABLES *****************************
    private $base_dir = __DIR__;
    private $service = "";
    private $modo = 0;
    private $log_xmls = TRUE; 
    private $cuit = 0;
    private $wsdl = "";
    private $url = "";
    private $cert = "";
    private $privatekey = "";

    public function __construct($service, $modo_afip, $cuit, $logs) {
        $this->log_xmls = $logs;
        $this->modo = $modo_afip;
        $this->cuit = $cuit;
        $this->service = $service;
        ini_set("soap.wsdl_cache_enabled", 0);
        ini_set('soap.wsdl_cache_ttl', 0);
        
        if ($this->modo === Wsaa::MODO_PRODUCCION) {
            $this->wsdl = Wsaa::WSDL_PRODUCCION; 
            $this->url = Wsaa::URL_PRODUCCION; 
            $this->cert = "file://" . $this->base_dir . Wsaa::CERT_PRODUCCION;
            $this->privatekey = "file://" . $this->base_dir . Wsaa::PRIVATEKEY_PRODUCCION;
        } else {
            $this->wsdl = Wsaa::WSDL_HOMOLOGACION; 
            $this->url = Wsaa::URL_HOMOLOGACION; 
            $this->cert = "file://" . $this->base_dir . Wsaa::CERT_HOMOLOGACION;
            $this->privatekey = "file://" . $this->base_dir . Wsaa::PRIVATEKEY_HOMOLOGACION;
        }
    }

    /**
     * Genera un nuevo token de conexión y lo guarda en el archivo ./:CUIT/:WebSerivce/token/TA.xml
     *
     * @author: NeoComplexx Group S.A.
     */
    public function generateToken() {
        $this->validateFileExists($this->cert);
        $this->validateFileExists($this->privatekey);
        $this->validateFileExists($this->base_dir . $this->wsdl);

        $this->createTRA();
        $cms = $this->signTRA();
        $loginResult = $this->callWSAA($cms);
        file_put_contents($this->base_dir . "/" . $this->cuit . '/' . $this->service . "/token/TA.xml", $loginResult);
    }

    /**
     * Crea el archivo ./:CUIT/:WebSerivce/token/TRA.xml
     * El archivo es necesario para realizar la firma
     * 
     * @author: NeoComplexx Group S.A.
     */
    private function createTRA() {
        try {
            $TRA = new SimpleXMLElement(
                    '<?xml version="1.0" encoding="UTF-8"?>' .
                    '<loginTicketRequest version="1.0">' .
                    '</loginTicketRequest>');
            $TRA->addChild('header');
            $TRA->header->addChild('uniqueId', date('U'));
            $TRA->header->addChild('generationTime', date('c', date('U') - 60));
            $TRA->header->addChild('expirationTime', date('c', date('U') + 60));
            $TRA->addChild('service', $this->service);
            $TRA->asXML($this->base_dir . "/" . $this->cuit . '/' . $this->service . '/token/TRA.xml');
        } catch (Exception $exc) {
            throw new Exception("Error al crear TRA.xml: " . $exc->getTraceAsString());
        }
    }

    /**
     * Esta funcion realiza la firma PKCS#7 usando como entrada el archivo TRA.xml, el certificado y la clave privada
     * Genera un archivo intermedio ./:CUIT/:WebSerivce/TRA.tmp y finalmente obtiene del encabezado solo lo que se necesita para WSAA
     * 
     * @author: NeoComplexx Group S.A.
     */
    private function signTRA() {
        $infilename = $this->base_dir . "/" . $this->cuit . '/' . $this->service . "/token/TRA.xml";
        $outfilename = $this->base_dir . "/" . $this->cuit . '/' . $this->service . "/TRA.tmp";
        $headers = array();
        $flags = !PKCS7_DETACHED;
        $status = openssl_pkcs7_sign($infilename, $outfilename, $this->cert, $this->privatekey, $headers, $flags);
        if (!$status) {
            throw new Exception("ERROR al generar la firma PKCS#7");
        }
        $inf = fopen($this->base_dir . "/" . $this->cuit . '/' . $this->service . "/TRA.tmp", "r");
        $i = 0;
        $cms = "";
        while (!feof($inf)) {
            $buffer = fgets($inf);
            if ($i++ >= 4) {
                $cms.=$buffer;
            }
        }
        fclose($inf);
        #unlink("token/TRA.xml");
        unlink($this->base_dir . "/" . $this->cuit . '/' . $this->service . "/TRA.tmp");

        return $cms;
    }
    
    /**
     * Esta funcion se conecta al webservice SOAP de AFIP para autenticarse
     * El resultado es la información del token generado
     * 
     * @author: NeoComplexx Group S.A.
     */
    private function callWSAA($cms) {
        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        ));

        $client = new SoapClient($this->base_dir . $this->wsdl, array(
            'soap_version' => SOAP_1_2,
            'location' => $this->url,
            'trace' => 1,
            'exceptions' => 0,
            'stream_context' => $context
        ));

        $results = $client->loginCms(array('in0' => $cms));

        if ($this->log_xmls) {
            file_put_contents($this->base_dir . "/" . $this->cuit . '/' . $this->service . "/tmp/request-loginCms.xml", $client->__getLastRequest());
            file_put_contents($this->base_dir . "/" . $this->cuit . '/' . $this->service . "/tmp/response-loginCms.xml", $client->__getLastResponse());
        }

        if (is_soap_fault($results)) {
            throw new Exception("Error SOAP: " . $results->faultcode . " - " . $results->faultstring);
        }

        return $results->loginCmsReturn;
    }

    /**
     * Verifica la existencia de un archivo y lanza una excepción si este no existe.
     * @param String $filePath
     * @throws Exception
     */
    private function validateFileExists($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("No pudo abrirse el archivo $filePath");
        }
    }

}
