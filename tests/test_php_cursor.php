<?php
// Run with: php -n -d zend.assertions=1 -d assert.exception=1 tests/test_php_cursor.php
$source = file_get_contents(__DIR__ . '/../php/index.php');
eval(substr(explode('$config = config();', $source)[0], 5));
$sources = [];
foreach (['claude', 'deepseek', 'cursor', 'codex', 'grok2api'] as $name) {
    $sources[$name] = ['accounts' => [['name' => $name, 'summary' => 'display summary']]];
}
$sources['codex']['accounts'] = [
    ['name' => 'Codex 1', 'summary' => '5H'],
    ['name' => 'Codex 2', 'summary' => '7D'],
    ['name' => 'Codex 3', 'summary' => 'legacy'],
];
$state = ['quota' => ['sources' => $sources]];
assert(array_column(quota_accounts($state), 'name') === ['claude', 'deepseek', 'cursor', 'Codex 1', 'Codex 2', 'grok2api']);
assert(count(codex_cursor_quota_rows($state)) === 3);
assert(array_column(codex_cursor_quota_rows($state), 'source') === ['codex', 'codex', 'cursor']);
$metric = clean_metric(['used' => 3.6, 'reset_at' => 1795443282, 'access_token' => 'discard']);
assert($metric === ['used' => 4, 'reset_at' => 1795443282]);
$state['quota']['sources']['cursor']['accounts'][0]['included_usage'] = $metric;
assert(account_metric(quota_source_account($state, 'cursor'), 'included_usage') === ['used' => 4, 'reset_at' => 1795443282]);
assert(strpos(iphone_metric_html('7D', $metric, 2678400), '4%') !== false);
echo "PHP Cursor: six-account preservation, ink rows and safe metrics passed\n";
