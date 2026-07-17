<?php

class Gstr1HsnEntry {
    public string $hsn_sc = '';
    public string $desc = '';
    public string $uqc = 'NOS';
    public float $qty = 0.00;
    public float $val = 0.00;
    public float $txval = 0.00;
    public float $iamt = 0.00;
    public float $camt = 0.00;
    public float $samt = 0.00;
    public float $csamt = 0.00;

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}
