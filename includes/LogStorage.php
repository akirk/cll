<?php

abstract class LogStorage {
	protected $currentConversationId = null;

	public function setCurrentConversation( $conversationId ) {
		$this->currentConversationId = $conversationId;
	}

	public function getCurrentConversation() {
		return $this->currentConversationId;
	}

	abstract public function initializeConversation( $conversationId, $model, $createdAt = null );
	abstract public function writeSystemPrompt( $conversationId, $systemPrompt, $promptName = null );
	abstract public function writeUserMessage( $conversationId, $message, $createdAt = null );
	abstract public function writeAssistantMessage( $conversationId, $message, $createdAt = null, $thinking = null );
	abstract public function loadConversation( $conversationId );
	abstract public function findConversations( $limit = 10, $search = null, $tag = null, $offset = 0 );
	abstract public function getConversationMetadata( $conversationId );
	abstract public function copyConversation( $sourceId, $targetId );
	abstract public function storeCostData( $conversationId, $cost, $inputTokens, $outputTokens );
}

class NoLogStorage extends LogStorage {
	public function initializeConversation( $conversationId, $model, $createdAt = null ) {
		return null; // No-op implementation
	}

	public function writeSystemPrompt( $conversationId, $systemPrompt, $promptName = null ) {
		// No-op implementation
	}

	public function writeUserMessage( $conversationId, $message, $createdAt = null ) {
		// No-op implementation
	}

	public function writeAssistantMessage( $conversationId, $message, $createdAt = null, $thinking = null ) {
		// No-op implementation
	}

	public function loadConversation( $conversationId ) {
		return null; // No-op implementation
	}

	public function findConversations( $limit = 10, $search = null, $tag = null, $offset = 0 ) {
		return array(); // No-op implementation
	}

	public function getConversationMetadata( $conversationId ) {
		return null; // No-op implementation
	}

	public function copyConversation( $sourceId, $targetId ) {
		return false; // No-op implementation
	}

	public function close( $conversationId = null ) {
		// No-op implementation
	}

	public function getSystemPrompt( $id ) {
		return null; // No-op implementation
	}

	public function getSystemPromptByName( $name ) {
		return null; // No-op implementation
	}

	public function storeCostData( $conversationId, $cost, $inputTokens, $outputTokens ) {
		// No-op implementation
	}
}


class SQLiteLogStorage extends LogStorage {
	private $db;
	private $dbPath;

	public function __construct( $dbPath ) {
		$this->dbPath = $dbPath;
		$this->initializeDatabase();
	}

	public function getDatabase() {
		return $this->db;
	}

	private function initializeDatabase() {
		$this->db = new PDO( 'sqlite:' . $this->dbPath );
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		// Create tables
$this->db->exec(
    "
            CREATE TABLE IF NOT EXISTS conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                tags TEXT DEFAULT '',
                cost REAL DEFAULT 0,
                input_tokens INTEGER DEFAULT 0,
                output_tokens INTEGER DEFAULT 0
            )
        "
);

$this->db->exec(
    "
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'system')),
                content TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id)
            )
        "
);

$this->db->exec(
    '
            CREATE TABLE IF NOT EXISTS system_prompts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                prompt TEXT NOT NULL,
                description TEXT,
                is_default INTEGER DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )
        '
);

		$this->db->exec(
			'
			CREATE TABLE IF NOT EXISTS models (
				model TEXT PRIMARY KEY,
				provider TEXT NOT NULL,
				is_offline INTEGER DEFAULT 0,
				is_default INTEGER DEFAULT 0,
				input_price REAL DEFAULT NULL,
				output_price REAL DEFAULT NULL,
				cache_read_price REAL DEFAULT NULL,
				cache_write_price REAL DEFAULT NULL,
				per_tokens INTEGER DEFAULT 1000000,
				added_at INTEGER NOT NULL,
				updated_at INTEGER NOT NULL
			)
		'
		);

		// Create unique partial index to enforce only one default per is_offline value
		$this->db->exec( 'CREATE UNIQUE INDEX IF NOT EXISTS idx_models_default ON models(is_offline, is_default) WHERE is_default = 1' );

		$this->db->exec( 'CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id)' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS idx_conversations_created ON conversations(created_at DESC)' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS idx_conversations_tags ON conversations(tags)' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS idx_system_prompts_name ON system_prompts(name)' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS idx_models_provider ON models(provider)' );
		$this->db->exec( 'CREATE INDEX IF NOT EXISTS idx_models_offline ON models(is_offline)' );

		// Add cost tracking columns if they don't exist (migration)
		$columns = $this->db->query( "PRAGMA table_info(conversations)" )->fetchAll( PDO::FETCH_ASSOC );
		$hasColumns = array();
		foreach ( $columns as $column ) {
			$hasColumns[] = $column['name'];
		}

		if ( ! in_array( 'cost', $hasColumns ) ) {
			$this->db->exec( 'ALTER TABLE conversations ADD COLUMN cost REAL DEFAULT 0' );
		}
		if ( ! in_array( 'input_tokens', $hasColumns ) ) {
			$this->db->exec( 'ALTER TABLE conversations ADD COLUMN input_tokens INTEGER DEFAULT 0' );
		}
		if ( ! in_array( 'output_tokens', $hasColumns ) ) {
			$this->db->exec( 'ALTER TABLE conversations ADD COLUMN output_tokens INTEGER DEFAULT 0' );
		}

		// Add thinking column to messages table if it doesn't exist (migration)
		$messageColumns = $this->db->query( "PRAGMA table_info(messages)" )->fetchAll( PDO::FETCH_ASSOC );
		$hasMessageColumns = array();
		foreach ( $messageColumns as $column ) {
			$hasMessageColumns[] = $column['name'];
		}

		if ( ! in_array( 'thinking', $hasMessageColumns ) ) {
			$this->db->exec( 'ALTER TABLE messages ADD COLUMN thinking TEXT DEFAULT NULL' );
		}

		// Insert default empty system prompt if none exists
		$existingPrompts = $this->db->query( 'SELECT COUNT(*) FROM system_prompts' )->fetchColumn();
		if ( $existingPrompts == 0 ) {
			$time = time();
$stmt = $this->db->prepare(
				'
                INSERT INTO system_prompts (name, prompt, description, is_default, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)
            '
);
			$stmt->execute( array( 'default', '', 'Default empty system prompt', 1, $time, $time ) );
		}
	}

	public function initializeConversation( $conversationId, $model, $createdAt = null ) {
		// Always use autoincrement for SQLite - ignore provided conversationId
		$createdAt = $createdAt ?: time();
		$time = time();

$stmt = $this->db->prepare(
    '
            INSERT INTO conversations (model, created_at, updated_at)
            VALUES (?, ?, ?)
        '
);
		$stmt->execute( array( $model, $createdAt, $time ) );
		return $this->db->lastInsertId();
	}

	public function writeSystemPrompt( $conversationId, $systemPrompt, $promptName = null ) {
		// Simply add as the first system message
		$this->writeMessage( $conversationId, 'system', $systemPrompt );

		// Add automatic system prompt tag
		$this->addSystemPromptTag( $conversationId, $systemPrompt, $promptName );
	}

	private function addSystemPromptTag( $conversationId, $systemPrompt, $promptName = null ) {
		$tag = null;

		if ( $promptName ) {
			// Predefined system prompt - tag as "system:name"
			$tag = 'system:' . $promptName;
		} elseif ( ! empty( trim( $systemPrompt ) ) ) {
			// User-entered custom system prompt - tag as "system"
			$tag = 'system';
		}
		// No tag for default/empty system prompt

		if ( $tag ) {
			$this->addTagToConversation( $conversationId, $tag );
		}
	}

	public function writeUserMessage( $conversationId, $message, $createdAt = null ) {
		$this->writeMessage( $conversationId, 'user', $message, $createdAt );
	}

	public function writeAssistantMessage( $conversationId, $message, $createdAt = null, $thinking = null ) {
		$this->writeMessage( $conversationId, 'assistant', $message, $createdAt, $thinking );
		$this->updateConversationTags( $conversationId );
	}

	private function writeMessage( $conversationId, $role, $content, $createdAt = null, $thinking = null ) {
		$createdAt = $createdAt ?: time();
$stmt = $this->db->prepare(
    '
            INSERT INTO messages (conversation_id, role, content, created_at, thinking)
            VALUES (?, ?, ?, ?, ?)
        '
);
		$stmt->execute( array( $conversationId, $role, $content, $createdAt, $thinking ) );

		// Update conversation timestamp
$updateStmt = $this->db->prepare(
    '
            UPDATE conversations SET updated_at = ? WHERE id = ?
        '
);
		$updateStmt->execute( array( $createdAt, $conversationId ) );
	}

	public function loadConversation( $conversationId ) {
$stmt = $this->db->prepare(
    '
            SELECT role, content, created_at, thinking
            FROM messages
            WHERE conversation_id = ?
            ORDER BY created_at ASC
        '
);
		$stmt->execute( array( $conversationId ) );

		$result = array();
		while ( $row = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$message = array(
				'content'   => $row['content'],
				'timestamp' => $row['created_at'],
				'role'      => $row['role'],
			);
			if ( ! empty( $row['thinking'] ) ) {
				$message['thinking'] = $row['thinking'];
			}
			$result[] = $message;
		}

		return empty( $result ) ? null : $result;
	}


	public function getConversationMetadata( $conversationId ) {
$stmt = $this->db->prepare(
    '
            SELECT c.*, 
                   COUNT(m.id) as message_count,
                   SUM(LENGTH(m.content)) as word_count
            FROM conversations c
            LEFT JOIN messages m ON c.id = m.conversation_id
            WHERE c.id = ?
            GROUP BY c.id
        '
);
		$stmt->execute( array( $conversationId ) );

		$row = $stmt->fetch( PDO::FETCH_ASSOC );
		if ( ! $row ) {
			return null;
		}

		// Count assistant messages (answers)
$answerStmt = $this->db->prepare(
    "
            SELECT COUNT(*) as answers 
            FROM messages 
            WHERE conversation_id = ? AND role = 'assistant'
        "
);
		$answerStmt->execute( array( $conversationId ) );
		$answers = $answerStmt->fetch( PDO::FETCH_ASSOC )['answers'];

		return array(
			'model'        => $row['model'],
			'timestamp'    => $row['created_at'],
			'word_count'   => intval( $row['word_count'] / 5 ), // Rough word count estimation
			'answers'      => $answers,
			'file_path'    => null, // Not applicable for SQLite
			'tags'         => $row['tags'] ?? '',
			'cost'         => floatval( $row['cost'] ?? 0 ),
			'input_tokens' => intval( $row['input_tokens'] ?? 0 ),
			'output_tokens' => intval( $row['output_tokens'] ?? 0 ),
		);
	}

	public function copyConversation( $sourceId, $targetId ) {
		$this->db->beginTransaction();
		try {
			// Copy conversation record
$stmt = $this->db->prepare(
				'
                INSERT INTO conversations (id, model, system_prompt, created_at, updated_at)
                SELECT ?, model, system_prompt, ?, ?
                FROM conversations WHERE id = ?
            '
);
			$time = time();
			$stmt->execute( array( $targetId, $time, $time, $sourceId ) );

			// Copy messages
$stmt = $this->db->prepare(
				'
                INSERT INTO messages (conversation_id, role, content, created_at)
                SELECT ?, role, content, ?
                FROM messages WHERE conversation_id = ?
                ORDER BY created_at ASC
            '
);
			$stmt->execute( array( $targetId, $time, $sourceId ) );

			$this->db->commit();
			return true;
		} catch ( Exception $e ) {
			$this->db->rollback();
			return false;
		}
	}

	public function close( $conversationId = null ) {
		// SQLite doesn't need explicit closing for specific conversations
		// The connection will be closed when the object is destroyed
	}

	public function updateConversationTags( $conversationId ) {
		// Get all messages in conversation
$stmt = $this->db->prepare(
    '
            SELECT content, role FROM messages 
            WHERE conversation_id = ? 
            ORDER BY created_at ASC
        '
);
		$stmt->execute( array( $conversationId ) );
		$messages = $stmt->fetchAll( PDO::FETCH_ASSOC );

		$detectedTags = $this->detectTags( $messages );

		// Add each detected tag individually (this preserves existing tags)
		foreach ( $detectedTags as $tag ) {
			$this->addTagToConversation( $conversationId, $tag );
		}
	}

	public function detectTags( $messages ) {
		$tags = array();
		$allContent = '';
		$hasCode = false;

		foreach ( $messages as $msg ) {
			$content = $msg['content'];
			$allContent .= ' ' . $content;

			// Check for code blocks and extract languages
			if ( preg_match_all( '/```(\w+)?/i', $content, $matches ) ) {
				$hasCode = true;
				foreach ( $matches[1] as $lang ) {
					if ( $lang && strlen( $lang ) > 0 ) {
						$tags[] = strtolower( $lang );
					}
				}
			}

			// Check for inline code
			if ( preg_match( '/`[^`]+`/', $content ) ) {
				$hasCode = true;
			}
		}

		$allContentLower = strtolower( $allContent );

		// Programming languages (keyword-based detection)
		$languages = array(
			'javascript' => array( 'javascript', 'js', 'node.js', 'react', 'vue', 'angular', 'jquery', 'npm' ),
			'php'        => array( '<?php', 'php', 'laravel', 'wordpress', 'composer' ),
			'python'     => array( 'python', 'pip', 'django', 'flask', 'pandas', 'numpy' ),
			'java'       => array( 'java', 'spring', 'maven', 'gradle' ),
			'css'        => array( 'css', 'stylesheet', 'bootstrap', 'sass', 'scss' ),
			'html'       => array( 'html', '<div>', '<span>', '<html>', 'dom' ),
			'sql'        => array( 'select ', 'insert ', 'update ', 'delete ', 'mysql', 'postgres' ),
			'bash'       => array( 'bash', 'shell', 'command', 'terminal', '#!/bin' ),
			'git'        => array( 'git ', 'github', 'commit', 'branch', 'merge', 'pull request' ),
		);

		foreach ( $languages as $lang => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( strpos( $allContentLower, $keyword ) !== false ) {
					$tags[] = $lang;
					break;
				}
			}
		}

		// File types
		$fileTypes = array( '.js', '.php', '.py', '.java', '.css', '.html', '.sql', '.json', '.xml', '.csv' );
		foreach ( $fileTypes as $ext ) {
			if ( strpos( $allContentLower, $ext ) !== false ) {
				$tags[] = 'files';
				break;
			}
		}

		// Content types
		if ( preg_match( '/\b(error|exception|bug|fix|debug|troubleshoot)\b/i', $allContent ) ) {
			$tags[] = 'debugging';
		}

		if ( preg_match( '/\b(optimize|performance|speed|slow|faster)\b/i', $allContent ) ) {
			$tags[] = 'performance';
		}

		if ( preg_match( '/\b(database|db|query|table|schema)\b/i', $allContent ) ) {
			$tags[] = 'database';
		}

		if ( preg_match( '/\b(api|rest|endpoint|http|request|response)\b/i', $allContent ) ) {
			$tags[] = 'api';
		}

		// Tag for code
		if ( $hasCode ) {
			$tags[] = 'code';
		}

		// Conversation length
		if ( count( $messages ) > 20 ) {
			$tags[] = 'long-conversation';
		} elseif ( count( $messages ) <= 5 ) {
			$tags[] = 'quick-question';
		}

		return array_unique( $tags );
	}

	private function isEnglishText( $text ) {
		$text = strtolower( $text );

		// Common English words that appear frequently
		$englishWords = array(
			'the',
			'and',
			'or',
			'but',
			'in',
			'on',
			'at',
			'to',
			'for',
			'of',
			'with',
			'by',
			'is',
			'are',
			'was',
			'were',
			'be',
			'been',
			'have',
			'has',
			'had',
			'do',
			'does',
			'did',
			'will',
			'would',
			'could',
			'should',
			'can',
			'may',
			'might',
			'must',
			'this',
			'that',
			'these',
			'those',
			'a',
			'an',
			'it',
			'he',
			'she',
			'we',
			'they',
			'you',
			'i',
			'what',
			'when',
			'where',
			'why',
			'how',
			'which',
			'who',
			'whose',
		);

		$wordCount = 0;
		$englishWordCount = 0;

		// Split into words and count
		$words = preg_split( '/\s+/', $text );
		foreach ( $words as $word ) {
			$word = preg_replace( '/[^a-z]/', '', $word ); // Remove non-letters
			if ( strlen( $word ) > 1 ) {
				++$wordCount;
				if ( in_array( $word, $englishWords ) ) {
					++$englishWordCount;
				}
			}
		}

		// If we have enough words and at least 20% are common English words
		return $wordCount > 10 && ( $englishWordCount / $wordCount ) > 0.2;
	}

	public function findConversations( $limit = 10, $search = null, $tag = null, $offset = 0 ) {
		$sql = '
            SELECT DISTINCT c.id
            FROM conversations c
        ';

		$params = array();
		$whereClauses = array();

		if ( $search ) {
			$sql .= ' LEFT JOIN messages m ON c.id = m.conversation_id';
			$whereClauses[] = 'm.content LIKE ?';
			$searchParam = '%' . $search . '%';
			$params[] = $searchParam;
		}

		if ( $tag ) {
			$whereClauses[] = 'c.tags LIKE ?';
			$params[] = '%' . $tag . '%';
		}

		if ( ! empty( $whereClauses ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $whereClauses );
		}

		$sql .= ' ORDER BY c.updated_at DESC LIMIT ? OFFSET ?';
		$params[] = $limit;
		$params[] = $offset;

		$stmt = $this->db->prepare( $sql );
		$stmt->execute( $params );

		$conversations = array();
		while ( $row = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$conversations[] = $row['id'];
		}

		return $conversations;
	}

	public function getAllTags() {
$stmt = $this->db->prepare(
    "
            SELECT DISTINCT tags FROM conversations 
            WHERE tags != '' AND tags IS NOT NULL
        "
);
		$stmt->execute();

		$allTags = array();
		while ( $row = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$tags = explode( ',', $row['tags'] );
			foreach ( $tags as $tag ) {
				$tag = trim( $tag );
				if ( $tag && ! in_array( $tag, $allTags ) ) {
					$allTags[] = $tag;
				}
			}
		}

		sort( $allTags );
		return $allTags;
	}

	public function getConversationTags( $conversationId ) {
		$stmt = $this->db->prepare( 'SELECT tags FROM conversations WHERE id = ?' );
		$stmt->execute( array( $conversationId ) );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		if ( ! $row || ! $row['tags'] ) {
			return array();
		}

		$tags = explode( ',', $row['tags'] );
		return array_map( 'trim', $tags );
	}

	public function setConversationTags( $conversationId, $tags ) {
		$tagsString = implode( ',', array_map( 'trim', $tags ) );
		$stmt = $this->db->prepare( 'UPDATE conversations SET tags = ? WHERE id = ?' );
		return $stmt->execute( array( $tagsString, $conversationId ) );
	}

	public function addTagToConversation( $conversationId, $newTag ) {
		$currentTags = $this->getConversationTags( $conversationId );
		$newTag = trim( $newTag );

		if ( $newTag && ! in_array( $newTag, $currentTags ) ) {
			$currentTags[] = $newTag;
			return $this->setConversationTags( $conversationId, $currentTags );
		}

		return false;
	}

	public function addTag( $newTag ) {
		if ( $this->currentConversationId ) {
			return $this->addTagToConversation( $this->currentConversationId, $newTag );
		}
		return false;
	}

	public function removeTagFromConversation( $conversationId, $tagToRemove ) {
		$currentTags = $this->getConversationTags( $conversationId );
		$tagToRemove = trim( $tagToRemove );

$filteredTags = array_filter(
    $currentTags,
    function ( $tag ) use ( $tagToRemove ) {
     return $tag !== $tagToRemove;
    }
);

		if ( count( $filteredTags ) !== count( $currentTags ) ) {
			return $this->setConversationTags( $conversationId, $filteredTags );
		}

		return false;
	}

	public function deleteConversation( $conversationId ) {
		$this->db->beginTransaction();
		try {
			// Delete messages first (due to foreign key constraint)
			$stmt = $this->db->prepare( 'DELETE FROM messages WHERE conversation_id = ?' );
			$stmt->execute( array( $conversationId ) );

			// Delete conversation
			$stmt = $this->db->prepare( 'DELETE FROM conversations WHERE id = ?' );
			$stmt->execute( array( $conversationId ) );

			$this->db->commit();
			return true;
		} catch ( Exception $e ) {
			$this->db->rollback();
			return false;
		}
	}

	public function getAllSystemPrompts() {
$stmt = $this->db->prepare(
    '
            SELECT * FROM system_prompts
            ORDER BY is_default DESC, name ASC
        '
);
		$stmt->execute();
		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	public function getSystemPrompt( $id ) {
		$stmt = $this->db->prepare( 'SELECT * FROM system_prompts WHERE id = ?' );
		$stmt->execute( array( $id ) );
		return $stmt->fetch( PDO::FETCH_ASSOC );
	}

	public function getSystemPromptByName( $name ) {
		$stmt = $this->db->prepare( 'SELECT * FROM system_prompts WHERE name = ?' );
		$stmt->execute( array( $name ) );
		return $stmt->fetch( PDO::FETCH_ASSOC );
	}

	public function getDefaultSystemPrompt() {
		$stmt = $this->db->prepare( 'SELECT * FROM system_prompts WHERE is_default = 1 LIMIT 1' );
		$stmt->execute();
		return $stmt->fetch( PDO::FETCH_ASSOC );
	}

	public function createSystemPrompt( $name, $prompt, $description = '', $isDefault = false ) {
		$time = time();

		// If setting as default, unset other defaults first
		if ( $isDefault ) {
			$this->db->exec( 'UPDATE system_prompts SET is_default = 0' );
		}

$stmt = $this->db->prepare(
    '
            INSERT INTO system_prompts (name, prompt, description, is_default, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
        '
);

		try {
			$stmt->execute( array( $name, $prompt, $description, $isDefault ? 1 : 0, $time, $time ) );
			return $this->db->lastInsertId();
		} catch ( PDOException $e ) {
			if ( $e->getCode() == 23000 ) { // UNIQUE constraint failed
				return false;
			}
			throw $e;
		}
	}

	public function updateSystemPrompt( $id, $name, $prompt, $description = '', $isDefault = false ) {
		$time = time();

		// If setting as default, unset other defaults first
		if ( $isDefault ) {
			$this->db->exec( 'UPDATE system_prompts SET is_default = 0' );
		}

$stmt = $this->db->prepare(
    '
            UPDATE system_prompts
            SET name = ?, prompt = ?, description = ?, is_default = ?, updated_at = ?
            WHERE id = ?
        '
);

		try {
			return $stmt->execute( array( $name, $prompt, $description, $isDefault ? 1 : 0, $time, $id ) );
		} catch ( PDOException $e ) {
			if ( $e->getCode() == 23000 ) { // UNIQUE constraint failed
				return false;
			}
			throw $e;
		}
	}

	public function deleteSystemPrompt( $id ) {
		$stmt = $this->db->prepare( 'DELETE FROM system_prompts WHERE id = ?' );
		return $stmt->execute( array( $id ) );
	}

	public function setDefaultSystemPrompt( $id ) {
		$this->db->beginTransaction();
		try {
			// Unset all defaults
			$this->db->exec( 'UPDATE system_prompts SET is_default = 0' );

			// Set new default
			$stmt = $this->db->prepare( 'UPDATE system_prompts SET is_default = 1 WHERE id = ?' );
			$result = $stmt->execute( array( $id ) );

			$this->db->commit();
			return $result;
		} catch ( Exception $e ) {
			$this->db->rollback();
			return false;
		}
	}

	public function storeCostData( $conversationId, $cost, $inputTokens, $outputTokens ) {
		$stmt = $this->db->prepare(
			'UPDATE conversations SET cost = cost + ?, input_tokens = input_tokens + ?, output_tokens = output_tokens + ? WHERE id = ?'
		);
		return $stmt->execute( array( $cost, $inputTokens, $outputTokens, $conversationId ) );
	}

	// Model management methods
	public function getModels( $isOffline = null ) {
		if ( $isOffline === null ) {
			$stmt = $this->db->prepare( 'SELECT * FROM models ORDER BY provider, model' );
			$stmt->execute();
		} else {
			$stmt = $this->db->prepare( 'SELECT * FROM models WHERE is_offline = ? ORDER BY provider, model' );
			$stmt->execute( array( $isOffline ? 1 : 0 ) );
		}
		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	public function getLastModelUpdate() {
		$stmt = $this->db->prepare( 'SELECT MAX(updated_at) as last_update FROM models' );
		$stmt->execute();
		$result = $stmt->fetch( PDO::FETCH_ASSOC );
		return $result['last_update'] ?? null;
	}

	public function getDefaultModel( $isOffline ) {
		$stmt = $this->db->prepare( 'SELECT model FROM models WHERE is_offline = ? AND is_default = 1' );
		$stmt->execute( array( $isOffline ? 1 : 0 ) );
		$result = $stmt->fetch( PDO::FETCH_ASSOC );
		return $result ? $result['model'] : null;
	}

	public function setDefaultModel( $model, $isOffline ) {
		$time = time();
		$isOfflineInt = $isOffline ? 1 : 0;

		// First, unset any existing default for this mode (online or offline)
		$stmt = $this->db->prepare(
			'UPDATE models SET is_default = 0, updated_at = ? WHERE is_offline = ? AND is_default = 1'
		);
		$stmt->execute( array( $time, $isOfflineInt ) );

		// Then set the new default
		$stmt = $this->db->prepare(
			'UPDATE models SET is_default = 1, updated_at = ? WHERE model = ? AND is_offline = ?'
		);
		return $stmt->execute( array( $time, $model, $isOfflineInt ) );
	}

	public function upsertModel( $model, $provider, $isOffline, $inputPrice = null, $outputPrice = null, $cacheReadPrice = null, $cacheWritePrice = null, $perTokens = 1000000 ) {
		$time = time();
		$stmt = $this->db->prepare(
			'INSERT OR REPLACE INTO models (model, provider, is_offline, is_default, input_price, output_price, cache_read_price, cache_write_price, per_tokens, added_at, updated_at)
			VALUES (?, ?, ?, COALESCE((SELECT is_default FROM models WHERE model = ?), 0), ?, ?, ?, ?, ?, COALESCE((SELECT added_at FROM models WHERE model = ?), ?), ?)'
		);
		return $stmt->execute( array( $model, $provider, $isOffline ? 1 : 0, $model, $inputPrice, $outputPrice, $cacheReadPrice, $cacheWritePrice, $perTokens, $model, $time, $time ) );
	}

	public function getModelPricing( $model ) {
		$stmt = $this->db->prepare( 'SELECT input_price, output_price, cache_read_price, cache_write_price, per_tokens FROM models WHERE model = ?' );
		$stmt->execute( array( $model ) );
		return $stmt->fetch( PDO::FETCH_ASSOC );
	}

	public function __destruct() {
		$this->db = null;
	}
}
