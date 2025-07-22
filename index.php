<?php
require_once 'LogStorage.php';

$dbPath = 'chats.sqlite';
if (!file_exists($dbPath)) {
    die("Database file not found: $dbPath");
}

$storage = new SQLiteLogStorage($dbPath);

$action = $_GET['action'] ?? 'list';
$conversationId = $_GET['id'] ?? null;
$search = $_GET['search'] ?? null;
$selectedTag = $_GET['tag'] ?? null;

// Handle tag management actions
if ($_POST['tag_action'] ?? null) {
    $tagAction = $_POST['tag_action'];
    $targetConversationId = $_POST['conversation_id'] ?? null;
    
    if ($targetConversationId) {
        switch ($tagAction) {
            case 'add':
                $newTag = trim($_POST['new_tag'] ?? '');
                if ($newTag) {
                    $storage->addTagToConversation($targetConversationId, $newTag);
                }
                break;
                
            case 'remove':
                $tagToRemove = $_POST['tag_to_remove'] ?? '';
                if ($tagToRemove) {
                    $storage->removeTagFromConversation($targetConversationId, $tagToRemove);
                }
                break;
                
            case 'set':
                $newTags = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));
                $storage->setConversationTags($targetConversationId, $newTags);
                break;
        }
        
        // Redirect to prevent form resubmission
        header("Location: ?action=view&id=" . urlencode($targetConversationId));
        exit;
    }
}

function renderMarkdown($text) {
    // Add HTML comment with original markdown for debugging
    $originalText = $text;
    $debugComment = "<!-- Original markdown:\n" . htmlspecialchars($originalText) . "\n-->\n";
    
    // Keep both original and escaped versions
    $originalLines = explode("\n", $originalText);
    $escapedText = htmlspecialchars($text);
    $lines = explode("\n", $escapedText);
    
    $result = [];
    $state = [
        'inList' => false,
        'inCodeBlock' => false,
        'codeBlockLang' => '',
        'codeBlockLines' => []
    ];
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $originalLine = $originalLines[$i] ?? '';
        $trimmed = trim($line);
        
        // Check for code block delimiters (look for ``` at start of trimmed line)
        if (preg_match('/^```([a-zA-Z0-9_+-]*)\s*$/', trim($originalLine), $matches)) {
            if (!$state['inCodeBlock']) {
                // Starting code block
                if ($state['inList']) {
                    $result[] = '</ul>';
                    $state['inList'] = false;
                }
                $state['inCodeBlock'] = true;
                $state['codeBlockLang'] = $matches[1] ?? '';
                $state['codeBlockLines'] = [];
            } else {
                // Ending code block
                $lang = $state['codeBlockLang'] ? ' class="language-' . $state['codeBlockLang'] . '"' : '';
                $codeContent = implode("\n", $state['codeBlockLines']);
                $result[] = '<pre><code' . $lang . '>' . htmlspecialchars($codeContent) . '</code></pre>';
                $state['inCodeBlock'] = false;
                $state['codeBlockLang'] = '';
                $state['codeBlockLines'] = [];
            }
            continue;
        }
        
        // If inside code block, collect original lines (not HTML escaped)
        if ($state['inCodeBlock']) {
            $state['codeBlockLines'][] = $originalLine;
            continue;
        }
        
        // Process other markdown outside code blocks
        $trimmedLeft = ltrim($line);
        
        // List items
        if (preg_match('/^[-*] (.+)$/', $trimmedLeft, $matches)) {
            if (!$state['inList']) {
                $result[] = '<ul>';
                $state['inList'] = true;
            }
            $result[] = '<li>' . processInlineFormatting($matches[1]) . '</li>';
            continue;
        }
        
        // Close list if not a list item
        if ($state['inList']) {
            $result[] = '</ul>';
            $state['inList'] = false;
        }
        
        // Headers
        if (preg_match('/^(#{1,3}) (.+)$/', $trimmedLeft, $matches)) {
            $level = strlen($matches[1]);
            $result[] = '<h' . $level . '>' . processInlineFormatting($matches[2]) . '</h' . $level . '>';
            continue;
        }
        
        // Regular paragraph
        if ($trimmed !== '') {
            $result[] = '<p>' . processInlineFormatting($line) . '</p>';
        }
    }
    
    // Clean up any open states
    if ($state['inList']) {
        $result[] = '</ul>';
    }
    if ($state['inCodeBlock']) {
        // Unclosed code block - treat as text
        $result[] = '<p>```' . $state['codeBlockLang'] . '</p>';
        foreach ($state['codeBlockLines'] as $codeLine) {
            $result[] = '<p>' . processInlineFormatting(htmlspecialchars($codeLine)) . '</p>';
        }
    }
    
    return $debugComment . implode("\n", $result);
}

function processInlineFormatting($text) {
    // Process inline code first to protect it
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    
    // Bold formatting
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);
    
    // Italic formatting (avoid conflicts with bold)
    $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
    $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text);
    
    // Links
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $text);
    
    return $text;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        .search-form { margin-bottom: 20px; }
        .search-form input { padding: 8px; width: 300px; border: 1px solid #ddd; border-radius: 4px; }
        .search-form button { padding: 8px 15px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .conversation-list { list-style: none; padding: 0; }
        .conversation-item { margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
        .conversation-item h3 { margin: 0 0 8px 0; }
        .conversation-item .tags { float: right; }
        .conversation-meta { color: #666; font-size: 0.9em; margin-bottom: 8px; }
        .tags { margin-top: 8px; }
        .tag { display: inline-block; background: #e0e0e0; color: #333; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-right: 5px; text-decoration: none; }
        .tag:hover { background: #d0d0d0; }
        .tag-editor { background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 15px 0; border: 1px solid #ddd; }
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
        .message.assistant { background: #f3e5f5; border-left: 4px solid #9c27b0; }
        .message.system { background: #fff3e0; border-left: 4px solid #ff9800; }
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
        .back-link { display: inline-block; margin-bottom: 20px; padding: 8px 15px; background: #666; color: white; text-decoration: none; border-radius: 4px; }
        .nav { margin-bottom: 20px; }
        .nav a { margin-right: 15px; color: #007cba; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Chat Viewer</h1>
            <div class="nav">
                <a href="?action=list">All Conversations</a>
                <a href="?action=stats">Statistics</a>
            </div>
        </div>

        <?php if ($action === 'view' && $conversationId): ?>
            <a href="?action=list" class="back-link">← Back to List</a>
            <?php
            $metadata = $storage->getConversationMetadata($conversationId);
            $messages = $storage->loadConversation($conversationId);
            $conversationTags = $storage->getConversationTags($conversationId);
            
            if ($metadata && $messages): ?>
                <h2>Conversation #<?= htmlspecialchars($conversationId) ?></h2>
                <div class="conversation-meta">
                    Model: <?= htmlspecialchars($metadata['model']) ?> | 
                    Created: <?= date('Y-m-d H:i:s', $metadata['timestamp']) ?> | 
                    Messages: <?= count($messages) ?> | 
                    Answers: <?= $metadata['answers'] ?>
                </div>
                
                <div class="tag-editor">
                    <h4>Tags</h4>
                    <div class="tag-list">
                        <?php if (empty($conversationTags)): ?>
                            <em>No tags assigned</em>
                        <?php else: ?>
                            <?php foreach ($conversationTags as $tag): ?>
                                <span class="editable-tag">
                                    <?= htmlspecialchars($tag) ?>
                                    <span class="remove-tag-btn" onclick="removeTag('<?= htmlspecialchars($tag) ?>')">×</span>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <form class="tag-form" method="post" style="display: inline-block;">
                        <input type="hidden" name="tag_action" value="add">
                        <input type="hidden" name="conversation_id" value="<?= htmlspecialchars($conversationId) ?>">
                        <input type="text" name="new_tag" placeholder="Add new tag" required>
                        <button type="submit">Add Tag</button>
                    </form>
                    
                    <button onclick="toggleBulkEdit()" style="margin-left: 10px; padding: 6px 10px; background: #666; color: white; border: none; border-radius: 3px; cursor: pointer;">
                        Edit All Tags
                    </button>
                    
                    <form id="bulk-edit-form" class="tag-form" method="post" style="display: none; margin-top: 10px;">
                        <input type="hidden" name="tag_action" value="set">
                        <input type="hidden" name="conversation_id" value="<?= htmlspecialchars($conversationId) ?>">
                        <input type="text" name="tags" placeholder="Enter tags separated by commas" 
                               value="<?= htmlspecialchars(implode(', ', $conversationTags)) ?>" style="width: 300px;">
                        <button type="submit">Save Tags</button>
                        <button type="button" onclick="toggleBulkEdit()" style="background: #666; margin-left: 5px;">Cancel</button>
                    </form>
                </div>
                
                <!-- Hidden form for removing tags -->
                <form id="remove-tag-form" method="post" style="display: none;">
                    <input type="hidden" name="tag_action" value="remove">
                    <input type="hidden" name="conversation_id" value="<?= htmlspecialchars($conversationId) ?>">
                    <input type="hidden" name="tag_to_remove" id="tag_to_remove_input">
                </form>
                
                <script>
                function removeTag(tag) {
                    if (confirm('Remove tag "' + tag + '"?')) {
                        document.getElementById('tag_to_remove_input').value = tag;
                        document.getElementById('remove-tag-form').submit();
                    }
                }
                
                function toggleBulkEdit() {
                    var form = document.getElementById('bulk-edit-form');
                    if (form.style.display === 'none') {
                        form.style.display = 'block';
                    } else {
                        form.style.display = 'none';
                    }
                }
                </script>
                
                <div class="messages">
                    <?php foreach ($messages as $message): 
                        if (is_array($message)) {
                            $content = $message['content'];
                            $timestamp = $message['timestamp'];
                            $role = $message['role'];
                        } else {
                            // Fallback for old format
                            $content = $message;
                            $timestamp = null;
                            $role = 'unknown';
                        }
                        
                        // Handle mixed system/user message
                        if ($role === 'mixed' && strpos($content, 'System: ') === 0) {
                            $systemEnd = strpos($content, "\n");
                            if ($systemEnd !== false) {
                                $systemContent = substr($content, 8, $systemEnd - 8);
                                $userContent = substr($content, $systemEnd + 1);
                                
                                // Display system message
                                echo '<div class="message system">
                                        <div class="message-header">
                                            <div class="message-role">system</div>
                                            <div class="message-timestamp">' . ($timestamp ? date('Y-m-d H:i:s', $timestamp) : '') . '</div>
                                        </div>
                                        <div class="message-content">' . renderMarkdown($systemContent) . '</div>
                                      </div>';
                                      
                                // Display user message
                                echo '<div class="message user">
                                        <div class="message-header">
                                            <div class="message-role">user</div>
                                            <div class="message-timestamp">' . ($timestamp ? date('Y-m-d H:i:s', $timestamp) : '') . '</div>
                                        </div>
                                        <div class="message-content">' . renderMarkdown($userContent) . '</div>
                                      </div>';
                                continue;
                            }
                        }
                        
                        // Handle system message only
                        if ($role === 'system' && strpos($content, 'System: ') === 0) {
                            $systemContent = substr($content, 8);
                            echo '<div class="message system">
                                    <div class="message-header">
                                        <div class="message-role">system</div>
                                        <div class="message-timestamp">' . ($timestamp ? date('Y-m-d H:i:s', $timestamp) : '') . '</div>
                                    </div>
                                    <div class="message-content">' . renderMarkdown($systemContent) . '</div>
                                  </div>';
                            continue;
                        }
                        
                        // Regular message
                        $displayRole = $role === 'mixed' ? 'user' : $role;
                    ?>
                        <div class="message <?= htmlspecialchars($displayRole) ?>">
                            <div class="message-header">
                                <div class="message-role"><?= htmlspecialchars($displayRole) ?></div>
                                <div class="message-timestamp"><?= $timestamp ? date('Y-m-d H:i:s', $timestamp) : '' ?></div>
                            </div>
                            <div class="message-content"><?= renderMarkdown($content) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Conversation not found.</p>
            <?php endif; ?>

        <?php elseif ($action === 'stats'): ?>
            <?php
            $db = new PDO('sqlite:' . $dbPath);
            $totalConversations = $db->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
            $totalMessages = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
            $modelStats = $db->query("SELECT model, COUNT(*) as count FROM conversations GROUP BY model ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
            
            // Tag statistics
            $tagStats = [];
            $allTags = $storage->getAllTags();
            foreach ($allTags as $tag) {
                $count = $db->prepare("SELECT COUNT(*) FROM conversations WHERE tags LIKE ?");
                $count->execute(['%' . $tag . '%']);
                $tagStats[$tag] = $count->fetchColumn();
            }
            arsort($tagStats);
            
            // Day of week statistics
            $dayStats = $db->query("
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
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Hour of day statistics
            $hourStats = $db->query("
                SELECT 
                    strftime('%H', created_at, 'unixepoch') as hour,
                    COUNT(*) as count
                FROM conversations 
                GROUP BY strftime('%H', created_at, 'unixepoch')
                ORDER BY hour
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Monthly statistics (last 12 months)
            $monthStats = $db->query("
                SELECT 
                    strftime('%Y-%m', created_at, 'unixepoch') as month,
                    COUNT(*) as count
                FROM conversations 
                WHERE created_at > " . (time() - 365*24*3600) . "
                GROUP BY strftime('%Y-%m', created_at, 'unixepoch')
                ORDER BY month DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Recent activity
            $recentActivity = $db->query("
                SELECT DATE(created_at, 'unixepoch') as date, COUNT(*) as count 
                FROM conversations 
                WHERE created_at > " . (time() - 30*24*3600) . " 
                GROUP BY date 
                ORDER BY date DESC 
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <h2>Statistics</h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <div>
                    <h3>Overview</h3>
                    <p><strong>Total Conversations:</strong> <?= $totalConversations ?></p>
                    <p><strong>Total Messages:</strong> <?= $totalMessages ?></p>
                    <p><strong>Average Messages per Conversation:</strong> <?= $totalConversations > 0 ? round($totalMessages / $totalConversations, 1) : 0 ?></p>
                </div>
                
                <div>
                    <h3>Models Used</h3>
                    <?php foreach ($modelStats as $stat): ?>
                        <p><strong><?= htmlspecialchars($stat['model'] ?: 'Unknown') ?>:</strong> <?= $stat['count'] ?> conversations</p>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <div>
                    <h3>Tags</h3>
                    <?php foreach ($tagStats as $tag => $count): ?>
                        <p><strong><?= htmlspecialchars($tag) ?>:</strong> <?= $count ?> conversations</p>
                    <?php endforeach; ?>
                </div>
                
                <div>
                    <h3>Day of Week Activity</h3>
                    <?php foreach ($dayStats as $stat): ?>
                        <p><strong><?= $stat['day_name'] ?>:</strong> <?= $stat['count'] ?> conversations</p>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <div>
                    <h3>Hour of Day Activity</h3>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($hourStats as $stat): ?>
                            <p><strong><?= sprintf('%02d:00', $stat['hour']) ?>:</strong> <?= $stat['count'] ?> conversations</p>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div>
                    <h3>Monthly Activity (Last 12 Months)</h3>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($monthStats as $stat): ?>
                            <p><strong><?= $stat['month'] ?>:</strong> <?= $stat['count'] ?> conversations</p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <h3>Recent Activity (Last 30 Days)</h3>
            <div style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($recentActivity as $activity): ?>
                    <p><?= $activity['date'] ?>: <?= $activity['count'] ?> conversations</p>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <form class="search-form" method="get">
                <input type="hidden" name="action" value="list">
                <input type="text" name="search" placeholder="Search conversations..." value="<?= htmlspecialchars($search ?? '') ?>">
                
                <select name="tag" style="margin-left: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="">All Tags</option>
                    <?php 
                    $allTags = $storage->getAllTags();
                    foreach ($allTags as $tag): ?>
                        <option value="<?= htmlspecialchars($tag) ?>" <?= $selectedTag === $tag ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tag) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit">Filter</button>
                <?php if ($search || $selectedTag): ?>
                    <a href="?action=list" style="margin-left: 10px;">Clear All</a>
                <?php endif; ?>
            </form>

            <?php
            $conversations = $storage->findConversations(50, $search, $selectedTag);
            ?>
            
            <h2>
                <?php if ($search && $selectedTag): ?>
                    Search Results for "<?= htmlspecialchars($search) ?>" with tag "<?= htmlspecialchars($selectedTag) ?>"
                <?php elseif ($search): ?>
                    Search Results for "<?= htmlspecialchars($search) ?>"
                <?php elseif ($selectedTag): ?>
                    Conversations tagged "<?= htmlspecialchars($selectedTag) ?>"
                <?php else: ?>
                    Recent Conversations
                <?php endif; ?>
                (<?= count($conversations) ?>)
            </h2>
            
            <?php if (empty($conversations)): ?>
                <p>No conversations found.</p>
            <?php else: ?>
                <ul class="conversation-list">
                    <?php foreach ($conversations as $id): 
                        $metadata = $storage->getConversationMetadata($id);
                        if (!$metadata) continue;
                        
                        $messages = $storage->loadConversation($id);
                        $firstMessage = '';
                        if ($messages && !empty($messages)) {
                            $firstMsg = $messages[0];
                            if (is_array($firstMsg)) {
                                $firstMessage = $firstMsg['content'];
                            } else {
                                $firstMessage = $firstMsg;
                            }
                            // If first message contains system prompt, extract the user part
                            if (strpos($firstMessage, 'System: ') === 0) {
                                $systemEnd = strpos($firstMessage, "\n");
                                if ($systemEnd !== false) {
                                    $firstMessage = substr($firstMessage, $systemEnd + 1);
                                }
                            }
                        }
                        $preview = strlen($firstMessage) > 100 ? substr($firstMessage, 0, 100) . '...' : $firstMessage;
                    ?>
                        <li class="conversation-item">
                            <h3>Conversation #<?= $id ?></h3>
                            <div class="conversation-meta">
                                Model: <?= htmlspecialchars($metadata['model']) ?> | 
                                Created: <?= date('Y-m-d H:i:s', $metadata['timestamp']) ?> | 
                                Answers: <?= $metadata['answers'] ?> | 
                                ~<?= $metadata['word_count'] ?> words
                            </div>
                            <p><?= htmlspecialchars($preview) ?></p>
                            <?php if ($metadata['tags']): ?>
                                <div class="tags">
                                    <?php foreach (explode(',', $metadata['tags']) as $tag): 
                                        $trimmedTag = trim($tag); ?>
                                        <a href="?action=list&tag=<?= urlencode($trimmedTag) ?>" class="tag"><?= htmlspecialchars($trimmedTag) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <a href="?action=view&id=<?= $id ?>" class="conversation-link">View Conversation</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
