<?php
include __DIR__ . '/vendor/autoload.php';

$openai_key = getenv( 'OPENAI_API_KEY', true );
if ( empty( $openai_key ) ) {
	echo 'Please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL;
	exit( 1 );
}

$messages[] = array(
	'role'    => 'user',
	'content' => 'Get some news from a random German news website on the internet. Please only respond in English.',
);
echo 'Prompt: ', $messages[0]['content'], PHP_EOL;
function chatgpt( $messages ) {
	global $openai_key;

	$functions = array(
		array(
			"name"=> "get_extracted_url_contents",
			"description"=> "Get the article contents of the given URL on the internet",
			"parameters"=> array(
				"type"=> "object",
				"properties"=> array(
					"url"=> array(
						"type"=> "string",
						"description"=> "The URL",
					),
				),
				"required"=> array( "url" ),
			)
		)
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
				'model'      => 'gpt-3.5-turbo-0613',
				'messages'   => $messages,
				'functions'  => $functions
			)
		)
	);
	$x = curl_exec( $ch );
	$output = json_decode( $x, true );
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
	if ( $message['function_call']['name'] === 'get_extracted_url_contents' ) {
		$args = json_decode( $message['function_call']['arguments'] );
		$opts = array('http' =>
		  array(
		    'timeout' => 8
		  )
		);
		$context  = stream_context_create($opts);
		echo 'Fetching ', $args->url, ' by request of ChatGPT.';
		$content = file_get_contents( $args->url, false, $context );
		$content = preg_replace( '#<header.*?</header>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<head.*?</head>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<footer.*?</footer>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<style.*?</style>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<script.*?</script>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<noscript.*?</noscript>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<svg.*?</svg>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<iframe.*?</iframe>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<img.*?</img>#is', '', $content ) ?: $content;
		$content = preg_replace( '#<figure.*?</figure>#is', '', $content ) ?: $content;
		$content = preg_replace( '#class="[^"]+"#i', '', $content ) ?: $content;
		$content = preg_replace( '#<!--.*?-->#is', '', $content ) ?: $content;
		$content = str_replace( '&nbsp;', ' ', $content );
		$content = strip_tags( $content, '<strong><h1><h2><h3><h4>' );
		$content = substr( preg_replace( '#[\t ]+#', ' ', $content ), 0, 4000 );
		$content = substr( preg_replace( '#(\s*\n)+#', PHP_EOL, $content ), 0, 4000 );

		$messages[] = array(
			'role'    => 'user',
			'name' => $message['function_call']['name'],
			'content' => $content,
		);
		$output = chatgpt( $messages );
		$message = $output['choices'][0]['message'];
		$messages[] = $message;
	}
}
$out = 'AI (' . $output['usage']['total_tokens'] . ' tokens used):' . PHP_EOL . trim( $message['content'] ) . PHP_EOL;
echo PHP_EOL . $out;
