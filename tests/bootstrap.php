<?php

class TestCase extends PHPUnit\Framework\TestCase {
    protected function assertStringEqualsFileOrWrite( $expected_file_path, $actual_content ) {
        if ( ! file_exists( $expected_file_path ) ) {
            $dir = dirname( $expected_file_path );
            if ( ! is_dir( $dir ) ) {
                mkdir( $dir, 0755, true );
            }
            file_put_contents( $expected_file_path, $actual_content );
            file_put_contents( 'php://stderr', 'Updated fixture: ' . basename( $expected_file_path ) . "\n" );
        }
        $expected_content = file_get_contents( $expected_file_path );
        return $this->assertSame( rtrim( $expected_content ), rtrim( $actual_content ) );
    }
}

