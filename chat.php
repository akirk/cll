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

// Start chatting.
$messages = array();
while ( true ) {
	$input = readline( '> ' );
	if ( empty( $input ) ) {
		continue;
	}
	if ( in_array( strtolower( trim( $input ) ), array( 'quit', 'exit', 'bye' ) ) ) {
		break;
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
				if ( ! empty( $json['choices'][0]['delta']['content'] ) ) {
					echo $json['choices'][0]['delta']['content'];
					$message .= $json['choices'][0]['delta']['content'];
				}
			}

			return strlen( $data );
		}
	);

	$output = curl_exec( $ch );

	echo PHP_EOL;
	$messages[] = $message;
	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		fwrite( $fp, $message . PHP_EOL );
	}
}
echo 'Bye.', PHP_EOL;
fclose( $fp );
