<?php

namespace Aimeos\MShop\Service\Provider\Payment;

use Zorb\IPay\Facades\IPay;
use Zorb\IPay\Enums\Intent;
use Zorb\IPay\Enums\CheckoutStatus;

class BankOfGeorgiaIPay extends \Aimeos\MShop\Service\Provider\Payment\Base
    implements \Aimeos\MShop\Service\Provider\Payment\Iface
{
    public function process( \Aimeos\MShop\Order\Item\Iface $order,
                             array $params = [] ) : ?\Aimeos\MShop\Common\Helper\Form\Iface
    {
        $items = [];

        $orderBaseItem = $this->getOrderBase( $order->getBaseId(), \Aimeos\MShop\Order\Item\Base\Base::PARTS_ALL );
        $orderPrice = self::convertToCents($orderBaseItem->getPrice()->getValue());
        $orderCost =  self::convertToCents($orderBaseItem->getPrice()->getCosts());

        foreach( $orderBaseItem->getProducts() as $product )
        {
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
//        dump($response);

        if (isset($response->status) && $response->status === CheckoutStatus::Created) {
            $order->setPaymentStatus(\Aimeos\MShop\Order\Item\Base::PAY_PENDING);
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

    private static function convertToCents($price)
    {
        return (int) ($price * 100);
    }
}
