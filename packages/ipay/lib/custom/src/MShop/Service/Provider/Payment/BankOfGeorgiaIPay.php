<?php

namespace Aimeos\MShop\Service\Provider\Payment;

use Zorb\IPay\Facades\IPay;
use Zorb\IPay\Enums\Intent;
use Zorb\IPay\Enums\CheckoutStatus;

class BankOfGeorgiaIPay extends \Aimeos\MShop\Service\Provider\Payment\Base
    implements \Aimeos\MShop\Service\Provider\Payment\Iface
{
    private $statuses = [
        'CREATED' => \Aimeos\MShop\Order\Item\Base::PAY_PENDING,
        'VIEWED' => \Aimeos\MShop\Order\Item\Base::PAY_PENDING,
        'REJECTED' => \Aimeos\MShop\Order\Item\Base::PAY_REFUSED,
    ];

    public function process(\Aimeos\MShop\Order\Item\Iface $order,
                            array $params = []): ?\Aimeos\MShop\Common\Helper\Form\Iface
    {
        $items = [];

        $orderBaseItem = $this->getOrderBase($order->getBaseId(), \Aimeos\MShop\Order\Item\Base\Base::PARTS_ALL);
        $orderPrice = self::convertToCents($orderBaseItem->getPrice()->getValue());
        $orderCost = self::convertToCents($orderBaseItem->getPrice()->getCosts());

        foreach ($orderBaseItem->getProducts() as $product) {
            $price = $product->getPrice();
            $productId = $product->getId();
            $productName = $product->getName();
            $productQuantity = $product->getQuantity();
            $productAmount = self::convertToCents($this->getAmount($price, false));
            $items[] = IPay::purchaseItem($productId, $productAmount, $productQuantity, $productName);
        }


        $units[] = IPay::purchaseUnit($orderPrice + $orderCost);

//        if ($orderCost > 0) {
//            $units[] = IPay::purchaseUnit($orderCost);
//        }

        $response = IPay::checkout(Intent::Capture, $order->getId(), $units, $items);
//        dump($response);die;

        if (isset($response->status) && $response->status === CheckoutStatus::Created) {
            $order->setPaymentStatus($this->statuses[$response->status]);
            $order->set('transaction_id', $response->order_id);
            $this->saveOrder($order);

            if (isset($response->links)) {
                $link = collect($response->links)->filter(function ($item) {
                    return isset($item->rel) && $item->rel === 'approve';
                })->first();

                if (!$link || !isset($link->href)) {
                    return back();
                }

                return new \Aimeos\MShop\Common\Helper\Form\Standard($link->href, 'GET');
            }

        }
    }

    public function updateAsync(): bool
    {
        $manager = \Aimeos\MShop::create($this->getContext(), 'order');
        $search = $manager->filter(true);

        $expr = [
            $search->compare('==', 'order.statuspayment', [
                \Aimeos\MShop\Order\Item\Base::PAY_UNFINISHED,
                \Aimeos\MShop\Order\Item\Base::PAY_PENDING,
                \Aimeos\MShop\Order\Item\Base::PAY_AUTHORIZED]),
            $search->compare('!=', 'transaction_id', null),
        ];
        $search->setConditions($search->and($expr));

        $items = $manager->search($search);

        if ($items) {
            foreach ($items as $item) {
                $transactionId = $item->get('transaction_id');
                $response = IPay::orderStatus($transactionId);
                $status = $this->statuses[$response->status];
//                $order = $this->getOrder($item->getId());
                $item->setStatusPayment($status);
                $this->saveOrder($item);
            }
        }

        return true;
    }

    public function refund(\Aimeos\MShop\Order\Item\Iface $order): \Aimeos\MShop\Order\Item\Iface
    {

    }

    private static function convertToCents($price)
    {
        return (int)($price * 100);
    }
}
