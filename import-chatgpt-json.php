<?php
require_once __DIR__ . '/includes/LogStorage.php';

class ExternalConversationImporter {
	private $sqliteStorage;
	private $imported = 0;
	private $skipped = 0;
	private $errors = 0;
	private $defaultModel = null;

	public function __construct( $sqliteDbPath, $defaultModel = null ) {
		if ( ! file_exists( $sqliteDbPath ) ) {
			throw new Exception( "SQLite database not found: {$sqliteDbPath}" );
		}
		$this->sqliteStorage = new SQLiteLogStorage( $sqliteDbPath );
		$this->defaultModel = $defaultModel;
	}

	public function importFile( $jsonFilePath, $dryRun = false, $interactive = true ) {
		if ( ! file_exists( $jsonFilePath ) ) {
			throw new Exception( "JSON file not found: {$jsonFilePath}" );
		}

		$jsonContent = file_get_contents( $jsonFilePath );
		$data = json_decode( $jsonContent, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( 'Invalid JSON: ' . json_last_error_msg() );
		}

		if ( ! isset( $data['chat_messages'] ) || ! is_array( $data['chat_messages'] ) ) {
			throw new Exception( 'Invalid conversation export format: missing chat_messages' );
		}

		$this->importConversation( $data, $dryRun, $interactive );
		$this->printSummary();
	}

	private function importConversation( $data, $dryRun = false, $interactive = true ) {
		try {
			$name = $data['name'] ?? 'Untitled';
			$created_at = isset( $data['created_at'] ) ? strtotime( $data['created_at'] ) : time();
			$messages = $data['chat_messages'] ?? array();

			if ( empty( $messages ) ) {
				echo "⚠️  Skipping conversation with no messages: {$name}\n";
				++$this->skipped;
				return;
			}

			// Determine model from messages or use default
			$detectedModel = $this->detectModel( $messages );
			$model = $this->defaultModel ? $this->defaultModel : $detectedModel;

			// In interactive mode, ask user to confirm/change model
			if ( $interactive && ! $dryRun && php_sapi_name() === 'cli' ) {
				echo "\nConversation: {$name}\n";
				echo 'Messages: ' . count( $messages ) . "\n";
				echo "Detected model: {$detectedModel}\n";
				if ( $this->defaultModel ) {
					echo "Using specified model: {$model}\n";
				}
				echo "Enter model name to use (or press Enter to use '{$model}'): ";
				$input = trim( fgets( STDIN ) );
				if ( ! empty( $input ) ) {
					$model = $input;
				}
				echo "\n";
			}

			if ( $dryRun ) {
				echo "📄 Would import: {$name} ({$model}, " . count( $messages ) . " messages) from " . date( 'Y-m-d H:i', $created_at ) . "\n";
				++$this->imported;
				return;
			}

			// Initialize conversation
			$conversationId = $this->sqliteStorage->initializeConversation( null, $model, $created_at );

			// Import messages
			foreach ( $messages as $msg ) {
				$sender = $msg['sender'] ?? 'unknown';
				$content = $this->extractContent( $msg );
				$timestamp = isset( $msg['created_at'] ) ? strtotime( $msg['created_at'] ) : $created_at;

				if ( empty( $content ) ) {
					continue;
				}

				if ( $sender === 'human' ) {
					$this->sqliteStorage->writeUserMessage( $conversationId, $content, $timestamp );
				} elseif ( $sender === 'assistant' ) {
					$this->sqliteStorage->writeAssistantMessage( $conversationId, $content, $timestamp );
				}
			}

			echo "✅ Imported: ID {$conversationId} - {$name} ({$model}, " . count( $messages ) . ' messages, ' . date( 'Y-m-d H:i', $created_at ) . ")\n";
			++$this->imported;

		} catch ( Exception $e ) {
			$name = $data['name'] ?? 'Unknown';
			echo "❌ Error importing {$name}: " . $e->getMessage() . "\n";
			++$this->errors;
		}
	}

	private function extractContent( $message ) {
		// Handle 'text' field (Claude format)
		if ( isset( $message['text'] ) && is_string( $message['text'] ) && ! empty( $message['text'] ) ) {
			return $message['text'];
		}

		// Handle 'content' field
		$content = $message['content'] ?? array();

		if ( is_string( $content ) ) {
			return $content;
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$parts = array();
		foreach ( $content as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = $item['type'] ?? '';

			if ( $type === 'text' && isset( $item['text'] ) ) {
				$parts[] = $item['text'];
			} elseif ( $type === 'code' && isset( $item['text'] ) ) {
				$language = $item['language'] ?? '';
				$parts[] = "```{$language}\n{$item['text']}\n```";
			}
		}

		return implode( "\n\n", $parts );
	}

	private function detectModel( $messages ) {
		// Try to detect model from message metadata (ChatGPT format)
		foreach ( $messages as $msg ) {
			if ( isset( $msg['metadata']['model_slug'] ) ) {
				return $this->normalizeModelName( $msg['metadata']['model_slug'] );
			}
		}

		// Check if this looks like a Claude conversation
		// Claude conversations typically have assistant messages with markdown formatting
		foreach ( $messages as $msg ) {
			if ( ( $msg['sender'] ?? '' ) === 'assistant' ) {
				$content = $this->extractContent( $msg );
				// Claude responses often have markdown formatting like **bold**
				if ( strpos( $content, '**' ) !== false ) {
					return 'claude-3-5-sonnet-20241022';
				}
			}
		}

		// Default to a generic model name
		return 'gpt-4';
	}

	private function normalizeModelName( $modelSlug ) {
		// Map ChatGPT model slugs to standard names
		$mapping = array(
			'gpt-4o' => 'gpt-4o',
			'gpt-4' => 'gpt-4',
			'gpt-4-turbo' => 'gpt-4-turbo',
			'gpt-3.5-turbo' => 'gpt-3.5-turbo',
			'text-davinci-002-render-sha' => 'gpt-3.5-turbo',
			'text-davinci-002-render' => 'gpt-3.5-turbo',
		);

		foreach ( $mapping as $pattern => $model ) {
			if ( stripos( $modelSlug, $pattern ) !== false ) {
				return $model;
			}
		}

		return $modelSlug;
	}

	private function printSummary() {
		echo "\n" . str_repeat( '=', 50 ) . "\n";
		echo "Import Summary:\n";
		echo "✅ Imported: {$this->imported}\n";
		echo "⏭️  Skipped: {$this->skipped}\n";
		echo "❌ Errors: {$this->errors}\n";
		echo str_repeat( '=', 50 ) . "\n";
	}
}

// CLI interface
if ( php_sapi_name() === 'cli' ) {
	$options = getopt( 'hdm:s:y', array( 'help', 'dry-run', 'model:', 'sqlite:', 'yes' ) );

	if ( isset( $options['h'] ) || isset( $options['help'] ) || $argc < 2 ) {
		echo "Usage: php import-chatgpt-json.php [options] <json_file>\n";
		echo "\nImport conversation exports from ChatGPT, Claude, or other AI assistants.\n";
		echo "\nOptions:\n";
		echo "  -h, --help          Show this help message\n";
		echo "  -d, --dry-run       Perform a dry run without importing\n";
		echo "  -m, --model MODEL   Specify model to use (default: auto-detect)\n";
		echo "  -s, --sqlite FILE   SQLite database path (default: chats.sqlite)\n";
		echo "  -y, --yes           Skip confirmation prompts (non-interactive)\n";
		echo "\nArguments:\n";
		echo "  json_file           Path to conversation JSON export file\n";
		echo "\nExamples:\n";
		echo "  php import-chatgpt-json.php conversation.json\n";
		echo "  php import-chatgpt-json.php --dry-run ~/Downloads/chat-export.json\n";
		echo "  php import-chatgpt-json.php -m claude-3-5-sonnet-20241022 conversation.json\n";
		echo "  php import-chatgpt-json.php -y -s custom.sqlite conversation.json\n";
		exit( 0 );
	}

	// Get the JSON file path (last argument)
	$jsonFile = $argv[ count( $argv ) - 1 ];

	$sqliteDb = isset( $options['s'] ) ? $options['s'] : ( isset( $options['sqlite'] ) ? $options['sqlite'] : __DIR__ . '/chats.sqlite' );
	$dryRun = isset( $options['d'] ) || isset( $options['dry-run'] );
	$defaultModel = isset( $options['m'] ) ? $options['m'] : ( isset( $options['model'] ) ? $options['model'] : null );
	$interactive = ! ( isset( $options['y'] ) || isset( $options['yes'] ) );

	try {
		$importer = new ExternalConversationImporter( $sqliteDb, $defaultModel );
		$importer->importFile( $jsonFile, $dryRun, $interactive );
	} catch ( Exception $e ) {
		echo 'Error: ' . $e->getMessage() . "\n";
		exit( 1 );
	}
}
