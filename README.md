# wp-csv-tableview
A simple WordPress plugin for viewing CSV tables

## Shortcode attributes
 - `src (required)`: URL to the CSV file (http(s) or site-relative).
 - `header`: 1 or 0 - treat first row as header **(default 1)**.
 - `delimiter`: single character CSV delimiter **(default ",")**.
 - `class`: space-separated extra CSS classes for the table element.
 - `cache_minutes`: integer minutes to cache fetch results **(default 5)**
 - `max_rows`: maximum number of rows to render **(default 500)**.
 - `max_mb`: maximum CSV size in megabytes **(default 2; 0 = unlimited)**.
 - `restrict_host`: 1 or 0 — when 1, only same-host CSVs are allowed **(default 1)**.
 - `sort_col`: zero-based column index used as default sort when no `col` query param provided.
 - `sort_order`: `asc` or `desc` default sort order optional used with `sort_col` **(default "asc")**.

## Example
```php

[csv_table src="https://example.com/assets/table.csv" header="1" delimiter="," class="my-table"]

// Use shortcode defaults for sorting
[csv_table src="https://example.com/assets/table.csv" sort_col="2" sort_order="desc"]

// Limit rows and bytes
[csv_table src="/files/data.csv" max_rows="200" max_mb="5"]
```

## Sorting
 - Query parameters: `?col=<n>&order=<asc|desc>` — sort by zero-based column index.
 - Shortcode attributes: `sort_col` (0-based index) and `sort_order` (`asc` or `desc`) set defaults when no query params are present.
 - Settings: enable default sorting and set `default_sort_col` / `default_sort_order` in the plugin settings page.
 - Table headers become clickable when sorting is enabled; clicking toggles ascending/descending and preserves other query parameters.

## Examples
```php
[csv_table src="https://example.com/assets/table.csv" sort_col="2" sort_order="desc"]
// or via URL: /page-with-table/?col=2&order=desc
```