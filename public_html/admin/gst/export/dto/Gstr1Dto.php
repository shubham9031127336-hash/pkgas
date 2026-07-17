<?php

class Gstr1Dto {
    public string $gstin = '';
    public string $fp = '';
    public float $gt = 0.00;
    public float $cur_gt = 0.00;
    public ?array $b2b = null;
    public ?array $b2cl = null;
    public ?array $b2cs = null;
    public ?array $cdnr = null;
    public ?array $cndnr = null;
    public ?array $hsn = null;
    public ?Gstr1NilEntry $nil = null;
    public ?Gstr1DocIssue $doc_issue = null;

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}
