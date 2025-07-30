<?php
require_once __DIR__ . '/LogStorage.php';

class ConversationImporter {
    private $fileStorage;
    private $sqliteStorage;
    private $imported = 0;
    private $skipped = 0;
    private $errors = 0;

    public function __construct($chatsDirectory, $sqliteDbPath) {
        $this->fileStorage = new FileLogStorage($chatsDirectory);
        $this->sqliteStorage = new SQLiteLogStorage($sqliteDbPath);
    }

    public function importAll($limit = null, $dryRun = false) {
        echo "Starting conversation import" . ($dryRun ? " (dry run)" : "") . "...\n";
        
        // Find all conversations
        $conversations = $this->fileStorage->findConversations($limit ?: 10000);
        
        if (empty($conversations)) {
            echo "No conversations found to import.\n";
            return;
        }

        // Sort conversations by creation date (oldest first) so they get sequential IDs
        $conversationsWithDates = [];
        foreach ($conversations as $filePath) {
            $createdAt = $this->extractCreationDate($filePath);
            $conversationsWithDates[] = ['path' => $filePath, 'created_at' => $createdAt];
        }

        // Sort by creation date (oldest first)
        usort($conversationsWithDates, function($a, $b) {
            return $a['created_at'] <=> $b['created_at'];
        });

        echo "Found " . count($conversationsWithDates) . " conversations to process (sorted oldest first).\n\n";

        foreach ($conversationsWithDates as $conversation) {
            $this->importConversation($conversation['path'], $dryRun);
        }

        $this->printSummary();
    }

    private function importConversation($filePath, $dryRun = false) {
        try {
            // Get conversation metadata
            $metadata = $this->fileStorage->getConversationMetadata($filePath);
            if (!$metadata) {
                echo "⚠️  Skipping invalid conversation: $filePath\n";
                $this->skipped++;
                return;
            }

            // Extract creation date from filename (we'll use autoincrement for ID)
            $createdAt = $this->extractCreationDate($filePath);
            $originalId = $this->extractConversationId($filePath);

            if (!$originalId) {
                echo "⚠️  Could not extract timestamp from: $filePath\n";
                $this->skipped++;
                return;
            }

            // Load conversation messages
            $messages = $this->fileStorage->loadConversation($filePath);
            if (!$messages) {
                echo "⚠️  Could not load messages from: $filePath\n";
                $this->skipped++;
                return;
            }

            if ($dryRun) {
                echo "📄 Would import: {$originalId} ({$metadata['model']}, {$metadata['answers']} answers, {$metadata['word_count']} words) from " . date('Y-m-d H:i', $createdAt) . "\n";
                $this->imported++;
                return;
            }

            $currentTime = $createdAt;

            // Initialize conversation in SQLite with extracted creation date (let SQLite auto-assign ID)
            $newConversationId = $this->sqliteStorage->initializeConversation(null, $metadata['model'], $createdAt);

            // Import messages
            $systemPromptSet = false;
            foreach ($messages as $i => $message) {
                if ($i === 0 && substr($message, 0, 7) === 'System:') {
                    // Extract and set system prompt
                    $systemPrompt = trim(substr($message, 7));
                    $this->sqliteStorage->writeSystemPrompt($newConversationId, $systemPrompt);
                    $systemPromptSet = true;
                } elseif ($i % 2 === ($systemPromptSet ? 1 : 0)) {
                    // User message (odd index if system prompt exists, even otherwise)
                    $this->sqliteStorage->writeUserMessage($newConversationId, $message, $currentTime);
                } else {
                    // Assistant message
                    $this->sqliteStorage->writeAssistantMessage($newConversationId, $message, $currentTime);
                }
                $currentTime += 10; // Increment time by 10 seconds for each message
            }

            echo "✅ Imported: ID $newConversationId (was {$originalId}) - {$metadata['model']}, {$metadata['answers']} answers, " . date('Y-m-d H:i', $createdAt) . "\n";
            $this->imported++;

        } catch (Exception $e) {
            echo "❌ Error importing $filePath: " . $e->getMessage() . "\n";
            $this->errors++;
        }
    }

    private function extractConversationId($filePath) {
        $filename = basename($filePath);
        if (preg_match('/^history\.(\d+)\./', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extractCreationDate($filePath) {
        $filename = basename($filePath);
        if (preg_match('/^history\.(\d+)\./', $filename, $matches)) {
            return intval($matches[1]);
        }
        return time(); // fallback to current time
    }

    private function printSummary() {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "Import Summary:\n";
        echo "✅ Imported: {$this->imported}\n";
        echo "⏭️  Skipped: {$this->skipped}\n";
        echo "❌ Errors: {$this->errors}\n";
        echo str_repeat("=", 50) . "\n";
    }

    public function listConversations($format = 'table') {
        echo "Listing SQLite conversations:\n\n";
        
        $conversations = $this->sqliteStorage->findConversations(50);
        
        if (empty($conversations)) {
            echo "No conversations found in SQLite database.\n";
            return;
        }

        if ($format === 'table') {
            printf("%-12s %-15s %-8s %-8s %s\n", "ID", "Model", "Answers", "Words", "Created");
            echo str_repeat("-", 70) . "\n";
        }

        foreach ($conversations as $conversationId) {
            $metadata = $this->sqliteStorage->getConversationMetadata($conversationId);
            if (!$metadata) continue;

            $created = date('Y-m-d H:i', $metadata['timestamp']);
            
            if ($format === 'table') {
                printf("%-12s %-15s %-8d %-8d %s\n", 
                    $conversationId, 
                    substr($metadata['model'], 0, 14),
                    $metadata['answers'], 
                    $metadata['word_count'], 
                    $created
                );
            } else {
                echo "$conversationId: {$metadata['model']} ({$metadata['answers']} answers, $created)\n";
            }
        }
    }

    public function exportConversation($conversationId, $outputFile = null) {
        $messages = $this->sqliteStorage->loadConversation($conversationId);
        if (!$messages) {
            echo "Conversation not found: $conversationId\n";
            return false;
        }

        $metadata = $this->sqliteStorage->getConversationMetadata($conversationId);
        $output = "Conversation: $conversationId\n";
        $output .= "Model: {$metadata['model']}\n";
        $output .= "Created: " . date('Y-m-d H:i:s', $metadata['timestamp']) . "\n";
        $output .= str_repeat("=", 50) . "\n\n";

        foreach ($messages as $i => $message) {
            if ($i === 0 && substr($message, 0, 7) === 'System:') {
                $output .= "SYSTEM: " . substr($message, 7) . "\n\n";
            } elseif ($i % 2 === 0) {
                $output .= "USER: $message\n\n";
            } else {
                $output .= "ASSISTANT: $message\n\n";
            }
        }

        if ($outputFile) {
            file_put_contents($outputFile, $output);
            echo "Conversation exported to: $outputFile\n";
        } else {
            echo $output;
        }

        return true;
    }
}

// CLI interface
if (php_sapi_name() === 'cli') {
    $options = getopt('hdls:e:n:', ['help', 'dry-run', 'list', 'sqlite:', 'export:', 'limit:']);
    
    if (isset($options['h']) || isset($options['help'])) {
        echo "Usage: php import-conversations.php [options]\n";
        echo "\nOptions:\n";
        echo "  -h, --help          Show this help message\n";
        echo "  -d, --dry-run       Perform a dry run without importing\n";
        echo "  -l, --list          List conversations in SQLite database\n";
        echo "  -s, --sqlite FILE   SQLite database path (default: chats.sqlite)\n";
        echo "  -e, --export ID     Export specific conversation to stdout\n";
        echo "  -n, --limit N       Limit number of conversations to import\n";
        echo "\nExamples:\n";
        echo "  php import-conversations.php --dry-run\n";
        echo "  php import-conversations.php --limit 10\n";
        echo "  php import-conversations.php --list\n";
        echo "  php import-conversations.php --export 1234567890\n";
        exit(0);
    }

    $chatsDir = __DIR__ . '/chats';
    $sqliteDb = isset($options['s']) ? $options['s'] : (isset($options['sqlite']) ? $options['sqlite'] : __DIR__ . '/chats.sqlite');
    
    if (!is_dir($chatsDir)) {
        echo "Error: Chats directory not found: $chatsDir\n";
        exit(1);
    }

    try {
        $importer = new ConversationImporter($chatsDir, $sqliteDb);
        
        if (isset($options['l']) || isset($options['list'])) {
            $importer->listConversations();
        } elseif (isset($options['e']) || isset($options['export'])) {
            $conversationId = isset($options['e']) ? $options['e'] : $options['export'];
            $importer->exportConversation($conversationId);
        } else {
            $limit = isset($options['n']) ? intval($options['n']) : (isset($options['limit']) ? intval($options['limit']) : null);
            $dryRun = isset($options['d']) || isset($options['dry-run']);
            $importer->importAll($limit, $dryRun);
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
