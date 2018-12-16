<?php

include_once (__DIR__ . '/wsaa.php');

/**
 * Clase base para conexión a webservices de AFIP
 * 
 * @author NeoComplexx Group S.A.
 */
class WsAFIP {

    //************* CONSTANTES ***************************** 

    const MSG_AFIP_CONNECTION = "No pudimos comunicarnos con AFIP: ";
    const MSG_BAD_RESPONSE = "Respuesta mal formada";
    const MSG_ERROR_RESPONSE = "Respuesta con errores";
    const TA = "/token/TA.xml"; # Ticket de Acceso, from WSAA  
    const PROXY_HOST = ""; # Proxy IP, to reach the Internet
    const PROXY_PORT = ""; # Proxy TCP port 

    //************* VARIABLES *****************************
    protected $log_xmls = TRUE; # Logs de las llamadas
    protected $modo = 0; # Homologacion "0" o produccion "1"
    protected $cuit = 0; # CUIT del emisor de las FC/NC/ND
    protected $client = NULL;
    protected $token = NULL;
    protected $sign = NULL;
    protected $base_dir = __DIR__;
    protected $wsdl = "";
    protected $url = "";
    protected $serviceName = "";

    /**
     * Verifica la existencia y validez del token actual y solicita uno nuevo si corresponde.
     *
     * @author: NeoComplexx Group S.A.
     */
    protected function checkToken() {
        if (!file_exists($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName . WsAFIP::TA)) {
            $generateToken = TRUE;
        } else {
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName . WsAFIP::TA);
            $expirationTime = date('c', strtotime($TA->header->expirationTime));
            $actualTime = date('c', date('U'));
            $generateToken = $actualTime >= $expirationTime;
        }

        if ($generateToken) {
            //renovamos el token
            $wsaa_client = new Wsaa($this->serviceName, $this->modo, $this->cuit, $this->log_xmls);
            $wsaa_client->generateToken();
            //Recargamos con el nuevo token
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName . WsAFIP::TA);
        }

        $this->token = $TA->credentials->token;
        $this->sign = $TA->credentials->sign;
    }

    /**
     * Si el loggueo de errores esta habilitado graba en archivos xml y txt las solicitudes y respuestas
     *
     * @param: $method - String: Metodo consultado
     * @author: NeoComplexx Group S.A.
     */
    protected function logClientActivity($method) {
        if ($this->log_xmls) {
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName . "/tmp/request-" . $method . ".xml", $this->client->__getLastRequest());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName . "/tmp/hdr-request-" . $method . ".txt", $this->client->
                __getLastRequestHeaders());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName . "/tmp/response-" . $method . ".xml", $this->client->__getLastResponse());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName . "/tmp/hdr-response-" . $method . ".txt", $this->client->
                __getLastResponseHeaders());
        }
    }

    /**
     * Verifica la existencia de un archivo y lanza una excepción si este no existe.
     * @param String $filePath
     * @throws Exception
     */
    protected function validateFileExists($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("No pudo abrirse el archivo $filePath");
        }
    }

        /**
     * Crea el cliente de conexión para el protocolo SOAP.
     *
     * @author: NeoComplexx Group S.A.
     */
    protected function initializeSoapClient($soap_version) {
        try {
            $this->validateFileExists($this->base_dir . $this->wsdl);
            ini_set("soap.wsdl_cache_enabled", 0);
            ini_set('soap.wsdl_cache_ttl', 0);

            $context = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            ));

            $this->client = new soapClient($this->base_dir . $this->wsdl, array('soap_version' => $soap_version,
                'location' => $this->url,
                #'proxy_host' => PROXY_HOST,
                #'proxy_port' => PROXY_PORT,
                #'verifypeer' => false,
                #'verifyhost' => false,
                'exceptions' => 1,
                'encoding' => 'ISO-8859-1',
                'features' => SOAP_USE_XSI_ARRAY_TYPE + SOAP_SINGLE_ELEMENT_ARRAYS,
                'trace' => 1,
                'stream_context' => $context
            )); # needed by getLastRequestHeaders and others

            if ($this->log_xmls) {
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName ."/tmp/functions.txt", print_r($this->client->__getFunctions(), TRUE));
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName ."/tmp/types.txt", print_r($this->client->__getTypes(), TRUE));
            }
        } catch (Exception $exc) {
            throw new Exception("Error: " . $exc->getTraceAsString());
        }
    }

}