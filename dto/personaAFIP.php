<?php
include_once (__DIR__ . '/domicilioFiscalAFIP.php');

class PersonaAFIP {

    public $apellido = "";
    public $domicilioFiscal = null;
    public $estadoClave = "";
    public $idPersona = 0;
    public $mesCierre = 0;
    public $nombre = "";
    public $tipoClave = "";
    public $tipoPersona = "";
    public $razonSocial = "";
    // public $fechaContratoSocial = null;
    // public $dependencia = null;

    public function __construct($responseObject)
    {
        $this->_map($responseObject);
    }

    private function _map($responseObject) {
        $this->apellido = $responseObject->apellido;
        $this->estadoClave = $responseObject->estadoClave;
        $this->idPersona = $responseObject->idPersona;
        $this->mesCierre = $responseObject->mesCierre;
        $this->nombre = $responseObject->nombre;
        $this->tipoClave = $responseObject->tipoClave;
        $this->tipoPersona = $responseObject->tipoPersona;
        
        if (isset($responseObject->domicilioFiscal)) {
            $this->domicilioFiscal = new DomicilioFiscalAFIP($responseObject->domicilioFiscal);
        }

        if (isset($responseObject->razonSocial)) {
            $this->razonSocial = $responseObject->razonSocial;
        }
        
    }
}
  