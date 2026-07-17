<?php

class Gstr1CdnrEntry {
    public string $ctin = '';
    public array $nt = []; // Gstr1CdnrNote[]
}

class Gstr1CdnrNote {
    public string $ntty = '';
    public string $nt_num = '';
    public string $nt_dt = '';
    public float $val = 0.00;
    public array $itms = []; // Gstr1CdnrItem[]

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}

class Gstr1CdnrItem {
    public int $num = 1;
    public Gstr1ItemDetail $itm_det;

    public function __construct() {
        $this->itm_det = new Gstr1ItemDetail();
    }
}
