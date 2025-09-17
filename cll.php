<?php
require_once __DIR__ . '/includes/LogStorage.php';
require_once __DIR__ . '/includes/MessageStreamer.php';

$version = '2.0.2';
$openai_key = getenv( 'OPENAI_API_KEY', true );
$anthropic_key = getenv( 'ANTHROPIC_API_KEY', true );
$ansi = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );

$options = getopt( 'ds:li:p:vhfm::r:w::n', array( 'help', 'version', 'webui' ), $initial_input );

$new_argv = array( $_SERVER['argv'][0] );
foreach ( $_SERVER['argv'] as $i => $arg ) {
	if ( preg_match( '/^-[mw]$/', $arg ) && isset( $_SERVER['argv'][$i + 1] ) ) {
		$options['m'] = $_SERVER['argv'][++$i];
		$initial_input += 1;
	}
}

if ( ! isset( $options['m'] ) ) {
	putenv( 'RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1' );
	$online = gethostbyname( 'api.openai.com.' ) !== 'api.openai.com.';
} else {
	$online = true;
}

$ch = curl_init();
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

function dont_auto_complete( $input, $index ) {
	return array(); }


readline_completion_function( 'dont_auto_complete' );

$readline_history_file = __DIR__ . '/.history';
$sqlite_db_path = __DIR__ . '/chats.sqlite';
$time = time();

if ( isset( $options['n'] ) ) {
	$logStorage = new NoLogStorage();
	fprintf( STDERR, 'Private conversation, it will not be saved.' . PHP_EOL );
} else {
	try {
		if ( class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers() ) ) {
			$logStorage = new SQLiteLogStorage( $sqlite_db_path );
		} else {
			$logStorage = new NoLogStorage();
		}
	} catch ( Exception $e ) {
		$logStorage = new NoLogStorage();
	}

	if ( $logStorage instanceof NoLogStorage ) {
		fprintf( STDERR, 'Warning: No logging storage available. Conversations will not be saved.' . PHP_EOL );
	}
}

$messageStreamer = new MessageStreamer( $ansi, $logStorage );

$system = false;

$supported_models = array();
$supported_models_file = __DIR__ . '/supported-models.json';
if ( file_exists( $supported_models_file ) ) {
	$supported_models = json_decode( file_get_contents( $supported_models_file ), true );
	if ( ! is_array( $supported_models ) ) {
		$supported_models = array();
	}
} else {
	// Update the models.
	$options['m'] = '';
}

if ( $online && isset( $options['m'] ) && empty( $options['m'] ) ) {
	// Update models.
	$supported_models = array();
	if ( ! empty( $openai_key ) ) {
		fprintf( STDERR, 'Updating supported models... ' );

		curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/models' );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $openai_key,
			)
		);

		$response = curl_exec( $ch );
		$data = json_decode( $response, true );

		foreach ( $data['data'] as $model ) {
			if ( preg_match( '/^(gpt-\d|o\d-)/', $model['id'] ) && false === strpos( $model['id'], 'preview' ) ) {
				$supported_models[ $model['id'] ] = 'OpenAI';
			}
		}
	}
	if ( ! empty( $anthropic_key ) ) {
		curl_setopt( $ch, CURLOPT_URL, 'https://api.anthropic.com/v1/models' );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'x-api-key: ' . $anthropic_key,
				'anthropic-version: 2023-06-01',
				'Content-Type: application/json',
			)
		);
		$response = curl_exec( $ch );
		$data = json_decode( $response, true );

		foreach ( $data['data'] as $model ) {
			if ( $model['type'] === 'model' && 0 === strpos( $model['id'], 'claude' ) ) {
				$supported_models[ $model['id'] ] = 'Anthropic';
			}
		}
	}

	fprintf( STDERR, 'done (%d models found).' . PHP_EOL, count( $supported_models ) );

	file_put_contents( $supported_models_file, json_encode( $supported_models, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}

curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/api/tags' );
$ollama_models = json_decode( curl_exec( $ch ), true );
if ( isset( $ollama_models['models'] ) ) {
	foreach ( $ollama_models['models'] as $m ) {
		$supported_models[ $m['name'] ] = 'Ollama (local)';
	}
}

// Handle webui option before checking for supported models
if ( isset( $options['w'] ) || isset( $options['webui'] ) ) {
	$port = 8381; // default port
	if ( isset( $options['w'] ) ) {
		if ( $options['w'] !== false && is_numeric( $options['w'] ) ) {
			$port = $options['w'];
		}
	}
	$host = 'localhost';
	$url = "http://{$host}:{$port}";

	echo "Starting web UI on {$url}...", PHP_EOL;

	// Check if port is available
	$socket = @fsockopen( $host, $port, $errno, $errstr, 1 );
	if ( $socket ) {
		fclose( $socket );
		echo "Port {$port} is already in use. Trying to open browser anyway...", PHP_EOL;
		exec( "open '{$url}'" );
		exit( 0 );
	}

	// Start PHP built-in server in background
	$command = "php -S {$host}:{$port} -t " . escapeshellarg( __DIR__ ) . ' > /dev/null 2>&1 &';
	exec( $command );

	// Give server time to start
	sleep( 1 );

	// Verify server started
	$socket = @fsockopen( $host, $port, $errno, $errstr, 2 );
	if ( ! $socket ) {
		echo "Failed to start web server on port {$port}: {$errstr}", PHP_EOL;
		exit( 1 );
	}
	fclose( $socket );

	// Open browser
	exec( "open '{$url}'" );
	echo "Web UI started at {$url}", PHP_EOL;
	echo 'Server running in background. Press Ctrl+C to stop it.', PHP_EOL;

	// Keep script running to show the message
	while ( true ) {
		sleep( 3600 ); // Sleep for 1 hour, then repeat message
		echo "Web UI still running at {$url} (Press Ctrl+C to exit)", PHP_EOL;
	}
}

if ( empty( $supported_models ) ) {
	echo 'No supported models found.', PHP_EOL, PHP_EOL;
	echo 'If you want to use ChatGPT, please set your OpenAI API key in the OPENAI_API_KEY environment variable:', PHP_EOL;
	echo 'export OPENAI_API_KEY=sk-...', PHP_EOL, PHP_EOL;
	echo 'If you want to use Claude, please set your Anthropic API key in the ANTHROPIC_API_KEY environment variable:', PHP_EOL;
	echo 'export ANTHROPIC_API_KEY=sk-...', PHP_EOL, PHP_EOL;
	echo 'If you want to use Ollama, please make sure it is accessible on localhost:11434', PHP_EOL;
	exit( 1 );
}

$model_weight = array_flip( array_reverse( array( 'gpt-4o-mini', 'claude-3-5-haiku', 'gemma3', 'llama3', 'llama2', '' ) ) );
uksort(
	$supported_models,
	function ( $a, $b ) use ( $model_weight ) {
		$a_weight = $b_weight = -1;
		foreach ( $model_weight as $model => $weight ) {
			if ( 0 === strpos( $a, $model ) ) {
				$a_weight = $weight;
			}

			if ( 0 === strpos( $b, $model ) ) {
				$b_weight = $weight;
			}
		}

		if ( $a_weight > $b_weight ) {
			return -1;
		}

		if ( $a_weight < $b_weight ) {
			return 1;
		}


		if ( strlen( $a ) < strlen( $b ) ) {
			return -1;
		}

		if ( strlen( $a ) > strlen( $b ) ) {
			return 1;
		}


		return 0;
	}
);
$model = key( $supported_models );

$supported_models_by_provider = array();
foreach ( $supported_models as $m => $provider ) {
	$model_group = $m;
	if ( 'OpenAI' === $provider && preg_match( '/^o\d/', $m ) ) {
		$model_group = strtok( $m, '-' );
	} elseif ( 'OpenAI' === $provider ) {
		$model_group = strtok( $m, '-' ) . '-' . strtok( '-' );
	} elseif ( 'Anthropic' === $provider ) {
		$model_group = strtok( $m, '-' ) . '-' . strtok( '-' ) . '-' . strtok( '-' );
	} elseif ( 'Ollama (local)' === $provider ) {
		$model_group = strtok( $m, ':' );
	}

	$supported_models_by_provider[ $provider ][ $model_group ][] = $m;
}
ksort( $supported_models_by_provider );
$supported_models_list = '';
foreach ( $supported_models_by_provider as $provider => $model_groups ) {
	$provider_count = $provider . ' (' . count( array_merge( $model_groups ) ) . ')';
	$supported_models_list .= PHP_EOL . PHP_EOL . $provider_count;
	$supported_models_list .= PHP_EOL . str_repeat( '-', strlen( $provider_count ) );
	ksort( $model_groups );
	foreach ( $model_groups as $model_group => $models ) {
		usort( $models, function ( $a, $b ) {
			if ( preg_match( '/\d{4}/', $a ) && ! preg_match( '/\d{4}/', $b ) ) {
				return 1; // a is newer than b
			} else if ( preg_match( '/\d{4}/', $b ) && ! preg_match( '/\d{4}/', $a ) ) {
				return -1; // b is newer than a
			}
			return strnatcmp( $a, $b );
		} );
		if ( $ansi ) {
			$supported_models_list .= PHP_EOL . "\033[1m" . $model_group . "\033[m";
			$t = ' ';
		} else {
			$supported_models_list .= PHP_EOL . $model_group;
			$t = ': ';
		}
		foreach ( $models as $_model ) {
			$supported_models_list .= $t;
			$t = ' ';
			if ( $ansi ) {
				if ( 'OpenAI' === $provider && preg_match( '/\d{4}/', $_model ) ) {
					// light gray
					$supported_models_list .= "\033[90m";
					$supported_models_list .= $_model;
					$supported_models_list .= "\033[0m";
				} else {
					$supported_models_list .= $_model;
				}
			} else {
				$supported_models_list .= $_model;
			}
		}
	}
}


if ( isset( $options['version'] ) ) {
	echo basename( $_SERVER['argv'][0] ), ' version ', $version, PHP_EOL;
	exit( 1 );
}

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	$offline = ! $online ? "(we're offline)" : '';
	$self = basename( $_SERVER['argv'][0] );
	$padded_supported_models_list = str_replace( PHP_EOL, PHP_EOL . '    | ', $supported_models_list );
	echo <<<USAGE
Usage: $self [-l] [-f] [-r [number|searchterm]] [-m model] [-s [system_prompt|id]] [-i input_file_s] [-p picture_file] [-w port|--webui] [conversation_input]

Options:
  -l                   Resume last conversation.
  -r number|search     Resume a previous conversation and list 'number' conversations or search them.
  -d                   Ignore the model's last answer. Useful when combining with -l to ask the question to another model.
  -v                   Be verbose.
  -f                   Allow file system writes for suggested file content by the AI.
  -m [model]           Use a specific model. Default: $model
  -i input_file(s)     Read these files and add them to the prompt.
  -p picture_file      Add an picture as input (only gpt-4o).
  -s [prompt|id|name]  System prompt: text, saved prompt ID/name, or empty to list saved prompts.
  -w port|--webui      Launch web UI on specified port (default: 8080) and open browser.

Arguments:
  conversation_input  Input for the first conversation.

Notes:
  - To input multiline messages, send an empty message.
  - To end the conversation, enter "bye".
  - System prompts are managed via the web interface at --webui.

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
    Use custom system prompt.

  $self -s
    List all saved system prompts.

  $self -s 3
    Use saved system prompt with ID 3.

  $self -s learn
    Use saved system prompt named 'learn'.

  $self Tell me a joke
    Starts a new conversation with the given message.

  $self -w 8080
    Launch web UI on port 8080 and open browser.

  $self --webui
    Launch web UI on default port 8080 and open browser.

  $self -m gpt-3.5-turbo-16k
    Use a ChatGPT model with 16k tokens instead of 4k.
    Supported models: $padded_supported_models_list $offline


USAGE;
	exit( 1 );
}
$messages = array();
$remaining_args = array_slice( $_SERVER['argv'], $initial_input );
$initial_input = trim( implode( ' ', $remaining_args ) . ' ' );
$stdin = false;

$fp_stdin = fopen( 'php://stdin', 'r' );
$stat = fstat( $fp_stdin );
if ( $stat['size'] > 0 ) {
	$initial_input .= PHP_EOL;
	while ( $in = fread( $fp_stdin, $stat['size'] ) ) {
		$initial_input .= $in;
	}
	$stdin = true;
	$ansi = false;
}
fclose( $fp_stdin );

if ( isset( $options['m'] ) ) {
	$model = false;
	if ( isset( $supported_models[ $options['m'] ] ) ) {
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
		foreach ( $supported_models as $m => $provider ) {
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
$model_provider = $supported_models[ $model ];
$wrapper = array(
	'model'  => $model,
	'stream' => true,
);

if ( $ansi || isset( $options['v'] ) ) {
	fprintf( STDERR, 'Model: ' . $model . ' via ' . $model_provider . ( isset( $options['v'] ) ? ' (verbose)' : '' ) . PHP_EOL );
}

// Let SQLite auto-generate the conversation ID
$conversation_id = null;

if ( isset( $options['l'] ) ) {
	$lastConversations = $logStorage->findConversations( 1 );
	$options['r'] = $lastConversations[0];
}

$sel = false;
$last_conversations = array();
if ( isset( $options['r'] ) ) {
	$search = false;
	$specific_conversation_id = false;

	if ( ! is_numeric( $options['r'] ) ) {
		$search = $options['r'];
		$options['r'] = 10;
	} else {
		// Check if this is a specific conversation ID by trying to load it
		$test_conversation = $logStorage->getConversationMetadata( $options['r'] );

		if ( $test_conversation ) {
			$specific_conversation_id = $options['r'];
			$conversations = array( $specific_conversation_id => null );
		} else {
			$options['r'] = intval( $options['r'] );
			if ( $options['r'] <= 0 ) {
				$options['r'] = 10;
			}
		}
	}

	if ( ! $specific_conversation_id ) {
		$conversation_list = $logStorage->findConversations( $options['r'] * 10, $search );
		$conversations = array();
		foreach ( $conversation_list as $conversation_key ) {
			$conversations[ $conversation_key ] = null; // Will be loaded later
		}
	} else {
		// For specific conversation ID, we need to load the conversation data
		$conversation_data = $logStorage->loadConversation( $specific_conversation_id );
		// Keep the full message format with role information for proper handling
		if ( $conversation_data && is_array( $conversation_data ) ) {
			$conversations[ $specific_conversation_id ] = $conversation_data;
		} else {
			echo 'Conversation not found or empty.', PHP_EOL;
			exit( 1 );
		}

		// Set up for direct resume
		$last_conversations = array( 1 => $specific_conversation_id );
	}

	$length = is_numeric( $options['r'] ) ? $options['r'] : 10;
	if ( isset( $options['l'] ) ) {
		echo 'Resuming the last conversation.', PHP_EOL;
	} elseif ( $specific_conversation_id ) {
			echo 'Resuming conversation ID: ', $specific_conversation_id, PHP_EOL;
	} else {
		echo 'Resuming a conversation. ';
	}
	$sel = $specific_conversation_id ? 1 : 'm';
	$c = 0;
	while ( 'm' === $sel ) {
		$current_conversation_batch = array_slice( array_keys( $conversations ), $c, $length );
		if ( empty( $current_conversation_batch ) ) {
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

		if ( ! empty( $current_conversation_batch ) ) {
			$length = 10;
			foreach ( $current_conversation_batch as $k => $conversation_key ) {
				// Get conversation metadata from storage
				$metadata = $logStorage->getConversationMetadata( $conversation_key );
				if ( ! $metadata ) {
					unset( $conversations[ $conversation_key ] );
					unset( $current_conversation_batch[ $k ] );
					continue;
				}

				$used_model = $metadata['model'];
				if ( 'txt' === $used_model || empty( $used_model ) ) {
					$used_model = '';
				} else {
					if ( ! isset( $options['m'] ) ) {
						$model = str_replace( 'gpt-3-5-', 'gpt-3.5-', $used_model );
						$model = str_replace( '-latest', ':latest', $used_model );
					}
					$used_model .= ', ';
				}

				$ago = '';
				$unix_timestamp = $metadata['timestamp'];
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

				// Load conversation data
				$split = $logStorage->loadConversation( $conversation_key );
				// Keep the full message format with role information for proper handling

				if ( ! $split || count( $split ) < 2 ) {
					unset( $conversations[ $conversation_key ] );
					unset( $current_conversation_batch[ $k ] );
					continue;
				}

				$conversations[ $conversation_key ] = $split;
				$answers = $metadata['answers'];

				$c = $c + 1;

				if ( ! isset( $options['l'] ) ) {
					echo PHP_EOL;
					echo $c, ') ';
					if ( $ansi ) {
						echo "\033[1m";
					}
					$first_message = $conversations[ $conversation_key ][0];
					if ( is_array( $first_message ) && isset( $first_message['content'] ) ) {
						$first_message = $first_message['content'];
					}
					if ( substr( $first_message, 0, 7 ) === 'System:' ) {
						$first_message = isset( $conversations[ $conversation_key ][1] ) ? $conversations[ $conversation_key ][1] : '';
						if ( is_array( $first_message ) && isset( $first_message['content'] ) ) {
							$first_message = $first_message['content'];
						}
					}
					echo ltrim( str_replace( PHP_EOL, ' ', substr( $first_message, 0, 100 ) ), '> ' );
					if ( $ansi ) {
						echo "\033[0m";
						echo PHP_EOL;
						echo str_repeat( ' ', strlen( $c . ') ' ) );
					} else {
						echo ' (';
					}
				}
				echo $ago, $used_model, $answers, ' answer', $answers === 1 ? '' : 's', ', ', $metadata['word_count'], ' words';
				if ( ! $ansi ) {
					echo ')';
				}
				echo PHP_EOL;

				$last_conversations[ $c ] = $conversation_key;
				if ( isset( $options['l'] ) ) {
					break;
				}
			}

			krsort( $conversations );
			if ( $c < $options['r'] ) {
				continue;
			}
		}
		echo PHP_EOL;
		if ( isset( $options['l'] ) ) {
			$sel = 1;
			break;
		}

		if ( 1 === count( $current_conversation_batch ) ) {
			echo 'Resume this conversation (m for more): ';
		} else {
			echo 'Please enter the number of the conversation you want to resume (m for more): ';
		}
		$sel = readline();
		if ( 1 === count( $current_conversation_batch ) ) {
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
			exit( 1 );
		}

		$conversation_key = $last_conversations[ $sel ];
		if ( ! isset( $conversations[ $conversation_key ] ) || ! is_array( $conversations[ $conversation_key ] ) ) {
			echo 'Conversation data not found.', PHP_EOL;
			exit( 1 );
		}

		$first_message = $conversations[ $conversation_key ][0];
		$first_role = is_array( $first_message ) && isset( $first_message['role'] ) ? $first_message['role'] : null;

		// Check for system prompt in SQLite format (role='system')
		if ( $first_role === 'system' ) {
			$system = $first_message['content'];
			$system_prompt_name = null; // Resume case - no name available

			// Remove the system message from conversation data since it's now handled separately
			array_shift( $conversations[ $conversation_key ] );

			if ( isset( $options['s'] ) && $options['s'] ) {
				echo 'Old System prompt: ' . $system, PHP_EOL, 'New ';
				$system = $options['s'];
				$system_prompt_name = null; // Override case - custom prompt
			}

			if ( isset( $options['v'] ) ) {
				echo 'System prompt: ', $system, PHP_EOL;
			} else {
				$words = preg_split( '/\s+/', $system, 11 );
				if ( isset( $words[10] ) ) {
					$words[10] = '...';
				}
				echo 'System prompt: ', implode( ' ', $words ), PHP_EOL;
			}
			if ( $model_provider === 'Anthropic' ) {
				$wrapper['system'] = $system;
			} else {
				array_unshift(
        $messages,
        array(
        'role'    => 'system',
        'content' => $system,
        )
				);
			}
		}
		$conversation_data = $conversations[ $conversation_key ];
		foreach ( $conversation_data as $k => $message ) {
			if ( isset( $options['d'] ) && $k % 2 ) {
				// Ignore assistant answers.
				continue;
			}

			// Handle both string messages and array format from SQLite
			$content = is_array( $message ) && isset( $message['content'] ) ? $message['content'] : $message;
			$role = is_array( $message ) && isset( $message['role'] ) ? $message['role'] : ( $k % 2 ? 'assistant' : 'user' );

			// Skip system messages when outputting conversation history
			if ( $role === 'system' ) {
				continue;
			}

			$messages[] = array(
				'role'    => $role,
				'content' => $content,
			);

			if ( $role === 'user' ) {
				echo '> ';
			}

			foreach ( $messageStreamer->outputMessage( $content . PHP_EOL ) as $output ) {
				echo $output;
			}
		}

		if ( isset( $options['d'] ) ) {
			$initial_input = ' ';
			// Answer the question right away.
		}
	}
} elseif ( ! empty( $options['s'] ) || isset( $options['f'] ) || substr( $model, 0, 7 ) === 'gpt-oss' ) {
	$system = '';
	$system_prompt_name = null; // Track the name for tagging
	if ( ! empty( $options['s'] ) ) {
		if ( is_numeric( $options['s'] ) ) {
			$found_system_prompt = $logStorage->getSystemPrompt( intval( $options['s'] ) );
		} else {
			$found_system_prompt = $logStorage->getSystemPromptByName( $options['s'] );
		}
		if ( $found_system_prompt ) {
			$system = $found_system_prompt['prompt'];
			$system_prompt_name = $found_system_prompt['name']; // Store the name for tagging
			$words = preg_split( '/\s+/', $system, 11 );
			if ( isset( $words[10] ) ) {
				$words[10] = '...';
			}

			if ( $ansi || isset( $options['v'] ) ) {
				echo 'Loaded system prompt ', $found_system_prompt['id'], ': ', implode( ' ', $words ), PHP_EOL;
			}
		} else {
			$system = $options['s'];
			$system_prompt_name = null; // Custom prompt, no name
			if ( $ansi || isset( $options['v'] ) ) {
				echo 'System prompt: ', $system, PHP_EOL;
			}
		}
	}
	if ( isset( $options['f'] ) ) {
		$system = 'When recommending file content it must be prepended with the proposed filename in the form: "File: filename.ext" ' . $system;
	}
	if ( substr( $model, 0, 7 ) === 'gpt-oss' ) {
		$system .= ' When responding, ALWAYS prefer lists to tables.';
	}
	if ( $system ) {
		if ( $model_provider === 'Anthropic' ) {
			$wrapper['system'] = $system;
		} else {
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => $system,
				)
			);
		}
	}
	if ( trim( $initial_input ) ) {
		if ( $ansi || isset( $options['v'] ) ) {
			echo '> ', $initial_input, PHP_EOL;
		}
	}
} elseif ( trim( $initial_input ) ) {
	if ( $ansi || isset( $options['v'] ) ) {
		echo '> ', $initial_input, PHP_EOL;
	}
}

readline_clear_history();
readline_read_history( $readline_history_file );

$headers = array(
	'Content-Type: application/json',
	'Transfer-Encoding: chunked',
);

$usage = array();

// Cost tracking variables
$conversation_start_time = microtime( true );
$total_api_duration = 0;
$total_input_tokens = 0;
$total_output_tokens = 0;
$total_cache_read_tokens = 0;
$total_cache_write_tokens = 0;
$api_call_count = 0;

function calculate_cost( $model, $input_tokens, $output_tokens, $cache_read_tokens = 0, $cache_write_tokens = 0 ) {
	static $pricing_data = null;

	// Load pricing data once
	if ( $pricing_data === null ) {
		$pricing_file = __DIR__ . '/pricing.json';
		if ( file_exists( $pricing_file ) ) {
			$pricing_json = file_get_contents( $pricing_file );
			$pricing_data = json_decode( $pricing_json, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				error_log( 'Invalid pricing.json format: ' . json_last_error_msg() );
				$pricing_data = false;
			}
		} else {
			error_log( 'pricing.json file not found: ' . $pricing_file );
			$pricing_data = false;
		}
	}

	// Return 0 if pricing data couldn't be loaded
	if ( ! $pricing_data || ! isset( $pricing_data['models'] ) ) {
		return 0;
	}

	// Find the best matching pricing
	$model_pricing = null;
	foreach ( $pricing_data['models'] as $price_model => $prices ) {
		if ( strpos( $model, $price_model ) !== false ) {
			$model_pricing = $prices;
			break;
		}
	}

	// Return 0 for unknown models (no fallback)
	if ( ! $model_pricing ) {
		return 0;
	}

	$per_tokens = $pricing_data['per_tokens'] ?? 1000000;

	$cost = 0;
	$cost += ( $input_tokens / $per_tokens ) * $model_pricing['input'];
	$cost += ( $output_tokens / $per_tokens ) * $model_pricing['output'];

	if ( isset( $model_pricing['cache_read'] ) && $cache_read_tokens > 0 ) {
		$cost += ( $cache_read_tokens / $per_tokens ) * $model_pricing['cache_read'];
	}

	if ( isset( $model_pricing['cache_write'] ) && $cache_write_tokens > 0 ) {
		$cost += ( $cache_write_tokens / $per_tokens ) * $model_pricing['cache_write'];
	}

	return $cost;
}

if ( 'OpenAI' === $model_provider ) {
	curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
	$headers[] = 'Authorization: Bearer ' . $openai_key;
} elseif ( 'Anthropic' === $model_provider ) {
	curl_setopt( $ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages' );
	$headers[] = 'x-api-key: ' . $anthropic_key;
	$headers[] = 'anthropic-version: 2023-06-01';
	$headers[] = 'Content-Type: application/json';
} elseif ( 'Ollama (local)' === $model_provider ) {
	curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/v1/chat/completions' );
}

$chunk_overflow = '';
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
// The curl write function will be set after MessageStreamer is created

// Start chatting.
$multiline = false;
while ( true ) {
	if ( ! empty( $initial_input ) ) {
		$input = $initial_input;
		$initial_input = null;
	} elseif ( ! $ansi ) {
		break;
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
	static $conversation_initialized = false;
	if ( ! $conversation_initialized ) {
		if ( $sel && $last_conversations && isset( $last_conversations[ $sel ] ) ) {
			$source_key = $last_conversations[ $sel ];

			// Use existing conversation ID
			$conversation_id = $source_key;
		} else {
			// Only initialize new conversation if not resuming
			$conversation_id = $logStorage->initializeConversation( $conversation_id, $model );
		}
		if ( $system ) {
			$logStorage->writeSystemPrompt( $conversation_id, $system, $system_prompt_name );
			$system = false;
		}
		$conversation_initialized = true;
		// Set current conversation in logStorage and create MessageStreamer
		$logStorage->setCurrentConversation( $conversation_id );
	}

	if ( isset( $options['i'] ) ) {
		if ( ! is_array( $options['i'] ) ) {
			$options['i'] = array( $options['i'] );
		}

		$all_yes = false;
		foreach ( $options['i'] as $glob ) {
			if ( '.' === $glob ) {
				$exclude_dirs = array();
				$allow_all_subdirs = array();
				$already_asked = array( '' );

				$objects = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( '.' ), RecursiveIteratorIterator::SELF_FIRST );
				$files = array();
				foreach ( $objects as $name => $object ) {
					if ( './' === substr( $name, 0, 2 ) ) {
						$name = substr( $name, 2 );
					}
					$dirs = explode( '/', $name );
					$base_dir = implode( '/', array_slice( $dirs, 0, -1 ) );
					foreach ( $dirs as $dir ) {
						if ( '.' === substr( $dir, 0, 1 ) ) {
							continue 2;
						}
					}

					if ( ! in_array( $base_dir, $already_asked ) ) {
						foreach ( $allow_all_subdirs as $allow_all_subdir ) {
							if ( 0 === strpos( $base_dir, $allow_all_subdir ) ) {
								echo 'Including directory: ', $base_dir, PHP_EOL;
								$already_asked[] = $base_dir;
								break;
							}
						}

						foreach ( $exclude_dirs as $exclude_dir ) {
							if ( 0 === strpos( $base_dir, $exclude_dir ) ) {
								echo 'Skipping directory: ', $base_dir, PHP_EOL;
								continue 2;
							}
						}
					}

					if ( ! in_array( $base_dir, $already_asked ) ) {
						echo 'Include directory: ', $base_dir, ' [Y/n/a]: ';
						$add = readline();
						if ( 'n' === strtolower( $add ) ) {
							echo 'Skipping directory: ', $base_dir, PHP_EOL;
							$exclude_dirs[] = $base_dir;
						} elseif ( 'a' === strtolower( $add ) ) {
							$allow_all_subdirs[] = $base_dir;
						}

						$already_asked[] = $base_dir;
					}

					if ( in_array( $base_dir, $exclude_dirs ) ) {
						continue;
					}
					if ( $object->isFile() ) {
						$files[] = $name;
					}
				}
			} else {
				$files = glob( $glob );
			}
			if ( empty( $files ) ) {
				echo 'No files found for: ', $glob, PHP_EOL;
				continue;
			}

			if ( count( $files ) > 1 ) {
				echo 'Found ', count( $files ), ' files: ', implode( ' ', array_slice( $files, 0, 5 ) ), ' ... ', PHP_EOL;
			}

			foreach ( $files as $file ) {
				if ( file_exists( $file ) && is_readable( $file ) && is_file( $file ) ) {
					$if = fopen( $file, 'r' );
					$line = fgets( $if );
					// skip if binary but an utf8 text is ok
					if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', $line ) && ! preg_match( '/^[\x09\x0A\x0C\x0D\x20-\x7E\xA0-\xFF]+$/', $line ) ) {
						echo 'Skipping binary file: ', $file, PHP_EOL;
						fclose( $if );
						continue;
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
					if ( ! $all_yes ) {
						echo 'Add file content to the prompt? [Y/n/a]: ';

						$add = readline();
						if ( 'a' === strtolower( $add ) ) {
							$all_yes = true;
						} elseif ( 'n' === strtolower( $add ) ) {
							echo 'Skipping file: ', $file, PHP_EOL;
							continue;
						}
					}
					$input .= PHP_EOL . 'File: `' . $file . '`' . PHP_EOL . '```' . str_replace( '```', '\`\`\`', file_get_contents( $file ) ) . '```';
				} else {
					echo 'File not found: ', $file, PHP_EOL;
				}
			}
		}
		unset( $options['i'] );
	}

	if ( $stdin || ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		if ( ! $stdin ) {
			readline_write_history( $readline_history_file );
		}
		if ( $system ) {
			$logStorage->writeSystemPrompt( $conversation_id, $system, $system_prompt_name );
			$system = false;
		}
		$logStorage->writeUserMessage( $conversation_id, $input );
	}

	$image = false;
	if ( isset( $options['p'] ) ) {
		if ( preg_match( '/^(gpt-4o|llava)/', $model ) ) {
			$image = trim( $options['p'] );
			if ( ! filter_var( $image, FILTER_VALIDATE_URL ) ) {
				if ( ! file_exists( $image ) ) {
					echo 'Image file not found: ', $image, PHP_EOL;
					exit( 1 );
				} else {
					$mime = mime_content_type( $image );
					$image = 'data:' . $mime . ';base64,' . base64_encode( file_get_contents( $image ) );
				}
			}
		} else {
			echo 'Image input is only supported with gpt-4o* or llava.', PHP_EOL;
			exit( 1 );
		}
	}

	if ( $image ) {
		$input = array(
			array(
				'type' => 'text',
				'text' => $input,
			),
			array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => $image,
				),
			),
		);
		// Only send the image in the first message.
		unset( $options['p'] );
	}

	$messages[] = array(
		'role'    => 'user',
		'content' => $input,
	);
	$wrapper['messages'] = $messages;

	if ( 'Anthropic' === $model_provider ) {
		$wrapper['max_tokens'] = 3200;
	}

	if ( 'OpenAI' === $model_provider ) {
		$wrapper['stream_options'] = array(
			'include_usage' => true,
		);
	}

	curl_setopt(
	    $ch,
	    CURLOPT_POSTFIELDS,
	    json_encode(
	        $wrapper
	    )
	);

	if ( $ansi || isset( $options['v'] ) ) {
		echo PHP_EOL;
	}
	$message = '';

	curl_setopt( $ch, CURLOPT_WRITEFUNCTION, $messageStreamer->createCurlWriteHandler( $message, $chunk_overflow, $usage, $model_provider ) );

	$api_start_time = microtime( true );
	$output = curl_exec( $ch );
	$api_end_time = microtime( true );
	$api_call_duration = $api_end_time - $api_start_time;
	$total_api_duration += $api_call_duration;
	$api_call_count++;

	if ( curl_error( $ch ) ) {
		echo 'CURL Error: ', curl_error( $ch ), PHP_EOL;
		exit( 1 );
	}
	if ( $ansi || isset( $options['v'] ) ) {
		echo PHP_EOL;
	}
	$messages[] = array(
		'role'    => 'assistant',
		'content' => $message,
	);
	if ( ! is_string( $input ) ) {
		$input = $input[0]['text'];
	}
	if ( isset( $options['f'] ) ) {
		preg_match_all( '/^(?:#+\s*)?File: `?([a-z0-9_.\/-]+)`?$/m', $message, $matches, PREG_SET_ORDER );
		if ( $matches ) {
			foreach ( $matches as $match ) {
				$file = $match[1];
				preg_match( '/^' . preg_quote( $match[0], '/' ) . '.*?```[a-z0-9_-]*\n(.*?)```/sm', $message, $m );
				if ( $m ) {
					if ( file_exists( $file ) ) {
						$backup_filename = $file . '.bak.' . time();
						echo "\033[33m";
						echo 'Backing up existing file: ', $file, ' => ', $backup_filename, PHP_EOL;
						echo "\033[0m";

						copy( $file, $backup_filename );
					}
					echo "\033[32m";
					echo 'Writing ', strlen( $m[1] ), ' bytes to file: ', $file, PHP_EOL;
					echo "\033[0m";
					file_put_contents( $file, $m[1] );
				}
			}
		}
	}
	if ( $stdin || ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		$logStorage->writeAssistantMessage( $conversation_id, $message );
	}

	// Accumulate token usage and update cost after each API response
	if ( ! empty( $usage ) ) {
		$current_input_tokens = 0;
		$current_output_tokens = 0;
		$current_cache_read_tokens = 0;
		$current_cache_write_tokens = 0;

		if ( isset( $usage['prompt_tokens'] ) ) {
			$current_input_tokens = $usage['prompt_tokens'];
			$total_input_tokens += $current_input_tokens;
		} elseif ( isset( $usage['input_tokens'] ) ) {
			$current_input_tokens = $usage['input_tokens'];
			$total_input_tokens += $current_input_tokens;
		}

		if ( isset( $usage['completion_tokens'] ) ) {
			$current_output_tokens = $usage['completion_tokens'];
			$total_output_tokens += $current_output_tokens;
		} elseif ( isset( $usage['output_tokens'] ) ) {
			$current_output_tokens = $usage['output_tokens'];
			$total_output_tokens += $current_output_tokens;
		}

		if ( isset( $usage['cache_read_input_tokens'] ) ) {
			$current_cache_read_tokens = $usage['cache_read_input_tokens'];
			$total_cache_read_tokens += $current_cache_read_tokens;
		}

		if ( isset( $usage['cache_creation_input_tokens'] ) ) {
			$current_cache_write_tokens = $usage['cache_creation_input_tokens'];
			$total_cache_write_tokens += $current_cache_write_tokens;
		}

		// Calculate and store cost for this API call
		if ( $conversation_id && ( $current_input_tokens > 0 || $current_output_tokens > 0 ) ) {
			$current_cost = calculate_cost( $model, $current_input_tokens, $current_output_tokens, $current_cache_read_tokens, $current_cache_write_tokens );
			if ( $current_cost > 0 ) {
				$logStorage->storeCostData( $conversation_id, $current_cost, $current_input_tokens, $current_output_tokens );
			}
		}
	}

	if ( isset( $options['v'] ) && $messageStreamer ) {
		echo $messageStreamer->getDebugInfo();
		$messageStreamer->clearChunks();
		if ( ! empty( $usage ) ) {
			echo 'Tokens';
			$t = ': ';
			foreach ( $usage as $type => $count ) {
				$type = str_replace( '_tokens', '', $type );
				if ( is_numeric( $count ) && $type !== 'total' ) {
					echo $t, $count, ' ', $type;
					$t = ', ';
				}
			}
			echo PHP_EOL;
		}
	}

	if ( $stdin ) {
		break;
	}
}

// Cost is now stored after each API response, not at the end

// Only show cost summary in verbose mode
if ( isset( $options['v'] ) ) {
	$conversation_end_time = microtime( true );
	$total_wall_duration = $conversation_end_time - $conversation_start_time;
	$cost = calculate_cost( $model, $total_input_tokens, $total_output_tokens, $total_cache_read_tokens, $total_cache_write_tokens );

	echo PHP_EOL;

	if ( $cost > 0 ) {
		echo sprintf( 'Total cost:            $%.4f', $cost ), PHP_EOL;
	}

	echo sprintf( 'Total duration (API):  %.1fs', $total_api_duration ), PHP_EOL;
	echo sprintf( 'Total duration (wall): %s', format_duration( $total_wall_duration ) ), PHP_EOL;

	if ( $total_input_tokens > 0 || $total_output_tokens > 0 || $total_cache_read_tokens > 0 || $total_cache_write_tokens > 0 ) {
		echo 'Usage by model:', PHP_EOL;
		$formatted_input = format_token_count( $total_input_tokens );
		$formatted_output = format_token_count( $total_output_tokens );
		$formatted_cache_read = format_token_count( $total_cache_read_tokens );
		$formatted_cache_write = format_token_count( $total_cache_write_tokens );

		echo sprintf( '       %s: %s input, %s output', $model, $formatted_input, $formatted_output );

		if ( $total_cache_read_tokens > 0 || $total_cache_write_tokens > 0 ) {
			echo sprintf( ', %s cache read, %s cache write', $formatted_cache_read, $formatted_cache_write );
		}
		echo PHP_EOL;
	}
}

$logStorage->close();

function format_duration( $seconds ) {
	if ( $seconds < 60 ) {
		return sprintf( '%.1fs', $seconds );
	} else {
		$minutes = floor( $seconds / 60 );
		$remaining_seconds = $seconds - ( $minutes * 60 );
		return sprintf( '%dm %.1fs', $minutes, $remaining_seconds );
	}
}

function format_token_count( $tokens ) {
	if ( $tokens >= 1000000 ) {
		return sprintf( '%.1fM', $tokens / 1000000 );
	} elseif ( $tokens >= 1000 ) {
		return sprintf( '%.1fk', $tokens / 1000 );
	} else {
		return (string) $tokens;
	}
}
