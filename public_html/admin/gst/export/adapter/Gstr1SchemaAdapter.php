<?php

require_once __DIR__ . '/SchemaAdapterInterface.php';
require_once __DIR__ . '/../dto/Gstr1Dto.php';

class Gstr1SchemaAdapter implements SchemaAdapterInterface {

    public function adapt(object $dto, string $version = 'v1.0'): array {
        if (!$dto instanceof Gstr1Dto) {
            throw new InvalidArgumentException('Expected Gstr1Dto');
        }

        $json = [
            'gstin' => $dto->gstin,
            'fp' => $dto->fp,
            'gt' => $dto->gt,
            'cur_gt' => $dto->cur_gt,
        ];

        // B2B
        if ($dto->b2b !== null) {
            $json['b2b'] = [];
            foreach ($dto->b2b as $b2bEntry) {
                $invArr = [];
                foreach ($b2bEntry->inv as $inv) {
                    $invArr[] = [
                        'inum' => $inv['inum'],
                        'idt' => $inv['idt'],
                        'val' => $inv['val'],
                        'pos' => $inv['pos'],
                        'rchg' => $inv['rchg'],
                        'itms' => $inv['itms'],
                    ];
                }
                $json['b2b'][] = [
                    'ctin' => $b2bEntry->ctin,
                    'inv' => $invArr,
                ];
            }
        }

        // B2CL
        if ($dto->b2cl !== null) {
            $json['b2cl'] = [];
            foreach ($dto->b2cl as $b2clEntry) {
                $invArr = [];
                foreach ($b2clEntry->inv as $inv) {
                    $invArr[] = [
                        'inum' => $inv['inum'],
                        'idt' => $inv['idt'],
                        'val' => $inv['val'],
                        'itms' => $inv['itms'],
                    ];
                }
                $json['b2cl'][] = [
                    'pos' => $b2clEntry->pos,
                    'inv' => $invArr,
                ];
            }
        }

        // B2CS
        if ($dto->b2cs !== null) {
            $json['b2cs'] = [];
            foreach ($dto->b2cs as $b2csEntry) {
                $invArr = [];
                foreach ($b2csEntry->inv as $inv) {
                    $invArr[] = [
                        'inum' => $inv->inum,
                        'idt' => $inv->idt,
                        'val' => $inv->val,
                    ];
                }
                $json['b2cs'][] = [
                    'sply_ty' => $b2csEntry->sply_ty,
                    'typ' => $b2csEntry->typ,
                    'etin' => $b2csEntry->etin,
                    'pos' => $b2csEntry->pos,
                    'inv' => $invArr,
                ];
            }
        }

        // Nil rated
        if ($dto->nil !== null) {
            $invArr = [];
            foreach ($dto->nil->inv as $inv) {
                $invArr[] = [
                    'inum' => $inv->inum,
                    'idt' => $inv->idt,
                    'val' => $inv->val,
                ];
            }
            $json['nil'] = [
                'inv' => $invArr,
                'nil_amt' => $dto->nil->nil_amt,
                'expt_amt' => $dto->nil->expt_amt,
                'ngsup_amt' => $dto->nil->ngsup_amt,
            ];
        }

        // HSN Summary
        if ($dto->hsn !== null) {
            $json['hsn'] = [];
            foreach ($dto->hsn as $h) {
                $json['hsn'][] = [
                    'hsn_sc' => $h->hsn_sc,
                    'desc' => $h->desc,
                    'uqc' => $h->uqc,
                    'qty' => $h->qty,
                    'val' => $h->val,
                    'txval' => $h->txval,
                    'iamt' => $h->iamt,
                    'camt' => $h->camt,
                    'samt' => $h->samt,
                    'csamt' => $h->csamt,
                ];
            }
        }

        // Document Issue
        if ($dto->doc_issue !== null) {
            $json['doc_issue'] = [
                'doc_num' => $dto->doc_issue->doc_num,
                'doc_type' => $dto->doc_issue->doc_type,
            ];
        }

        return $json;
    }
}
