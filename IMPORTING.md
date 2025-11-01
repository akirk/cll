# Importing Conversations

This tool supports importing conversations from various external sources (ChatGPT, Claude.ai, etc.) into the SQLite database.

## Importing External Conversation JSON

Both ChatGPT and Claude.ai allow you to export individual conversations as JSON files. You can import these into your local chat database.

### Usage

```bash
php import-chatgpt-json.php [options] <json_file>
```

### Options

- `-h, --help` - Show help message
- `-d, --dry-run` - Preview what would be imported without actually importing
- `-m, --model MODEL` - Specify which model to assign to the conversation (default: auto-detect)
- `-s, --sqlite FILE` - Specify a custom SQLite database path (default: chats.sqlite)
- `-y, --yes` - Skip confirmation prompts (non-interactive mode)

### Examples

**Import a single conversation:**
```bash
php import-chatgpt-json.php ~/Downloads/conversation.json
```

**Preview before importing:**
```bash
php import-chatgpt-json.php --dry-run ~/Downloads/conversation.json
```

**Import to a custom database:**
```bash
php import-chatgpt-json.php -s custom.sqlite ~/Downloads/conversation.json
```

**Specify the model manually:**
```bash
php import-chatgpt-json.php -m claude-3-5-sonnet-20241022 ~/Downloads/conversation.json
```

**Non-interactive mode (skip model confirmation):**
```bash
php import-chatgpt-json.php -y ~/Downloads/conversation.json
```

### What Gets Imported

The importer extracts:
- **Conversation name** - Used as metadata
- **Messages** - Both user and assistant messages with proper roles
- **Timestamps** - Original conversation timestamps are preserved
- **Model information** - Auto-detects ChatGPT or Claude model, or you can specify manually
- **Content** - Text content and code blocks are properly formatted

### Model Detection

The importer will:
1. Try to detect the model from metadata (ChatGPT exports include model information)
2. Analyze the content to identify Claude conversations (markdown formatting patterns)
3. Prompt you to confirm or change the detected model (in interactive mode)
4. Use a default if detection fails (gpt-4)

### Supported JSON Formats

The importer supports both **ChatGPT** and **Claude.ai** export formats:

**Common fields:**
- `name` - Conversation title
- `created_at` - ISO 8601 timestamp
- `chat_messages` - Array of message objects

**Message format:**
- `sender` - "human" or "assistant"
- `content` or `text` - Message content (can be string or array of content blocks)
- `created_at` - Message timestamp

The importer automatically detects whether the conversation is from ChatGPT or Claude based on the format and content.

### After Import

After importing, you can:
- View the conversation in the web UI at `http://localhost:8381`
- Resume the conversation from CLI with `-r <conversation_id>`
- Search for the imported conversation by content or tags

## Importing Legacy File-Based Conversations

If you have conversations stored in the old file-based format (in `chats/` directory), use:

```bash
php import-conversations.php [options]
```

See `import-conversations.php --help` for details.
