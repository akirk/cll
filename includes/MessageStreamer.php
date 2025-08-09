<?php

class MessageStreamer {

	private bool $ansi;
	private string $old_message = '';
	private array $chunks = array();
	private $logStorage = null;
	private array $state = array(
		'maybe_bold'            => false,
		'maybe_underline'       => false,
		'maybe_underline_words' => 0,
		'maybe_space_to_tab'    => false,
		'bold'                  => false,
		'headline'              => false,
		'trimnext'              => false,
		'inline_code'           => false,
		'in_code_block'         => false,
		'code_block_start'      => false,
		'maybe_code_block_end'  => false,
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
	}

	public function setLogStorage( $logStorage ): void {
		$this->logStorage = $logStorage;
	}

	public function createCurlWriteHandler( &$message, &$chunk_overflow, &$usage, $model_provider ) {
		return function ( $curl, $data ) use ( &$message, &$chunk_overflow, &$usage, $model_provider ) {
			if ( 200 !== curl_getinfo( $curl, CURLINFO_HTTP_CODE ) ) {
				$error = json_decode( trim( $chunk_overflow . $data ), true );
				if ( $error ) {
					echo 'Error: ', $error['error']['message'], PHP_EOL;
				} else {
					$chunk_overflow .= $data;
				}
				return strlen( $data );
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
					if ( isset( $json['delta']['text'] ) ) {
						foreach ( $this->outputMessage( $json['delta']['text'] ) as $output ) {
							echo $output;
						}
						$message .= $json['delta']['text'];
					} else {
						$chunk_overflow = $item;
					}
				} elseif ( isset( $json['choices'][0]['delta']['content'] ) ) {
					foreach ( $this->outputMessage( $json['choices'][0]['delta']['content'] ) as $output ) {
						echo $output;
					}
					$message .= $json['choices'][0]['delta']['content'];
				} else {
					$chunk_overflow = $item;
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

		while ( $i < $length ) {
			// Check for the start of a code block
			$last_php_eol = $i > 1 ? strrpos( $message, PHP_EOL, $i - $length - 1 ) : 0;
			$is_word_delimiter = strpos( PHP_EOL . ' ,;.-_!?()[]{}:', $message[ $i ] ) !== false;

			if ( $i > 1 && substr( $message, $i - 2, 3 ) === '```' && trim( substr( $message, $last_php_eol, $i - $last_php_eol - 2 ) ) === '' ) {
				// Strip code delimiters when in ansi.
				if ( $this->state['in_code_block'] ) {
					if ( $this->ansi ) {
						yield "\033[m";
					}
					if ( false !== $this->state['maybe_code_block_end'] ) {
						if ( $this->ansi ) {
							yield substr( $message, $this->state['maybe_code_block_end'], 2 );
						}
						$this->state['maybe_code_block_end'] = false;
					}
					$this->state['in_code_block'] = false;
				} else {
					$this->state['code_block_start'] = true;
					if ( $this->ansi ) {
						yield substr( $message, $i - 2, 2 );
					}
				}
				if ( $this->ansi ) {
					yield $message[ $i ];
				}
				++$i;
				continue;
			}

			// If we're in a code block, just output the text as is
			if ( $this->state['code_block_start'] ) {
				if ( $this->ansi ) {
					yield $message[ $i ];
				}
				if ( $message[ $i ] === PHP_EOL ) {
					$this->state['code_block_start'] = false;
					$this->state['in_code_block'] = true;
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
				if ( $this->state['maybe_space_to_tab'] !== false ) {
					if ( $message[ $i ] === ' ' ) {
						++$i;
						++$this->state['maybe_space_to_tab'];
						continue;
					}

					$spaces_count = $this->state['maybe_space_to_tab'];
					$this->state['maybe_space_to_tab'] = false;
					if ( $spaces_count > 0 ) {
						if ( $spaces_count % 4 == 0 ) {
							yield str_repeat( "\t", $spaces_count / 4 );
						} else {
							yield str_repeat( ' ', $spaces_count );
						}
						yield $message[ $i++ ];
						continue;
					}
				}
				$this->state['maybe_space_to_tab'] = false;
				if ( false === $this->state['maybe_code_block_end'] && $message[ $i ] === '`' && trim( substr( $message, $last_php_eol, $i - $last_php_eol - 1 ) ) === '' ) {
					$this->state['maybe_code_block_end'] = $i;
					++$i;
					continue;
				}
				if ( false !== $this->state['maybe_code_block_end'] && substr( $message, $i - 1, 2 ) === '``' && trim( substr( $message, $last_php_eol, $i - $last_php_eol - 2 ) ) === '' ) {
					++$i;
					continue;
				}
				yield $message[ $i++ ];
				continue;
			}

			// Math expression detection and processing (outside code blocks)
			if ( ! $this->state['in_code_block'] && ! $this->state['code_block_start'] ) {
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

			// Process bold and headline markers only outside code blocks
			if ( $message[ $i ] === '*' ) {
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
			} elseif ( $this->state['maybe_bold'] ) {
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
			} elseif ( false !== $this->state['maybe_underline'] ) {
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

			// Process bold and headline markers only outside code blocks
			if ( $i > 1 && substr( $message, $i - 1, 2 ) === '**' && substr( $message, $i - 2, 1 ) === PHP_EOL ) {
				$this->state['bold'] = ! $this->state['bold'];
				if ( $this->ansi ) {
					yield $this->state['bold'] ? "\033[1m" : "\033[m";
				}
				++$i; // Move past the bold indicator
				continue;
			}

			if ( substr( $message, $i, 1 ) === '`' ) {
				$this->state['inline_code'] = ! $this->state['inline_code'];
				if ( $this->ansi ) {
					yield $this->state['inline_code'] ? "\033[34m" : "\033[m";
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

			if ( substr( $message, $i, 1 ) === '#' && ( substr( $message, $i - 1, 1 ) === PHP_EOL || ! $i ) ) {
				// Start of a headline
				$this->state['headline'] = true;
				$this->state['trimnext'] = true;
				yield "\033[4m";
				while ( $i < $length && ( $message[ $i ] === '#' || $message[ $i ] === ' ' ) ) {
					++$i;
				}
				continue;
			}

			// Reset states on new lines
			if ( $message[ $i ] === PHP_EOL ) {
				if ( $i > 2 && substr( $message, $i - 3, 3 ) === PHP_EOL . PHP_EOL . PHP_EOL ) {
					++$i;
					continue;
				}
				if ( $this->state['bold'] || $this->state['headline'] || $this->state['maybe_bold'] || $this->state['maybe_underline'] ) {
					yield "\033[m"; // Reset bold and headline
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
