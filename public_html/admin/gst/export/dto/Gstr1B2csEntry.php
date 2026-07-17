<?php

class Gstr1B2csEntry {
    public string $sply_ty = 'INTRA'; // INTRA or INTER
    public string $typ = 'OE';
    public string $etin = '';
    public int $pos = 0;
    public array $inv = []; // Gstr1B2csInvoice[]
}

class Gstr1B2csInvoice {
    public string $inum = '';
    public string $idt = '';
    public float $val = 0.00;

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}
