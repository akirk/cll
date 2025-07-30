<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/MessageStreamer.php';

class MathExpressionTest extends TestCase
{
    private MessageStreamer $streamer;

    protected function setUp(): void
    {
        $this->streamer = new MessageStreamer(false); // No ANSI for testing
    }

    public function testFractionWithCdotParsing()
    {
        // Start with a clean output state
        iterator_to_array($this->streamer->outputMessage(''));
        
        $testExpression = "\\[\n   \\frac{1(1 + 1)}{2} = \\frac{1 \\cdot 2}{2} = 1\n   \\]";
        
        // Collect output from generator
        $result = implode('', iterator_to_array($this->streamer->outputMessage($testExpression)));
        
        // Expected: fractions converted to (numerator)/(denominator) format and \cdot converted to ⋅
        $expected = "(1(1 + 1))/(2) = (1 ⋅ 2)/(2) = 1";
        
        $this->assertEquals($expected, $result);
    }
    
    public function testBasicCdotSymbol()
    {
        iterator_to_array($this->streamer->outputMessage(''));
        
        $testExpression = "\\[ a \\cdot b \\]";
        
        $result = implode('', iterator_to_array($this->streamer->outputMessage($testExpression)));
        
        $expected = "a ⋅ b";
        
        $this->assertEquals($expected, $result);
    }
    
    public function testFractionParsing()
    {
        iterator_to_array($this->streamer->outputMessage(''));
        
        $testExpression = "\\[ \\frac{x}{y} \\]";
        
        $result = implode('', iterator_to_array($this->streamer->outputMessage($testExpression)));
        
        $expected = "(x)/(y)";
        
        $this->assertEquals($expected, $result);
    }
    
    public function testInlineMathWithCdot()
    {
        iterator_to_array($this->streamer->outputMessage(''));
        
        $testExpression = "\\( 2 \\cdot 3 \\)";
        
        $result = implode('', iterator_to_array($this->streamer->outputMessage($testExpression)));
        
        $expected = "2 ⋅ 3";
        
        $this->assertEquals($expected, $result);
    }

    public function testLatexToUnicodeConversion()
    {
        $testExpression = "\\[\n   \\frac{1(1 + 1)}{2} = \\frac{1 \\cdot 2}{2} = 1\n   \\]";
        
        $result = $this->streamer->convertLatexToUnicode($testExpression);
        
        $expected = "(1(1 + 1))/(2) = (1 ⋅ 2)/(2) = 1";
        
        $this->assertEquals($expected, $result);
    }

    public function testDebugInfo()
    {
        // Clear any existing chunks
        $this->streamer->clearChunks();
        
        // Process some messages
        iterator_to_array($this->streamer->outputMessage('test1'));
        iterator_to_array($this->streamer->outputMessage('test2'));
        
        $debugInfo = $this->streamer->getDebugInfo();
        
        $this->assertStringContainsString('Received 2 chunks:', $debugInfo);
        $this->assertStringContainsString('"test1"', $debugInfo);
        $this->assertStringContainsString('"test2"', $debugInfo);
        
        // Test clear chunks
        $this->streamer->clearChunks();
        $debugInfoAfterClear = $this->streamer->getDebugInfo();
        $this->assertStringContainsString('Received 0 chunks:', $debugInfoAfterClear);
    }
}
