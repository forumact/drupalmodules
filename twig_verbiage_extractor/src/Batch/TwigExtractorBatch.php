<?php

namespace Drupal\twig_verbiage_extractor\Batch;

use Drupal\Core\File\FileSystemInterface;

/**
 * Batch operations for Twig Verbiage Extractor.
 */
class TwigExtractorBatch {

  // -------------------------------------------------------------------------
  // BATCH OPERATION 1: Fetch API Data
  // -------------------------------------------------------------------------

  /**
   * Fetch digital_sites API data and store in state.
   */
  public static function fetchApiData($api_base_url, $organization, &$context) {
    $context['message'] = t('Fetching digital_sites API data...');

    $url = $api_base_url . '/api/v1/digital/list?organization=' . urlencode($organization);

    try {
      /** @var \GuzzleHttp\ClientInterface $client */
      $client   = \Drupal::httpClient();
      $response = $client->get($url, [
        'verify'  => FALSE, // Self-signed SSL on localhost.
        'timeout' => 30,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);

      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Invalid JSON response from API.');
      }

      $api_data = isset($data['data']) ? $data['data'] : [];
      \Drupal::state()->set('tve_api_data', $api_data);

      $context['results']['api_count'] = count($api_data);
      $context['message'] = t('API data fetched: @count nodes loaded.', ['@count' => count($api_data)]);
    }
    catch (\Exception $e) {
      // Log error but continue — column D will just be empty.
      \Drupal::logger('twig_verbiage_extractor')->warning(
        'API fetch failed: @msg. Continuing without API data.',
        ['@msg' => $e->getMessage()]
      );
      \Drupal::state()->set('tve_api_data', []);
      $context['results']['api_count'] = 0;
      $context['message'] = t('API fetch failed. Continuing without API data.');
    }
  }

  // -------------------------------------------------------------------------
  // BATCH OPERATION 2: Discover Files + Initialise CSV
  // -------------------------------------------------------------------------

  /**
   * Recursively discover all .twig files and initialise the CSV.
   */
  public static function discoverFiles($templates_path, &$context) {
    $context['message'] = t('Scanning templates directory...');

    // Recursively find all .twig files.
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($templates_path, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
      if ($file->isFile() && substr($file->getFilename(), -5) === '.twig') {
        $full_path = $file->getPathname();
        $rel_path  = ltrim(str_replace($templates_path, '', $full_path), '/\\');
        $files[]   = [
          'full_path' => $full_path,
          'rel_path'  => $rel_path,
        ];
      }
    }

    // Sort alphabetically by relative path.
    usort($files, function ($a, $b) {
      return strcmp($a['rel_path'], $b['rel_path']);
    });

    // Store file list in state.
    \Drupal::state()->set('tve_file_list', $files);

    // Initialise CSV file.
    $csv_uri = 'public://twig_verbiage/textmap.csv';
    $dir     = 'public://twig_verbiage';

    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $real_path = $file_system->realpath($dir) . '/textmap.csv';

    // Write UTF-8 BOM + headers so Excel opens correctly.
    $fp = fopen($real_path, 'w');
    if ($fp) {
      // UTF-8 BOM.
      fwrite($fp, "\xEF\xBB\xBF");
      fputcsv($fp, ['Feature Name', 'Twig File', 'Verbiage / Variable', 'Static Text (auto-filled)']);
      fclose($fp);
    }

    \Drupal::state()->set('tve_csv_real_path', $real_path);
    \Drupal::state()->set('tve_csv_path', $csv_uri);

    $context['results']['total_files'] = count($files);
    $context['message'] = t('Found @count twig files. Starting processing...', ['@count' => count($files)]);
  }

  // -------------------------------------------------------------------------
  // BATCH OPERATION 3: Dispatch — one op per file
  // -------------------------------------------------------------------------

  /**
   * Dispatcher: queues one processFile operation per discovered twig file.
   *
   * Drupal batch does not support dynamically adding operations after batch_set,
   * so we process all files here in a single operation but update progress
   * message per file so the UI stays responsive.
   */
  public static function dispatchFileOps(&$context) {
    $files    = \Drupal::state()->get('tve_file_list', []);
    $api_data = \Drupal::state()->get('tve_api_data', []);
    $csv_path = \Drupal::state()->get('tve_csv_real_path', '');

    if (empty($files)) {
      $context['message'] = t('No twig files found to process.');
      return;
    }

    // Initialise sandbox for progressive processing.
    if (empty($context['sandbox'])) {
      $context['sandbox']['total']     = count($files);
      $context['sandbox']['current']   = 0;
      $context['sandbox']['files']     = $files;
      $context['sandbox']['api_data']  = $api_data;
      $context['sandbox']['csv_path']  = $csv_path;
      $context['sandbox']['variables'] = 0;
      $context['sandbox']['matched']   = 0;
    }

    $sandbox = &$context['sandbox'];
    $index   = $sandbox['current'];
    $file    = $sandbox['files'][$index];

    $context['message'] = t('Processing (@current/@total): @file', [
      '@current' => $index + 1,
      '@total'   => $sandbox['total'],
      '@file'    => $file['rel_path'],
    ]);

    // Process this file.
    $rows = self::processFile(
      $file['full_path'],
      $file['rel_path'],
      $sandbox['api_data']
    );

    // Append rows to CSV.
    if (!empty($rows) && !empty($sandbox['csv_path'])) {
      $fp = fopen($sandbox['csv_path'], 'a');
      if ($fp) {
        foreach ($rows as $row) {
          fputcsv($fp, $row);
          if ($row[2] !== '' && strpos($row[2], '(no static text)') === FALSE) {
            $sandbox['variables']++;
            if (!empty($row[3])) {
              $sandbox['matched']++;
            }
          }
        }
        fclose($fp);
      }
    }

    $sandbox['current']++;

    // Tell Drupal batch how far we are.
    $context['finished'] = $sandbox['current'] / $sandbox['total'];

    // Store stats when done.
    if ($sandbox['current'] >= $sandbox['total']) {
      $context['results']['files']     = $sandbox['total'];
      $context['results']['variables'] = $sandbox['variables'];
      $context['results']['matched']   = $sandbox['matched'];
    }
  }

  // -------------------------------------------------------------------------
  // BATCH FINISHED
  // -------------------------------------------------------------------------

  /**
   * Batch finished callback.
   */
  public static function finished($success, $results, $operations) {
    if ($success) {
      $files     = isset($results['files']) ? $results['files'] : 0;
      $variables = isset($results['variables']) ? $results['variables'] : 0;
      $matched   = isset($results['matched']) ? $results['matched'] : 0;

      // Persist stats for display on form.
      \Drupal::state()->set('tve_stats', [
        'files'     => $files,
        'variables' => $variables,
        'matched'   => $matched,
      ]);

      \Drupal::messenger()->addStatus(t(
        'Export complete! @files files processed, @vars variables found, @matched auto-filled from API.',
        [
          '@files'   => $files,
          '@vars'    => $variables,
          '@matched' => $matched,
        ]
      ));

      \Drupal::messenger()->addStatus(t(
        '<a href="@url">Click here to download the CSV</a>',
        ['@url' => \Drupal\Core\Url::fromRoute('twig_verbiage_extractor.download')->toString()]
      ));
    }
    else {
      \Drupal::messenger()->addError(t('Export failed. Please check the logs for details.'));
    }
  }

  // =========================================================================
  // HELPER: Process a single twig file — returns array of CSV rows
  // =========================================================================

  /**
   * Process one twig file and return CSV rows.
   *
   * @param string $full_path
   *   Absolute path to the .twig file.
   * @param string $rel_path
   *   Relative path from templates root (e.g. banners/banner.html.twig).
   * @param array $api_data
   *   The digital_sites API data keyed by node key.
   *
   * @return array
   *   Array of [feature_name, twig_file, verbiage, static_text] rows.
   */
  protected static function processFile($full_path, $rel_path, array $api_data) {
    // Read file content.
    $content = @file_get_contents($full_path);
    if ($content === FALSE) {
      return [];
    }

    // Parse feature name and twig filename.
    list($feature_name, $twig_file) = self::parseFeatureAndTwig($rel_path);

    // Extract static text and variables.
    $static_text = self::extractStaticText($content);
    $variables   = self::extractVariables($content);

    $rows = [];

    // Row 1: static text block.
    $rows[] = [
      $feature_name,
      $twig_file,
      $static_text ?: '(no static text)',
      '',
    ];

    // One row per variable.
    foreach ($variables as $var) {
      $verbiage = self::lookupDigitalSites($var, $api_data);
      $rows[]   = [
        $feature_name,
        $twig_file,
        '{{ ' . $var . ' }}',
        $verbiage,
      ];
    }

    return $rows;
  }

  // =========================================================================
  // HELPER: Parse feature name and twig filename from relative path
  // =========================================================================

  /**
   * Split relative path into [feature_name, twig_filename].
   */
  protected static function parseFeatureAndTwig($rel_path) {
    $rel_path = str_replace('\\', '/', $rel_path);
    $parts    = explode('/', $rel_path);

    if (count($parts) === 1) {
      return ['', $parts[0]];
    }

    // Feature name = top-level folder only.
    return [$parts[0], end($parts)];
  }

  // =========================================================================
  // HELPER: Extract static text
  // =========================================================================

  /**
   * Extract human-readable static text from twig content.
   */
  protected static function extractStaticText($content) {
    // Remove twig comments {# ... #}.
    $text = preg_replace('/\{#.*?#\}/s', '', $content);

    // Remove twig block tags {% ... %}.
    $text = preg_replace('/\{%.*?%\}/s', '', $text);

    // Remove twig variable expressions {{ ... }}.
    $text = preg_replace('/\{\{.*?\}\}/s', '', $text);

    // Remove HTML tags.
    $text = preg_replace('/<[^>]+>/', '', $text);

    // Decode HTML entities.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Collapse horizontal whitespace per line and strip.
    $lines   = explode("\n", $text);
    $cleaned = [];
    $prev    = NULL;

    foreach ($lines as $line) {
      $line = preg_replace('/[ \t]+/', ' ', $line);
      $line = trim($line);

      if ($line === '') {
        continue;
      }

      // Skip lines that are just punctuation remnants.
      if (preg_match('/^[\.\,\;\:\'\"\`\-\~\!\@\#\$\%\^\&\*\(\)\[\]\{\}\/\\\|]+$/', $line)) {
        continue;
      }

      // Deduplicate consecutive identical lines.
      if ($line !== $prev) {
        $cleaned[] = $line;
      }

      $prev = $line;
    }

    return implode("\n", $cleaned);
  }

  // =========================================================================
  // HELPER: Extract variables
  // =========================================================================

  /**
   * Extract deduplicated list of user-visible variable expressions.
   */
  protected static function extractVariables($content) {
    // Find all {{ ... }} expressions.
    preg_match_all('/\{\{(.*?)\}\}/s', $content, $matches);

    $variables = [];
    $seen      = [];

    foreach ($matches[1] as $raw) {
      $expr = trim($raw);

      if (empty($expr)) {
        continue;
      }

      if (!self::isContentVariable($expr)) {
        continue;
      }

      if (!isset($seen[$expr])) {
        $seen[$expr]  = TRUE;
        $variables[]  = $expr;
      }
    }

    return $variables;
  }

  /**
   * Determine if a twig expression is user-visible content.
   */
  protected static function isContentVariable($expr) {
    $expr = trim($expr);

    if (empty($expr)) {
      return FALSE;
    }

    // Drupal/Twig helper functions — never user-visible content.
    $drupal_functions = [
      'path(', 'attach_library(', 'url(', 'file_url(', 'link(',
      'render(', 'block(', 'drupal_view(', 'drupal_block(',
      'drupal_field(', 'drupal_entity(', 'drupal_token(',
      'asset(', 'source(', 'create_attribute(', 'active_theme_path(',
    ];

    foreach ($drupal_functions as $fn) {
      if (stripos($expr, $fn) !== FALSE) {
        return FALSE;
      }
    }

    // Any ternary operator — CSS class switching, never content.
    if (strpos($expr, '?') !== FALSE) {
      return FALSE;
    }

    // Pure quoted string literal — not a data variable.
    if (preg_match('/^\s*(?:\'[^\']*\'|"[^"]*")\s*$/', $expr)) {
      return FALSE;
    }

    // Twig translation filter only — not dynamic content.
    if (preg_match('/^\s*\'[^\']*\'\s*\|t\s*$/', $expr)) {
      return FALSE;
    }

    // Attribute/class manipulation expressions.
    if (strpos($expr, 'addClass(') !== FALSE || strpos($expr, 'setAttribute(') !== FALSE) {
      return FALSE;
    }

    return TRUE;
  }

  // =========================================================================
  // HELPER: Lookup digital_sites variable value from API data
  // =========================================================================

  /**
   * Resolve a digital_sites twig variable to its API value.
   *
   * Supports:
   *   digital_sites.node_key.key_in_description
   *   digital_sites.node_key.nested.deep.key
   *   digital_sites.node_key.description (plain string)
   *   backOfficeTokenReplace(digital_sites.node_key.key, ...) wrapper
   *
   * Uses reverse traversal: tries full path inside description JSON,
   * then drops parts from the left one by one until a match is found.
   *
   * @param string $expr
   *   The raw twig variable expression (without {{ }}).
   * @param array $api_data
   *   The digital_sites API data array.
   *
   * @return string
   *   The resolved plain-text value, or empty string if not found.
   */
  protected static function lookupDigitalSites($expr, array $api_data) {
    if (empty($api_data)) {
      return '';
    }

    // Strip twig filters e.g. |raw, |striptags|raw, |title.
    $expr_clean = preg_replace('/\|[a-z_]+(\([^)]*\))?(\|[a-z_]+(\([^)]*\))?)*$/', '', $expr);
    $expr_clean = trim($expr_clean);

    // Handle backOfficeTokenReplace(digital_sites.x.y.z, ...) wrapper.
    if (stripos($expr_clean, 'backOfficeTokenReplace') !== FALSE) {
      if (preg_match('/backOfficeTokenReplace\s*\(\s*([^,]+)/i', $expr_clean, $m)) {
        $expr_clean = trim($m[1]);
        // Strip filters again from extracted inner expression.
        $expr_clean = preg_replace('/\|[a-z_]+(\([^)]*\))?(\|[a-z_]+(\([^)]*\))?)*$/', '', $expr_clean);
        $expr_clean = trim($expr_clean);
      }
      else {
        return '';
      }
    }

    // Must start with digital_sites.
    if (strpos($expr_clean, 'digital_sites.') !== 0) {
      return '';
    }

    $parts = explode('.', $expr_clean);

    // Need at least: digital_sites, node_key, one_more_part.
    if (count($parts) < 3) {
      return '';
    }

    // parts[0] = digital_sites (skip)
    // parts[1] = node key
    // parts[2+] = path inside description JSON
    $node_key  = $parts[1];
    $remaining = array_slice($parts, 2);

    $node = isset($api_data[$node_key]) ? $api_data[$node_key] : NULL;
    if ($node === NULL) {
      return '';
    }

    $desc = isset($node['description']) ? $node['description'] : NULL;
    if ($desc === NULL) {
      return '';
    }

    // Try to parse description as JSON.
    if (is_string($desc)) {
      $decoded = json_decode($desc, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        $desc_parsed = $decoded;
      }
      else {
        // Plain string description.
        // Valid if remaining is empty or just ['description'].
        if (empty($remaining) || $remaining === ['description']) {
          return self::stripHtml($desc);
        }
        return '';
      }
    }
    else {
      $desc_parsed = $desc;
    }

    // If description is not array/object after parsing, return as plain text.
    if (!is_array($desc_parsed)) {
      return self::stripHtml((string) $desc_parsed);
    }

    // Reverse traversal:
    // Try full path [parts[2], parts[3], ...] inside desc_parsed.
    // If not found, drop the leftmost part and try again.
    // This handles both patterns:
    //   digital_sites.node.description.key     → try [description][key], then [key]
    //   digital_sites.node.hsaLanding.hero.title → try [hsaLanding][hero][title] directly
    for ($start = 0; $start < count($remaining); $start++) {
      $path   = array_slice($remaining, $start);
      $result = self::traverseJson($desc_parsed, $path);

      if ($result !== NULL) {
        if (is_array($result)) {
          $serialised = json_encode($result);
          // Only return if it's a small leaf-ish value, not a huge nested object.
          if (strlen($serialised) < 500) {
            return self::stripHtml($serialised);
          }
          return '';
        }
        return self::stripHtml((string) $result);
      }
    }

    return '';
  }

  /**
   * Traverse a nested array using an array of string keys.
   * Handles numeric keys for array access (e.g. items.0, items.1).
   */
  protected static function traverseJson($data, array $keys) {
    $current = $data;

    foreach ($keys as $key) {
      if ($current === NULL) {
        return NULL;
      }

      if (is_array($current)) {
        // Try string key first.
        if (array_key_exists($key, $current)) {
          $current = $current[$key];
        }
        // Try numeric index.
        elseif (is_numeric($key) && array_key_exists((int) $key, $current)) {
          $current = $current[(int) $key];
        }
        else {
          return NULL;
        }
      }
      elseif (is_string($current)) {
        // Try to parse as JSON and continue traversal.
        $decoded = json_decode($current, TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          $current = isset($decoded[$key]) ? $decoded[$key] : NULL;
        }
        else {
          return NULL;
        }
      }
      else {
        return NULL;
      }
    }

    return $current;
  }

  /**
   * Strip HTML tags and decode entities from a string.
   */
  protected static function stripHtml($text) {
    // Remove HTML tags.
    $text = strip_tags($text);

    // Decode HTML entities.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Collapse whitespace.
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Trim each line and remove blank lines.
    $lines   = explode("\n", $text);
    $cleaned = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '') {
        $cleaned[] = $line;
      }
    }

    return implode("\n", $cleaned);
  }

}
