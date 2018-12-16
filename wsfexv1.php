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
    const MSG_ERROR_RESPONSE = "Respuesta con errores";
    const TA = "/token/TA.xml"; # Ticket de Acceso, from WSAA  
    const WSDL_PRODUCCION = "/wsdl/produccion/wsfexv1.wsdl";
    const URL_PRODUCCION = "https://servicios1.afip.gov.ar/wsfexv1/service.asmx";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wsfexv1.wsdl";
    const URL_HOMOLOGACION = "https://wswhomo.afip.gov.ar/wsfexv1/service.asmx";
    const PROXY_HOST = ""; # Proxy IP, to reach the Internet
    const PROXY_PORT = ""; # Proxy TCP port   
    const SERVICE_NAME = "wsfex";
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
            $this->wsdl = Wsfexv1::WSDL_PRODUCCION;
            $this->url = Wsfexv1::URL_PRODUCCION;
        } else {
            $this->wsdl = Wsfexv1::WSDL_HOMOLOGACION;
            $this->url = Wsfexv1::URL_HOMOLOGACION;
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
            $results = $this->client->FEXDummy(new stdClass());
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXDummy');
        $this->checkErrors($results, 'FEXDummy');
        return $results;
    }
    /**
     * Metodo privado que arma los parametros que pide el WSDL
     * 
     * @param type $voucher
     * @return \stdClass
     * 
     * @author: NeoComplexx Group S.A.
     */
    private function buildCAEXParams($voucher) {
        //Token************************************************
        $params = $this->buildBaseParams();
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
    public function emitirComprobante($voucher) {
        $params = $this->buildCAEXParams($voucher);
        try {
            $results = $this->client->FEXAuthorize($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXAuthorize');
        $this->checkErrors($results, 'FEXAuthorize');
        $response = $results->FEXAuthorizeResult->FEXResultAuth;
        return array("cae" => $response->Cae, "fechaVencimientoCAE" => $response->Fch_venc_Cae);
    }
    /**
     * Consulta un comprobante de exportación en AFIP y devuelve el XML correspondiente
     * 
     * @param: $PV - Integer: Punto de venta
     * @param: $TC - Integer: Tipo de comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarComprobante($PV, $TC, $NRO) {
        $params = $this->buildBaseParams();
        $params->Cmp = new stdClass();
        $params->Cmp->Cbte_tipo = $TC;
        $params->Cmp->Punto_vta = $PV;
        $params->Cmp->Cbte_nro = $NRO;
        try {
            $results = $this->client->FEXGetCMP($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetCMP');
        $this->checkErrors($results, 'FEXGetCMP');
        return $results->FEXGetCMPResult->FEXResultGet;
    }
    /**
     * Consulta el numero de comprobante del último autorizado por AFIP
     * 
     * @param: $PV - Integer: Punto de venta
     * @param: $TC - Integer: Tipo de comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarUltimoComprobanteAutorizado($PV, $TC) {
        $params = $this->buildBaseParams();
        $params->Auth->Cbte_Tipo = $TC;
        $params->Auth->Pto_venta = $PV;
        try {
            $results = $this->client->FEXGetLast_CMP($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetLast_CMP');
        $this->checkErrors($results, 'FEXGetLast_CMP');
        return $results->FEXGetLast_CMPResult->FEXResult_LastCMP->Cbte_nro;
    }
    /**
     * Recuperador de valores referenciales de CUITs de Países
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarCuitsPaises() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_DST_CUIT($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_DST_CUIT');
        $this->checkErrors($results, 'FEXGetPARAM_DST_CUIT');
        $results = $results->FEXGetPARAM_DST_CUITResult->FEXResultGet;
        $datos = array();
        foreach ($results->ClsFEXResponse_DST_cuit as $result) {
            $datos[$result->DST_CUIT] = $result->DST_Ds;
        }
        return $datos;
    }
    /**
     * Consulta los tipos de moneda disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarMonedas() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_MON($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_MON');
        $this->checkErrors($results, 'FEXGetPARAM_MON');
        $results = $results->FEXGetPARAM_MONResult->FEXResultGet;
        $datos = array();
        foreach ($results->ClsFEXResponse_Mon as $result) {
            $datos[$result->Mon_Id] = $result->Mon_Ds;
        }
        return $datos;
    }
    /**
     * Consulta tipos de comprobantes
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarTiposComprobante() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_Cbte_Tipo($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_Cbte_Tipo');
        $this->checkErrors($results, 'FEXGetPARAM_Cbte_Tipo');
        $results = $results->FEXGetPARAM_Cbte_TipoResult->FEXResultGet;
        $datos = array();
        foreach ($results->ClsFEXResponse_Cbte_Tipo as $result) {
            $datos[$result->Cbte_Id] = $result->Cbte_Ds;
        }
        return $datos;
    }
    /**
     * Consulta tipos de exportación
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarTiposExportacion() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_Tipo_Expo($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_Tipo_Expo');
        $this->checkErrors($results, 'FEXGetPARAM_Tipo_Expo');
        $results = $results->FEXGetPARAM_Tipo_ExpoResult->FEXResultGet;
        $datos = array();
        foreach ($results->ClsFEXResponse_Tex as $result) {
            $datos[$result->Tex_Id] = $result->Tex_Ds;
        }
        return $datos;
    }
    /**
     * Consulta las unidades de medida disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarUnidadesMedida() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_Umed($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_Umed');
        $this->checkErrors($results, 'FEXGetPARAM_Umed');
        $results = $results->FEXGetPARAM_UMedResult->FEXResultGet;
        $datos = array();
        foreach ($results->ClsFEXResponse_UMed as $result) {
            $datos[$result->Umed_Id] = $result->Umed_Ds;
        }
        return $datos;
    }
    /**
     * Consulta de idiomas
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarIdiomas() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_Idiomas($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_Idiomas');
        $this->checkErrors($results, 'FEXGetPARAM_Idiomas');
        $results = $results->FEXGetPARAM_IdiomasResult->FEXResultGet;
        $datos = array();
        foreach ($results->ClsFEXResponse_Idi as $result) {
            $datos[$result->Idi_Id] = $result->Idi_Ds;
        }
        return $datos;
    }
    /**
     * Consulta de paises
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarPaises() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_DST_Pais($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_DST_Pais');
        $this->checkErrors($results, 'FEXGetPARAM_DST_pais');
        $results = $results->FEXGetPARAM_DST_paisResult->FEXResultGet;
        $datos = array();
        foreach ($results->ClsFEXResponse_DST_pais as $result) {
            $datos[$result->DST_Codigo] = $result->DST_Ds;
        }
        return $datos;
    }
    /**
     * Consulta de cotización de moneda
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarCotizacion($mon_id) {
        $params = $this->buildBaseParams();
        $params->Mon_id = $mon_id;
        try {
            $results = $this->client->FEXGetPARAM_Ctz($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_Ctz');
        $this->checkErrors($results, 'FEXGetPARAM_Ctz');
        return $results->FEXGetPARAM_CtzResult->FEXResultGet->Mon_ctz;
    }
    /**
     * Consulta de puntos de venta habilitados para facturas de exportación
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarPuntosVenta() {
        $params = $this->buildBaseParams();
        try {
            $results = $this->client->FEXGetPARAM_PtoVenta($params);
        } catch (Exception $e) {
            throw new Exception(Wsfexv1::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }
        $this->logClientActivity('FEXGetPARAM_PtoVenta');
        $this->checkErrors($results, 'FEXGetPARAM_PtoVenta');
        $results = $results->FEXGetPARAM_PtoVentaResult->FEXResultGet; //TODO Ver cuando viene un resultado
        $datos = array();
        foreach ($results->ClsFEXResponse_PtoVenta as $result) {
            $datos[$result->PVE_Nro] = $result->PVE_Bloqueado;
        }
        return $datos;
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
            $this->client = new soapClient($this->base_dir . $this->wsdl, array('soap_version' => SOAP_1_2,
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
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME ."/tmp/functions.txt", print_r($this->client->__getFunctions(), TRUE));
                file_put_contents($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME ."/tmp/types.txt", print_r($this->client->__getTypes(), TRUE));
            }
        } catch (Exception $exc) {
            throw new Exception("Error: " . $exc->getTraceAsString());
        }
    }
    /**
     * Construye un objeto con los parametros basicos requeridos por todos los metodos del servcio.
     */
    private function buildBaseParams() {
        $this->checkToken();
        $params = new stdClass();
        $params->Auth = new stdClass();
        $params->Auth->Token = $this->token;
        $params->Auth->Sign = $this->sign;
        $params->Auth->Cuit = $this->cuit;
        return $params;
    }
    /**
     * Verifica la existencia y validez del token actual y solicita uno nuevo si corresponde.
     *
     * @author: NeoComplexx Group S.A.
     */
    private function checkToken() {
        if (!file_exists($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME . Wsfexv1::TA)) {
            $generateToken = TRUE;
        } else {
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME . Wsfexv1::TA);
            $expirationTime = date('c', strtotime($TA->header->expirationTime));
            $actualTime = date('c', date('U'));
            $generateToken = $actualTime >= $expirationTime;
        }
        if ($generateToken) {
            //renovamos el token
            $wsaa_client = new Wsaa(Wsfexv1::SERVICE_NAME, $this->modo, $this->cuit, $this->log_xmls);
            $wsaa_client->generateToken();
            //Recargamos con el nuevo token
            $TA = simplexml_load_file($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME .Wsfev1::TA);
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
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME . "/tmp/request-" . $method . ".xml", $this->client->__getLastRequest());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME . "/tmp/hdr-request-" . $method . ".txt", $this->client->
                __getLastRequestHeaders());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME . "/tmp/response-" . $method . ".xml", $this->client->__getLastResponse());
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . Wsfexv1::SERVICE_NAME . "/tmp/hdr-response-" . $method . ".txt", $this->client->
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
        if (!isset($results->{$calledMethod.'Result'})) {
            throw new Exception(Wsfexv1::MSG_BAD_RESPONSE . ' - ' . $calledMethod);
        } else if (isset($results->{$calledMethod.'Result'}->FEXErr) && $results->{$calledMethod.'Result'}->FEXErr->ErrCode !== 0) {
            $errorMsg = Wsfexv1::MSG_ERROR_RESPONSE . ' - ' . $calledMethod;
            $e = $results->{$calledMethod.'Result'}->FEXErr;
            $errorMsg .= "$e->ErrCode - $e->ErrMsg";
            throw new Exception($errorMsg);
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