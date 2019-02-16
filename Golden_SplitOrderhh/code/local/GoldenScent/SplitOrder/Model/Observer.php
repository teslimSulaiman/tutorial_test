<?php
class GoldenScent_SplitOrder_Model_Observer
{
    const BRAND_IN_ORDER  = 1;
    const OBSERVER_HAS_RUN  = 'my_observer_has_run';

    public function SplitOrder(Varien_Event_Observer $observer)
    {
        if (Mage::registry(GoldenScent_SplitOrder_Model_Observer::OBSERVER_HAS_RUN))
        {
            Mage::log('Observer has ran', null, 'GoldenScent_SplitOrder.log');
            return;
        }

        $order = $observer->getEvent()->getOrder();
        $allItem = $order->getAllVisibleItems();
        foreach ($allItem as $item)
        {
            $product = $item->getProduct();
            $_product = Mage::getModel('catalog/product')->load($product->getId());
            $brand_Item = $_product->getData("brand");
            if ($brand_Item != "") {
                $brand[$product->getId()] =  $brand_Item;
                $itemQty[$product->getId()] = $item->getData('qty_ordered');
            }

        }
        $brandProductArray= array();
        foreach ($brand as $key => $val)
        {
            $brandProductArray[$val][] = $key;
        }
        if (count($brandProductArray) > GoldenScent_SplitOrder_Model_Observer::BRAND_IN_ORDER) {
          $this->createOrder($order, $brandProductArray, $itemQty);
        }
    }

    protected function createOrder($order, $array, $itemQty)
    {
        $orderParentId = $order->getId();
        foreach($array as $productId)
        {
            $quote = $this->assignCustomer($order);
            $quote = $this->addProduct($productId, $itemQty, $quote);
            $quote = $this->addBillingShipping($order, $quote);
            $quote->collectTotals()->save();
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll();
            $sub_order = $service->getOrder();
            $sub_order->setRelationParentId($orderParentId);
            $sub_order->save();
            $quote->setIsActive(0)->save();
            Mage::getSingleton('checkout/cart')->truncate()->save();
            Mage::log($orderParentId, null, 'mylogfile.log');
        }
        try {
            Mage::register(GoldenScent_SplitOrder_Model_Observer::OBSERVER_HAS_RUN, true);
        }
        catch (Exception $ex) {
            echo $ex->getMessage();
        }

    }

    protected function addProduct($productId, $itemQty, $quote)
    {
        foreach($productId as $id)
        {
            $product = Mage::getModel('catalog/product')->load($id);
            $quantity = $itemQty[$id];
            $quote->addProduct($product,new Varien_Object(array('qty'   => $quantity)));
        }
        return $quote;
    }

    protected function assignCustomer($order)
    {
        $store = $order->getStore();
        $quote = Mage::getModel('sales/quote')->setStoreId($store->getId());
        $quote->setCurrency($order->AdjustmentAmount->currencyID);
        $customer = $order->getCustomer();
        if($customer->getId()=="")
        {
            //Its guest order
            $quote->setCheckoutMethod('guest')
                ->setCustomerId(null)
                ->setCustomerEmail($order->getCustomerEmail())
                ->setCustomerIsGuest(true)
                ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
        } else {
            // Assign Customer To Sales Order Quote
            $quote->assignCustomer($customer);
        }

        return $quote;

    }

    protected function addBillingShipping($order, $quote)
    {
        $billingAddress = $order->getBillingAddress()->getData();
        $shippingAddress = $order->getShippingAddress()->getData();
        $billingAddress = $quote->getBillingAddress()->addData($billingAddress);
        $shippingAddress = $quote->getShippingAddress()->addData($shippingAddress);
        $paymentMethod = $order->getPayment()->getMethodInstance()->getCode();
        $shippingAddress->setCollectShippingRates(true)->collectShippingRates()
            ->setShippingMethod($order->getShippingMethod())
            ->setPaymentMethod($paymentMethod);
        // Set Sales Order Payment

        $quote->getPayment()->importData(array('method' => $paymentMethod));

        return $quote;
    }


}