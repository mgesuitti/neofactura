<?php
include_once (__DIR__ . '/domicilioFiscalAFIP.php');
include_once (__DIR__ . '/monotributoAFIP.php');
include_once (__DIR__ . '/regimenGeneralAFIP.php');

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
    public $datosRegimenGeneral = null;
    public $datosMonotributo = null;

    public function __construct($responseObject)
    {
        $this->_map($responseObject);
    }

    private function _map($responseObject) {
        $datosGenerales = $responseObject->datosGenerales;

        $this->apellido = $datosGenerales->apellido;
        $this->estadoClave = $datosGenerales->estadoClave;
        $this->idPersona = $datosGenerales->idPersona;
        $this->mesCierre = $datosGenerales->mesCierre;
        $this->nombre = $datosGenerales->nombre;
        $this->tipoClave = $datosGenerales->tipoClave;
        $this->tipoPersona = $datosGenerales->tipoPersona;
        
        if (isset($datosGenerales->domicilioFiscal)) {
            $this->domicilioFiscal = new DomicilioFiscalAFIP($datosGenerales->domicilioFiscal);
        }

        if (isset($datosGenerales->razonSocial)) {
            $this->razonSocial = $responseObject->razonSocial;
        }

        if (isset($responseObject->datosRegimenGeneral)) {
            $this->datosRegimenGeneral = new RegimenGeneralAFIP($responseObject->datosRegimenGeneral);
        }

        if (isset($responseObject->datosMonotributo)) {
            $this->datosMonotributo = new MonotributoAFIP($responseObject->datosMonotributo);
        }
        
    }
}
  