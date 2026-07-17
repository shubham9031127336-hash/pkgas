<?php

class Gstr1B2bEntry {
    public string $ctin = '';
    public array $inv = []; // Gstr1B2bInvoice[]

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}

class Gstr1B2bInvoice {
    public string $inum = '';
    public string $idt = '';
    public float $val = 0.00;
    public int $pos = 0;
    public string $rchg = 'N';
    public array $itms = []; // Gstr1B2bItem[]

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}

class Gstr1B2bItem {
    public int $num = 1;
    public Gstr1ItemDetail $itm_det;

    public function __construct() {
        $this->itm_det = new Gstr1ItemDetail();
    }
}

class Gstr1ItemDetail {
    public float $txval = 0.00;
    public float $rt = 0.00;
    public float $iamt = 0.00;
    public float $camt = 0.00;
    public float $samt = 0.00;
    public float $csamt = 0.00;
}
