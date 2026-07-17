<?php

class Gstr3bDto {
    public string $gstin = '';
    public string $fp = '';
    public Gstr3bOutwardSupply $sup_details;
    public Gstr3bItcSummary $itc_elg;
    public Gstr3bPayment $intr_ltfee;

    public function __construct() {
        $this->sup_details = new Gstr3bOutwardSupply();
        $this->itc_elg = new Gstr3bItcSummary();
        $this->intr_ltfee = new Gstr3bPayment();
    }
}
