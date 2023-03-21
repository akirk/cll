<?php
$openai_key = getenv( 'OPENAI_API_KEY', true );
if ( empty( $openai_key ) ) {
	echo 'Please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL;
	exit( 1 );
}

// Get the current usage.
$ch = curl_init();
curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/dashboard/billing/usage?end_date=' . date( 'Y-m-d' ) . '&start_date=2023-01-01' );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt(
	$ch,
	CURLOPT_HTTPHEADER,
	array(
		'Content-Type: application/json',
		'Authorization: Bearer ' . $openai_key,
	)
);
$output = json_decode( curl_exec( $ch ), true );
if ( isset( $output['error'] ) ) {
	echo $output['error']['message'], PHP_EOL;
	exit( 1 );
}
echo 'OpenAI usage: $', $output['total_usage'] / 100, PHP_EOL;

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
		)
	);

	curl_setopt(
		$ch,
		CURLOPT_POSTFIELDS,
		json_encode(
			array(
				'model'      => 'gpt-3.5-turbo',
				'messages'   => $messages,
				'max_tokens' => 1000,
			)
		)
	);
	$output = json_decode( curl_exec( $ch ), true );
	if ( isset( $output['error'] ) ) {
		echo $output['error']['message'], PHP_EOL;
		exit( 1 );
	}
	$message = $output['choices'][0]['message'];
	$messages[] = $message;
	$out = 'AI: ' . trim( $message['content'] ) . PHP_EOL;
	echo PHP_EOL . $out;
	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		fwrite( $fp, $out );
	}
}
echo 'Bye.', PHP_EOL;
