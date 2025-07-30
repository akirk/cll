<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/MessageStreamer.php';

class FixtureTestCase extends PHPUnit\Framework\TestCase {
    protected function assertStringEqualsFileOrWrite( $expected_file_path, $actual_content ) {
        if ( ! file_exists( $expected_file_path ) ) {
            $dir = dirname( $expected_file_path );
            if ( ! is_dir( $dir ) ) {
                mkdir( $dir, 0755, true );
            }
            file_put_contents( $expected_file_path, $actual_content );
            file_put_contents( 'php://stderr', 'Updated fixture: ' . basename( $expected_file_path ) . "\n" );
        }
        return $this->assertStringEqualsFile( $expected_file_path, $actual_content );
    }
}

class MessageStreamerTest extends FixtureTestCase
{
    private MessageStreamer $streamer;

    protected function setUp(): void
    {
        $this->streamer = new MessageStreamer(false); // No ANSI for testing
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
        // Load token stream from fixture
        $tokens = json_decode(file_get_contents(__DIR__ . '/fixtures/input/token-stream.json'), true);

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

        // Compare output against fixture
        $this->assertStringEqualsFileOrWrite(__DIR__ . '/fixtures/expected/token-stream-output.txt', $output);
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

    private function runFixtureTest($streamer, $expected_dir)
    {
        $fixturesDir = __DIR__ . '/fixtures/input';
        $inputFiles = glob($fixturesDir . '/*.json');

        $this->assertNotEmpty($inputFiles, 'No input fixture files found');

        foreach ($inputFiles as $inputFile) {
            $filename = basename($inputFile, '.json');
            $expectedFile = $expected_dir . '/' . $filename . '-output.txt';

            // Load token stream from fixture
            $tokens = json_decode(file_get_contents($inputFile), true);
            $this->assertIsArray($tokens, "Failed to decode JSON from $inputFile");

            // Clear any existing state
            iterator_to_array($streamer->outputMessage(''));
            $streamer->clearChunks();

            // Process each token individually to simulate streaming
            $output = '';
            foreach ($tokens as $token) {
                $result = iterator_to_array($streamer->outputMessage($token));
                $output .= implode('', $result);
            }

            // Compare output against fixture
            $this->assertStringEqualsFileOrWrite($expectedFile, $output);
        }
    }

    public function testAllInputFixtures()
    {
        $this->runFixtureTest($this->streamer, __DIR__ . '/fixtures/expected/');
    }

    public function testAllInputFixturesWithAnsi()
    {
        $ansiStreamer = new MessageStreamer(true);
        $this->runFixtureTest($ansiStreamer, __DIR__ . '/fixtures/expected-ansi/' );
    }
}
