<?php
require_once __DIR__ . '/LogStorage.php';

$version = '1.1.2';
$openai_key = getenv( 'OPENAI_API_KEY', true );
$anthropic_key = getenv( 'ANTHROPIC_API_KEY', true );
$ansi = function_exists( 'posix_isatty' ) && posix_isatty( STDOUT );

$options = getopt( 'ds:li:p:vhfm:r:w::', array( 'help', 'version', 'webui' ), $initial_input );

if ( ! isset( $options['m'] ) ) {
	putenv('RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1');
	$online = gethostbyname( 'api.openai.com.' ) !== 'api.openai.com.';
} else {
	$online = true;
}

$ch = curl_init();
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

function dont_auto_complete ($input, $index) { return []; }
function output_message( $message ) {
	global $ansi;
	if ( ! isset( $ansi ) ) {
		$ansi = false;
	}
	static $old_message = '';
	if ( $message === '') {
		$old_message = '';
	}
	static $chunks = array();
	if ( $message === '---OUTPUTOKENS' ) {
		echo PHP_EOL, 'Received ', count( $chunks ), " chunks: \033[37m", json_encode( $chunks ), PHP_EOL, "\033[m";
		$chunks = array();
		return;
	} else {
		$chunks[] = $message;
	}
	static $state = array(
		'maybe_bold' => false,
		'maybe_underline' => false,
		'maybe_underline_words' => 0,
		'maybe_space_to_tab' => false,
		'bold' => false,
		'headline' => false,
		'trimnext' => false,
		'inline_code' => false,
		'in_code_block' => false,
		'code_block_start' => false,
		'maybe_code_block_end' => false,
	);

	$message = $old_message . $message;
	$i = strlen( $old_message );
	$old_message = $message;
	$length = strlen( $message );

	while ($i < $length) {

		// Check for the start of a code block
		$last_php_eol = $i > 1 ? strrpos( $message, PHP_EOL, $i - $length - 1 ) : 0;
		$is_word_delimiter = strpos( PHP_EOL . ' ,;.-_!?()[]{}:', $message[$i] ) !== false;

		if ( $i > 1 && substr( $message, $i - 2, 3 ) === '```' && trim( substr( $message, $last_php_eol, $i - $last_php_eol - 2 ) ) === '' ) {

			// Strip code delimiters when in ansi.
			if ( $state['in_code_block'] ) {
				if ( $ansi ) {
					echo "\033[m";
				}
				if ( false !== $state['maybe_code_block_end']) {
					if ( $ansi ) {
						echo substr( $message, $state['maybe_code_block_end'], 2 );
					}
					$state['maybe_code_block_end'] = false;
				}
				$state['in_code_block'] = false;
			} else {
				$state['code_block_start'] = true;
				if ( $ansi ) {
					echo substr($message, $i - 2, 2);
				}
			}
			if ( $ansi ) {
				echo $message[$i];
			}
			$i++;
			continue;
		}

		// If we're in a code block, just output the text as is
		if ($state['code_block_start'] ) {
			if ( $ansi ) {
				echo $message[$i];
			}
			if ($message[$i] === PHP_EOL) {
				$state['code_block_start'] = false;
				$state['in_code_block'] = true;
				// show in darkgrey
				if ( $ansi ) {
					echo "\033[90m";
				}
			}
			$i++;
			continue;
		}

		if ($state['in_code_block']) {
			if ( $message[$i] === PHP_EOL ) {
				$state['maybe_space_to_tab'] = 0;
				echo $message[$i++];
				continue;
			}
			if ( $state['maybe_space_to_tab'] !== false ) {
				if ( $message[$i] === ' ') {
					$i++;
					$state['maybe_space_to_tab']++;
					continue;
				}

				$spaces_count = $state['maybe_space_to_tab'];
				$state['maybe_space_to_tab'] = false;
				if ( $spaces_count > 0 ) {
					if ( $spaces_count % 4 == 0 ) {
						echo str_repeat( "\t", $spaces_count / 4 );
					} else {
						echo str_repeat( " ", $spaces_count );
					}
					echo $message[$i++];
					continue;
				}
			}
			$state['maybe_space_to_tab'] = false;
			if ( false === $state['maybe_code_block_end'] && $message[$i] === '`' && trim( substr( $message, $last_php_eol, $i - $last_php_eol-1) ) === '') {
				$state['maybe_code_block_end'] = $i;
				$i++;
				continue;
			}
			if ( false !== $state['maybe_code_block_end'] && substr( $message, $i-1, 2) === '``' && trim( substr( $message, $last_php_eol, $i - $last_php_eol-2) ) === '') {
				$i++;
				continue;
			}
			echo $message[$i++];
			continue;
		}

		// Process bold and headline markers only outside code blocks
		if ($message[$i] === '*') {
			// The second *.
			if ( $state['maybe_bold'] ) {
				$state['bold'] = !$state['bold'];
				if ( $ansi ) {
					echo $state['bold'] ? "\033[1m" : "\033[m";
				}
				$state['maybe_bold'] = false;
			} elseif ( false !== $state['maybe_underline'] ) {
				// write the buffered word with an underline
				if ( $ansi ) {
					echo "\033[4m";
				}
				echo substr( $message, $state['maybe_underline'], $i - $state['maybe_underline'] );
				if ( $ansi ) {
					echo "\033[m";
				}
				$state['maybe_underline'] = false;
			} else {
				$state['maybe_bold'] = true;
			}
			$i++; // Move past the bold indicator
			continue;
		} elseif ( $state['maybe_bold'] ) {
			// No second *.
			$state['maybe_bold'] = false;
			// This might become an underline if we find another * before a word separator.
			if ( ! $is_word_delimiter ) {
				$state['maybe_underline'] = $i;
				$state['maybe_underline_words'] = 0;
				$i++;
				continue;
			}
			$i--;
		} elseif ( false !== $state['maybe_underline'] ) {
			if ( ! $is_word_delimiter ) {
				// buffer
				$i++;
				continue;
			}
			if ( $is_word_delimiter && $message[$i] !== PHP_EOL ) {
				$state['maybe_underline_words']++;
				if ( $state['maybe_underline_words'] < 3 ) {
					// buffer
					$i++;
					continue;
				}
			}
			echo substr( $message, $state['maybe_underline'] - 1, $i - $state['maybe_underline'] + 1);
			$state['maybe_underline'] = false;
			$state['maybe_underline_words'] = 0;
		}

		// Process bold and headline markers only outside code blocks
		if ($i > 1 && substr($message, $i-1, 2) === '**' && substr($message, $i - 2, 1) === PHP_EOL) {
			$state['bold'] = !$state['bold'];
			if ( $ansi ) {
				echo $state['bold'] ? "\033[1m" : "\033[m";
			}
			$i++; // Move past the bold indicator
			continue;
		}

		if ( substr($message, $i, 1) === '`') {
			$state['inline_code'] = !$state['inline_code'];
			if ( $ansi ) {
				echo $state['inline_code'] ? "\033[34m" : "\033[m";
			}
			$i++;
			continue;
		}

		if ( $state['trimnext'] ) {
			if (trim($message[$i]) == '') {
				$i++;
				continue;
			}
			$state['trimnext'] = false;
		}

		if ( substr($message, $i, 1) === '#' && ( substr($message, $i - 1, 1) === PHP_EOL || !$i ) ) {
			// Start of a headline
			$state['headline'] = true;
			$state['trimnext'] = true;
			echo "\033[4m";
			while ( $i < $length && ( $message[$i] === '#' || $message[$i] === ' ') ) {
				$i++;
			}
			continue;
		}

		// Reset states on new lines
		if ($message[$i] === PHP_EOL) {
			if ( $i > 2 && substr($message, $i - 3, 3) === PHP_EOL . PHP_EOL . PHP_EOL ) {
				$i++;
				continue;
			}
			if ($state['bold'] || $state['headline'] || $state['maybe_bold'] || $state['maybe_underline']) {
				echo "\033[m"; // Reset bold and headline
				$state['bold'] = false;
				$state['headline'] = false;
				$state['maybe_bold'] = false;
				$state['maybe_underline'] = false;
			}
		}

		echo $message[$i++];
	}
}

readline_completion_function("dont_auto_complete");

$readline_history_file = __DIR__ . '/.history';
$chats_base_directory = __DIR__ . '/chats/';
$sqlite_db_path = __DIR__ . '/chats.sqlite';
$time = time();

// Initialize log storage - prefer SQLite, fallback to file storage
try {
	if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers())) {
		$logStorage = new SQLiteLogStorage($sqlite_db_path);
		$storage_type = 'SQLite';
	} else {
		$logStorage = new FileLogStorage($chats_base_directory);
		$storage_type = 'File';
	}
} catch (Exception $e) {
	// Fallback to file storage if SQLite fails
	try {
		$logStorage = new FileLogStorage($chats_base_directory);
		$storage_type = 'File (fallback)';
	} catch (Exception $e2) {
		echo 'Error initializing log storage: ', $e2->getMessage(), PHP_EOL;
		exit(1);
	}
}

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

if ( $online && isset( $options['m'] ) && $options['m'] == '' ) {
	// Update models.
	if ( ! empty( $openai_key ) ) {
		curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/models' );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $openai_key,
			)
		);

		$response = curl_exec($ch);
		$data = json_decode($response, true);

		foreach ($data['data'] as $model) {
			if ( 0 === strpos( $model['id'], 'gpt' ) ) {
				$supported_models[ $model['id'] ]  = 'OpenAI';
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
		$response = curl_exec($ch);
		$data = json_decode($response, true);

		foreach ( $data['data'] as $model ) {
			if ( $model['type'] === 'model' && 0 === strpos( $model['id'], 'claude' ) ) {
				$supported_models[ $model['id'] ] = 'Anthropic';
			}
		}
	}

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
	$socket = @fsockopen($host, $port, $errno, $errstr, 1);
	if ($socket) {
		fclose($socket);
		echo "Port {$port} is already in use. Trying to open browser anyway...", PHP_EOL;
		exec("open '{$url}'");
		exit(0);
	}

	// Start PHP built-in server in background
	$command = "php -S {$host}:{$port} -t " . escapeshellarg(__DIR__) . " > /dev/null 2>&1 &";
	exec($command);

	// Give server time to start
	sleep(1);

	// Verify server started
	$socket = @fsockopen($host, $port, $errno, $errstr, 2);
	if (!$socket) {
		echo "Failed to start web server on port {$port}: {$errstr}", PHP_EOL;
		exit(1);
	}
	fclose($socket);

	// Open browser
	exec("open '{$url}'");
	echo "Web UI started at {$url}", PHP_EOL;
	echo "Server running in background. Press Ctrl+C to stop it.", PHP_EOL;

	// Keep script running to show the message
	while (true) {
		sleep(3600); // Sleep for 1 hour, then repeat message
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

$model_weight = array_flip( array_reverse( array( 'gpt-4o-mini', 'gemma3', 'llama3', 'llama2' ) ) );
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
    Supported modes: $supported_models_list $offline


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
$model_provider = $supported_models[$model];
$wrapper = array(
	'model'  => $model,
	'stream' => true,
);

if ( $ansi || isset( $options['v'] ) ) {
	fprintf( STDERR, 'Model: ' . $model . ' via ' . $model_provider . ( isset( $options['v'] ) ? ' (verbose)' : '' ) . ', ' );
	fprintf( STDERR, 'Storage: ' . $storage_type . PHP_EOL );
}

// For SQLite, we'll let it auto-generate the ID. For file storage, we use timestamp.
$conversation_id = ($storage_type === 'SQLite') ? null : $time;

if ( isset( $options['l'] ) ) {
	$lastConversations = $logStorage->findConversations(1);
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
		$test_conversation = null;
		if ($storage_type === 'SQLite') {
			$test_conversation = $logStorage->getConversationMetadata($options['r']);
		} else {
			// For file storage, we need to find the file first
			$test_file = null;
			$historyDirectory = __DIR__ . '/chats/' . date('Y/m', $options['r']);
			$pattern = $historyDirectory . '/history.' . $options['r'] . '*.txt';
			$files = glob($pattern);
			if ($files) {
				$test_file = $files[0];
				$test_conversation = $logStorage->getConversationMetadata($test_file);
			}
		}

		if ($test_conversation) {
			$specific_conversation_id = $options['r'];
			$conversations = array($specific_conversation_id => null);
		} else {
			$options['r'] = intval( $options['r'] );
			if ( $options['r'] <= 0 ) {
				$options['r'] = 10;
			}
		}
	}

	if (!$specific_conversation_id) {
		$conversation_list = $logStorage->findConversations($options['r'] * 10, $search);
		$conversations = array();
		foreach ($conversation_list as $conversation_key) {
			$conversations[$conversation_key] = null; // Will be loaded later
		}
	} else {
		// For specific conversation ID, we need to load the conversation data
		if ($storage_type === 'SQLite') {
			$conversation_data = $logStorage->loadConversation($specific_conversation_id);
			// Convert SQLite format to simple array format
			if ($conversation_data && is_array($conversation_data)) {
				$simple_split = [];
				foreach ($conversation_data as $msg) {
					if (is_array($msg) && isset($msg['content'])) {
						$simple_split[] = $msg['content'];
					} else {
						$simple_split[] = $msg;
					}
				}
				$conversations[$specific_conversation_id] = $simple_split;
			} else {
				echo 'Conversation not found or empty.', PHP_EOL;
				exit(1);
			}
		} else {
			// For file storage, find the actual file path
			$historyDirectory = __DIR__ . '/chats/' . date('Y/m', $specific_conversation_id);
			$pattern = $historyDirectory . '/history.' . $specific_conversation_id . '*.txt';
			$files = glob($pattern);
			if ($files) {
				$file_path = $files[0];
				$conversation_data = $logStorage->loadConversation($file_path);
				if ($conversation_data) {
					$conversations[$file_path] = $conversation_data;
					// Use file path as key for file storage
					$specific_conversation_id = $file_path;
				} else {
					echo 'Conversation not found or empty.', PHP_EOL;
					exit(1);
				}
			} else {
				echo 'Conversation file not found.', PHP_EOL;
				exit(1);
			}
		}

		// Set up for direct resume
		$last_conversations = array(1 => $specific_conversation_id);
	}

	$length = is_numeric($options['r']) ? $options['r'] : 10;
	if ( isset( $options['l'] ) ) {
		echo 'Resuming the last conversation.', PHP_EOL;
	} else {
		if ($specific_conversation_id) {
			echo 'Resuming conversation ID: ', ($storage_type === 'SQLite' ? $specific_conversation_id : basename($specific_conversation_id, '.txt')), PHP_EOL;
		} else {
			echo 'Resuming a conversation. ';
		}
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

		if ( !empty( $current_conversation_batch ) ) {
			$length = 10;
			foreach ( $current_conversation_batch as $k => $conversation_key ) {
				// Get conversation metadata from storage
				$metadata = $logStorage->getConversationMetadata($conversation_key);
				if (!$metadata) {
					unset( $conversations[ $conversation_key ] );
					unset( $current_conversation_batch[ $k ] );
					continue;
				}

				$used_model = $metadata['model'];
				if ( 'txt' === $used_model || empty($used_model) ) {
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

				// Load conversation data differently based on storage type
				if ($storage_type === 'SQLite') {
					$split = $logStorage->loadConversation($conversation_key);
					// Convert SQLite format to simple array format expected by the rest of the code
					if ($split && is_array($split)) {
						$simple_split = [];
						foreach ($split as $msg) {
							if (is_array($msg) && isset($msg['content'])) {
								$simple_split[] = $msg['content'];
							} else {
								$simple_split[] = $msg;
							}
						}
						$split = $simple_split;
					}
				} else {
					// File storage expects file path
					$split = $logStorage->loadConversation($conversation_key);
				}

				if (!$split || count($split) < 2) {
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
					if (is_array($first_message) && isset($first_message['content'])) {
						$first_message = $first_message['content'];
					}
					if (substr($first_message, 0, 7) === 'System:') {
						$first_message = isset($conversations[ $conversation_key ][1]) ? $conversations[ $conversation_key ][1] : '';
						if (is_array($first_message) && isset($first_message['content'])) {
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
			exit(1);
		}

		$conversation_key = $last_conversations[ $sel ];
		if ( ! isset( $conversations[ $conversation_key ] ) || ! is_array( $conversations[ $conversation_key ] ) ) {
			echo 'Conversation data not found.', PHP_EOL;
			exit(1);
		}

		$first_message = $conversations[ $conversation_key ][ 0 ];
		$first_content = is_array($first_message) && isset($first_message['content']) ? $first_message['content'] : $first_message;

		if ( substr( $first_content, 0, 7 ) === 'System:' ) {
			$system = substr( $first_content, 8, strpos( $first_content, PHP_EOL ) - 8 );
			$remaining_content = substr( $first_content, strlen( $system ) + 9 );
			$system_prompt_name = null; // Resume case - no name available

			// Update the first message with the remaining content
			if (is_array($conversations[ $conversation_key ][ 0 ])) {
				$conversations[ $conversation_key ][ 0 ]['content'] = $remaining_content;
			} else {
				$conversations[ $conversation_key ][ 0 ] = $remaining_content;
			}

			if ( isset( $options['s'] ) && $options['s'] ) {
				echo 'Old System prompt: ' . $system, PHP_EOL, 'New ';
				$system = $options['s'];
				$system_prompt_name = null; // Override case - custom prompt
			}
			echo 'System prompt: ', $system, PHP_EOL;
			if ( $model_provider === 'Anthropic' ) {
				$wrapper['system'] = $system;
			} else {
				array_unshift( $messages, array(
					'role'    => 'system',
					'content' => $system,
				) );
			}
		}
		$conversation_data = $conversations[ $conversation_key ];
		foreach ( $conversation_data as $k => $message ) {
			if ( isset( $options['d'] ) && $k % 2 ) {
				// Ignore assistant answers.
				continue;
			}

			// Handle both string messages and array format from SQLite
			$content = is_array($message) && isset($message['content']) ? $message['content'] : $message;

			$messages[] = array(
				'role'    => $k % 2 ? 'assistant' : 'user',
				'content' => $content,
			);

			if ( 0 === $k % 2 ) {
				echo '> ';
			}
			output_message( $content . PHP_EOL );
		}
		if ( isset( $options['d'] ) ) {
			$initial_input = ' ';
			// Answer the question right away.
		}

	}
} elseif ( ! empty( $options['s'] ) || isset( $options['f'] ) ) {
	$system = '';
	$system_prompt_name = null; // Track the name for tagging
	if ( ! empty( $options['s'] ) ) {
		if ($storage_type === 'SQLite') {
			if (is_numeric($options['s'])) {
				$found_system_prompt = $logStorage->getSystemPrompt(intval($options['s']));
			} else {
				$found_system_prompt = $logStorage->getSystemPromptByName($options['s']);
			}
			if ( $found_system_prompt ) {
				$system = $found_system_prompt['prompt'];
				$system_prompt_name = $found_system_prompt['name']; // Store the name for tagging
				$words = preg_split( '/\s+/', $system, 11 );
				if ( isset( $words[10] ) ) {
					$words[10] = '...';
				}

				if ( $ansi || isset( $options['v'] ) ) {
					echo 'Loaded system prompt ', $found_system_prompt['id'] , ': ', implode( ' ', $words ), PHP_EOL;
				}
			} else {
				$system = $options['s'];
				$system_prompt_name = null; // Custom prompt, no name
				if ( $ansi || isset( $options['v'] ) ) {
					echo 'System prompt: ', $system, PHP_EOL;
				}
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
	if ( $system ) {
		if ( $model_provider === 'Anthropic' ) {
			$wrapper['system'] = $system;
		} else {
			array_unshift( $messages, array(
				'role'    => 'system',
				'content' => $system,
			) );
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
curl_setopt(
	$ch,
	CURLOPT_WRITEFUNCTION,
	function ( $curl, $data ) use ( &$message, &$chunk_overflow, &$usage, $model_provider ) {
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

			if ( $model_provider === 'Anthropic' ) {
				if ( isset( $json['delta']['text'] ) ) {
					output_message( $json['delta']['text'] );

					$message .= $json['delta']['text'];
				} else {
					$chunk_overflow = $item;
				}
			} else {
				if ( isset( $json['choices'][0]['delta']['content'] ) ) {
					output_message( $json['choices'][0]['delta']['content'] );

					$message .= $json['choices'][0]['delta']['content'];
				} else {
					$chunk_overflow = $item;
				}
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
			
			// Extract conversation ID depending on storage type
			if ($storage_type === 'SQLite') {
				$conversation_id = $source_key; // Use existing conversation ID
			} else {
				// File storage - extract from filename
				$source_conversation_id = basename($source_key, '.txt');
				$source_conversation_id = preg_replace('/^history\.(\d+)\..*$/', '$1', $source_conversation_id);
				$conversation_id = $source_conversation_id; // Use existing conversation ID
			}
		} else {
			// Only initialize new conversation if not resuming
			$conversation_id = $logStorage->initializeConversation($conversation_id, $model);
		}
		if ( $system ) {
			$logStorage->writeSystemPrompt($conversation_id, $system, $system_prompt_name);
			$system = false;
		}
		$conversation_initialized = true;
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

	if ( ltrim( $input ) === $input ) {
		// Persist history unless prepended by whitespace.
		readline_write_history( $readline_history_file );
		if ( $system ) {
			$logStorage->writeSystemPrompt($conversation_id, $system, $system_prompt_name);
			$system = false;
		}
		$logStorage->writeUserMessage($conversation_id, $input);
	}

	$image = false;
	if ( isset( $options['p'] ) ) {
		if ( preg_match( '/^(gpt-4o|llava)/', $model ) ) {
			$image = trim( $options['p'] );
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
			echo 'Image input is only supported with gpt-4o* or llava.', PHP_EOL;
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

	$output = curl_exec( $ch );
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
		// Persist history unless prepended by whitespace or coming from stdin.
		$logStorage->writeAssistantMessage($conversation_id, $message);
	}

	if ( isset( $options['v'] ) ) {
		output_message( '---OUTPUTOKENS' );
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
$logStorage->close();
