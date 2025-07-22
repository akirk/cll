<?php

abstract class LogStorage {
    abstract public function initializeConversation($conversationId, $model);
    abstract public function writeSystemPrompt($conversationId, $systemPrompt);
    abstract public function writeUserMessage($conversationId, $message);
    abstract public function writeAssistantMessage($conversationId, $message);
    abstract public function loadConversation($conversationId);
    abstract public function findConversations($limit = 10, $search = null);
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

    public function initializeConversation($conversationId, $model) {
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

    public function writeUserMessage($conversationId, $message) {
        $fp = $this->getFileHandle($conversationId);
        if (false === strpos($message, PHP_EOL)) {
            fwrite($fp, '> ' . $message . PHP_EOL . PHP_EOL);
        } else {
            fwrite($fp, '>>> ' . $message . PHP_EOL . '.' . PHP_EOL . PHP_EOL);
        }
    }

    public function writeAssistantMessage($conversationId, $message) {
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

    public function findConversations($limit = 10, $search = null) {
        $historyFiles = [];
        $time = time();
        
        for ($i = 0; $i > -300; $i -= 20) {
            $pattern = $this->baseDirectory . '/' . date('Y/m', $time + $i) . '/history.*';
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
        return array_slice(array_keys($historyFiles), 0, $limit);
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
                id TEXT PRIMARY KEY,
                model TEXT NOT NULL,
                system_prompt TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id TEXT NOT NULL,
                role TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'system')),
                content TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id)
            )
        ");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_conversations_created ON conversations(created_at DESC)");
    }

    public function initializeConversation($conversationId, $model) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO conversations (id, model, created_at, updated_at) 
            VALUES (?, ?, ?, ?)
        ");
        $time = time();
        $stmt->execute([$conversationId, $model, $time, $time]);
        return $conversationId;
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

    public function writeUserMessage($conversationId, $message) {
        $this->writeMessage($conversationId, 'user', $message);
    }

    public function writeAssistantMessage($conversationId, $message) {
        $this->writeMessage($conversationId, 'assistant', $message);
    }

    private function writeMessage($conversationId, $role, $content) {
        $stmt = $this->db->prepare("
            INSERT INTO messages (conversation_id, role, content, created_at) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conversationId, $role, $content, time()]);

        // Update conversation timestamp
        $updateStmt = $this->db->prepare("
            UPDATE conversations SET updated_at = ? WHERE id = ?
        ");
        $updateStmt->execute([time(), $conversationId]);
    }

    public function loadConversation($conversationId) {
        $stmt = $this->db->prepare("
            SELECT role, content 
            FROM messages 
            WHERE conversation_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversationId]);
        
        $messages = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['role'] === 'system') {
                // Prepend system message in the expected format
                $messages[] = 'System: ' . $row['content'];
            } else {
                $messages[] = $row['content'];
            }
        }

        return empty($messages) ? null : $messages;
    }

    public function findConversations($limit = 10, $search = null) {
        $sql = "
            SELECT DISTINCT c.id
            FROM conversations c
        ";
        
        $params = [];
        if ($search) {
            $sql .= " 
                LEFT JOIN messages m ON c.id = m.conversation_id
                WHERE m.content LIKE ? OR c.system_prompt LIKE ?
            ";
            $searchParam = '%' . $search . '%';
            $params = [$searchParam, $searchParam];
        }
        
        $sql .= " ORDER BY c.updated_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $conversations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $conversations[] = $row['id'];
        }

        return $conversations;
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
            'file_path' => null // Not applicable for SQLite
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

    public function __destruct() {
        $this->db = null;
    }
}