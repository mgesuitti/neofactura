<?php

include_once (__DIR__ . '/wsaa.php');
include_once (__DIR__ . '/wsafip.php');

/**
 * Clase para emitir facturas electronicas online con AFIP
 * con el webservice wsfev1
 * 
 * @author NeoComplexx Group S.A.
 */
class Wsfev1 extends WsAFIP {

    //************* CONSTANTES ***************************** **

    const WSDL_PRODUCCION = "/wsdl/produccion/wsfev1.wsdl";
    const URL_PRODUCCION = "https://servicios1.afip.gov.ar/wsfev1/service.asmx";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wsfev1.wsdl";
    const URL_HOMOLOGACION = "https://wswhomo.afip.gov.ar/wsfev1/service.asmx";

    public function __construct($cuit, $modo_afip) {
        $this->serviceName = "wsfe";
        // Si no casteamos a float, lo toma como long y el soap client
        // de windows no lo soporta - > lo transformaba en 2147483647
        $this->cuit = (float) $cuit;
        $this->modo = intval($modo_afip);
        if ($this->modo === Wsaa::MODO_PRODUCCION) {
            $this->wsdl = Wsfev1::WSDL_PRODUCCION;
            $this->url = Wsfev1::URL_PRODUCCION;
        } else {
            $this->wsdl = Wsfev1::WSDL_HOMOLOGACION;
            $this->url = Wsfev1::URL_HOMOLOGACION;
        }
        $this->initializeSoapClient(SOAP_1_2);
    }

    /**
     * Consulta dummy para verificar funcionamiento del servicio
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function dummy() {
        try {
            $results = $this->client->FEDummy(new stdClass());
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEDummy');
        $this->checkErrors($results, 'FEDummy');

        return $results;
    }

    /**
     * Consulta los tipos de otros tributos que pueden
     * enviarse en el comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarTiposTributos() {
        $params = $this->buildBaseParams();

        try {
            $results = $this->client->FEParamGetTiposTributos($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetTiposTributos');
        $this->checkErrors($results, 'FEParamGetTiposTributos');

        $results = $results->FEParamGetTiposTributosResult->ResultGet;
        $datos = array();
        foreach ($results->TributoTipo as $result) {
            $datos[$result->Id] = $result->Desc;
        }
        return $datos;
    }

    /**
     * Consulta campos auxiliares
     * Dependiendo de la actividad que realize el comercio
     * Puede que requiera mandar datos adicionales
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarCamposAuxiliares() {
        $params = $this->buildBaseParams();

        try {
            $results = $this->client->FEParamGetTiposOpcional($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetTiposOpcional');
        $this->checkErrors($results, 'FEParamGetTiposOpcional');

        $results = $results->FEParamGetTiposOpcionalResult->ResultGet;
        $datos = array();
        foreach ($results->OpcionalTipo as $result) {
            $datos[$result->Id] = $result->Desc;
        }
        return $datos;
    }

    /**
     * Consulta puntos de venta habilitados
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarPuntosVenta() {
        $params = $this->buildBaseParams();

        try {
            $results = $this->client->FEParamGetPtosVenta($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetPtosVenta');
        $this->checkErrors($results, 'FEParamGetPtosVenta');

        $results = $results->FEParamGetPtosVentaResult->ResultGet;
        $datos = array();
        foreach ($results->PtoVenta as $result) {
            $datos[$result->Nro] = $result->EmisionTipo;
        }
        return $datos;
    }

    /**
     * Consulta las unidades de medida disponibles
     * Este metodo solo se utiliza en el otro web service WSMTXCA
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarUnidadesMedida() {
        return NULL;
    }

    /**
     * Consulta los tipos de documento disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarTiposDocumento() {
        $params = $this->buildBaseParams();

        try {
            $results = $this->client->FEParamGetTiposDoc($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetTiposDoc');
        $this->checkErrors($results, 'FEParamGetTiposDoc');

        $results = $results->FEParamGetTiposDocResult->ResultGet;
        $datos = array();
        foreach ($results->DocTipo as $result) {
            $datos[$result->Id] = $result->Desc;
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
            $results = $this->client->FEParamGetTiposCbte($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetTiposCbte');
        $this->checkErrors($results, 'FEParamGetTiposCbte');

        $results = $results->FEParamGetTiposCbteResult->ResultGet;
        $datos = array();
        foreach ($results->CbteTipo as $result) {
            $datos[$result->Id] = $result->Desc;
        }
        return $datos;
    }

    /**
     * Consulta las alicuotas de IVA disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarAlicuotasIVA() {
        $params = $this->buildBaseParams();

        try {
            $results = $this->client->FEParamGetTiposIva($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetTiposIva');
        $this->checkErrors($results, 'FEParamGetTiposIva');

        $results = $results->FEParamGetTiposIvaResult->ResultGet;
        $datos = array();
        foreach ($results->IvaTipo as $result) {
            $datos[$result->Id] = $result->Desc;
        }
        return $datos;
    }

    /**
     * Consulta las condiciones de IVA disponibles
     * Este metodo solo se utiliza en el otro web service WSMTXCA
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarCondicionesIVA() {
        return NULL;
    }

    /**
     * Consulta los tipos de moneda disponibles
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarMonedas() {
        $params = $this->buildBaseParams();

        try {
            $results = $this->client->FEParamGetTiposMonedas($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetTiposMonedas');
        $this->checkErrors($results, 'FEParamGetTiposMonedas');

        $results = $results->FEParamGetTiposMonedasResult->ResultGet;
        $datos = array();
        foreach ($results->Moneda as $result) {
            $datos[$result->Id] = $result->Desc;
        }
        return $datos;
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
    public function consultarCotizacionMoneda($id_moneda) {
        $params = $this->buildBaseParams();
        $params->MonId = $id_moneda;

        try {
            $results = $this->client->FEParamGetCotizacion($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FEParamGetCotizacion');
        $this->checkErrors($results, 'FEParamGetCotizacion');

        return $results->FEParamGetCotizacionResult->ResultGet->MonCotiz;
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
        $params->PtoVta = $PV;
        $params->CbteTipo = $TC;

        try {
            $results = $this->client->FECompUltimoAutorizado($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECompUltimoAutorizado');
        $this->checkErrors($results, 'FECompUltimoAutorizado');

        return $results->FECompUltimoAutorizadoResult->CbteNro;
    }

    /**
     * Consulta un comprobante en AFIP y devuelve el XML correspondiente
     * 
     * @param: $PV - Integer: Punto de venta
     * @param: $TC - Integer: Tipo de comprobante
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarComprobante($PV, $TC, $NRO) {
        $params = $this->buildBaseParams();
        $params->FeCompConsReq = new stdClass();
        $params->FeCompConsReq->PtoVta = $PV;
        $params->FeCompConsReq->CbteTipo = $TC;
        $params->FeCompConsReq->CbteNro = $NRO;

        try {
            $results = $this->client->FECompConsultar($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECompConsultar');
        $this->checkErrors($results, 'FECompConsultar');

        return $results->FECompConsultarResult->ResultGet;
    }

    /**
     * Metodo privado que arma los parametros que pide el WSDL
     * 
     * @param type $voucher
     * @return \stdClass
     * 
     * @author: NeoComplexx Group S.A.
     */
    private function buildCAEAParams($voucher) {
        $params = $this->buildBaseParams();
        //Enbezado 1*********************************************
        $params->FeCAEARegInfReq = new stdClass();
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
    private function buildCAEParams($voucher) {
        $params = $this->buildBaseParams();
        //Enbezado 1*********************************************
        $params->FeCAEReq = new stdClass();
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

        // FACTURA DE CREDITO ELECTRONICO FCE by AleDC
        if (array_key_exists("Opcionales", $voucher) && count($voucher["Opcionales"]) > 0) {
            $comprobante->Opcionales = array();
            foreach ($voucher["Opcionales"] as $value) {
                $opcional = new stdClass();
                $opcional->Id = $value["id"];
                $opcional->Valor = $value["valor"];
                $comprobante->Opcionales[] = $opcional;
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
                $cbte->Cuit = $value["Cuit"];
                $cbte->CbteFch = $value["CbteFch"];
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
    public function emitirComprobante($voucher) {
        $params = $this->buildCAEParams($voucher);

        try {
            $results = $this->client->FECAESolicitar($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECAESolicitar');
        $this->checkErrors($results, 'FECAESolicitar');

        if (property_exists($results->FECAESolicitarResult->FeCabResp, "Resultado") && $results->FECAESolicitarResult->FeCabResp->Resultado == 'R') {
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

            throw new Exception($error_str);
        }

        $respuestas = $results->FECAESolicitarResult->FeDetResp->FECAEDetResponse;
        //Faltaria contemplar mas de 1 comprobante
        $cae = $respuestas[0]->CAE;
        $fecha_vencimiento = $respuestas[0]->CAEFchVto;
        return array("cae" => $cae, "fechaVencimientoCAE" => $fecha_vencimiento);
    }

    /**
     * Presenta un comprobante electronico con el web service de afip
     * A partir de un arreglo asociativo con los datos necesarios 
     *  
     * @param: $voucher - Array: Datos del comprobante a emitir
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function presentarComprobanteCAEA($voucher) {
        $params = $this->buildCAEAParams($voucher);

        try {
            $results = $this->client->FECAEARegInformativo($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECAEARegInformativo');
        $this->checkErrors($results, 'FECAEARegInformativo');

        if (property_exists($results->FECAEARegInformativoResult->FeCabResp, "Resultado") && $results->FECAEARegInformativoResult->FeCabResp->Resultado == 'R') {
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

            throw new Exception($error_str);
        }

        return true;
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
    public function solicitarCAEA($periodo, $orden) {
        $params = $this->buildBaseParams();
        $params->Periodo = $periodo;
        $params->Orden = $orden;
        
        try {
            $results = $this->client->FECAEASolicitar($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECAEASolicitar');
        $this->checkErrors($results, 'FECAEASolicitar');

        $caea = $results->FECAEASolicitarResult->ResultGet->CAEA;
        $fecha_desde = $results->FECAEASolicitarResult->ResultGet->FchVigDesde;
        $fecha_hasta = $results->FECAEASolicitarResult->ResultGet->FchVigHasta;
        $fecha_tope = $results->FECAEASolicitarResult->ResultGet->FchTopeInf;
        $fecha_proceso = $results->FECAEASolicitarResult->ResultGet->FchProceso;
        return array("caea" => $caea, "fecha_desde" => $fecha_desde, "fecha_hasta" => $fecha_hasta, "fecha_tope" => $fecha_tope, "fecha_proceso" => $fecha_proceso);
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
    public function consultarCAEA($periodo, $orden) {
        $params = $this->buildBaseParams();
        $params->Periodo = $periodo;
        $params->Orden = $orden;

        try {
            $results = $this->client->FECAEAConsultar($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECAEAConsultar');
        $this->checkErrors($results, 'FECAEAConsultar');

        $caea = $results->FECAEAConsultarResult->ResultGet->CAEA;
        $fecha_desde = $results->FECAEAConsultarResult->ResultGet->FchVigDesde;
        $fecha_hasta = $results->FECAEAConsultarResult->ResultGet->FchVigHasta;
        $fecha_tope = $results->FECAEAConsultarResult->ResultGet->FchTopeInf;
        $fecha_proceso = $results->FECAEAConsultarResult->ResultGet->FchProceso;
        return array("caea" => $caea, "fecha_desde" => $fecha_desde, "fecha_hasta" => $fecha_hasta, "fecha_tope" => $fecha_tope, "fecha_proceso" => $fecha_proceso);
    }

    /**
     * Informar un CAEA sin movimiento
     * 
     * @param int $caea CAEA informado sin movimientos
     * @param int $pos Punto de Venta vinculado al CAEA
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function informarSinMovCAEA($caea, $pos) {
        $params = $this->buildBaseParams();
        $params->CAEA = $caea;
        $params->PtoVta = $pos;

        try {
            $results = $this->client->FECAEASinMovimiento($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECAEASinMovimiento');
        $this->checkErrors($results, 'FECAEASinMovimiento');
        return $results->FECAEASinMovimientoResult->FchProceso;
    }

    /**
     * Consultar CAEA sin movimiento 
     * 
     * @param int $caea CAEA informado sin movimientos
     * @param int $pos Punto de Venta vinculado al CAEA
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarSinMovCAEA($caea, $pos) {
        $params = $this->buildBaseParams();
        $params->CAEA = $caea;
        $params->PtoVta = $pos;

        try {
            $results = $this->client->FECAEASinMovimientoConsultar($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECAEASinMovimientoConsultar');
        $this->checkErrors($results, 'FECAEASinMovimientoConsultar');
        return $results->FECAEASinMovimientoConsultarResult->ResultGet;
    }

    /**
     * 
     * Sirve para consultar la cantidad maxima de registros para 
     * solcitud de CAE o Informacion de CAEA
     * 
     * @author: NeoComplexx Group S.A.
     */
    public function consultarMaxComprobantes() {
        $params = $this->buildBaseParams();

        try {
            $results = $this->client->FECompTotXRequest($params);
        } catch (Exception $e) {
            throw new Exception(WsAFIP::MSG_AFIP_CONNECTION . $e->getMessage(), null, $e);
        }

        $this->logClientActivity('FECompTotXRequest');
        $this->checkErrors($results, 'FECompTotXRequest');
        return $results->FECompTotXRequestResult->RegXReq;
    }

    /**
     * Construye un objeto con los parametros basicos requeridos por todos los metodos del servcio wsfev1.
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
     * Revisa la respuesta de un web service en busca de errores y lanza una excepción si corresponde.
     * @param unknown $results
     * @param String $calledMethod
     * @throws Exception
     */
    private function checkErrors($results, $calledMethod) {
        if (!isset($results->{$calledMethod.'Result'})) {
            throw new Exception(WsAFIP::MSG_BAD_RESPONSE . ' - ' . $calledMethod);
        } else if (isset($results->{$calledMethod.'Result'}->Errors)) {
            $errorMsg = WsAFIP::MSG_ERROR_RESPONSE . ' - ' . $calledMethod;
            foreach ($results->{$calledMethod.'Result'}->Errors->Err as $e) {
                $errorMsg .= "\n $e->Code - $e->Msg";
            }
            throw new Exception($errorMsg);
        } else if (is_soap_fault($results)) {
            throw new Exception("$results->faultcode - $results->faultstring - $calledMethod");
        }
    }

}