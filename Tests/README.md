# Sybgo Tests

Comprehensive test suite for the Sybgo WordPress plugin.

## Test Structure

```
Tests/
├── Unit/                  # Unit tests (Brain\Monkey)
│   ├── Database/         # Repository tests
│   ├── Events/           # Event tracker tests
│   └── Reports/          # Report generator tests
├── Integration/          # Integration tests (WordPress test suite)
└── bootstrap.php         # Test bootstrap
```

## Running Tests

### All Tests
```bash
composer run-tests
```

### Unit Tests Only
```bash
composer test-unit
# or
./vendor/bin/phpunit --testsuite=Unit
```

### Integration Tests Only
```bash
composer test-integration
# or
./vendor/bin/phpunit --testsuite=Integration
```

### Specific Test File
```bash
./vendor/bin/phpunit Tests/Unit/Database/EventRepositoryTest.php
```

### With Code Coverage
```bash
./vendor/bin/phpunit --coverage-html coverage/
```

## Unit Tests (Brain\Monkey)

Unit tests use Brain\Monkey to mock WordPress functions without requiring a full WordPress installation.

**What's Tested:**
- Database repositories (CRUD operations)
- Event trackers (edit magnitude calculation)
- Report generator (trend calculation, highlights)
- Business logic in isolation

**Example:**
```php
public function test_create_event() {
    $this->wpdb->shouldReceive('insert')
        ->once()
        ->andReturn(1);

    $result = $this->event_repo->create($event_data);

    $this->assertGreaterThan(0, $result);
}
```

## Integration Tests (WordPress Test Suite)

Integration tests run against actual WordPress database and functions.

**What's Tested:**
- Complete report lifecycle (create → freeze → email)
- Event tracking flow
- Database operations
- Cross-component integration

**Setup Required:**
1. Install WordPress test suite:
```bash
bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

2. Set environment variable (optional):
```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

**Example:**
```php
public function test_complete_report_lifecycle() {
    // Create report
    $report_id = $this->report_manager->create_new_active_report();

    // Create events
    $this->event_repo->create($event_data);

    // Freeze report
    $frozen_id = $this->report_manager->freeze_current_report();

    // Verify
    $this->assertEquals('frozen', $report['status']);
}
```

## Test Coverage

### Currently Covered

✅ **Event Repository**
- Event creation with JSON conversion
- Query by report (assigned/unassigned)
- Event counting by type
- Throttling (get_last_event_for_object)
- Cache usage

✅ **Report Generator**
- Summary generation
- Trend calculation (increase/decrease/same)
- Highlights generation with trend arrows
- Top authors extraction
- First report (no previous comparison)

✅ **Post Tracker**
- Edit magnitude calculation
  - Identical content (0%)
  - Complete rewrite (100%)
  - Minor changes
  - HTML stripping
- Category/tag extraction

✅ **Integration**
- Complete report lifecycle
- Multi-report trend calculation
- Event count accuracy
- Highlights generation

### To Be Added

⏳ **User Tracker**
- User registration tracking
- Role change detection

⏳ **Update Tracker**
- Core/plugin/theme update detection
- Version comparison

⏳ **Comment Tracker**
- Comment creation tracking
- Status change detection

⏳ **Report Manager**
- Manual freeze functionality
- Email marking

⏳ **Dashboard Widget**
- AJAX filtering
- Preview generation

⏳ **Email Manager**
- Email generation
- Retry queue

## Writing New Tests

### Unit Test Template

```php
<?php
namespace Rocket\Sybgo\Tests\Unit\YourNamespace;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class YourClassTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock WordPress functions
        Functions\when('wp_function')->justReturn('value');
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_something() {
        // Arrange
        $data = ['test' => 'data'];

        // Act
        $result = $this->instance->method($data);

        // Assert
        $this->assertTrue($result);
    }
}
```

### Integration Test Template

```php
<?php
namespace Rocket\Sybgo\Tests\Integration;

use WP_UnitTestCase;

/**
 * @group integration
 */
class YourIntegrationTest extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        // Setup code
    }

    public function test_integration() {
        // Test with real WordPress
        $post_id = $this->factory->post->create();
        $this->assertGreaterThan(0, $post_id);
    }

    public function tearDown(): void {
        // Cleanup
        parent::tearDown();
    }
}
```

## Continuous Integration

Tests should run on every commit. Example GitHub Actions:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'

      - name: Install dependencies
        run: composer install

      - name: Run unit tests
        run: composer test-unit

      - name: Run integration tests
        run: composer test-integration
```

## Best Practices

1. **Isolation**: Each test should be independent
2. **Naming**: Use descriptive test method names (`test_create_with_valid_data`)
3. **AAA Pattern**: Arrange, Act, Assert
4. **One Assertion**: Test one thing per test method
5. **Mock External**: Mock WordPress functions and database in unit tests
6. **Cleanup**: Always clean up in `tearDown()`

## Debugging Tests

### Verbose Output
```bash
./vendor/bin/phpunit --verbose
```

### Debug Specific Test
```bash
./vendor/bin/phpunit --filter test_calculate_edit_magnitude
```

### Print Debug Info
```php
public function test_something() {
    $result = $this->method();
    var_dump($result); // Debug output
    $this->assertTrue($result);
}
```

## Test Data

Use WordPress factory for integration tests:

```php
// Create post
$post_id = $this->factory->post->create([
    'post_title' => 'Test Post',
    'post_status' => 'publish',
]);

// Create user
$user_id = $this->factory->user->create([
    'user_login' => 'testuser',
    'role' => 'editor',
]);

// Create comment
$comment_id = $this->factory->comment->create([
    'comment_post_ID' => $post_id,
]);
```

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Brain\Monkey Documentation](https://giuseppe-mazzapica.gitbook.io/brain-monkey/)
- [WordPress Testing Handbook](https://make.wordpress.org/core/handbook/testing/)
- [Mockery Documentation](http://docs.mockery.io/)
