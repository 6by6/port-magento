<?php

namespace SixBySix\PortTest\Writer;

use SixBySix\Port\Writer\InvoiceWriter;

/**
 * Class InvoiceWriterTest.
 *
 * @author Aydin Hassan <aydin@hotmail.co.uk>
 *
 * @internal
 * @coversNothing
 */
final class InvoiceWriterTest extends \PHPUnit\Framework\TestCase
{
    protected $orderModel;
    protected $transactionResourceModel;
    protected $invoiceWriter;

    protected function setUp()
    {
        $this->orderModel = $this->getMockBuilder('Mage_Sales_Model_Order')
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $this->transactionResourceModel = $this->getMockBuilder('Mage_Core_Model_Resource_Transaction')
            ->setMethods([])
            ->getMock();

        $this->invoiceWriter = new InvoiceWriter($this->transactionResourceModel, $this->orderModel);
    }

    public function testExceptionIsThrownIfNoOrderId()
    {
        $this->expectException('Port\Exception\WriterException');
        $this->expectExceptionMessage('order_id must be set');
        $this->invoiceWriter->writeItem([]);
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
        $this->invoiceWriter->writeItem(['order_id' => 5]);
    }

    public function testInvoiceCannotBeCreatedIfOrderHasNoOrders()
    {
        $this->orderModel
            ->expects($this->once())
            ->method('loadByIncrementId')
            ->with(5);

        $this->orderModel
            ->expects($this->exactly(2))
            ->method('getId')
            ->will($this->returnValue(5));

        $invoice = $this->createMock('Mage_Sales_Model_Order_Invoice');
        $invoice
            ->expects($this->once())
            ->method('getData')
            ->with('total_qty')
            ->will($this->returnValue(0));

        $this->orderModel
            ->expects($this->once())
            ->method('prepareInvoice')
            ->will($this->returnValue($invoice));

        $this->expectException(
            'Port\Exception\WriterException'
        );
        $this->expectExceptionMessage(
            'Cannot create invoice without products. Order ID: "5"'
        );
        $this->invoiceWriter->writeItem(['order_id' => 5]);
    }

    public function testInvoiceCanBeCreatedIfOrderHasProducts()
    {
        $this->orderModel
            ->expects($this->once())
            ->method('loadByIncrementId')
            ->with(5);

        $this->orderModel
            ->expects($this->once())
            ->method('getId')
            ->will($this->returnValue(5));

        $invoice = $this->createMock('Mage_Sales_Model_Order_Invoice');
        $invoice
            ->expects($this->once())
            ->method('getData')
            ->with('total_qty')
            ->will($this->returnValue(1));

        $this->orderModel
            ->expects($this->once())
            ->method('prepareInvoice')
            ->will($this->returnValue($invoice));

        $invoice
            ->expects($this->once())
            ->method('setData')
            ->with('request_capture_case', 'offline');

        $this->transactionResourceModel
            ->expects($this->at(0))
            ->method('addObject')
            ->with($invoice)
            ->will($this->returnSelf());

        $invoice
            ->expects($this->any())
            ->method('getOrder')
            ->will($this->returnValue($this->orderModel));

        $this->transactionResourceModel
            ->expects($this->at(1))
            ->method('addObject')
            ->with($this->orderModel)
            ->will($this->returnSelf());

        $this->transactionResourceModel
            ->expects($this->once())
            ->method('save');

        $invoice
            ->expects($this->once())
            ->method('register');

        $this->invoiceWriter->writeItem(['order_id' => 5]);
    }

    public function testMagentoSaveExceptionIsThrownIfSaveFails()
    {
        $this->orderModel
            ->expects($this->once())
            ->method('loadByIncrementId')
            ->with(5);

        $this->orderModel
            ->expects($this->once())
            ->method('getId')
            ->will($this->returnValue(5));

        $invoice = $this->createMock('Mage_Sales_Model_Order_Invoice');
        $invoice
            ->expects($this->once())
            ->method('getData')
            ->with('total_qty')
            ->will($this->returnValue(1));

        $this->orderModel
            ->expects($this->once())
            ->method('prepareInvoice')
            ->will($this->returnValue($invoice));

        $invoice
            ->expects($this->once())
            ->method('setData')
            ->with('request_capture_case', 'offline');

        $this->transactionResourceModel
            ->expects($this->at(0))
            ->method('addObject')
            ->with($invoice)
            ->will($this->returnSelf());

        $invoice
            ->expects($this->any())
            ->method('getOrder')
            ->will($this->returnValue($this->orderModel));

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

        $invoice
            ->expects($this->once())
            ->method('register');

        $this->expectException('SixBySix\Port\Exception\MagentoSaveException');
        $this->expectExceptionMessage('Save Failed');
        $this->invoiceWriter->writeItem(['order_id' => 5]);
    }
}
