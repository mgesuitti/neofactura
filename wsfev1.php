<?php

include_once (__DIR__ . '/wsaa.php');

/**
 * Clase para emitir facturas electronicas online con AFIP
 * con el webservice wsfev1
 * 
 * @author NeoComplexx Group S.A.
 */
class Wsfev1 {

    //************* CONSTANTES ***************************** 
    const MSG_AFIP_CONNECTION = "No pudimos comunicarnos con AFIP: ";
    const MSG_BAD_RESPONSE = "Respuesta mal formada";
    const RESULT_ERROR = 1;
    const RESULT_OK = 0;
    const TA = "/token/TA.xml"; # Ticket de Acceso, from WSAA  
    const WSDL_PRODUCCION = "/wsdl/produccion/wsfev1.wsdl";
    const URL_PRODUCCION = "https://servicios1.afip.gov.ar/wsfev1/service.asmx";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wsfev1.wsdl";
    const URL_HOMOLOGACION = "https://wswhomo.afip.gov.ar/wsfev1/service.asmx";
    const PROXY_HOST = ""; # Proxy IP, to reach the Internet
    const PROXY_PORT = ""; # Proxy TCP port   

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
            $this->wsdl = Wsfev1::WSDL_PRODUCCION;
            $this->url = Wsfev1::URL_PRODUCCION;
        } else {
            $this->wsdl = Wsfev1::WSDL_HOMOLOGACION;
            $this->url = Wsfev1::URL_HOMOLOGACION;
        }
    }

    /**
     * Crea el cliente de conexión para el protocolo SOAP y carga el token actual
     * 
     * @author: NeoComplexx Group S.A.
     */
    function init() {
        try {
            ini_set("soap.wsdl_cache_enabled", 0);
            ini_set('soap.wsdl_cache_ttl', 0);

            if (!file_exists($this->base_dir . $this->wsdl)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "No existe el archivo de configuración de AFIP: " . $this->base_dir . $this->wsdl);
            }
            $context = stream_context_create(array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            ));
            $this->client = new soapClient($this->base_dir . $this->wsdl, array('soap_version' => SOAP_1_2,
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
                file_put_contents($this->base_dir . "/" . $this->cuit . "/tmp/functions.txt", print_r($this->client->__getFunctions(), TRUE));
                file_put_contents($this->base_dir . "/" . $this->cuit . "/tmp/types.txt", print_r($this->client->__getTypes(), TRUE));
            }
        } catch (Exception $exc) {
            return array("code" => Wsfev1::RESULT_ERROR, "msg" => "Error: " . $exc->getTraceAsString());
        }

        return array("code" => Wsfev1::RESULT_OK, "msg" => "Inicio correcto");
    }

    /**
     * Si el loggueo de errores esta habilitado graba en archivos xml y txt las solicitudes y respuestas
     * 
     * @param: $method - String: Metodo consultado
     * 
     * @author: NeoComplexx Group S.A.
     */
    function checkErrors($method) {
        if ($this->log_xmls) {
            file_put_contents($this->base_dir . "/" . $this->cuit . "/tmp/request-" . $method . ".xml", $this->client->__getLastRequest());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/tmp/hdr-request-" . $method . ".txt", $this->client->
                            __getLastRequestHeaders());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/tmp/response-" . $method . ".xml", $this->client->__getLastResponse());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/tmp/hdr-response-" . $method . ".txt", $this->client->
                            __getLastResponseHeaders());
        }
    }

    /**
     * Si el token actual está vencido solicita uno nuevo
     * 
     * @author: NeoComplexx Group S.A.
     */
    function checkToken() {
        if (!file_exists($this->base_dir . "/" . $this->cuit . Wsfev1::TA)) {
            $not_exist = TRUE;
        } else {
            $not_exist = FALSE;
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . Wsfev1::TA);
            $expirationTime = date('c', strtotime($TA->header->expirationTime));
            $actualTime = date('c', date('U'));
        }

        if ($not_exist || $actualTime >= $expirationTime) {
            //renovamos el token
            $wsaa_client = new Wsaa("wsfe", $this->modo, $this->cuit, $this->log_xmls);
            $result = $wsaa_client->generateToken();
            if ($result["code"] == wsaa::RESULT_OK) {
                //Recargamos con el nuevo token
                $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . Wsfev1::TA);
            } else {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $result["msg"]);
            }
        }

        $this->token = $TA->credentials->token;
        $this->sign = $TA->credentials->sign;
        return array("code" => Wsfev1::RESULT_OK, "msg" => "Ok, token valido");
    }

    /**
     * Consulta dummy para verificar funcionamiento del servicio
     * 
     * @author: NeoComplexx Group S.A.
     */
    function dummy() {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();

            try {
                $results = $this->client->FEDummy($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }
            $this->checkErrors('FEDummy');

            if (!isset($results->FEDummyResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEDummyResult->Errors)) {
                $error_str = "Error al realizar consulta de puntos de venta: \n";
                foreach ($results->FEDummyResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring");
            } else {
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK");
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta los tipos de otros tributos que pueden
     * enviarse en el comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarTiposTributos() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEParamGetTiposTributos($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetTiposTributos');
            if (!isset($results->FEParamGetTiposTributosResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetTiposTributosResult->Errors)) {
                $error_str = "Error al realizar consulta de tipos de tributos: \n";
                foreach ($results->FEParamGetTiposTributosResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEParamGetTiposTributosResult->ResultGet;
                foreach ($X->TributoTipo as $Y) {
                    $datos[$Y->Id] = $Y->Desc;
                }
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta campos auxiliares
     * Dependiendo de la actividad que realize el comercio
     * Puede que requiera mandar datos adicionales
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarCamposAuxiliares() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEParamGetTiposOpcional($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetTiposOpcional');
            if (!isset($results->FEParamGetTiposOpcionalResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetTiposOpcionalResult->Errors)) {
                $error_str = "Error al realizar consulta de campos auxiliares: \n";
                foreach ($results->FEParamGetTiposOpcionalResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEParamGetTiposOpcionalResult->ResultGet;
                foreach ($X->OpcionalTipo as $Y) {
                    $datos[$Y->Id] = $Y->Desc;
                }
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta puntos de venta habilitados
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarPuntosVenta() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEParamGetPtosVenta($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetPtosVenta');
            if (!isset($results->FEParamGetPtosVentaResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetPtosVentaResult->Errors)) {
                $error_str = "Error al realizar consulta de puntos de venta: \n";
                foreach ($results->FEParamGetPtosVentaResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEParamGetPtosVentaResult->ResultGet;
                foreach ($X->PtoVenta as $Y) {
                    $datos[$Y->Nro] = $Y->EmisionTipo;
                }
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta las unidades de medida disponibles
     * Este metodo solo se utiliza en el otro web service WSMTXCA
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarUnidadesMedida() {
        return NULL;
    }

    /**
     * Consulta los tipos de documento disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarTiposDocumento() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEParamGetTiposDoc($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetTiposDoc');
            if (!isset($results->FEParamGetTiposDocResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetTiposDocResult->Errors)) {
                $error_str = "Error al realizar consulta de tipos de documento: \n";
                foreach ($results->FEParamGetTiposDocResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEParamGetTiposDocResult->ResultGet;
                foreach ($X->DocTipo as $Y) {
                    $datos[$Y->Id] = $Y->Desc;
                }
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta tipos de comprobantes
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarTiposComprobante() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEParamGetTiposCbte($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetTiposCbte');
            if (!isset($results->FEParamGetTiposCbteResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetTiposCbteResult->Errors)) {
                $error_str = "Error al realizar consulta de tipos de comprobantes: \n";
                foreach ($results->FEParamGetTiposCbteResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEParamGetTiposCbteResult->ResultGet;
                foreach ($X->CbteTipo as $Y) {
                    $datos[$Y->Id] = $Y->Desc;
                }
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta las alicuotas de IVA disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarAlicuotasIVA() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEParamGetTiposIva($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetTiposIva');
            if (!isset($results->FEParamGetTiposIvaResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetTiposIvaResult->Errors)) {
                $error_str = "Error al realizar consulta de alicuotas de iva: \n";
                foreach ($results->FEParamGetTiposIvaResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEParamGetTiposIvaResult->ResultGet;
                foreach ($X->IvaTipo as $Y) {
                    $datos[$Y->Id] = $Y->Desc;
                }
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta las condiciones de IVA disponibles
     * Este metodo solo se utiliza en el otro web service WSMTXCA
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarCondicionesIVA() {
        return NULL;
    }

    /**
     * Consulta los tipos de moneda disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarMonedas() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEParamGetTiposMonedas($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetTiposMonedas');
            if (!isset($results->FEParamGetTiposMonedasResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetTiposMonedasResult->Errors)) {
                $error_str = "Error al realizar consulta de monedas: \n";
                foreach ($results->FEParamGetTiposMonedasResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEParamGetTiposMonedasResult->ResultGet;
                foreach ($X->Moneda as $Y) {
                    $datos[$Y->Id] = $Y->Desc;
                }
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta la cotización de un moneda en particular
     * 
     * @param string $id_moneda Identificador de la moneda (dado por afip: Ej 'PES')
     * 
     * @comment: Para PES no trae nada.
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarCotizacionMoneda($id_moneda) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->MonId = $id_moneda;

            try {
                $results = $this->client->FEParamGetCotizacion($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEParamGetCotizacion');
            if (!isset($results->FEParamGetCotizacionResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEParamGetCotizacionResult->Errors)) {
                $error_str = "Error al realizar consulta la cotización: \n";
                foreach ($results->FEParamGetCotizacionResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $cotizacion = $results->FEParamGetCotizacionResult->ResultGet->MonCotiz;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "cotizacion" => $cotizacion);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta el numero de comprobante del último autorizado por AFIP
     * 
     * @param: $PV - Integer: Punto de venta
     * @param: $TC - Integer: Tipo de comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarUltimoComprobanteAutorizado($PV, $TC) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->PtoVta = $PV;
            $params->CbteTipo = $TC;

            try {
                $results = $this->client->FECompUltimoAutorizado($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FECompUltimoAutorizado');
            if (!isset($results->FECompUltimoAutorizadoResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECompUltimoAutorizadoResult->Errors)) {
                $error_str = "Error al realizar consulta del último número de comprobante: \n";
                foreach ($results->FECompUltimoAutorizadoResult->Errors->Err as $e) {
                    $error_str .= utf8_encode($e->Code . " - " . $e->Msg);
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $number = $results->FECompUltimoAutorizadoResult->CbteNro;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "number" => $number);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta un comprobante en AFIP y devuelve el XML correspondiente
     * 
     * @param: $PV - Integer: Punto de venta
     * @param: $TC - Integer: Tipo de comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarComprobante($PV, $TC, $NRO) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->FeCompConsReq = new stdClass();
            $params->FeCompConsReq->PtoVta = $PV;
            $params->FeCompConsReq->CbteTipo = $TC;
            $params->FeCompConsReq->CbteNro = $NRO;
            $results = $this->client->FECompConsultar($params);
            $this->checkErrors('FECompConsultar');
            if (!isset($results->FECompConsultarResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECompConsultarResult->Errors)) {
                $error_str = "Error al realizar consulta del comprobante: \n";
                foreach ($results->FECompConsultarResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $datos = $results->FECompConsultarResult->ResultGet;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Metodo privado que arma los parametros que pide el WSDL
     * 
     * @param type $voucher
     * @return \stdClass
     * 
     * @author: NeoComplexx Group S.A.
     */
    function _comprobanteCAEA($voucher) {
        $params = new stdClass();

        //Token************************************************
        $params->Auth = new stdClass();
        $params->Auth->Token = $this->token;
        $params->Auth->Sign = $this->sign;
        $params->Auth->Cuit = $this->cuit;
        $params->FeCAEARegInfReq = new stdClass();
        //Enbezado 1*********************************************
        $params->FeCAEARegInfReq->FeCabReq = new stdClass();
        $params->FeCAEARegInfReq->FeCabReq->CantReg = 1; //Para emitir lotes de comprobante, por eso los cambos CbteDesde-CbteHasta
        $params->FeCAEARegInfReq->FeCabReq->PtoVta = $voucher["numeroPuntoVenta"];
        $params->FeCAEARegInfReq->FeCabReq->CbteTipo = $voucher["codigoTipoComprobante"];
        //Enbezado 2*********************************************

        $comprobante = new stdClass();
        //El nro de comprobante no se envia
        $comprobante->DocTipo = $voucher["codigoTipoDocumento"];
        $comprobante->Concepto = $voucher["codigoConcepto"];
        $comprobante->DocNro = (float) $voucher["numeroDocumento"];
        $comprobante->CAEA = $voucher["caea"];
        $comprobante->CbteDesde = $voucher["numeroComprobante"];
        $comprobante->CbteHasta = $voucher["numeroComprobante"];
        $comprobante->CbteFch = $voucher["fechaComprobante"];
        $comprobante->ImpTrib = $voucher["importeOtrosTributos"];
        if ($comprobante->Concepto == 2 || $comprobante->Concepto == 3) {
            //En el caso de servicios los siguientes campos son obligatorios:
            $comprobante->FchServDesde = $voucher["fechaDesde"];
            $comprobante->FchServHasta = $voucher["fechaHasta"];
            $comprobante->FchVtoPago = $voucher["fechaVtoPago"];
        }
        //$comprobante->Opcionales //Array
        //PIE**************************************************
        $comprobante->MonId = $voucher["codigoMoneda"];
        $comprobante->MonCotiz = $voucher["cotizacionMoneda"];
        $comprobante->ImpIVA = $voucher["importeIVA"];
        $comprobante->ImpTotConc = $voucher["importeNoGravado"];
        $comprobante->ImpOpEx = $voucher["importeExento"];
        $comprobante->ImpNeto = $voucher["importeGravado"];
        $comprobante->ImpTotal = $voucher["importeTotal"];

        //IVA**************************************************
        if (array_key_exists("subtotivas", $voucher) && count($voucher["subtotivas"]) > 0) {
            $comprobante->Iva = array();
            foreach ($voucher["subtotivas"] as $value) {
                $iva = new stdClass();
                $iva->Id = $value["codigo"];
                $iva->BaseImp = $value["BaseImp"];
                $iva->Importe = $value["importe"];
                $comprobante->Iva[] = $iva;
            }
        }

        //OTROS TRIBUTO*****************************************
        if (array_key_exists("Tributos", $voucher) && count($voucher["Tributos"]) > 0) {
            $comprobante->Tributos = array();
            foreach ($voucher["Tributos"] as $value) {
                $tributo = new stdClass();
                $tributo->Id = $value["codigo"];
                $tributo->Desc = $value["descripcion"];
                $tributo->BaseImp = $value["baseImponible"];
                $tributo->Importe = $value["importe"];
                $tributo->Alic = $value["Alic"];
                $comprobante->Tributos[] = $tributo;
            }
        }

        $params->FeCAEARegInfReq->FeDetReq = array();

        $params->FeCAEARegInfReq->FeDetReq[] = $comprobante;

        return $params;
    }

    /**
     * Metodo privado que arma los parametros que pide el WSDL
     * 
     * @param type $voucher
     * @return \stdClass
     * 
     * @author: NeoComplexx Group S.A.
     */
    function _comprobante($voucher) {
        $params = new stdClass();

        //Token************************************************
        $params->Auth = new stdClass();
        $params->Auth->Token = $this->token;
        $params->Auth->Sign = $this->sign;
        $params->Auth->Cuit = $this->cuit;
        $params->FeCAEReq = new stdClass();
        //Enbezado 1*********************************************
        $params->FeCabReq = new stdClass();
        $params->FeCAEReq->FeCabReq = new stdClass();
        $params->FeCAEReq->FeCabReq->CantReg = 1; //Para emitir lotes de comprobante, por eso los cambos CbteDesde-CbteHasta
        $params->FeCAEReq->FeCabReq->PtoVta = $voucher["numeroPuntoVenta"];
        $params->FeCAEReq->FeCabReq->CbteTipo = $voucher["codigoTipoComprobante"];
        //Enbezado 2*********************************************
        $params->FeCAEReq->FeDetReq = new stdClass();

        $comprobante = new stdClass();
        //El nro de comprobante no se envia
        $comprobante->DocTipo = $voucher["codigoTipoDocumento"];
        $comprobante->Concepto = $voucher["codigoConcepto"];
        $comprobante->DocNro = (float) $voucher["numeroDocumento"];

        $comprobante->CbteDesde = $voucher["numeroComprobante"];
        $comprobante->CbteHasta = $voucher["numeroComprobante"];
        $comprobante->CbteFch = date('Ymd');
        $comprobante->ImpTrib = $voucher["importeOtrosTributos"];

        if ($comprobante->Concepto == 2 || $comprobante->Concepto == 3) {
            //En el caso de servicios los siguientes campos son obligatorios:
            $comprobante->FchServDesde = $voucher["fechaDesde"];
            $comprobante->FchServHasta = $voucher["fechaHasta"];
            $comprobante->FchVtoPago = $voucher["fechaVtoPago"];
        }

        //$comprobante->Opcionales //Array
        //PIE**************************************************
        $comprobante->MonId = $voucher["codigoMoneda"];
        $comprobante->MonCotiz = $voucher["cotizacionMoneda"];
        $comprobante->ImpIVA = $voucher["importeIVA"];
        $comprobante->ImpTotConc = $voucher["importeNoGravado"];
        $comprobante->ImpOpEx = $voucher["importeExento"];
        $comprobante->ImpNeto = $voucher["importeGravado"];
        $comprobante->ImpTotal = $voucher["importeTotal"];

        //IVA**************************************************
        if (array_key_exists("subtotivas", $voucher) && count($voucher["subtotivas"]) > 0) {
            $comprobante->Iva = array();
            foreach ($voucher["subtotivas"] as $value) {
                $iva = new stdClass();
                $iva->Id = $value["codigo"];
                $iva->BaseImp = $value["BaseImp"];
                $iva->Importe = $value["importe"];
                $comprobante->Iva[] = $iva;
            }
        }

        //OTROS TRIBUTO*****************************************
        if (array_key_exists("Tributos", $voucher) && count($voucher["Tributos"]) > 0) {
            $comprobante->Tributos = array();
            foreach ($voucher["Tributos"] as $value) {
                $tributo = new stdClass();
                $tributo->Id = $value["Id"];
                $tributo->Desc = $value["Desc"];
                $tributo->BaseImp = $value["BaseImp"];
                $tributo->Importe = $value["Importe"];
                $tributo->Alic = $value["Alic"];
                $comprobante->Tributos[] = $tributo;
            }
        }

        //COMPROBANTES ASOCIADOS*****************************************
        if (count($voucher["CbtesAsoc"]) > 0) {
            $comprobante->CbtesAsoc = array();
            foreach ($voucher["CbtesAsoc"] as $value) {
                $cbte = new stdClass();
                $cbte->Tipo = $value["Tipo"];
                $cbte->PtoVta = $value["PtoVta"];
                $cbte->Nro = $value["Nro"];
                $comprobante->CbtesAsoc[] = $cbte;
            }
        }

        $params->FeCAEReq->FeDetReq = array();

        $params->FeCAEReq->FeDetReq[] = $comprobante;

        return $params;
    }

    /**
     * Emite un comprobante electronico con el web service de afip
     * A partir de un arreglo asociativo con los datos necesarios 
     *  
     * @param: $voucher - Array: Datos del comprobante a emitir
     * 
     * @author: NeoComplexx Group S.A.
     */
    function emitirComprobante($voucher) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {

            $params = $this->_comprobante($voucher);

            try {
                $results = $this->client->FECAESolicitar($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FECAESolicitar');

            if (!isset($results->FECAESolicitarResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECAESolicitarResult->Errors)) {
                $error_str = "Error al generar comprobante electronico: ";
                foreach ($results->FECAESolicitarResult->Errors->Err as $e) {
                    $error_str .= utf8_encode($e->Code . " - " . $e->Msg);
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (property_exists($results->FECAESolicitarResult->FeCabResp, "Resultado") && $results->FECAESolicitarResult->FeCabResp->Resultado == 'R') {
                //Pedido rechazado
                $error_str = "Comprobante rechazado: ";
                $respuestas = $results->FECAESolicitarResult->FeDetResp->FECAEDetResponse;
                //1 respuesta por cada comprobante
                foreach ($respuestas as $r) {
                    if (isset($r->Observaciones)) {
                        foreach ($r->Observaciones->Obs as $e) {
                            $error_str .= utf8_encode($e->Code . " - " . $e->Msg);
                        }
                    }
                }

                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "cae" => -1, "fechaVencimientoCAE" => -1);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {

                $respuestas = $results->FECAESolicitarResult->FeDetResp->FECAEDetResponse; //Faltaria contemplar mas de 1 comprobante
                //Faltaria contemplar mas de 1 comprobante
                $cae = $respuestas[0]->CAE;
                $fecha_vencimiento = $respuestas[0]->CAEFchVto;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "cae" => $cae, "fechaVencimientoCAE" => $fecha_vencimiento);
            }
        } else {
            return $result;
        }
    }

    /**
     * Presenta un comprobante electronico con el web service de afip
     * A partir de un arreglo asociativo con los datos necesarios 
     *  
     * @param: $voucher - Array: Datos del comprobante a emitir
     * 
     * @author: NeoComplexx Group S.A.
     */
    function presentarComprobanteCAEA($voucher) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {

            $params = $this->_comprobanteCAEA($voucher);

            try {
                $results = $this->client->FECAEARegInformativo($params);
            } catch (Exception $e) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FECAEARegInformativo');

            if (!isset($results->FECAEARegInformativoResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECAEARegInformativoResult->Errors)) {
                $error_str = "Error al informar comprobante electronico: ";
                foreach ($results->FECAEARegInformativoResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (property_exists($results->FECAEARegInformativoResult->FeCabResp, "Resultado") && $results->FECAEARegInformativoResult->FeCabResp->Resultado == 'R') {
                //Pedido rechazado
                $error_str = "Comprobante rechazado: \n";
                $respuestas = $results->FECAEARegInformativoResult->FeDetResp->FECAEADetResponse;
                //1 respuesta por cada comprobante
                foreach ($respuestas as $r) {
                    if (isset($r->Observaciones)) {
                        foreach ($r->Observaciones->Obs as $e) {
                            $error_str .= "$e->Code - $e->Msg";
                        }
                    }
                }

                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "cae" => -1, "fechaVencimientoCAE" => -1);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {

                $respuestas = $results->FECAEARegInformativoResult->FeDetResp->FECAEADetResponse; //Faltaria contemplar mas de 1 comprobante
                //Faltaria contemplar mas de 1 comprobante
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK");
            }
        } else {
            return $result;
        }
    }

    /**
     * Solicita un CAEA (Codigo de autorizacion electronico anticipado)
     * 
     * Solicitar hasta 5 días corridos antes del inicio de quincena
     * 
     * @param int $periodo YYYYMM
     * @param short orden (Primera quincena: 1 - Segunda quincena: 2)
     * 
     * @author: NeoComplexx Group S.A.
     */
    function solicitarCAEA($periodo, $orden) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->Periodo = $periodo;
            $params->Orden = $orden;
            $results = $this->client->FECAEASolicitar($params);
            $this->checkErrors('FECAEASolicitar');
            if (!isset($results->FECAEASolicitarResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECAEASolicitarResult->Errors)) {
                $error_str = "Error al realizar solicitud de CAEA: ";
                
                foreach ($results->FECAEASolicitarResult->Errors->Err as $e) {
                    $error_str .= utf8_encode($e->Code . " - " . $e->Msg);
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $caea = $results->FECAEASolicitarResult->ResultGet->CAEA;
                $fecha_desde = $results->FECAEASolicitarResult->ResultGet->FchVigDesde;
                $fecha_hasta = $results->FECAEASolicitarResult->ResultGet->FchVigHasta;
                $fecha_tope = $results->FECAEASolicitarResult->ResultGet->FchTopeInf;
                $fecha_proceso = $results->FECAEASolicitarResult->ResultGet->FchProceso;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "caea" => $caea, "fecha_desde" => $fecha_desde, "fecha_hasta" => $fecha_hasta, "fecha_tope" => $fecha_tope, "fecha_proceso" => $fecha_proceso);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consultar un CAEA (Codigo de autorizacion electronico anticipado)
     * 
     * Sirve para consultar un CAEA que fue solicitado anteriormente
     * 
     * @param int $periodo YYYYMM
     * @param short orden (Primera quincena: 1 - Segunda quincena: 2)
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarCAEA($periodo, $orden) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->Periodo = $periodo;
            $params->Orden = $orden;
            $results = $this->client->FECAEAConsultar($params);
            $this->checkErrors('FECAEAConsultar');
            if (!isset($results->FECAEAConsultarResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECAEAConsultarResult->Errors)) {
                $error_str = "Error al realizar consulta de CAEA: \n";
                foreach ($results->FECAEAConsultarResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $caea = $results->FECAEAConsultarResult->ResultGet->CAEA;
                $fecha_desde = $results->FECAEAConsultarResult->ResultGet->FchVigDesde;
                $fecha_hasta = $results->FECAEAConsultarResult->ResultGet->FchVigHasta;
                $fecha_tope = $results->FECAEAConsultarResult->ResultGet->FchTopeInf;
                $fecha_proceso = $results->FECAEAConsultarResult->ResultGet->FchProceso;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "caea" => $caea, "fecha_desde" => $fecha_desde, "fecha_hasta" => $fecha_hasta, "fecha_tope" => $fecha_tope, "fecha_proceso" => $fecha_proceso);
            }
        } else {
            return $result;
        }
    }

    /**
     * Informar un CAEA sin movimiento
     * 
     * @param int $caea CAEA informado sin movimientos
     * @param int $pos Punto de Venta vinculado al CAEA
     * 
     * @author: NeoComplexx Group S.A.
     */
    function informarSinMovCAEA($caea, $pos) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->CAEA = $caea;
            $params->PtoVta = $pos;

            $results = $this->client->FECAEASinMovimiento($params);
            $this->checkErrors('FECAEASinMovimiento');
            if (!isset($results->FECAEASinMovimientoResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECAEASinMovimientoResult->Errors)) {
                $error_str = "Error al realizar el informe de CAEA sin movimientos: \n";
                foreach ($results->FECAEASinMovimientoResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $fecha = $results->FECAEASinMovimientoResult->FchProceso;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "fecha" => $fecha);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consultar CAEA sin movimiento 
     * 
     * @param int $caea CAEA informado sin movimientos
     * @param int $pos Punto de Venta vinculado al CAEA
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarSinMovCAEA($caea, $pos) {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->CAEA = $caea;
            $params->PtoVta = $pos;

            $results = $this->client->FECAEASinMovimientoConsultar($params);
            $this->checkErrors('FECAEASinMovimientoConsultar');
            if (!isset($results->FECAEASinMovimientoConsultarResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECAEASinMovimientoConsultarResult->Errors)) {
                $error_str = "Error al realizar consulta de CAEA sin movimientos: \n";
                foreach ($results->FECAEASinMovimientoConsultarResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $datos = $results->FECAEASinMovimientoConsultarResult->ResultGet;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * 
     * Sirve para consultar la cantidad maxima de registros para 
     * solcitud de CAE o Informacion de CAEA
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarMaxComprobantes() {
        $result = $this->checkToken();
        if ($result["code"] == Wsfev1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $results = $this->client->FECompTotXRequest($params);
            $this->checkErrors('FECompTotXRequest');
            if (!isset($results->FECompTotXRequestResult)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => Wsfev1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FECompTotXRequestResult->Errors)) {
                $error_str = "Error al realizar consulta de cantidad maxima de comprobantes: \n";
                foreach ($results->FECompTotXRequestResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfev1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $cantidad = $results->FECompTotXRequestResult->RegXReq;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "cantidad" => $cantidad);
            }
        } else {
            return $result;
        }
    }

}