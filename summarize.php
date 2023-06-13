<?php
$openai_key = getenv( 'OPENAI_API_KEY', true );
if ( empty( $openai_key ) ) {
	echo 'Please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL;
	exit( 1 );
}

$input = file_get_contents( 'php://stdin' );

$messages[] = array(
	'role'    => 'user',
	'content' => $input . PHP_EOL . PHP_EOL . 'Please summarize the above in 3 sentences:' . PHP_EOL . PHP_EOL,
	// 'content' => 'Which 3 hashtags would you give this article? Please output a JSON list:' . PHP_EOL . PHP_EOL . $input,
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
			'model'      => 'gpt-3.5-turbo-16k',
			'messages'   => $messages,
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
$out = 'AI (' . $output['usage']['total_tokens'] . ' tokens used):' . PHP_EOL . trim( $message['content'] ) . PHP_EOL;
echo PHP_EOL . $out;
