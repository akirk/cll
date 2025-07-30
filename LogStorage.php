<?php

abstract class LogStorage {
    abstract public function initializeConversation($conversationId, $model, $createdAt = null);
    abstract public function writeSystemPrompt($conversationId, $systemPrompt, $promptName = null);
    abstract public function writeUserMessage($conversationId, $message, $createdAt = null);
    abstract public function writeAssistantMessage($conversationId, $message, $createdAt = null);
    abstract public function loadConversation($conversationId);
    abstract public function findConversations($limit = 10, $search = null, $tag = null, $offset = 0);
    abstract public function getConversationMetadata($conversationId);
    abstract public function copyConversation($sourceId, $targetId);
}


class SQLiteLogStorage extends LogStorage {
    private $db;
    private $dbPath;

    public function __construct($dbPath) {
        $this->dbPath = $dbPath;
        $this->initializeDatabase();
    }

    private function initializeDatabase() {
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create tables
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                tags TEXT DEFAULT ''
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'system')),
                content TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS system_prompts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                prompt TEXT NOT NULL,
                description TEXT,
                is_default INTEGER DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )
        ");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_conversations_created ON conversations(created_at DESC)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_conversations_tags ON conversations(tags)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_system_prompts_name ON system_prompts(name)");

        // Insert default empty system prompt if none exists
        $existingPrompts = $this->db->query("SELECT COUNT(*) FROM system_prompts")->fetchColumn();
        if ($existingPrompts == 0) {
            $time = time();
            $stmt = $this->db->prepare("
                INSERT INTO system_prompts (name, prompt, description, is_default, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(['default', '', 'Default empty system prompt', 1, $time, $time]);
        }
    }

    public function initializeConversation($conversationId, $model, $createdAt = null) {
        // Always use autoincrement for SQLite - ignore provided conversationId
        $createdAt = $createdAt ?: time();
        $time = time();

        $stmt = $this->db->prepare("
            INSERT INTO conversations (model, created_at, updated_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$model, $createdAt, $time]);
        return $this->db->lastInsertId();
    }

    public function writeSystemPrompt($conversationId, $systemPrompt, $promptName = null) {
        // Simply add as the first system message
        $this->writeMessage($conversationId, 'system', $systemPrompt);

        // Add automatic system prompt tag
        $this->addSystemPromptTag($conversationId, $systemPrompt, $promptName);
    }

    private function addSystemPromptTag($conversationId, $systemPrompt, $promptName = null) {
        $tag = null;

        if ($promptName) {
            // Predefined system prompt - tag as "system:name"
            $tag = 'system:' . $promptName;
        } elseif (!empty(trim($systemPrompt))) {
            // User-entered custom system prompt - tag as "system"
            $tag = 'system';
        }
        // No tag for default/empty system prompt

        if ($tag) {
            $this->addTagToConversation($conversationId, $tag);
        }
    }

    public function writeUserMessage($conversationId, $message, $createdAt = null) {
        $this->writeMessage($conversationId, 'user', $message, $createdAt);
    }

    public function writeAssistantMessage($conversationId, $message, $createdAt = null) {
        $this->writeMessage($conversationId, 'assistant', $message, $createdAt);
        $this->updateConversationTags($conversationId);
    }

    private function writeMessage($conversationId, $role, $content, $createdAt = null) {
        $createdAt = $createdAt ?: time();
        $stmt = $this->db->prepare("
            INSERT INTO messages (conversation_id, role, content, created_at) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conversationId, $role, $content, $createdAt]);

        // Update conversation timestamp
        $updateStmt = $this->db->prepare("
            UPDATE conversations SET updated_at = ? WHERE id = ?
        ");
        $updateStmt->execute([$createdAt, $conversationId]);
    }

    public function loadConversation($conversationId) {
        $stmt = $this->db->prepare("
            SELECT role, content, created_at 
            FROM messages 
            WHERE conversation_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversationId]);
        
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = [
                'content' => $row['content'],
                'timestamp' => $row['created_at'],
                'role' => $row['role']
            ];
        }

        return empty($result) ? null : $result;
    }


    public function getConversationMetadata($conversationId) {
        $stmt = $this->db->prepare("
            SELECT c.*, 
                   COUNT(m.id) as message_count,
                   SUM(LENGTH(m.content)) as word_count
            FROM conversations c
            LEFT JOIN messages m ON c.id = m.conversation_id
            WHERE c.id = ?
            GROUP BY c.id
        ");
        $stmt->execute([$conversationId]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // Count assistant messages (answers)
        $answerStmt = $this->db->prepare("
            SELECT COUNT(*) as answers 
            FROM messages 
            WHERE conversation_id = ? AND role = 'assistant'
        ");
        $answerStmt->execute([$conversationId]);
        $answers = $answerStmt->fetch(PDO::FETCH_ASSOC)['answers'];

        return [
            'model' => $row['model'],
            'timestamp' => $row['created_at'],
            'word_count' => intval($row['word_count'] / 5), // Rough word count estimation
            'answers' => $answers,
            'file_path' => null, // Not applicable for SQLite
            'tags' => $row['tags'] ?? ''
        ];
    }

    public function copyConversation($sourceId, $targetId) {
        $this->db->beginTransaction();
        try {
            // Copy conversation record
            $stmt = $this->db->prepare("
                INSERT INTO conversations (id, model, system_prompt, created_at, updated_at)
                SELECT ?, model, system_prompt, ?, ?
                FROM conversations WHERE id = ?
            ");
            $time = time();
            $stmt->execute([$targetId, $time, $time, $sourceId]);

            // Copy messages
            $stmt = $this->db->prepare("
                INSERT INTO messages (conversation_id, role, content, created_at)
                SELECT ?, role, content, ?
                FROM messages WHERE conversation_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$targetId, $time, $sourceId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    public function close($conversationId = null) {
        // SQLite doesn't need explicit closing for specific conversations
        // The connection will be closed when the object is destroyed
    }

    public function updateConversationTags($conversationId) {
        // Get all messages in conversation
        $stmt = $this->db->prepare("
            SELECT content, role FROM messages 
            WHERE conversation_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $detectedTags = $this->detectTags($messages);
        
        // Add each detected tag individually (this preserves existing tags)
        foreach ($detectedTags as $tag) {
            $this->addTagToConversation($conversationId, $tag);
        }
    }
    
    public function detectTags($messages) {
        $tags = [];
        $allContent = '';
        $hasCode = false;
        
        foreach ($messages as $msg) {
            $content = $msg['content'];
            $allContent .= ' ' . $content;
            
            // Check for code blocks and extract languages
            if (preg_match_all('/```(\w+)?/i', $content, $matches)) {
                $hasCode = true;
                foreach ($matches[1] as $lang) {
                    if ($lang && strlen($lang) > 0) {
                        $tags[] = strtolower($lang);
                    }
                }
            }
            
            // Check for inline code
            if (preg_match('/`[^`]+`/', $content)) {
                $hasCode = true;
            }
        }
        
        $allContentLower = strtolower($allContent);

        // Programming languages (keyword-based detection)
        $languages = [
            'javascript' => ['javascript', 'js', 'node.js', 'react', 'vue', 'angular', 'jquery', 'npm'],
            'php' => ['<?php', 'php', 'laravel', 'wordpress', 'composer'],
            'python' => ['python', 'pip', 'django', 'flask', 'pandas', 'numpy'],
            'java' => ['java', 'spring', 'maven', 'gradle'],
            'css' => ['css', 'stylesheet', 'bootstrap', 'sass', 'scss'],
            'html' => ['html', '<div>', '<span>', '<html>', 'dom'],
            'sql' => ['select ', 'insert ', 'update ', 'delete ', 'mysql', 'postgres'],
            'bash' => ['bash', 'shell', 'command', 'terminal', '#!/bin'],
            'git' => ['git ', 'github', 'commit', 'branch', 'merge', 'pull request']
        ];

        foreach ($languages as $lang => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($allContentLower, $keyword) !== false) {
                    $tags[] = $lang;
                    break;
                }
            }
        }

        // File types
        $fileTypes = ['.js', '.php', '.py', '.java', '.css', '.html', '.sql', '.json', '.xml', '.csv'];
        foreach ($fileTypes as $ext) {
            if (strpos($allContentLower, $ext) !== false) {
                $tags[] = 'files';
                break;
            }
        }

        // Content types
        if (preg_match('/\b(error|exception|bug|fix|debug|troubleshoot)\b/i', $allContent)) {
            $tags[] = 'debugging';
        }

        if (preg_match('/\b(optimize|performance|speed|slow|faster)\b/i', $allContent)) {
            $tags[] = 'performance';
        }

        if (preg_match('/\b(database|db|query|table|schema)\b/i', $allContent)) {
            $tags[] = 'database';
        }

        if (preg_match('/\b(api|rest|endpoint|http|request|response)\b/i', $allContent)) {
            $tags[] = 'api';
        }

        // Tag for code
        if ($hasCode) {
            $tags[] = 'code';
        }
        
        // Conversation length
        if (count($messages) > 20) {
            $tags[] = 'long-conversation';
        } elseif (count($messages) <= 5) {
            $tags[] = 'quick-question';
        }
        
        return array_unique($tags);
    }
    
    private function isEnglishText($text) {
        $text = strtolower($text);
        
        // Common English words that appear frequently
        $englishWords = [
            'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by',
            'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did',
            'will', 'would', 'could', 'should', 'can', 'may', 'might', 'must',
            'this', 'that', 'these', 'those', 'a', 'an', 'it', 'he', 'she', 'we', 'they', 'you', 'i',
            'what', 'when', 'where', 'why', 'how', 'which', 'who', 'whose'
        ];
        
        $wordCount = 0;
        $englishWordCount = 0;
        
        // Split into words and count
        $words = preg_split('/\s+/', $text);
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word); // Remove non-letters
            if (strlen($word) > 1) {
                $wordCount++;
                if (in_array($word, $englishWords)) {
                    $englishWordCount++;
                }
            }
        }
        
        // If we have enough words and at least 20% are common English words
        return $wordCount > 10 && ($englishWordCount / $wordCount) > 0.2;
    }
    
    public function findConversations($limit = 10, $search = null, $tag = null, $offset = 0) {
        $sql = "
            SELECT DISTINCT c.id
            FROM conversations c
        ";
        
        $params = [];
        $whereClauses = [];
        
        if ($search) {
            $sql .= " LEFT JOIN messages m ON c.id = m.conversation_id";
            $whereClauses[] = "m.content LIKE ?";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
        }
        
        if ($tag) {
            $whereClauses[] = "c.tags LIKE ?";
            $params[] = '%' . $tag . '%';
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY c.updated_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $conversations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $conversations[] = $row['id'];
        }

        return $conversations;
    }
    
    public function getAllTags() {
        $stmt = $this->db->prepare("
            SELECT DISTINCT tags FROM conversations 
            WHERE tags != '' AND tags IS NOT NULL
        ");
        $stmt->execute();
        
        $allTags = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tags = explode(',', $row['tags']);
            foreach ($tags as $tag) {
                $tag = trim($tag);
                if ($tag && !in_array($tag, $allTags)) {
                    $allTags[] = $tag;
                }
            }
        }
        
        sort($allTags);
        return $allTags;
    }
    
    public function getConversationTags($conversationId) {
        $stmt = $this->db->prepare("SELECT tags FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row || !$row['tags']) {
            return [];
        }
        
        $tags = explode(',', $row['tags']);
        return array_map('trim', $tags);
    }
    
    public function setConversationTags($conversationId, $tags) {
        $tagsString = implode(',', array_map('trim', $tags));
        $stmt = $this->db->prepare("UPDATE conversations SET tags = ? WHERE id = ?");
        return $stmt->execute([$tagsString, $conversationId]);
    }
    
    public function addTagToConversation($conversationId, $newTag) {
        $currentTags = $this->getConversationTags($conversationId);
        $newTag = trim($newTag);
        
        if ($newTag && !in_array($newTag, $currentTags)) {
            $currentTags[] = $newTag;
            return $this->setConversationTags($conversationId, $currentTags);
        }
        
        return false;
    }
    
    public function removeTagFromConversation($conversationId, $tagToRemove) {
        $currentTags = $this->getConversationTags($conversationId);
        $tagToRemove = trim($tagToRemove);
        
        $filteredTags = array_filter($currentTags, function($tag) use ($tagToRemove) {
            return $tag !== $tagToRemove;
        });
        
        if (count($filteredTags) !== count($currentTags)) {
            return $this->setConversationTags($conversationId, $filteredTags);
        }
        
        return false;
    }

    public function deleteConversation($conversationId) {
        $this->db->beginTransaction();
        try {
            // Delete messages first (due to foreign key constraint)
            $stmt = $this->db->prepare("DELETE FROM messages WHERE conversation_id = ?");
            $stmt->execute([$conversationId]);

            // Delete conversation
            $stmt = $this->db->prepare("DELETE FROM conversations WHERE id = ?");
            $stmt->execute([$conversationId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    public function getAllSystemPrompts() {
        $stmt = $this->db->prepare("
            SELECT * FROM system_prompts
            ORDER BY is_default DESC, name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSystemPrompt($id) {
        $stmt = $this->db->prepare("SELECT * FROM system_prompts WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getSystemPromptByName($name) {
        $stmt = $this->db->prepare("SELECT * FROM system_prompts WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getDefaultSystemPrompt() {
        $stmt = $this->db->prepare("SELECT * FROM system_prompts WHERE is_default = 1 LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createSystemPrompt($name, $prompt, $description = '', $isDefault = false) {
        $time = time();

        // If setting as default, unset other defaults first
        if ($isDefault) {
            $this->db->exec("UPDATE system_prompts SET is_default = 0");
        }

        $stmt = $this->db->prepare("
            INSERT INTO system_prompts (name, prompt, description, is_default, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        try {
            $stmt->execute([$name, $prompt, $description, $isDefault ? 1 : 0, $time, $time]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // UNIQUE constraint failed
                return false;
            }
            throw $e;
        }
    }

    public function updateSystemPrompt($id, $name, $prompt, $description = '', $isDefault = false) {
        $time = time();

        // If setting as default, unset other defaults first
        if ($isDefault) {
            $this->db->exec("UPDATE system_prompts SET is_default = 0");
        }

        $stmt = $this->db->prepare("
            UPDATE system_prompts
            SET name = ?, prompt = ?, description = ?, is_default = ?, updated_at = ?
            WHERE id = ?
        ");

        try {
            return $stmt->execute([$name, $prompt, $description, $isDefault ? 1 : 0, $time, $id]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // UNIQUE constraint failed
                return false;
            }
            throw $e;
        }
    }

    public function deleteSystemPrompt($id) {
        $stmt = $this->db->prepare("DELETE FROM system_prompts WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function setDefaultSystemPrompt($id) {
        $this->db->beginTransaction();
        try {
            // Unset all defaults
            $this->db->exec("UPDATE system_prompts SET is_default = 0");

            // Set new default
            $stmt = $this->db->prepare("UPDATE system_prompts SET is_default = 1 WHERE id = ?");
            $result = $stmt->execute([$id]);

            $this->db->commit();
            return $result;
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    public function __destruct() {
        $this->db = null;
    }
}
