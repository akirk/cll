<?php
require_once __DIR__ . '/includes/LogStorage.php';
require_once __DIR__ . '/includes/MessageStreamer.php';
require_once __DIR__ . '/includes/ApiClient.php';

$version = '2.0.2';
$ansi = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );

$options = array();
$initial_input = 1;
$i = 1;
while ( $i < count( $_SERVER['argv'] ) ) {
	$arg = $_SERVER['argv'][$i];

	if ( '--help' === $arg ) {
		$options['help'] = true;
	} elseif ( '--version' === $arg ) {
		$options['version'] = true;
	} elseif ( '--webui' === $arg ) {
		$options['webui'] = true;
	} elseif ( '--show-thinking' === $arg ) {
		$options['show-thinking'] = true;
	} elseif ( '--offline' === $arg ) {
		$options['offline'] = true;
	} elseif ( preg_match( '/^-([a-z]+)$/i', $arg, $matches ) ) {
		$flags = str_split( $matches[1] );
		foreach ( $flags as $flag ) {
			if ( in_array( $flag, array( 'd', 'l', 'v', 'h', 'f', 'n', 't', 'o' ) ) ) {
				$options[ $flag ] = true;
			} elseif ( in_array( $flag, array( 's', 'i', 'p', 'm', 'r', 'w' ) ) ) {
				if ( isset( $_SERVER['argv'][$i + 1] ) && ! preg_match( '/^-/', $_SERVER['argv'][$i + 1] ) ) {
					$options[ $flag ] = $_SERVER['argv'][++$i];
					$initial_input++;
				} else {
					$options[ $flag ] = false;
				}
			}
		}
		$initial_input++;
	} else {
		break;
	}
	$i++;
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

// Autocomplete function will be set later after models are loaded
$autocomplete_models = array();

function command_autocomplete( $input, $index ) {
	global $autocomplete_models;

	// Get the full line and current word
	$info = readline_info();
	$text = substr( $info['line_buffer'], 0, $info['end'] );

	$commands = array(
		'help',
		'?',
		'models',
		'update',
		'use',
		'system',
		'default',
		't',
		'debug',
		'tokens',
		'w',
		'webui',
		'quit',
		'exit',
		'bye',
	);

	// If line starts with "use ", suggest model names
	if ( preg_match( '/^use\s+(.*)$/', $text, $matches ) ) {
		$modelPrefix = $matches[1];
		$completions = array();
		foreach ( $autocomplete_models as $model ) {
			if ( empty( $modelPrefix ) || strpos( $model, $modelPrefix ) === 0 ) {
				$completions[] = $model;
			}
		}
		return $completions;
	}

	// Check if input matches a model name (allow autocomplete for direct model switching)
	$modelMatches = array();
	foreach ( $autocomplete_models as $model ) {
		if ( strpos( $model, $input ) === 0 ) {
			$modelMatches[] = $model;
		}
	}

	// Check if input matches a command
	$commandMatches = array();
	foreach ( $commands as $cmd ) {
		if ( strpos( $cmd, $input ) === 0 ) {
			$commandMatches[] = $cmd;
		}
	}

	// Prefer commands over models for short inputs (1-2 chars) to avoid autocomplete noise
	if ( strlen( $input ) <= 2 && ! empty( $commandMatches ) ) {
		return $commandMatches;
	}

	// Otherwise return both commands and models
	return array_merge( $commandMatches, $modelMatches );
}


readline_completion_function( 'command_autocomplete' );

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
if ( isset( $options['t'] ) || isset( $options['show-thinking'] ) ) {
	$messageStreamer->setShowThinking( true );
}

// Initialize ApiClient with logStorage for SQLite model/pricing support
$apiClient = new ApiClient( $logStorage );

$system = false;

$supported_models = $apiClient->getSupportedModels();

// Check if we need to update models
$shouldUpdate = false;
$updateReason = '';

if ( empty( $supported_models ) ) {
	$shouldUpdate = true;
	$updateReason = 'No models found';
} elseif ( isset( $options['m'] ) && empty( $options['m'] ) ) {
	$shouldUpdate = true;
	$updateReason = 'Manual update requested';
} elseif ( $logStorage && method_exists( $logStorage, 'getLastModelUpdate' ) ) {
	$lastUpdate = $logStorage->getLastModelUpdate();
	if ( $lastUpdate ) {
		$daysSinceUpdate = ( $time - $lastUpdate ) / 86400;
		if ( $daysSinceUpdate > 5 ) {
			$shouldUpdate = true;
			$updateReason = sprintf( 'Last update %.1f days ago', $daysSinceUpdate );
		}
	}
}

// Update models from APIs if needed
if ( $shouldUpdate ) {
	fprintf( STDERR, "Updating models from APIs... (%s)\n", $updateReason );
	$counts = $apiClient->updateModelsFromApis();
	fprintf( STDERR, "✓ Updated models: " );
	$parts = array();
	foreach ( $counts as $provider => $count ) {
		if ( $count > 0 ) {
			$parts[] = "{$provider} ({$count})";
		}
	}
	fprintf( STDERR, "%s\n", implode( ', ', $parts ) );
	$supported_models = $apiClient->getSupportedModels();

	// If -m was used without a value to trigger this update, automatically show models
	if ( isset( $options['m'] ) && $options['m'] === false ) {
		$show_models_after_init = true;
	}
}

// Populate autocomplete models
$autocomplete_models = array_keys( $supported_models );

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

// Determine if we're in offline mode
$isOffline = isset( $options['offline'] ) || isset( $options['o'] ) || ! $online;

// Get default model from SQLite or fallback to first available
$model = null;
if ( $logStorage && method_exists( $logStorage, 'getDefaultModel' ) ) {
	$model = $logStorage->getDefaultModel( $isOffline );
}

// If no default found in SQLite, use first available model
if ( ! $model ) {
	$model = key( $supported_models );
}

$supported_models_by_provider = array();
foreach ( $supported_models as $m => $provider ) {
	$model_group = $m;
	if ( 'OpenAI' === $provider && preg_match( '/^o\d/', $m ) ) {
		$model_group = strtok( $m, '-' );
	} elseif ( 'OpenAI' === $provider ) {
		$model_group = strtok( $m, '-' ) . '-' . strtok( '-' );
	} elseif ( 'Anthropic' === $provider ) {
		$model_group = strtok( $m, '-' ) . '-' . strtok( '-' ) . '-' . strtok( '-' );
	} elseif ( 'Ollama' === $provider ) {
		$model_group = strtok( $m, ':' );
	}

	$supported_models_by_provider[ $provider ][ $model_group ][] = $m;
}
ksort( $supported_models_by_provider );

// If -m was used without a value, show models list and exit
if ( isset( $show_models_after_init ) && $show_models_after_init ) {
	// Get default models
	$defaultOnline = null;
	$defaultOffline = null;
	if ( $logStorage && method_exists( $logStorage, 'getDefaultModel' ) ) {
		$defaultOnline = $logStorage->getDefaultModel( false );
		$defaultOffline = $logStorage->getDefaultModel( true );
	}

	// Infer defaults if not set
	if ( ! $defaultOnline ) {
		foreach ( $supported_models as $m => $p ) {
			if ( $p !== 'Ollama' ) {
				$defaultOnline = $m;
				break;
			}
		}
	}
	if ( ! $defaultOffline ) {
		foreach ( $supported_models as $m => $p ) {
			if ( $p === 'Ollama' ) {
				$defaultOffline = $m;
				break;
			}
		}
	}

	echo "\n\033[1mAvailable Models:\033[m\n";
	foreach ( $supported_models_by_provider as $provider => $groups ) {
		echo "\n\033[1m{$provider}:\033[m\n";
		foreach ( $groups as $group => $models ) {
			$count = count( $models );
			$firstModel = $models[0];

			// Collect markers for the first model in group
			$markers = array();
			if ( $firstModel === $model ) {
				$markers[] = "\033[32mcurrent\033[m";
			}
			if ( $firstModel === $defaultOnline ) {
				$markers[] = "\033[36mdefault online\033[m";
			}
			if ( $firstModel === $defaultOffline ) {
				$markers[] = "\033[36mdefault offline\033[m";
			}
			$marker_str = ! empty( $markers ) ? ' (' . implode( ', ', $markers ) . ')' : '';

			if ( $count === 1 ) {
				echo "  - {$firstModel}{$marker_str}\n";
			} else {
				echo "  - {$group}: {$firstModel}{$marker_str}";
				echo " \033[90m(+{$count} variants)\033[m\n";
			}
		}
	}
	echo "\n\033[90mUse -m <model> to select a model\033[m\n";
	echo "\n";
	exit( 0 );
}

if ( isset( $options['version'] ) ) {
	echo basename( $_SERVER['argv'][0] ), ' version ', $version, PHP_EOL;
	exit( 1 );
}

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	$offline = ! $online ? "(we're offline)" : '';
	$self = basename( $_SERVER['argv'][0] );
	echo <<<USAGE
Usage: $self [-l] [-f] [-r [number|searchterm]] [-m model] [-s [system_prompt|id]] [-i input_file_s] [-p picture_file] [-w port|--webui] [-t|--show-thinking] [conversation_input]

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
  -t, --show-thinking  Show thinking process for reasoning models (o1, o3, Claude with thinking).
  -n                   Don't save conversation to database (private mode).
  -o, --offline        Force offline mode (use local Ollama models only).

Arguments:
  conversation_input  Input for the first conversation.

Notes:
  - Type "help" or "?" at the prompt to see available commands.
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

  $self -m gpt-4o-mini
    Use a specific model. Type 'models' in the CLI to see all available models.


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

if ( isset( $options['m'] ) && $options['m'] !== false ) {
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
		fprintf( STDERR, "Error: Model '{$options['m']}' not found.\n" );
		fprintf( STDERR, "Run with no arguments and type 'models' to see available models.\n" );
		exit( 1 );
	}
}
$model_provider = $apiClient->getModelProvider( $model );
$wrapper = array(
	'model'  => $model,
	'stream' => true,
);

if ( $ansi || isset( $options['v'] ) ) {
	$offlineLabel = '';
	$thinkingLabel = '';
	$helpHint = "'help' for commands";

	if ( $isOffline ) {
		$offlineLabel = $ansi ? " \033[90m(offline)\033[m" : ' (offline)';
	}

	if ( isset( $options['t'] ) || isset( $options['show-thinking'] ) ) {
		$thinkingLabel = ', thinking enabled';
	} else {
		$helpHint .= ", 't' for thinking";
	}

	$helpHint .= ", 'w' for webui";

	if ( $ansi ) {
		$helpHint = "\033[90m{$helpHint}\033[m";
	}

	fprintf( STDERR, "Model: {$model} via {$model_provider}{$offlineLabel}" . ( isset( $options['v'] ) ? ' (verbose)' : '' ) . "{$thinkingLabel} - {$helpHint}\n" );
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
} elseif ( isset( $options['s'] ) || isset( $options['f'] ) || substr( $model, 0, 7 ) === 'gpt-oss' ) {
	$system = '';
	$system_prompt_name = null; // Track the name for tagging
	if ( isset( $options['s'] ) ) {
		if ( empty( $options['s'] ) ) {
			// Empty -s parameter: list all available system prompts
			if ( $logStorage && method_exists( $logStorage, 'getAllSystemPrompts' ) ) {
				$allPrompts = $logStorage->getAllSystemPrompts();
				if ( ! empty( $allPrompts ) ) {
					echo PHP_EOL, "\033[1mAvailable System Prompts:\033[m", PHP_EOL, PHP_EOL;
					foreach ( $allPrompts as $prompt ) {
						$defaultMarker = $prompt['is_default'] ? ' (default)' : '';
						echo '  ', $prompt['id'], ') ', "\033[1m", $prompt['name'], "\033[m", $defaultMarker, PHP_EOL;
						if ( ! empty( $prompt['description'] ) ) {
							echo '     ', $prompt['description'], PHP_EOL;
						}
						if ( ! empty( $prompt['prompt'] ) ) {
							$preview = substr( $prompt['prompt'], 0, 100 );
							if ( strlen( $prompt['prompt'] ) > 100 ) {
								$preview .= '...';
							}
							echo '     ', "\033[90m", $preview, "\033[m", PHP_EOL;
						}
						echo PHP_EOL;
					}
					echo "\033[90mUse -s <id> or -s <name> to use a system prompt\033[m", PHP_EOL, PHP_EOL;
				} else {
					echo 'No system prompts available.', PHP_EOL;
				}
			} else {
				echo 'System prompts are not available (no storage).', PHP_EOL;
			}
			exit( 0 );
		} elseif ( is_numeric( $options['s'] ) ) {
			$found_system_prompt = $logStorage->getSystemPrompt( intval( $options['s'] ) );
		} else {
			$found_system_prompt = $logStorage->getSystemPromptByName( $options['s'] );
		}
		if ( isset( $found_system_prompt ) && $found_system_prompt ) {
			$system = $found_system_prompt['prompt'];
			$system_prompt_name = $found_system_prompt['name']; // Store the name for tagging
			$words = preg_split( '/\s+/', $system, 11 );
			if ( isset( $words[10] ) ) {
				$words[10] = '...';
			}

			if ( $ansi || isset( $options['v'] ) ) {
				echo 'Loaded system prompt ', $found_system_prompt['id'], ': ', implode( ' ', $words ), PHP_EOL;
			}
		} elseif ( ! empty( $options['s'] ) ) {
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


$chunk_overflow = '';
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

	// Handle 't' command to view last thought process
	if ( trim( $input ) === 't' && ! empty( $thinking ) ) {
		echo "\033[90m" . PHP_EOL;
		echo '─── Thought Process ───' . PHP_EOL;
		echo $thinking . PHP_EOL;
		echo '───────────────────────' . PHP_EOL;
		echo "\033[m";
		continue;
	}

	// Handle 'debug' command to view last tokens and chunks
	if ( preg_match( '/^(?:debug|:debug|\/debug|tokens)(\s+(json|save))?$/i', trim( $input ), $debugMatches ) ) {
		$lastTokens = $messageStreamer->getLastTokens();
		$lastChunks = $messageStreamer->getLastChunks();
		$mode = isset( $debugMatches[2] ) ? strtolower( trim( $debugMatches[2] ) ) : 'normal';

		if ( $mode === 'json' ) {
			echo json_encode(
				array(
					'tokens' => $lastTokens,
					'chunks' => $lastChunks,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n";
		} elseif ( $mode === 'save' ) {
			if ( empty( $lastChunks ) ) {
				echo "\033[31mNo chunks available to save\033[m\n";
				continue;
			}

			echo "Enter basename for test fixture (e.g., 'my-test' will create tests/fixtures/input/my-test.json): ";
			$basename = trim( fgets( STDIN ) );

			if ( empty( $basename ) ) {
				echo "\033[31mCancelled\033[m\n";
				continue;
			}

			// Remove .json extension if provided
			if ( substr( $basename, -5 ) === '.json' ) {
				$basename = substr( $basename, 0, -5 );
			}

			$inputFixturePath = __DIR__ . '/tests/fixtures/input/' . $basename . '.json';
			$expectedDir = $ansi ? '/tests/fixtures/expected-ansi/' : '/tests/fixtures/expected/';
			$expectedFixturePath = __DIR__ . $expectedDir . $basename . '.txt';

			// Check if file exists
			if ( file_exists( $inputFixturePath ) ) {
				echo "\033[33mFile already exists. Overwrite? (y/n): \033[m";
				$confirm = trim( fgets( STDIN ) );
				if ( strtolower( $confirm ) !== 'y' && strtolower( $confirm ) !== 'yes' ) {
					echo "\033[31mCancelled\033[m\n";
					continue;
				}
			}

			// Write the chunks as JSON array (input fixture)
			// The API may split multi-byte UTF-8 characters across chunks
			// We need to save chunks with broken UTF-8 as base64 objects
			echo "\n\033[33mPreparing chunks for fixture:\033[m\n";

			// Convert chunks: valid UTF-8 as strings, broken UTF-8 as base64 objects
			$jsonChunks = array();
			$invalidCount = 0;
			foreach ( $lastChunks as $chunk ) {
				if ( mb_check_encoding( $chunk, 'UTF-8' ) ) {
					// Valid UTF-8, save as string
					$jsonChunks[] = $chunk;
				} else {
					// Broken UTF-8, save as object with base64
					$invalidCount++;
					$jsonChunks[] = array(
						'_base64' => base64_encode( $chunk ),
					);
				}
			}

			$json = json_encode( $jsonChunks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			if ( $json === false ) {
				echo "\033[31mError: Failed to encode chunks as JSON: " . json_last_error_msg() . "\033[m\n";
				continue;
			}

			if ( $invalidCount > 0 ) {
				echo "\033[33mWarning: {$invalidCount} chunks have broken UTF-8 (split multi-byte characters)\033[m\n";
				echo "These are preserved as base64 objects: {\"_base64\": \"...\"}\n";
			}
			if ( file_put_contents( $inputFixturePath, $json ) === false ) {
				echo "\033[31mError: Failed to write input fixture\033[m\n";
				continue;
			}

			// Generate both expected outputs (with and without ANSI)
			$expectedOutputPlain = '';
			$expectedOutputAnsi = '';

			// Plain output (no ANSI)
			$testStreamer = new MessageStreamer( false );
			iterator_to_array( $testStreamer->outputMessage( '' ) );
			$testStreamer->clearChunks();
			foreach ( $lastChunks as $chunk ) {
				$result = iterator_to_array( $testStreamer->outputMessage( $chunk ) );
				$expectedOutputPlain .= implode( '', $result );
			}

			// ANSI output
			$testStreamer = new MessageStreamer( true );
			iterator_to_array( $testStreamer->outputMessage( '' ) );
			$testStreamer->clearChunks();
			foreach ( $lastChunks as $chunk ) {
				$result = iterator_to_array( $testStreamer->outputMessage( $chunk ) );
				$expectedOutputAnsi .= implode( '', $result );
			}

			// Create expected directories if they don't exist
			$expectedPlainPath = __DIR__ . '/tests/fixtures/expected/' . $basename . '.txt';
			$expectedAnsiPath = __DIR__ . '/tests/fixtures/expected-ansi/' . $basename . '.txt';

			if ( ! is_dir( __DIR__ . '/tests/fixtures/expected' ) ) {
				mkdir( __DIR__ . '/tests/fixtures/expected', 0755, true );
			}
			if ( ! is_dir( __DIR__ . '/tests/fixtures/expected-ansi' ) ) {
				mkdir( __DIR__ . '/tests/fixtures/expected-ansi', 0755, true );
			}

			// Write both expected outputs
			if ( file_put_contents( $expectedPlainPath, $expectedOutputPlain ) === false ) {
				echo "\033[31mError: Failed to write plain expected output fixture\033[m\n";
				continue;
			}
			if ( file_put_contents( $expectedAnsiPath, $expectedOutputAnsi ) === false ) {
				echo "\033[31mError: Failed to write ANSI expected output fixture\033[m\n";
				continue;
			}

			echo "\033[32m✓ Saved test fixtures:\033[m\n";
			echo "  Input:         {$inputFixturePath} (" . count( $lastChunks ) . " chunks)\n";
			echo "  Expected:      {$expectedPlainPath}\n";
			echo "  Expected ANSI: {$expectedAnsiPath}\n";
			echo "\n\033[90mNote: Tests run automatically via fixtureProvider data provider\033[m\n";
			echo "\n\033[33mNext steps:\033[m\n";
			echo "  1. Review and edit the expected outputs if needed\n";
			echo "  2. Run tests: \033[1mcomposer test\033[m\n";
		} else {
			echo "\n\033[1m─── Debug Info ───\033[m\n";

			if ( ! empty( $lastTokens ) ) {
				echo "\n\033[1mTokens:\033[m\n";
				foreach ( $lastTokens as $type => $count ) {
					$type_label = str_replace( '_tokens', '', $type );
					if ( is_numeric( $count ) && $type !== 'total' ) {
						echo "  {$type_label}: {$count}\n";
					}
				}
			} else {
				echo "  No token data available\n";
			}

			if ( ! empty( $lastChunks ) ) {
				echo "\n\033[1mChunks (" . count( $lastChunks ) . " total):\033[m\n";
				foreach ( $lastChunks as $i => $chunk ) {
					$preview = substr( $chunk, 0, 60 );
					if ( strlen( $chunk ) > 60 ) {
						$preview .= '...';
					}
					$preview = str_replace( "\n", '\\n', $preview );
					echo sprintf( "  %3d: %s\n", $i + 1, $preview );
				}
			} else {
				echo "  No chunks available\n";
			}

			echo "\n\033[90mTip: Use 'debug json' for JSON output or 'debug save' to create a test fixture\033[m\n";
			echo "\n";
		}
		continue;
	}

	if ( false === $input || in_array( strtolower( trim( $input ) ), array( 'quit', 'exit', 'bye' ) ) ) {
		break;
	}

	// Check for models command
	if ( in_array( strtolower( trim( $input ) ), array( 'models', ':models', '/models' ) ) ) {
		// Get default models
		$defaultOnline = null;
		$defaultOffline = null;
		if ( $logStorage && method_exists( $logStorage, 'getDefaultModel' ) ) {
			$defaultOnline = $logStorage->getDefaultModel( false );
			$defaultOffline = $logStorage->getDefaultModel( true );
		}

		// Infer defaults if not set
		if ( ! $defaultOnline ) {
			foreach ( $supported_models as $m => $p ) {
				if ( $p !== 'Ollama' ) {
					$defaultOnline = $m;
					break;
				}
			}
		}
		if ( ! $defaultOffline ) {
			foreach ( $supported_models as $m => $p ) {
				if ( $p === 'Ollama' ) {
					$defaultOffline = $m;
					break;
				}
			}
		}

		echo "\n\033[1mAvailable Models:\033[m\n";
		foreach ( $supported_models_by_provider as $provider => $groups ) {
			echo "\n\033[1m{$provider}:\033[m\n";
			foreach ( $groups as $group => $models ) {
				$count = count( $models );
				$firstModel = $models[0];

				// Collect markers for the first model in group
				$markers = array();
				if ( $firstModel === $model ) {
					$markers[] = "\033[32mcurrent\033[m";
				}
				if ( $firstModel === $defaultOnline ) {
					$markers[] = "\033[36mdefault online\033[m";
				}
				if ( $firstModel === $defaultOffline ) {
					$markers[] = "\033[36mdefault offline\033[m";
				}
				$marker_str = ! empty( $markers ) ? ' (' . implode( ', ', $markers ) . ')' : '';

				if ( $count === 1 ) {
					echo "  - {$firstModel}{$marker_str}\n";
				} else {
					echo "  - {$group}: {$firstModel}{$marker_str}";
					echo " \033[90m(+{$count} variants)\033[m\n";
				}
			}
		}
		echo "\n\033[90mTip: Use 'switch <model>' to switch models\033[m\n";
		echo "\n";
		continue;
	}

	// Check for update models command
	if ( in_array( strtolower( trim( $input ) ), array( 'update', ':update', '/update' ) ) ) {
		echo "Updating models from APIs...\n";
		$counts = $apiClient->updateModelsFromApis();

		// Reload models
		$supported_models = $apiClient->getSupportedModels();
		$autocomplete_models = array_keys( $supported_models );

		echo "\033[32m✓ Models and pricing updated successfully\033[m\n";
		foreach ( $counts as $provider => $count ) {
			if ( $count > 0 ) {
				echo "  {$provider}: {$count} models\n";
			}
		}
		continue;
	}

	// Check for switch model command
	$potentialModelInput = null;
	if ( preg_match( '/^(?:use|:use|\/use)\s+(.+)$/i', trim( $input ), $matches ) ) {
		$potentialModelInput = trim( $matches[1] );
	} elseif ( isset( $supported_models[ trim( $input ) ] ) ) {
		// Allow switching by just typing the model name
		$potentialModelInput = trim( $input );
	}

	if ( $potentialModelInput !== null ) {
		$newModel = $potentialModelInput;

		// If not found directly, try finding a match
		if ( ! isset( $supported_models[ $newModel ] ) ) {
			// Try adding dash after gpt (e.g., "gpt4o" -> "gpt-4o")
			$normalized = preg_replace( '/^(gpt)(\d)/', '$1-$2', $newModel );
			if ( isset( $supported_models[ $normalized ] ) ) {
				$newModel = $normalized;
			} else {
				// Try partial match (e.g., "o1" matches "o1-2024-12-17" or "o1-mini")
				$matches = array();
				foreach ( array_keys( $supported_models ) as $modelName ) {
					if ( strpos( $modelName, $newModel ) === 0 ) {
						$matches[] = $modelName;
					}
				}

				if ( count( $matches ) === 1 ) {
					$newModel = $matches[0];
				} elseif ( count( $matches ) > 1 ) {
					echo "\033[33mAmbiguous model '{$potentialModelInput}'. Did you mean:\033[m\n";
					foreach ( array_slice( $matches, 0, 10 ) as $match ) {
						echo "  - {$match}\n";
					}
					continue;
				}
			}
		}

		if ( ! isset( $supported_models[ $newModel ] ) ) {
			echo "\033[31mError: Model '{$potentialModelInput}' not found. Type 'models' to see available models.\033[m\n";
			continue;
		}

		$model = $newModel;
		$model_provider = $supported_models[ $model ];
		echo "\033[32mSwitched to model '{$model}' ({$model_provider})\033[m\n";
		continue;
	}

	// Check for system prompt command
	if ( preg_match( '/^(?:system|:system|\/system)(?:\s+(.+))?$/i', trim( $input ), $matches ) ) {
		if ( isset( $matches[1] ) && ! empty( trim( $matches[1] ) ) ) {
			// Set new system prompt
			$system = trim( $matches[1] );
			$system_prompt_name = null;
			echo "\033[32mSystem prompt set to: \033[m\"{$system}\"\n";
		} else {
			// Show current system prompt
			if ( $system ) {
				echo "\033[1mCurrent system prompt:\033[m\n{$system}\n";
			} else {
				echo "\033[90mNo system prompt set\033[m\n";
			}
		}
		continue;
	}

	// Check for default command (sets current model as default)
	if ( preg_match( '/^(?:default|:default|\/default)$/i', trim( $input ) ) ) {
		$modelProvider = $supported_models[ $model ];
		$modelIsOffline = ( $modelProvider === 'Ollama' );

		if ( $logStorage && method_exists( $logStorage, 'setDefaultModel' ) ) {
			$logStorage->setDefaultModel( $model, $modelIsOffline );
			$modeLabel = $modelIsOffline ? 'offline' : 'online';
			echo "\033[32mSet '{$model}' as default {$modeLabel} model.\033[m\n";
		} else {
			echo "\033[31mError: Cannot set default model (storage not available).\033[m\n";
		}
		continue;
	}

	// Check for help command
	if ( in_array( strtolower( trim( $input ) ), array( 'help', ':help', '/help', '?' ) ) ) {
		echo "\n\033[1mAvailable Commands:\033[m\n";
		echo "  \033[1mmodels\033[m               List all available models\n";
		echo "  \033[1mupdate\033[m               Update models and pricing from APIs\n";
		echo "  \033[1muse <model>\033[m          Switch to a different model for this conversation\n";
		echo "  \033[1mdefault\033[m              Set current model as default (offline/online auto-detected)\n";
		echo "  \033[1msystem [text]\033[m        View or set system prompt\n";
		echo "  \033[1mt\033[m                    View thought process from last response\n";
		echo "  \033[1mdebug [json|save]\033[m    View token usage and chunks ('json' for JSON, 'save' to create test fixture)\n";
		echo "  \033[1mw, webui\033[m             Open web UI in browser\n";
		echo "  \033[1mhelp, ?\033[m              Show this help message\n";
		echo "  \033[1mquit, exit, bye\033[m      Exit the program\n";
		echo "\n\033[1mInput Modes:\033[m\n";
		echo "  Empty line or \033[1m.\033[m      Start multiline input\n";
		echo "  Line ending with \033[1m:\033[m   Continue multiline input\n";
		echo "\n";
		continue;
	}

	// Check for webui switch command
	if ( in_array( strtolower( trim( $input ) ), array( 'w', 'webui', ':webui', '/webui' ) ) ) {
		$port = 8381; // default port
		$host = 'localhost';
		$url = "http://{$host}:{$port}";

		echo "Switching to web UI at {$url}...", PHP_EOL;

		// Check if port is available
		$socket = @fsockopen( $host, $port, $errno, $errstr, 1 );
		if ( $socket ) {
			fclose( $socket );
			echo "Web UI already running. Opening browser...", PHP_EOL;
		} else {
			// Start PHP built-in server in background
			$command = "php -S {$host}:{$port} -t " . escapeshellarg( __DIR__ ) . ' > /dev/null 2>&1 &';
			exec( $command );
			echo "Starting web server...", PHP_EOL;

			// Give server time to start
			sleep( 1 );

			// Verify server started
			$socket = @fsockopen( $host, $port, $errno, $errstr, 2 );
			if ( ! $socket ) {
				echo "Failed to start web server: {$errstr}", PHP_EOL;
				continue;
			}
			fclose( $socket );
			echo "Web server started successfully.", PHP_EOL;
		}

		// Open browser to the current conversation if we have one
		if ( isset( $conversation_id ) && $conversation_id ) {
			$conversation_url = "{$url}?action=view&id={$conversation_id}";
			exec( "open '{$conversation_url}'" );
			echo "Opening current conversation in browser: {$conversation_url}", PHP_EOL;
		} else {
			exec( "open '{$url}'" );
			echo "Opening web UI in browser: {$url}", PHP_EOL;
		}

		echo "You can continue this conversation in the web interface.", PHP_EOL;
		echo "Type 'quit' to exit CLI or continue typing here.", PHP_EOL;
		continue;
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

	// Check for single-word input that isn't a known command or common word
	$trimmedInput = trim( $input );
	$wordCount = str_word_count( $trimmedInput );
	$commonWords = array( 'yes', 'no', 'ok', 'okay', 'sure', 'thanks', 'please', 'why', 'how', 'what', 'when', 'where', 'who' );

	if ( $wordCount === 1 && ! in_array( strtolower( $trimmedInput ), $commonWords ) ) {
		echo "\033[33mSend single word '{$trimmedInput}' to the LLM? (y/n): \033[m";
		$confirm = trim( fgets( STDIN ) );
		if ( strtolower( $confirm ) !== 'y' && strtolower( $confirm ) !== 'yes' ) {
			continue;
		}
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

	// Use ApiClient to prepare the request
	try {
		$apiConfig = $apiClient->prepareApiRequest( $model, $messages, $system );
		curl_setopt( $ch, CURLOPT_URL, $apiConfig['url'] );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $apiConfig['headers'] );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $apiConfig['data'] ) );
	} catch ( Exception $e ) {
		echo 'API Error: ', $e->getMessage(), PHP_EOL;
		exit( 1 );
	}

	if ( $ansi || isset( $options['v'] ) ) {
		echo PHP_EOL;
	}
	$message = '';
	$thinking = ''; // Reset thinking for new message
	$messageStreamer->resetThinking();

	curl_setopt( $ch, CURLOPT_WRITEFUNCTION, $messageStreamer->createCurlWriteHandler( $message, $chunk_overflow, $usage, $model_provider, $thinking ) );

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

	// Show hint after response completes
	$hints = array();
	if ( ! empty( $thinking ) && ! isset( $options['t'] ) && ! isset( $options['show-thinking'] ) ) {
		$hints[] = "'t' to view thought process";
		$hints[] = "'w' to open web UI";
}

	if ( ! empty( $hints ) ) {
		$hint_text = '(Type ' . implode( ', ', $hints ) . ')';
		if ( $ansi ) {
			echo "\033[90m{$hint_text}\033[m" . PHP_EOL;
		} else {
			echo $hint_text . PHP_EOL;
		}
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
		$logStorage->writeAssistantMessage( $conversation_id, $message, null, ! empty( $thinking ) ? $thinking : null );
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
			$current_cost = $apiClient->calculateCost( $model, $current_input_tokens, $current_output_tokens, $current_cache_read_tokens, $current_cache_write_tokens );
			if ( $current_cost > 0 ) {
				$logStorage->storeCostData( $conversation_id, $current_cost, $current_input_tokens, $current_output_tokens );
			}
		}

		// Always store tokens for debugging
		$messageStreamer->storeTokens( $usage );
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
	$cost = $apiClient->calculateCost( $model, $total_input_tokens, $total_output_tokens, $total_cache_read_tokens, $total_cache_write_tokens );

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
