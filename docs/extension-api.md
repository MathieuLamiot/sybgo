# Extension API

This guide shows how to integrate your WordPress plugin with Sybgo to track custom events and customize weekly digests.

## Quick Start

### Check if Sybgo is Active

```php
if ( function_exists( 'sybgo_track_event' ) ) {
    // Sybgo is available
}
```

### Track Your First Event

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

### Function Signature

```php
sybgo_track_event(
    string $event_type,    // Unique event identifier (e.g., 'woocommerce_order')
    array $event_data,     // Event data following standard structure
    string $source_plugin  // Your plugin identifier (e.g., 'woocommerce')
): int|false;
```

**Returns:**
- Success: Event ID (integer)
- Failure: `false` (validation failed or Sybgo inactive)

### Event Data Structure

All events must follow this structure:

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
    ]
]
```

**Required:**
- `action` - Action performed (e.g., 'created', 'updated', 'deleted')
- `object.type` - Type of object (e.g., 'order', 'booking', 'form')

**Recommended:**
- `object.id` - Unique identifier
- `object.name` or `object.title` - Human-readable name

## Event Type Registration

Register your event types via the `sybgo_event_types` filter for proper reporting, dashboard display, and AI integration:

```php
add_filter( 'sybgo_event_types', function( array $types ): array {
    $types['woocommerce_order'] = array(
        'icon'            => '🛒',
        'stat_label'      => __( 'Orders', 'my-plugin' ),
        'short_title'     => function ( array $event_data ): string {
            return 'Order #' . $event_data['object']['id'];
        },
        'detailed_title'  => function ( array $event_data ): string {
            return 'New order #' . $event_data['object']['id'] . ' (' . $event_data['metadata']['total'] . ')';
        },
        'ai_description'  => function ( array $object, array $metadata ): string {
            return "WooCommerce order #{$object['id']}, total: {$metadata['total']}";
        },
        'describe'        => function ( array $event_data ): string {
            return "Event Type: WooCommerce Order\n" .
                   "Description: A new order was placed.\n" .
                   "Data: Order total, status, items, customer info";
        },
    );
    return $types;
} );
```

**Registration keys:**
- `icon` - Emoji displayed in dashboard and emails
- `stat_label` - Human-readable label for statistics
- `short_title` - Callable returning a short display title
- `detailed_title` - Callable returning a detailed display title
- `ai_description` - Callable providing context for AI summaries
- `describe` - Callable returning a full schema description

## Hooks & Filters

### Modify Event Data Before Saving

```php
add_filter( 'sybgo_before_track_event', function( $event_data, $event_type ) {
    // Add custom field to all events
    $event_data['metadata']['custom_field'] = 'value';
    return $event_data;
}, 10, 2 );
```

### Add Custom Data to Report Summary

```php
add_filter( 'sybgo_report_summary', function( $summary, $report_id ) {
    $summary['custom_metrics'] = [
        'revenue' => 1234.56,
        'conversions' => 42
    ];
    return $summary;
}, 10, 2 );
```

### Customize Email Subject

```php
add_filter( 'sybgo_email_subject', function( $subject, $report ) {
    $summary = json_decode( $report['summary_data'], true );
    $subject .= ' - ' . $summary['total_events'] . ' events';
    return $subject;
}, 10, 2 );
```

### Add Custom Section to Email

```php
add_action( 'sybgo_email_custom_section', function( $report, $summary ) {
    ?>
    <div class="section" style="margin-bottom: 30px;">
        <h2 style="font-size: 20px; color: #2c3e50;">Custom Metrics</h2>
        <p>Total Revenue: $<?php echo number_format( $summary['custom_metrics']['revenue'] ?? 0, 2 ); ?></p>
    </div>
    <?php
}, 10, 2 );
```

### Execute Code After Event Tracked

```php
add_action( 'sybgo_event_tracked', function( $event_id, $event_type, $event_data ) {
    error_log( "Event #{$event_id} tracked: {$event_type}" );
}, 10, 3 );
```

### Execute Code During Report Freeze

```php
// Before freeze
add_action( 'sybgo_before_report_freeze', function( $report_id ) {
    // Calculate custom metrics before freeze
}, 10, 1 );

// After freeze
add_action( 'sybgo_after_report_freeze', function( $report_id ) {
    // Send notification to external service
}, 10, 1 );
```

### Track Email Delivery

```php
// Success
add_action( 'sybgo_email_sent', function( $report_id, $recipient ) {
    update_option( "last_email_to_{$recipient}", time() );
}, 10, 2 );

// Failure
add_action( 'sybgo_email_failed', function( $report_id, $recipient, $error_message ) {
    error_log( "Email failed for {$recipient}: {$error_message}" );
}, 10, 3 );
```

## Integration Examples

### WooCommerce Orders

```php
// Track order events
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

// Register event type via filter
add_filter( 'sybgo_event_types', function( array $types ): array {
    $types['woocommerce_order'] = array(
        'icon'            => '🛒',
        'stat_label'      => __( 'Orders', 'my-plugin' ),
        'short_title'     => function ( array $event_data ): string {
            return 'Order #' . $event_data['object']['id'];
        },
        'detailed_title'  => function ( array $event_data ): string {
            return 'New order #' . $event_data['object']['name'];
        },
        'ai_description'  => function ( array $object, array $metadata ): string {
            return "WooCommerce order #{$object['id']}, total: {$metadata['total']}";
        },
        'describe'        => function ( array $event_data ): string {
            return "Event Type: WooCommerce Order\nData: Order total, status, items, customer info";
        },
    );
    return $types;
} );
```

### Contact Form 7 Submissions

```php
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
            'form_id' => $contact_form->id()
        ]
    ], 'contact-form-7' );
} );
```

### Custom Booking Plugin

```php
class My_Booking_Plugin {

    public function create_booking( $data ) {
        // Create booking
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

// Register event type via filter
add_filter( 'sybgo_event_types', function( array $types ): array {
    $types['booking_created'] = array(
        'icon'            => '📅',
        'stat_label'      => __( 'Bookings', 'my-booking-plugin' ),
        'short_title'     => function ( array $event_data ): string {
            return $event_data['object']['name'];
        },
        'detailed_title'  => function ( array $event_data ): string {
            return 'Booking: ' . $event_data['object']['name'] . ' on ' . $event_data['metadata']['date'];
        },
        'ai_description'  => function ( array $object, array $metadata ): string {
            return "Booking for {$metadata['service']} on {$metadata['date']}";
        },
        'describe'        => function ( array $event_data ): string {
            return "Event Type: Booking Created\nData: Service, date, duration, customer, price";
        },
    );
    return $types;
} );
```

### Add Revenue Metrics to Reports

```php
// Calculate metrics
add_filter( 'sybgo_report_summary', function( $summary, $report_id ) {
    global $wpdb;

    // Get WooCommerce order events
    $events = $wpdb->get_results( $wpdb->prepare(
        "SELECT event_data FROM {$wpdb->prefix}sybgo_events
         WHERE report_id = %d AND event_type = %s",
        $report_id,
        'woocommerce_order'
    ), ARRAY_A );

    $total_revenue = 0;
    foreach ( $events as $event ) {
        $data = json_decode( $event['event_data'], true );
        $total_revenue += $data['metadata']['total'] ?? 0;
    }

    $summary['custom_metrics'] = [
        'total_revenue' => $total_revenue,
        'order_count' => count( $events ),
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
        <p><strong>Total Revenue:</strong> $<?php echo number_format( $summary['custom_metrics']['total_revenue'], 2 ); ?></p>
        <p><strong>Orders:</strong> <?php echo $summary['custom_metrics']['order_count']; ?></p>
        <p><strong>Avg Order Value:</strong> $<?php echo number_format( $summary['custom_metrics']['average_order_value'], 2 ); ?></p>
    </div>
    <?php
}, 10, 2 );
```

## Best Practices

### 1. Always Check Availability
```php
if ( function_exists( 'sybgo_track_event' ) ) {
    // Your code
}
```

### 2. Use Descriptive Event Types
- ✅ Good: `woocommerce_order`, `booking_created`, `form_submitted`
- ❌ Bad: `event1`, `custom`, `action`

### 3. Include Meaningful Data
- Add enough context for report generation
- Include user information when relevant
- Store data needed for future analysis

### 4. Register Event Types via Filter
- Always register custom event types via `sybgo_event_types` filter
- Provide all callable fields (short_title, detailed_title, ai_description, describe)
- Include an icon and stat_label for dashboard display

### 5. Use Source Plugin Identifier
```php
sybgo_track_event( 'my_event', $data, 'my-plugin-slug' );
```

This helps with:
- Filtering events by plugin
- Debugging issues
- Plugin-specific reporting

### 6. Handle Errors Gracefully
```php
$event_id = sybgo_track_event( $type, $data, $source );
if ( false === $event_id ) {
    error_log( 'Sybgo event tracking failed' );
    // Don't break your plugin
}
```

## API Reference

### Global Functions
- `sybgo_track_event( $type, $data, $source )` - Track an event
- `sybgo_is_active()` - Check if Sybgo is available
- `sybgo_get_version()` - Get Sybgo version

### Available Filters
- `sybgo_event_types` - Register custom event types (array of type definitions)
- `sybgo_before_track_event` - Modify event data before saving (via API function)
- `sybgo_event_data` - Modify event data before creation (via core trackers)
- `sybgo_should_track_event` - Control whether an event is recorded
- `sybgo_report_summary` - Add custom data to report summary
- `sybgo_email_subject` - Customize email subject
- `sybgo_email_body` - Customize email HTML
- `sybgo_email_headers` - Modify email headers

### Available Actions
- `sybgo_event_recorded` - After a core tracker creates an event
- `sybgo_event_tracked` - After an event is saved via the API function
- `sybgo_before_report_freeze` - Before freezing report
- `sybgo_after_report_freeze` - After freezing report
- `sybgo_report_created` - After a new report is created
- `sybgo_email_custom_section` - Add custom section to email
- `sybgo_email_sent` - After successful email delivery
- `sybgo_email_failed` - After failed email delivery

## Related Documentation

- [Event Tracking](event-tracking.md) - Understanding Sybgo's event system
- [Report Lifecycle](report-lifecycle.md) - How reports are generated
- [Development Guide](development.md) - Setting up development environment
