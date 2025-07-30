<?php
require __DIR__ . '/vendor/autoload.php';

$openai_key = getenv( 'OPENAI_API_KEY', true );
if ( empty( $openai_key ) ) {
	echo 'Please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL;
	exit( 1 );
}

if ( ! isset( $_SERVER['argv'][1] ) ) {
	echo 'Please give a URL as parameter', PHP_EOL;
	exit( 1 );
}

$messages[] = array(
	'role'    => 'user',
	'content' => 'Please summarize the article at ' . $_SERVER['argv'][1] . ' in 3 sentences.',
);

function chatgpt( $messages ) {
	global $openai_key;

	$functions = array(
		array(
			'name'        => 'get_url_contents',
			'description' => 'Get the contens of the given URL on the internet',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'url' => array(
						'type'        => 'string',
						'description' => 'The URL',
					),
				),
				'required'   => array( 'url' ),
			),
		),
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
				'model'     => 'gpt-3.5-turbo-0613',
				'messages'  => $messages,
				'functions' => $functions,
			)
		)
	);
	$output = json_decode( curl_exec( $ch ), true );
	if ( isset( $output['error'] ) ) {
		echo $output['error']['message'], PHP_EOL;
		exit( 1 );
	}
	return $output;
}
$output = chatgpt( $messages );
$message = $output['choices'][0]['message'];
$messages[] = $message;
if ( isset( $message['function_call']['name'] ) ) {
	if ( $message['function_call']['name'] === 'get_url_contents' ) {
		$args = json_decode( $message['function_call']['arguments'] );
		echo 'Fetching ', $args->url, ' by request of ChatGPT.';
		set_error_handler( function () {} );
		$config = new \andreskrey\Readability\Configuration();
		$config->setFixRelativeURLs( true );
		$config->setOriginalURL( $args->url );
		$readability = new \andreskrey\Readability\Readability( $config );

		$readability->parse( file_get_contents( $args->url ) );
		$content = str_replace( '&#xD;', '', $readability->getContent() );


		$messages[] = array(
			'role'    => 'user',
			'name'    => $message['function_call']['name'],
			'content' => $content,
		);
		$output = chatgpt( $messages );
		$message = $output['choices'][0]['message'];
		$messages[] = $message;
	}
}
$out = 'AI (' . $output['usage']['total_tokens'] . ' tokens used):' . PHP_EOL . trim( $message['content'] ) . PHP_EOL;
echo PHP_EOL . $out;
