<?php

class Gstr3bItcCategory {
    public float $iamt = 0.00;
    public float $camt = 0.00;
    public float $samt = 0.00;
    public float $csamt = 0.00;
}

class Gstr3bItcSummary {
    public Gstr3bItcCategory $itc_avl;
    public Gstr3bItcCategory $itc_rev;
    public Gstr3bItcCategory $itc_net;
    public Gstr3bItcCategory $itc_inelg;

    public function __construct() {
        $this->itc_avl = new Gstr3bItcCategory();
        $this->itc_rev = new Gstr3bItcCategory();
        $this->itc_net = new Gstr3bItcCategory();
        $this->itc_inelg = new Gstr3bItcCategory();
    }
}
