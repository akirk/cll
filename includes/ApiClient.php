<?php

class ApiClient {
	private $openaiKey;
	private $anthropicKey;
	private $supportedModels;
	private $ch;
	private $logStorage;

	public function __construct( $logStorage = null ) {
		$this->openaiKey = getenv( 'OPENAI_API_KEY', true );
		$this->anthropicKey = getenv( 'ANTHROPIC_API_KEY', true );
		$this->logStorage = $logStorage;
		$this->loadSupportedModels();
		$this->loadOllamaModels();
		$this->initializeCurl();
	}

	private function loadSupportedModels() {
		$this->supportedModels = array();

		// Load from SQLite
		if ( $this->logStorage && method_exists( $this->logStorage, 'getModels' ) ) {
			$models = $this->logStorage->getModels();
			foreach ( $models as $modelData ) {
				$this->supportedModels[ $modelData['model'] ] = $modelData['provider'];
			}
		}
	}

	private function loadOllamaModels() {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'http://localhost:11434/api/tags' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 2 );
		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( $httpCode === 200 && $response ) {
			$ollama_models = json_decode( $response, true );
			if ( isset( $ollama_models['models'] ) ) {
				foreach ( $ollama_models['models'] as $m ) {
					$modelName = $m['name'];
					$this->supportedModels[ $modelName ] = 'Ollama';

					// Cache in SQLite if available
					if ( $this->logStorage && method_exists( $this->logStorage, 'upsertModel' ) ) {
						$this->logStorage->upsertModel( $modelName, 'Ollama', true );
					}
				}
			}
		}
	}

	private function initializeCurl() {
		$this->ch = curl_init();
		curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
	}

	public function getSupportedModels() {
		return $this->supportedModels;
	}

	public function getModelProvider( $model ) {
		return isset( $this->supportedModels[ $model ] ) ? $this->supportedModels[ $model ] : null;
	}

	public function hasApiKey( $provider ) {
		switch ( $provider ) {
			case 'OpenAI':
				return ! empty( $this->openaiKey );
			case 'Anthropic':
				return ! empty( $this->anthropicKey );
			case 'Ollama':
				return true; // Always available locally
			default:
				return false;
		}
	}

	public function getApiConfiguration() {
		return array(
			'openai_available'    => ! empty( $this->openaiKey ),
			'anthropic_available' => ! empty( $this->anthropicKey ),
			'supported_models'    => $this->supportedModels,
		);
	}

	public function getSecureApiConfiguration() {
		// Return configuration without exposing actual API keys
		$config = $this->getApiConfiguration();
		if ( $config['openai_available'] ) {
			$config['openai_key_preview'] = 'sk-...' . substr( $this->openaiKey, -4 );
		}
		if ( $config['anthropic_available'] ) {
			$config['anthropic_key_preview'] = 'sk-...' . substr( $this->anthropicKey, -4 );
		}
		return $config;
	}

	public function prepareApiRequest( $model, $messages, $systemPrompt = null ) {
		$provider = $this->getModelProvider( $model );
		if ( ! $provider ) {
			throw new Exception( "Unsupported model: {$model}" );
		}

		if ( ! $this->hasApiKey( $provider ) ) {
			throw new Exception( "API key not available for provider: {$provider}" );
		}

		$wrapper = array(
			'model'  => $model,
			'stream' => true,
		);

		// Handle system prompt based on provider
		if ( $systemPrompt ) {
			if ( $provider === 'Anthropic' ) {
				$wrapper['system'] = $systemPrompt;
			} else {
				// For OpenAI and others, add system message to messages array
				array_unshift(
					$messages,
					array(
						'role'    => 'system',
						'content' => $systemPrompt,
					)
				);
			}
		}

		$wrapper['messages'] = $messages;

		// Provider-specific configurations
		if ( $provider === 'Anthropic' ) {
			$wrapper['max_tokens'] = 3200;

			// Enable thinking for Claude models that support it
			// Claude 3.7 Sonnet and later support extended thinking
			if ( strpos( $model, 'claude-3-7' ) !== false ||
			     strpos( $model, 'claude-3-opus' ) !== false ||
			     strpos( $model, 'claude-3-5-sonnet' ) !== false ) {
				$wrapper['thinking'] = array(
					'type' => 'enabled',
					'budget_tokens' => 10000
				);
			}
		}

		if ( $provider === 'OpenAI' ) {
			$wrapper['stream_options'] = array(
				'include_usage' => true,
			);

			// For o1/o3 reasoning models, set reasoning_effort if available
			if ( strpos( $model, 'o1' ) !== false || strpos( $model, 'o3' ) !== false ) {
				// o1/o3 models automatically provide reasoning, no special config needed
				// The reasoning_content will be in the delta
			}
		}

		// Ollama also supports thinking through their OpenAI-compatible API
		if ( $provider === 'Ollama (local)' ) {
			// Some Ollama models support thinking, it will be detected in the response
		}

		return array(
			'url'     => $this->getApiUrl( $provider ),
			'headers' => $this->getApiHeaders( $provider ),
			'data'    => $wrapper,
		);
	}

	private function getApiUrl( $provider ) {
		switch ( $provider ) {
			case 'OpenAI':
				return 'https://api.openai.com/v1/chat/completions';
			case 'Anthropic':
				return 'https://api.anthropic.com/v1/messages';
			case 'Ollama':
				return 'http://localhost:11434/v1/chat/completions';
			default:
				throw new Exception( "Unknown provider: {$provider}" );
		}
	}

	private function getApiHeaders( $provider ) {
		$headers = array(
			'Content-Type: application/json',
			'Transfer-Encoding: chunked',
		);

		switch ( $provider ) {
			case 'OpenAI':
				$headers[] = 'Authorization: Bearer ' . $this->openaiKey;
				break;
			case 'Anthropic':
				$headers[] = 'x-api-key: ' . $this->anthropicKey;
				$headers[] = 'anthropic-version: 2023-06-01';
				break;
			case 'Ollama':
				// No authentication needed for local Ollama
				break;
		}

		return $headers;
	}

	public function calculateCost( $model, $inputTokens, $outputTokens, $cacheReadTokens = 0, $cacheWriteTokens = 0 ) {
		// Get pricing from SQLite
		$modelPricing = null;
		$perTokens = 1000000;

		if ( $this->logStorage && method_exists( $this->logStorage, 'getModelPricing' ) ) {
			$pricing = $this->logStorage->getModelPricing( $model );
			if ( $pricing && $pricing['input_price'] !== null ) {
				$modelPricing = $pricing;
				$perTokens = $pricing['per_tokens'] ?? 1000000;
			}
		}

		// Return 0 for unknown models
		if ( ! $modelPricing ) {
			return 0;
		}

		$cost = 0;
		$inputPrice = $modelPricing['input_price'] ?? $modelPricing['input'] ?? 0;
		$outputPrice = $modelPricing['output_price'] ?? $modelPricing['output'] ?? 0;
		$cacheReadPrice = $modelPricing['cache_read_price'] ?? $modelPricing['cache_read'] ?? null;
		$cacheWritePrice = $modelPricing['cache_write_price'] ?? $modelPricing['cache_write'] ?? null;

		$cost += ( $inputTokens / $perTokens ) * $inputPrice;
		$cost += ( $outputTokens / $perTokens ) * $outputPrice;

		if ( $cacheReadPrice !== null && $cacheReadTokens > 0 ) {
			$cost += ( $cacheReadTokens / $perTokens ) * $cacheReadPrice;
		}

		if ( $cacheWritePrice !== null && $cacheWriteTokens > 0 ) {
			$cost += ( $cacheWriteTokens / $perTokens ) * $cacheWritePrice;
		}

		return $cost;
	}

	public function updateModelsFromApis() {
		if ( ! $this->logStorage || ! method_exists( $this->logStorage, 'upsertModel' ) ) {
			return false;
		}

		$counts = array(
			'OpenAI'    => 0,
			'Anthropic' => 0,
			'Ollama'    => 0,
		);

		// Update OpenAI models
		if ( ! empty( $this->openaiKey ) ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/models' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt(
				$ch,
				CURLOPT_HTTPHEADER,
				array(
					'Content-Type: application/json',
					'Authorization: Bearer ' . $this->openaiKey,
				)
			);
			$response = curl_exec( $ch );

			$data = json_decode( $response, true );
			if ( isset( $data['data'] ) ) {
				foreach ( $data['data'] as $model ) {
					if ( preg_match( '/^(gpt-\d|o\d-)/', $model['id'] ) && false === strpos( $model['id'], 'preview' ) ) {
						$this->logStorage->upsertModel( $model['id'], 'OpenAI', false );
						$counts['OpenAI']++;
					}
				}
			}
		}

		// Update Anthropic models (they don't have a models list API, so we'll add known models)
		if ( ! empty( $this->anthropicKey ) ) {
			$anthropicModels = array(
				'claude-opus-4-1-20250805',
				'claude-opus-4-20250514',
				'claude-sonnet-4-20250514',
				'claude-3-7-sonnet-20250219',
				'claude-3-5-sonnet-20241022',
				'claude-3-5-haiku-20241022',
				'claude-3-5-sonnet-20240620',
				'claude-3-haiku-20240307',
				'claude-3-opus-20240229',
			);
			foreach ( $anthropicModels as $model ) {
				$this->logStorage->upsertModel( $model, 'Anthropic', false );
				$counts['Anthropic']++;
			}
		}

		// Reload models after update
		$this->loadSupportedModels();
		$this->loadOllamaModels();

		// Count Ollama models
		foreach ( $this->supportedModels as $model => $provider ) {
			if ( $provider === 'Ollama' ) {
				$counts['Ollama']++;
			}
		}

		return $counts;
	}
}