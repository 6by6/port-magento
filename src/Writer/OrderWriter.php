<?php

namespace SixBySix\Port\Writer;

use Port\Exception\WriterException;
use Port\Writer;
use SixBySix\Port\Exception\MagentoSaveException;

/**
 * Class OrderWriter.
 *
 * @author Six By Six <hello@sixbysix.co.uk>
 * @author Aydin Hassan <aydin@wearejh.com>
 * @author Adam Paterson <adam@wearejh.com>
 */
class OrderWriter implements Writer
{
    /**
     * @var \Mage_Sales_Model_Quote
     */
    protected $quoteModel;

    /**
     * @var \Mage_Sales_Model_Convert_Quote
     */
    protected $convertQuoteModel;

    /**
     * @var \Mage_Customer_Model_Customer
     */
    protected $customerModel;

    /**
     * @var \Mage_Catalog_Model_Product
     */
    protected $productModel;

    /**
     * @var \Mage_Sales_Model_Quote_Item
     */
    protected $quoteItemModel;

    /**
     * @var null|string
     */
    protected $customerMappingAttribute;

    /**
     * @var null|string
     */
    protected $paymentMethodCode;

    /**
     * @param \Mage_Sales_Model_Quote         $quoteModel
     * @param \Mage_Sales_Model_Convert_Quote $convertQuoteModel
     * @param \Mage_Customer_Model_Customer   $customerModel
     * @param \Mage_Catalog_Model_Product     $productModel
     * @param \Mage_Sales_Model_Quote_Item    $quoteItemModel
     * @param string                          $customerMappingAttribute
     * @param string                          $paymentMethodCode
     */
    public function __construct(
        \Mage_Sales_Model_Quote $quoteModel,
        \Mage_Sales_Model_Convert_Quote $convertQuoteModel,
        \Mage_Customer_Model_Customer $customerModel,
        \Mage_Catalog_Model_Product $productModel,
        \Mage_Sales_Model_Quote_Item $quoteItemModel,
        $customerMappingAttribute = 'email',
        $paymentMethodCode = 'checkmo'
    ) {
        $this->quoteModel = $quoteModel;
        $this->convertQuoteModel = $convertQuoteModel;
        $this->customerModel = $customerModel;
        $this->productModel = $productModel;
        $this->quoteItemModel = $quoteItemModel;

        if (!\is_string($customerMappingAttribute) || !\is_string($paymentMethodCode)) {
            throw new \InvalidArgumentException(
                'Customer Mapping Attribute and Payment Method Code should be strings'
            );
        }

        $this->customerMappingAttribute = $customerMappingAttribute;
        $this->paymentMethodCode = $paymentMethodCode;
    }

    /**
     * @param string $attribute
     * @param string $value
     *
     * @return \Mage_Customer_Model_Customer
     */
    public function getCustomerByAttribute($attribute, $value)
    {
        return $this->customerModel
            ->getCollection()
            ->addAttributeToFilter($attribute, $value)
            ->addAttributeToSelect('*')
            ->getFirstItem();
    }

    /**
     * @param \Mage_Sales_Model_Quote       $quote
     * @param \Mage_Customer_Model_Customer $customer
     */
    public function addCustomerToQuote(\Mage_Sales_Model_Quote $quote, \Mage_Customer_Model_Customer $customer)
    {
        $quote->addData([
            'customer_firstname' => $customer->getData('firstname'),
            'customer_lastname' => $customer->getData('lastname'),
            'customer_email' => $customer->getData('email'),
        ]);

        $quote->setCustomerFirstname($customer->getData('firstname'));
        $quote->setCustomerLastname($customer->getData('lastname'));
        $quote->setCustomerEmail($customer->getData('email'));

        $billingAddress = $customer->getDefaultBillingAddress();
        if (!$billingAddress) {
            $billingAddress = $customer->getAddressesCollection()->getFirstItem();
        }

        $shippingAddress = $customer->getDefaultShippingAddress();
        if (!$shippingAddress) {
            $shippingAddress = $billingAddress;
        }

        $quote->getBillingAddress()->addData($billingAddress->getData());
        $quote->getShippingAddress()->addData($shippingAddress->getData());
        $quote->assignCustomer($customer);
    }

    /**
     * @param \Mage_Sales_Model_Quote $quote
     * @param array                   $items
     *
     * @throws WriterException
     */
    public function addProductsToQuote(\Mage_Sales_Model_Quote $quote, array $items)
    {
        foreach ($items as $item) {
            $product = $this->productModel->loadByAttribute('sku', $item['sku']);

            if (!$product) {
                throw new WriterException(sprintf('Product with SKU: %s does not exist in Magento', $item['sku']));
            }

            $quoteItem = clone $this->quoteItemModel;
            $quoteItem->setProduct($product);
            $quoteItem->setQuote($quote);
            $quoteItem->setQty($item['qty']);

            //set prices
            $quoteItem->addData([
                'price' => $item['price'],
                'base_price' => $item['price'],
                'original_price' => $item['price'],
                'custom_price' => $item['price'],
                'original_custom_price' => $item['price'],
            ]);

            $quote->addItem($quoteItem);
        }
    }

    /**
     * @param \Mage_Sales_Model_Quote $quote
     * @param array                   $item
     *
     * @return Mage_Sales_Model_Quote
     */
    public function addDetailsToQuote(\Mage_Sales_Model_Quote $quote, array $item)
    {
        $quote->getPayment()->addData(
            ['method' => $this->paymentMethodCode]
        );

        $quote->addData([
            'created_at' => $item['created_at'],
            'reserved_order_id' => $item['increment_id'],
        ]);

        $quote->getShippingAddress()->addData(['payment_method' => $this->paymentMethodCode]);
    }

    /**
     * @param \Mage_Sales_Model_Quote $quote
     * @param array                   $orderData
     *
     * @return \Mage_Sales_Model_Order
     */
    public function quoteToOrder(\Mage_Sales_Model_Quote $quote, array $orderData)
    {
        //Convert quote to order
        $order = $this->convertQuoteModel->toOrder($quote);

        //Convert address items to order address items
        $billingAddress = $this->convertQuoteModel->addressToOrderAddress($quote->getBillingAddress());
        $order->setBillingAddress($billingAddress);

        $shippingAddress = $this->convertQuoteModel->addressToOrderAddress($quote->getShippingAddress());
        $order->setShippingAddress($shippingAddress);

        // Convert quote items and add as order items
        foreach ($quote->getAllItems() as $quoteItem) {
            $orderItem = $this->convertQuoteModel->itemToOrderItem($quoteItem);

            $productSku = $quoteItem->getData('sku');

            $lineItem = null;
            foreach ($orderData['items'] as $item) {
                if ($item['sku'] === $productSku) {
                    $lineItem = $item;
                }
            }

            $orderItem->addData([
                'discount_amount' => $lineItem['discount_amount'],
                'base_discount_amount' => $lineItem['discount_amount'],
                'tax_amount' => $lineItem['tax_amount'],
                'base_tax_amount' => $lineItem['tax_amount'],
                'gw_price' => isset($lineItem['gw_price']) ? $lineItem['gw_price'] : 0,
                'base_gw_price' => isset($lineItem['gw_price']) ? $lineItem['gw_price'] : 0,
                'tax_percent' => $this->calculateTaxPercentage($lineItem['price'], $lineItem['tax_amount']),
            ]);

            $order->addItem($orderItem);
        }

        // Shipping information to order
        $order->setShippingAmount($orderData['shipping_amount']);

        // Convert and add payment instance
        $orderPayment = $this->convertQuoteModel->paymentToOrderPayment($quote->getPayment());
        $order->setPayment($orderPayment);

        // Set increment id if provided
        if ($quote->getIncrementId()) {
            $order->setIncrementId($quote->getIncrementId());
        }

        $grandTotal = $this->calculateGrandTotal($order, $orderData);
        $subTotal = $this->calculateSubtotal($order);
        //Set Data
        $order->addData([
            'created_at' => $quote->getCreatedAt(),
            'base_grand_total' => $grandTotal,
            'grand_total' => $grandTotal,
            'base_subtotal' => $subTotal,
            'subtotal' => $subTotal,
            'gw_price' => $orderData['gw_price'],
            'base_gw_price' => $orderData['gw_price'],
            'discount_amount' => $orderData['discount_amount'],
            'base_discount_amount' => $orderData['discount_amount'],
        ]);

        return $order;
    }

    /**
     * @param float $price
     * @param float $taxAmount
     *
     * @return float
     */
    public function calculateTaxPercentage($price, $taxAmount)
    {
        if (0 === (int) $taxAmount) {
            return 0;
        }

        return round((100 / $price) * $taxAmount, 1);
    }

    /**
     * @param \Mage_Sales_Model_Order $order
     * @param array                   $orderData
     *
     * @return float
     */
    public function calculateGrandTotal(\Mage_Sales_Model_Order $order, array $orderData)
    {
        $grandTotal = 0;
        $grandTotal += $this->calculateSubTotal($order);

        $grandTotal += $orderData['shipping_amount'];
        $grandTotal += $orderData['gw_price'];
        $grandTotal -= $orderData['discount_amount'];

        return $grandTotal;
    }

    /**
     * @param \Mage_Sales_Model_Order $order
     *
     * @return float
     */
    public function calculateSubTotal(\Mage_Sales_Model_Order $order)
    {
        $subTotal = 0;

        foreach ($order->getAllItems() as $item) {
            $subTotal += $item->getData('price');
            $subTotal += $item->getData('tax_amount');
        }

        return $subTotal;
    }

    /**
     * @param array $item
     *
     * @throws \Port\Exception\WriterException
     * @throws \SixBySix\Port\Exception\MagentoSaveException
     *
     * @return $this|void
     */
    public function writeItem(array $item)
    {
        $quote = clone $this->quoteModel;

        //find customer
        $customer = $this->getCustomerByAttribute(
            $this->customerMappingAttribute,
            $item[$this->customerMappingAttribute]
        );

        if (!$customer->getId()) {
            throw new WriterException(
                sprintf(
                    'Customer could not be found. Using field "%s" with value "%s"',
                    $this->customerMappingAttribute,
                    $item[$this->customerMappingAttribute]
                )
            );
        }

        if (!\count($item['items'])) {
            throw new WriterException(sprintf('No Order Items for Order: "%s"', $item['increment_id']));
        }

        $this->addCustomerToQuote($quote, $customer);
        $this->addProductsToQuote($quote, $item['items']);
        $this->addDetailsToQuote($quote, $item);

        //save quote
        $quote->save();

        $quote->collectTotals();

        //create order from Quote
        $order = $this->quoteToOrder($quote, $item);

        try {
            $order->place();
            $order->save();
            $quote->setIsActive(false)->save();
        } catch (\Mage_Core_Exception $e) {
            throw new MagentoSaveException($e->getMessage());
        } catch (\Exception $e) {
            throw new MagentoSaveException($e->getMessage());
        }
    }

    /**
     * Prepare the writer before writing the items.
     */
    public function prepare()
    {
        return $this;
    }

    /**
     * Wrap up the writer after all items have been written.
     */
    public function finish()
    {
        return $this;
    }
}
