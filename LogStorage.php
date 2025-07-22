<?php

abstract class LogStorage {
    abstract public function initializeConversation($conversationId, $model, $createdAt = null);
    abstract public function writeSystemPrompt($conversationId, $systemPrompt);
    abstract public function writeUserMessage($conversationId, $message, $createdAt = null);
    abstract public function writeAssistantMessage($conversationId, $message, $createdAt = null);
    abstract public function loadConversation($conversationId);
    abstract public function findConversations($limit = 10, $search = null, $tag = null, $offset = 0);
    abstract public function getConversationMetadata($conversationId);
    abstract public function copyConversation($sourceId, $targetId);
}

class FileLogStorage extends LogStorage {
    private $baseDirectory;
    private $openFiles = [];

    public function __construct($baseDirectory) {
        $this->baseDirectory = rtrim($baseDirectory, '/');
        if (!file_exists($this->baseDirectory)) {
            if (!mkdir($this->baseDirectory, 0777, true)) {
                throw new Exception("Could not create log directory: " . $this->baseDirectory);
            }
        }
    }

    public function initializeConversation($conversationId, $model, $createdAt = null) {
        $historyDirectory = $this->getHistoryDirectory($conversationId);
        if (!file_exists($historyDirectory)) {
            mkdir($historyDirectory, 0777, true);
        }
        
        $filePath = $this->getConversationFilePath($conversationId, $model);
        if (!isset($this->openFiles[$conversationId])) {
            $this->openFiles[$conversationId] = fopen($filePath, 'a');
        }
        return $filePath;
    }

    public function writeSystemPrompt($conversationId, $systemPrompt) {
        $fp = $this->getFileHandle($conversationId);
        fwrite($fp, 'System: ' . $systemPrompt . PHP_EOL);
    }

    public function writeUserMessage($conversationId, $message, $createdAt = null) {
        $fp = $this->getFileHandle($conversationId);
        if (false === strpos($message, PHP_EOL)) {
            fwrite($fp, '> ' . $message . PHP_EOL . PHP_EOL);
        } else {
            fwrite($fp, '>>> ' . $message . PHP_EOL . '.' . PHP_EOL . PHP_EOL);
        }
    }

    public function writeAssistantMessage($conversationId, $message, $createdAt = null) {
        $fp = $this->getFileHandle($conversationId);
        fwrite($fp, $message . PHP_EOL . PHP_EOL);
    }

    public function loadConversation($filePath) {
        if (!file_exists($filePath)) {
            return null;
        }

        $conversationContents = file_get_contents($filePath);
        $split = preg_split('/^>(?: ([^\n]*)|>> (.*)\n\.)\n\n/ms', trim($conversationContents), -1, PREG_SPLIT_DELIM_CAPTURE);
        $split = array_filter($split);
        $split = array_values($split);

        if (count($split) < 2) {
            return null;
        }

        $s = array_shift($split);
        if (substr($s, 0, 7) === 'System:') {
            $split[0] = $s . $split[0];
        } else {
            array_unshift($split, $s);
        }

        return $split;
    }

    public function findConversations($limit = 10, $search = null, $tag = null, $offset = 0) {
        $historyFiles = [];
        $time = time();
        
        for ($i = 0; $i > -300; $i -= 20) {
            $pattern = $this->baseDirectory . '/*/*/history.*';
            $moreHistoryFiles = array_flip(glob($pattern));
            
            if ($search) {
                $moreHistoryFiles = array_filter($moreHistoryFiles, function($file) use ($search) {
                    $fileContents = file_get_contents($file);
                    return false !== stripos($fileContents, $search);
                }, ARRAY_FILTER_USE_KEY);
            }
            
            $historyFiles = array_merge($historyFiles, $moreHistoryFiles);
            if (count($historyFiles) >= $limit) {
                break;
            }
        }
        
        krsort($historyFiles);
        return array_slice(array_keys($historyFiles), $offset, $limit);
    }

    public function getConversationMetadata($filePath) {
        if (!file_exists($filePath)) {
            return null;
        }

        $filenameParts = explode('.', basename($filePath));
        $usedModel = isset($filenameParts[2]) && $filenameParts[2] !== 'txt' ? $filenameParts[2] : '';
        $timestamp = isset($filenameParts[1]) && is_numeric($filenameParts[1]) ? $filenameParts[1] : time();
        
        $conversationContents = file_get_contents($filePath);
        $wordCount = str_word_count($conversationContents);
        
        $split = preg_split('/^>(?: ([^\n]*)|>> (.*)\n\.)\n\n/ms', trim($conversationContents), -1, PREG_SPLIT_DELIM_CAPTURE);
        if ( ! is_array($split) || count($split) < 2) {
            return null;
        }

        $answers = intval(count(array_filter($split)) / 2);

        return [
            'model' => $usedModel,
            'timestamp' => $timestamp,
            'word_count' => $wordCount,
            'answers' => $answers,
            'file_path' => $filePath
        ];
    }

    public function copyConversation($sourceId, $targetId) {
        $sourceFile = $this->findConversationFile($sourceId);
        if (!$sourceFile) {
            return false;
        }
        
        $targetFile = $this->getConversationFilePath($targetId, '');
        return copy($sourceFile, $targetFile);
    }

    private function getFileHandle($conversationId) {
        if (!isset($this->openFiles[$conversationId])) {
            throw new Exception("Conversation not initialized: " . $conversationId);
        }
        return $this->openFiles[$conversationId];
    }

    private function getHistoryDirectory($conversationId) {
        $timestamp = $this->extractTimestamp($conversationId);
        return $this->baseDirectory . '/' . date('Y/m', $timestamp);
    }

    private function getConversationFilePath($conversationId, $model) {
        $historyDirectory = $this->getHistoryDirectory($conversationId);
        $modelSuffix = $model ? '.' . preg_replace('/[^a-z0-9]+/', '-', $model) : '';
        return $historyDirectory . '/history.' . $conversationId . $modelSuffix . '.txt';
    }

    private function findConversationFile($conversationId) {
        $historyDirectory = $this->getHistoryDirectory($conversationId);
        $pattern = $historyDirectory . '/history.' . $conversationId . '*.txt';
        $files = glob($pattern);
        return $files ? $files[0] : null;
    }

    private function extractTimestamp($conversationId) {
        return is_numeric($conversationId) ? $conversationId : time();
    }

    public function close($conversationId = null) {
        if ($conversationId) {
            if (isset($this->openFiles[$conversationId])) {
                fclose($this->openFiles[$conversationId]);
                unset($this->openFiles[$conversationId]);
            }
        } else {
            foreach ($this->openFiles as $fp) {
                fclose($fp);
            }
            $this->openFiles = [];
        }
    }

    public function __destruct() {
        $this->close();
    }
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
                system_prompt TEXT,
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

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_conversations_created ON conversations(created_at DESC)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_conversations_tags ON conversations(tags)");
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

    public function writeSystemPrompt($conversationId, $systemPrompt) {
        // Update the conversation with system prompt
        $stmt = $this->db->prepare("
            UPDATE conversations 
            SET system_prompt = ?, updated_at = ? 
            WHERE id = ?
        ");
        $stmt->execute([$systemPrompt, time(), $conversationId]);

        // Also add as a message for completeness
        $this->writeMessage($conversationId, 'system', $systemPrompt);
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
        
        $messages = [];
        $systemMessage = '';
        $systemTimestamp = null;
        $userMessages = [];
        $assistantMessages = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['role'] === 'system') {
                $systemMessage = 'System: ' . $row['content'];
                $systemTimestamp = $row['created_at'];
            } elseif ($row['role'] === 'user') {
                $userMessages[] = ['content' => $row['content'], 'timestamp' => $row['created_at']];
            } elseif ($row['role'] === 'assistant') {
                $assistantMessages[] = ['content' => $row['content'], 'timestamp' => $row['created_at']];
            }
        }

        // Reconstruct alternating pattern like FileLogStorage but with timestamps
        $result = [];
        
        // Add system message combined with first user message if exists
        if ($systemMessage && !empty($userMessages)) {
            $firstUser = array_shift($userMessages);
            $result[] = [
                'content' => $systemMessage . "\n" . $firstUser['content'],
                'timestamp' => $firstUser['timestamp'],
                'role' => 'mixed'
            ];
        } elseif ($systemMessage) {
            $result[] = [
                'content' => $systemMessage,
                'timestamp' => $systemTimestamp,
                'role' => 'system'
            ];
        } elseif (!empty($userMessages)) {
            $firstUser = array_shift($userMessages);
            $result[] = [
                'content' => $firstUser['content'],
                'timestamp' => $firstUser['timestamp'],
                'role' => 'user'
            ];
        }
        
        // Alternate between remaining user and assistant messages
        $maxMessages = max(count($userMessages), count($assistantMessages));
        for ($i = 0; $i < $maxMessages; $i++) {
            if (isset($assistantMessages[$i])) {
                $result[] = [
                    'content' => $assistantMessages[$i]['content'],
                    'timestamp' => $assistantMessages[$i]['timestamp'],
                    'role' => 'assistant'
                ];
            }
            if (isset($userMessages[$i])) {
                $result[] = [
                    'content' => $userMessages[$i]['content'],
                    'timestamp' => $userMessages[$i]['timestamp'],
                    'role' => 'user'
                ];
            }
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

    private function updateConversationTags($conversationId) {
        // Get all messages in conversation
        $stmt = $this->db->prepare("
            SELECT content, role FROM messages 
            WHERE conversation_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $tags = $this->detectTags($messages);
        $tagsString = implode(',', $tags);
        
        // Update conversation with tags
        $updateStmt = $this->db->prepare("
            UPDATE conversations SET tags = ? WHERE id = ?
        ");
        $updateStmt->execute([$tagsString, $conversationId]);
    }
    
    private function detectTags($messages) {
        $tags = [];
        $allContent = '';
        $hasCode = false;
        
        foreach ($messages as $msg) {
            $content = $msg['content'];
            $allContent .= ' ' . $content;
            
            // Check for code blocks
            if (preg_match('/```/', $content)) {
                $hasCode = true;
            }
            
            // Check for inline code
            if (preg_match('/`[^`]+`/', $content)) {
                $hasCode = true;
            }
        }
        
        // Tag for code
        if ($hasCode) {
            $tags[] = 'code';
        }
        
        // Language detection (simple English vs non-English)
        if ($this->isEnglishText($allContent)) {
            $tags[] = 'english';
        } else {
            $tags[] = 'non-english';
        }
        
        return $tags;
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
            $whereClauses[] = "(m.content LIKE ? OR c.system_prompt LIKE ?)";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
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

    public function __destruct() {
        $this->db = null;
    }
}
