<?php

class ApiClient {
	private $openaiKey;
	private $anthropicKey;
	private $supportedModels;
	private $ch;

	public function __construct() {
		$this->openaiKey = getenv( 'OPENAI_API_KEY', true );
		$this->anthropicKey = getenv( 'ANTHROPIC_API_KEY', true );
		$this->loadSupportedModels();
		$this->initializeCurl();
	}

	private function loadSupportedModels() {
		$supportedModelsFile = __DIR__ . '/../supported-models.json';
		if ( file_exists( $supportedModelsFile ) ) {
			$this->supportedModels = json_decode( file_get_contents( $supportedModelsFile ), true );
			if ( ! is_array( $this->supportedModels ) ) {
				$this->supportedModels = array();
			}
		} else {
			$this->supportedModels = array();
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
			case 'Ollama (local)':
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
		}

		if ( $provider === 'OpenAI' ) {
			$wrapper['stream_options'] = array(
				'include_usage' => true,
			);
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
			case 'Ollama (local)':
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
			case 'Ollama (local)':
				// No authentication needed for local Ollama
				break;
		}

		return $headers;
	}

	public function calculateCost( $model, $inputTokens, $outputTokens, $cacheReadTokens = 0, $cacheWriteTokens = 0 ) {
		static $pricingData = null;

		// Load pricing data once
		if ( $pricingData === null ) {
			$pricingFile = __DIR__ . '/../pricing.json';
			if ( file_exists( $pricingFile ) ) {
				$pricingJson = file_get_contents( $pricingFile );
				$pricingData = json_decode( $pricingJson, true );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					error_log( 'Invalid pricing.json format: ' . json_last_error_msg() );
					$pricingData = false;
				}
			} else {
				error_log( 'pricing.json file not found: ' . $pricingFile );
				$pricingData = false;
			}
		}

		// Return 0 if pricing data couldn't be loaded
		if ( ! $pricingData || ! isset( $pricingData['models'] ) ) {
			return 0;
		}

		// Find the best matching pricing
		$modelPricing = null;
		foreach ( $pricingData['models'] as $priceModel => $prices ) {
			if ( strpos( $model, $priceModel ) !== false ) {
				$modelPricing = $prices;
				break;
			}
		}

		// Return 0 for unknown models
		if ( ! $modelPricing ) {
			return 0;
		}

		$perTokens = $pricingData['per_tokens'] ?? 1000000;

		$cost = 0;
		$cost += ( $inputTokens / $perTokens ) * $modelPricing['input'];
		$cost += ( $outputTokens / $perTokens ) * $modelPricing['output'];

		if ( isset( $modelPricing['cache_read'] ) && $cacheReadTokens > 0 ) {
			$cost += ( $cacheReadTokens / $perTokens ) * $modelPricing['cache_read'];
		}

		if ( isset( $modelPricing['cache_write'] ) && $cacheWriteTokens > 0 ) {
			$cost += ( $cacheWriteTokens / $perTokens ) * $modelPricing['cache_write'];
		}

		return $cost;
	}

	public function __destruct() {
		if ( $this->ch ) {
			curl_close( $this->ch );
		}
	}
}