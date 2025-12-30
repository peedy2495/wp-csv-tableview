# wp-csv-tableview
A simple WordPress plugin for viewing CSV tables

## Shortcode attributes
 - src (required): URL to the CSV file (http(s) or site-relative).
 - header: 1 or 0 (default 1) - treat first row as header.
 - delimiter: single character CSV delimiter (default ",").
 - class: extra CSS classes for the table element.
 - max_rows: maximum number of rows to render (default 500).
 - cache_minutes: integer minutes to cache fetch results (default 5)

## Example
```php
[csv_table src="https://example.com/assets/table.csv" header="1" delimiter="," class="my-table"]
```