<?php
require_once 'LogStorage.php';

$dbPath = 'chats.sqlite';
if (!file_exists($dbPath)) {
    die("Database file not found: $dbPath");
}

$storage = new SQLiteLogStorage($dbPath);

// Get all conversation IDs
$db = new PDO('sqlite:' . $dbPath);
$stmt = $db->query("SELECT id FROM conversations ORDER BY id ASC");
$conversationIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($conversationIds) . " conversations to re-tag.\n";

$count = 0;
foreach ($conversationIds as $id) {
    echo "Processing conversation $id... ";
    
    // Get all messages for this conversation
    $stmt = $db->prepare("SELECT content, role FROM messages WHERE conversation_id = ? ORDER BY created_at ASC");
    $stmt->execute([$id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($messages)) {
        echo "No messages found.\n";
        continue;
    }
    
    $tags = $storage->detectTags($messages);
    
    // Update the conversation with new tags
    $tagsString = implode(',', $tags);
    $updateStmt = $db->prepare("UPDATE conversations SET tags = ? WHERE id = ?");
    $updateStmt->execute([$tagsString, $id]);
    
    echo "Tagged with: " . ($tagsString ?: 'no tags') . "\n";
    $count++;
}

echo "\nCompleted! Re-tagged $count conversations.\n";
