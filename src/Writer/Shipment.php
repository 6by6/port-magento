<?php

namespace SixBySix\Port\Writer;

use Port\Exception\WriterException;
use Port\Writer;
use SixBySix\Port\Exception\MagentoSaveException;
use SixBySix\Port\Options\OptionsParseTrait;

/**
 * Class Shipment.
 *
 * @author Six By Six <hello@sixbysix.co.uk>
 * @author Aydin Hassan <aydin@hotmail.co.uk>
 */
class Shipment implements Writer
{
    use OptionsParseTrait;

    /**
     * @var \Mage_Core_Model_Resource_Transaction
     */
    protected $transactionResourceModel;

    /**
     * @var \Mage_Sales_Model_Order
     */
    protected $orderModel;

    /**
     * @var \Mage_Sales_Model_Order_Shipment_Track
     */
    protected $trackingModel;

    /**
     * @var array
     */
    protected $options = [
        'send_shipment_email' => false,
    ];

    /**
     * @param \Mage_Sales_Model_Order                $order
     * @param \Mage_Core_Model_Resource_Transaction  $transactionResourceModel
     * @param \Mage_Sales_Model_Order_Shipment_Track $trackingModel
     * @param array                                  $options
     */
    public function __construct(
        \Mage_Sales_Model_Order $order,
        \Mage_Core_Model_Resource_Transaction $transactionResourceModel,
        \Mage_Sales_Model_Order_Shipment_Track $trackingModel,
        array $options
    ) {
        $this->orderModel = $order;
        $this->transactionResourceModel = $transactionResourceModel;
        $this->trackingModel = $trackingModel;
        $this->setOptions($options);
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options)
    {
        $this->options = $this->parseOptions($this->options, $options);
    }

    /**
     * @param array $item
     *
     * @throws \Port\Exception\WriterException
     * @throws \SixBySix\Port\Exception\MagentoSaveException
     *
     * @return \Ddeboer\DataImport\Writer\WriterInterface|void
     */
    public function writeItem(array $item)
    {
        if (!isset($item['order_id'])) {
            throw new WriterException('order_id must be set');
        }
        $order = clone $this->orderModel;
        $order->loadByIncrementId($item['order_id']);
        if (!$order->getId()) {
            throw new WriterException(sprintf('Order with ID: "%s" cannot be found', $item['order_id']));
        }

        try {
            $shipment = $order->prepareShipment();
            $shipment->register();
            $shipment->getOrder()->setData('is_in_process', true);

            $transactionSave = clone $this->transactionResourceModel;
            $transactionSave
                ->addObject($shipment)
                ->addObject($shipment->getOrder())
                ->save();

            if (array_key_exists('tracks', $item) && \is_array($item['tracks'])) {
                foreach ($item['tracks'] as $currentTrack) {
                    $tracking = clone $this->trackingModel;
                    $tracking->setShipment($shipment);
                    $tracking->setData(
                        [
                            'title' => $currentTrack['carrier'],
                            'number' => $currentTrack['tracking_number'],
                            'carrier_code' => 'custom',
                            'order_id' => $order->getId(),
                        ]
                    );
                    $tracking->save();
                }
            }
        } catch (\Exception $e) {
            throw new MagentoSaveException($e->getMessage());
        }

        if ($this->options['send_shipment_email']) {
            $shipment->sendEmail(true);
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
