<?php

namespace SixBySix\PortTest\Exception;

use SixBySix\Port\Exception\AttributeNotExistException;

/**
 * Class AttributeNotExistExceptionTest.
 *
 * @author Aydin Hassan <aydin@hotmail.co.uk>
 *
 * @internal
 * @coversNothing
 */
final class AttributeNotExistExceptionTest extends \PHPUnit\Framework\TestCase
{
    public function testException()
    {
        $e = new AttributeNotExistException('some_attribute');
        $this->assertSame('Attribute with code: "some_attribute" does not exist', $e->getMessage());
        $this->assertSame(0, $e->getCode());
    }
}
