# AI Transport Layer

Sybgo uses a transport abstraction for AI completion, allowing the AI provider to be swapped without modifying `AI_Summarizer`.

## WordPress 7 Requirement

AI summaries require **WordPress 7 or later**. On WordPress < 7, AI features are entirely disabled — no fallback provider, no API key setting. The plugin works normally for event tracking and email digests; the `ai_summary` field is simply `null`.

## AI_Transport_Interface

Defined in `lib/ai/interface-ai-transport.php`:

```php
interface AI_Transport_Interface {
    /**
     * @throws \RuntimeException If the transport call fails.
     */
    public function complete( string $prompt, int $max_tokens ): string;
}
```

- Returns the completion text as a string on success.
- Throws `\RuntimeException` on failure (the caller catches it and returns `null`).
- `$max_tokens` is part of the contract for future-proofing; individual transports may not honour it if the underlying provider does not expose token limits.

## WP7_AI_Transport

Implemented in `lib/ai/class-wp7-ai-transport.php`. Uses the WordPress 7 native AI API:

```php
$result = wp_ai_client_prompt( $prompt )->generate_text();
// returns string|WP_Error
```

`wp_ai_client_prompt()` returns a fluent `Prompt_Builder_With_WP_Error` object. Calling `->generate_text()` returns `string|WP_Error`. The transport converts `WP_Error` to a `\RuntimeException`.

### Error Handling

```php
$result = wp_ai_client_prompt( $prompt )->generate_text();

if ( is_wp_error( $result ) ) {
    throw new \RuntimeException( $result->get_error_message() );
}

return $result;
```

## Version Gating

`Factory::create_ai_summarizer()` gates on WP 7 availability and returns `null` on WP < 7:

```php
if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
    return null;
}
```

Callers handle nullable AI_Summarizer. The factory returns `null` on WP < 7; callers (such as `Dashboard_Widget` and `Reports_Page`) check for null before calling `generate_summary()`.

## WordPress 7 Ability API

Sybgo registers two capabilities via the WP 7 Ability API on WP 7+. On WP < 7 the registration is skipped via `function_exists` guard:

| Ability | Description |
|---------|-------------|
| `sybgo/generate-summary` | Generates an AI summary of the weekly activity report |
| `sybgo/track-events` | Records WordPress site events for the weekly digest |

Registration is triggered by the `wp_abilities_api_init` action hook in `class-sybgo.php::register_abilities()`.

```php
if ( ! function_exists( 'wp_register_ability' ) ) {
    return; // WP < 7, no-op
}
add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
```

## Implementing a Custom Transport

Any class implementing `AI_Transport_Interface` can be used as a transport:

```php
class My_Custom_Transport implements \Sybgo\AI\AI_Transport_Interface {
    public function complete( string $prompt, int $max_tokens ): string {
        // Call your AI provider here.
        // Throw \RuntimeException on failure.
    }
}
```

## Testing Transports

Use Brain Monkey to mock `wp_ai_client_prompt` and Mockery to mock the builder:

```php
$mock_builder = Mockery::mock( 'Prompt_Builder_With_WP_Error' );
$mock_builder->shouldReceive( 'generate_text' )->andReturn( 'Summary text.' );

Functions\expect( 'wp_ai_client_prompt' )
    ->once()
    ->with( 'My prompt' )
    ->andReturn( $mock_builder );

Functions\expect( 'is_wp_error' )->once()->andReturn( false );
```

To test `AI_Summarizer` in isolation, mock the interface directly:

```php
$transport = Mockery::mock( 'Sybgo\AI\AI_Transport_Interface' );
$transport->shouldReceive( 'complete' )->andReturn( 'Generated summary' );
```
