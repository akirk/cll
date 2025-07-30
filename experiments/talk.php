<?php
$version = '1.0.0';
$openai_key = getenv( 'OPENAI_API_KEY', true );
$supported_voices = array();
$ansi = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );

putenv( 'RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1' );
$online = gethostbyname( 'api.openai.com' ) !== 'api.openai.com';

if ( ! $online ) {
	echo 'This only works online since it uses OpenAI.', PHP_EOL;
	exit( 1 );
}

if ( empty( $openai_key ) ) {
	echo 'Please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL, PHP_EOL;
	exit( 1 );
}


if ( ! exec( 'which play' ) && ! exec( 'which sox' ) ) {
	echo 'Please install sox:', PHP_EOL;
	echo 'brew install sox', PHP_EOL, PHP_EOL;
	exit( 1 );
}

$ch = curl_init();
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

$readline_history_file = __DIR__ . '/.talkhistory';
$history_base_directory = __DIR__ . '/talks/';
if ( ! file_exists( $history_base_directory ) ) {
	if ( ! mkdir( $history_base_directory ) ) {
		echo 'Could not create history directory: ', $history_base_directory, PHP_EOL;
		exit( 1 );
	}
}
$time = time();
$history_directory = $history_base_directory . date( 'Y/m', $time );

$options = getopt( 'v:hs:c', array( 'help', 'version' ), $initial_input );
$speed = '1.0';

$supported_voices['echo'] = 'openai';
$supported_voices['alloy'] = 'openai';
$supported_voices['fable'] = 'openai';
$supported_voices['onyx'] = 'openai';
$supported_voices['nova'] = 'openai';
$supported_voices['shimmer'] = 'openai';

$voice = key( $supported_voices );

$supported_voices_list = implode( ', ', array_keys( $supported_voices ) );

if ( isset( $options['version'] ) ) {
	echo basename( $_SERVER['argv'][0] ), ' version ', $version, PHP_EOL;
	exit( 1 );
}

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	$offline = ! $online ? "(we're offline)" : '';
	$self = basename( $_SERVER['argv'][0] );
	echo <<<USAGE
	Usage: $self [-v voice] [conversation_input]

	Options:
	-v [voice]         Use a specific voice. Default: $voice

	Arguments:
	conversation_input  Input for the first conversation.

	USAGE;
	exit( 1 );
}
$initial_input = trim( implode( ' ', array_slice( $_SERVER['argv'], $initial_input ) ) . ' ' );
$fp = false;

if ( isset( $options['v'] ) ) {
	$voice = false;
	if ( isset( $supported_voices[ $options['v'] ] ) ) {
		$voice = $options['v'];
	}
	if ( ! $voice ) {
		echo 'Unsupported voice. Valid values: ', $supported_voices_list, PHP_EOL;
		exit( 1 );
	}
}
echo 'Voice: ', $voice, PHP_EOL;

$spellcheck = false;
if ( isset( $options['c'] ) ) {
	$spellcheck = true;
}
echo 'Fix spelling: ', $spellcheck ? 'on' : 'off', PHP_EOL;
if ( isset( $options['s'] ) ) {
	if ( is_numeric( $options['s'] ) && $options['s'] >= 0.25 && $options['s'] <= 4.0 ) {
		$speed = $options['s'];
	}
}
echo 'Speed: ', $speed, PHP_EOL;

$full_history_file = $history_directory . '/chunk.' . $time . '.' . preg_replace( '/[^a-z0-9]+/', '-', $voice ) . '.txt';
$chunk_file_template = $history_directory . '/chunk.' . $time . '.' . preg_replace( '/[^a-z0-9]+/', '-', $voice ) . '.';

if ( trim( $initial_input ) ) {
	echo '> ', $initial_input, PHP_EOL;
}

readline_clear_history();
readline_read_history( $readline_history_file );

curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/audio/speech' );

curl_setopt(
	$ch,
	CURLOPT_HTTPHEADER,
	array(
		'Content-Type: application/json',
		'Authorization: Bearer ' . $openai_key,
		'Transfer-Encoding: chunked',
	)
);

$pipes = $audiofp = null;
$fp = fopen( $full_history_file, 'a' );
curl_setopt(
	$ch,
	CURLOPT_WRITEFUNCTION,
	function ( $curl, $data ) use ( &$audiofp, &$pipes ) {
		if ( 200 !== curl_getinfo( $curl, CURLINFO_HTTP_CODE ) ) {
			var_dump( curl_getinfo( $curl, CURLINFO_HTTP_CODE ) );
			$error = json_decode( trim( $data ), true );
			echo 'Error: ', $error['error']['message'], PHP_EOL;
			return strlen( $data );
		}
		if ( $pipes[0] ) {
			fwrite( $pipes[0], $data );
		}
		if ( $audiofp ) {
			fwrite( $audiofp, $data );
		}

		return strlen( $data );
	}
);

function correctSpelling( $text ) {
	global $openai_key;
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

	curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );

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
				'model'    => 'gpt-3.5-turbo',
				'messages' =>
				array(
					array(
						'role'    => 'system',
						'content' => 'Return the input text but spell corrected',
					),
					array(
						'role'    => 'user',
						'content' => $text,
					),
				),
			)
		)
	);

	$output = curl_exec( $ch );
	$json = json_decode( $output, true );
	return $json['choices'][0]['message']['content'];
}


$chunk_id = 0;
// Start chatting.
while ( true ) {
	if ( ! empty( $initial_input ) ) {
		$input = $initial_input;
		$initial_input = null;
	} else {
		$input = readline( '> ' );
	}

	if ( false === $input || in_array( strtolower( trim( $input ) ), array( 'quit', 'exit', 'bye' ) ) ) {
		break;
	}

	if ( empty( $input ) ) {
		continue;
	}

	readline_add_history( $input );
	if ( ! $fp ) {
		if ( ! file_exists( $history_directory ) ) {
			mkdir( $history_directory, 0777, true );
		}

		$fp = fopen( $full_history_file, 'a' );
	}
	if ( preg_match( '/^s\d(.\d)?$/', $input ) ) {
		$input = round( substr( $input, 1 ), 1 );
		if ( $input >= 0.25 && $input <= 4.0 ) {
			$speed = $input;
		}
		echo 'Speed: ', $speed, PHP_EOL;
		continue;
	}

	if ( preg_match( '/^sc$/', $input ) ) {
		$spellcheck = ! $spellcheck;
		echo 'Fix spelling: ', $spellcheck ? 'on' : 'off', PHP_EOL;
		continue;
	}

	if ( strpos( $input, ' ' ) !== false && $spellcheck ) {
		$input = correctSpelling( $input );
		echo $input, PHP_EOL;
	}

	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		readline_write_history( $readline_history_file );
		fwrite( $fp, $input . PHP_EOL );
	}

	curl_setopt(
		$ch,
		CURLOPT_POSTFIELDS,
		json_encode(
			array(
				'model'  => 'tts-1',
				'voice'  => $voice,
				'input'  => $input,
				'speed'  => $speed,
				'stream' => true,
			)
		)
	);

	$file = $chunk_file_template . sprintf( '%06d', ++$chunk_id ) . '.mp3';
	$audiofp = fopen( $file, 'a' );
	$process = proc_open( 'play -qtmp3 -', array( array( 'pipe', 'r' ) ), $pipes );
	$output = curl_exec( $ch );
	fclose( $audiofp );
	fclose( $pipes[0] );
	proc_close( $process );

	if ( curl_error( $ch ) ) {
		echo 'CURL Error: ', curl_error( $ch ), PHP_EOL;
		exit( 1 );
	}
}
echo 'Bye.', PHP_EOL;
fclose( $fp );
fclose( $pipes[0] );
proc_close( $process );
