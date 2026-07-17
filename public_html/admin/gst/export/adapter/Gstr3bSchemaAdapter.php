<?php

require_once __DIR__ . '/SchemaAdapterInterface.php';
require_once __DIR__ . '/../dto/Gstr3bDto.php';

class Gstr3bSchemaAdapter implements SchemaAdapterInterface {

    public function adapt(object $dto, string $version = 'v1.0'): array {
        if (!$dto instanceof Gstr3bDto) {
            throw new InvalidArgumentException('Expected Gstr3bDto');
        }

        return [
            'gstin' => $dto->gstin,
            'fp' => $dto->fp,
            'sup_details' => [
                'osup_det' => [
                    'txval' => $dto->sup_details->osup_det->txval,
                    'iamt' => $dto->sup_details->osup_det->iamt,
                    'camt' => $dto->sup_details->osup_det->camt,
                    'samt' => $dto->sup_details->osup_det->samt,
                ],
                'osup_zero' => [
                    'txval' => $dto->sup_details->osup_zero->txval,
                    'iamt' => $dto->sup_details->osup_zero->iamt,
                    'camt' => $dto->sup_details->osup_zero->camt,
                    'samt' => $dto->sup_details->osup_zero->samt,
                ],
                'osup_nil_exmp' => [
                    'txval' => $dto->sup_details->osup_nil_exmp->txval,
                    'iamt' => $dto->sup_details->osup_nil_exmp->iamt,
                    'camt' => $dto->sup_details->osup_nil_exmp->camt,
                    'samt' => $dto->sup_details->osup_nil_exmp->samt,
                ],
                'isup_rev' => [
                    'txval' => $dto->sup_details->isup_rev->txval,
                    'iamt' => $dto->sup_details->isup_rev->iamt,
                    'camt' => $dto->sup_details->isup_rev->camt,
                    'samt' => $dto->sup_details->isup_rev->samt,
                ],
                'osup_ng' => [
                    'txval' => $dto->sup_details->osup_ng->txval,
                    'iamt' => $dto->sup_details->osup_ng->iamt,
                    'camt' => $dto->sup_details->osup_ng->camt,
                    'samt' => $dto->sup_details->osup_ng->samt,
                ],
            ],
            'itc_elg' => [
                'itc_avl' => [
                    'iamt' => $dto->itc_elg->itc_avl->iamt,
                    'camt' => $dto->itc_elg->itc_avl->camt,
                    'samt' => $dto->itc_elg->itc_avl->samt,
                    'csamt' => $dto->itc_elg->itc_avl->csamt,
                ],
                'itc_rev' => [
                    'iamt' => $dto->itc_elg->itc_rev->iamt,
                    'camt' => $dto->itc_elg->itc_rev->camt,
                    'samt' => $dto->itc_elg->itc_rev->samt,
                    'csamt' => $dto->itc_elg->itc_rev->csamt,
                ],
                'itc_net' => [
                    'iamt' => $dto->itc_elg->itc_net->iamt,
                    'camt' => $dto->itc_elg->itc_net->camt,
                    'samt' => $dto->itc_elg->itc_net->samt,
                    'csamt' => $dto->itc_elg->itc_net->csamt,
                ],
                'itc_inelg' => [
                    'iamt' => $dto->itc_elg->itc_inelg->iamt,
                    'camt' => $dto->itc_elg->itc_inelg->camt,
                    'samt' => $dto->itc_elg->itc_inelg->samt,
                    'csamt' => $dto->itc_elg->itc_inelg->csamt,
                ],
            ],
            'intr_ltfee' => [
                'intr_details' => $dto->intr_ltfee->intr_details,
                'lt_fee_details' => $dto->intr_ltfee->lt_fee_details,
            ],
        ];
    }
}
