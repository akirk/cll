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
$systemPromptId = $_GET['prompt_id'] ?? null;

// Handle AJAX request for loading more conversations
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_more') {
    header('Content-Type: application/json');

    $offset = intval($_GET['offset'] ?? 0);
    $limit = 10;
    $search = $_GET['search'] ?? null;
    $selectedTag = $_GET['tag'] ?? null;

    $conversations = $storage->findConversations($limit, $search, $selectedTag, $offset);
    $html = '';

    foreach ($conversations as $id) {
        $html .= renderConversationItem($storage, $id);
    }

    echo json_encode([
        'html' => $html,
        'hasMore' => count($conversations) === $limit
    ]);
    exit;
}

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

// Handle delete conversation action
if ($_POST['delete_action'] ?? null) {
    $deleteAction = $_POST['delete_action'];
    $targetConversationId = $_POST['conversation_id'] ?? null;

    if ($deleteAction === 'delete' && $targetConversationId) {
        if ($storage->deleteConversation($targetConversationId)) {
            // Redirect to conversation list
            header("Location: ?action=list");
            exit;
        } else {
            $deleteError = "Failed to delete conversation.";
        }
    }
}

// Handle system prompt management actions
if ($_POST['prompt_action'] ?? null) {
    $promptAction = $_POST['prompt_action'];

    switch ($promptAction) {
        case 'create':
            $name = trim($_POST['prompt_name'] ?? '');
            $prompt = trim($_POST['prompt_content'] ?? '');
            $description = trim($_POST['prompt_description'] ?? '');
            $isDefault = isset($_POST['is_default']);

            if ($name && $prompt) {
                $result = $storage->createSystemPrompt($name, $prompt, $description, $isDefault);
                if ($result) {
                    header("Location: ?action=system_prompts&success=created");
                    exit;
                } else {
                    $promptError = "Failed to create system prompt. Name might already exist.";
                }
            } else {
                $promptError = "Name and prompt content are required.";
            }
            break;

        case 'update':
            $id = intval($_POST['prompt_id'] ?? 0);
            $name = trim($_POST['prompt_name'] ?? '');
            $prompt = trim($_POST['prompt_content'] ?? '');
            $description = trim($_POST['prompt_description'] ?? '');
            $isDefault = isset($_POST['is_default']);

            if ($id && $name && $prompt) {
                $result = $storage->updateSystemPrompt($id, $name, $prompt, $description, $isDefault);
                if ($result) {
                    header("Location: ?action=system_prompts&success=updated");
                    exit;
                } else {
                    $promptError = "Failed to update system prompt. Name might already exist.";
                }
            } else {
                $promptError = "All fields are required.";
            }
            break;

        case 'delete':
            $id = intval($_POST['prompt_id'] ?? 0);
            if ($id) {
                if ($storage->deleteSystemPrompt($id)) {
                    header("Location: ?action=system_prompts&success=deleted");
                    exit;
                } else {
                    $promptError = "Failed to delete system prompt.";
                }
            }
            break;

        case 'set_default':
            $id = intval($_POST['prompt_id'] ?? 0);
            if ($id) {
                if ($storage->setDefaultSystemPrompt($id)) {
                    header("Location: ?action=system_prompts&success=default_set");
                    exit;
                } else {
                    $promptError = "Failed to set default system prompt.";
                }
            }
            break;
    }
}

function renderConversationItem($storage, $id) {
    $metadata = $storage->getConversationMetadata($id);
    if (!$metadata) return '';

    $messages = $storage->loadConversation($id);
    $firstMessage = '';
    if ($messages && !empty($messages)) {
        // Skip system message if it's the first message, show the first user message instead
        $firstUserMessage = null;
        foreach ($messages as $msg) {
            $content = is_array($msg) ? $msg['content'] : $msg;
            $role = is_array($msg) ? $msg['role'] : 'unknown';
            
            if ($role === 'user') {
                $firstUserMessage = $content;
                break;
            }
        }
        $firstMessage = $firstUserMessage ?: '';
    }
    $preview = strlen($firstMessage) > 100 ? substr($firstMessage, 0, 100) . '...' : $firstMessage;

    $html = '<li class="conversation-item">';
    $html .= '<h3><a href="?action=view&id=' . $id . '" style="text-decoration: none; color: inherit;">Conversation #' . htmlspecialchars($id) . '</a></h3>';
    $html .= '<div class="conversation-meta">';
    $html .= '<span><strong>Model:</strong> ' . htmlspecialchars($metadata['model']) . '</span>';
    $html .= '<span><strong>Created:</strong> ' . date('M j, Y g:i A', $metadata['timestamp']) . '</span>';
    $html .= '<span><strong>Answers:</strong> ' . $metadata['answers'] . '</span>';
    $html .= '<span><strong>Words:</strong> ~' . number_format($metadata['word_count']) . '</span>';
    $html .= '</div>';
    $html .= '<p>' . htmlspecialchars($preview) . '</p>';

    if ($metadata['tags']) {
        $html .= '<div class="tags">';
        foreach (explode(',', $metadata['tags']) as $tag) {
            $trimmedTag = trim($tag);
            $isSystemTag = (strpos($trimmedTag, 'system') === 0);
            $tagClass = $isSystemTag ? 'tag system-tag' : 'tag';
            $html .= '<a href="?action=list&tag=' . urlencode($trimmedTag) . '" class="' . $tagClass . '">' . htmlspecialchars($trimmedTag) . '</a>';
        }
        $html .= '</div>';
    }

    $html .= '<a href="?action=view&id=' . $id . '" class="conversation-link">View Conversation</a>';
    $html .= '</li>';

    return $html;
}

function renderMarkdown($text) {
    // Add HTML comment with original markdown for debugging
    $originalText = $text;
    $debugComment = "<!-- Original markdown:\n" . htmlspecialchars($originalText) . "\n-->\n";
    
    // Extract and protect math expressions before HTML escaping
    $mathExpressions = [];
    $mathCounter = 0;
    
    // Find and replace math expressions with placeholders
    $text = preg_replace_callback('/\\\\\[([^\]]+)\\\\\]|\\\\\(([^)]+)\\\\\)|\$\$([^$]+)\$\$|\$([^$]+)\$/', function($matches) use (&$mathExpressions, &$mathCounter) {
        $placeholder = "MATHPLACEHOLDER" . $mathCounter++;
        $mathExpressions[$placeholder] = $matches[0]; // Store the full match
        return $placeholder;
    }, $text);
    
    // Now HTML escape the text with placeholders
    $escapedText = htmlspecialchars($text);
    $lines = explode("\n", $escapedText);
    
    // We also need the original lines for code block processing
    $originalLines = explode("\n", $originalText);
    
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
        // Use the original text to detect code blocks, but work with placeholder text for content
        $originalLineForCodeCheck = $originalLines[$i] ?? '';
        if (preg_match('/^```([a-zA-Z0-9_+-]*)\s*$/', trim($originalLineForCodeCheck), $matches)) {
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
            $state['codeBlockLines'][] = $originalLineForCodeCheck;
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
    
    $finalResult = implode("\n", $result);
    
    // Debug: Add info about math expressions found
    $debugInfo = "<!-- Math expressions found: " . count($mathExpressions) . " -->\n";
    if (!empty($mathExpressions)) {
        $debugInfo .= "<!-- Placeholders: " . implode(', ', array_keys($mathExpressions)) . " -->\n";
    }
    
    // Restore math expressions
    foreach ($mathExpressions as $placeholder => $mathExpression) {
        $finalResult = str_replace($placeholder, $mathExpression, $finalResult);
    }
    
    return $debugComment . $debugInfo . $finalResult;
}

function processInlineFormatting($text) {
    // Extract and protect math expressions first
    $mathExpressions = [];
    $mathCounter = 0;
    
    // Find and replace math expressions with placeholders
    $text = preg_replace_callback('/MATHPLACEHOLDER\d+/', function($matches) use (&$mathExpressions, &$mathCounter) {
        // If it's already a placeholder, keep it as is
        return $matches[0];
    }, $text);
    
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
    <title>cll Web Interface</title>
    <link rel="stylesheet" href="katex.min.css">
    <script defer src="katex.min.js"></script>
    <script defer src="auto-render.min.js"></script>
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

        <?php if ($action === 'view' && $conversationId): ?>
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
            $metadata = $storage->getConversationMetadata($conversationId);
            $messages = $storage->loadConversation($conversationId);
            $conversationTags = $storage->getConversationTags($conversationId);
            
            if ($metadata && $messages): ?>
                <?php if (isset($deleteError)): ?>
                    <div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 15px;">
                        <?= htmlspecialchars($deleteError) ?>
                    </div>
                <?php endif; ?>
                <h2>Conversation #<?= htmlspecialchars($conversationId) ?></h2>
                <div class="conversation-meta">
                    <span><strong>Model:</strong> <?= htmlspecialchars($metadata['model']) ?></span>
                    <span><strong>Created:</strong> <?= date('M j, Y g:i A', $metadata['timestamp']) ?></span>
                    <span><strong>Messages:</strong> <?= count($messages) ?></span>
                    <span><strong>Answers:</strong> <?= $metadata['answers'] ?></span>
                    <span><strong>Words:</strong> ~<?= number_format($metadata['word_count']) ?></span>
                </div>
                
                <button class="toggle-tags-btn" onclick="toggleTagEditor()">Manage Tags</button>
                <button class="delete-btn" onclick="confirmDelete()" style="background: #dc3545; margin-left: 10px;">Delete Conversation</button>

                <!-- Display Tags -->
                <?php if (!empty($conversationTags)): ?>
                    <div style="margin: 15px 0;">
                        <strong>Tags:</strong>
                        <?php foreach ($conversationTags as $tag): 
                            $isSystemTag = (strpos($tag, 'system') === 0);
                            $tagClass = $isSystemTag ? 'tag system-tag' : 'tag';
                        ?>
                            <a href="?action=list&tag=<?= urlencode($tag) ?>" class="<?= $tagClass ?>" style="margin-left: 8px;"><?= htmlspecialchars($tag) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="tag-editor" id="tag-editor">
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
                
                <!-- Hidden form for deleting conversation -->
                <form id="delete-form" method="post" style="display: none;">
                    <input type="hidden" name="delete_action" value="delete">
                    <input type="hidden" name="conversation_id" value="<?= htmlspecialchars($conversationId) ?>">
                </form>

                <script>
                function removeTag(tag) {
                    if (confirm('Remove tag "' + tag + '"?')) {
                        document.getElementById('tag_to_remove_input').value = tag;
                        document.getElementById('remove-tag-form').submit();
                    }
                }
                
                function toggleTagEditor() {
                    var editor = document.getElementById('tag-editor');
                    editor.classList.toggle('visible');
                }

                function toggleBulkEdit() {
                    var form = document.getElementById('bulk-edit-form');
                    if (form.style.display === 'none') {
                        form.style.display = 'block';
                    } else {
                        form.style.display = 'none';
                    }
                }

                function confirmDelete() {
                    if (confirm('Are you sure you want to delete this conversation? This action cannot be undone.')) {
                        document.getElementById('delete-form').submit();
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
                        
                        // Regular message - no special handling needed
                        $displayRole = $role;
                        $isSystemMessage = ($role === 'system');
                    ?>
                        <div class="message <?= htmlspecialchars($displayRole) ?>">
                            <div class="message-header" <?= $isSystemMessage ? 'style="cursor: pointer;" onclick="toggleSystemPrompt(this)"' : '' ?>>
                                <div class="message-role">
                                    <?= htmlspecialchars($displayRole) ?>
                                    <?= $isSystemMessage ? ' <span style="font-size: 0.8em; color: #666;">(click to expand)</span>' : '' ?>
                                </div>
                                <div class="message-timestamp"><?= $timestamp ? date('Y-m-d H:i:s', $timestamp) : '' ?></div>
                            </div>
                            <div class="message-content" <?= $isSystemMessage ? 'style="display: none;"' : '' ?>><?= renderMarkdown($content) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <script>
                function toggleSystemPrompt(header) {
                    const content = header.nextElementSibling;
                    const roleElement = header.querySelector('.message-role');
                    
                    if (content.style.display === 'none') {
                        content.style.display = 'block';
                        roleElement.innerHTML = roleElement.innerHTML.replace('(click to expand)', '(click to collapse)');
                    } else {
                        content.style.display = 'none';
                        roleElement.innerHTML = roleElement.innerHTML.replace('(click to collapse)', '(click to expand)');
                    }
                }
                </script>

                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                    <div style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px;">
                        <strong>Resume this conversation:</strong>
                        <code id="resume-command" style="display: inline-block; margin-left: 10px; padding: 4px 8px; background: #ffffff; border: 1px solid #ddd; border-radius: 3px; cursor: pointer; user-select: all;" onclick="copyResumeCommand()"><?= 'cll -r ' . htmlspecialchars($conversationId) ?></code>
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
                </script>
            <?php else: ?>
                <p>Conversation not found.</p>
                <div style="margin-top: 30px; text-align: center;">
                    <a href="?action=list" class="back-link">← Back to List</a>
                </div>
            <?php endif; ?>

        <?php elseif ($action === 'system_prompts'): ?>
            <h2>System Prompts Management</h2>

            <?php if (isset($_GET['success'])): ?>
                <div style="background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 15px;">
                    <?php
                    switch ($_GET['success']) {
                        case 'created': echo 'System prompt created successfully!'; break;
                        case 'updated': echo 'System prompt updated successfully!'; break;
                        case 'deleted': echo 'System prompt deleted successfully!'; break;
                        case 'default_set': echo 'Default system prompt set successfully!'; break;
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($promptError)): ?>
                <div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 15px;">
                    <?= htmlspecialchars($promptError) ?>
                </div>
            <?php endif; ?>

            <?php
            $systemPrompts = $storage->getAllSystemPrompts();
            $editingPrompt = null;
            if ($systemPromptId) {
                $editingPrompt = $storage->getSystemPrompt($systemPromptId);
            }
            ?>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
                <!-- Create/Edit Form -->
                <div>
                    <h3><?= $editingPrompt ? 'Edit' : 'Create' ?> System Prompt</h3>
                    <form method="post" style="background: #f8f9fa; padding: 20px; border-radius: 6px; border: 1px solid #e9ecef;">
                        <input type="hidden" name="prompt_action" value="<?= $editingPrompt ? 'update' : 'create' ?>">
                        <?php if ($editingPrompt): ?>
                            <input type="hidden" name="prompt_id" value="<?= $editingPrompt['id'] ?>">
                        <?php endif; ?>

                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Name:</label>
                            <input type="text" name="prompt_name" required
                                   value="<?= htmlspecialchars($editingPrompt['name'] ?? '') ?>"
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Description:</label>
                            <input type="text" name="prompt_description"
                                   value="<?= htmlspecialchars($editingPrompt['description'] ?? '') ?>"
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Prompt Content:</label>
                            <textarea name="prompt_content" required rows="6"
                                      style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"><?= htmlspecialchars($editingPrompt['prompt'] ?? '') ?></textarea>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label>
                                <input type="checkbox" name="is_default" <?= ($editingPrompt['is_default'] ?? false) ? 'checked' : '' ?>>
                                Set as default prompt
                            </label>
                        </div>

                        <div>
                            <button type="submit" style="padding: 8px 15px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                <?= $editingPrompt ? 'Update' : 'Create' ?> Prompt
                            </button>
                            <?php if ($editingPrompt): ?>
                                <a href="?action=system_prompts" style="margin-left: 10px; padding: 8px 15px; background: #666; color: white; text-decoration: none; border-radius: 4px;">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Prompts List -->
                <div>
                    <h3>Existing System Prompts</h3>
                    <?php if (empty($systemPrompts)): ?>
                        <p style="color: #666; font-style: italic;">No system prompts created yet.</p>
                    <?php else: ?>
                        <div style="max-height: 600px; overflow-y: auto;">
                            <?php foreach ($systemPrompts as $prompt): ?>
                                <div style="background: white; border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin-bottom: 15px; <?= $prompt['is_default'] ? 'border-left: 4px solid #007cba;' : '' ?>">
                                    <div style="display: flex; justify-content: between; align-items: flex-start; margin-bottom: 10px;">
                                        <div style="flex: 1;">
                                            <h4 style="margin: 0 0 5px 0;">
                                                <?= htmlspecialchars($prompt['name']) ?>
                                                <?php if ($prompt['is_default']): ?>
                                                    <span style="background: #007cba; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.7em; margin-left: 8px;">DEFAULT</span>
                                                <?php endif; ?>
                                            </h4>
                                            <?php if ($prompt['description']): ?>
                                                <p style="margin: 0 0 10px 0; color: #666; font-size: 0.9em;"><?= htmlspecialchars($prompt['description']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div style="margin-left: 15px;">
                                            <a href="?action=system_prompts&prompt_id=<?= $prompt['id'] ?>"
                                               style="padding: 4px 8px; background: #007cba; color: white; text-decoration: none; border-radius: 3px; font-size: 0.8em; margin-right: 5px;">Edit</a>
                                            <?php if (!$prompt['is_default']): ?>
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="prompt_action" value="set_default">
                                                    <input type="hidden" name="prompt_id" value="<?= $prompt['id'] ?>">
                                                    <button type="submit" style="padding: 4px 8px; background: #28a745; color: white; border: none; border-radius: 3px; font-size: 0.8em; cursor: pointer; margin-right: 5px;">Set Default</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this system prompt?')">
                                                <input type="hidden" name="prompt_action" value="delete">
                                                <input type="hidden" name="prompt_id" value="<?= $prompt['id'] ?>">
                                                <button type="submit" style="padding: 4px 8px; background: #dc3545; color: white; border: none; border-radius: 3px; font-size: 0.8em; cursor: pointer;">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 0.9em; white-space: pre-wrap; max-height: 150px; overflow-y: auto;">
                                        <?= htmlspecialchars($prompt['prompt']) ?>
                                    </div>
                                    <div style="margin-top: 8px; font-size: 0.8em; color: #666;">
                                        Created: <?= date('M j, Y g:i A', $prompt['created_at']) ?>
                                        <?php if ($prompt['updated_at'] != $prompt['created_at']): ?>
                                            • Updated: <?= date('M j, Y g:i A', $prompt['updated_at']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

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
            
            ?>
            
            <h2>Statistics</h2>
            
            <div style="margin-bottom: 30px;">
                <h3>Overview</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #007cba;"><?= $totalConversations ?></div>
                        <div style="color: #666;">Total Conversations</div>
                    </div>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #007cba;"><?= $totalMessages ?></div>
                        <div style="color: #666;">Total Messages</div>
                    </div>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #007cba;"><?= $totalConversations > 0 ? round($totalMessages / $totalConversations, 1) : 0 ?></div>
                        <div style="color: #666;">Avg Messages/Conv</div>
                    </div>
                </div>
            </div>
            
            <!-- Models Chart -->
            <div class="chart-container">
                <h3 class="chart-title">Models Used</h3>
                <div class="chart-wrapper">
                    <div class="chart chart-models <?= count($modelStats) > 6 ? 'chart-scrollable' : '' ?>">
                        <?php
                        $maxCount = max(array_column($modelStats, 'count'));
                        foreach ($modelStats as $stat):
                            $height = $maxCount > 0 ? ($stat['count'] / $maxCount) * 150 : 2;
                        ?>
                            <div class="chart-bar">
                                <div class="chart-bar-inner" style="height: <?= $height ?>px;"></div>
                                <div class="chart-bar-value"><?= $stat['count'] ?></div>
                                <div class="chart-bar-label"><?= htmlspecialchars($stat['model'] ?: 'Unknown') ?></div>
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
                        $maxCount = max(array_column($dayStats, 'count'));
                        foreach ($dayStats as $stat):
                            $height = $maxCount > 0 ? ($stat['count'] / $maxCount) * 150 : 2;
                        ?>
                            <div class="chart-bar">
                                <div class="chart-bar-inner" style="height: <?= $height ?>px;"></div>
                                <div class="chart-bar-value"><?= $stat['count'] ?></div>
                                <div class="chart-bar-label"><?= substr($stat['day_name'], 0, 3) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Monthly Activity Chart -->
            <div class="chart-container">
                <h3 class="chart-title">Monthly Activity (Last 12 Months)</h3>
                <div class="chart-wrapper">
                    <div class="chart <?= count($monthStats) > 8 ? 'chart-scrollable' : '' ?>">
                        <?php
                        $maxCount = max(array_column($monthStats, 'count'));
                        foreach (array_reverse($monthStats) as $stat):
                            $height = $maxCount > 0 ? ($stat['count'] / $maxCount) * 150 : 2;
                        ?>
                            <div class="chart-bar">
                                <div class="chart-bar-inner" style="height: <?= $height ?>px;"></div>
                                <div class="chart-bar-value"><?= $stat['count'] ?></div>
                                <div class="chart-bar-label"><?= $stat['month'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($tagStats)): ?>
            <div style="margin-bottom: 30px;">
                <h3>Tags</h3>
                <div class="tag-cloud">
                    <?php 
                    $maxCount = max(array_values($tagStats));
                    $minCount = min(array_values($tagStats));
                    $range = max($maxCount - $minCount, 1);
                    
                    foreach ($tagStats as $tag => $count): 
                        // Calculate size class (1-6) based on count
                        $normalized = ($count - $minCount) / $range;
                        $sizeClass = max(1, min(6, ceil($normalized * 5) + 1));
                        $isSystemTag = (strpos($tag, 'system') === 0);
                        $systemClass = $isSystemTag ? ' system-tag' : '';
                    ?>
                        <a href="?action=list&tag=<?= urlencode($tag) ?>" class="tag-cloud-item tag-cloud-size-<?= $sizeClass ?><?= $systemClass ?>" title="<?= $count ?> conversations">
                            <?= htmlspecialchars($tag) ?>
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
                        $maxCount = max(array_column($hourStats, 'count'));
                        foreach ($hourStats as $stat):
                            $height = $maxCount > 0 ? ($stat['count'] / $maxCount) * 150 : 2;
                        ?>
                            <div class="chart-bar">
                                <div class="chart-bar-inner" style="height: <?= $height ?>px;"></div>
                                <div class="chart-bar-value"><?= $stat['count'] ?></div>
                                <div class="chart-bar-label"><?= sprintf('%02d:00', $stat['hour']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
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
            $conversations = $storage->findConversations(5, $search, $selectedTag);
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
            </h2>
            
            <?php if (empty($conversations)): ?>
                <p>No conversations found.</p>
            <?php else: ?>
                <ul class="conversation-list">
                    <?php foreach ($conversations as $id): ?>
                        <?= renderConversationItem($storage, $id) ?>
                    <?php endforeach; ?>
                </ul>

                <div id="loading" class="loading hidden">Loading more conversations...</div>

                <script>
                let loading = false;
                let hasMore = true;
                let offset = 5;
                const search = <?= json_encode($search ?? '') ?>;
                const selectedTag = <?= json_encode($selectedTag ?? '') ?>;

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
                    {left: '$', right: '$', display: false},
                    {left: '$$', right: '$$', display: true}
                ],
                throwOnError: false,
                strict: false
            });
        }
    });
    </script>
</body>
</html>
