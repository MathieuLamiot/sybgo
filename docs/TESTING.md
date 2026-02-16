# Testing Guide

Comprehensive testing documentation for Sybgo plugin.

## Quick Start

```bash
# Run all tests
composer run-tests

# Unit tests only (fast, no WordPress required)
composer test-unit

# Integration tests (requires WordPress test suite)
composer test-integration

# Code coverage
./vendor/bin/phpunit --coverage-html coverage/
```

## Test Suite Overview

### Test Statistics

**Total Test Files**: 5
- Unit Tests: 4 files (Database, Reports, Events, Registry)
- Integration Tests: 1 file (Complete lifecycle)

**Test Methods**: 30+
- Event Repository: 8 tests
- Report Generator: 8 tests
- Post Tracker: 8 tests
- Event Registry: 8 tests
- Integration: 5 tests

**Code Coverage**: ~70% (core business logic)

## What's Tested

### ✅ Database Layer (Event Repository)

**File**: `Tests/Unit/Database/EventRepositoryTest.php`

Tests:
- ✅ Event creation with data validation
- ✅ JSON conversion for event_data field
- ✅ Query by report_id (assigned/unassigned)
- ✅ Cache usage and invalidation
- ✅ Event counting by type
- ✅ Assigning events to reports
- ✅ Throttling support (get_last_event_for_object)

**Coverage**: Full CRUD operations, caching, throttling queries

### ✅ Reports Layer (Report Generator)

**File**: `Tests/Unit/Reports/ReportGeneratorTest.php`

Tests:
- ✅ Summary generation with all components
- ✅ Trend calculation (increase/decrease/same)
- ✅ Percentage change accuracy
- ✅ First report handling (no previous comparison)
- ✅ Highlights generation with trend arrows
- ✅ Top authors extraction from events

**Coverage**: Full summary generation, trend algorithms, highlights

**Example Test**:
```php
public function test_get_trend_comparison_increase() {
    // Current: 12 posts, Previous: 10 posts
    $trends = $this->generator->get_trend_comparison(2, $current_totals);

    // Verify: 10 -> 12 = 20% increase
    $this->assertEquals(20.0, $trends['post_published']['change_percent']);
    $this->assertEquals('up', $trends['post_published']['direction']);
}
```

### ✅ Event Tracking (Post Tracker)

**File**: `Tests/Unit/Events/PostTrackerTest.php`

Tests:
- ✅ Edit magnitude calculation (0-100%)
- ✅ Identical content returns 0%
- ✅ Complete rewrite returns 100%
- ✅ HTML stripping before comparison
- ✅ Empty old content handling
- ✅ Range validation (always 0-100)
- ✅ Category and tag extraction

**Coverage**: Full edit magnitude algorithm, metadata extraction

**Example Test**:
```php
public function test_calculate_edit_magnitude_minor_change() {
    $old = 'This is some test content with many words.';
    $new = 'This is some test content with many more words.';

    $magnitude = $this->calculate_edit_magnitude($old, $new);

    // Minor change: should be low percentage
    $this->assertLessThan(30, $magnitude);
    $this->assertGreaterThan(0, $magnitude);
}
```

### ✅ Event Registry (AI Descriptions)

**File**: `Tests/Unit/Events/EventRegistryTest.php`

Tests:
- ✅ Event type registration
- ✅ Description callback execution
- ✅ Unregistered type handling
- ✅ Get all registered types
- ✅ AI context generation for events
- ✅ Unique type deduplication
- ✅ Event data passing to callbacks

**Coverage**: Full event registry API, AI context generation

### ✅ Integration (Complete Flow)

**File**: `Tests/Integration/ReportLifecycleTest.php`

Tests:
- ✅ Complete report lifecycle (create → freeze → new)
- ✅ Event assignment to frozen reports
- ✅ Summary data generation
- ✅ Trend calculation across multiple reports
- ✅ Event count accuracy
- ✅ Highlights generation with real data

**Coverage**: End-to-end workflow, cross-component integration

**Example Test**:
```php
public function test_complete_report_lifecycle() {
    // 1. Create active report
    $report_id = $this->report_manager->create_new_active_report();

    // 2. Create events
    $this->event_repo->create(['event_type' => 'post_published', ...]);

    // 3. Freeze report
    $frozen_id = $this->report_manager->freeze_current_report();

    // 4. Verify frozen
    $report = $this->report_repo->get_by_id($frozen_id);
    $this->assertEquals('frozen', $report['status']);

    // 5. Verify summary has trends and highlights
    $summary = json_decode($report['summary_data'], true);
    $this->assertArrayHasKey('trends', $summary);
    $this->assertArrayHasKey('highlights', $summary);
}
```

## Test Execution

### Unit Tests (Fast - No WordPress)

Uses **Brain\Monkey** to mock WordPress functions.

**Run**:
```bash
composer test-unit
```

**Advantages**:
- ⚡ Fast (milliseconds)
- 🔌 No WordPress installation needed
- 🎯 Tests logic in isolation
- 🔄 Easy to run in CI/CD

**Use Cases**:
- Business logic testing
- Algorithm validation
- Data transformation
- Utility functions

### Integration Tests (Slower - Requires WordPress)

Uses **WordPress Test Suite** with real database.

**Setup**:
```bash
# Install WordPress test suite
bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Set environment variable (optional)
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

**Run**:
```bash
composer test-integration
```

**Advantages**:
- ✅ Real WordPress environment
- 🗄️ Actual database operations
- 🔗 Cross-component testing
- 📊 Real-world scenarios

**Use Cases**:
- End-to-end workflows
- Database operations
- Hook integration
- Multi-component features

## Coverage Report

Generate HTML coverage report:

```bash
./vendor/bin/phpunit --coverage-html coverage/
open coverage/index.html
```

**Current Coverage**:
- Database layer: ~90%
- Reports layer: ~85%
- Events layer: ~75%
- **Overall**: ~70%

## Writing Tests

### Unit Test Template

```php
<?php
namespace Rocket\Sybgo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

class MyClassTest extends TestCase {
    private $instance;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock WordPress functions
        Functions\when('current_time')->justReturn('2026-02-16 12:00:00');
        Functions\when('wp_json_encode')->alias('json_encode');

        // Create instance
        $this->instance = new MyClass();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_method_does_something() {
        // Arrange
        $input = 'test';

        // Act
        $result = $this->instance->method($input);

        // Assert
        $this->assertEquals('expected', $result);
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
class MyIntegrationTest extends WP_UnitTestCase {
    private $manager;

    public function setUp(): void {
        parent::setUp();

        // Setup with real WordPress
        $this->manager = Factory::get_instance()->create_manager();
    }

    public function test_integration_scenario() {
        // Create real WordPress post
        $post_id = $this->factory->post->create();

        // Test with real data
        $result = $this->manager->process($post_id);

        $this->assertTrue($result);
    }

    public function tearDown(): void {
        // Cleanup database
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sybgo_events");

        parent::tearDown();
    }
}
```

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
      - run: composer install
      - run: composer test-unit

  integration-tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:5.7
        env:
          MYSQL_ROOT_PASSWORD: password
    steps:
      - uses: actions/checkout@v2
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
      - run: composer install
      - run: bash bin/install-wp-tests.sh wordpress_test root password 127.0.0.1
      - run: composer test-integration
```

## Debugging Tests

### Verbose Output

```bash
./vendor/bin/phpunit --verbose
```

### Run Specific Test

```bash
./vendor/bin/phpunit --filter test_calculate_edit_magnitude
```

### Run Single File

```bash
./vendor/bin/phpunit Tests/Unit/Events/PostTrackerTest.php
```

### Debug Output

```php
public function test_something() {
    $result = $this->method();

    // Debug output
    var_dump($result);
    print_r($result);
    error_log(print_r($result, true));

    $this->assertTrue($result);
}
```

## Test Data Factories

For integration tests, use WordPress factories:

```php
// Create post
$post_id = $this->factory->post->create([
    'post_title' => 'Test Post',
    'post_status' => 'publish',
    'post_content' => 'Test content',
]);

// Create user
$user_id = $this->factory->user->create([
    'user_login' => 'testuser',
    'role' => 'editor',
]);

// Create comment
$comment_id = $this->factory->comment->create([
    'comment_post_ID' => $post_id,
    'comment_content' => 'Test comment',
]);
```

## Best Practices

1. **AAA Pattern**: Arrange, Act, Assert
2. **One Assertion**: Test one thing per method
3. **Descriptive Names**: `test_creates_event_with_valid_data`
4. **Test Edge Cases**: Empty, null, boundary values
5. **Mock External**: Don't test WordPress, test your code
6. **Clean Up**: Always use `tearDown()`
7. **Fast Tests**: Unit tests should be < 100ms each
8. **Isolated**: Tests shouldn't depend on each other

## Common Assertions

```php
// Equality
$this->assertEquals($expected, $actual);
$this->assertSame($expected, $actual); // Strict ===

// Type
$this->assertIsArray($value);
$this->assertIsString($value);
$this->assertNull($value);

// Numeric
$this->assertGreaterThan(5, $value);
$this->assertLessThan(100, $value);

// Arrays
$this->assertCount(3, $array);
$this->assertArrayHasKey('key', $array);
$this->assertContains('value', $array);

// Strings
$this->assertStringContainsString('needle', $haystack);
$this->assertStringStartsWith('prefix', $string);

// Boolean
$this->assertTrue($condition);
$this->assertFalse($condition);

// Exceptions
$this->expectException(Exception::class);
```

## Next Steps

### Tests to Add

- [ ] User Tracker tests
- [ ] Update Tracker tests
- [ ] Comment Tracker tests
- [ ] Report Manager tests
- [ ] Email Manager tests
- [ ] Dashboard Widget tests
- [ ] Settings Page tests

### Coverage Goals

- [ ] 80%+ overall coverage
- [ ] 100% critical path coverage
- [ ] Integration tests for all workflows

## Resources

- [PHPUnit Docs](https://phpunit.de/documentation.html)
- [Brain\Monkey Docs](https://giuseppe-mazzapica.gitbook.io/brain-monkey/)
- [WP Testing Handbook](https://make.wordpress.org/core/handbook/testing/)
- [Mockery Docs](http://docs.mockery.io/)
