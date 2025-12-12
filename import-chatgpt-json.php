<?php
require_once __DIR__ . '/includes/LogStorage.php';

class ExternalConversationImporter {
	private $sqliteStorage;
	private $imported = 0;
	private $skipped = 0;
	private $errors = 0;
	private $defaultModel = null;
	private $lastUsedModel = null;

	public function __construct( $sqliteDbPath, $defaultModel = null ) {
		if ( ! file_exists( $sqliteDbPath ) ) {
			throw new Exception( "SQLite database not found: {$sqliteDbPath}" );
		}
		$this->sqliteStorage = new SQLiteLogStorage( $sqliteDbPath );
		$this->defaultModel = $defaultModel;
	}

	public function importFile( $jsonFilePath, $dryRun = false, $interactive = true, $showSummary = true ) {
		if ( ! file_exists( $jsonFilePath ) ) {
			throw new Exception( "JSON file not found: {$jsonFilePath}" );
		}

		$jsonContent = file_get_contents( $jsonFilePath );
		$data = json_decode( $jsonContent, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( 'Invalid JSON: ' . json_last_error_msg() );
		}

		// Check if this is an array of conversations or a single conversation
		if ( $this->isMultipleConversations( $data ) ) {
			echo 'Found ' . count( $data ) . " conversations to import.\n\n";
			foreach ( $data as $index => $conversation ) {
				if ( ! isset( $conversation['chat_messages'] ) || ! is_array( $conversation['chat_messages'] ) ) {
					$name = $conversation['name'] ?? "Conversation #" . ( $index + 1 );
					echo "⚠️  Skipping {$name}: missing chat_messages\n";
					++$this->skipped;
					continue;
				}
				$this->importConversation( $conversation, $dryRun, $interactive );
			}
		} else {
			if ( ! isset( $data['chat_messages'] ) || ! is_array( $data['chat_messages'] ) ) {
				throw new Exception( 'Invalid conversation export format: missing chat_messages' );
			}
			$this->importConversation( $data, $dryRun, $interactive );
		}

		if ( $showSummary ) {
			$this->printSummary();
		}
	}

	private function isMultipleConversations( $data ) {
		// Check if data is a sequential array (list) of conversations
		if ( ! is_array( $data ) ) {
			return false;
		}

		// If it has chat_messages at the top level, it's a single conversation
		if ( isset( $data['chat_messages'] ) ) {
			return false;
		}

		// Check if it's a sequential array (numeric keys starting from 0)
		if ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
			return false;
		}

		// Check if at least the first item looks like a conversation
		if ( ! empty( $data ) && is_array( $data[0] ) ) {
			return isset( $data[0]['chat_messages'] ) || isset( $data[0]['name'] );
		}

		return false;
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

			// Check if this conversation already exists
			$existingConversationId = $this->findExistingConversation( $messages, $created_at );

			if ( $existingConversationId ) {
				$this->appendToConversation( $existingConversationId, $name, $messages, $dryRun );
				return;
			}

			// Determine model: command-line > last used > auto-detect > claude-opus-4-5
			$detectedModel = $this->detectModel( $messages );
			$suggestedModel = $this->defaultModel ?? $this->lastUsedModel ?? $detectedModel ?? 'claude-opus-4-5';

			// In interactive mode, ask user to confirm/change model
			if ( $interactive && ! $dryRun && php_sapi_name() === 'cli' ) {
				echo "\nConversation: {$name}\n";
				echo 'Messages: ' . count( $messages ) . "\n";
				if ( $detectedModel ) {
					echo "Detected model: {$detectedModel}\n";
				}
				echo "Enter model name to use (or press Enter to use '{$suggestedModel}'): ";
				$input = trim( fgets( STDIN ) );
				$model = ! empty( $input ) ? $input : $suggestedModel;
				$this->lastUsedModel = $model;
				echo "\n";
			} else {
				$model = $suggestedModel;
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

	private function findExistingConversation( $messages, $created_at ) {
		if ( empty( $messages ) ) {
			return null;
		}

		$firstUserMessage = null;
		foreach ( $messages as $msg ) {
			if ( ( $msg['sender'] ?? '' ) === 'human' ) {
				$firstUserMessage = $this->extractContent( $msg );
				break;
			}
		}

		if ( ! $firstUserMessage ) {
			return null;
		}

		$db = $this->sqliteStorage->getDatabase();
		$stmt = $db->prepare(
			"
			SELECT c.id, COUNT(m.id) as message_count
			FROM conversations c
			INNER JOIN messages m ON c.id = m.conversation_id
			WHERE c.created_at = ?
			AND EXISTS (
				SELECT 1 FROM messages
				WHERE conversation_id = c.id
				AND role = 'user'
				AND content = ?
				LIMIT 1
			)
			GROUP BY c.id
			ORDER BY c.created_at DESC
			LIMIT 1
			"
		);
		$stmt->execute( array( $created_at, $firstUserMessage ) );
		$result = $stmt->fetch( PDO::FETCH_ASSOC );

		if ( $result ) {
			return array(
				'id' => $result['id'],
				'message_count' => $result['message_count'],
			);
		}

		return null;
	}

	private function appendToConversation( $existingConversation, $name, $messages, $dryRun ) {
		$conversationId = $existingConversation['id'];
		$existingMessageCount = $existingConversation['message_count'];
		$newMessageCount = count( $messages );

		if ( $newMessageCount <= $existingMessageCount ) {
			echo "⏭️  Skipping: ID {$conversationId} - {$name} (no new messages: {$existingMessageCount} existing, {$newMessageCount} in import)\n";
			++$this->skipped;
			return;
		}

		$messagesToAppend = $newMessageCount - $existingMessageCount;

		if ( $dryRun ) {
			echo "📄 Would append {$messagesToAppend} messages to: ID {$conversationId} - {$name} ({$existingMessageCount} existing → {$newMessageCount} total)\n";
			++$this->imported;
			return;
		}

		$appendedCount = 0;
		for ( $i = $existingMessageCount; $i < $newMessageCount; $i++ ) {
			$msg = $messages[ $i ];
			$sender = $msg['sender'] ?? 'unknown';
			$content = $this->extractContent( $msg );
			$timestamp = isset( $msg['created_at'] ) ? strtotime( $msg['created_at'] ) : time();

			if ( empty( $content ) ) {
				continue;
			}

			if ( $sender === 'human' ) {
				$this->sqliteStorage->writeUserMessage( $conversationId, $content, $timestamp );
				++$appendedCount;
			} elseif ( $sender === 'assistant' ) {
				$this->sqliteStorage->writeAssistantMessage( $conversationId, $content, $timestamp );
				++$appendedCount;
			}
		}

		echo "➕ Appended {$appendedCount} messages to: ID {$conversationId} - {$name} ({$existingMessageCount} → " . ( $existingMessageCount + $appendedCount ) . " messages)\n";
		++$this->imported;
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

		return null;
	}

	private function normalizeModelName( $modelSlug ) {
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

	public function printSummary() {
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
		echo "Usage: php import-chatgpt-json.php [options] <json_file> [json_file2 ...]\n";
		echo "\nImport conversation exports from ChatGPT, Claude, or other AI assistants.\n";
		echo "\nOptions:\n";
		echo "  -h, --help          Show this help message\n";
		echo "  -d, --dry-run       Perform a dry run without importing\n";
		echo "  -m, --model MODEL   Specify model to use (default: auto-detect or claude-opus-4-5)\n";
		echo "  -s, --sqlite FILE   SQLite database path (default: chats.sqlite)\n";
		echo "  -y, --yes           Skip confirmation prompts (non-interactive)\n";
		echo "\nArguments:\n";
		echo "  json_file           Path to conversation JSON export file(s)\n";
		echo "\nExamples:\n";
		echo "  php import-chatgpt-json.php conversation.json\n";
		echo "  php import-chatgpt-json.php --dry-run ~/Downloads/chat-export.json\n";
		echo "  php import-chatgpt-json.php -m claude-3-5-sonnet-20241022 conversation.json\n";
		echo "  php import-chatgpt-json.php -y -s custom.sqlite *.json\n";
		exit( 0 );
	}

	// Extract JSON files from arguments (skip script name and options)
	$jsonFiles = array();
	$skipNext = false;
	for ( $i = 1; $i < $argc; $i++ ) {
		$arg = $argv[ $i ];
		if ( $skipNext ) {
			$skipNext = false;
			continue;
		}
		// Skip option flags
		if ( $arg === '-d' || $arg === '--dry-run' || $arg === '-h' || $arg === '--help' || $arg === '-y' || $arg === '--yes' ) {
			continue;
		}
		// Skip option flags with values
		if ( $arg === '-m' || $arg === '--model' || $arg === '-s' || $arg === '--sqlite' ) {
			$skipNext = true;
			continue;
		}
		// Skip combined option=value format
		if ( preg_match( '/^-[ms]=/', $arg ) || preg_match( '/^--(model|sqlite)=/', $arg ) ) {
			continue;
		}
		// This should be a JSON file
		$jsonFiles[] = $arg;
	}

	if ( empty( $jsonFiles ) ) {
		echo "Error: No JSON files specified.\n";
		exit( 1 );
	}

	$sqliteDb = isset( $options['s'] ) ? $options['s'] : ( isset( $options['sqlite'] ) ? $options['sqlite'] : __DIR__ . '/chats.sqlite' );
	$dryRun = isset( $options['d'] ) || isset( $options['dry-run'] );
	$defaultModel = isset( $options['m'] ) ? $options['m'] : ( isset( $options['model'] ) ? $options['model'] : null );
	$interactive = ! ( isset( $options['y'] ) || isset( $options['yes'] ) );

	try {
		$importer = new ExternalConversationImporter( $sqliteDb, $defaultModel );
		$multipleFiles = count( $jsonFiles ) > 1;
		foreach ( $jsonFiles as $jsonFile ) {
			if ( $multipleFiles ) {
				echo "\n📁 Processing: {$jsonFile}\n";
				echo str_repeat( '-', 50 ) . "\n";
			}
			$importer->importFile( $jsonFile, $dryRun, $interactive, ! $multipleFiles );
		}
		if ( $multipleFiles ) {
			$importer->printSummary();
		}
	} catch ( Exception $e ) {
		echo 'Error: ' . $e->getMessage() . "\n";
		exit( 1 );
	}
}
