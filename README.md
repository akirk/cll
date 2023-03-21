# cli-chatgpt

To use this, make your [OpenAI Key](https://platform.openai.com/account/api-keys) available as an environment variable:

```bash
export OPENAI_API_KEY=sk-...
```

Then run the script:

```bash
php chat.php
```

It will keep your input history in a readline file `.history` (so that you can go back to old prompts using the up-key).

It will also keep the conversation history in a file `chat-history.txt` unless you prefix your input with whitespace, in that case the message and its response won't be kept.
