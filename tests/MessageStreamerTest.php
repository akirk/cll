<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/MessageStreamer.php';

class MessageStreamerTest extends TestCase
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

    public function testTokenStreamProcessing()
    {
        // Test streaming the provided token array that builds "The Last Whisper" story
        $tokens = ["**","Title",":"," The"," Last"," Whisper","**\n\n","In"," a"," **","forgot","ten"," village","**,"," a"," **","young"," girl","**"," discovers"," an"," **","anc","ient"," book","**"," that"," holds"," the"," **","se","crets"," of"," lost"," voices","**","."," Each"," page"," unlock","s"," a"," **","wh","isper"," from"," the"," past","**","."," As"," she"," speaks"," the"," words",","," long","-b","ur","ied"," **","mem","ories","**"," awaken",","," revealing"," an"," **","ep","ic"," tale"," of"," love","**,"," **","betr","ay","al","**,"," and"," the"," **","power"," of"," memories","**"," to"," change"," the"," future","."];

        // Clear any existing state
        iterator_to_array($this->streamer->outputMessage(''));
        $this->streamer->clearChunks();

        // Process each token individually to simulate streaming
        $output = '';
        foreach ($tokens as $token) {
            $result = iterator_to_array($this->streamer->outputMessage($token));
            $output .= implode('', $result);
        }

        // Verify the tokens were stored
        $this->assertEquals(count($tokens), count($this->streamer->getChunks()));

        // The output should contain the story text without markdown formatting (since ANSI is false)
        $this->assertStringContainsString('Title: The Last Whisper', $output);
        $this->assertStringContainsString('forgotten village', $output);
        $this->assertStringContainsString('young girl', $output);
        $this->assertStringContainsString('ancient book', $output);
        $this->assertStringContainsString('secrets of lost voices', $output);
        $this->assertStringContainsString('whisper from the past', $output);
        $this->assertStringContainsString('memories', $output);
        $this->assertStringContainsString('epic tale of love', $output);
        $this->assertStringContainsString('betrayal', $output);
        $this->assertStringContainsString('power of memories', $output);
    }

    public function testTokenStreamBoldFormatting()
    {
        // Test that bold markers are handled correctly during token streaming
        $boldTokens = ["**", "bold", " text", "**", " normal"];

        iterator_to_array($this->streamer->outputMessage(''));
        $this->streamer->clearChunks();

        $output = '';
        foreach ($boldTokens as $token) {
            $result = iterator_to_array($this->streamer->outputMessage($token));
            $output .= implode('', $result);
        }

        // Should contain the text without bold markers (since ANSI is false)
        $this->assertStringContainsString('bold text normal', $output);
        $this->assertStringNotContainsString('**', $output);
    }

    public function testTokenStreamWordBoundaryBehavior()
    {
        // Test word boundary detection with fragmented tokens
        $fragmentedTokens = ["anc", "ient", " ", "book"];

        iterator_to_array($this->streamer->outputMessage(''));
        $this->streamer->clearChunks();

        $output = '';
        foreach ($fragmentedTokens as $token) {
            $result = iterator_to_array($this->streamer->outputMessage($token));
            $output .= implode('', $result);
        }

        $this->assertEquals('ancient book', $output);
    }

    public function testTokenStreamNewlineHandling()
    {
        // Test newline handling in token stream
        $newlineTokens = ["Line", " one", "\n", "Line", " two"];

        iterator_to_array($this->streamer->outputMessage(''));
        $this->streamer->clearChunks();

        $output = '';
        foreach ($newlineTokens as $token) {
            $result = iterator_to_array($this->streamer->outputMessage($token));
            $output .= implode('', $result);
        }

        $this->assertEquals("Line one\nLine two", $output);
    }

    public function testTokenStreamEmptyAndSpecialTokens()
    {
        // Test handling of empty strings and special characters
        $specialTokens = ["", "text", "", " ", ",", "", "more"];

        iterator_to_array($this->streamer->outputMessage(''));
        $this->streamer->clearChunks();

        $output = '';
        foreach ($specialTokens as $token) {
            $result = iterator_to_array($this->streamer->outputMessage($token));
            $output .= implode('', $result);
        }

        $this->assertEquals('text ,more', $output);
    }

    public function testTokenStreamStateConsistency()
    {
        // Test that internal state remains consistent across token boundaries
        $stateTestTokens = ["**", "start", " bold", "**", " normal", " **", "end", " bold", "**"];

        iterator_to_array($this->streamer->outputMessage(''));
        $this->streamer->clearChunks();

        $output = '';
        foreach ($stateTestTokens as $token) {
            $result = iterator_to_array($this->streamer->outputMessage($token));
            $output .= implode('', $result);
        }

        // Should have processed bold formatting correctly
        $this->assertStringContainsString('start bold normal end bold', $output);
        $this->assertStringNotContainsString('**', $output);
    }

    public function testTokenStreamMathExpressions()
    {
        // Test math expressions split across tokens
        $mathTokens = ["\\[", " \\frac{", "a}{", "b} ", "\\]"];

        iterator_to_array($this->streamer->outputMessage(''));
        $this->streamer->clearChunks();

        $output = '';
        foreach ($mathTokens as $token) {
            $result = iterator_to_array($this->streamer->outputMessage($token));
            $output .= implode('', $result);
        }

        $this->assertEquals('(a)/(b)', $output);
    }

    public function testCompleteStoryTokenProcessing()
    {
        // Test processing the complete story with ANSI enabled to check formatting
        $tokens = ["**","Title",":"," The"," Last"," Whisper","**\n\n","In"," a"," **","forgot","ten"," village","**,"," a"," **","young"," girl","**"," discovers"," an"," **","anc","ient"," book","**"," that"," holds"," the"," **","se","crets"," of"," lost"," voices","**","."," Each"," page"," unlock","s"," a"," **","wh","isper"," from"," the"," past","**","."," As"," she"," speaks"," the"," words",","," long","-b","ur","ied"," **","mem","ories","**"," awaken",","," revealing"," an"," **","ep","ic"," tale"," of"," love","**,"," **","betr","ay","al","**,"," and"," the"," **","power"," of"," memories","**"," to"," change"," the"," future","."];

        $ansiStreamer = new MessageStreamer(true); // Enable ANSI for this test
        iterator_to_array($ansiStreamer->outputMessage(''));

        $output = '';
        foreach ($tokens as $token) {
            $result = iterator_to_array($ansiStreamer->outputMessage($token));
            $output .= implode('', $result);
        }

        // Should contain ANSI formatting codes for bold text
        $this->assertStringContainsString("\033[1m", $output); // Bold on
        $this->assertStringContainsString("\033[m", $output);  // Reset
        $this->assertStringContainsString('Title: The Last Whisper', $output);
    }
}
