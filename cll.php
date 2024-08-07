<?php
$version = '1.1.2';
$openai_key = getenv( 'OPENAI_API_KEY', true );
$supported_models = array();
$ansi = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );

$options = getopt( 'ds:li:vhm:r:', array( 'help', 'version' ), $initial_input );

if ( ! isset( $options['m'] ) ) {
	putenv('RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1');
	$online = gethostbyname( 'api.openai.com.' ) !== 'api.openai.com.';
} else {
	$online = true;
}

$ch = curl_init();
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

function dontAutoComplete ($input, $index) { return []; }

readline_completion_function("dontAutoComplete");

$readline_history_file = __DIR__ . '/.history';
$history_base_directory = __DIR__ . '/chats/';
if ( ! file_exists( $history_base_directory ) ) {
	if ( ! mkdir( $history_base_directory ) ) {
		echo 'Could not create history directory: ', $history_base_directory, PHP_EOL;
		exit( 1 );
	}
}
$time = time();
$history_directory = $history_base_directory . date( 'Y/m', $time );

$system = false;

if ( $online && ! empty( $openai_key ) ) {
	// curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/models' );
	// curl_setopt(
	// 	$ch,
	// 	CURLOPT_HTTPHEADER,
	// 	array(
	// 		'Content-Type: application/json',
	// 		'Authorization: Bearer ' . $openai_key,
	// 	)
	// );

	// $response = curl_exec($ch);
	// $data = json_decode($response, true);

	// foreach ($data['data'] as $model) {
	// 	if ( false !== strpos( $model['id'], 'gpt' ) ) {
	//     $supported_models[ $model['id'] ]  = 'OpenAI';
	// 	}
	// }

	$supported_models['gpt-3.5-turbo'] = 'OpenAI';
	$supported_models['gpt-3.5-turbo-16k'] = 'OpenAI';
	$supported_models['gpt-4o-mini'] = 'OpenAI';
	$supported_models['gpt-4o'] = 'OpenAI';
}

curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/api/tags' );
$ollama_models = json_decode( curl_exec( $ch ), true );
if ( isset( $ollama_models['models'] ) ) {
	foreach ( $ollama_models['models'] as $m ) {
		$supported_models[ $m['name'] ] = 'Ollama (local)';
	}
}

if ( empty( $supported_models ) ) {
	echo 'No supported models found.', PHP_EOL, PHP_EOL;
	echo 'If you want to use ChatGPT, please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL, PHP_EOL;
	echo 'If you want to use Ollama, please make sure it is accessible on localhost:11434', PHP_EOL;
	exit( 1 );
}

$model_weight = array_flip( array_reverse( array( 'gpt-4o-mini', 'gemma', 'llama3', 'llama2' ) ) );
uksort( $supported_models, function( $a, $b ) use ( $model_weight ) {
	$a_weight = $b_weight = -1;
	foreach ( $model_weight as $model => $weight ) {
		if ( 0 === strpos( $a, $model ) ) {
			$a_weight = $weight;
		} elseif ( 0 === strpos( $b, $model ) ) {
			$b_weight = $weight;
		}
	}

	if ( $a_weight > $b_weight ) {
		return -1;
	}

	if ( $a_weight < $b_weight ) {
		return 1;
	}

	return 0;
} );

$model = key( $supported_models );

$supported_models_list = implode( ', ', array_keys( $supported_models ) );

if ( isset( $options['version'] ) ) {
	echo basename( $_SERVER['argv'][0] ), ' version ', $version, PHP_EOL;
	exit( 1 );
}

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	$offline = ! $online ? "(we're offline)" : '';
	$self = basename( $_SERVER['argv'][0] );
	echo <<<USAGE
Usage: $self [-l] [-r [number]] [-m model] [-s system_prompt] [conversation_input]

Options:
  -l                 Resume last conversation.
  -r [number|search] Resume a previous conversation and list 'number' conversations or search them.
  -d                 Ignore the model's answer.
  -v                 Be verbose.
  -m [model]         Use a specific model. Default: $model
  -i [image_file]    Add an image as input (only gpt-4o).
  -s [system_prompt] Specify a system prompt preceeding the conversation.

Arguments:
  conversation_input  Input for the first conversation.

Notes:
  - To input multiline messages, send an empty message.
  - To end the conversation, enter "bye".

Example usage:
  $self -l
    Resumes the last conversation.

  $self -ld -m llama2
    Reasks the previous question.

  $self -r 5
    Resume a conversation and list the last 5 to choose from.

  $self -r hello
    Resume a conversation and list the last 10 containing "hello" to choose from.

  $self -s "Only respond in emojis"
    Have an interesting conversation 🙂

  $self Tell me a joke
    Starts a new conversation with the given message.

  $self -m gpt-3.5-turbo-16k
    Use a ChatGPT model with 16k tokens instead of 4k.
    Supported modes: $supported_models_list $offline


USAGE;
	exit( 1 );
}
$messages = array();
$initial_input = trim( implode( ' ', array_slice( $_SERVER['argv'], $initial_input ) ) . ' ' );
$stdin = false;

$fp_stdin = fopen( 'php://stdin', 'r' );
$stat = fstat( $fp_stdin );
if ( $stat['size'] > 0 ) {
	$initial_input .= PHP_EOL;
	while ( $in = fread( $fp_stdin, $stat['size'] ) ) {
		$initial_input .= $in;
	}
	$stdin = true;
}
fclose( $fp_stdin );

$fp = false;

if ( isset( $options['m'] ) ) {
	$model = false;
	if ( isset( $supported_models[$options['m']] ) ) {
		$model = $options['m'];
	}
	if ( ! $model && $options['m'] ) {
		foreach ( array_keys( $supported_models ) as $m ) {
			if ( false !== strpos( $m, $options['m'] ) ) {
				$model = $m;
				break;
			}
		}
	}
	if ( ! $model ) {
		foreach ($supported_models as $m => $provider ) {
			if ( $provider === $options['m'] ) {
				$model = $m;
				break;
			}
		}
	}
	if ( ! $model ) {
		fprintf( STDERR, 'Supported Models: ' . $supported_models_list . PHP_EOL );
		exit( 1 );
	}
}
if ( ! $stdin || isset( $options['v'] ) ) {
	fprintf( STDERR, 'Model: ' . $model . ' via ' . $supported_models[$model] . PHP_EOL );
}

$full_history_file = $history_directory . '/history.' . $time . '.' . preg_replace( '/[^a-z0-9]+/', '-', $model ) . '.txt';

if ( isset( $options['l'] ) ) {
	$options['r'] = 1;
}

$sel = false;
$last_conversations = array();

if ( isset( $options['r'] ) ) {
	$search = false;
	if ( ! is_numeric( $options['r'] ) ) {
		$search = $options['r'];
		$options['r'] = 10;
	}

	$options['r'] = intval( $options['r'] );
	if ( $options['r'] <= 0 ) {
		$options['r'] = 10;
	}
	$history_files = array();
	for ( $i = 0; $i > -300; $i -= 20 ) {
		$more_history_files = array_flip( glob( $history_base_directory . date( 'Y/m', $time - $i ) . '/history.*' ) );
		if ( $search ) {
			$more_history_files = array_filter( $more_history_files, function( $file ) use ( $search ) {
				$file_contents = file_get_contents( $file );
				return false !== stripos( $file_contents, $search );
			}, ARRAY_FILTER_USE_KEY );
		}
		$history_files = array_merge( $history_files, $more_history_files );
		if ( count( $history_files ) >= $options['r'] ) {
			break;
		}
	}
	krsort( $history_files );

	$length = $options['r'];
	if ( isset( $options['l'] ) ) {
		echo 'Resuming the last conversation.', PHP_EOL;
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
				$filename_parts = explode( '.', $last_history_file );
				$used_model = $filename_parts[2];
				if ( 'txt' === $used_model ) {
					$used_model = '';
				} else {
					if ( ! isset( $options['m'] ) ) {
						$model = str_replace( 'gpt-3-5-', 'gpt-3.5-', $used_model );
						$model = str_replace( '-latest', ':latest', $used_model );
					}
					$used_model .= ', ';
				}
				$ago = '';
				$unix_timestamp = $filename_parts[1];
				if ( is_numeric( $unix_timestamp ) ) {
					$ago_in_seconds = $time - $unix_timestamp;
					if ( $ago_in_seconds > 60 * 60 * 24 ) {
						$ago = round( $ago_in_seconds / ( 60 * 60 * 24 ) ) . 'd ago, ';
					} elseif ( $ago_in_seconds > 60 * 60 ) {
						$ago = round( $ago_in_seconds / ( 60 * 60 ) ) . 'h ago, ';
					} elseif ( $ago_in_seconds > 60 ) {
						$ago = round( $ago_in_seconds / 60 ) . 'm ago, ';
					} else {
						$ago = $ago_in_seconds . 's ago, ';
					}
				}
				$conversation_contents = file_get_contents( $last_history_file );
				$split = preg_split( '/^>(?: ([^\n]*)|>> (.*)\n\.)\n\n/ms', trim( $conversation_contents ), -1, PREG_SPLIT_DELIM_CAPTURE );
				$split = array_filter( $split );
				$split = array_values( $split );

				if ( count( $split ) < 2 ) {
					// echo 'Empty history file: ', $last_history_file, PHP_EOL;
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
					echo PHP_EOL;
					echo $c, ') ';
					if ( $ansi ) {
						echo "\033[1m";
					}
					echo ltrim( str_replace( PHP_EOL, ' ', substr( $history_files[ $last_history_file ][0], 0, 100 ) ), '> ' );
					if ( $ansi ) {
						echo "\033[0m";
						echo PHP_EOL;
						echo str_repeat( ' ', strlen( $c . ') ' ) );
					} else {
						echo ' (';
					}
				}
				echo $ago, $used_model, $answers, ' answer', $answers === 1 ? '' : 's', ', ', str_word_count( $conversation_contents ), ' words';
				if ( ! $ansi ) {
					echo ')';
				}
				echo PHP_EOL;

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
		$state = array(
			'bold' => false,
		);
		foreach ( $history_files[ $last_conversations[ $sel ] ] as $k => $message ) {
			if ( isset( $options['d'] ) && $k % 2 ) {
				// Ignore assistant answers.
				continue;
			}

			$messages[] = array(
				'role'    => $k % 2 ? 'assistant' : 'user',
				'content' => $message,
			);

			if ( 0 === $k % 2 ) {
				echo '> ';
			}
			$chunks = preg_split( '/(\*\*)/', $message, 0, PREG_SPLIT_DELIM_CAPTURE );
			$chunk = '';
			while ( $chunks ) {
				$previous_chunk = $chunk;
				$chunk = array_shift( $chunks );
				if ( '**' === $chunk && '/' !== substr( $previous_chunk, -1 ) ) {
					$state['bold'] = ! isset( $state['bold'] ) || ! $state['bold'];
					if ( $state['bold'] ) {
						echo "\033[1m";
					} else {
						echo "\033[0m";
					}
					continue;
				}

				// A new line ends the bold state as a fallback.
				if ( false !== strpos( $chunk, "\n" ) && $state['bold'] ) {
					echo "\033[0m";
					$state['bold'] = false;
				}

				echo $chunk;
			}
			echo PHP_EOL;
		}
		if ( isset( $options['d'] ) ) {
			$initial_input = ' ';
			// Answer the question right away.
		}

	}
} elseif ( isset( $options['s'] ) && $options['s'] ) {
	$system = $options['s'];
	array_unshift( $messages, array(
		'role'    => 'system',
		'content' => $system,
	) );
	if ( ! $stdin || isset( $options['v'] ) ) {
		echo 'System prompt: ', $system, PHP_EOL;
	}
	if ( trim( $initial_input ) ) {
		if ( ! $stdin || isset( $options['v'] ) ) {
			echo '> ', $initial_input, PHP_EOL;
		}
	}
} elseif ( trim( $initial_input ) ) {
	if ( ! $stdin || isset( $options['v'] ) ) {
		echo '> ', $initial_input, PHP_EOL;
	}
}

readline_clear_history();
readline_read_history( $readline_history_file );

$headers = array(
	'Content-Type: application/json',
	'Transfer-Encoding: chunked',
);
if ( 'OpenAI' === $supported_models[$model] ) {
	curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
	$headers[] = 'Authorization: Bearer ' . $openai_key;
} elseif ( 'Ollama (local)' === $supported_models[$model] ) {
	curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/v1/chat/completions' );
}
$state = array( 'bold' => false );
$chunk_overflow = '';
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
curl_setopt(
	$ch,
	CURLOPT_WRITEFUNCTION,
	function ( $curl, $data ) use ( &$message, &$chunk_overflow, &$state ) {
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
			if ( isset( $json['choices'][0]['delta']['content'] ) ) {
				$chunks = preg_split( '/(\*\*)/', $json['choices'][0]['delta']['content'], 0, PREG_SPLIT_DELIM_CAPTURE );
				$chunk = '';
				while ( $chunks ) {
					$previous_chunk = $chunk;
					$chunk = array_shift( $chunks );
					if ( '**' === $chunk && '/' !== substr( $previous_chunk, -1 ) ) {
						$state['bold'] = ! isset( $state['bold'] ) || ! $state['bold'];
						if ( $state['bold'] ) {
							echo "\033[1m";
						} else {
							echo "\033[0m";
						}
						continue;
					}

					// A new line ends the bold state as a fallback.
					if ( false !== strpos( $chunk, "\n" ) && $state['bold'] ) {
						echo "\033[0m";
						$state['bold'] = false;
					}

					echo $chunk;
				}
				$message .= $json['choices'][0]['delta']['content'];
			} else {
				$chunk_overflow = $item;
			}
		}

		return strlen( $data );
	}
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
			$input = rtrim( $multiline ) . PHP_EOL;
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

	if ( ':' === substr( trim( $input ), -1 ) ) {
		$multiline = $input . PHP_EOL;
		echo 'Continuing multiline input. End with the last message as just a dot.', PHP_EOL;
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
			fwrite( $fp, '>>> ' . $input . PHP_EOL . '.' . PHP_EOL . PHP_EOL );
		}
	}

	$image = false;
	if ( isset( $options['i'] ) ) {
		if ( $model === 'gpt-4o' ) {
			$image = trim( $options['i'] );
			if ( ! filter_var( $image, FILTER_VALIDATE_URL ) ) {
				if ( ! file_exists( $image ) ) {
					echo 'Image file not found: ', $image, PHP_EOL;
					exit(1);
				} else {
					$mime = mime_content_type( $image );
					$image = 'data:' . $mime . ';base64,' . base64_encode( file_get_contents( $image ) );
				}
			}
		} else {
			echo 'Image input is only supported with gpt-4o.', PHP_EOL;
			exit(1);
		}
	}

	if ( $image ) {
		$input = array(
			array(
				'type' => 'text',
				'text' => $input,
			),
			array(
				'type' => 'image_url',
				'image_url' => array(
					'url' => $image,
				),
			),
		);
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
				'model'        => $model,
				'messages'     => $messages,
				'stream'       => true,
			)
		)
	);

	if ( ! $stdin || isset( $options['v'] ) ) {
		echo PHP_EOL;
	}
	$message = '';

	$output = curl_exec( $ch );
	if ( curl_error( $ch ) ) {
		echo 'CURL Error: ', curl_error( $ch ), PHP_EOL;
		exit( 1 );
	}
	if ( ! $stdin || isset( $options['v'] ) ) {
		echo PHP_EOL;
	}
	$messages[] = array(
		'role'    => 'assistant',
		'content' => $message,
	);
	if ( ! is_string( $input ) ) {
		$input = $input[0]['text'];
	}
	if ( $stdin || ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace or coming from stdin.
		fwrite( $fp, $message . PHP_EOL . PHP_EOL );
	}
	if ( $stdin ) {
		break;
	}
}
if ( $fp ) {
	fclose( $fp );
}
