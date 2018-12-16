<?php

include_once (__DIR__ . '/wsaa.php');

/**
 * Clase para emitir facturas de exportación electronicas online con AFIP
 * con el webservice wsfexv1
 * 
 * @author NeoComplexx Group S.A.
 */
class Wsfexv1 {

    //************* CONSTANTES ***************************** 
    const MSG_AFIP_CONNECTION = "No pudimos comunicarnos con AFIP: ";
    const MSG_BAD_RESPONSE = "Respuesta mal formada";
    const RESULT_ERROR = 1;
    const RESULT_OK = 0;
    const TA = "/token/TA.xml"; # Ticket de Acceso, from WSAA  
    const WSDL_PRODUCCION = "/wsdl/produccion/wsfexv1.wsdl";
    const URL_PRODUCCION = "https://servicios1.afip.gov.ar/wsfexv1/service.asmx";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wsfexv1.wsdl";
    const URL_HOMOLOGACION = "https://wswhomo.afip.gov.ar/wsfexv1/service.asmx";
    const PROXY_HOST = ""; # Proxy IP, to reach the Internet
    const PROXY_PORT = ""; # Proxy TCP port   
    const SERVICE_NAME = "wsfex";

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
            $this->wsdl = Wsfexv1::WSDL_PRODUCCION;
            $this->url = Wsfexv1::URL_PRODUCCION;
        } else {
            $this->wsdl = Wsfexv1::WSDL_HOMOLOGACION;
            $this->url = Wsfexv1::URL_HOMOLOGACION;
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
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "No existe el archivo de configuración de AFIP: " . $this->base_dir . $this->wsdl);
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
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/functions.txt", print_r($this->client->__getFunctions(), TRUE));
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . "/tmp/types.txt", print_r($this->client->__getTypes(), TRUE));
            }
        } catch (Exception $exc) {
            return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "Error: " . $exc->getTraceAsString());
        }

        return array("code" => Wsfexv1::RESULT_OK, "msg" => "Inicio correcto");
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
     * @author: NeoComplexx Group S.A.
     */
    function checkToken() {
        if (!file_exists($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . Wsfexv1::TA)) {
            $not_exist = TRUE;
        } else {
            $not_exist = FALSE;
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . Wsfexv1::TA);
            $expirationTime = date('c', strtotime($TA->header->expirationTime));
            $actualTime = date('c', date('U'));
        }

        if ($not_exist || $actualTime >= $expirationTime) {
            //renovamos el token
            $wsaa_client = new Wsaa("wsfex", $this->modo, $this->cuit, $this->log_xmls);
            $result = $wsaa_client->generateToken();
            if ($result["code"] == wsaa::RESULT_OK) {
                //Recargamos con el nuevo token
                $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . $this::SERVICE_NAME . Wsfexv1::TA);
            } else {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $result["msg"]);
            }
        }

        $this->token = $TA->credentials->token;
        $this->sign = $TA->credentials->sign;
        return array("code" => Wsfexv1::RESULT_OK, "msg" => "Ok, token valido");
    }

    /**
     * Consulta dummy para verificar funcionamiento del servicio
     * 
     * @author: NeoComplexx Group S.A.
     */
    function dummy() {
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();

            try {
                $results = $this->client->FEXDummy($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }
            $this->checkErrors('FEXDummy');

            if (!isset($results->FEXDummyResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXDummyResult->Errors)) {
                $error_str = "Error al realizar consulta dummy: \n";
                foreach ($results->FEXDummyResult->Errors->Err as $e) {
                    $error_str .= "$e->Code - $e->Msg";
                }
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring");
            } else {
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK");
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
    function _comprobante($voucher) {
        $params = new stdClass();

        //Token************************************************
        $params->Auth = new stdClass();
        $params->Auth->Token = $this->token;
        $params->Auth->Sign = $this->sign;
        $params->Auth->Cuit = $this->cuit;
        //Enbezado *********************************************
        $params->Cmp = new stdClass();
        $params->Cmp->Id = floatVal($voucher["numeroPuntoVenta"] . $voucher["numeroComprobante"]);
        $params->Cmp->Fecha_cbte = $voucher["fechaComprobante"]; // [N]
        $params->Cmp->Cbte_Tipo = $voucher["codigoTipoComprobante"]; // FEXGetPARAM_Cbte_Tipo
        $params->Cmp->Punto_vta = $voucher["numeroPuntoVenta"];
        $params->Cmp->Cbte_nro = $voucher["numeroComprobante"];
        $params->Cmp->Tipo_expo = $voucher["codigoConcepto"]; // FEXGetPARAM_Tipo_Expo 
        $params->Cmp->Permiso_existente = ""; // Permiso de embarque - S, N, NULL (vacío)
        //$params->Cmp->Permisos = array(); // [N]
        $params->Cmp->Dst_cmp = $voucher["codigoPais"]; // FEXGetPARAM_DST_pais
        $params->Cmp->Cliente = $voucher["nombreCliente"];
        $params->Cmp->Cuit_pais_cliente = ""; // FEXGetPARAM_DST_CUIT (No es necesario si se ingresa ID_impositivo)
        $params->Cmp->Domicilio_cliente = $voucher["domicilioCliente"];
        $params->Cmp->Id_impositivo = $voucher["numeroDocumento"];
        $params->Cmp->Moneda_Id = $voucher["codigoMoneda"]; // FEXGetPARAM_MON
        $params->Cmp->Moneda_ctz = $voucher["cotizacionMoneda"]; // FEXGetPARAM_Ctz
        //$params->Cmp->Obs_comerciales = ""; // [N]
        $params->Cmp->Imp_total = $voucher["importeTotal"];
        //$params->Cmp->Obs = ""; // [N]
        //$params->Cmp->Cmps_asoc = array(); // [N]
        //$params->Cmp->Forma_pago = $voucher["CondicionVenta"]; // [N]
        //$params->Cmp->Incoterms = ""; // Cláusula de venta - FEXGetPARAM_Incoterms [N]
        //$params->Cmp->Incoterms_Ds = ""; // [N]
        $params->Cmp->Idioma_cbte = $voucher["idiomaComprobante"]; // 2:Ingles - FEXGET_PARAM_IDIOMAS
        //$params->Cmp->Opcionales = array(); // [N]

        // Items
        if (array_key_exists("items", $voucher) && count($voucher["items"]) > 0) {
            $params->Cmp->Items = array();
            foreach ($voucher["items"] as $value) {
                $item = new stdClass();
                $item->Pro_codigo = $value["codigo"];
                $item->Pro_ds = $value["descripcion"];
                $item->Pro_qty = $value["cantidad"];
                $item->Pro_umed = $value["codigoUnidadMedida"]; // FEXGetPARAM_UMed 
                $item->Pro_precio_uni = $value["precioUnitario"];
                $item->Pro_bonificacion = $value["impBonif"];
                $item->Pro_total_item = $value["importeItem"];
                $params->Cmp->Items[] = $item;
            }
        }

        return $params;
    }

    /**
     * Emite un comprobante electrónico de exportación con el web service de afip
     * A partir de un arreglo asociativo con los datos necesarios 
     *  
     * @param: $voucher - Array: Datos del comprobante a emitir
     * 
     * @author: NeoComplexx Group S.A.
     */
    function emitirComprobante($voucher) {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {

            $params = $this->_comprobante($voucher);

            try {
                $results = $this->client->FEXAuthorize($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXAuthorize');
            if (!isset($results->FEXAuthorizeResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXAuthorizeResult->FEXErr) && $results->FEXAuthorizeResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al emitir comprobante de exportación: \n";
                $e = $results->FEXAuthorizeResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $response =$results->FEXAuthorizeResult->FEXResultAuth;
                $cae = $response->Cae;
                $fecha_vencimiento = $response->Fch_venc_Cae;
                return array("code" => Wsfev1::RESULT_OK, "msg" => "OK", "cae" => $cae, "fechaVencimientoCAE" => $fecha_vencimiento);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta un comprobante de exportación en AFIP y devuelve el XML correspondiente
     * 
     * @param: $PV - Integer: Punto de venta
     * @param: $TC - Integer: Tipo de comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarComprobante($PV, $TC, $NRO) {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->Cmp = new stdClass();
            $params->Cmp->Cbte_tipo = $TC;
            $params->Cmp->Punto_vta = $PV;
            $params->Cmp->Cbte_nro = $NRO;

            try {
                $results = $this->client->FEXGetCMP($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetCMP');
            if (!isset($results->FEXGetCMPResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetCMPResult->FEXErr) && $results->FEXGetCMPResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de comprobante: \n";
                $e = $results->FEXGetCMPResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $datos = $results->FEXGetCMPResult->FEXResultGet;
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
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
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->Auth->Cbte_Tipo = $TC;
            $params->Auth->Pto_venta = $PV;

            try {
                $results = $this->client->FEXGetLast_CMP($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetLast_CMP');
            if (!isset($results->FEXGetLast_CMPResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetLast_CMPResult->FEXErr) && $results->FEXGetLast_CMPResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de ultimo comprobante autorizado: \n";
                $e = $results->FEXGetLast_CMPResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $number = $results->FEXGetLast_CMPResult->FEXResult_LastCMP->Cbte_nro;
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "number" => $number);
            }
        } else {
            return $result;
        }
    }


    /**
     * Recuperador de valores referenciales de CUITs de Países
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarCuitsPaises() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_DST_CUIT($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_DST_CUIT');
            if (!isset($results->FEXGetPARAM_DST_CUITResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_DST_CUITResult->FEXErr) && $results->FEXGetPARAM_DST_CUITResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de CUITs paises: \n";
                $e = $results->FEXGetPARAM_DST_CUITResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_DST_CUITResult->FEXResultGet;
                foreach ($X->ClsFEXResponse_DST_cuit as $Y) {
                    $datos[$Y->DST_CUIT] = $Y->DST_Ds;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta los tipos de moneda disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarMonedas() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_MON($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_MON');
            if (!isset($results->FEXGetPARAM_MONResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_MONResult->FEXErr) && $results->FEXGetPARAM_MONResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de Monedas: \n";
                $e = $results->FEXGetPARAM_MONResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_MONResult->FEXResultGet;
                foreach ($X->ClsFEXResponse_Mon as $Y) {
                    $datos[$Y->Mon_Id] = $Y->Mon_Ds;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
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
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_Cbte_Tipo($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_Cbte_Tipo');
            if (!isset($results->FEXGetPARAM_Cbte_TipoResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_Cbte_TipoResult->FEXErr) && $results->FEXGetPARAM_Cbte_TipoResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de tipos de comprobantes: \n";
                $e = $results->FEXGetPARAM_Cbte_TipoResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_Cbte_TipoResult->FEXResultGet;
                foreach ($X->ClsFEXResponse_Cbte_Tipo as $Y) {
                    $datos[$Y->Cbte_Id] = $Y->Cbte_Ds;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta tipos de exportación
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarTiposExportacion() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_Tipo_Expo($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_Tipo_Expo');
            if (!isset($results->FEXGetPARAM_Tipo_ExpoResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_Tipo_ExpoResult->FEXErr) && $results->FEXGetPARAM_Tipo_ExpoResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de tipos de exportación: \n";
                $e = $results->FEXGetPARAM_Tipo_ExpoResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_Tipo_ExpoResult->FEXResultGet;
                foreach ($X->ClsFEXResponse_Tex as $Y) {
                    $datos[$Y->Tex_Id] = $Y->Tex_Ds;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta las unidades de medida disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarUnidadesMedida() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_Umed($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_Umed');
            if (!isset($results->FEXGetPARAM_UMedResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_UMedResult->FEXErr) && $results->FEXGetPARAM_UMedResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de unidades de medida: \n";
                $e = $results->FEXGetPARAM_UMedResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_UMedResult->FEXResultGet;
                foreach ($X->ClsFEXResponse_UMed as $Y) {
                    $datos[$Y->Umed_Id] = $Y->Umed_Ds;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta de idiomas
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarIdiomas() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_Idiomas($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_Idiomas');
            if (!isset($results->FEXGetPARAM_IdiomasResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_IdiomasResult->FEXErr) && $results->FEXGetPARAM_IdiomasResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de idiomas: \n";
                $e = $results->FEXGetPARAM_IdiomasResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_IdiomasResult->FEXResultGet;
                foreach ($X->ClsFEXResponse_Idi as $Y) {
                    $datos[$Y->Idi_Id] = $Y->Idi_Ds;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta de paises
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarPaises() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_DST_Pais($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_DST_Pais');
            if (!isset($results->FEXGetPARAM_DST_paisResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_DST_paisResult->FEXErr) && $results->FEXGetPARAM_DST_paisResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de paises: \n";
                $e = $results->FEXGetPARAM_DST_paisResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_DST_paisResult->FEXResultGet;
                foreach ($X->ClsFEXResponse_DST_pais as $Y) {
                    $datos[$Y->DST_Codigo] = $Y->DST_Ds;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta de cotización de moneda
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarCotizacion($Mon_id) {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;
            $params->Mon_id = $Mon_id;
            try {
                $results = $this->client->FEXGetPARAM_Ctz($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_Ctz');
            if (!isset($results->FEXGetPARAM_CtzResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_CtzResult->FEXErr) && $results->FEXGetPARAM_CtzResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de cotización de moneda: \n";
                $e = $results->FEXGetPARAM_CtzResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_CtzResult->FEXResultGet;
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $X->Mon_ctz);
            }
        } else {
            return $result;
        }
    }

    /**
     * Consulta de puntos de venta habilitados para facturas de exportación
     * 
     * @author: NeoComplexx Group S.A.
     */
    function consultarPuntosVenta() {
        $datos = array();
        $result = $this->checkToken();
        if ($result["code"] == Wsfexv1::RESULT_OK) {
            $params = new stdClass();
            $params->Auth = new stdClass();
            $params->Auth->Token = $this->token;
            $params->Auth->Sign = $this->sign;
            $params->Auth->Cuit = $this->cuit;

            try {
                $results = $this->client->FEXGetPARAM_PtoVenta($params);
            } catch (Exception $e) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), "datos" => NULL);
            }

            $this->checkErrors('FEXGetPARAM_PtoVenta');
            if (!isset($results->FEXGetPARAM_PtoVentaResult)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => Wsfexv1::MSG_BAD_RESPONSE, "datos" => NULL);
            } else if (isset($results->FEXGetPARAM_PtoVentaResult->FEXErr) && $results->FEXGetPARAM_PtoVentaResult->FEXErr->ErrCode !== 0) {
                $error_str = "Error al realizar consulta de puntos de venta: \n";
                $e = $results->FEXGetPARAM_PtoVentaResult->FEXErr;
                $error_str .= "$e->ErrCode - $e->ErrMsg";
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => $error_str, "datos" => NULL);
            } else if (is_soap_fault($results)) {
                return array("code" => Wsfexv1::RESULT_ERROR, "msg" => "$results->faultcode - $results->faultstring", "datos" => NULL);
            } else {
                $X = $results->FEXGetPARAM_PtoVentaResult->FEXResultGet; //TODO Ver cuando viene un resultado
                foreach ($X->ClsFEXResponse_PtoVenta as $Y) {
                    $datos[$Y->PVE_Nro] = $Y->$Y->PVE_Bloqueado;
                }
                return array("code" => Wsfexv1::RESULT_OK, "msg" => "OK", "datos" => $datos);
            }
        } else {
            return $result;
        }
    }

}