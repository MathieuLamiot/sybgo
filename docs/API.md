# Sybgo Extensibility API

Complete guide for developers extending Sybgo functionality.

## Table of Contents

- [Quick Start](#quick-start)
- [Tracking Custom Events](#tracking-custom-events)
- [Event Data Structure](#event-data-structure)
- [Event Type Registration](#event-type-registration)
- [Available Filters](#available-filters)
- [Available Actions](#available-actions)
- [Integration Examples](#integration-examples)

## Quick Start

### Check if Sybgo is Active

```php
if ( function_exists( 'sybgo_is_active' ) && sybgo_is_active() ) {
    // Sybgo is available
}
```

### Track a Simple Event

```php
sybgo_track_event( 'my_custom_event', [
    'action' => 'item_created',
    'object' => [
        'type' => 'custom_item',
        'id' => 123,
        'name' => 'My Item'
    ]
], 'my-plugin' );
```

## Tracking Custom Events

### Using Global Function (Recommended)

```php
sybgo_track_event(
    string $event_type,    // Unique event identifier
    array $event_data,     // Event data following standard structure
    string $source_plugin  // Your plugin identifier (optional)
): int|false;
```

### Using Static API Class

```php
use Rocket\Sybgo\API\Extensibility_API;

Extensibility_API::track_event( $event_type, $event_data, $source_plugin );
```

### Return Value

- **Success**: Returns event ID (integer)
- **Failure**: Returns `false` (validation failed or Sybgo inactive)

## Event Data Structure

All events must follow this standard structure:

```php
[
    'action' => 'created',           // REQUIRED: Action performed
    'object' => [                    // REQUIRED: Object affected
        'type' => 'order',           // REQUIRED: Object type
        'id' => 123,                 // Recommended: Object ID
        'name' => 'Order #123',      // Optional: Display name
        'url' => 'https://...'       // Optional: Link to object
    ],
    'context' => [                   // Optional: Who performed action
        'user_id' => 1,
        'user_name' => 'admin'
    ],
    'metadata' => [                  // Optional: Additional data
        'status' => 'pending',
        'total' => 99.99,
        'custom_field' => 'value'
    ],
    'source_plugin' => 'woocommerce' // Optional: Auto-added if passed to track_event
]
```

### Required Fields

1. **`action`** (string): The action performed (e.g., 'created', 'updated', 'deleted')
2. **`object.type`** (string): Type of object (e.g., 'order', 'booking', 'form_submission')

### Recommended Fields

- **`object.id`**: Unique identifier for the object
- **`object.name`** or **`object.title`**: Human-readable name

### Optional Fields

Everything in `context` and `metadata` is optional and can contain any data relevant to your event.

## Event Type Registration

Register your event types for AI integration and better reporting:

```php
sybgo_register_event_type( 'woocommerce_order', function( $event_data ) {
    return "Event Type: WooCommerce Order\n" .
           "Description: A new order was placed in the store.\n" .
           "Data Structure:\n" .
           "  - object.id: Order ID\n" .
           "  - object.total: Order total amount\n" .
           "  - metadata.status: Order status (pending, completed, etc.)\n" .
           "  - metadata.items: Number of items in order\n" .
           "Purpose: Track e-commerce transactions and revenue.";
} );
```

**Why Register?**
- Provides AI context for intelligent summaries
- Improves email digest descriptions
- Documents your event structure
- Better integration with Sybgo ecosystem

## Available Filters

### 1. Before Event Tracking

Modify event data before it's saved:

```php
add_filter( 'sybgo_before_track_event', function( $event_data, $event_type ) {
    // Add custom field to all events
    $event_data['metadata']['custom_timestamp'] = time();

    return $event_data;
}, 10, 2 );
```

**Helper Method:**
```php
Extensibility_API::add_event_filter( $callback, $priority );
```

### 2. Report Summary

Add custom data to report summaries:

```php
add_filter( 'sybgo_report_summary', function( $summary, $report_id ) {
    // Add custom metric
    $summary['custom_metrics'] = [
        'revenue' => 1234.56,
        'conversions' => 42
    ];

    return $summary;
}, 10, 2 );
```

**Helper Method:**
```php
Extensibility_API::add_report_summary_filter( $callback, $priority );
```

### 3. Email Recipients

Modify who receives digest emails:

```php
add_filter( 'sybgo_email_recipients', function( $recipients, $report ) {
    // Add additional recipient for high-value reports
    $summary = json_decode( $report['summary_data'], true );

    if ( isset( $summary['custom_metrics']['revenue'] ) &&
         $summary['custom_metrics']['revenue'] > 10000 ) {
        $recipients[] = 'ceo@example.com';
    }

    return $recipients;
}, 10, 2 );
```

**Helper Method:**
```php
Extensibility_API::add_email_recipients_filter( $callback, $priority );
```

### 4. Email Template

Customize email HTML:

```php
add_filter( 'sybgo_email_body', function( $html, $report ) {
    // Add custom content before footer
    $html = str_replace(
        '<!-- Footer -->',
        '<div class="custom-section">Custom content here</div><!-- Footer -->',
        $html
    );

    return $html;
}, 10, 2 );
```

**Helper Method:**
```php
Extensibility_API::add_email_template_filter( $callback, $priority );
```

### 5. Email Subject

Customize email subject line:

```php
add_filter( 'sybgo_email_subject', function( $subject, $report ) {
    $summary = json_decode( $report['summary_data'], true );

    // Add total events count to subject
    if ( isset( $summary['total_events'] ) ) {
        $subject .= ' - ' . $summary['total_events'] . ' events';
    }

    return $subject;
}, 10, 2 );
```

### 6. Email Headers

Customize email headers (from, reply-to, etc.):

```php
add_filter( 'sybgo_email_headers', function( $headers ) {
    $headers[] = 'Reply-To: support@example.com';
    return $headers;
} );
```

## Available Actions

### 1. Event Tracked

Execute code after an event is saved:

```php
add_action( 'sybgo_event_tracked', function( $event_id, $event_type, $event_data ) {
    // Log event to custom table
    error_log( "Sybgo tracked event #{$event_id} of type {$event_type}" );
}, 10, 3 );
```

**Helper Method:**
```php
Extensibility_API::add_event_action( $callback, $priority );
```

### 2. Before Report Freeze

Execute code before freezing a report:

```php
add_action( 'sybgo_before_report_freeze', function( $report_id ) {
    // Calculate custom metrics before freeze
    $events = get_events_for_report( $report_id );

    // Store custom data
    update_option( "sybgo_custom_report_{$report_id}", [
        'processed_at' => time(),
        'event_count' => count( $events )
    ] );
}, 10, 1 );
```

**Helper Method:**
```php
Extensibility_API::add_before_freeze_action( $callback, $priority );
```

### 3. After Report Freeze

Execute code after freezing a report:

```php
add_action( 'sybgo_after_report_freeze', function( $report_id ) {
    // Send notification to external service
    wp_remote_post( 'https://api.example.com/webhook', [
        'body' => [ 'report_id' => $report_id ]
    ] );
}, 10, 1 );
```

**Helper Method:**
```php
Extensibility_API::add_after_freeze_action( $callback, $priority );
```

### 4. Custom Email Sections

Add custom sections to digest emails:

```php
add_action( 'sybgo_email_custom_section', function( $report, $summary ) {
    ?>
    <div class="section" style="margin-bottom: 30px;">
        <h2 style="font-size: 20px; color: #2c3e50;">Custom Metrics</h2>
        <p>Total Revenue: $<?php echo number_format( $summary['custom_metrics']['revenue'] ?? 0, 2 ); ?></p>
        <p>Conversions: <?php echo $summary['custom_metrics']['conversions'] ?? 0; ?></p>
    </div>
    <?php
}, 10, 2 );
```

**Helper Method:**
```php
Extensibility_API::add_email_section_action( $callback, $priority );
```

### 5. Event Type Registered

Execute code after registering an event type:

```php
add_action( 'sybgo_event_type_registered', function( $event_type, $describe_callback ) {
    // Log registered types
    error_log( "New event type registered: {$event_type}" );
}, 10, 2 );
```

### 6. Email Sent

Execute code after email is sent successfully:

```php
add_action( 'sybgo_email_sent', function( $report_id, $recipient ) {
    // Track email delivery
    update_option( "sybgo_last_email_to_{$recipient}", time() );
}, 10, 2 );
```

### 7. Email Failed

Execute code when email sending fails:

```php
add_action( 'sybgo_email_failed', function( $report_id, $recipient, $error_message ) {
    // Log failure
    error_log( "Sybgo email failed for {$recipient}: {$error_message}" );

    // Send admin notification
    wp_mail( get_option( 'admin_email' ), 'Sybgo Email Failure', $error_message );
}, 10, 3 );
```

## Integration Examples

### Example 1: WooCommerce Integration

```php
/**
 * Track WooCommerce orders in Sybgo
 */
add_action( 'woocommerce_new_order', function( $order_id ) {
    if ( ! function_exists( 'sybgo_track_event' ) ) {
        return;
    }

    $order = wc_get_order( $order_id );

    sybgo_track_event( 'woocommerce_order', [
        'action' => 'created',
        'object' => [
            'type' => 'order',
            'id' => $order_id,
            'name' => 'Order #' . $order->get_order_number()
        ],
        'context' => [
            'user_id' => $order->get_user_id(),
            'user_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
        ],
        'metadata' => [
            'total' => $order->get_total(),
            'status' => $order->get_status(),
            'items' => $order->get_item_count(),
            'currency' => $order->get_currency()
        ]
    ], 'woocommerce' );
} );

// Register event type for AI
sybgo_register_event_type( 'woocommerce_order', function( $event_data ) {
    return "Event Type: WooCommerce Order\n" .
           "Description: A new order was placed.\n" .
           "Data: Order total, status, items, customer info";
} );
```

### Example 2: Contact Form 7 Integration

```php
/**
 * Track Contact Form 7 submissions
 */
add_action( 'wpcf7_mail_sent', function( $contact_form ) {
    if ( ! function_exists( 'sybgo_track_event' ) ) {
        return;
    }

    sybgo_track_event( 'contact_form_submission', [
        'action' => 'submitted',
        'object' => [
            'type' => 'contact_form',
            'id' => $contact_form->id(),
            'name' => $contact_form->title()
        ],
        'metadata' => [
            'form_id' => $contact_form->id(),
            'timestamp' => current_time( 'mysql' )
        ]
    ], 'contact-form-7' );
} );
```

### Example 3: Custom Plugin Events

```php
/**
 * Track custom booking events
 */
class My_Booking_Plugin {

    public function create_booking( $data ) {
        // Your booking creation logic
        $booking_id = $this->save_booking( $data );

        // Track in Sybgo
        if ( function_exists( 'sybgo_track_event' ) ) {
            sybgo_track_event( 'booking_created', [
                'action' => 'created',
                'object' => [
                    'type' => 'booking',
                    'id' => $booking_id,
                    'name' => $data['service_name']
                ],
                'context' => [
                    'user_id' => get_current_user_id(),
                    'user_name' => $data['customer_name']
                ],
                'metadata' => [
                    'service' => $data['service_name'],
                    'date' => $data['booking_date'],
                    'duration' => $data['duration'],
                    'price' => $data['price']
                ]
            ], 'my-booking-plugin' );
        }

        return $booking_id;
    }
}

// Register event type
add_action( 'init', function() {
    if ( function_exists( 'sybgo_register_event_type' ) ) {
        sybgo_register_event_type( 'booking_created', function( $event_data ) {
            return "Event Type: Booking Created\n" .
                   "Description: A new booking was made.\n" .
                   "Data: Service, date, duration, customer, price";
        } );
    }
} );
```

### Example 4: Add Custom Metrics to Reports

```php
/**
 * Add revenue metrics to weekly reports
 */
add_filter( 'sybgo_report_summary', function( $summary, $report_id ) {
    // Calculate total revenue from WooCommerce orders
    $total_revenue = 0;

    // Get all events for this report
    global $wpdb;
    $events = $wpdb->get_results( $wpdb->prepare(
        "SELECT event_data FROM {$wpdb->prefix}sybgo_events
         WHERE report_id = %d AND event_type = %s",
        $report_id,
        'woocommerce_order'
    ), ARRAY_A );

    foreach ( $events as $event ) {
        $data = json_decode( $event['event_data'], true );
        $total_revenue += $data['metadata']['total'] ?? 0;
    }

    // Add to summary
    $summary['custom_metrics'] = [
        'total_revenue' => $total_revenue,
        'average_order_value' => count( $events ) > 0 ? $total_revenue / count( $events ) : 0
    ];

    return $summary;
}, 10, 2 );

// Add to email
add_action( 'sybgo_email_custom_section', function( $report, $summary ) {
    if ( ! isset( $summary['custom_metrics'] ) ) {
        return;
    }
    ?>
    <div class="section">
        <h2>💰 Revenue Summary</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value">$<?php echo number_format( $summary['custom_metrics']['total_revenue'], 2 ); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Order Value</div>
                <div class="stat-value">$<?php echo number_format( $summary['custom_metrics']['average_order_value'], 2 ); ?></div>
            </div>
        </div>
    </div>
    <?php
}, 10, 2 );
```

## Best Practices

1. **Always Check Availability**
   ```php
   if ( function_exists( 'sybgo_track_event' ) ) {
       // Your code
   }
   ```

2. **Use Descriptive Event Types**
   - ✅ Good: `woocommerce_order`, `booking_created`, `form_submitted`
   - ❌ Bad: `event1`, `custom`, `action`

3. **Include Meaningful Data**
   - Add enough context for report generation
   - Include user information when relevant
   - Store data needed for future analysis

4. **Register Event Types**
   - Always register custom event types
   - Provide clear descriptions for AI integration
   - Document your data structure

5. **Use Source Plugin Identifier**
   - Pass your plugin slug as third parameter
   - Helps with filtering and debugging
   - Enables plugin-specific reporting

6. **Handle Errors Gracefully**
   - Check return values from `sybgo_track_event()`
   - Don't break your plugin if Sybgo fails
   - Log errors for debugging

## API Reference

### Global Functions

- `sybgo_track_event( $type, $data, $source )` - Track an event
- `sybgo_register_event_type( $type, $callback )` - Register event type
- `sybgo_is_active()` - Check if Sybgo is available
- `sybgo_get_version()` - Get Sybgo version

### Static API Methods

- `Extensibility_API::track_event()`
- `Extensibility_API::register_event_type()`
- `Extensibility_API::is_event_type_registered()`
- `Extensibility_API::get_registered_event_types()`
- `Extensibility_API::add_event_filter()`
- `Extensibility_API::add_event_action()`
- `Extensibility_API::add_report_summary_filter()`
- `Extensibility_API::add_email_recipients_filter()`
- `Extensibility_API::add_email_template_filter()`
- `Extensibility_API::add_email_section_action()`
- `Extensibility_API::add_before_freeze_action()`
- `Extensibility_API::add_after_freeze_action()`

## Support

For questions, issues, or feature requests:
- GitHub: https://github.com/your-repo/sybgo
- Documentation: See `/docs` folder

## License

GPL-2.0-or-later
