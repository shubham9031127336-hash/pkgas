<?php

class Gstr1B2clEntry {
    public int $pos = 0;
    public array $inv = []; // Gstr1B2clInvoice[]

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}

class Gstr1B2clInvoice {
    public string $inum = '';
    public string $idt = '';
    public float $val = 0.00;
    public array $itms = []; // Gstr1B2clItem[]

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}

class Gstr1B2clItem {
    public int $num = 1;
    public Gstr1ItemDetail $itm_det;

    public function __construct() {
        $this->itm_det = new Gstr1ItemDetail();
    }
}
