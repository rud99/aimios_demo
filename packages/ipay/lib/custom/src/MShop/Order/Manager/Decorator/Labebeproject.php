<?php

namespace Aimeos\MShop\Order\Manager\Decorator;

class Labebeproject extends \Aimeos\MShop\Common\Manager\Decorator\Base
{
    private $attr = [
        'transaction_id' => [
            'code' => 'transaction_id',
            'internalcode' => 'mord."transaction_id"',
            'label' => 'Bank transaction Id',
            'type' => 'string',
            'internaltype' => \Aimeos\MW\DB\Statement\Base::PARAM_STR,
        ],
    ];

    public function getSaveAttributes(): array
    {
        return parent::getSaveAttributes() + $this->createAttributes($this->attr);
    }

    public function getSearchAttributes(bool $sub = true): array
    {
        return parent::getSearchAttributes($sub) + $this->createAttributes($this->attr);
    }
}