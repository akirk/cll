# cll

A simple command line chatbot using [OpenAI's GPT-3](https://openai.com/blog/openai-api/), [Anthropic's Claude](https://www.anthropic.com/), or [Ollama](https://github.com/jmorganca/ollama) (offline) that uses streaming for faster responses.

### OpenAI

If you want to use OpenAI, you need to make your [OpenAI Key](https://platform.openai.com/account/api-keys) available as an environment variable:

```bash
export OPENAI_API_KEY=sk-...
```

### Anthropic

If you want to use Claude, you need to make your [Anthropic API Key](https://console.anthropic.com/) available as an environment variable:

```bash
export ANTHROPIC_API_KEY=sk-...
```

### Ollama

For using Ollama, it needs to be available via HTTP on localhost:11434 (that's the default).  

### Usage

With `cll -h` you'll see all available models. By default, `gpt-4o-mini` will be used if available. For offline usage with Ollama, the preference order is: `gemma3` > `llama3` > `llama2`.

The script will keep your input history in a readline file `.history` (so that you can go back to old prompts using the up-key).

It will also keep the conversation history in a directory `chats/` unless you prefix your input with whitespace, in that case the message and its response won't be kept.

I recommend using an alias in your shell to have it available anywhere in your command line: `alias cll='php ~/chatgpt/cll.php'` or alternatively put the directory in your path so that the `cll` shell script can invoke php.

### Web Interface

The project now includes a web interface accessible via `index.php` or by using the `-w` option. Features include:

- **Browse conversations**: View and manage your conversation history through a web browser
- **Enhanced markdown rendering**: Messages are rendered with proper markdown formatting using the Parsedown library
- **View markdown button**: Toggle between rendered and raw markdown source for messages
- **Conversation search**: Search through your conversation history
- **Tag management**: Organize conversations with tags for better organization

### Parameters

```
Usage: cll [-l] [-f] [-r [number|searchterm]] [-m model] [-s system_prompt] [-i input_file_s] [-p picture_file] [-w port|--webui] [conversation_input]

Options:
  -l                 Resume last conversation.
  -r number|search   Resume a previous conversation and list 'number' conversations or search them.
  -d                 Ignore the model's last answer. Useful when combining with -l to ask the question to another model.
  -v                 Be verbose.
  -f                 Allow file system writes for suggested file content by the AI.
  -m [model]         Use a specific model. Default: gpt-4o-mini-2024-07-18
  -i input_file(s)   Read these files and add them to the prompt.
  -p picture_file    Add an picture as input (only gpt-4o).
  -s system_prompt   Specify a system prompt preceeding the conversation.
  -w port|--webui    Launch web UI on specified port (default: 8080) and open browser.

Arguments:
  conversation_input  Input for the first conversation.

Notes:
  - To input multiline messages, send an empty message.
  - To end the conversation, enter "bye".

Example usage:
  cll -l
    Resumes the last conversation.

  cll -ld -m llama2
    Reasks the previous question.

  cll -r 5
    Resume a conversation and list the last 5 to choose from.

  cll -r hello
    Resume a conversation and list the last 10 containing "hello" to choose from.

  cll -s "Only respond in emojis"
    Have an interesting conversation 🙂

  cll Tell me a joke
    Starts a new conversation with the given message.

  cll -m gpt-3.5-turbo-16k
    Use a ChatGPT model with 16k tokens instead of 4k.
Supported modes: gpt-4o-mini, claude-3-5-sonnet-20241022, claude-3-5-haiku-20241022, claude-3-opus-20240229, gemma2:latest, llama3:latest, llama2:latest, gpt-3.5-turbo, gpt-3.5-turbo-16k, gpt-4o, qwen:0.5b, mistral:latest
```
