<?php

namespace SixBySix\PortTest\Writer;

use SixBySix\Port\Writer\Shipment;

/**
 * Class ShipmentTest.
 *
 * @author Aydin Hassan <aydin@hotmail.co.uk>
 *
 * @internal
 * @coversNothing
 */
final class ShipmentTest extends \PHPUnit\Framework\TestCase
{
    protected $orderModel;
    protected $transactionResourceModel;
    protected $shipmentWriter;
    protected $trackingModel;
    protected $options;

    protected function setUp()
    {
        $this->orderModel = $this->getMockBuilder('Mage_Sales_Model_Order')
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $this->transactionResourceModel = $this->getMockBuilder('Mage_Core_Model_Resource_Transaction')
            ->setMethods([])
            ->getMock();

        $this->trackingModel = $this->getMockBuilder('Mage_Sales_Model_Order_Shipment_Track')
            ->setMethods([])
            ->getMock();

        $this->options = [
            'send_shipment_email' => 1,
        ];

        $this->shipmentWriter = new Shipment(
            $this->orderModel,
            $this->transactionResourceModel,
            $this->trackingModel,
            $this->options
        );
    }

    public function testExceptionIsThrownIfNoOrderId()
    {
        $this->expectException('Port\Exception\WriterException');
        $this->expectExceptionMessage('order_id must be set');
        $this->shipmentWriter->writeItem([]);
    }

    public function testExceptionIsThrownIfOrderCannotBeFound()
    {
        $this->orderModel
            ->expects($this->once())
            ->method('loadByIncrementId')
            ->with(5);

        $this->orderModel
            ->expects($this->once())
            ->method('getId')
            ->will($this->returnValue(null));

        $this->expectException(
            'Port\Exception\WriterException'
        );
        $this->expectExceptionMessage(
            'Order with ID: "5" cannot be found'
        );
        $this->shipmentWriter->writeItem(['order_id' => 5]);
    }

    public function testShipmentCanBeCreatedWithTracking()
    {
        $this->orderModel
            ->expects($this->once())
            ->method('loadByIncrementId')
            ->with(5);

        $this->orderModel
            ->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(5));

        $shipment = $this->createMock('Mage_Sales_Model_Order_Shipment');

        $this->orderModel
            ->expects($this->once())
            ->method('prepareShipment')
            ->will($this->returnValue($shipment));

        $shipment
            ->expects($this->once())
            ->method('register');

        $shipment
            ->expects($this->any())
            ->method('getOrder')
            ->will($this->returnValue($this->orderModel));

        $this->orderModel
            ->expects($this->once())
            ->method('setData')
            ->with('is_in_process', true);

        $this->transactionResourceModel
            ->expects($this->at(0))
            ->method('addObject')
            ->with($shipment)
            ->will($this->returnSelf());

        $this->transactionResourceModel
            ->expects($this->at(1))
            ->method('addObject')
            ->with($this->orderModel)
            ->will($this->returnSelf());

        $this->transactionResourceModel
            ->expects($this->once())
            ->method('save');

        $this->trackingModel
            ->expects($this->once())
            ->method('setShipment')
            ->with($shipment);

        $this->trackingModel
            ->expects($this->once())
            ->method('setData')
            ->with(
                [
                    'title' => 'Test Carrier',
                    'number' => '782773742',
                    'carrier_code' => 'custom',
                    'order_id' => 5,
                ]
            );

        $this->trackingModel
            ->expects($this->once())
            ->method('save');

        $this->shipmentWriter->writeItem(
            [
                'tracks' => [
                    0 => [
                        'carrier' => 'Test Carrier',
                        'tracking_number' => '782773742',
                    ],
                ],
                'items' => [
                    'items' => [
                        0 => [
                            'LineNo' => 70000,
                            'SKU' => 'FILAM317RL1',
                            'Qty' => 1,
                        ],
                        1 => [
                            'LineNo' => 70001,
                            'SKU' => 'FILAM317RL2',
                            'Qty' => 1,
                        ],
                    ],
                ],
                'order_id' => 5,
            ]
        );
    }

    public function testShipmentCanBeCreatedWithoutTracking()
    {
        $this->orderModel
            ->expects($this->once())
            ->method('loadByIncrementId')
            ->with(5);

        $this->orderModel
            ->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(5));

        $shipment = $this->createMock('Mage_Sales_Model_Order_Shipment');

        $this->orderModel
            ->expects($this->once())
            ->method('prepareShipment')
            ->will($this->returnValue($shipment));

        $shipment
            ->expects($this->once())
            ->method('register');

        $shipment
            ->expects($this->any())
            ->method('getOrder')
            ->will($this->returnValue($this->orderModel));

        $this->orderModel
            ->expects($this->once())
            ->method('setData')
            ->with('is_in_process', true);

        $this->transactionResourceModel
            ->expects($this->at(0))
            ->method('addObject')
            ->with($shipment)
            ->will($this->returnSelf());

        $this->transactionResourceModel
            ->expects($this->at(1))
            ->method('addObject')
            ->with($this->orderModel)
            ->will($this->returnSelf());

        $this->transactionResourceModel
            ->expects($this->once())
            ->method('save');

        $this->trackingModel
            ->expects($this->never())
            ->method('setShipment')
            ->with($shipment);

        $this->trackingModel
            ->expects($this->never())
            ->method('setData')
            ->with(
                [
                    'title' => 'Test Carrier',
                    'number' => '782773742',
                    'carrier_code' => 'custom',
                    'order_id' => 5,
                ]
            );

        $this->trackingModel
            ->expects($this->never())
            ->method('save');

        $this->shipmentWriter->writeItem(
            [
                'items' => [
                    'items' => [
                        0 => [
                            'LineNo' => 70000,
                            'SKU' => 'FILAM317RL1',
                            'Qty' => 1,
                        ],
                        1 => [
                            'LineNo' => 70001,
                            'SKU' => 'FILAM317RL2',
                            'Qty' => 1,
                        ],
                    ],
                ],
                'order_id' => 5,
            ]
        );
    }

    public function testMagentoSaveExceptionIsThrownIfSaveFails()
    {
        $this->orderModel
            ->expects($this->once())
            ->method('loadByIncrementId')
            ->with(5);

        $this->orderModel
            ->expects($this->any())
            ->method('getId')
            ->will($this->returnValue(5));

        $shipment = $this->createMock('Mage_Sales_Model_Order_Shipment');

        $this->orderModel
            ->expects($this->once())
            ->method('prepareShipment')
            ->will($this->returnValue($shipment));

        $shipment
            ->expects($this->once())
            ->method('register');

        $shipment
            ->expects($this->any())
            ->method('getOrder')
            ->will($this->returnValue($this->orderModel));

        $this->orderModel
            ->expects($this->once())
            ->method('setData')
            ->with('is_in_process', true);

        $this->transactionResourceModel
            ->expects($this->at(0))
            ->method('addObject')
            ->with($shipment)
            ->will($this->returnSelf());

        $this->transactionResourceModel
            ->expects($this->at(1))
            ->method('addObject')
            ->with($this->orderModel)
            ->will($this->returnSelf());

        $e = new \Mage_Core_Exception('Save Failed');
        $this->transactionResourceModel
            ->expects($this->once())
            ->method('save')
            ->will($this->throwException($e));

        $this->expectException('SixBySix\Port\Exception\MagentoSaveException');
        $this->expectExceptionMessage('Save Failed');
        $this->shipmentWriter->writeItem(['order_id' => 5]);
    }
}
