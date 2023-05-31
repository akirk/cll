<?php

$openai_key = getenv( 'OPENAI_API_KEY', true );
if ( empty( $openai_key ) ) {
	echo 'Please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL;
	exit( 1 );
}

$readline_history_file = __DIR__ . '/.history';
$full_history_file = __DIR__ . '/chat-history.txt';
$fp = fopen( $full_history_file, 'a' );
readline_read_history( $readline_history_file );

$initial_input = trim( implode( ' ', array_slice( $_SERVER['argv'], 1 ) ) );
// Start chatting.
$messages = array();
$multiline = false;
while ( true ) {
	if ( ! empty( $initial_input ) ) {
		$input = $initial_input;
		$initial_input = null;
	} else {
		$input = readline( '> ' );
	}
	if ( false !== $multiline ) {
		if ( '.' !== trim( $input ) ) {
			$multiline .= $input . PHP_EOL;
			continue;
		} else {
			$input = $multiline;
			// Finished with Multiline input.
			$multiline = false;
		}
	}

	if ( false === $input || in_array( strtolower( trim( $input ) ), array( 'quit', 'exit', 'bye' ) ) ) {
		break;
	}

	if ( empty( $input ) || '.' === $input ) {
		$multiline = '';
		echo 'Starting multiline input. End with the last message as just a dot.', PHP_EOL;
		continue;
	}

	readline_add_history( $input );
	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		readline_write_history( $readline_history_file );
		if ( empty( $messages ) ) {
			fwrite( $fp, PHP_EOL . '---' . PHP_EOL . PHP_EOL );
		}
		fwrite( $fp, '> ' . $input . PHP_EOL );
	}
	$messages[] = array(
		'role'    => 'user',
		'content' => $input,
	);

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt(
		$ch,
		CURLOPT_HTTPHEADER,
		array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $openai_key,
			'Transfer-Encoding: chunked',
		)
	);

	curl_setopt(
		$ch,
		CURLOPT_POSTFIELDS,
		json_encode(
			array(
				'model'      => 'gpt-3.5-turbo',
				'messages'   => $messages,
				'stream'     => true,
			)
		)
	);
	echo PHP_EOL;
	$message = '';

	curl_setopt(
		$ch,
		CURLOPT_WRITEFUNCTION,
		function ( $curl, $data ) use ( &$message ) {
			if ( 200 !== curl_getinfo( $curl, CURLINFO_HTTP_CODE ) ) {
				var_dump( curl_getinfo( $curl, CURLINFO_HTTP_CODE ) );
				$error = json_decode( trim( $data ), true );
				echo 'Error: ', $error['error']['message'], PHP_EOL;
				return strlen( $data );
			}
			$items = explode( 'data: ', $data );
			foreach ( $items as $item ) {
				$json = json_decode( trim( $item ), true );
				if ( isset( $json['choices'][0]['delta']['content'] ) ) {
					echo $json['choices'][0]['delta']['content'];
					$message .= $json['choices'][0]['delta']['content'];
				}
			}

			return strlen( $data );
		}
	);

	$output = curl_exec( $ch );

	echo PHP_EOL;
	$messages[] = array(
		'role'    => 'assistant',
		'content' => $message,
	);
	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		fwrite( $fp, $message . PHP_EOL );
	}
}
echo 'Bye.', PHP_EOL;
fclose( $fp );
