<?php
require_once __DIR__ . '/includes/LogStorage.php';
require_once __DIR__ . '/includes/Parsedown.php';
require_once __DIR__ . '/includes/ParsedownMath.php';
require_once __DIR__ . '/includes/ApiClient.php';

$dbPath = __DIR__ . '/chats.sqlite';
if ( ! file_exists( $dbPath ) ) {
	die( "Database file not found: $dbPath" );
}

$storage = new SQLiteLogStorage( $dbPath );
$parsedown = new ParsedownMath();
$apiClient = new ApiClient( $storage );

$action = $_GET['action'] ?? 'list';
$conversationId = $_GET['id'] ?? null;
$search = $_GET['search'] ?? null;
$selectedTag = $_GET['tag'] ?? null;
$systemPromptId = $_GET['prompt_id'] ?? null;

// Handle AJAX request for loading more conversations
if ( isset( $_GET['ajax'] ) && $_GET['ajax'] === 'load_more' ) {
	header( 'Content-Type: application/json' );

	$offset = intval( $_GET['offset'] ?? 0 );
	$limit = 10;
	$search = $_GET['search'] ?? null;
	$selectedTag = $_GET['tag'] ?? null;

	$conversations = $storage->findConversations( $limit, $search, $selectedTag, $offset );
	$html = '';

	foreach ( $conversations as $id ) {
		$html .= renderConversationItem( $storage, $id );
	}

echo json_encode(
    array(
    'html'    => $html,
    'hasMore' => count( $conversations ) === $limit,
    )
);
	exit;
}

// Handle API configuration request (provides keys for direct JavaScript API calls)
if ( isset( $_GET['api'] ) && $_GET['api'] === 'config' ) {
	header( 'Content-Type: application/json' );

	// Get supported models including Ollama
	$supportedModels = $apiClient->getSupportedModels();

	// Add Ollama models if available
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/api/tags' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 2 );
	$ollamaModels = json_decode( curl_exec( $ch ), true );
	curl_close( $ch );

	if ( isset( $ollamaModels['models'] ) ) {
		foreach ( $ollamaModels['models'] as $m ) {
			$supportedModels[ $m['name'] ] = 'Ollama';
		}
	}

	$config = array(
		'openai_key' => getenv( 'OPENAI_API_KEY', true ) ?: null,
		'anthropic_key' => getenv( 'ANTHROPIC_API_KEY', true ) ?: null,
		'supported_models' => $supportedModels,
	);

	echo json_encode( $config );
	exit;
}

// Handle storing user message
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_GET['api'] ) && $_GET['api'] === 'store_user_message' ) {
	header( 'Content-Type: application/json' );

	$input = json_decode( file_get_contents( 'php://input' ), true );

	if ( ! $input || ! isset( $input['conversationId'] ) || ! isset( $input['message'] ) ) {
		echo json_encode( array( 'error' => 'Invalid request' ) );
		exit;
	}

	$conversationId = $input['conversationId'];
	$message = $input['message'];

	$storage->writeUserMessage( $conversationId, $message );
	echo json_encode( array( 'success' => true ) );
	exit;
}

// Handle storing assistant message and cost data
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_GET['api'] ) && $_GET['api'] === 'store_assistant_message' ) {
	header( 'Content-Type: application/json' );

	$input = json_decode( file_get_contents( 'php://input' ), true );

	if ( ! $input || ! isset( $input['conversationId'] ) || ! isset( $input['message'] ) ) {
		echo json_encode( array( 'error' => 'Invalid request' ) );
		exit;
	}

	$conversationId = $input['conversationId'];
	$message = $input['message'];
	$model = $input['model'] ?? '';
	$usage = $input['usage'] ?? array();
	$thinking = $input['thinking'] ?? null;

	// Store the message
	$storage->writeAssistantMessage( $conversationId, $message, null, $thinking );

	// Calculate and store cost if usage data is provided
	if ( ! empty( $usage ) && $model ) {
		$inputTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0;
		$outputTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
		$cacheReadTokens = $usage['cache_read_input_tokens'] ?? 0;
		$cacheWriteTokens = $usage['cache_creation_input_tokens'] ?? 0;

		$cost = $apiClient->calculateCost( $model, $inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens );
		if ( $cost > 0 ) {
			$storage->storeCostData( $conversationId, $cost, $inputTokens, $outputTokens );
		}
	}

	echo json_encode( array( 'success' => true ) );
	exit;
}

// Handle fork conversation request
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_GET['api'] ) && $_GET['api'] === 'fork_conversation' ) {
	header( 'Content-Type: application/json' );

	$input = json_decode( file_get_contents( 'php://input' ), true );

	if ( ! $input || ! isset( $input['conversationId'] ) || ! isset( $input['messageIndex'] ) ) {
		http_response_code( 400 );
		echo json_encode( array( 'error' => 'Missing required parameters' ) );
		exit;
	}

	$sourceConversationId = $input['conversationId'];
	$messageIndex = intval( $input['messageIndex'] );

	try {
		// Load the source conversation
		$sourceConversation = $storage->loadConversation( $sourceConversationId );
		if ( ! $sourceConversation ) {
			http_response_code( 404 );
			echo json_encode( array( 'error' => 'Source conversation not found' ) );
			exit;
		}

		// Create new conversation with same model
		$metadata = $storage->getConversationMetadata( $sourceConversationId );
		$model = $metadata['model'] ?? 'gpt-4';
		$newConversationId = $storage->initializeConversation( null, $model ); // initializeConversation returns the actual ID

		// Copy messages up to and including the specified index
		$messageCount = 0;
		$copiedCount = 0;

		foreach ( $sourceConversation as $message ) {
			if ( $messageCount > $messageIndex ) {
				break;
			}

			switch ( $message['role'] ) {
				case 'system':
					$storage->writeSystemPrompt( $newConversationId, $message['content'] );
					$copiedCount++;
					break;
				case 'user':
					$storage->writeUserMessage( $newConversationId, $message['content'], $message['timestamp'] ?? null );
					$copiedCount++;
					break;
				case 'assistant':
					$storage->writeAssistantMessage( $newConversationId, $message['content'], $message['timestamp'] ?? null );
					$copiedCount++;
					break;
			}
			$messageCount++;
		}

		echo json_encode( array(
			'success' => true,
			'newConversationId' => $newConversationId,
			'messagesCopied' => $copiedCount,
			'totalMessages' => count( $sourceConversation ),
			'requestedIndex' => $messageIndex
		) );
	} catch ( Exception $e ) {
		http_response_code( 500 );
		echo json_encode( array( 'error' => 'Failed to fork conversation: ' . $e->getMessage() ) );
	}

	exit;
}

// Handle tag management actions
if ( $_POST['tag_action'] ?? null ) {
	$tagAction = $_POST['tag_action'];
	$targetConversationId = $_POST['conversation_id'] ?? null;

	if ( $targetConversationId ) {
		switch ( $tagAction ) {
			case 'add':
				$newTag = trim( $_POST['new_tag'] ?? '' );
				if ( $newTag ) {
					$storage->addTagToConversation( $targetConversationId, $newTag );
				}
				break;

			case 'remove':
				$tagToRemove = $_POST['tag_to_remove'] ?? '';
				if ( $tagToRemove ) {
					$storage->removeTagFromConversation( $targetConversationId, $tagToRemove );
				}
				break;

			case 'set':
				$newTags = array_filter( array_map( 'trim', explode( ' ', $_POST['tags'] ?? '' ) ) );
				$storage->setConversationTags( $targetConversationId, $newTags );
				break;
		}

		// Redirect to prevent form resubmission
		header( 'Location: ?action=view&id=' . urlencode( $targetConversationId ) );
		exit;
	}
}

// Handle delete conversation action
if ( $_POST['delete_action'] ?? null ) {
	$deleteAction = $_POST['delete_action'];
	$targetConversationId = $_POST['conversation_id'] ?? null;

	if ( $deleteAction === 'delete' && $targetConversationId ) {
		if ( $storage->deleteConversation( $targetConversationId ) ) {
			// Redirect to conversation list
			header( 'Location: ?action=list' );
			exit;
		} else {
			$deleteError = 'Failed to delete conversation.';
		}
	}
}

// Handle system prompt management actions
if ( $_POST['prompt_action'] ?? null ) {
	$promptAction = $_POST['prompt_action'];

	switch ( $promptAction ) {
		case 'create':
			$name = trim( $_POST['prompt_name'] ?? '' );
			$prompt = trim( $_POST['prompt_content'] ?? '' );
			$description = trim( $_POST['prompt_description'] ?? '' );
			$isDefault = isset( $_POST['is_default'] );

			if ( $name && $prompt ) {
				$result = $storage->createSystemPrompt( $name, $prompt, $description, $isDefault );
				if ( $result ) {
					header( 'Location: ?action=system_prompts&success=created' );
					exit;
				} else {
					$promptError = 'Failed to create system prompt. Name might already exist.';
				}
			} else {
				$promptError = 'Name and prompt content are required.';
			}
			break;

		case 'update':
			$id = intval( $_POST['prompt_id'] ?? 0 );
			$name = trim( $_POST['prompt_name'] ?? '' );
			$prompt = trim( $_POST['prompt_content'] ?? '' );
			$description = trim( $_POST['prompt_description'] ?? '' );
			$isDefault = isset( $_POST['is_default'] );

			if ( $id && $name && $prompt ) {
				$result = $storage->updateSystemPrompt( $id, $name, $prompt, $description, $isDefault );
				if ( $result ) {
					header( 'Location: ?action=system_prompts&success=updated' );
					exit;
				} else {
					$promptError = 'Failed to update system prompt. Name might already exist.';
				}
			} else {
				$promptError = 'All fields are required.';
			}
			break;

		case 'delete':
			$id = intval( $_POST['prompt_id'] ?? 0 );
			if ( $id ) {
				if ( $storage->deleteSystemPrompt( $id ) ) {
					header( 'Location: ?action=system_prompts&success=deleted' );
					exit;
				} else {
					$promptError = 'Failed to delete system prompt.';
				}
			}
			break;

		case 'set_default':
			$id = intval( $_POST['prompt_id'] ?? 0 );
			if ( $id ) {
				if ( $storage->setDefaultSystemPrompt( $id ) ) {
					header( 'Location: ?action=system_prompts&success=default_set' );
					exit;
				} else {
					$promptError = 'Failed to set default system prompt.';
				}
			}
			break;
	}
}

function renderConversationItem( $storage, $id ) {
	$metadata = $storage->getConversationMetadata( $id );
	if ( ! $metadata ) {
		return '';
	}

	$messages = $storage->loadConversation( $id );
	$firstMessage = '';
	if ( $messages && ! empty( $messages ) ) {
		// Skip system message if it's the first message, show the first user message instead
		$firstUserMessage = null;
		foreach ( $messages as $msg ) {
			$content = is_array( $msg ) ? $msg['content'] : $msg;
			$role = is_array( $msg ) ? $msg['role'] : 'unknown';

			if ( $role === 'user' ) {
				$firstUserMessage = $content;
				break;
			}
		}
		$firstMessage = $firstUserMessage ?: '';
	}
	$preview = strlen( $firstMessage ) > 100 ? substr( $firstMessage, 0, 100 ) . '...' : $firstMessage;

	$html = '<li class="conversation-item">';
	$html .= '<h3><a href="?action=view&id=' . $id . '" style="text-decoration: none; color: inherit;">Conversation #' . htmlspecialchars( $id ) . '</a></h3>';
	$html .= '<div class="conversation-meta">';
	$html .= '<span><strong>Model:</strong> ' . htmlspecialchars( $metadata['model'] ) . '</span>';
	$html .= '<span><strong>Created:</strong> ' . date( 'M j, Y g:i A', $metadata['timestamp'] ) . '</span>';
	$html .= '<span><strong>Answers:</strong> ' . $metadata['answers'] . '</span>';
	$html .= '<span><strong>Words:</strong> ~' . number_format( $metadata['word_count'] ) . '</span>';
	if ( $metadata['cost'] > 0 ) {
		$html .= '<span><strong>Cost:</strong> $' . number_format( $metadata['cost'], 4 ) . '</span>';
	}
	$html .= '</div>';
	$html .= '<p>' . htmlspecialchars( $preview ) . '</p>';

	if ( $metadata['tags'] ) {
		$html .= '<div class="tags">';
		foreach ( explode( ',', $metadata['tags'] ) as $tag ) {
			$trimmedTag = trim( $tag );
			$isSystemTag = ( strpos( $trimmedTag, 'system' ) === 0 );
			$tagClass = $isSystemTag ? 'tag system-tag' : 'tag';
			$html .= '<a href="?action=list&tag=' . urlencode( $trimmedTag ) . '" class="' . $tagClass . '">' . htmlspecialchars( $trimmedTag ) . '</a>';
		}
		$html .= '</div>';
	}

	$html .= '<a href="?action=view&id=' . $id . '" class="conversation-link">View Conversation</a>';
	$html .= '</li>';

	return $html;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>cll Web Interface</title>
	<link rel="stylesheet" href="katex/katex.min.css">
	<script defer src="katex/katex.min.js"></script>
	<script defer src="katex/auto-render.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
	<style>
		body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
		.container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
		.header {
			border-bottom: 2px solid #eee;
			padding-bottom: 15px;
			margin-bottom: 20px;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		.header h1 {
			margin: 0;
		}
		.header h1 a {
			color: #333;
			text-decoration: none;
		}
		.header h1 a:hover {
			color: #007cba;
		}
		.search-form { margin-bottom: 20px; }
		.search-form input { padding: 8px; width: 300px; border: 1px solid #ddd; border-radius: 4px; }
		.search-form button { padding: 8px 15px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer; }
		.conversation-list { list-style: none; padding: 0; }
		.conversation-item { margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
		.conversation-item h3 { margin: 0 0 8px 0; }
		.conversation-item .tags { float: right; }
		.conversation-meta {
			color: #666;
			font-size: 0.9em;
			margin-bottom: 15px;
			line-height: 1.4;
		}
		.conversation-meta span {
			margin-right: 15px;
		}
		.tags { margin-top: 8px; }
		.tag { display: inline-block; background: #e0e0e0; color: #333; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-right: 5px; text-decoration: none; }
		.tag:hover { background: #d0d0d0; }
		.tag.system-tag { background: #ff9800; color: white; }
		.tag.system-tag:hover { background: #f57c00; }
		.tag-editor { background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 15px 0; border: 1px solid #ddd; display: none; }
		.tag-editor.visible { display: block; }
		.toggle-tags-btn {
			background: #007cba;
			color: white;
			border: none;
			padding: 4px 6px;
			border-radius: 4px;
			cursor: pointer;
			font-size: 0.9em;
			margin-bottom: 10px;
			float: right;
		}
		.toggle-tags-btn:hover { background: #005a8b; }
		.delete-btn {
			color: white;
			border: none;
			padding: 4px 6px;
			border-radius: 4px;
			cursor: pointer;
			font-size: 0.9em;
			margin-bottom: 10px;
			margin-right: .5em;
			float: right;
		}
		.delete-btn:hover { background: #c82333; }
		.tag-editor h4 { margin: 0 0 10px 0; }
		.tag-list { margin: 10px 0; }
		.editable-tag { display: inline-block; background: #e0e0e0; color: #333; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-right: 5px; margin-bottom: 5px; }
		.remove-tag-btn { margin-left: 5px; color: #666; cursor: pointer; font-weight: bold; }
		.remove-tag-btn:hover { color: #d32f2f; }
		.tag-form { margin: 10px 0; }
		.tag-form input[type="text"] { padding: 6px; border: 1px solid #ddd; border-radius: 3px; margin-right: 5px; }
		.tag-form button { padding: 6px 10px; background: #007cba; color: white; border: none; border-radius: 3px; cursor: pointer; }
		.tag-form button:hover { background: #005a8b; }
		.conversation-link { display: inline-block; padding: 6px 12px; background: #007cba; color: white; text-decoration: none; border-radius: 4px; }
		.message { margin-bottom: 20px; padding: 15px; border-radius: 6px; }
		.message.user { background: #e3f2fd; border-left: 4px solid #2196f3; }
		.message.user .message-content pre { max-height: 10em; overflow: auto; }
		.message.assistant { background: #f3e5f5; border-left: 4px solid #9c27b0; }
		.message.system { background: #fff3e0; border-left: 4px solid #ff9800; }
        .message.system .message-toggle { font-size: 0.8em; color: #666; }
        .message.system .message-toggle::before { content: '(click to expand)'; }
        .message.system.show .message-toggle::before { content: '(click to hide)'; }
        .message.system .message-content { display: none; }
        button.display-markdown { padding: 2px 6px; background: #666; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 0.7em; }
        .branch-zone {
            position: relative;
            cursor: pointer;
            margin: 10px 0;
            padding: 0;
            height: 20px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .branch-zone::before {
            content: "💡 Continue in a different way";
            background: white;
            color: #666;
            border: 1px solid #ddd;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            white-space: nowrap;
            opacity: 0.3;
            transition: all 0.2s ease;
        }
        .branch-zone:hover {
            height: 30px;
            margin: 15px 0;
        }
        .branch-zone:hover::before {
            opacity: 1;
            padding: 6px 12px;
            font-size: 0.8em;
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        .branch-interface {
            background: #f8f9fa;
            border: 2px solid #4CAF50;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            display: none;
        }
        .branch-interface.active {
            display: block;
        }
        .branch-textarea {
            width: 100%;
            min-height: 80px;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
        }
        .branch-buttons {
            margin-top: 10px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .branch-submit {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .branch-submit:hover {
            background: #45a049;
        }
        .branch-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        }
        .branch-cancel:hover {
            background: #5a6268;
        }
        .message.system button.display-markdown { display: none; }
        .message.system.show button.display-markdown { display: block; }
		.thinking-container {
			background: #fff9e6;
			border: 1px solid #f0e6cc;
			border-radius: 6px;
			margin: 15px 0;
			overflow: hidden;
			transition: all 0.3s ease;
		}
		.thinking-header {
			padding: 10px 15px;
			background: #fef6e6;
			border-bottom: 1px solid #f0e6cc;
			cursor: pointer;
			display: flex;
			justify-content: space-between;
			align-items: center;
			font-weight: 500;
			color: #856404;
		}
		.thinking-header:hover {
			background: #fdecc8;
		}
		.thinking-icon {
			transition: transform 0.3s ease;
		}
		.thinking-container.collapsed .thinking-icon {
			transform: rotate(-90deg);
		}
		.thinking-content {
			padding: 15px;
			color: #856404;
			font-size: 0.9em;
			line-height: 1.6;
			white-space: pre-wrap;
			max-height: 400px;
			overflow-y: auto;
		}
		.thinking-container.collapsed .thinking-content {
			display: none;
		}
		.thinking-spinner {
			display: inline-block;
			animation: spin 1s linear infinite;
			margin-left: 8px;
		}
		@keyframes spin {
			0% { content: '⠋'; }
			10% { content: '⠙'; }
			20% { content: '⠹'; }
			30% { content: '⠸'; }
			40% { content: '⠼'; }
			50% { content: '⠴'; }
			60% { content: '⠦'; }
			70% { content: '⠧'; }
			80% { content: '⠇'; }
			90% { content: '⠏'; }
			100% { content: '⠋'; }
		}
		.message-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
		.message-role { font-weight: bold; text-transform: capitalize; }
		.message-timestamp { font-size: 0.8em; color: #666; }
		.message-content { line-height: 1.5; }
		.message-content p { margin: 0.5em 0; }
		.message-content p:first-child { margin-top: 0; }
		.message-content p:last-child { margin-bottom: 0; }
		.message-content pre { background: #f4f4f4; padding: 10px; border-radius: 4px; overflow-x: auto; margin: 1em 0; }
		.message-content code { background: #f4f4f4; padding: 2px 4px; border-radius: 3px; font-family: monospace; }
		.message-content pre code { background: none; padding: 0; }
		.message-content h1, .message-content h2, .message-content h3 { margin: 15px 0 10px 0; }
		.message-content ul { margin: 10px 0; padding-left: 20px; }
		.message-content li { margin: 5px 0; }

		/* Enhanced table styling for assistant messages */
		.message.assistant .message-content table {
			border-collapse: collapse;
			width: 100%;
			margin: 15px 0;
			background: white;
			border-radius: 6px;
			overflow: hidden;
			box-shadow: 0 2px 4px rgba(0,0,0,0.1);
		}
		.message.assistant .message-content th,
		.message.assistant .message-content td {
			border: 1px solid #e0e0e0;
			padding: 12px 15px;
			text-align: left;
			vertical-align: top;
		}
		.message.assistant .message-content th {
			background: linear-gradient(135deg, #9c27b0, #ba68c8);
			color: white;
			font-weight: 600;
			text-transform: uppercase;
			font-size: 0.85em;
			letter-spacing: 0.5px;
		}
		.message.assistant .message-content tr:nth-child(even) {
			background: #fafafa;
		}
		.message.assistant .message-content tr:hover {
			background: #f0f0f0;
			transition: background-color 0.2s ease;
		}
		.message.assistant .message-content td code {
			background: #f8f9fa;
			border: 1px solid #e9ecef;
		}
		.back-link { display: inline-block; margin-bottom: 20px; padding: 8px 15px; background: #666; color: white; text-decoration: none; border-radius: 4px; }
		.nav { margin: 0; }
		.nav a { margin-left: 20px; color: #007cba; text-decoration: none; font-weight: 500; }
		.nav a:hover { text-decoration: underline; }

		/* Loading indicator */
		.loading {
			text-align: center;
			padding: 20px;
			color: #666;
			font-style: italic;
		}
		.loading.hidden {
			display: none;
		}

		/* Bar Chart Styles */
		.chart-container { margin-bottom: 30px; }
		.chart-title { margin-bottom: 15px; font-weight: bold; }
		.chart-wrapper { overflow-x: auto; padding-bottom: 10px; }
		.chart { display: flex; align-items: flex-end; min-width: 100%; height: 220px; border-bottom: 2px solid #ddd; border-left: 2px solid #ddd; padding: 10px 0 0 10px; }
		.chart-bar { display: flex; flex-direction: column; align-items: center; margin-right: 8px; min-width: 60px; }
		.chart-bar-inner { background: linear-gradient(to top, #007cba, #4db8e8); border-radius: 4px 4px 0 0; width: 40px; transition: all 0.3s ease; min-height: 2px; }
		.chart-bar-inner:hover { background: linear-gradient(to top, #005a8b, #007cba); transform: scaleY(1.05); }
		.chart-bar-label { font-size: 0.8em; color: #666; margin-top: 8px; text-align: center; word-wrap: break-word; max-width: 60px; overflow: hidden; text-overflow: clip; height: 30px; margin-bottom: 10px; }
		.chart-bar-value { font-size: 0.7em; color: #333; margin-top: 4px; font-weight: bold; }
		.chart-scrollable { min-width: 600px; }
		.chart-models .chart-bar { min-width: 80px; }
		.chart-models .chart-bar-label { max-width: 80px; }

		/* Tag Cloud Styles */
		.tag-cloud {
			text-align: center;
			line-height: 1.8;
			padding: 20px;
			background: #f8f9fa;
			border-radius: 8px;
		}
		.tag-cloud-item {
			display: inline-block;
			margin: 5px 8px;
			padding: 4px 8px;
			border-radius: 12px;
			background: #007cba;
			color: white;
			text-decoration: none;
			transition: all 0.3s ease;
			font-weight: 500;
		}
		.tag-cloud-item:hover {
			background: #005a8b;
			transform: scale(1.1);
		}
		.tag-cloud-item.system-tag {
			background: #ff9800;
		}
		.tag-cloud-item.system-tag:hover {
			background: #f57c00;
		}
		.tag-cloud-size-1 { font-size: 0.8em; opacity: 0.7; }
		.tag-cloud-size-2 { font-size: 0.9em; opacity: 0.8; }
		.tag-cloud-size-3 { font-size: 1.0em; opacity: 0.9; }
		.tag-cloud-size-4 { font-size: 1.2em; opacity: 1.0; }
		.tag-cloud-size-5 { font-size: 1.4em; opacity: 1.0; font-weight: 600; }
		.tag-cloud-size-6 { font-size: 1.6em; opacity: 1.0; font-weight: 700; }
	</style>
</head>
<body>
	<div class="container">
		<div class="header">
			<h1><a href="?action=list">cll Web Interface</a></h1>
			<div class="nav">
				<a href="?action=list">All Conversations</a>
				<a href="?action=stats">Statistics</a>
				<a href="?action=system_prompts">System Prompts</a>
			</div>
		</div>

		<?php if ( $action === 'view' && $conversationId ) : ?>
			<form class="search-form" method="get">
				<input type="hidden" name="action" value="list">
				<input type="text" name="search" placeholder="Search conversations..." value="<?php echo htmlspecialchars( $search ?? '' ); ?>">

				<select name="tag" style="margin-left: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
					<option value="">All Tags</option>
					<?php
					$allTags = $storage->getAllTags();
					foreach ( $allTags as $tag ) :
						?>
						<option value="<?php echo htmlspecialchars( $tag ); ?>" <?php echo $selectedTag === $tag ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars( $tag ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="submit">Filter</button>
				<?php if ( $search || $selectedTag ) : ?>
					<a href="?action=list" style="margin-left: 10px;">Clear All</a>
				<?php endif; ?>
			</form>
			<?php
			$metadata = $storage->getConversationMetadata( $conversationId );
			$messages = $storage->loadConversation( $conversationId );
			$conversationTags = $storage->getConversationTags( $conversationId );

			if ( $metadata && $messages ) :
				?>
				<?php if ( isset( $deleteError ) ) : ?>
					<div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 15px;">
						<?php echo htmlspecialchars( $deleteError ); ?>
					</div>
				<?php endif; ?>
				<h2>Conversation #<?php echo htmlspecialchars( $conversationId ); ?></h2>
				<div class="conversation-meta">
					<span><strong>Model:</strong> <?php echo htmlspecialchars( $metadata['model'] ); ?></span>
					<span><strong>Created:</strong> <?php echo date( 'M j, Y g:i A', $metadata['timestamp'] ); ?></span>
					<span><strong>Messages:</strong> <?php echo count( $messages ); ?></span>
					<span><strong>Answers:</strong> <?php echo $metadata['answers']; ?></span>
					<span><strong>Words:</strong> ~<?php echo number_format( $metadata['word_count'] ); ?></span>
					<?php if ( $metadata['cost'] > 0 ) : ?>
						<span><strong>Cost:</strong> $<?php echo number_format( $metadata['cost'], 4 ); ?></span>
					<?php endif; ?>
					<?php if ( $metadata['input_tokens'] > 0 || $metadata['output_tokens'] > 0 ) : ?>
						<span><strong>Tokens:</strong> <?php echo number_format( $metadata['input_tokens'] ); ?> in, <?php echo number_format( $metadata['output_tokens'] ); ?> out</span>
					<?php endif; ?>
				</div>
				
				<button class="toggle-tags-btn" onclick="toggleTagEditor()">Manage Tags</button>
				<button class="delete-btn" onclick="confirmDelete()" style="background: #dc3545; margin-left: 10px;">Delete Conversation</button>

				<!-- Display Tags -->
				<?php if ( ! empty( $conversationTags ) ) : ?>
					<div style="margin: 15px 0;">
						<strong>Tags:</strong>
						<?php
						foreach ( $conversationTags as $tag ) :
							$isSystemTag = ( strpos( $tag, 'system' ) === 0 );
							$tagClass = $isSystemTag ? 'tag system-tag' : 'tag';
							?>
							<a href="?action=list&tag=<?php echo urlencode( $tag ); ?>" class="<?php echo $tagClass; ?>" style="margin-left: 8px;"><?php echo htmlspecialchars( $tag ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="tag-editor" id="tag-editor">
					<h4>Tags</h4>
					<form class="tag-form" method="post">
						<input type="hidden" name="tag_action" value="set">
						<input type="hidden" name="conversation_id" value="<?php echo htmlspecialchars( $conversationId ); ?>">
						<input type="text" name="tags" placeholder="Enter tags separated by spaces" 
								value="<?php echo htmlspecialchars( implode( ' ', $conversationTags ) ); ?>" style="width: 400px; padding: 8px;">
						<button type="submit" style="padding: 8px 15px; margin-left: 10px;">Save Tags</button>
					</form>
				</div>
				
				
				<!-- Hidden form for deleting conversation -->
				<form id="delete-form" method="post" style="display: none;">
					<input type="hidden" name="delete_action" value="delete">
					<input type="hidden" name="conversation_id" value="<?php echo htmlspecialchars( $conversationId ); ?>">
				</form>

				<script>
				function toggleTagEditor() {
					var editor = document.getElementById('tag-editor');
					editor.classList.toggle('visible');
				}

				function confirmDelete() {
					if (confirm('Are you sure you want to delete this conversation? This action cannot be undone.')) {
						document.getElementById('delete-form').submit();
					}
				}
				</script>
				
				<div class="messages">
					<?php
					$previousRole = null;
					foreach ( $messages as $i => $message ) :
						if ( is_array( $message ) ) {
							$content = $message['content'];
							$timestamp = $message['timestamp'];
							$role = $message['role'];
							$thinking = $message['thinking'] ?? null;
						} else {
							// Fallback for old format
							$content = $message;
							$timestamp = null;
							$role = 'unknown';
							$thinking = null;
						}

						// Add branch zone if previous message was assistant and current is user
						if ( $previousRole === 'assistant' && $role === 'user' ) {
							?>
							<div class="branch-zone" onclick="showBranchInterface(<?php echo $i; ?>)" data-message-index="<?php echo $i; ?>"></div>
							<div id="branch-interface-<?php echo $i; ?>" class="branch-interface">
								<div style="font-weight: bold; margin-bottom: 10px; color: #4CAF50;">💡 Continue the conversation in a different way</div>
								<textarea class="branch-textarea" placeholder="Ask a different question or explore another direction..."></textarea>
								<div class="branch-buttons">
									<button class="branch-cancel" onclick="hideBranchInterface(<?php echo $i; ?>)">Cancel</button>
									<button class="branch-submit" onclick="submitBranch(<?php echo $i; ?>)">Branch & Ask</button>
								</div>
							</div>
							<?php
						}

						// Regular message - no special handling needed
						$displayRole = $role;
						$isSystemMessage = ( $role === 'system' );

						// Display thinking before assistant message if it exists
						if ( $thinking && $role === 'assistant' ) :
							?>
							<div class="thinking-container collapsed">
								<div class="thinking-header" onclick="toggleThinking(this)">
									<span class="thinking-icon">💭</span>
									<span class="thinking-label">Thought Process</span>
								</div>
								<div class="thinking-content"><?php echo htmlspecialchars( trim( $thinking ) ); ?></div>
							</div>
							<?php
						endif;
						?>
						<div class="message <?php echo htmlspecialchars( $displayRole ); ?>">
							<div class="message-header">
								<div class="message-role" <?php echo $isSystemMessage ? 'style="cursor: pointer;" onclick="toggleSystemPrompt(this.parentNode)"' : ''; ?>>
									<?php echo htmlspecialchars( $displayRole ); ?>
									<?php echo $isSystemMessage ? ' <span class="message-toggle"></span>' : ''; ?>
								</div>
								<div style="display: flex; align-items: center; gap: 10px;">
									<button onclick="toggleRawSource(this)" class="display-markdown">View Markdown</button>
									<div class="message-timestamp"><?php echo $timestamp ? date( 'Y-m-d H:i:s', $timestamp ) : ''; ?></div>
								</div>
							</div>
							<div class="message-content"><?php echo $parsedown->text( $content ); ?></div>
							<div class="message-raw" style="display: none; margin-top: 10px;">
								<div style="background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 10px;">
									<div style="font-weight: bold; margin-bottom: 5px; font-size: 0.9em; color: #666;">Raw Message Content:</div>
									<pre style="background: #fff; border: 1px solid #ddd; padding: 8px; border-radius: 3px; font-size: 0.8em; white-space: pre-wrap; word-wrap: break-word; margin: 0;"><?php echo htmlspecialchars( $content ); ?></pre>
								</div>
							</div>
						</div>
						<?php $previousRole = $role; ?>
					<?php endforeach; ?>
				</div>

				<!-- Continue Conversation Section -->
				<?php if ( $conversationId && $metadata ) : ?>
				<div id="continue-conversation" style="margin-top: 30px; padding: 20px; border-top: 2px solid #eee;">
					<h3>Continue this conversation</h3>
					<div id="chat-interface">
						<div style="margin-bottom: 15px; color: #666; font-style: italic;">
							<strong>Continuing with:</strong> <?php echo htmlspecialchars( $metadata['model'] ?? 'Unknown model' ); ?>
						</div>
						<div style="display: flex; gap: 10px; margin-top: 15px;">
							<textarea id="user-input" placeholder="Type your message here..." style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; min-height: 60px;"></textarea>
							<button id="send-message" style="padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer;" disabled>Send</button>
						</div>
						<div id="api-status" style="margin-top: 10px; padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; display: none;"></div>
					</div>
					<div id="api-not-available" style="display: none; padding: 15px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px;">
						<strong>API keys not available.</strong> To continue conversations, please set your API keys:
						<br><br>
						<code>export OPENAI_API_KEY=sk-...</code><br>
						<code>export ANTHROPIC_API_KEY=sk-...</code>
					</div>
				</div>
				<?php endif; ?>

				<script>
				function toggleSystemPrompt(header) {
                    header.closest( 'div.message' ).classList.toggle('show');
					const content = header.nextElementSibling;
					const roleElement = header.querySelector('.message-role');
					
					if (content.style.display === 'block') {
                        content.style.display = 'none';
                        roleElement.innerHTML = roleElement.innerHTML.replace('(click to collapse)', '(click to expand)');
					} else {
                        content.style.display = 'block';
                        roleElement.innerHTML = roleElement.innerHTML.replace('(click to expand)', '(click to collapse)');
					}
				}

				function toggleRawSource(button) {
					const message = button.closest('.message');
					const rawDiv = message.querySelector('.message-raw');
                    const contentDiv = message.querySelector('.message-content');

					if (rawDiv.style.display === 'none') {
						rawDiv.style.display = 'block';
                        contentDiv.style.display = 'none';
						button.textContent = 'Hide Markdown';
					} else {
						rawDiv.style.display = 'none';
                        contentDiv.style.display = 'block';
						button.textContent = 'View Markdown';
					}
				}

				function toggleThinking(header) {
					const container = header.closest('.thinking-container');
					container.classList.toggle('collapsed');
				}
				</script>

				<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
					<div style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px;">
						<strong>Resume this conversation:</strong>
						<code id="resume-command" style="display: inline-block; margin-left: 10px; padding: 4px 8px; background: #ffffff; border: 1px solid #ddd; border-radius: 3px; cursor: pointer; user-select: all;" onclick="copyResumeCommand()"><?php echo 'cll -r ' . htmlspecialchars( $conversationId ); ?></code>
						<span id="copy-feedback" style="margin-left: 8px; color: #28a745; font-size: 0.9em; display: none;">Copied!</span>
					</div>
					<div style="text-align: center;">
						<a href="?action=list" class="back-link">← Back to List</a>
					</div>
				</div>

				<script>
				function copyResumeCommand() {
					const command = document.getElementById('resume-command').textContent;
					navigator.clipboard.writeText(command).then(function() {
						const feedback = document.getElementById('copy-feedback');
						feedback.style.display = 'inline';
						setTimeout(() => {
							feedback.style.display = 'none';
						}, 2000);
					}).catch(function(err) {
						// Fallback for older browsers
						const textArea = document.createElement('textarea');
						textArea.value = command;
						document.body.appendChild(textArea);
						textArea.select();
						try {
							document.execCommand('copy');
							const feedback = document.getElementById('copy-feedback');
							feedback.style.display = 'inline';
							setTimeout(() => {
								feedback.style.display = 'none';
							}, 2000);
						} catch (err) {
							console.error('Could not copy text: ', err);
						}
						document.body.removeChild(textArea);
					});
				}

				// Chat Interface JavaScript
				let chatConfig = null;
				let currentConversationId = <?php echo json_encode( $conversationId ); ?>;
				let currentModel = '<?php echo htmlspecialchars( $metadata['model'] ?? '' ); ?>';
				let conversationMessages = [];
				let systemPrompt = null;

				// Extract system prompt from conversation data
				<?php
				$hasSystemPrompt = false;
				if ( $messages && ! empty( $messages ) ) {
					$firstMessage = $messages[0];
					if ( is_array( $firstMessage ) && isset( $firstMessage['role'] ) && $firstMessage['role'] === 'system' ) {
						$hasSystemPrompt = true;
						echo 'systemPrompt = ' . json_encode( $firstMessage['content'] ) . ';';
					}
				}
				?>

				// Load conversation messages for API context
				<?php
				$apiMessages = array();
				if ( $messages ) {
					foreach ( $messages as $message ) {
						if ( is_array( $message ) && isset( $message['role'] ) ) {
							// Skip system messages as they're handled separately
							if ( $message['role'] !== 'system' ) {
								$apiMessages[] = array(
									'role' => $message['role'],
									'content' => $message['content']
								);
							}
						}
					}
				}

				$json = json_encode( $apiMessages, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( $json === false ) {
					echo 'console.error("JSON encoding failed: ' . addslashes( json_last_error_msg() ) . '");';
					echo 'conversationMessages = [];';
				} else {
					echo 'conversationMessages = ' . $json . ';';
				}
				?>

				async function loadApiConfig() {
					try {
						const response = await fetch('?api=config');
						chatConfig = await response.json();

						// Check if we have any supported provider for the current model
						const provider = chatConfig.supported_models[currentModel];
						if (!provider) {
							document.getElementById('api-not-available').style.display = 'block';
							return;
						}

						// Check if we have the required API key for the current model
						if (provider === 'OpenAI' && !chatConfig.openai_key) {
							document.getElementById('api-not-available').style.display = 'block';
							return;
						}
						if (provider === 'Anthropic' && !chatConfig.anthropic_key) {
							document.getElementById('api-not-available').style.display = 'block';
							return;
						}
						// Ollama doesn't need API keys, so it's always available if the model is supported

						// Enable send button when API is ready
						document.getElementById('send-message').disabled = false;
					} catch (error) {
						console.error('Failed to load API config:', error);
						document.getElementById('api-not-available').style.display = 'block';
					}
				}



				async function sendMessage(autoSubmitMode = false) {
					const userInput = document.getElementById('user-input');
					const sendButton = document.getElementById('send-message');
					const statusDiv = document.getElementById('api-status');

					let userMessage;

					if (autoSubmitMode) {
						// In auto-submit mode, we skip input validation and storage since message is already stored
						if (!currentModel) return;
						// User message is already in conversationMessages from autoSubmitLastUserMessage
						userMessage = conversationMessages[conversationMessages.length - 1].content;
					} else {
						// Normal mode - get message from input field
						if (!userInput.value.trim() || !currentModel) return;

						userMessage = userInput.value.trim();
						userInput.value = '';

						// Add user message to conversation
						appendMessage('user', userMessage);

						// Store user message in database
						try {
							await fetch('?api=store_user_message', {
								method: 'POST',
								headers: { 'Content-Type': 'application/json' },
								body: JSON.stringify({
									conversationId: currentConversationId,
									message: userMessage
								})
							});
						} catch (error) {
							console.error('Failed to store user message:', error);
						}

						// Add user message to conversation context
						conversationMessages.push({ role: 'user', content: userMessage });
					}

					sendButton.disabled = true;

					// Prepare API request
					const provider = chatConfig.supported_models[currentModel];
					console.log('Current model:', currentModel, 'Provider:', provider, 'Config:', chatConfig);
					let apiUrl, headers, requestBody;
					let thinkingDiv = null;
					let thinkingContent = '';
					let tagBuffer = '';
					let inThinkingTag = false;

					if (provider === 'OpenAI') {
						apiUrl = 'https://api.openai.com/v1/chat/completions';
						headers = {
							'Content-Type': 'application/json',
							'Authorization': `Bearer ${chatConfig.openai_key}`
						};

						const messages = [...conversationMessages];
						if (systemPrompt) {
							messages.unshift({ role: 'system', content: systemPrompt });
						}

						requestBody = {
							model: currentModel,
							messages: messages,
							stream: true
						};
					} else if (provider === 'Anthropic') {
						apiUrl = 'https://api.anthropic.com/v1/messages';
						headers = {
							'Content-Type': 'application/json',
							'x-api-key': chatConfig.anthropic_key,
							'anthropic-version': '2023-06-01'
						};

						requestBody = {
							model: currentModel,
							messages: conversationMessages,
							max_tokens: 3200,
							stream: true
						};

						if (systemPrompt) {
							requestBody.system = systemPrompt;
						}
					} else if (provider === 'Ollama') {
						apiUrl = 'http://localhost:11434/v1/chat/completions';
						headers = {
							'Content-Type': 'application/json'
						};

						const messages = [...conversationMessages];
						if (systemPrompt) {
							messages.unshift({ role: 'system', content: systemPrompt });
						}

						requestBody = {
							model: currentModel,
							messages: messages,
							stream: true
						};
					} else {
						throw new Error(`Unsupported provider: ${provider}`);
					}

					// Show status
					statusDiv.style.display = 'block';
					statusDiv.innerHTML = `<strong>Sending to ${provider}...</strong>`;

					try {
						// Create assistant message container
						const assistantMessageDiv = appendMessage('assistant', '');
						let assistantMessage = '';
						let usage = null;

						console.log('Making request to:', apiUrl, 'with headers:', headers, 'and body:', requestBody);

						const response = await fetch(apiUrl, {
							method: 'POST',
							headers: headers,
							body: JSON.stringify(requestBody)
						});

						console.log('Response status:', response.status, 'Response headers:', [...response.headers.entries()]);

						if (!response.ok) {
							const errorText = await response.text();
							console.error('API error response:', errorText);
							throw new Error(`API request failed: ${response.status} - ${errorText}`);
						}

						// Check if response is actually streamed
						const contentType = response.headers.get('content-type');
						const transferEncoding = response.headers.get('transfer-encoding');
						console.log('Response content-type:', contentType, 'Transfer-Encoding:', transferEncoding);

						const reader = response.body.getReader();
						const decoder = new TextDecoder();

						statusDiv.innerHTML = `<strong>Receiving response from ${provider}...</strong>`;

						let buffer = '';
						while (true) {
							const { done, value } = await reader.read();
							if (done) break;

							buffer += decoder.decode(value, { stream: true });

							// Handle different response formats based on provider
							if (provider === 'OpenAI' || provider === 'Ollama') {
								// OpenAI/Ollama use Server-Sent Events format
								const lines = buffer.split('\n');
								buffer = lines.pop() || '';

								for (const line of lines) {
									const trimmedLine = line.trim();
									if (!trimmedLine) continue;

									if (trimmedLine.startsWith('data: ')) {
										const data = trimmedLine.slice(6);
										if (data === '[DONE]') continue;

										try {
											const parsed = JSON.parse(data);
											// Check for reasoning content (o1/o3 models)
											if (parsed.choices?.[0]?.delta?.reasoning_content) {
												if (!thinkingDiv) {
													thinkingDiv = createThinkingContainer();
													assistantMessageDiv.parentNode.insertBefore(thinkingDiv, assistantMessageDiv);
												}
												const delta = parsed.choices[0].delta.reasoning_content;
												thinkingContent += delta;
												thinkingDiv.querySelector('.thinking-content').textContent = thinkingContent;
											} else if (parsed.choices?.[0]?.delta?.content) {
												let delta = parsed.choices[0].delta.content;

												// For Ollama, process <think> tags
												if (provider === 'Ollama') {
													const state = {
														tagBuffer: tagBuffer,
														inThinking: inThinkingTag,
														thinkingDiv: thinkingDiv,
														thinkingContent: thinkingContent,
														assistantMessageDiv: assistantMessageDiv
													};
													delta = processThinkTags(delta, state);
													tagBuffer = state.tagBuffer;
													inThinkingTag = state.inThinking;
													thinkingDiv = state.thinkingDiv;
													thinkingContent = state.thinkingContent;
												} else {
													// Close thinking if it was open (for non-Ollama)
													if (thinkingDiv) {
														closeThinkingContainer(thinkingDiv);
														thinkingDiv = null;
													}
												}

												// Only update message if there's content to show
												if (delta) {
													assistantMessage += delta;
													assistantMessageDiv.querySelector('.message-content').innerHTML =
														(typeof marked !== 'undefined') ? marked.parse(assistantMessage) : assistantMessage;
												}
											}
											if (parsed.usage) {
												usage = parsed.usage;
											}
										} catch (e) {
											console.warn('Failed to parse OpenAI/Ollama streaming data:', data, e);
										}
									}
								}
							} else if (provider === 'Anthropic') {
								// Anthropic uses newline-delimited JSON
								const lines = buffer.split('\n');
								buffer = lines.pop() || '';

								for (const line of lines) {
									const trimmedLine = line.trim();
									if (!trimmedLine) continue;

									// Skip Server-Sent Events format if present
									let jsonData = trimmedLine;
									if (trimmedLine.startsWith('data: ')) {
										jsonData = trimmedLine.slice(6);
										if (jsonData === '[DONE]') continue;
									}

									try {
										const parsed = JSON.parse(jsonData);
										// Handle thinking content blocks
										if (parsed.type === 'content_block_start' && parsed.content_block?.type === 'thinking') {
											if (!thinkingDiv) {
												thinkingDiv = createThinkingContainer();
												assistantMessageDiv.parentNode.insertBefore(thinkingDiv, assistantMessageDiv);
											}
										} else if (parsed.type === 'content_block_delta' && parsed.delta?.type === 'thinking_delta') {
											const delta = parsed.delta?.thinking || '';
											thinkingContent += delta;
											if (thinkingDiv) {
												thinkingDiv.querySelector('.thinking-content').textContent = thinkingContent;
											}
										} else if (parsed.type === 'content_block_stop' && thinkingDiv) {
											closeThinkingContainer(thinkingDiv);
											thinkingDiv = null;
										} else if (parsed.type === 'content_block_delta') {
											const delta = parsed.delta?.text || '';
											assistantMessage += delta;
											assistantMessageDiv.querySelector('.message-content').innerHTML =
												(typeof marked !== 'undefined') ? marked.parse(assistantMessage) : assistantMessage;
										}
										if (parsed.type === 'message_delta' && parsed.usage) {
											usage = parsed.usage;
										}
									} catch (e) {
										console.warn('Failed to parse Anthropic streaming data:', jsonData, e);
									}
								}
							}
						}

						// Update the raw markdown content now that streaming is complete
						const rawDiv = assistantMessageDiv.querySelector('.message-raw pre');
						if (rawDiv) {
							rawDiv.textContent = assistantMessage;
						}

						// Store assistant message in database
						await fetch('?api=store_assistant_message', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({
								conversationId: currentConversationId,
								message: assistantMessage,
								model: currentModel,
								usage: usage,
								thinking: thinkingContent || null
							})
						});

						// Add to conversation context
						conversationMessages.push({ role: 'assistant', content: assistantMessage });

						statusDiv.innerHTML = `<strong>✓ Response completed</strong>`;
						setTimeout(() => {
							statusDiv.style.display = 'none';
						}, 2000);

					} catch (error) {
						console.error('Chat error:', error);

						// Handle CORS errors that are common with direct API calls
						if (error.message.includes('Failed to fetch') || error.message.includes('CORS')) {
							statusDiv.innerHTML = `<strong style="color: #d32f2f;">Network Error: CORS or connection issue</strong>`;
							appendMessage('system', `Network Error: ${error.message}. This might be due to CORS restrictions when calling APIs directly from the browser. Consider using a CORS proxy or configuring your browser to allow cross-origin requests.`);
						} else {
							statusDiv.innerHTML = `<strong style="color: #d32f2f;">Error: ${error.message}</strong>`;
							appendMessage('system', `Error: ${error.message}`);
						}
					}

					sendButton.disabled = false;
				}

				function processThinkTags(content, state) {
					// Process <think> tags for Ollama models
					state.tagBuffer += content;
					let output = '';

					// Check for <think> opening tag
					if (!state.inThinking && state.tagBuffer.includes('<think>')) {
						const parts = state.tagBuffer.split('<think>', 2);
						output += parts[0]; // Content before <think>
						state.tagBuffer = parts[1] || '';
						state.inThinking = true;

						// Create thinking container if needed
						if (!state.thinkingDiv) {
							state.thinkingDiv = createThinkingContainer();
							state.assistantMessageDiv.parentNode.insertBefore(state.thinkingDiv, state.assistantMessageDiv);
						}
					}

					// Check for </think> closing tag
					if (state.inThinking && state.tagBuffer.includes('</think>')) {
						const parts = state.tagBuffer.split('</think>', 2);
						const thinkingText = parts[0];
						if (state.thinkingDiv) {
							state.thinkingDiv.querySelector('.thinking-content').textContent += thinkingText;
						}
						state.thinkingContent += thinkingText;
						state.inThinking = false;
						state.tagBuffer = parts[1] || '';
						output += state.tagBuffer;
						state.tagBuffer = '';

						// Close thinking container
						if (state.thinkingDiv) {
							closeThinkingContainer(state.thinkingDiv);
						}
					} else if (state.inThinking) {
						// We're in thinking mode, accumulate content
						// Keep last 10 chars in buffer in case of split tag
						if (state.tagBuffer.length > 10) {
							const thinkingText = state.tagBuffer.slice(0, -10);
							if (state.thinkingDiv) {
								state.thinkingDiv.querySelector('.thinking-content').textContent += thinkingText;
							}
							state.thinkingContent += thinkingText;
							state.tagBuffer = state.tagBuffer.slice(-10);
						}
					} else {
						// Not in thinking mode
						// Keep last 10 chars in buffer in case of split <think> tag
						if (state.tagBuffer.length > 10) {
							output += state.tagBuffer.slice(0, -10);
							state.tagBuffer = state.tagBuffer.slice(-10);
						}
					}

					return output;
				}

				function createThinkingContainer() {
					const container = document.createElement('div');
					container.className = 'thinking-container';
					container.innerHTML = `
						<div class="thinking-header" onclick="toggleThinking(this)">
							<span>💭 Thinking...</span>
							<span class="thinking-icon">▼</span>
						</div>
						<div class="thinking-content"></div>
					`;
					return container;
				}

				function closeThinkingContainer(container) {
					const header = container.querySelector('.thinking-header span:first-child');
					header.textContent = '💭 Thinking';
					container.classList.add('collapsed');
				}

				function toggleThinking(header) {
					const container = header.closest('.thinking-container');
					container.classList.toggle('collapsed');
				}

				function appendMessage(role, content) {
					const messagesContainer = document.querySelector('.messages');
					const messageDiv = document.createElement('div');
					messageDiv.className = `message ${role}`;

					const headerDiv = document.createElement('div');
					headerDiv.className = 'message-header';
					const currentTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
					headerDiv.innerHTML = `
						<div class="message-role">${role}</div>
						<div style="display: flex; align-items: center; gap: 10px;">
							<button onclick="toggleRawSource(this)" class="display-markdown">View Markdown</button>
							<div class="message-timestamp">${currentTime}</div>
						</div>
					`;

					const contentDiv = document.createElement('div');
					contentDiv.className = 'message-content';
					contentDiv.innerHTML = content;

					const rawDiv = document.createElement('div');
					rawDiv.className = 'message-raw';
					rawDiv.style.display = 'none';
					rawDiv.style.marginTop = '10px';
					const rawPre = document.createElement('pre');
					rawPre.style.cssText = 'background: #fff; border: 1px solid #ddd; padding: 8px; border-radius: 3px; font-size: 0.8em; white-space: pre-wrap; word-wrap: break-word; margin: 0;';
					rawPre.textContent = content;

					rawDiv.innerHTML = `
						<div style="background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 10px;">
							<div style="font-weight: bold; margin-bottom: 5px; font-size: 0.9em; color: #666;">Raw Message Content:</div>
						</div>
					`;
					rawDiv.querySelector('div').appendChild(rawPre);

					messageDiv.appendChild(headerDiv);
					messageDiv.appendChild(contentDiv);
					messageDiv.appendChild(rawDiv);
					messagesContainer.appendChild(messageDiv);

					// Scroll to keep the input area visible after the new message
					setTimeout(() => {
						const inputArea = document.getElementById('user-input');
						inputArea.scrollIntoView({ behavior: 'smooth', block: 'end' });
					}, 100);

					return messageDiv;
				}

				function showBranchInterface(messageIndex) {
					// Hide any other open branch interfaces
					document.querySelectorAll('.branch-interface.active').forEach(el => {
						el.classList.remove('active');
					});

					// Show the clicked branch interface
					const interface = document.getElementById(`branch-interface-${messageIndex}`);
					if (interface) {
						interface.classList.add('active');
						// Focus on the textarea
						const textarea = interface.querySelector('.branch-textarea');
						if (textarea) {
							textarea.focus();

							// Add Enter key listener for this textarea
							textarea.addEventListener('keydown', function(e) {
								if (e.key === 'Enter' && !e.shiftKey) {
									e.preventDefault();
									submitBranch(messageIndex);
								}
							});
						}
					}
				}

				function hideBranchInterface(messageIndex) {
					const interface = document.getElementById(`branch-interface-${messageIndex}`);
					if (interface) {
						interface.classList.remove('active');
						// Clear the textarea
						const textarea = interface.querySelector('.branch-textarea');
						if (textarea) {
							textarea.value = '';
						}
					}
				}

				async function submitBranch(messageIndex) {
					if (!currentConversationId) {
						alert('No conversation to branch from');
						return;
					}

					const interface = document.getElementById(`branch-interface-${messageIndex}`);
					const textarea = interface.querySelector('.branch-textarea');
					const newQuestion = textarea.value.trim();

					if (!newQuestion) {
						alert('Please enter a question or message to continue the conversation');
						textarea.focus();
						return;
					}

					try {
						// Disable the submit button to prevent double-clicks
						const submitBtn = interface.querySelector('.branch-submit');
						const originalText = submitBtn.textContent;
						submitBtn.disabled = true;
						submitBtn.textContent = 'Creating branch...';

						// Fork the conversation up to the assistant message before this user message
						const forkResponse = await fetch('?api=fork_conversation', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({
								conversationId: currentConversationId,
								messageIndex: messageIndex - 1 // messageIndex points to user message, so -1 gets the assistant message
							})
						});

						const forkResult = await forkResponse.json();

						console.log('Fork result:', forkResult);

						if (!forkResponse.ok) {
							throw new Error(forkResult.error || 'Failed to create branch');
						}

						// Store the new user message in the branched conversation
						await fetch('?api=store_user_message', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({
								conversationId: forkResult.newConversationId,
								message: newQuestion
							})
						});

						// Redirect to the new conversation with auto-submit flag
						window.location.href = `?action=view&id=${forkResult.newConversationId}&auto_submit=1`;

					} catch (error) {
						console.error('Branch error:', error);
						alert(`Failed to create branch: ${error.message}`);

						// Re-enable the submit button
						const submitBtn = interface.querySelector('.branch-submit');
						submitBtn.disabled = false;
						submitBtn.textContent = originalText;
					}
				}

				// Event listeners
				document.getElementById('send-message').addEventListener('click', sendMessage);
				document.getElementById('user-input').addEventListener('keydown', (e) => {
					if (e.key === 'Enter' && !e.shiftKey) {
						e.preventDefault();
						sendMessage();
					}
				});

				// Function to auto-submit the last user message (for branching)
				async function autoSubmitLastUserMessage() {
					const messages = document.querySelectorAll('.message');
					const lastMessage = messages[messages.length - 1];

					if (!lastMessage || !lastMessage.classList.contains('user')) {
						console.log('No user message to auto-submit');
						return;
					}

					// Get the last user message content
					const userContent = lastMessage.querySelector('.message-content').textContent.trim();
					console.log('Auto-submitting last user message:', userContent);

					// Add to conversation context (message is already stored in DB)
					conversationMessages.push({ role: 'user', content: userContent });

					// Trigger the API call by calling sendMessage with auto-submit mode
					await sendMessage(true); // Pass true to indicate auto-submit mode
				}

				// Initialize only if we're on a conversation page
				if (currentConversationId) {
					loadApiConfig();

					// Handle auto-submit from branching
					const urlParams = new URLSearchParams(window.location.search);
					if (urlParams.get('auto_submit') === '1') {
						// Wait for API config to load, then auto-submit
						setTimeout(() => {
							autoSubmitLastUserMessage();

							// Clean up URL parameter
							const newUrl = window.location.pathname + '?action=view&id=' + currentConversationId;
							window.history.replaceState({}, '', newUrl);
						}, 1000);
					}
				}
				</script>
			<?php else : ?>
				<p>Conversation not found.</p>
				<div style="margin-top: 30px; text-align: center;">
					<a href="?action=list" class="back-link">← Back to List</a>
				</div>
			<?php endif; ?>

		<?php elseif ( $action === 'system_prompts' ) : ?>
			<h2>System Prompts Management</h2>

			<?php if ( isset( $_GET['success'] ) ) : ?>
				<div style="background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 15px;">
					<?php
					switch ( $_GET['success'] ) {
						case 'created':
							echo 'System prompt created successfully!';
							break;
						case 'updated':
							echo 'System prompt updated successfully!';
							break;
						case 'deleted':
							echo 'System prompt deleted successfully!';
							break;
						case 'default_set':
							echo 'Default system prompt set successfully!';
							break;
					}
					?>
				</div>
			<?php endif; ?>

			<?php if ( isset( $promptError ) ) : ?>
				<div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 15px;">
					<?php echo htmlspecialchars( $promptError ); ?>
				</div>
			<?php endif; ?>

			<?php
			$systemPrompts = $storage->getAllSystemPrompts();
			$editingPrompt = null;
			if ( $systemPromptId ) {
				$editingPrompt = $storage->getSystemPrompt( $systemPromptId );
			}
			?>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
				<!-- Create/Edit Form -->
				<div>
					<h3><?php echo $editingPrompt ? 'Edit' : 'Create'; ?> System Prompt</h3>
					<form method="post" style="background: #f8f9fa; padding: 20px; border-radius: 6px; border: 1px solid #e9ecef; width: 25em">
						<input type="hidden" name="prompt_action" value="<?php echo $editingPrompt ? 'update' : 'create'; ?>">
						<?php if ( $editingPrompt ) : ?>
							<input type="hidden" name="prompt_id" value="<?php echo $editingPrompt['id']; ?>">
						<?php endif; ?>

						<div style="margin-bottom: 15px; margin-right: 15px">
							<label style="display: block; margin-bottom: 5px; font-weight: bold;">Name:</label>
							<input type="text" name="prompt_name" required
									value="<?php echo htmlspecialchars( $editingPrompt['name'] ?? '' ); ?>"
									style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
						</div>

						<div style="margin-bottom: 15px; margin-right: 15px">
							<label style="display: block; margin-bottom: 5px; font-weight: bold;">Description:</label>
							<input type="text" name="prompt_description"
									value="<?php echo htmlspecialchars( $editingPrompt['description'] ?? '' ); ?>"
									style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
						</div>

						<div style="margin-bottom: 15px; margin-right: 15px">
							<label style="display: block; margin-bottom: 5px; font-weight: bold;">Prompt Content:</label>
							<textarea name="prompt_content" required rows="6"
										style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"><?php echo htmlspecialchars( $editingPrompt['prompt'] ?? '' ); ?></textarea>
						</div>

						<div style="margin-bottom: 15px; margin-right: 15px">
							<label>
								<input type="checkbox" name="is_default" <?php echo ( $editingPrompt['is_default'] ?? false ) ? 'checked' : ''; ?>>
								Set as default prompt
							</label>
						</div>

						<div>
							<button type="submit" style="padding: 8px 15px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer;">
								<?php echo $editingPrompt ? 'Update' : 'Create'; ?> Prompt
							</button>
							<?php if ( $editingPrompt ) : ?>
								<a href="?action=system_prompts" style="margin-left: 10px; padding: 8px 15px; background: #666; color: white; text-decoration: none; border-radius: 4px;">Cancel</a>
							<?php endif; ?>
						</div>
					</form>
				</div>

				<!-- Prompts List -->
				<div>
					<h3>Existing System Prompts</h3>
					<?php if ( empty( $systemPrompts ) ) : ?>
						<p style="color: #666; font-style: italic;">No system prompts created yet.</p>
					<?php else : ?>
						<div style="max-height: 600px; overflow-y: auto;">
							<?php foreach ( $systemPrompts as $prompt ) : ?>
								<div style="background: white; border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin-bottom: 15px; <?php echo $prompt['is_default'] ? 'border-left: 4px solid #007cba;' : ''; ?>">
									<div style="display: flex; justify-content: between; align-items: flex-start; margin-bottom: 10px;">
										<div style="flex: 1;">
											<h4 style="margin: 0 0 5px 0;">
												<?php echo htmlspecialchars( $prompt['name'] ); ?>
												<?php if ( $prompt['is_default'] ) : ?>
													<span style="background: #007cba; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.7em; margin-left: 8px;">DEFAULT</span>
												<?php endif; ?>
											</h4>
											<?php if ( $prompt['description'] ) : ?>
												<p style="margin: 0 0 10px 0; color: #666; font-size: 0.9em;"><?php echo htmlspecialchars( $prompt['description'] ); ?></p>
											<?php endif; ?>
										</div>
										<div style="margin-left: 15px;">
											<a href="?action=system_prompts&prompt_id=<?php echo $prompt['id']; ?>"
												style="padding: 4px 8px; background: #007cba; color: white; text-decoration: none; border-radius: 3px; font-size: 0.8em; margin-right: 5px;">Edit</a>
											<?php if ( ! $prompt['is_default'] ) : ?>
												<form method="post" style="display: inline;">
													<input type="hidden" name="prompt_action" value="set_default">
													<input type="hidden" name="prompt_id" value="<?php echo $prompt['id']; ?>">
													<button type="submit" style="padding: 4px 8px; background: #28a745; color: white; border: none; border-radius: 3px; font-size: 0.8em; cursor: pointer; margin-right: 5px;">Set Default</button>
												</form>
											<?php endif; ?>
											<form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this system prompt?')">
												<input type="hidden" name="prompt_action" value="delete">
												<input type="hidden" name="prompt_id" value="<?php echo $prompt['id']; ?>">
												<button type="submit" style="padding: 4px 8px; background: #dc3545; color: white; border: none; border-radius: 3px; font-size: 0.8em; cursor: pointer;">Delete</button>
											</form>
										</div>
									</div>
									<div style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 0.9em; white-space: pre-wrap; max-height: 150px; overflow: auto;"><?php echo htmlspecialchars( $prompt['prompt'] ); ?></div>
									<div style="margin-top: 8px; font-size: 0.8em; color: #666;">
										Created: <?php echo date( 'M j, Y g:i A', $prompt['created_at'] ); ?>
										<?php if ( $prompt['updated_at'] != $prompt['created_at'] ) : ?>
											• Updated: <?php echo date( 'M j, Y g:i A', $prompt['updated_at'] ); ?>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

		<?php elseif ( $action === 'stats' ) : ?>
			<?php
			$db = new PDO( 'sqlite:' . $dbPath );
			$totalConversations = $db->query( 'SELECT COUNT(*) FROM conversations' )->fetchColumn();
			$totalMessages = $db->query( 'SELECT COUNT(*) FROM messages' )->fetchColumn();
			$totalCost = $db->query( 'SELECT SUM(cost) FROM conversations' )->fetchColumn();
			$totalInputTokens = $db->query( 'SELECT SUM(input_tokens) FROM conversations' )->fetchColumn();
			$totalOutputTokens = $db->query( 'SELECT SUM(output_tokens) FROM conversations' )->fetchColumn();
			$modelStats = $db->query( 'SELECT model, COUNT(*) as count, SUM(cost) as total_cost FROM conversations GROUP BY model ORDER BY count DESC' )->fetchAll( PDO::FETCH_ASSOC );

			// Tag statistics
			$tagStats = array();
			$allTags = $storage->getAllTags();
			foreach ( $allTags as $tag ) {
				$count = $db->prepare( 'SELECT COUNT(*) FROM conversations WHERE tags LIKE ?' );
				$count->execute( array( '%' . $tag . '%' ) );
				$tagStats[ $tag ] = $count->fetchColumn();
			}
			arsort( $tagStats );

			// Day of week statistics
$dayStats = $db->query(
				"
                SELECT 
                    CASE strftime('%w', created_at, 'unixepoch')
                        WHEN '0' THEN 'Sunday'
                        WHEN '1' THEN 'Monday' 
                        WHEN '2' THEN 'Tuesday'
                        WHEN '3' THEN 'Wednesday'
                        WHEN '4' THEN 'Thursday'
                        WHEN '5' THEN 'Friday'
                        WHEN '6' THEN 'Saturday'
                    END as day_name,
                    COUNT(*) as count
                FROM conversations 
                GROUP BY strftime('%w', created_at, 'unixepoch')
                ORDER BY strftime('%w', created_at, 'unixepoch')
            "
)->fetchAll( PDO::FETCH_ASSOC );

			// Hour of day statistics
$hourStats = $db->query(
				"
                SELECT 
                    strftime('%H', created_at, 'unixepoch') as hour,
                    COUNT(*) as count
                FROM conversations 
                GROUP BY strftime('%H', created_at, 'unixepoch')
                ORDER BY hour
            "
)->fetchAll( PDO::FETCH_ASSOC );

			// Monthly statistics (last 12 months)
$monthStats = $db->query(
				"
                SELECT 
                    strftime('%Y-%m', created_at, 'unixepoch') as month,
                    COUNT(*) as count
                FROM conversations 
                WHERE created_at > " . ( time() - 365 * 24 * 3600 ) . "
                GROUP BY strftime('%Y-%m', created_at, 'unixepoch')
                ORDER BY month DESC
            "
)->fetchAll( PDO::FETCH_ASSOC );

			?>
			
			<h2>Statistics</h2>
			
			<div style="margin-bottom: 30px;">
				<h3>Overview</h3>
				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
					<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
						<div style="font-size: 2em; font-weight: bold; color: #007cba;"><?php echo $totalConversations; ?></div>
						<div style="color: #666;">Total Conversations</div>
					</div>
					<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
						<div style="font-size: 2em; font-weight: bold; color: #007cba;"><?php echo $totalMessages; ?></div>
						<div style="color: #666;">Total Messages</div>
					</div>
					<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
						<div style="font-size: 2em; font-weight: bold; color: #007cba;"><?php echo $totalConversations > 0 ? round( $totalMessages / $totalConversations, 1 ) : 0; ?></div>
						<div style="color: #666;">Avg Messages/Conv</div>
					</div>
					<?php if ( $totalCost > 0 ) : ?>
					<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
						<div style="font-size: 2em; font-weight: bold; color: #28a745;">$<?php echo number_format( $totalCost, 2 ); ?></div>
						<div style="color: #666;">Total Cost</div>
					</div>
					<?php endif; ?>
					<?php if ( $totalInputTokens > 0 || $totalOutputTokens > 0 ) : ?>
					<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
						<div style="font-size: 1.5em; font-weight: bold; color: #007cba;">
							<?php echo number_format( $totalInputTokens + $totalOutputTokens ); ?>
						</div>
						<div style="color: #666; font-size: 0.9em;">
							<?php echo number_format( $totalInputTokens ); ?> in + <?php echo number_format( $totalOutputTokens ); ?> out
						</div>
						<div style="color: #666;">Total Tokens</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
			
			<!-- Models Chart -->
			<div class="chart-container">
				<h3 class="chart-title">Models Used</h3>
				<div class="chart-wrapper">
					<div class="chart chart-models <?php echo count( $modelStats ) > 6 ? 'chart-scrollable' : ''; ?>">
						<?php
						$maxCount = max( array_column( $modelStats, 'count' ) );
						foreach ( $modelStats as $stat ) :
							$height = $maxCount > 0 ? ( $stat['count'] / $maxCount ) * 150 : 2;
							?>
							<div class="chart-bar">
								<div class="chart-bar-inner" style="height: <?php echo $height; ?>px;"></div>
								<div class="chart-bar-value"><?php echo $stat['count']; ?></div>
								<div class="chart-bar-label"><?php echo htmlspecialchars( $stat['model'] ?: 'Unknown' ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			
			<!-- Day of Week Chart -->
			<div class="chart-container">
				<h3 class="chart-title">Day of Week Activity</h3>
				<div class="chart-wrapper">
					<div class="chart">
						<?php
						$maxCount = max( array_column( $dayStats, 'count' ) );
						foreach ( $dayStats as $stat ) :
							$height = $maxCount > 0 ? ( $stat['count'] / $maxCount ) * 150 : 2;
							?>
							<div class="chart-bar">
								<div class="chart-bar-inner" style="height: <?php echo $height; ?>px;"></div>
								<div class="chart-bar-value"><?php echo $stat['count']; ?></div>
								<div class="chart-bar-label"><?php echo substr( $stat['day_name'], 0, 3 ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<!-- Monthly Activity Chart -->
			<div class="chart-container">
				<h3 class="chart-title">Monthly Activity (Last 12 Months)</h3>
				<div class="chart-wrapper">
					<div class="chart <?php echo count( $monthStats ) > 8 ? 'chart-scrollable' : ''; ?>">
						<?php
						$maxCount = max( array_column( $monthStats, 'count' ) );
						foreach ( array_reverse( $monthStats ) as $stat ) :
							$height = $maxCount > 0 ? ( $stat['count'] / $maxCount ) * 150 : 2;
							?>
							<div class="chart-bar">
								<div class="chart-bar-inner" style="height: <?php echo $height; ?>px;"></div>
								<div class="chart-bar-value"><?php echo $stat['count']; ?></div>
								<div class="chart-bar-label"><?php echo $stat['month']; ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			
			<?php if ( ! empty( $tagStats ) ) : ?>
			<div style="margin-bottom: 30px;">
				<h3>Tags</h3>
				<div class="tag-cloud">
					<?php
					$maxCount = max( array_values( $tagStats ) );
					$minCount = min( array_values( $tagStats ) );
					$range = max( $maxCount - $minCount, 1 );

					foreach ( $tagStats as $tag => $count ) :
						// Calculate size class (1-6) based on count
						$normalized = ( $count - $minCount ) / $range;
						$sizeClass = max( 1, min( 6, ceil( $normalized * 5 ) + 1 ) );
						$isSystemTag = ( strpos( $tag, 'system' ) === 0 );
						$systemClass = $isSystemTag ? ' system-tag' : '';
						?>
						<a href="?action=list&tag=<?php echo urlencode( $tag ); ?>" class="tag-cloud-item tag-cloud-size-<?php echo $sizeClass; ?><?php echo $systemClass; ?>" title="<?php echo $count; ?> conversations">
							<?php echo htmlspecialchars( $tag ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<!-- Hour of Day Chart -->
			<div class="chart-container">
				<h3 class="chart-title">Hour of Day Activity</h3>
				<div class="chart-wrapper">
					<div class="chart chart-scrollable">
						<?php
						$maxCount = max( array_column( $hourStats, 'count' ) );
						foreach ( $hourStats as $stat ) :
							$height = $maxCount > 0 ? ( $stat['count'] / $maxCount ) * 150 : 2;
							?>
							<div class="chart-bar">
								<div class="chart-bar-inner" style="height: <?php echo $height; ?>px;"></div>
								<div class="chart-bar-value"><?php echo $stat['count']; ?></div>
								<div class="chart-bar-label"><?php printf( '%02d:00', $stat['hour'] ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

		<?php else : ?>
			<form class="search-form" method="get">
				<input type="hidden" name="action" value="list">
				<input type="text" name="search" placeholder="Search conversations..." value="<?php echo htmlspecialchars( $search ?? '' ); ?>">
				
				<select name="tag" style="margin-left: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
					<option value="">All Tags</option>
					<?php
					$allTags = $storage->getAllTags();
					foreach ( $allTags as $tag ) :
						?>
						<option value="<?php echo htmlspecialchars( $tag ); ?>" <?php echo $selectedTag === $tag ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars( $tag ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				
				<button type="submit">Filter</button>
				<?php if ( $search || $selectedTag ) : ?>
					<a href="?action=list" style="margin-left: 10px;">Clear All</a>
				<?php endif; ?>
			</form>

			<?php
			$conversations = $storage->findConversations( 5, $search, $selectedTag );
			?>
			
			<h2>
				<?php if ( $search && $selectedTag ) : ?>
					Search Results for "<?php echo htmlspecialchars( $search ); ?>" with tag "<?php echo htmlspecialchars( $selectedTag ); ?>"
				<?php elseif ( $search ) : ?>
					Search Results for "<?php echo htmlspecialchars( $search ); ?>"
				<?php elseif ( $selectedTag ) : ?>
					Conversations tagged "<?php echo htmlspecialchars( $selectedTag ); ?>"
				<?php else : ?>
					Recent Conversations
				<?php endif; ?>
			</h2>
			
			<?php if ( empty( $conversations ) ) : ?>
				<p>No conversations found.</p>
			<?php else : ?>
				<ul class="conversation-list">
					<?php foreach ( $conversations as $id ) : ?>
						<?php echo renderConversationItem( $storage, $id ); ?>
					<?php endforeach; ?>
				</ul>

				<div id="loading" class="loading hidden">Loading more conversations...</div>

				<script>
				let loading = false;
				let hasMore = true;
				let offset = 5;
				const search = <?php echo json_encode( $search ?? '' ); ?>;
				const selectedTag = <?php echo json_encode( $selectedTag ?? '' ); ?>;

				function loadMoreConversations() {
					if (loading || !hasMore) return;

					loading = true;
					document.getElementById('loading').classList.remove('hidden');

					const params = new URLSearchParams({
						ajax: 'load_more',
						offset: offset
					});

					if (search) params.append('search', search);
					if (selectedTag) params.append('tag', selectedTag);

					fetch('?' + params.toString())
						.then(response => response.json())
						.then(data => {
							if (data.html) {
								document.querySelector('.conversation-list').insertAdjacentHTML('beforeend', data.html);
								offset += 10;
							}
							hasMore = data.hasMore;
							loading = false;
							document.getElementById('loading').classList.add('hidden');
						})
						.catch(error => {
							console.error('Error loading conversations:', error);
							loading = false;
							document.getElementById('loading').classList.add('hidden');
						});
				}

				// Endless scroll listener
				window.addEventListener('scroll', () => {
					if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 1000) {
						loadMoreConversations();
					}
				});
				</script>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<script>
	document.addEventListener("DOMContentLoaded", function() {
		if (typeof renderMathInElement !== 'undefined') {
			renderMathInElement(document.body, {
				delimiters: [
					{left: '\\[', right: '\\]', display: true},
					{left: '\\(', right: '\\)', display: false},
					{left: '$$', right: '$$', display: true}
				],
				throwOnError: false,
				strict: false,
				ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code']
			});
		}
	});
	</script>
</body>
</html>
