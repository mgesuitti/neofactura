<?php

include_once (__DIR__ . '/wsaa.php');
include_once (__DIR__ . '/wsafip.php');
include_once (__DIR__ . '/dto/personaAFIP.php');

/**
 * Clase para emitir facturas electronicas online con AFIP
 * con el webservice WsSrPadronA13
 * 
 * @author NeoComplexx Group S.A.
 */
class WsSrPadronA13 extends WsAFIP {

    //************* CONSTANTES ***************************** 

    const WSDL_PRODUCCION = "/wsdl/produccion/personaServiceA13.wsdl";
    const URL_PRODUCCION = "https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/personaServiceA13.wsdl";
    const URL_HOMOLOGACION = "https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13";

    public function __construct($cuit, $modo_afip) {
        $this->serviceName = "ws_sr_padron_a13";
        // Si no casteamos a float, lo toma como long y el soap client
        // de windows no lo soporta - > lo transformaba en 2147483647
        $this->cuit = (float) $cuit;
        $this->modo = intval($modo_afip);
        if ($this->modo === Wsaa::MODO_PRODUCCION) {
            $this->wsdl = WsSrPadronA13::WSDL_PRODUCCION;
            $this->url = WsSrPadronA13::URL_PRODUCCION;
        } else {
            $this->wsdl = WsSrPadronA13::WSDL_HOMOLOGACION;
            $this->url = WsSrPadronA13::URL_HOMOLOGACION;
        }
        $this->initializeSoapClient(SOAP_1_1);
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
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('dummy');
        $this->checkErrors($results, 'dummy');

        return $results;
    }

     /**
     * Consulta los datos de una persona dado su documento
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function getPersonaByDocumento($documento) {
        $params = $this->buildBaseParams();
        $params->documento = $documento;

        try {
            $results = $this->client->getIdPersonaListByDocumento($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('getIdPersonaListByDocumento');

        $idPersona = 0;
        if (isset($results->idPersonaListReturn) && isset($results->idPersonaListReturn->idPersona)) {
            $idPersona = $results->idPersonaListReturn->idPersona[0];
        }

        return $this->getPersona($idPersona);
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
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('getPersona');
        $this->checkErrors($results, 'persona');

        $personaAFIP = new PersonaAFIP($results->personaReturn);

        return $personaAFIP;
    }

    /**
     * Construye un objeto con los parametros basicos requeridos por todos los metodos del servcio WsSrPadronA13.
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
     * Revisa la respuesta de un web service en busca de errores y lanza una excepción si corresponde.
     * @param unknown $results
     * @param String $calledMethod
     * @throws Exception
     */
    private function checkErrors($results, $calledMethod) {
        if (!(isset($results->{$calledMethod.'Return'}) || isset($results->{'return'}))) {
            throw new Exception(WsAFIP::MSG_BAD_RESPONSE . ' - ' . $calledMethod);
        } else if (is_soap_fault($results)) {
            throw new Exception("$results->faultcode - $results->faultstring - $calledMethod");
        }
    }

}