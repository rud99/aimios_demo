<?php

namespace Aimeos\MShop\Service\Provider\Payment;

use Giorgijorji\LaravelTbcInstallment\LaravelTbcInstallment;

class TbcInstallment extends \Aimeos\MShop\Service\Provider\Payment\Base
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

        # Create new instance of LaravelTbcInstallment
        $tbcInstallment = new LaravelTbcInstallment();
        dd($tbcInstallment);
# Adding Single Product Example | Array
# Single Product structure and parameter types (name => string, price => float, quantity => integer) are validated upon adding
# If invalid product structure or parameters provided it will throw InvalidProductException
        $product = [
            'name' => "SampleProduct", // string - product name
            'price' => 12.33, // Value in GEL (decimal numbering); Note that if Quantity is more than 1, you must set total price
            'quantity' => 1, // integer - product quantity
        ];
# Call AddProduct

        $tbcInstallment->addProduct($product);

# Adding Multiple Products Example | Array => (Array)
# Single Product structure and parameter types (name => string, price => float, quantity => integer) are validated upon adding
# If invalid product structure or parameters provided will throw InvalidProductException
        $products = [
            [
                'name' => "SampleProduct1", // string - product name
                'price' => 12.33, // Value in GEL (decimal numbering); Note that if Quantity is more than 1, you must set total price
                'quantity' => 1, // integer - product quantity
            ],
            [
                'name' => "SampleProduct2", // string - product name
                'price' => 24.66, // Value in GEL (decimal numbering); Note that if Quantity is more than 1, you must set total price
                'quantity' => 2, // integer - product quantity
            ],
        ];
# Call AddProducts , that gets array of products

        $tbcInstallment->addProducts($products);

# To check or get added products you can simply call getProducts(), which will return array of products
        $addedProducts = $tbcInstallment->getProducts();

        /*
        * @param string your invoiceId -
        * The unique value of your system that is attached to the application, for example, is initiated by you
        * Application Id which is in your database.
        * When a customer enters into an installment agreement on the TBC Installment Site, you will receive this InvoiceId by email along with other details.
        * @param invoiceId must identify the application on your side.
        * @param decimal total price of all Products
        * On apply total price provided and products price sum is validated, else will throw exception
        * On apply if products are empty will throw ProductsNotFoundException
        * On apply if products price total sum and total price not equal it will throw InvalidProductPriceException
        */
        $response = $tbcInstallment->applyInstallmentApplication(1, 12.33);

# After applyInstallmentApplication you can get sessionId and redirect url to tbc installment web page
        if ($response['status_code'] === 200) {
            $sessionId = $tbcInstallment->getSessionId(); // string - session id for later use to cancel  installment
            $redirectUri = $tbcInstallment->getRedirectUri(); // string - redirect uri to tbc installment webpage
            # save session id to your database
            # then you can simply call laravel redirect method
            return redirect($redirectUri);
        } # else error acquired

# After that the application will processed by TBC you will receive this InvoiceId by email along with other details.
# Only after that you can Confirm or Cancel Installment application via your admin panel or as you wish
# Confirm Installment application example
        $response = $tbcInstallment->confirm($invoiceId, $sessionId, $priceTotal);
        if ($response['status_code'] === 200) {
            # TODO HERE YOUR STUFF
        } # else error acquired

# Cancel Installment application example, $sessionId is previously saved sessionId
        $response = $tbcInstallment->cancel($sessionId);
        if ($response['status_code'] === 200) {
            # TODO HERE YOUR STUFF
        } # else error acquired

# applyInstallmentApplication, confirm and cancel methods will return status code and message
# example of response
# status code 200 means all ok, any other status code is fail of request
        $response = [
            'status_code' => 200,
            'message' => 'ok',
        ];


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
