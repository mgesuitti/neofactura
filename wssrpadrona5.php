<?php

include_once (__DIR__ . '/wsaa.php');
include_once (__DIR__ . '/dto/personaAFIP.php');
/**
 * Clase para emitir facturas electronicas online con AFIP
 * con el webservice WsSrPadronA5
 * 
 * @author NeoComplexx Group S.A.
 */
class WsSrPadronA5 {

    //************* CONSTANTES ***************************** 
    const MSG_AFIP_CONNECTION = "No pudimos comunicarnos con AFIP: ";
    const MSG_BAD_RESPONSE = "Respuesta mal formada";
    const MSG_ERROR_RESPONSE = "Respuesta con errores";
    const TA = "/token/TA.xml"; # Ticket de Acceso, from WSAA  
    const WSDL_PRODUCCION = "/wsdl/produccion/wssrpadrona5.wsdl";
    const URL_PRODUCCION = "https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wssrpadrona5.wsdl";
    const URL_HOMOLOGACION = "https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5";
    const PROXY_HOST = ""; # Proxy IP, to reach the Internet
    const PROXY_PORT = ""; # Proxy TCP port   
    const SERVICE_NAME = "ws_sr_padron_a5";

    //************* VARIABLES *****************************
    private $log_xmls = TRUE; # Logs de las llamadas
    private $modo = 0; # Homologacion "0" o produccion "1"
    private $cuit = 0; # CUIT del emisor de las FC/NC/ND
    private $client = NULL;
    private $token = NULL;
    private $sign = NULL;
    private $base_dir = __DIR__;
    private $wsdl = "";
    private $url = "";

    public function __construct($cuit, $modo_afip) {
        // Si no casteamos a float, lo toma como long y el soap client
        // de windows no lo soporta - > lo transformaba en 2147483647
        $this->cuit = (float) $cuit;
        $this->modo = intval($modo_afip);
        if ($this->modo === Wsaa::MODO_PRODUCCION) {
            $this->wsdl = WsSrPadronA5::WSDL_PRODUCCION;
            $this->url = WsSrPadronA5::URL_PRODUCCION;
        } else {
            $this->wsdl = WsSrPadronA5::WSDL_HOMOLOGACION;
            $this->url = WsSrPadronA5::URL_HOMOLOGACION;
        }
        $this->initializeSoapClient();
    }

    /**
     * Consulta dummy para verificar funcionamiento del servicio
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function dummy() {
        try {
            $results = $this->client->dummy(new stdClass());
        } catch (Exception $e) {
            throw new Exception(WsSrPadronA5::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('dummy');
        $this->checkErrors($results, 'dummy');

        return $results;
    }

    /**
     * Consulta los datos de una persona dado su identificador
     * CUIT, CUIL o CDI
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function getPersona($idPersona) {
        $params = $this->buildBaseParams();
        $params->idPersona = $idPersona;

        try {
            $results = $this->client->getPersona($params);
        } catch (Exception $e) {
            throw new Exception(WsSrPadronA5::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('getPersona');
        $this->checkErrors($results, 'persona');

        $personaAFIP = new PersonaAFIP($results->personaReturn->datosGenerales);

        return $personaAFIP;
    }


    /**
     * Crea el cliente de conexión para el protocolo SOAP.
     *
     * @author: NeoComplexx Group S.A.
     */
    private function initializeSoapClient() {
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

            $this->client = new soapClient($this->base_dir . $this->wsdl, array('soap_version' => SOAP_1_1,
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
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME ."/tmp/functions.txt", print_r($this->client->__getFunctions(), TRUE));
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME ."/tmp/types.txt", print_r($this->client->__getTypes(), TRUE));
            }
        } catch (Exception $exc) {
            throw new Exception("Error: " . $exc->getTraceAsString());
        }
    }

    /**
     * Construye un objeto con los parametros basicos requeridos por todos los metodos del servcio WsSrPadronA5.
     */
    private function buildBaseParams() {
        $this->checkToken();
        $params = new stdClass();
        $params->token = $this->token;
        $params->sign = $this->sign;
        $params->cuitRepresentada = $this->cuit;
        return $params;
    }

    /**
     * Verifica la existencia y validez del token actual y solicita uno nuevo si corresponde.
     *
     * @author: NeoComplexx Group S.A.
     */
    private function checkToken() {
        if (!file_exists($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME . WsSrPadronA5::TA)) {
            $generateToken = TRUE;
        } else {
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME . WsSrPadronA5::TA);
            $expirationTime = date('c', strtotime($TA->header->expirationTime));
            $actualTime = date('c', date('U'));
            $generateToken = $actualTime >= $expirationTime;
        }

        if ($generateToken) {
            //renovamos el token
            $wsaa_client = new Wsaa(WsSrPadronA5::SERVICE_NAME, $this->modo, $this->cuit, $this->log_xmls);
            $wsaa_client->generateToken();
            //Recargamos con el nuevo token
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME . WsSrPadronA5::TA);
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
    private function logClientActivity($method) {
        if ($this->log_xmls) {
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME . "/tmp/request-" . $method . ".xml", $this->client->__getLastRequest());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME . "/tmp/hdr-request-" . $method . ".txt", $this->client->
                __getLastRequestHeaders());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME . "/tmp/response-" . $method . ".xml", $this->client->__getLastResponse());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . WsSrPadronA5::SERVICE_NAME . "/tmp/hdr-response-" . $method . ".txt", $this->client->
                __getLastResponseHeaders());
        }
    }

    /**
     * Revisa la respuesta de un web service en busca de errores y lanza una excepción si corresponde.
     * @param unknown $results
     * @param String $calledMethod
     * @throws Exception
     */
    private function checkErrors($results, $calledMethod) {
        if (!(isset($results->{$calledMethod.'Return'}) || isset($results->{'return'}))) {
            throw new Exception(WsSrPadronA5::MSG_BAD_RESPONSE . ' - ' . $calledMethod);
        } else if (is_soap_fault($results)) {
            throw new Exception("$results->faultcode - $results->faultstring - $calledMethod");
        }
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