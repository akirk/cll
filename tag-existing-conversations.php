<?php
require_once 'LogStorage.php';

$dbPath = 'chats.sqlite';
if (!file_exists($dbPath)) {
    die("Database file not found: $dbPath\n");
}

$storage = new SQLiteLogStorage($dbPath);

// Get all conversations
$db = new PDO('sqlite:' . $dbPath);
$stmt = $db->query("SELECT id FROM conversations ORDER BY id");
$conversationIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($conversationIds) . " conversations to process.\n";

foreach ($conversationIds as $id) {
    try {
        $storage->updateConversationTags($id);
        echo "Tagged conversation $id\n";
    } catch (Exception $e) {
        echo "Error tagging conversation $id: " . $e->getMessage() . "\n";
    }
}

echo "Done! All existing conversations have been tagged.\n";
