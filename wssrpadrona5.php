<?php

include_once (__DIR__ . '/wsaa.php');
include_once (__DIR__ . '/dto/personaAFIP.php');

/**
 * Clase para consultar datos de personas en AFIP
 * con el webservice WsSrPadronA5
 * 
 * @author Neocomplexx Group SA
 */
class WsSrPadronA5 {

    //************* CONSTANTES ***************************** 
    const MSG_AFIP_CONNECTION = "No pudimos comunicarnos con AFIP: ";
    const MSG_BAD_RESPONSE = "Respuesta mal formada";
    const RESULT_ERROR = 1;
    const RESULT_OK = 0;
    const TA = "/token/TA.xml"; # Ticket de Acceso, from WSAA  
    const WSDL_PRODUCCION = "/wsdl/produccion/wssrpadrona5.wsdl";
    const URL_PRODUCCION = "https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wssrpadrona5.wsdl";
    const URL_HOMOLOGACION = "https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5";
    const PROXY_HOST = ""; # Proxy IP, to reach the Internet
    const PROXY_PORT = ""; # Proxy TCP port   
    const SERVICE_NAME = "ws_sr_padron_a5";

    //************* VARIABLES *****************************
    var $log_xmls = TRUE; # Logs de las llamadas
    var $modo = 0; # Homologacion "0" o produccion "1"
    var $cuit = 0; # CUIT del emisor de las FC/NC/ND
    var $client = NULL;
    var $token = NULL;
    var $sign = NULL;
    var $base_dir = __DIR__;
    var $wsdl = "";
    var $url = "";

    function __construct($cuit, $modo_afip) {
        // Llamar a init luego de construir la instancia de clase
        $this->cuit = (float) $cuit;
        // Si no casteamos a float, lo toma como long y el soap client
        // de windows no lo soporta - > lo transformaba en 2147483647
        $this->modo = intval($modo_afip);
        if ($this->modo === Wsaa::MODO_PRODUCCION) {
            $this->wsdl = WsSrPadronA5::WSDL_PRODUCCION;
            $this->url = WsSrPadronA5::URL_PRODUCCION;
        } else {
            $this->wsdl = WsSrPadronA5::WSDL_HOMOLOGACION;
            $this->url = WsSrPadronA5::URL_HOMOLOGACION;
        }
    }

    /**
     * Crea el cliente de conexión para el protocolo SOAP y carga el token actual
     * 
     * @author: Neocomplexx Group SA
     */
    function init() {
        try {
            ini_set("soap.wsdl_cache_enabled", 0);
            ini_set('soap.wsdl_cache_ttl', 0);

            if (!file_exists($this->base_dir . $this->wsdl)) {
                return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => "No existe el archivo de configuración de AFIP: " . $this->base_dir . $this->wsdl);
            }
            $context = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            ));
            $this->client = new soapClient($this->base_dir . $this->wsdl, array('soap_version' => SOAP_1_1,
                'location' => $this->url,
                #        'proxy_host'   => PROXY_HOST,
                #        'proxy_port'   => PROXY_PORT,
                #'verifypeer' => false, 'verifyhost' => false,
                'exceptions' => 1,
                'encoding' => 'ISO-8859-1',
                'features' => SOAP_USE_XSI_ARRAY_TYPE + SOAP_SINGLE_ELEMENT_ARRAYS,
                'trace' => 1,
                'stream_context' => $context
            )); # needed by getLastRequestHeaders and others

            $this->checkToken();

            if ($this->log_xmls) {
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/functions.txt", print_r($this->client->__getFunctions(), TRUE));
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/types.txt", print_r($this->client->__getTypes(), TRUE));
            }
        } catch (Exception $exc) {
            return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => "Error: " . $exc->getTraceAsString());
        }

        return array("code" => WsSrPadronA5::RESULT_OK, "msg" => "Inicio correcto");
    }

    /**
     * Si el loggueo de errores esta habilitado graba en archivos xml y txt las solicitudes y respuestas
     * 
     * @param: $method - String: Metodo consultado
     * 
     * @author: Neocomplexx Group SA
     */
    function saveRequest($method) {
        if ($this->log_xmls) {
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/request-" . $method . ".xml", $this->client->__getLastRequest());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/hdr-request-" . $method . ".txt", $this->client->
                            __getLastRequestHeaders());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/response-" . $method . ".xml", $this->client->__getLastResponse());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/hdr-response-" . $method . ".txt", $this->client->
                            __getLastResponseHeaders());
        }
    }

    /**
     * Si el token actual está vencido solicita uno nuevo
     * 
     * @author: Neocomplexx Group SA
     */
    function checkToken() {
        if (!file_exists($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . WsSrPadronA5::TA)) {
            $not_exist = TRUE;
        } else {
            $not_exist = FALSE;
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . WsSrPadronA5::TA);
            $expirationTime = date('c', strtotime($TA->header->expirationTime));
            $actualTime = date('c', date('U'));
        }

        if ($not_exist || $actualTime >= $expirationTime) {
            //renovamos el token
            $wsaa_client = new Wsaa(WsSrPadronA5::SERVICE_NAME, $this->modo, $this->cuit, $this->log_xmls);
            $result = $wsaa_client->generateToken();
            if ($result["code"] == wsaa::RESULT_OK) {
                //Recargamos con el nuevo token
                $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . WsSrPadronA5::TA);
            } else {
                return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => $result["msg"]);
            }
        }

        $this->token = $TA->credentials->token;
        $this->sign = $TA->credentials->sign;
        return array("code" => WsSrPadronA5::RESULT_OK, "msg" => "Ok, token valido");
    }

    /**
     * Consulta dummy para verificar funcionamiento del servicio
     * 
     * @author: Neocomplexx Group SA
     */
    function dummy() {
        $result = $this->checkToken();
        if ($result["code"] == WsSrPadronA5::RESULT_OK) {
            $params = new stdClass();

            try {
                $results = $this->client->dummy($params);
            } catch (Exception $e) {
                $this->saveRequest('dummy');
                return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => WsSrPadronA5::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }
            $this->saveRequest('dummy');

            if (is_soap_fault($results)) {
                return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else if (!isset($results->return)) {
                return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => WsSrPadronA5::MSG_BAD_RESPONSE, "datos" => NULL);
            } else {
                $appserver = $results->return->appserver;
                $authserver = $results->return->authserver;
                $dbserver = $results->return->dbserver;
                if (strcmp($appserver,"OK") == 0 && strcmp($authserver,"OK") == 0 && strcmp($dbserver,"OK") == 0) {
                    return array("code" => WsSrPadronA5::RESULT_OK, "msg" => "Ok", "datos" => NULL);
                } else {
                    return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => "Servicio no disponibles", "datos" => NULL);
                }
            } 
        } else {
            return $result;
        }
    }

    function getPersona($idPersona) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->token = $this->token;
            $params->sign = $this->sign;
            $params->cuitRepresentada = $this->cuit;
            $params->idPersona = $idPersona;

            try {
                $results = $this->client->getPersona($params);
            } catch (Exception $e) {
                $this->saveRequest('getPersona');
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            } 

            $this->saveRequest('getPersona');

            if (is_soap_fault($results)) {
                return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else if (!isset($results->personaReturn)) {
                return array("code" => WsSrPadronA5::RESULT_ERROR, "msg" => WsSrPadronA5::MSG_BAD_RESPONSE, "datos" => NULL);
            } else {
                $personaAFIP = new PersonaAFIP($results->personaReturn->datosGenerales);
                return array("code" => WsSrPadronA5::RESULT_OK, "msg" => "Ok", "datos" => $personaAFIP);
            } 
            return array("code" => Wsfexv1::RESULT_OK, "msg" => "", "datos" => $results);
        } else {
            return $result;
        }
    }

}