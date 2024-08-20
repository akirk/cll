# cll

A simple command line chatbot using [OpenAI's GPT-3](https://openai.com/blog/openai-api/) or [Ollama](https://github.com/jmorganca/ollama) (offline) that uses streaming for faster responses.

### OpenAI

If you want to use OpenAI, you need to make your [OpenAI Key](https://platform.openai.com/account/api-keys) available as an environment variable:

```bash
export OPENAI_API_KEY=sk-...
```

### Ollama

For using Ollama, it needs to be available via HTTP on localhost:11434 (that's the default).  

### Usage

With `cll -h` you'll see all available models. By default, `gpt-3.5-turbo` will be used if online. If offline, Ollama will be used, preferring `llama2`.

The script will keep your input history in a readline file `.history` (so that you can go back to old prompts using the up-key).

It will also keep the conversation history in a directory `chats/` unless you prefix your input with whitespace, in that case the message and its response won't be kept.

I recommend using an alias in your shell to have it available anywhere in your command line: `alias cll='php ~/chatgpt/cll.php'` or alternatively put the directory in your path so that the `cll` shell script can invoke php.

### Parameters

```
Usage: cll [-l] [-f] [-r [number|searchterm]] [-m model] [-s system_prompt] [-i image_file] [conversation_input]

Options:
  -l                 Resume last conversation.
  -r [number|search] Resume a previous conversation and list 'number' conversations or search them.
  -d                 Ignore the model's answer.
  -v                 Be verbose.
  -f                 Allow file system writes for suggested file content.
  -m [model]         Use a specific model. Default: gpt-4o-mini
  -i [image_file]    Add an image as input (only gpt-4o).
  -s [system_prompt] Specify a system prompt preceeding the conversation.

Arguments:
  conversation_input  Input for the first conversation.

Notes:
  - To input multiline messages, send an empty message.
  - To end the conversation, enter "bye".

Example usage:
  cll.php -l
    Resumes the last conversation.

  cll.php -ld -m llama2
    Reasks the previous question.

  cll.php -r 5
    Resume a conversation and list the last 5 to choose from.

  cll.php -r hello
    Resume a conversation and list the last 10 containing "hello" to choose from.

  cll.php -s "Only respond in emojis"
    Have an interesting conversation 🙂

  cll.php Tell me a joke
    Starts a new conversation with the given message.

  cll.php -m gpt-3.5-turbo-16k
    Use a ChatGPT model with 16k tokens instead of 4k.
Supported modes: gpt-4o-mini, gemma2:latest, llama3:latest, llama2:latest, gpt-3.5-turbo, gpt-3.5-turbo-16k, gpt-4o, qwen:0.5b, mistral:latest
```
