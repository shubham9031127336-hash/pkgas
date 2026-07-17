<?php

class Gstr1NilEntry {
    public array $inv = []; // Gstr1NilInvoice[]
    public float $nil_amt = 0.00;
    public float $expt_amt = 0.00;
    public float $ngsup_amt = 0.00;

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}

class Gstr1NilInvoice {
    public string $inum = '';
    public string $idt = '';
    public float $val = 0.00;

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}
