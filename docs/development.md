# Development Guide

This guide covers setting up your development environment, running tests, and contributing to Sybgo.

## Development Setup

### Requirements
- WordPress 5.0+
- PHP 7.4+
- Composer
- MySQL 5.7+ or MariaDB 10.2+

### Local Environment Options
- Local by Flywheel (recommended)
- MAMP/WAMP/XAMPP
- Docker (wp-env, Lando, etc.)

### Installation

```bash
# 1. Clone repository
cd /path/to/wordpress/wp-content/plugins
git clone <repo-url> sybgo
cd sybgo

# 2. Install dependencies
composer install

# 3. Activate in WordPress
# WP Admin → Plugins → Activate "Sybgo"

# 4. Verify installation
wp plugin list | grep sybgo
```

### Development Dependencies

```bash
# Code standards
composer require --dev squizlabs/php_codesniffer
composer require --dev wp-coding-standards/wpcs

# Testing
composer require --dev phpunit/phpunit
composer require --dev brain/monkey
composer require --dev mockery/mockery
```

## Code Standards

Sybgo follows WordPress Coding Standards and GroupOne technical standards.

### Check Code

```bash
# Check all PHP files
composer phpcs

# Check specific file
composer phpcs -- database/class-event-repository.php
```

### Auto-Fix Issues

```bash
# Fix all fixable issues
composer phpcs:fix

# Fix specific file
composer phpcs:fix -- database/class-event-repository.php
```

### Common Standards

**Type Declarations:**
```php
declare(strict_types=1);
```

**Namespacing:**
```php
namespace Rocket\Sybgo\Events\Trackers;
```

**Security:**
```php
// Nonces
wp_nonce_field( 'sybgo_action', 'sybgo_nonce' );
check_admin_referer( 'sybgo_action', 'sybgo_nonce' );

// Output escaping
echo esc_html( $text );
echo esc_url( $url );
echo esc_attr( $attr );

// Input sanitization
$clean = sanitize_text_field( $_POST['field'] );
$email = sanitize_email( $_POST['email'] );

// SQL preparation
$wpdb->prepare( "SELECT * FROM table WHERE id = %d", $id );
```

## Testing

### Run All Tests

```bash
# All tests (unit + integration)
composer run-tests

# Unit tests only
composer test-unit

# Integration tests only
composer test-integration

# With coverage report
composer test-coverage
```

### Run Specific Tests

```bash
# Single test class
vendor/bin/phpunit --filter PostTrackerTest

# Single test method
vendor/bin/phpunit --filter test_track_post_publish

# Specific file
vendor/bin/phpunit Tests/Unit/Events/PostTrackerTest.php
```

### Writing Tests

**Unit Test Example:**

```php
namespace Rocket\Sybgo\Tests\Unit\Events;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Rocket\Sybgo\Events\Trackers\Post_Tracker;

class PostTrackerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock WordPress functions
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_permalink' )->alias( function( $id ) {
            return "https://example.com/post-{$id}";
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_track_post_publish() {
        $event_tracker = Mockery::mock( Event_Tracker::class );
        $event_tracker->shouldReceive( 'track_event' )
            ->once()
            ->with( 'post_published', Mockery::type( 'array' ) )
            ->andReturn( 123 );

        $tracker = new Post_Tracker( $event_tracker );

        // Test logic here
        $this->assertTrue( true );
    }
}
```

### Testing Checklist

When adding new features:
- [ ] Write unit tests for all new classes
- [ ] Mock WordPress functions with Brain\Monkey
- [ ] Test both success and error cases
- [ ] Ensure 100% pass rate before committing
- [ ] Run code standards check

## Development Workflow

### Making Changes

```bash
# 1. Create feature branch
git checkout -b feature/my-feature

# 2. Make changes
# Edit files...

# 3. Check code standards
composer phpcs

# 4. Fix any issues
composer phpcs:fix

# 5. Run tests
composer run-tests

# 6. Commit changes
git add .
git commit -m "Add feature: description"

# 7. Push and create PR
git push origin feature/my-feature
```

### Debugging

**Enable WordPress Debug Mode:**

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

**Add Debug Logging:**

```php
error_log( 'Sybgo: Event tracked - ' . $event_type );
error_log( print_r( $event_data, true ) );
```

**Check Debug Log:**

```bash
tail -f wp-content/debug.log
```

### Database Inspection

```bash
# View recent events
wp db query "SELECT * FROM wp_sybgo_events ORDER BY created_at DESC LIMIT 10"

# Pretty print JSON
wp db query "SELECT id, event_type, JSON_PRETTY(event_data) FROM wp_sybgo_events LIMIT 1"

# Count events by type
wp db query "SELECT event_type, COUNT(*) as total FROM wp_sybgo_events GROUP BY event_type"

# View reports
wp db query "SELECT * FROM wp_sybgo_reports ORDER BY period_end DESC"
```

## Manual Testing

### Test Event Tracking

```bash
# Publish a post
wp post create --post_title="Test Post" --post_status=publish

# Verify event created
wp db query "SELECT * FROM wp_sybgo_events WHERE event_type='post_published' ORDER BY created_at DESC LIMIT 1"

# Edit the post
wp post update 1 --post_content="Updated content"

# Check edit event
wp db query "SELECT event_type, JSON_EXTRACT(event_data, '$.metadata.edit_magnitude') FROM wp_sybgo_events WHERE event_type='post_edited' ORDER BY created_at DESC LIMIT 1"
```

### Test Report Freezing

```bash
# Manual freeze
wp cron event run sybgo_freeze_weekly_report

# Verify report frozen
wp db query "SELECT * FROM wp_sybgo_reports WHERE status='frozen' ORDER BY period_end DESC LIMIT 1"

# Check events assigned
wp db query "SELECT COUNT(*) FROM wp_sybgo_events WHERE report_id IS NOT NULL"
```

### Test Email Sending

```bash
# Manual send
wp cron event run sybgo_send_report_emails

# Check email log
wp db query "SELECT * FROM wp_sybgo_email_log ORDER BY created_at DESC LIMIT 5"

# View email content (from log)
wp db query "SELECT recipient, status, error_message FROM wp_sybgo_email_log WHERE status='failed'"
```

## Project Structure

```
sybgo/
├── sybgo.php                     # Main plugin file (WordPress header)
├── class-sybgo.php               # Main orchestrator class
├── class-factory.php             # Dependency injection
│
├── database/                     # Data layer
│   ├── class-databasemanager.php
│   ├── class-event-repository.php
│   └── class-report-repository.php
│
├── events/                       # Event tracking
│   ├── class-event-tracker.php
│   ├── class-event-registry.php
│   └── trackers/
│       ├── class-post-tracker.php
│       ├── class-user-tracker.php
│       ├── class-comment-tracker.php
│       └── class-update-tracker.php
│
├── reports/                      # Report generation
│   ├── class-report-manager.php
│   └── class-report-generator.php
│
├── admin/                        # WordPress admin
│   ├── class-dashboard-widget.php
│   ├── class-settings-page.php
│   └── class-reports-page.php
│
├── email/                        # Email system
│   ├── class-email-manager.php
│   └── class-email-template.php
│
├── api/                          # Extensibility
│   ├── class-extensibility-api.php
│   └── functions.php
│
├── docs/                         # Documentation
│   ├── event-tracking.md
│   ├── report-lifecycle.md
│   ├── extension-api.md
│   └── development.md (this file)
│
└── Tests/                        # Test suite
    ├── Unit/
    │   ├── Events/
    │   ├── Reports/
    │   ├── Email/
    │   └── Admin/
    └── Integration/
```

## Adding New Event Types

### 1. Create Tracker Class

Create `events/trackers/class-media-tracker.php`:

```php
namespace Rocket\Sybgo\Events\Trackers;

use Rocket\Sybgo\Events\Event_Registry;

class Media_Tracker {
    private object $event_tracker;

    public function __construct( object $event_tracker ) {
        $this->event_tracker = $event_tracker;
        $this->register_hooks();
        $this->register_event_types();
    }

    private function register_hooks(): void {
        add_action( 'add_attachment', [ $this, 'on_media_upload' ] );
    }

    private function register_event_types(): void {
        Event_Registry::register_event_type( 'media_uploaded', function( $event_data ) {
            return "Event Type: Media Uploaded\n" .
                   "Description: A new file was uploaded.\n" .
                   "Data: Filename, size, MIME type";
        } );
    }

    public function on_media_upload( int $attachment_id ): void {
        $event_data = [
            'action' => 'uploaded',
            'object' => [
                'type' => 'media',
                'id' => $attachment_id,
                'filename' => basename( get_attached_file( $attachment_id ) )
            ],
            'metadata' => [
                'file_size' => filesize( get_attached_file( $attachment_id ) ),
                'mime_type' => get_post_mime_type( $attachment_id )
            ]
        ];

        $this->event_tracker->track_event( 'media_uploaded', $event_data );
    }
}
```

### 2. Wire Up in Main Plugin

Add to `class-sybgo.php`:

```php
private function init_event_tracking(): void {
    // ... existing trackers ...

    require_once SYBGO_PLUGIN_DIR . 'events/trackers/class-media-tracker.php';
    $media_tracker = new \Rocket\Sybgo\Events\Trackers\Media_Tracker( $event_tracker );
}
```

### 3. Update Report Generator

Add to `reports/class-report-generator.php`:

```php
private function calculate_totals( array $events ): array {
    $totals = [
        'posts_published' => 0,
        // ... existing ...
        'media_uploaded' => 0,
    ];

    foreach ( $events as $event ) {
        switch ( $event['event_type'] ) {
            // ... existing cases ...
            case 'media_uploaded':
                $totals['media_uploaded']++;
                break;
        }
    }

    return $totals;
}
```

### 4. Write Tests

Create `Tests/Unit/Events/MediaTrackerTest.php`:

```php
class MediaTrackerTest extends TestCase {
    public function test_track_media_upload() {
        // Test implementation
    }
}
```

## Troubleshooting

### Tests Failing

**Check PHP version:**
```bash
php -v  # Must be 7.4+
```

**Update dependencies:**
```bash
composer update
```

**Clear cache:**
```bash
composer dump-autoload
```

### Code Standards Errors

**Common issues:**
- Missing type declarations
- Incorrect escaping functions
- Wrong indentation (tabs vs spaces)
- Missing DocBlocks

**Fix automatically:**
```bash
composer phpcs:fix
```

### Cron Not Running

**Check schedule:**
```bash
wp cron event list
```

**Test manually:**
```bash
wp cron event run sybgo_freeze_weekly_report
wp cron event run sybgo_send_report_emails
```

**Enable system cron:**
```bash
# In crontab -e
*/15 * * * * wget -q -O - https://yoursite.local/wp-cron.php?doing_wp_cron
```

## Performance Testing

### Load Testing

```bash
# Generate test events
for i in {1..1000}; do
    wp post create --post_title="Test Post $i" --post_status=publish
done

# Check performance
time wp cron event run sybgo_freeze_weekly_report
```

### Query Analysis

```sql
-- Explain slow queries
EXPLAIN SELECT * FROM wp_sybgo_events WHERE report_id IS NULL;

-- Check index usage
SHOW INDEXES FROM wp_sybgo_events;
```

## Contributing

### Before Submitting PR

- [ ] All tests passing (`composer run-tests`)
- [ ] Code standards passing (`composer phpcs`)
- [ ] Documentation updated if needed
- [ ] Commit messages are descriptive
- [ ] No debug code left in

### Commit Message Format

```
Add feature: Brief description

- Detailed point 1
- Detailed point 2

Closes #123
```

## Related Documentation

- [Event Tracking](event-tracking.md) - Understanding event system
- [Report Lifecycle](report-lifecycle.md) - How reports work
- [Extension API](extension-api.md) - Plugin integration
