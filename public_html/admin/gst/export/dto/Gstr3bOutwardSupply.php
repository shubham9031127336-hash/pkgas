<?php

class Gstr3bSupplyCategory {
    public float $txval = 0.00;
    public float $iamt = 0.00;
    public float $camt = 0.00;
    public float $samt = 0.00;
}

class Gstr3bOutwardSupply {
    public Gstr3bSupplyCategory $osup_det;
    public Gstr3bSupplyCategory $osup_zero;
    public Gstr3bSupplyCategory $osup_nil_exmp;
    public Gstr3bSupplyCategory $isup_rev;
    public Gstr3bSupplyCategory $osup_ng;

    public function __construct() {
        $this->osup_det = new Gstr3bSupplyCategory();
        $this->osup_zero = new Gstr3bSupplyCategory();
        $this->osup_nil_exmp = new Gstr3bSupplyCategory();
        $this->isup_rev = new Gstr3bSupplyCategory();
        $this->osup_ng = new Gstr3bSupplyCategory();
    }
}
