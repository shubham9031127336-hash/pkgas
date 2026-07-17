<?php

class Gstr1DocIssue {
    public int $doc_num = 0;
    public string $doc_type = 'Invoices';

    public function __construct($data = []) {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }
}
