# cli-chatgpt

A simple command line chatbot using [OpenAI's GPT-3](https://openai.com/blog/openai-api/) that uses streaming for faster responses.

Before you can use this you need to make your [OpenAI Key](https://platform.openai.com/account/api-keys) available as an environment variable:

```bash
export OPENAI_API_KEY=sk-...
```

Then run the script:

```bash
php chat.php
```

It will keep your input history in a readline file `.history` (so that you can go back to old prompts using the up-key).

It will also keep the conversation history in a directory `chats/` unless you prefix your input with whitespace, in that case the message and its response won't be kept.

I recommend using an alias in your shell to have it available anywhere in your command line: `alias cgt='php ~/chatgpt/chat.php'`

### Usage

```
Usage: chat.php [-l] [-r [number]] [-s system_prompt] [conversation input]

Options:
  -l                 Resume last conversation.
  -r [number]        Resume a previous conversation and list 'number' conversations (default: 10).
  -s [system_prompt] Specify a system prompt preceeding the conversation.

Arguments:
  conversation input  Input for the first conversation.

Notes:
  - To input multiline messages, send an empty message.
  - To end the conversation, enter "bye".

Example usage:
  chat.php -l
    Resumes the last conversation.

  chat.php -r 5
    Resume a conversation and list the last 5 to choose from.

  chat.php -s "Only respond in emojis"
    Have an interesting conversation 🙂

  chat.php Tell me a joke
    Starts a new conversation with the given message.
```
