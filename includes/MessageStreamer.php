<?php

class MessageStreamer {

	private bool $ansi;
	private string $old_message = '';
	private array $chunks = array();
	private $logStorage = null;
	private bool $show_thinking = false;
	private $debug_tokens_file = null;
	private bool $in_thinking = false;
	private string $thinking_content = '';
	private int $spinner_state = 0;
	private array $spinner_frames = array( '⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏' );
	private string $tag_buffer = '';
	private float $thinking_start_time = 0;
	private array $last_tokens = array();
	private string $utf8_buffer = '';
	private array $state = array(
		'maybe_bold'            => false,
		'maybe_underline'       => false,
		'maybe_underline_words' => 0,
		'maybe_space_to_tab'    => false,
		'bold'                  => false,
		'headline'              => false,
		'headline_prefix'       => false,  // true when skipping ### prefix across token boundaries
		'trimnext'              => false,
		'inline_code'           => false,
		'in_code_block'         => false,
		'code_block_start'      => false,
		'code_block_indent'     => '',    // stores the indentation (leading whitespace) of the opening ```
		'maybe_code_block_end'  => false,
		'closing_fence_indent'  => '',    // stores the indentation detected for a potential closing fence
		'pending_fence'         => '',    // stores partial ``` when split across tokens (e.g., "  `" then "``bash")
		'pending_fence_indent'  => '',    // stores the indentation before the pending fence
		'math_buffer'           => '',
		'math_type'             => false, // false, 'inline_paren', 'inline_dollar', 'display_bracket', 'display_dollar'
		'math_start_pos'        => 0,
		'pending_backslash'     => false, // true when we see a backslash at end of token
		'pending_dollar'        => false, // true when we see a dollar at end of token
	);

	public function __construct( bool $ansi = false, $logStorage = null ) {
		$this->ansi = $ansi;
		$this->logStorage = $logStorage;
	}

	private function convertLatexInnerContent( string $latex ): string {
		// Common LaTeX to Unicode conversions for inner content only
		$conversions = array(
			// Fractions - handle with and without spaces
			'/\\\\frac\s*\{([^}]+)\}\s*\{([^}]+)\}/' => '($1)/($2)',

			// Greek letters
			'/\\\\alpha/'                            => 'α',
			'/\\\\beta/'                             => 'β',
			'/\\\\gamma/'                            => 'γ',
			'/\\\\delta/'                            => 'δ',
			'/\\\\epsilon/'                          => 'ε',
			'/\\\\zeta/'                             => 'ζ',
			'/\\\\eta/'                              => 'η',
			'/\\\\theta/'                            => 'θ',
			'/\\\\iota/'                             => 'ι',
			'/\\\\kappa/'                            => 'κ',
			'/\\\\lambda/'                           => 'λ',
			'/\\\\mu/'                               => 'μ',
			'/\\\\nu/'                               => 'ν',
			'/\\\\xi/'                               => 'ξ',
			'/\\\\pi/'                               => 'π',
			'/\\\\rho/'                              => 'ρ',
			'/\\\\sigma/'                            => 'σ',
			'/\\\\tau/'                              => 'τ',
			'/\\\\upsilon/'                          => 'υ',
			'/\\\\phi/'                              => 'φ',
			'/\\\\chi/'                              => 'χ',
			'/\\\\psi/'                              => 'ψ',
			'/\\\\omega/'                            => 'ω',
			'/\\\\Gamma/'                            => 'Γ',
			'/\\\\Delta/'                            => 'Δ',
			'/\\\\Theta/'                            => 'Θ',
			'/\\\\Lambda/'                           => 'Λ',
			'/\\\\Xi/'                               => 'Ξ',
			'/\\\\Pi/'                               => 'Π',
			'/\\\\Sigma/'                            => 'Σ',
			'/\\\\Phi/'                              => 'Φ',
			'/\\\\Psi/'                              => 'Ψ',
			'/\\\\Omega/'                            => 'Ω',

			// Mathematical symbols
			'/\\\\ldots/'                            => '…',
			'/\\\\cdots/'                            => '⋯',
			'/\\\\cdot/'                             => '⋅',
			'/\\\\times/'                            => '×',
			'/\\\\div/'                              => '÷',
			'/\\\\pm/'                               => '±',
			'/\\\\mp/'                               => '∓',
			'/\\\\infty/'                            => '∞',
			'/\\\\sum/'                              => '∑',
			'/\\\\prod/'                             => '∏',
			'/\\\\int/'                              => '∫',
			'/\\\\oint/'                             => '∮',
			'/\\\\partial/'                          => '∂',
			'/\\\\nabla/'                            => '∇',
			'/\\\\sqrt\{([^}]+)\}/'                  => '√($1)',
			'/\\\\approx/'                           => '≈',
			'/\\\\equiv/'                            => '≡',
			'/\\\\neq/'                              => '≠',
			'/\\\\leq/'                              => '≤',
			'/\\\\geq/'                              => '≥',
			'/\\\\ll/'                               => '≪',
			'/\\\\gg/'                               => '≫',
			'/\\\\in/'                               => '∈',
			'/\\\\notin/'                            => '∉',
			'/\\\\subset/'                           => '⊂',
			'/\\\\supset/'                           => '⊃',
			'/\\\\subseteq/'                         => '⊆',
			'/\\\\supseteq/'                         => '⊇',
			'/\\\\cup/'                              => '∪',
			'/\\\\cap/'                              => '∩',
			'/\\\\emptyset/'                         => '∅',
			'/\\\\exists/'                           => '∃',
			'/\\\\forall/'                           => '∀',
			'/\\\\neg/'                              => '¬',
			'/\\\\land/'                             => '∧',
			'/\\\\lor/'                              => '∨',
			'/\\\\rightarrow/'                       => '→',
			'/\\\\leftarrow/'                        => '←',
			'/\\\\leftrightarrow/'                   => '↔',
			'/\\\\Rightarrow/'                       => '⇒',
			'/\\\\Leftarrow/'                        => '⇐',
			'/\\\\Leftrightarrow/'                   => '⇔',

			// Superscripts
			'/\^0/'                                  => '⁰',
			'/\^1/'                                  => '¹',
			'/\^2/'                                  => '²',
			'/\^3/'                                  => '³',
			'/\^4/'                                  => '⁴',
			'/\^5/'                                  => '⁵',
			'/\^6/'                                  => '⁶',
			'/\^7/'                                  => '⁷',
			'/\^8/'                                  => '⁸',
			'/\^9/'                                  => '⁹',
			'/\^\+/'                                 => '⁺',
			'/\^-/'                                  => '⁻',
			'/\^n/'                                  => 'ⁿ',

			// Subscripts
			'/_0/'                                   => '₀',
			'/_1/'                                   => '₁',
			'/_2/'                                   => '₂',
			'/_3/'                                   => '₃',
			'/_4/'                                   => '₄',
			'/_5/'                                   => '₅',
			'/_6/'                                   => '₆',
			'/_7/'                                   => '₇',
			'/_8/'                                   => '₈',
			'/_9/'                                   => '₉',
			'/_\+/'                                  => '₊',
			'/_-/'                                   => '₋',
		);

		// Apply conversions
		foreach ( $conversions as $pattern => $replacement ) {
			$latex = preg_replace( $pattern, $replacement, $latex );
		}

		// Clean up any remaining LaTeX commands by removing backslashes
		// But preserve single letters and variables - only remove known LaTeX commands
		$latex = preg_replace( '/\\\\(text|mathbf|mathrm|mathit|mathcal|mathfrak|operatorname)([^a-zA-Z]|$)/', '$2', $latex );

		return trim( $latex );
	}

	public function getDebugInfo(): string {
		return PHP_EOL . 'Received ' . count( $this->chunks ) . " chunks: \033[37m" . json_encode( $this->chunks ) . PHP_EOL . "\033[m";
	}

	public function getChunks(): array {
		return $this->chunks;
	}

	public function clearChunks(): void {
		$this->chunks = array();
		$this->utf8_buffer = '';
	}

	public function setLogStorage( $logStorage ): void {
		$this->logStorage = $logStorage;
	}

	public function setShowThinking( bool $show ): void {
		$this->show_thinking = $show;
	}

	public function setDebugTokens( string $file_path ): void {
		$this->debug_tokens_file = fopen( $file_path, 'w' );
	}

	public function startThinking(): void {
		$this->in_thinking = true;
		$this->thinking_start_time = microtime( true );
		// Don't clear thinking_content here - only clear when explicitly requested
		if ( $this->ansi && ! $this->show_thinking ) {
			echo "\033[90mThinking... " . $this->spinner_frames[0] . "\033[m";
		} elseif ( $this->ansi && $this->show_thinking ) {
			echo "\033[90mThinking:\033[m" . PHP_EOL;
		}
	}

	public function resetThinking(): void {
		$this->thinking_content = '';
		$this->in_thinking = false;
		$this->tag_buffer = '';
	}

	public function storeTokens( array $tokens ): void {
		$this->last_tokens = $tokens;
	}

	public function getLastTokens(): array {
		return $this->last_tokens;
	}

	public function getLastChunks(): array {
		return $this->chunks;
	}

	public function updateThinkingSpinner(): void {
		if ( ! $this->ansi || $this->show_thinking || ! $this->in_thinking ) {
			return;
		}
		$this->spinner_state = ( $this->spinner_state + 1 ) % count( $this->spinner_frames );
		$elapsed = microtime( true ) - $this->thinking_start_time;
		$elapsed_str = sprintf( '%.0fs', $elapsed );
		$word_count = str_word_count( $this->thinking_content );
		echo "\r\033[90mThinking... " . $this->spinner_frames[ $this->spinner_state ] . " ({$elapsed_str}, {$word_count} words)\033[m";
	}

	public function endThinking(): void {
		if ( $this->in_thinking ) {
			if ( $this->ansi && ! $this->show_thinking ) {
				// Clear the spinner line
				echo "\r\033[K";
			}
		}
		$this->in_thinking = false;
	}

	public function outputThinking( string $content ): void {
		$this->thinking_content .= $content;
		if ( $this->show_thinking && $this->ansi ) {
			echo "\033[90m" . $content . "\033[m";
		}
	}

	public function getThinkingContent(): string {
		return $this->thinking_content;
	}

	public function clearThinkingContent(): void {
		$this->thinking_content = '';
	}

	private function processThinkTags( &$content, &$thinking ) {
		// Buffer content to handle split tags
		$this->tag_buffer .= $content;
		$content = '';
		$output = '';

		// Check for <think> opening tag
		if ( ! $this->in_thinking && strpos( $this->tag_buffer, '<think>' ) !== false ) {
			$parts = explode( '<think>', $this->tag_buffer, 2 );
			$output .= $parts[0]; // Content before <think>
			$this->tag_buffer = isset( $parts[1] ) ? $parts[1] : '';
			$this->startThinking();
		}

		// Check for </think> closing tag
		if ( $this->in_thinking && strpos( $this->tag_buffer, '</think>' ) !== false ) {
			$parts = explode( '</think>', $this->tag_buffer, 2 );
			$thinkingText = $parts[0];
			$this->outputThinking( $thinkingText );
			$thinking .= $thinkingText;
			$this->endThinking();
			$this->tag_buffer = isset( $parts[1] ) ? $parts[1] : '';
			$output .= $this->tag_buffer;
			$this->tag_buffer = '';
		} elseif ( $this->in_thinking ) {
			// We're in thinking mode, accumulate content
			// Keep last 10 chars in buffer in case of split tag
			if ( strlen( $this->tag_buffer ) > 10 ) {
				$thinkingText = substr( $this->tag_buffer, 0, -10 );
				$this->outputThinking( $thinkingText );
				$thinking .= $thinkingText;
				$this->tag_buffer = substr( $this->tag_buffer, -10 );
				$this->updateThinkingSpinner();
			}
		} else {
			// Not in thinking mode
			// Keep last 10 chars in buffer in case of split <think> tag
			if ( strlen( $this->tag_buffer ) > 10 ) {
				$output .= substr( $this->tag_buffer, 0, -10 );
				$this->tag_buffer = substr( $this->tag_buffer, -10 );
			}
		}

		$content = $output;
	}

	public function createCurlWriteHandler( &$message, &$chunk_overflow, &$usage, $model_provider, &$thinking = '' ) {
		return function ( $curl, $data ) use ( &$message, &$chunk_overflow, &$usage, $model_provider, &$thinking ) {
			if ( 200 !== curl_getinfo( $curl, CURLINFO_HTTP_CODE ) ) {
				$error = json_decode( trim( $chunk_overflow . $data ), true );
				if ( $error ) {
					echo 'Error: ', $error['error']['message'], PHP_EOL;
				} else {
					$chunk_overflow .= $data;
				}
				return strlen( $data );
			}

			// Prepend any buffered UTF-8 bytes from previous chunk
			if ( $this->utf8_buffer !== '' ) {
				$data = $this->utf8_buffer . $data;
				$this->utf8_buffer = '';
			}

			// Check if data ends with incomplete UTF-8 sequence and buffer it
			$data_len = strlen( $data );
			if ( $data_len > 0 ) {
				$last_byte = ord( $data[ $data_len - 1 ] );
				$incomplete_bytes = 0;

				// Check for incomplete multi-byte sequence at end
				// UTF-8 sequence starters: 110xxxxx (2 bytes), 1110xxxx (3 bytes), 11110xxx (4 bytes)
				if ( $data_len >= 1 && ( $last_byte & 0xC0 ) === 0x80 ) {
					// Last byte is a continuation byte, check how many we have
					for ( $i = 1; $i <= min( 3, $data_len ); $i++ ) {
						$byte = ord( $data[ $data_len - $i ] );
						if ( ( $byte & 0xC0 ) !== 0x80 ) {
							// Found the start byte
							$expected_bytes = 0;
							if ( ( $byte & 0xE0 ) === 0xC0 ) {
								$expected_bytes = 2;
							} elseif ( ( $byte & 0xF0 ) === 0xE0 ) {
								$expected_bytes = 3;
							} elseif ( ( $byte & 0xF8 ) === 0xF0 ) {
								$expected_bytes = 4;
							}

							if ( $expected_bytes > 0 && $i < $expected_bytes ) {
								$incomplete_bytes = $i;
							}
							break;
						}
					}
				} elseif ( ( $last_byte & 0xE0 ) === 0xC0 || ( $last_byte & 0xF0 ) === 0xE0 || ( $last_byte & 0xF8 ) === 0xF0 ) {
					// Last byte is a start byte without continuation
					$incomplete_bytes = 1;
				}

				// Buffer incomplete bytes for next chunk
				if ( $incomplete_bytes > 0 ) {
					$this->utf8_buffer = substr( $data, -$incomplete_bytes );
					$data = substr( $data, 0, -$incomplete_bytes );
				}
			}

			$items = explode( 'data: ', $data );
			foreach ( $items as $item ) {
				if ( ! $item ) {
					continue;
				}
				$json = json_decode( trim( $chunk_overflow . $item ), true );
				if ( $json ) {
					$chunk_overflow = '';
				} else {
					$json = json_decode( trim( $item ), true );
				}

				if ( isset( $json['message']['usage'] ) ) {
					$usage = array_merge( $usage, $json['message']['usage'] );
				} elseif ( isset( $json['usage'] ) ) {
					$usage = array_merge( $usage, $json['usage'] );
				}

				if ( $model_provider === 'Anthropic' ) {
					// Handle thinking content blocks
					if ( isset( $json['type'] ) && $json['type'] === 'content_block_start' && isset( $json['content_block']['type'] ) && $json['content_block']['type'] === 'thinking' ) {
						$this->startThinking();
					} elseif ( isset( $json['type'] ) && $json['type'] === 'content_block_delta' && isset( $json['delta']['type'] ) && $json['delta']['type'] === 'thinking_delta' ) {
						$this->updateThinkingSpinner();
						if ( isset( $json['delta']['thinking'] ) ) {
							$this->outputThinking( $json['delta']['thinking'] );
							$thinking .= $json['delta']['thinking'];
						}
					} elseif ( isset( $json['type'] ) && $json['type'] === 'content_block_stop' && $this->in_thinking ) {
						$this->endThinking();
					} elseif ( isset( $json['delta']['text'] ) ) {
						if ( $this->debug_tokens_file ) {
							fwrite( $this->debug_tokens_file, json_encode( $json['delta']['text'] ) . PHP_EOL );
						}
						foreach ( $this->outputMessage( $json['delta']['text'] ) as $output ) {
							echo $output;
						}
						$message .= $json['delta']['text'];
					} else {
						$chunk_overflow = $item;
					}
				} else {
					// OpenAI and Ollama handling
					// Check for reasoning content (o1/o3 models)
					if ( isset( $json['choices'][0]['delta']['reasoning_content'] ) ) {
						if ( ! $this->in_thinking ) {
							$this->startThinking();
						}
						$this->updateThinkingSpinner();
						$this->outputThinking( $json['choices'][0]['delta']['reasoning_content'] );
						$thinking .= $json['choices'][0]['delta']['reasoning_content'];
					} elseif ( isset( $json['choices'][0]['delta']['content'] ) ) {
						$content = $json['choices'][0]['delta']['content'];

						// For Ollama, check for <think> tags
						if ( $model_provider === 'Ollama' ) {
							$this->processThinkTags( $content, $thinking );
						}

						if ( $this->in_thinking && $model_provider !== 'Ollama' ) {
							$this->endThinking();
						}

						// Only output non-empty content
						if ( ! empty( $content ) ) {
							if ( $this->debug_tokens_file ) {
								fwrite( $this->debug_tokens_file, json_encode( $content ) . PHP_EOL );
							}
							foreach ( $this->outputMessage( $content ) as $output ) {
								echo $output;
							}
							$message .= $content;
						}
					} else {
						$chunk_overflow = $item;
					}
				}
			}

			return strlen( $data );
		};
	}

	public function convertLatexToUnicode( string $text ): string {
		return preg_replace_callback(
			'/\\\\\[([^\]]+)\\\\\]|\\\\\(([^)]+)\\\\\)|\$\$([^$]+)\$\$|\$([^$]+)\$/',
			function ( $matches ) {
			// Find which group matched
				$latex = '';
				for ( $i = 1; $i <= 4; $i++ ) {
					if ( isset( $matches[ $i ] ) && $matches[ $i ] !== '' ) {
						$latex = $matches[ $i ];
						break;
					}
				}

		     // Convert the inner LaTeX content
				return $this->convertLatexInnerContent( $latex );
			},
			$text
		);
	}

	public function outputMessage( string $message ): \Generator {
		if ( $message === '' ) {
			$this->old_message = '';
			return;
		}

		$this->chunks[] = $message;

		$message = $this->old_message . $message;
		$i = strlen( $this->old_message );
		$this->old_message = $message;
		$length = strlen( $message );

		// Handle pending fence from previous token
		if ( $this->state['pending_fence'] !== '' ) {
			$pending = $this->state['pending_fence'];
			$pending_indent = $this->state['pending_fence_indent'];
			$this->state['pending_fence'] = '';
			$this->state['pending_fence_indent'] = '';

			// The pending fence content is already in $message (as part of old_message)
			// $i points to the start of new content, so pending starts at $i - strlen($pending)
			$fence_start = $i - strlen( $pending );

			// Check if we now have enough characters to see if it's a full ``` fence
			if ( $fence_start + 3 <= $length ) {
				// Check if this is a ``` fence
				if ( substr( $message, $fence_start, 3 ) === '```' ) {
					// This is a code block start
					$this->state['code_block_indent'] = $pending_indent;
					$this->state['code_block_start'] = true;

					// Output the opening fence
					yield '```';

					// Move past the fence (fence_start + 3)
					$i = $fence_start + 3;
				} else {
					// Not a code block, go back and process from fence_start as normal
					$i = $fence_start;
				}
			} else {
				// Still not enough characters, keep buffering
				$this->state['pending_fence'] = substr( $message, $fence_start );
				$this->state['pending_fence_indent'] = $pending_indent;
				return;
			}
		}

		while ( $i < $length ) {
			// Check for the start of a code block
			$last_php_eol = $i > 1 ? strrpos( $message, PHP_EOL, $i - $length - 1 ) : 0;
			$is_word_delimiter = strpos( PHP_EOL . ' ,;.-_!?()[]{}:', $message[ $i ] ) !== false;

			// Check for closing ``` while IN a code block - must come before the in_code_block handling
			if ( $this->state['in_code_block'] && false !== $this->state['maybe_code_block_end'] && $i > 1 && substr( $message, $i - 2, 3 ) === '```' ) {
				// Check if the indentation matches the opening fence
				// Use closing_fence_indent if available (set when indentation was tracked across token boundaries)
				$closing_indent = '';
				if ( $this->state['closing_fence_indent'] !== '' ) {
					$closing_indent = $this->state['closing_fence_indent'];
				} else {
					$prev_newline_pos = strrpos( substr( $message, 0, $this->state['maybe_code_block_end'] ), PHP_EOL );
					if ( $prev_newline_pos !== false ) {
						$closing_indent = substr( $message, $prev_newline_pos + 1, $this->state['maybe_code_block_end'] - $prev_newline_pos - 1 );
					} else {
						$closing_indent = substr( $message, 0, $this->state['maybe_code_block_end'] );
					}
				}
				// Only close if indentation matches
				if ( $closing_indent === $this->state['code_block_indent'] ) {
					if ( $this->ansi ) {
						yield "\033[m";
						// In ANSI mode, the indentation was skipped - output it now
						yield $closing_indent;
					} elseif ( $this->state['closing_fence_indent'] !== '' ) {
						// In non-ANSI mode, output the indentation (converted to tabs if even)
						$indent = $this->state['closing_fence_indent'];
						if ( strlen( $indent ) % 2 == 0 ) {
							yield str_repeat( "\t", strlen( $indent ) / 2 );
						} else {
							yield $indent;
						}
					}
					yield substr( $message, $this->state['maybe_code_block_end'], 3 );
					$this->state['in_code_block'] = false;
					$this->state['maybe_code_block_end'] = false;
					$this->state['code_block_indent'] = '';
					$this->state['closing_fence_indent'] = '';
					// $i is currently at the 3rd backtick (since we checked substr($message, $i-2, 3))
					// After incrementing, $i will point to the character after the 3rd backtick
					$i += 1;
					continue;
				}
			}

			// Check for opening ``` when NOT in a code block
			if ( ! $this->state['in_code_block'] && $i > 1 && substr( $message, $i - 2, 3 ) === '```' && trim( substr( $message, $last_php_eol, $i - $last_php_eol - 2 ) ) === '' ) {
				$this->state['code_block_start'] = true;
				// Output the opening ```
				yield substr( $message, $i - 2, 2 );
				yield $message[ $i ];
				++$i;
				continue;
			}

			// If we're in a code block, just output the text as is
			if ( $this->state['code_block_start'] ) {
				yield $message[ $i ];
				if ( $message[ $i ] === PHP_EOL ) {
					$this->state['code_block_start'] = false;
					$this->state['in_code_block'] = true;
					$this->state['maybe_space_to_tab'] = 0;
					// show in darkgrey
					if ( $this->ansi ) {
						yield "\033[90m";
					}
				}
				++$i;
				continue;
			}

			if ( $this->state['in_code_block'] ) {
				if ( $message[ $i ] === PHP_EOL ) {
					$this->state['maybe_space_to_tab'] = 0;
					yield $message[ $i++ ];
					continue;
				}

				// Check for closing fence - look ahead when processing spaces
				if ( $this->state['maybe_space_to_tab'] !== false && $this->state['maybe_space_to_tab'] > 0 && $message[ $i ] === ' ' ) {
					// We're in the middle of processing spaces - look ahead to see if ``` follows
					$remaining_spaces = 0;
					$j = $i;
					while ( $j < $length && $message[ $j ] === ' ' ) {
						$remaining_spaces++;
						$j++;
					}
					// Check if after the spaces we have ```
					if ( $j + 2 < $length && substr( $message, $j, 3 ) === '```' ) {
						// This is a closing fence! Stop space-to-tab conversion
						// Calculate total indentation: already processed spaces + remaining spaces
						$total_indent = $this->state['maybe_space_to_tab'] + ( $j - $i );
						$this->state['maybe_space_to_tab'] = false;

						// In non-ANSI mode, output the total indentation before the fence
						if ( ! $this->ansi ) {
							if ( $total_indent > 0 ) {
								if ( $total_indent % 2 == 0 ) {
									yield str_repeat( "\t", $total_indent / 2 );
								} else {
									yield str_repeat( ' ', $total_indent );
								}
							}
							// Move past all the spaces
							$i = $j;
						} else {
							// In ANSI mode, skip all the indentation
							$i = $j;
						}

						// Mark the fence start
						$this->state['maybe_code_block_end'] = $i;
						++$i;
						continue;
					}
				}

				if ( $this->state['maybe_space_to_tab'] !== false ) {
					if ( $message[ $i ] === ' ' ) {
						++$i;
						++$this->state['maybe_space_to_tab'];
						continue;
					}

					$spaces_count = $this->state['maybe_space_to_tab'];
					$this->state['maybe_space_to_tab'] = false;
					if ( $spaces_count > 0 ) {
						// Check if this is a backtick that might start a closing fence
						if ( $message[ $i ] === '`' ) {
							// Store the indent and mark potential fence start
							$this->state['closing_fence_indent'] = str_repeat( ' ', $spaces_count );
							$this->state['maybe_code_block_end'] = $i;
							++$i;
							continue;
						}
						if ( $spaces_count % 2 == 0 ) {
							yield str_repeat( "\t", $spaces_count / 2 );
						} else {
							yield str_repeat( ' ', $spaces_count );
						}
						yield $message[ $i++ ];
						continue;
					}
				}
				$this->state['maybe_space_to_tab'] = false;
				// Detect closing ``` - the first backtick must be at start of line (with possible indentation)
				if ( false === $this->state['maybe_code_block_end'] && $message[ $i ] === '`' ) {
					// Check if we're at start of line, allowing for indentation
					$at_line_start = ( $i === 0 || $message[ $i - 1 ] === PHP_EOL );
					if ( ! $at_line_start && $i > 0 ) {
						// Check if everything from last newline to here is whitespace
						$prev_newline_pos = strrpos( substr( $message, 0, $i ), PHP_EOL );
						if ( $prev_newline_pos !== false ) {
							$line_content = substr( $message, $prev_newline_pos + 1, $i - $prev_newline_pos - 1 );
							$at_line_start = ( trim( $line_content ) === '' );
						}
					}
					if ( $at_line_start ) {
						$this->state['maybe_code_block_end'] = $i;
						++$i;
						continue;
					}
				}
				// Detect second backtick of closing ```
				if ( false !== $this->state['maybe_code_block_end'] && substr( $message, $i - 1, 2 ) === '``' ) {
					++$i;
					continue;
				}
				// Reset maybe_code_block_end if we see something other than a backtick
				if ( false !== $this->state['maybe_code_block_end'] && $message[ $i ] !== '`' ) {
					// Output the indentation that was skipped (if any)
					if ( $this->state['closing_fence_indent'] !== '' ) {
						$indent = $this->state['closing_fence_indent'];
						if ( strlen( $indent ) % 2 == 0 ) {
							yield str_repeat( "\t", strlen( $indent ) / 2 );
						} else {
							yield $indent;
						}
						$this->state['closing_fence_indent'] = '';
					}
					// Output the buffered backtick(s) and reset
					for ( $j = $this->state['maybe_code_block_end']; $j < $i; $j++ ) {
						yield $message[ $j ];
					}
					$this->state['maybe_code_block_end'] = false;
				}
				yield $message[ $i++ ];
				continue;
			}

			// Math expression detection and processing (outside code blocks and inline code)
			if ( ! $this->state['in_code_block'] && ! $this->state['code_block_start'] && ! $this->state['inline_code'] ) {
				// Check for start of math expressions
				if ( ! $this->state['math_type'] ) {
					// Handle pending backslash from previous token
					if ( $this->state['pending_backslash'] ) {
						if ( $message[ $i ] === '[' ) {
							$this->state['math_type'] = 'display_bracket';
							$this->state['math_buffer'] = '\\[';
							$this->state['math_start_pos'] = $i - 1; // Account for backslash from previous token
							$this->state['pending_backslash'] = false;
							++$i;
							continue;
						} elseif ( $message[ $i ] === '(' ) {
							$this->state['math_type'] = 'inline_paren';
							$this->state['math_buffer'] = '\\(';
							$this->state['math_start_pos'] = $i - 1; // Account for backslash from previous token
							$this->state['pending_backslash'] = false;
							++$i;
							continue;
						} else {
							// Not a math delimiter, output the pending backslash
							yield '\\';
							$this->state['pending_backslash'] = false;
							// Continue processing current character
						}
					}

					// Handle pending dollar from previous token
					if ( $this->state['pending_dollar'] ) {
						if ( ctype_digit( $message[ $i ] ) ) {
							// This is likely currency like $100, not math
							yield '$';
							$this->state['pending_dollar'] = false;
							// Continue processing current character
						} else {
							// This might be math, start inline dollar mode
							$this->state['math_type'] = 'inline_dollar';
							$this->state['math_buffer'] = '$';
							$this->state['math_start_pos'] = $i - 1; // Account for dollar from previous token
							$this->state['pending_dollar'] = false;
							// Continue processing current character
						}
					}

					// Look for \[ (display math) - check substring to handle split tokens
					if ( $i < $length - 1 && substr( $message, $i, 2 ) === '\\[' ) {
						$this->state['math_type'] = 'display_bracket';
						$this->state['math_buffer'] = '\\[';
						$this->state['math_start_pos'] = $i;
						$i += 2;
						continue;
					}
					// Also check for the case where backslash is at the very end and we need to wait for next token
					if ( $i === $length - 1 && $message[ $i ] === '\\' ) {
						// We're at the end with a backslash, mark it as pending
						$this->state['pending_backslash'] = true;
						++$i;
						continue;
					}
					// Look for \( (inline math) - check substring to handle split tokens
					if ( $i < $length - 1 && substr( $message, $i, 2 ) === '\\(' ) {
						$this->state['math_type'] = 'inline_paren';
						$this->state['math_buffer'] = '\\(';
						$this->state['math_start_pos'] = $i;
						$i += 2;
						continue;
					}
					// Look for $$ (display math)
					if ( $i < $length - 1 && substr( $message, $i, 2 ) === '$$' ) {
						$this->state['math_type'] = 'display_dollar';
						$this->state['math_buffer'] = '$$';
						$this->state['math_start_pos'] = $i;
						$i += 2;
						continue;
					}
					// Look for $ (inline math) - but not if already $$ or preceded by alphanumeric characters (like "$100")
					if ( $message[ $i ] === '$' && ! ( isset( $message[ $i - 1 ] ) && $message[ $i - 1 ] === '$' ) ) {
						// Check if this looks like a currency symbol rather than math
						$nextChar = isset( $message[ $i + 1 ] ) ? $message[ $i + 1 ] : '';
						if ( ctype_digit( $nextChar ) ) {
							// This is likely currency like $100, not math
							yield $message[ $i ];
							++$i;
							continue;
						} elseif ( $i === $length - 1 ) {
							// We're at the end of the token with a dollar, mark it as pending
							$this->state['pending_dollar'] = true;
							++$i;
							continue;
						}
						$this->state['math_type'] = 'inline_dollar';
						$this->state['math_buffer'] = '$';
						$this->state['math_start_pos'] = $i;
						++$i;
						continue;
					}
				} else {
					// We're inside a math expression, buffer it
					$this->state['math_buffer'] .= $message[ $i ];

					// Check for end of math expressions using substring to handle split tokens
					$shouldClose = false;
					$bufferLen = strlen( $this->state['math_buffer'] );
					if ( $this->state['math_type'] === 'display_bracket' && $bufferLen >= 2 && substr( $this->state['math_buffer'], -2 ) === '\\]' ) {
						$shouldClose = true;
					} elseif ( $this->state['math_type'] === 'inline_paren' && $bufferLen >= 2 && substr( $this->state['math_buffer'], -2 ) === '\\)' ) {
						$shouldClose = true;
					} elseif ( $this->state['math_type'] === 'display_dollar' && $bufferLen >= 2 && substr( $this->state['math_buffer'], -2 ) === '$$' ) {
						$shouldClose = true;
					} elseif ( $this->state['math_type'] === 'inline_dollar' && $message[ $i ] === '$' ) {
						$shouldClose = true;
					}

					if ( $shouldClose ) {
						// Extract just the inner content and convert it
						$innerContent = '';
						if ( $this->state['math_type'] === 'display_bracket' ) {
							$innerContent = substr( $this->state['math_buffer'], 2, -2 ); // Remove \[ and \]
						} elseif ( $this->state['math_type'] === 'inline_paren' ) {
							$innerContent = substr( $this->state['math_buffer'], 2, -2 ); // Remove \( and \)
						} elseif ( $this->state['math_type'] === 'display_dollar' ) {
							$innerContent = substr( $this->state['math_buffer'], 2, -2 ); // Remove $$ and $$
						} elseif ( $this->state['math_type'] === 'inline_dollar' ) {
							$innerContent = substr( $this->state['math_buffer'], 1, -1 ); // Remove $ and $
						}

						// Convert the inner content
						$converted = $this->convertLatexInnerContent( $innerContent );
						yield $converted;

						// Add math tag to conversation if we have logStorage
						if ( $this->logStorage ) {
							$this->logStorage->addTag( 'math' );
						}

						// Reset math state
						$this->state['math_type'] = false;
						$this->state['math_buffer'] = '';
						$this->state['math_start_pos'] = 0;
					}

					++$i;
					continue;
				}
			}

			// Process bold and headline markers only outside code blocks and inline code
			if ( $message[ $i ] === '*' && ! $this->state['inline_code'] ) {
				// The second *.
				if ( $this->state['maybe_bold'] ) {
					$this->state['bold'] = ! $this->state['bold'];
					if ( $this->ansi ) {
						yield $this->state['bold'] ? "\033[1m" : "\033[m";
					}
					$this->state['maybe_bold'] = false;
				} elseif ( false !== $this->state['maybe_underline'] ) {
					// write the buffered word with an underline
					if ( $this->ansi ) {
						yield "\033[4m";
					}
					yield substr( $message, $this->state['maybe_underline'], $i - $this->state['maybe_underline'] );
					if ( $this->ansi ) {
						yield "\033[m";
					}
					$this->state['maybe_underline'] = false;
				} else {
					$this->state['maybe_bold'] = true;
				}
				++$i; // Move past the bold indicator
				continue;
			} elseif ( $this->state['maybe_bold'] && ! $this->state['inline_code'] ) {
				// No second *.
				$this->state['maybe_bold'] = false;
				// This might become an underline if we find another * before a word separator.
				if ( ! $is_word_delimiter ) {
					$this->state['maybe_underline'] = $i;
					$this->state['maybe_underline_words'] = 0;
					++$i;
					continue;
				}
				--$i;
			} elseif ( false !== $this->state['maybe_underline'] && ! $this->state['inline_code'] ) {
				if ( ! $is_word_delimiter ) {
					// buffer
					++$i;
					continue;
				}
				if ( $is_word_delimiter && $message[ $i ] !== PHP_EOL ) {
					++$this->state['maybe_underline_words'];
					if ( $this->state['maybe_underline_words'] < 3 ) {
						// buffer
						++$i;
						continue;
					}
				}
				yield substr( $message, $this->state['maybe_underline'] - 1, $i - $this->state['maybe_underline'] + 1 );
				$this->state['maybe_underline'] = false;
				$this->state['maybe_underline_words'] = 0;
			}

			// Process bold and headline markers only outside code blocks and inline code
			if ( $i > 1 && substr( $message, $i - 1, 2 ) === '**' && substr( $message, $i - 2, 1 ) === PHP_EOL && ! $this->state['inline_code'] ) {
				$this->state['bold'] = ! $this->state['bold'];
				if ( $this->ansi ) {
					yield $this->state['bold'] ? "\033[1m" : "\033[m";
				}
				++$i; // Move past the bold indicator
				continue;
			}

			// Check for ` but not ``` (code block marker)
			// Look ahead to see if this is the start of a code block
			if ( substr( $message, $i, 1 ) === '`' && ! $this->state['math_type'] && ! $this->state['in_code_block'] && ! $this->state['code_block_start'] ) {
				// Check if we're at start of line (allowing leading whitespace)
				$line_start = ( $i === 0 || $message[ $i - 1 ] === PHP_EOL );
				if ( ! $line_start && $i > 0 ) {
					// Check if everything from last newline to here is whitespace
					$prev_newline_pos = strrpos( substr( $message, 0, $i ), PHP_EOL );
					if ( $prev_newline_pos !== false ) {
						$line_content = substr( $message, $prev_newline_pos + 1, $i - $prev_newline_pos - 1 );
						$line_start = trim( $line_content ) === '';
					}
				}

				// If at start of line and we can't see 3 characters ahead, buffer for next token
				if ( $line_start && $i + 2 >= $length ) {
					// Store the pending backticks and indentation
					$this->state['pending_fence'] = substr( $message, $i );
					$prev_newline_pos = strrpos( substr( $message, 0, $i ), PHP_EOL );
					if ( $prev_newline_pos !== false ) {
						$this->state['pending_fence_indent'] = substr( $message, $prev_newline_pos + 1, $i - $prev_newline_pos - 1 );
					} else {
						$this->state['pending_fence_indent'] = substr( $message, 0, $i );
					}
					// Don't output anything, wait for next token
					break;
				}

				// If this might be a code block (``` at start of line), handle it specially
				if ( $i + 2 < $length && substr( $message, $i, 3 ) === '```' ) {
					if ( $line_start ) {
						// This is a code block start - handle it here
						// Store the indentation (everything from last newline to the first backtick)
						$prev_newline_pos = strrpos( substr( $message, 0, $i ), PHP_EOL );
						if ( $prev_newline_pos !== false ) {
							$this->state['code_block_indent'] = substr( $message, $prev_newline_pos + 1, $i - $prev_newline_pos - 1 );
						} else {
							$this->state['code_block_indent'] = substr( $message, 0, $i );
						}

						$this->state['code_block_start'] = true;

						// Output the opening fence
						// Note: In non-ANSI mode, the indentation has already been output as we processed
						// the characters before the backticks, so we only output the backticks themselves
						yield $message[ $i ];
						yield $message[ $i + 1 ];
						yield $message[ $i + 2 ];

						$i += 3;
						continue;
					}
				}

				// Not a code block, treat as inline code
				$this->state['inline_code'] = ! $this->state['inline_code'];
				if ( $this->ansi ) {
					yield $this->state['inline_code'] ? "\033[34m" : "\033[m";
				} else {
					// In non-ANSI mode, output the backtick to preserve inline code markers
					yield $message[ $i ];
				}
				++$i;
				continue;
			}

			if ( $this->state['trimnext'] ) {
				if ( trim( $message[ $i ] ) == '' ) {
					++$i;
					continue;
				}
				$this->state['trimnext'] = false;
			}

			// Continue skipping headline prefix (### ) across token boundaries (ANSI mode only)
			if ( $this->ansi && $this->state['headline_prefix'] ) {
				if ( $message[ $i ] === '#' || $message[ $i ] === ' ' ) {
					++$i;
					continue;
				}
				$this->state['headline_prefix'] = false;
			}

			if ( substr( $message, $i, 1 ) === '#' && ( substr( $message, $i - 1, 1 ) === PHP_EOL || ! $i ) ) {
				// Start of a headline
				$this->state['headline'] = true;
				$this->state['trimnext'] = true;
				if ( $this->ansi ) {
					yield "\033[4m";
					$this->state['headline_prefix'] = true;
					while ( $i < $length && ( $message[ $i ] === '#' || $message[ $i ] === ' ' ) ) {
						++$i;
					}
					// If we consumed the entire token, we may need to continue in the next token
					if ( $i >= $length ) {
						continue;
					}
					$this->state['headline_prefix'] = false;
					continue;
				}
				// In non-ANSI mode, output the # and continue processing
			}

			// Reset states on new lines
			if ( $message[ $i ] === PHP_EOL ) {
				if ( $i > 2 && substr( $message, $i - 3, 3 ) === PHP_EOL . PHP_EOL . PHP_EOL ) {
					++$i;
					continue;
				}
				if ( $this->state['bold'] || $this->state['headline'] || $this->state['maybe_bold'] || $this->state['maybe_underline'] ) {
					if ( $this->ansi ) {
						yield "\033[m"; // Reset bold and headline
					}
					$this->state['bold'] = false;
					$this->state['headline'] = false;
					$this->state['maybe_bold'] = false;
					$this->state['maybe_underline'] = false;
				}
			}

			yield $message[ $i++ ];
		}
	}
}
