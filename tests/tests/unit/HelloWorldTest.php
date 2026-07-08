<?php

use PHPUnit\Framework\TestCase;

class HelloWorldTest extends TestCase {
    public function testHelloWorld(): void {
        $this->assertEquals('Hello, World!', 'Hello, World!');
    }
}