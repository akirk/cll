<?php
$version = '1.0.0';
$openai_key = getenv( 'OPENAI_API_KEY', true );
if ( empty( $openai_key ) ) {
	echo 'Please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL;
	exit( 1 );
}

$readline_history_file = __DIR__ . '/.history';
$history_base_directory = __DIR__ . '/chats/';
if ( ! file_exists( $history_base_directory ) ) {
	if ( mkdir( $history_base_directory) ) {
		echo 'Could not create history directory: ', $history_base_directory, PHP_EOL;
		exit( 1 );
	}
}
$time = time();
$history_directory = $history_base_directory . date( 'Y/m', $time );
$full_history_file = $history_directory . '/history.' . $time . '.txt';

$options = getopt( 's:lhr::', array( 'help', 'version' ), $initial_input );
$system = false;

if ( isset( $options['version'] ) ) {
	echo basename( $_SERVER['argv'][0] ), ' version ', $version, PHP_EOL;
	exit( 1 );
}

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	$self = basename( $_SERVER['argv'][0] );
	echo <<<USAGE
Usage: $self [-l] [-r [number]] [-s system_prompt] [conversation_input]

Options:
  -l                 Resume last conversation.
  -r [number]        Resume a previous conversation and list 'number' conversations (default: 10).
  -s [system_prompt] Specify a system prompt preceeding the conversation.

Arguments:
  conversation_input  Input for the first conversation.

Notes:
  - To input multiline messages, send an empty message.
  - To end the conversation, enter "bye".

Example usage:
  $self -l
    Resumes the last conversation.

  $self -r 5
    Resume a conversation and list the last 5 to choose from.

  $self -s "Only respond in emojis"
    Have an interesting conversation 🙂

  $self Tell me a joke
    Starts a new conversation with the given message.

USAGE;
	exit( 1 );
}
$messages = array();
$initial_input = trim( implode( ' ', array_slice( $_SERVER['argv'], $initial_input ) ) . ' ' );
$fp = false;

if ( isset( $options['l'] ) ) {
	$options['r'] = 1;
}

$sel = false;
$last_conversations = array();

if ( isset( $options['r'] ) ) {
	$options['r'] = intval( $options['r'] );
	if ( $options['r'] <= 0 ) {
		$options['r'] = 10;
	}
	$history_files = array();
	for ( $i = 0; $i > -300; $i -= 20 ) {
		$history_files = array_merge( $history_files, array_flip( glob( $history_base_directory . date( 'Y/m', $time - $i ) . '/history.*.txt' ) ) );
		if ( count( $history_files ) >= $options['r'] ) {
			break;
		}
	}
	krsort( $history_files );

	$length = $options['r'];
	if ( isset( $options['l'] ) ) {
		echo 'Resuming the last conversation.';
	} else {
		echo 'Resuming a conversation. ';
	}
	$sel = 'm';
	$c = 0;
	while ( 'm' === $sel ) {
		$last_history_files = array_slice( array_keys( $history_files ), $c, $length );
		if ( empty( $last_history_files ) ) {
			if ( $c ) {
				echo 'No more conversations.', PHP_EOL;
			} else {
				echo 'No previous conversation. Starting a new one:', PHP_EOL;
				$sel = 0;
				break;
			}
		}

		if ( empty( $last_conversations ) && ! isset( $options['l'] ) ) {
			echo 'Please choose one: ', PHP_EOL;
		}

		if ( !empty( $last_history_files ) ) {
			$length = 10;
			foreach ( $last_history_files as $k => $last_history_file ) {
				$conversation_contents = file_get_contents( $last_history_file );
				$split = preg_split( '/^>(?: ([^\n]*)|>> (.*)\n\.)\n\n/ms', trim( $conversation_contents ), -1, PREG_SPLIT_DELIM_CAPTURE );
				$split = array_filter( $split );
				$split = array_values( $split );

				if ( count( $split ) < 2 ) {
					echo 'Empty history file: ', $last_history_file, PHP_EOL;
					unset( $history_files[ $last_history_file ] );
					unset( $last_history_files[ $k ] );
					continue;
				}
				$s = array_shift( $split );
				if ( substr( $s, 0, 7 ) === 'System:' ) {
					$split[0] = $s . $split[0];
				} else {
					array_unshift( $split, $s );
				}

				$history_files[ $last_history_file ] = $split;
				$answers = intval( count( $history_files[ $last_history_file ] ) / 2 );

				$c = $c + 1;

				if ( ! isset( $options['l'] ) ) {
					echo PHP_EOL, $c, ') ', ltrim( substr( $history_files[ $last_history_file ][0], 0, 100 ), '> ' );
				}
				echo ' (', $answers, ' answer', $answers === 1 ? '' : 's', ', ', str_word_count( $conversation_contents ), ' words)', PHP_EOL;
				$last_conversations[ $c ] = $last_history_file;
				if ( isset( $options['l'] ) ) {
					break;
				}
			}

			krsort( $history_files );
			if ( $c < $options['r'] ) {
				continue;
			}
		}
		echo PHP_EOL;
		if ( isset( $options['l'] ) ) {
			$sel = 1;
			break;
		}

		if ( 1 === count( $last_history_files ) ) {
			echo 'Resume this conversation (m for more): ';
		} else {
			echo 'Please enter the number of the conversation you want to resume (m for more): ';
		}
		$sel = readline();
		if ( 1 === count( $last_history_files ) ) {
			if ( $sel < 0 || 'y' === $sel ) {
				$sel = 1;
			} else {
				$sel = 'm';
			}
		}
	}
	if ( $sel ) {
		if ( ! isset( $last_conversations[ $sel ] ) ) {
			echo 'Invalid selection.', PHP_EOL;
		}
		if ( substr( $history_files[ $last_conversations[ $sel ] ][ 0 ], 0, 7 ) === 'System:' ) {
			$system = substr( $history_files[ $last_conversations[ $sel ] ][ 0 ], 8, strpos( $history_files[ $last_conversations[ $sel ] ][ 0 ], PHP_EOL ) - 8 );
			$history_files[ $last_conversations[ $sel ] ][ 0 ]	= substr( $history_files[ $last_conversations[ $sel ] ][ 0 ], strlen( $system ) + 9 );
			if ( isset( $options['s'] ) && $options['s'] ) {
				echo 'Old System prompt: ' . $system, PHP_EOL, 'New ';
				$system = $options['s'];
			}
			echo 'System prompt: ', $system, PHP_EOL;
			array_unshift( $messages, array(
				'role'    => 'system',
				'content' => $system,
			) );
		}
		foreach ( $history_files[ $last_conversations[ $sel ] ] as $k => $message ) {
			$messages[] = array(
				'role'    => $k % 2 ? 'assistant' : 'user',
				'content' => $message,
			);

			if ( 0 === $k % 2 ) {
				echo '> ';
			}
			echo $message, PHP_EOL;
		}
	}
} elseif ( isset( $options['s'] ) && $options['s'] ) {
	$system = $options['s'];
	array_unshift( $messages, array(
		'role'    => 'system',
		'content' => $system,
	) );
	echo 'System prompt: ', $system, PHP_EOL;
	$initial_input = '';
} elseif ( trim( $initial_input ) ) {
	echo '> ', $initial_input, PHP_EOL;
}

readline_clear_history();
readline_read_history( $readline_history_file );

$ch = curl_init();
curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
curl_setopt(
	$ch,
	CURLOPT_HTTPHEADER,
	array(
		'Content-Type: application/json',
		'Authorization: Bearer ' . $openai_key,
		'Transfer-Encoding: chunked',
	)
);

// Start chatting.
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
			$input = rtrim( $multiline );
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
	if ( ! $fp ) {
		if ( ! file_exists( $history_directory ) ) {
			mkdir( $history_directory, 0777, true );
		}

		if ( $sel && $last_conversations && isset( $last_conversations[ $sel ] ) ) {
			copy( $last_conversations[ $sel ], $full_history_file );

			if ( $system ) {
				file_put_contents( $full_history_file, 'System: ' . $system  . PHP_EOL . trim( preg_replace( '/^System: .*$/m', '', file_get_contents( $full_history_file ) ) ) );
				$system = false;
			}
		}

		$fp = fopen( $full_history_file, 'a' );
	}
	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		readline_write_history( $readline_history_file );
		if ( $system ) {
			fwrite( $fp, 'System: ' . $system . PHP_EOL );
			$system = false;
		}
		if ( false === strpos( $input, PHP_EOL ) ) {
			fwrite( $fp, '> ' . $input . PHP_EOL . PHP_EOL );
		} else {
			fwrite( $fp, '>>> ' . $input . PHP_EOL . '.' . PHP_EOL );
		}
	}
	$messages[] = array(
		'role'    => 'user',
		'content' => $input,
	);

	curl_setopt(
		$ch,
		CURLOPT_POSTFIELDS,
		json_encode(
			array(
				'model'        => 'gpt-3.5-turbo',
				'messages'     => $messages,
				'stream'       => true,
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
		fwrite( $fp, $message . PHP_EOL . PHP_EOL );
	}
}
echo 'Bye.', PHP_EOL;
fclose( $fp );
