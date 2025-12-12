<?php
$openai_key = getenv( 'OPENAI_API_KEY', true );

$model = 'gpt-4o-audio-preview';

$options = getopt( 'i:vh', array( 'help', 'version' ), $initial_input );
$input = false;
if ( isset( $options['i'] ) ) {
	$file = $options['i'];
	if ( file_exists( $file ) && is_readable( $file ) && is_file( $file ) ) {
		$if = fopen( $file, 'r' );
		$line = fgets( $if );
		// skip if binary but an utf8 text is ok
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', $line ) && ! preg_match( '/^[\x09\x0A\x0C\x0D\x20-\x7E\xA0-\xFF]+$/', $line ) ) {
			echo 'Skipping binary file: ', $file, PHP_EOL;
			fclose( $if );
			exit( 1 );
		}

		// show the first 5 lines:
		echo 'Local File: ', $file, ' (', filesize( $file ), ' bytes):', PHP_EOL;
		echo "\033[90m";
		for ( $i = 0; $i < 4; $i++ ) {
			$line = fgets( $if );
			if ( false === $line ) {
				break;
			}
			echo $line;
		}
		fclose( $if );
		echo "\033[m";

		echo 'Is this the right file? [Y/n]: ';

		$add = readline();
		if ( $add && 'y' !== strtolower( $add ) ) {
			exit( 1 );
		}
		$input = file_get_contents( $file );
	} else {
		echo 'File not found: ', $file, PHP_EOL;
	}
}
unset( $options['i'] );

if ( ! $input ) {
	// If no input file, read from stdin
	echo 'Enter your text: ';
	$input = stream_get_contents( STDIN );
}
if ( false === $input ) {
	echo 'Error reading input', PHP_EOL;
	exit( 1 );
}
echo 'Sending to OpenAI API...', PHP_EOL;
if ( ! $openai_key ) {
	echo 'Error: OpenAI API key not found', PHP_EOL;
	exit( 1 );
}


$headers = array(
	'Content-Type: application/json',
	'Transfer-Encoding: chunked',
);

$ch = curl_init();
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
$headers[] = 'Authorization: Bearer ' . $openai_key;

$messages = array(
	array(
		'role'    => 'system',
		'content' => 'You are a speaker at a conference. This is your manuscript, please use it as a not overly loose reference for the talk. Cover all details and take about 20 minutes for it.',
	),
	array(
		'role'    => 'user',
		'content' => $input,
	),
);
$voice = 'ballad';
$openai_payload = array(
	'model'      => 'gpt-4o-audio-preview',
	'modalities' => array( 'text', 'audio' ),
	'audio'      => array(
		'voice'  => $voice,
		'format' => 'pcm16',
	),
	'messages'   => $messages,
	'stream'     => true,
);
$output_filename = 'tts-' . date( 'Y-m-d-H-i-s' ) . '.wav';

echo 'Output file: ', $output_filename, PHP_EOL;

// Wave file header
function wave( $numChannels, $sampleRate, $bitsPerSample, $durationSeconds ) {
	$numSamples = $sampleRate * $durationSeconds;
	$byteRate = $sampleRate * $numChannels * $bitsPerSample / 8;
	$blockAlign = $numChannels * $bitsPerSample / 8;
	$dataSize = $numSamples * $blockAlign;

return pack(
    'a*Va*a*VvvVVvva*V',
    // Header
    'RIFF',
    44 + $dataSize - 8,
    'WAVE',
    // First chunk with audio format PCM (1)
    'fmt ',
    16,
    1,
    $numChannels,
    $sampleRate,
    $byteRate,
    $blockAlign,
    $bitsPerSample,
    // Second chunk
    'data',
    $dataSize
);
}

file_put_contents( $output_filename, wave( 1, 24000, 16, 0 ) );


$openai_payload = json_encode( $openai_payload );
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
curl_setopt( $ch, CURLOPT_POST, 1 );
curl_setopt( $ch, CURLOPT_POSTFIELDS, $openai_payload );

$usage = array();
$chunk_overflow = '';
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
curl_setopt(
    $ch,
    CURLOPT_WRITEFUNCTION,
    function ( $curl, $data ) use ( &$message, &$chunk_overflow, &$usage, $output_filename ) {
     if ( 200 !== curl_getinfo( $curl, CURLINFO_HTTP_CODE ) ) {
      $error = json_decode( trim( $chunk_overflow . $data ), true );
      if ( $error ) {
       echo 'Error: ', $error['error']['message'], PHP_EOL;
      } else {
       $chunk_overflow .= $data;
      }
      return strlen( $data );
     }
     $items = explode( 'data: ', $data );
     foreach ( $items as $item ) {
      if ( ! $item ) {
       continue;
      }
      $json = json_decode( trim( $chunk_overflow . $item ), true );
      if ( $json ) {
       $chunk_overflow = '';
      } else {
       $json = json_decode( trim( $item ), true );
      }

      if ( isset( $json['message']['usage'] ) ) {
       $usage = array_merge( $usage, $json['message']['usage'] );
      } elseif ( isset( $json['usage'] ) ) {
       $usage = array_merge( $usage, $json['usage'] );
      }
      if ( isset( $json['choices'][0]['delta']['content'] ) ) {
       $message = $json['choices'][0]['delta']['content'];
       if ( $message ) {
        echo $message;
       }
      } elseif ( isset( $json['choices'][0]['delta']['audio'] ) ) {
       if ( isset( $json['choices'][0]['delta']['audio']['transcript'] ) ) {
        $message = $json['choices'][0]['delta']['audio']['transcript'];
        if ( $message ) {
         echo $message;
        }
       }
       if ( isset( $json['choices'][0]['delta']['audio']['data'] ) ) {
        $audio = base64_decode( $json['choices'][0]['delta']['audio']['data'] );
        file_put_contents( $output_filename, $audio, FILE_APPEND );
       }
      } elseif ( isset( $json['choices'][0]['delta']['finish_reason'] ) ) {
       echo PHP_EOL;
      } else {
       $chunk_overflow .= $item;
      }
     }

     return strlen( $data );
    }
);

$response = curl_exec( $ch );
if ( curl_errno( $ch ) ) {
	echo 'Error: ', curl_error( $ch ), PHP_EOL;
	exit( 1 );
}

$response = json_decode( $response );
var_dump( $response );

// convert to an mp3
system( 'ffmpeg -i ' . escapeshellarg( $output_filename ) . ' -acodec libmp3lame -ab 128k -y ' . escapeshellarg( str_replace( '.wav', '.mp3', $output_filename ) ) );
